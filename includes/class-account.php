<?php
/**
 * Accounts on the site: sign-in links by email, free sign-up from a join
 * card, the member area and its profile form.
 *
 * Sign-in links hold a random secret stored hashed in the user's meta. They
 * work once, until their end time. Opening the link shows a page with one
 * button: email scanners that open links do not use them up.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Account.
 */
final class Account {

	/**
	 * User meta that keeps the sign-in links.
	 */
	const META = '_aluleth_signin_links';

	/**
	 * Nonce action of the public forms (sign-in link, free sign-up).
	 */
	const PUBLIC_NONCE = 'aluleth_membership_public';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_nopriv_aluleth_membership_link', array( __CLASS__, 'request_link' ) );
		add_action( 'admin_post_aluleth_membership_link', array( __CLASS__, 'request_link' ) );
		add_action( 'admin_post_nopriv_aluleth_membership_join', array( __CLASS__, 'join' ) );
		add_action( 'admin_post_aluleth_membership_join', array( __CLASS__, 'join' ) );
		add_action( 'admin_post_aluleth_membership_profile', array( __CLASS__, 'profile' ) );
		// A fresh nonce for the public forms, for pages served from a cache.
		add_action( 'wp_ajax_nopriv_aluleth_membership_nonce', array( __CLASS__, 'fresh_nonce' ) );
		add_action( 'wp_ajax_aluleth_membership_nonce', array( __CLASS__, 'fresh_nonce' ) );
		add_action( 'template_redirect', array( __CLASS__, 'open_link' ), 1 );
		add_shortcode( 'aluleth_member_area', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Creates a sign-in link.
	 *
	 * @param int   $user_id User id.
	 * @param array $data    'redirect' (URL on this site), 'row' (pending access to open).
	 * @param int   $ttl     Seconds the link works.
	 * @return string
	 */
	public static function link( $user_id, array $data, $ttl ) {
		$secret = wp_generate_password( 40, false, false );
		$links  = get_user_meta( $user_id, self::META, true );
		$links  = is_array( $links ) ? $links : array();
		$now    = time();
		foreach ( $links as $hash => $link ) {
			if ( ! isset( $link['until'] ) || $link['until'] < $now ) {
				unset( $links[ $hash ] );
			}
		}
		$links[ hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) ) ] = array(
			'until'    => $now + (int) $ttl,
			'redirect' => isset( $data['redirect'] ) ? esc_url_raw( (string) $data['redirect'] ) : '',
			'row'      => isset( $data['row'] ) ? (int) $data['row'] : 0,
		);
		// Keep the 10 newest links.
		uasort(
			$links,
			static function ( $a, $b ) {
				return $b['until'] - $a['until'];
			}
		);
		update_user_meta( $user_id, self::META, array_slice( $links, 0, 10, true ) );
		return add_query_arg( 'alm_login', (int) $user_id . '-' . $secret, home_url( '/' ) );
	}

	/**
	 * Opens a sign-in link: a page with a button (GET), then the sign-in (POST).
	 *
	 * @return void
	 */
	public static function open_link() {
		// phpcs:disable WordPress.Security.NonceVerification -- the secret in the link is the proof.
		$raw = isset( $_REQUEST['alm_login'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['alm_login'] ) ) : '';
		if ( '' === $raw ) {
			return;
		}
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		// phpcs:enable
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		$parts   = explode( '-', $raw, 2 );
		$user_id = absint( $parts[0] );
		$secret  = isset( $parts[1] ) ? preg_replace( '/[^A-Za-z0-9]/', '', $parts[1] ) : '';
		$links   = $user_id ? get_user_meta( $user_id, self::META, true ) : array();
		$hash    = '' !== $secret ? hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) ) : '';
		$link    = is_array( $links ) && '' !== $hash && isset( $links[ $hash ] ) ? $links[ $hash ] : null;
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if ( ! $link || ! $user || $link['until'] < time() ) {
			self::page(
				__( 'This link no longer works', 'aluleth-membership' ),
				'<p>' . esc_html__( 'Sign-in links work once and for a limited time. Ask for a new one:', 'aluleth-membership' ) . '</p>' . self::link_form( Settings::member_url() )
			);
		}

		if ( ! $is_post ) {
			$button = $link['row'] ? __( 'Confirm and continue', 'aluleth-membership' ) : __( 'Sign in', 'aluleth-membership' );
			self::page(
				/* translators: %s: site name. */
				sprintf( __( 'Sign in to %s', 'aluleth-membership' ), get_bloginfo( 'name' ) ),
				'<p>' . esc_html(
					sprintf(
						/* translators: %s: email address, partly hidden. */
						__( 'You are signing in as %s.', 'aluleth-membership' ),
						self::masked( $user->user_email )
					)
				) . '</p><form method="post" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="alm_login" value="' . esc_attr( $raw ) . '"><input type="hidden" name="_almnonce" value="' . esc_attr( wp_create_nonce( 'aluleth_membership_signin_' . $user_id ) ) . '"><button type="submit" class="alm-btn">' . esc_html( $button ) . '</button></form>'
			);
		}

		// The button of the page above sends a nonce with the secret of the link.
		$nonce = isset( $_POST['_almnonce'] ) ? sanitize_key( wp_unslash( $_POST['_almnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'aluleth_membership_signin_' . $user_id ) ) {
			self::page(
				/* translators: %s: site name. */
				sprintf( __( 'Sign in to %s', 'aluleth-membership' ), get_bloginfo( 'name' ) ),
				'<p>' . esc_html__( 'This page has expired. Please open the link from your email again.', 'aluleth-membership' ) . '</p>'
			);
		}

		unset( $links[ $hash ] );
		update_user_meta( $user_id, self::META, $links );
		if ( $link['row'] ) {
			$row = Members::row( $link['row'] );
			if ( $row && (int) $row->user_id === $user_id ) {
				Members::activate( (int) $row->id );
			}
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			// Editors and admins never get signed in by an email link: they use their password.
			wp_safe_redirect( get_current_user_id() === $user_id ? wp_validate_redirect( $link['redirect'], Settings::member_url() ) : wp_login_url( $link['redirect'] ) );
			exit;
		}
		/**
		 * Lets security plugins refuse this sign-in, as they do for the WordPress
		 * sign-in form (blocked or unconfirmed accounts). No password is used here.
		 *
		 * This filter is documented in wp-includes/user.php.
		 */
		$checked = apply_filters( 'wp_authenticate_user', $user, '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
		if ( ! $checked instanceof \WP_User || (int) $checked->ID !== $user_id ) {
			self::page(
				/* translators: %s: site name. */
				sprintf( __( 'Sign in to %s', 'aluleth-membership' ), get_bloginfo( 'name' ) ),
				'<p>' . esc_html__( 'This account cannot sign in with a link right now. Please contact the site owner.', 'aluleth-membership' ) . '</p>'
			);
		}
		if ( get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true, is_ssl() );
			/** This action is documented in wp-includes/user.php */
			do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}
		$to = '' !== $link['redirect'] ? wp_validate_redirect( $link['redirect'], Settings::member_url() ) : Settings::member_url();
		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * A small page of its own (link pages), then stops.
	 *
	 * @param string $title Title.
	 * @param string $body  HTML.
	 * @return void
	 */
	private static function page( $title, $body ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		// This page has no theme (no wp_head()): its style is enqueued, then printed with wp_print_styles().
		wp_enqueue_style( 'aluleth-membership-page', plugins_url( 'assets/membership.css', ALULETH_MEMBERSHIP_FILE ), array(), ALULETH_MEMBERSHIP_VERSION );
		wp_add_inline_style( 'aluleth-membership-page', '.alm-page__box{--alm-accent:' . Settings::accent() . '}' );
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() escapes.
		wp_print_styles( array( 'aluleth-membership-page' ) );
		echo '</head><body class="alm-page"><main class="alm-page__box"><p class="alm-page__site"><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p><h1>' . esc_html( $title ) . '</h1>';
		echo wp_kses( $body, self::allowed_html() );
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Tags allowed in the forms printed by this class.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$common = array(
			'class'           => true,
			'id'              => true,
			'style'           => true,
			'hidden'          => true,
			'aria-live'       => true,
			'aria-label'      => true,
			'aria-hidden'     => true,
			'role'            => true,
			'data-alm-form'   => true,
			'data-alm-signup' => true,
			'data-alm-level'  => true,
		);
		return array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'form'   => array_merge(
					$common,
					array(
						'method'     => true,
						'action'     => true,
						'novalidate' => true,
					)
				),
				'input'  => array_merge(
					$common,
					array(
						'type'         => true,
						'name'         => true,
						'value'        => true,
						'placeholder'  => true,
						'required'     => true,
						'autocomplete' => true,
						'tabindex'     => true,
						'checked'      => true,
						'inputmode'    => true,
					)
				),
				'button' => array_merge(
					$common,
					array(
						'type'  => true,
						'name'  => true,
						'value' => true,
					)
				),
				'label'  => array_merge( $common, array( 'for' => true ) ),
				'div'    => $common,
				'span'   => $common,
				'p'      => $common,
				'strong' => $common,
				'a'      => array_merge(
					$common,
					array(
						'href' => true,
						'rel'  => true,
					)
				),
			)
		);
	}

	/**
	 * "a…@example.com".
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function masked( $email ) {
		$at = strpos( $email, '@' );
		return false === $at ? $email : substr( $email, 0, 1 ) . str_repeat( '•', max( 2, min( 6, $at - 1 ) ) ) . substr( $email, $at );
	}

	/**
	 * The "get a sign-in link" form.
	 *
	 * @param string $redirect Page to open after signing in.
	 * @param string $label    Field label.
	 * @return string
	 */
	public static function link_form( $redirect, $label = '' ) {
		$label = '' !== $label ? $label : __( 'Get a sign-in link by email', 'aluleth-membership' );
		$id    = 'alm-' . wp_rand( 1000, 999999 );
		return '<form class="alm-form alm-form--link" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-alm-form="link">'
			. '<input type="hidden" name="action" value="aluleth_membership_link"><input type="hidden" name="redirect" value="' . esc_attr( $redirect ) . '">' . self::nonce_field()
			. '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<span class="alm-form__row"><input id="' . esc_attr( $id ) . '" type="email" name="email" required autocomplete="email" placeholder="' . esc_attr__( 'you@example.com', 'aluleth-membership' ) . '">'
			. '<button type="submit" class="alm-btn alm-btn--ghost">' . esc_html__( 'Send me the link', 'aluleth-membership' ) . '</button></span>'
			. self::trap()
			. '<p class="alm-form__msg" aria-live="polite"></p></form>';
	}

	/**
	 * Nonce field of the public forms. The site script replaces it with a fresh
	 * one before sending, so forms on cached pages keep working.
	 *
	 * @return string
	 */
	public static function nonce_field() {
		return '<input type="hidden" name="_almnonce" value="' . esc_attr( wp_create_nonce( self::PUBLIC_NONCE ) ) . '" data-alm-nonce="1">';
	}

	/**
	 * Answers a fresh nonce for the public forms (admin-ajax, GET).
	 *
	 * @return void
	 */
	public static function fresh_nonce() {
		nocache_headers();
		wp_send_json( array( 'nonce' => wp_create_nonce( self::PUBLIC_NONCE ) ) );
	}

	/**
	 * Answer to a public form whose nonce does not match.
	 *
	 * @param string $back Page to go back to.
	 * @return void
	 */
	private static function expired( $back ) {
		self::answer( false, __( 'This page has expired. Please reload it and try again.', 'aluleth-membership' ), $back );
	}

	/**
	 * Field that people never fill (robots do).
	 *
	 * @return string
	 */
	public static function trap() {
		return '<span class="alm-trap" aria-hidden="true"><label>' . esc_html__( 'Leave empty', 'aluleth-membership' ) . '<input type="text" name="alm_website" value="" tabindex="-1" autocomplete="off"></label></span>';
	}

	/**
	 * Whether a request was sent by a robot or too often from this address.
	 *
	 * @param string $what  Kind of request.
	 * @param string $email Email (also limited).
	 * @return bool
	 */
	private static function limited( $what, $email ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form; checked by limits.
		if ( ! empty( $_POST['alm_website'] ) ) {
			return true;
		}
		$keys = array( 'aluleth_membership_rl_' . $what . '_' . substr( Util::ip_hash(), 0, 20 ) => 8 );
		if ( '' !== $email ) {
			$keys[ 'aluleth_membership_rl_' . $what . '_' . substr( hash( 'sha256', $email ), 0, 20 ) ] = 3;
		}
		foreach ( $keys as $key => $max ) {
			$n = (int) get_transient( $key );
			if ( $n >= $max ) {
				return true;
			}
			set_transient( $key, $n + 1, 15 * MINUTE_IN_SECONDS );
		}
		return false;
	}

	/**
	 * Answer to a public form: JSON for the script, else back to the page.
	 *
	 * @param bool   $ok       Success.
	 * @param string $message  Message.
	 * @param string $back     Page to go back to.
	 * @return void
	 */
	private static function answer( $ok, $message, $back ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading the answer format only.
		if ( ! empty( $_POST['alm_ajax'] ) ) {
			wp_send_json(
				array(
					'ok'      => (bool) $ok,
					'message' => $message,
				)
			);
		}
		$back = wp_validate_redirect( $back, home_url( '/' ) );
		set_transient( 'aluleth_membership_msg_' . substr( Util::ip_hash(), 0, 20 ), $message, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'alm_msg', '1', $back ) );
		exit;
	}

	/**
	 * Message left by answer() for a visitor without the script.
	 *
	 * @return string
	 */
	public static function pending_message() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message to the same visitor.
		if ( empty( $_GET['alm_msg'] ) ) {
			return '';
		}
		$key = 'aluleth_membership_msg_' . substr( Util::ip_hash(), 0, 20 );
		$msg = (string) get_transient( $key );
		delete_transient( $key );
		return $msg;
	}

	/**
	 * Back address of a public form.
	 *
	 * @return string
	 */
	private static function back() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public forms.
		$back = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		// phpcs:enable
		if ( '' === $back ) {
			$back = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		}
		return wp_validate_redirect( $back, home_url( '/' ) );
	}

	/**
	 * "Send me a sign-in link". The answer is the same whether the email has
	 * an account or not.
	 *
	 * @return void
	 */
	public static function request_link() {
		$back  = self::back();
		$nonce = isset( $_POST['_almnonce'] ) ? sanitize_key( wp_unslash( $_POST['_almnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::PUBLIC_NONCE ) ) {
			self::expired( $back );
		}
		$email = isset( $_POST['email'] ) ? Util::email( sanitize_text_field( wp_unslash( $_POST['email'] ) ) ) : '';
		if ( '' === $email ) {
			self::answer( false, __( 'Please type a valid email address.', 'aluleth-membership' ), $back );
		}
		$same = __( 'If this email has an account, a sign-in link is on its way. Check your inbox (and the spam folder).', 'aluleth-membership' );
		if ( self::limited( 'link', $email ) ) {
			self::answer( true, $same, $back );
		}
		$user = get_user_by( 'email', $email );
		if ( $user && ! user_can( $user, 'edit_posts' ) ) {
			Emails::login( $user, $back );
		}
		self::answer( true, $same, $back );
	}

	/**
	 * Free sign-up from a join card. Visitors confirm their email with the
	 * link they receive; signed-in people join right away.
	 *
	 * @return void
	 */
	public static function join() {
		$back = self::back();
		if ( is_user_logged_in() ) {
			check_admin_referer( 'aluleth_membership_join', '_almnonce' );
		} else {
			$nonce = isset( $_POST['_almnonce'] ) ? sanitize_key( wp_unslash( $_POST['_almnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::PUBLIC_NONCE ) ) {
				self::expired( $back );
			}
		}
		$level_id = isset( $_POST['level'] ) ? absint( $_POST['level'] ) : 0;
		$email    = isset( $_POST['email'] ) ? Util::email( sanitize_text_field( wp_unslash( $_POST['email'] ) ) ) : '';
		$first    = isset( $_POST['first_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['first_name'] ) ), 0, 60 ) : '';
		$level    = Levels::get( $level_id );
		if ( ! $level || ! $level['free_signup'] ) {
			self::answer( false, __( 'This membership can\'t be joined with this form.', 'aluleth-membership' ), $back );
		}
		$redirect = $level['redirect'] && 'publish' === get_post_status( $level['redirect'] ) ? (string) get_permalink( $level['redirect'] ) : $back;

		if ( is_user_logged_in() ) {
			Members::grant( get_current_user_id(), $level_id, 'signup' );
			/* translators: %s: level name. */
			self::answer( true, sprintf( __( 'Welcome! You are now a member: %s.', 'aluleth-membership' ), $level['name'] ), $redirect );
		}
		if ( '' === $email ) {
			self::answer( false, __( 'Please type a valid email address.', 'aluleth-membership' ), $back );
		}
		$done = __( 'Almost there: click the link we just sent you to confirm your email. Check the spam folder if you don\'t see it.', 'aluleth-membership' );
		if ( self::limited( 'join', $email ) ) {
			self::answer( true, $done, $back );
		}
		$existing = get_user_by( 'email', $email );
		if ( $existing && user_can( $existing, 'edit_posts' ) ) {
			self::answer( true, $done, $back );
		}
		list( $user_id ) = Members::ensure_user( $email, $first );
		$user            = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			self::answer( false, __( 'Sorry, the sign-up did not work. Please try again later.', 'aluleth-membership' ), $back );
		}
		if ( Members::has_level( $user_id, $level_id ) ) {
			Emails::login( $user, $redirect );
			self::answer( true, $done, $back );
		}
		$row = Members::grant(
			$user_id,
			$level_id,
			'signup',
			'',
			array(
				'status' => 'pending',
				'notify' => false,
			)
		);
		Emails::confirm( $user, $level, $row, $redirect );
		self::answer( true, $done, $back );
	}

	/**
	 * Profile form of the member area (name).
	 *
	 * @return void
	 */
	public static function profile() {
		check_admin_referer( 'aluleth_membership_profile', '_almnonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_die( esc_html__( 'Please sign in again.', 'aluleth-membership' ) );
		}
		$first = isset( $_POST['first_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['first_name'] ) ), 0, 60 ) : '';
		$last  = isset( $_POST['last_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['last_name'] ) ), 0, 60 ) : '';
		wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => '' !== trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : wp_get_current_user()->display_name,
			)
		);
		self::answer( true, __( 'Saved.', 'aluleth-membership' ), self::back() );
	}

	/**
	 * [aluleth_member_area]
	 *
	 * @return string
	 */
	public static function shortcode() {
		return Member_Area::render();
	}
}
