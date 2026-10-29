<?php
/**
 * The join card: shown where members-only content starts, with the levels
 * that open it, the free sign-up and the sign-in link form.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Join card.
 */
final class Join_Card {

	/**
	 * Renders a card.
	 *
	 * @param int[] $level_ids Levels that open the content (only those shown on cards appear).
	 * @param array $args      'redirect' (page to come back to), 'title', 'text', 'design', 'login' (bool),
	 *                         'preview' (bool: as a visitor sees it, for the admin screens),
	 *                         'page' (bool: placed on a page by a block, widget or shortcode, not cutting content),
	 *                         'notice' (string: line shown at the top, e.g. no free articles left).
	 * @return string
	 */
	public static function render( array $level_ids, array $args = array() ) {
		$settings = Settings::get();
		Plugin::enqueue_front();
		$design   = isset( $args['design'] ) && in_array( $args['design'], array( 'clean', 'dark', 'soft' ), true ) ? $args['design'] : $settings['design'];
		$redirect = isset( $args['redirect'] ) && '' !== $args['redirect'] ? (string) $args['redirect'] : self::here();
		$on_page  = ! empty( $args['page'] );
		// The title and text of the settings are for content that is cut; a card placed on a page has its own.
		$title = isset( $args['title'] ) && '' !== $args['title'] ? $args['title'] : ( $on_page ? '' : $settings['card_title'] );
		$text  = isset( $args['text'] ) && '' !== $args['text'] ? $args['text'] : ( $on_page ? '' : $settings['card_text'] );
		$login = isset( $args['login'] ) ? (bool) $args['login'] : (bool) $settings['card_login'];
		$plans = array();
		foreach ( Levels::pick( $level_ids ) as $level ) {
			if ( $level['on_card'] ) {
				$plans[] = $level;
			}
		}
		$user = ! empty( $args['preview'] ) || self::editing() ? new \WP_User( 0 ) : wp_get_current_user();
		if ( $on_page ) {
			$title = '' !== $title ? $title : __( 'Choose your membership', 'aluleth-membership' );
			$text  = '' !== $text ? $text : __( 'Pick the one that suits you.', 'aluleth-membership' );
		}
		$title = '' !== $title ? $title : ( $user->exists() ? __( 'Your membership doesn\'t include this yet', 'aluleth-membership' ) : __( 'This is for members', 'aluleth-membership' ) );
		if ( '' === $text ) {
			$text = $plans ? __( 'Join to read the rest. It only takes a minute.', 'aluleth-membership' ) : __( 'Sign in to read the rest.', 'aluleth-membership' );
		}

		$out  = '<section class="alm-card alm-card--' . esc_attr( $design ) . '" style="--alm-accent:' . esc_attr( $settings['accent'] ) . '" aria-label="' . esc_attr__( 'Members only', 'aluleth-membership' ) . '">';
		$out .= '<div class="alm-card__head"><p class="alm-card__title">' . esc_html( $title ) . '</p><p class="alm-card__text">' . esc_html( $text ) . '</p></div>';

		$message = Account::pending_message();
		if ( '' === $message && ! empty( $args['notice'] ) ) {
			$message = (string) $args['notice'];
		}
		if ( '' !== $message ) {
			$out .= '<p class="alm-card__notice" role="status">' . esc_html( $message ) . '</p>';
		}

		$free = array();
		if ( $plans ) {
			$out .= '<div class="alm-plans">';
			foreach ( $plans as $level ) {
				$url  = Levels::join_url( $level );
				$out .= '<div class="alm-plan' . ( $level['popular'] ? ' is-popular' : '' ) . '">';
				$out .= '<div class="alm-plan__top"><strong class="alm-plan__name">' . esc_html( $level['name'] ) . '</strong>';
				if ( $level['popular'] ) {
					$out .= '<span class="alm-plan__badge">' . esc_html__( 'Popular', 'aluleth-membership' ) . '</span>';
				}
				$out .= '</div>';
				if ( '' !== $level['price'] ) {
					$out .= '<p class="alm-plan__price">' . esc_html( $level['price'] ) . '</p>';
				}
				if ( '' !== $level['description'] ) {
					$out .= '<p class="alm-plan__text">' . esc_html( $level['description'] ) . '</p>';
				}
				$label = '' !== $level['button_label'] ? $level['button_label'] : '';
				if ( Members::has_level( $user->ID, $level['id'] ) ) {
					$out .= '<span class="alm-plan__mine">' . esc_html__( 'Your membership', 'aluleth-membership' ) . '</span>';
				} elseif ( '' !== $url ) {
					/* translators: %s: level name. */
					$out .= '<a class="alm-btn' . ( $level['popular'] ? '' : ' alm-btn--ghost' ) . '" href="' . esc_url( $url ) . '">' . esc_html( '' !== $label ? $label : sprintf( __( 'Join %s', 'aluleth-membership' ), $level['name'] ) ) . '</a>';
				} elseif ( $level['free_signup'] ) {
					$free[] = $level;
					$out   .= self::signup_button( $level, $label, $user->exists(), $redirect );
				}

				/**
				 * Adds to a plan of the join card, under its button (Membership Pro:
				 * "Try it free").
				 *
				 * @param string   $html     HTML (escaped by the add-on).
				 * @param array    $level    Level.
				 * @param \WP_User $user     Visitor (id 0 when not signed in or in a preview).
				 * @param string   $redirect Address to come back to.
				 */
				$out .= (string) apply_filters( 'aluleth_membership/join_card_plan', '', $level, $user, $redirect );
				$out .= '</div>';
			}
			$out .= '</div>';
		}

		if ( $free && ! $user->exists() ) {
			$out .= self::signup_form( $free, $redirect );
		}

		if ( $user->exists() ) {
			$out .= '<p class="alm-card__foot">' . esc_html(
				sprintf(
					/* translators: %s: the person's name. */
					__( 'Signed in as %s.', 'aluleth-membership' ),
					$user->display_name
				)
			) . ' <a href="' . esc_url( Settings::member_url() ) . '">' . esc_html__( 'My membership', 'aluleth-membership' ) . '</a></p>';
		} elseif ( $login ) {
			$out .= '<div class="alm-card__login">' . Account::link_form( $redirect, __( 'Already a member? Get a sign-in link by email', 'aluleth-membership' ) ) . '<p class="alm-card__alt"><a href="' . esc_url( wp_login_url( $redirect ) ) . '">' . esc_html__( 'Sign in with a password', 'aluleth-membership' ) . '</a></p></div>';
		}
		$out .= '</section>';
		return $out;
	}

	/**
	 * Card for a member whose content opens later (drip).
	 *
	 * @param int $opens Timestamp when it opens.
	 * @return string
	 */
	public static function waiting( $opens ) {
		Plugin::enqueue_front();
		$settings = Settings::get();
		$days     = (int) ceil( ( $opens - time() ) / DAY_IN_SECONDS );
		$out      = '<section class="alm-card alm-card--' . esc_attr( $settings['design'] ) . ' alm-card--waiting" style="--alm-accent:' . esc_attr( $settings['accent'] ) . '" aria-label="' . esc_attr__( 'Members only', 'aluleth-membership' ) . '">';
		$out     .= '<div class="alm-card__head"><p class="alm-card__title">' . esc_html(
			sprintf(
				/* translators: %s: date. */
				__( 'This opens for you on %s', 'aluleth-membership' ),
				wp_date( get_option( 'date_format' ), $opens )
			)
		) . '</p><p class="alm-card__text">' . esc_html(
			sprintf(
				/* translators: %d: number of days. */
				_n( 'It is part of your membership and opens in %d day. We will be glad to see you then.', 'It is part of your membership and opens in %d days. We will be glad to see you then.', max( 1, $days ), 'aluleth-membership' ),
				max( 1, $days )
			)
		) . '</p></div>';
		$out .= '<p class="alm-card__foot"><a href="' . esc_url( Settings::member_url() ) . '">' . esc_html__( 'See what is open for you now', 'aluleth-membership' ) . '</a></p></section>';
		return $out;
	}

	/**
	 * Button of a free level.
	 *
	 * @param array  $level     Level.
	 * @param string $label     Button text set on the level.
	 * @param bool   $signed_in Whether the visitor is signed in.
	 * @param string $redirect  Back address.
	 * @return string
	 */
	private static function signup_button( array $level, $label, $signed_in, $redirect ) {
		$label = '' !== $label ? $label : __( 'Sign up free', 'aluleth-membership' );
		if ( $signed_in ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-alm-form="join"><input type="hidden" name="action" value="aluleth_membership_join"><input type="hidden" name="level" value="' . (int) $level['id'] . '"><input type="hidden" name="redirect" value="' . esc_attr( $redirect ) . '"><input type="hidden" name="_almnonce" value="' . esc_attr( wp_create_nonce( 'aluleth_membership_join' ) ) . '"><button type="submit" class="alm-btn' . ( $level['popular'] ? '' : ' alm-btn--ghost' ) . '">' . esc_html( $label ) . '</button><p class="alm-form__msg" aria-live="polite"></p></form>';
		}
		return '<button type="button" class="alm-btn' . ( $level['popular'] ? '' : ' alm-btn--ghost' ) . '" data-alm-signup="' . (int) $level['id'] . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * Free sign-up form (one per card, for the free levels of the card).
	 *
	 * @param array[] $free     Free levels.
	 * @param string  $redirect Back address.
	 * @return string
	 */
	private static function signup_form( array $free, $redirect ) {
		$id    = 'alm-' . wp_rand( 1000, 999999 );
		$first = reset( $free );
		$out   = '<form class="alm-form alm-form--join" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-alm-form="join"' . ( count( $free ) > 0 ? ' hidden' : '' ) . '>';
		$out  .= '<input type="hidden" name="action" value="aluleth_membership_join"><input type="hidden" name="redirect" value="' . esc_attr( $redirect ) . '">' . Account::nonce_field();
		$out  .= '<input type="hidden" name="level" value="' . (int) $first['id'] . '" data-alm-level="1">';
		$out  .= '<p class="alm-form__title">' . esc_html(
			sprintf(
				/* translators: %s: level name. */
				__( 'Join %s for free', 'aluleth-membership' ),
				$first['name']
			)
		) . '</p>';
		$out .= '<span class="alm-form__row">';
		$out .= '<label for="' . esc_attr( $id ) . '-n">' . esc_html__( 'First name', 'aluleth-membership' ) . '<input id="' . esc_attr( $id ) . '-n" type="text" name="first_name" autocomplete="given-name"></label>';
		$out .= '<label for="' . esc_attr( $id ) . '-e">' . esc_html__( 'Email', 'aluleth-membership' ) . '<input id="' . esc_attr( $id ) . '-e" type="email" name="email" required autocomplete="email" placeholder="' . esc_attr__( 'you@example.com', 'aluleth-membership' ) . '"></label>';
		$out .= '<button type="submit" class="alm-btn">' . esc_html__( 'Send me the link', 'aluleth-membership' ) . '</button>';
		$out .= '</span>' . Account::trap();
		$out .= '<p class="alm-form__note">' . esc_html__( 'We send you a link to confirm your email. No password needed.', 'aluleth-membership' ) . '</p>';
		$out .= '<p class="alm-form__msg" aria-live="polite"></p></form>';
		return $out;
	}

	/**
	 * Whether the card is drawn in an editor (block editor preview, Elementor):
	 * it is then shown as a visitor sees it.
	 *
	 * @return bool
	 */
	public static function editing() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && is_object( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/**
	 * Address of the current page.
	 *
	 * @return string
	 */
	public static function here() {
		$post = get_post();
		if ( is_singular() && $post ) {
			return (string) get_permalink( $post );
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( remove_query_arg( array( 'alm_msg', 'alm_from' ), $uri ) );
	}
}
