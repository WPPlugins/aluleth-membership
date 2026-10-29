<?php
/**
 * The member area (shortcode [aluleth_member_area], block, Elementor widget):
 * memberships with renewal and billing, content opened for the member,
 * profile, sign-in for visitors.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Member area.
 */
final class Member_Area {

	/**
	 * Renders the member area for the current visitor.
	 *
	 * @param array $args 'content' (bool, show "Opened for you"), 'count' (number of items).
	 * @return string
	 */
	public static function render( array $args = array() ) {
		Plugin::enqueue_front();
		$settings = Settings::get();
		$style    = ' style="--alm-accent:' . esc_attr( $settings['accent'] ) . '"';
		$user     = wp_get_current_user();
		$here     = Join_Card::here();
		$message  = Account::pending_message();
		$notice   = '' !== $message ? '<p class="alm-card__notice" role="status">' . esc_html( $message ) . '</p>' : '';

		if ( ! $user->exists() ) {
			// Sent here from a members-only file: come back to it once signed in.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an address on this site, checked below.
			$back = isset( $_GET['alm_back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['alm_back'] ) ), '' ) : '';
			$here = '' !== $back ? $back : $here;
			return '<div class="alm-area alm-area--out"' . $style . '>' . $notice . '<div class="alm-box"><h2 class="alm-area__title">' . esc_html__( 'Sign in to your member area', 'aluleth-membership' ) . '</h2>'
				. Account::link_form( $here, __( 'Your email', 'aluleth-membership' ) )
				. '<p class="alm-card__alt"><a href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Sign in with a password', 'aluleth-membership' ) . '</a>'
				. ( Settings::join_url() ? ' · <a href="' . esc_url( Settings::join_url() ) . '">' . esc_html__( 'See the memberships', 'aluleth-membership' ) . '</a>' : '' ) . '</p></div></div>';
		}

		$rows    = Members::rows_of( $user->ID );
		$current = array();
		$past    = array();
		foreach ( $rows as $row ) {
			$level = Levels::get( $row->level_id );
			if ( ! $level || 'pending' === $row->status ) {
				continue;
			}
			$key = (int) $row->level_id;
			if ( Members::has_level( $user->ID, $key ) ) {
				if ( ! isset( $current[ $key ] ) && 'active' === $row->status && ( null === $row->expires_at || strtotime( $row->expires_at . ' UTC' ) > time() ) ) {
					$current[ $key ] = array( $level, $row );
				}
			} elseif ( ! isset( $past[ $key ] ) ) {
				$past[ $key ] = array( $level, $row );
			}
		}

		$first = '' !== trim( (string) $user->first_name ) ? $user->first_name : $user->display_name;
		$out   = '<div class="alm-area"' . $style . '>' . $notice;
		/* translators: %s: first name. */
		$out .= '<h2 class="alm-area__title">' . esc_html( sprintf( __( 'Hi %s', 'aluleth-membership' ), $first ) ) . '</h2>';

		$out .= '<div class="alm-area__cards">';
		if ( ! $current ) {
			$out .= '<div class="alm-box"><p class="alm-box__label">' . esc_html__( 'Your membership', 'aluleth-membership' ) . '</p><p>' . esc_html__( 'You have no membership at the moment.', 'aluleth-membership' ) . '</p>'
				. ( Settings::join_url() ? '<a class="alm-btn" href="' . esc_url( Settings::join_url() ) . '">' . esc_html__( 'See the memberships', 'aluleth-membership' ) . '</a>' : '' ) . '</div>';
		}
		foreach ( $current as $pair ) {
			list( $level, $row ) = $pair;
			$billing             = Sources::billing( $row );
			$facts               = array();
			/* translators: %s: date. */
			$facts[] = sprintf( __( 'Member since %s', 'aluleth-membership' ), self::date( $row->started_at ) );
			if ( $billing['renews'] && $billing['next'] ) {
				/* translators: %s: date. */
				$facts[] = sprintf( __( 'renews on %s', 'aluleth-membership' ), wp_date( get_option( 'date_format' ), $billing['next'] ) );
			} elseif ( $row->expires_at ) {
				/* translators: %s: date. */
				$facts[] = sprintf( __( 'ends on %s', 'aluleth-membership' ), self::date( $row->expires_at ) );
			}
			$out .= '<section class="alm-box alm-box--member"><p class="alm-box__label">' . esc_html__( 'Your membership', 'aluleth-membership' ) . ' <span class="alm-pill">' . esc_html__( 'Active', 'aluleth-membership' ) . '</span></p>';
			$out .= '<p class="alm-box__name">' . esc_html( $level['name'] ) . '</p>';
			$out .= '<p class="alm-box__facts">' . esc_html( implode( ' · ', $facts ) ) . '</p>';
			if ( '' !== $billing['url'] ) {
				$out .= '<a class="alm-btn alm-btn--light" href="' . esc_url( $billing['url'] ) . '">' . esc_html__( 'Manage billing', 'aluleth-membership' ) . '</a>';
			}
			$out .= '</section>';
		}
		$out .= '</div>';

		/**
		 * Adds to the top of the member area, under the membership cards
		 * (Membership Pro: "At a glance" and "Next for you").
		 *
		 * @param string   $html    HTML (escaped by the add-on).
		 * @param \WP_User $user    Member.
		 * @param array    $current Current memberships: level id => array( level, row ).
		 */
		$out .= (string) apply_filters( 'aluleth_membership/member_area_top', '', $user, $current );

		$show_content = ! isset( $args['content'] ) || $args['content'];
		$count        = isset( $args['count'] ) ? max( 1, min( 24, (int) $args['count'] ) ) : 6;
		if ( $show_content && $current ) {
			$items = array();
			$soon  = array();
			foreach ( Rules::content_for( array_keys( $current ), $count, true ) as $post ) {
				$opens = Rules::opens_at( $user->ID, Rules::for_post( $post->ID ) );
				if ( $opens && $opens > time() ) {
					$soon[] = array( $post, $opens );
				} elseif ( count( $items ) < $count ) {
					$items[] = $post;
				}
			}
			if ( $items ) {
				$out .= '<section class="alm-box"><h3 class="alm-box__head">' . esc_html__( 'Opened for you', 'aluleth-membership' ) . '</h3><ul class="alm-items">';
				foreach ( $items as $post ) {
					$thumb = get_the_post_thumbnail( $post, 'medium', array( 'loading' => 'lazy' ) );
					$out  .= '<li class="alm-item"><a href="' . esc_url( get_permalink( $post ) ) . '">'
						. ( $thumb ? '<span class="alm-item__img">' . $thumb . '</span>' : '' )
						. '<span class="alm-item__date">' . esc_html( get_the_date( '', $post ) ) . '</span>'
						. '<strong class="alm-item__title">' . esc_html( get_the_title( $post ) ) . '</strong></a></li>';
				}
				$out .= '</ul></section>';
			}
			if ( $soon ) {
				usort(
					$soon,
					static function ( $a, $b ) {
						return $a[1] - $b[1];
					}
				);
				$out .= '<section class="alm-box"><h3 class="alm-box__head">' . esc_html__( 'Opening soon', 'aluleth-membership' ) . '</h3><ul class="alm-soon">';
				foreach ( array_slice( $soon, 0, $count ) as $pair ) {
					$out .= '<li><strong>' . esc_html( get_the_title( $pair[0] ) ) . '</strong><span class="alm-soon__date">' . esc_html(
						sprintf(
							/* translators: %s: date. */
							__( 'Opens on %s', 'aluleth-membership' ),
							wp_date( get_option( 'date_format' ), $pair[1] )
						)
					) . '</span></li>';
				}
				$out .= '</ul></section>';
			}
		}

		/**
		 * Adds sections to the member area, after the content opened for the member
		 * (Membership Pro: "My courses").
		 *
		 * @param string   $html    HTML (escaped by the add-on).
		 * @param \WP_User $user    Member.
		 * @param array    $current Current memberships: level id => array( level, row ).
		 */
		$out .= (string) apply_filters( 'aluleth_membership/member_area_sections', '', $user, $current );

		$files = $current ? Files::for_user( $user->ID ) : array();
		if ( $files ) {
			$out .= '<section class="alm-box"><h3 class="alm-box__head">' . esc_html__( 'Downloads', 'aluleth-membership' ) . '</h3><ul class="alm-files">';
			foreach ( $files as $file ) {
				$out .= '<li><a href="' . esc_url( Files::url( $file['id'] ) ) . '">' . esc_html( $file['name'] ) . '</a><span class="alm-file__size">' . esc_html( size_format( $file['size'] ) ) . '</span></li>';
			}
			$out .= '</ul></section>';
		}

		if ( $past ) {
			$out .= '<section class="alm-box"><h3 class="alm-box__head">' . esc_html__( 'Past memberships', 'aluleth-membership' ) . '</h3><ul class="alm-past">';
			foreach ( $past as $pair ) {
				list( $level, $row ) = $pair;
				$join                = Levels::join_url( $level );
				$when                = $row->ended_at ? $row->ended_at : $row->expires_at;
				$out                .= '<li><span><strong>' . esc_html( $level['name'] ) . '</strong> · ' . esc_html(
					$when ? sprintf(
						/* translators: %s: date. */
						__( 'ended on %s', 'aluleth-membership' ),
						self::date( $when )
					) : ( 'paused' === $row->status ? __( 'paused', 'aluleth-membership' ) : __( 'ended', 'aluleth-membership' ) )
				) . '</span>' . ( '' !== $join ? '<a href="' . esc_url( $join ) . '">' . esc_html__( 'Join again', 'aluleth-membership' ) . '</a>' : '' ) . '</li>';
			}
			$out .= '</ul></section>';
		}

		$out .= '<section class="alm-box"><h3 class="alm-box__head">' . esc_html__( 'Profile', 'aluleth-membership' ) . '</h3>';
		$out .= '<form class="alm-form alm-form--profile" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-alm-form="profile">';
		$out .= '<input type="hidden" name="action" value="aluleth_membership_profile"><input type="hidden" name="redirect" value="' . esc_attr( $here ) . '"><input type="hidden" name="_almnonce" value="' . esc_attr( wp_create_nonce( 'aluleth_membership_profile' ) ) . '">';
		$out .= '<span class="alm-form__row"><label>' . esc_html__( 'First name', 'aluleth-membership' ) . '<input type="text" name="first_name" value="' . esc_attr( $user->first_name ) . '" autocomplete="given-name"></label>';
		$out .= '<label>' . esc_html__( 'Last name', 'aluleth-membership' ) . '<input type="text" name="last_name" value="' . esc_attr( $user->last_name ) . '" autocomplete="family-name"></label>';
		$out .= '<button type="submit" class="alm-btn alm-btn--ghost">' . esc_html__( 'Save', 'aluleth-membership' ) . '</button></span>';
		$out .= '<p class="alm-form__note">' . esc_html(
			sprintf(
				/* translators: %s: email address. */
				__( 'Email: %s. You sign in with a link sent to this address.', 'aluleth-membership' ),
				$user->user_email
			)
		) . '</p><p class="alm-form__msg" aria-live="polite"></p></form>';
		$out .= '<p class="alm-area__out"><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Sign out', 'aluleth-membership' ) . '</a></p></section>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Local date of a UTC datetime.
	 *
	 * @param string $utc Datetime.
	 * @return string
	 */
	private static function date( $utc ) {
		return wp_date( get_option( 'date_format' ), strtotime( $utc . ' UTC' ) );
	}
}
