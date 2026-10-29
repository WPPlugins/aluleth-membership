<?php
/**
 * Member emails: welcome, sign-in link, sign-up confirmation, "ends soon"
 * and "has ended". Sent through the Aluleth mail service (and Aluleth SMTP
 * when it is there), recorded in the contact's history.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Emails.
 */
final class Emails {

	/**
	 * Option name.
	 */
	const OPTION = 'aluleth_membership_emails';

	/**
	 * The emails, with their default text and whether they can be turned off.
	 *
	 * @return array
	 */
	public static function catalog() {
		$catalog = array(
			'welcome' => array(
				'label'    => __( 'Welcome', 'aluleth-membership' ),
				'when'     => __( 'When someone gets a level, whatever the way (purchase, sign-up, form, by hand).', 'aluleth-membership' ),
				'optional' => true,
				'subject'  => __( 'Welcome to {level}', 'aluleth-membership' ),
				'body'     => __( "Hi {first_name},\n\nYou are now a member: {level}.\n\n{sign_in_link}\n\nThe button signs you in to your member area. It works once, for 7 days. After that, ask for a new link on the site.\n\nSee you soon,\n{site}", 'aluleth-membership' ),
			),
			'confirm' => array(
				'label'    => __( 'Confirm the free sign-up', 'aluleth-membership' ),
				'when'     => __( 'When someone signs up for a free level from a join card. The level opens when they click the link.', 'aluleth-membership' ),
				'optional' => false,
				'subject'  => __( 'Confirm your email to join {level}', 'aluleth-membership' ),
				'body'     => __( "Hi {first_name},\n\nOne click and you are in:\n\n{confirm_link}\n\nIf you did not ask to join {site}, you can ignore this email.", 'aluleth-membership' ),
			),
			'login'   => array(
				'label'    => __( 'Sign-in link', 'aluleth-membership' ),
				'when'     => __( 'When a member asks for a sign-in link instead of typing a password.', 'aluleth-membership' ),
				'optional' => false,
				'subject'  => __( 'Your sign-in link for {site}', 'aluleth-membership' ),
				'body'     => __( "Hi {first_name},\n\n{sign_in_link}\n\nThe link works once, for 30 minutes. If you did not ask for it, you can ignore this email.", 'aluleth-membership' ),
			),
			'ending'  => array(
				'label'    => __( 'Ends soon', 'aluleth-membership' ),
				'when'     => __( '7 days before the end date of a membership that does not renew by itself.', 'aluleth-membership' ),
				'optional' => true,
				'subject'  => __( 'Your {level} membership ends on {end_date}', 'aluleth-membership' ),
				'body'     => __( "Hi {first_name},\n\nYour {level} membership ends on {end_date}. To keep your access, renew here:\n\n{join_link}\n\nThank you for being a member,\n{site}", 'aluleth-membership' ),
			),
			'ended'   => array(
				'label'    => __( 'Has ended', 'aluleth-membership' ),
				'when'     => __( 'When a membership ends: end date reached, refund, cancellation or removed by hand.', 'aluleth-membership' ),
				'optional' => true,
				'subject'  => __( 'Your {level} membership has ended', 'aluleth-membership' ),
				'body'     => __( "Hi {first_name},\n\nYour {level} membership has ended. You can join again at any time:\n\n{join_link}\n\n{site}", 'aluleth-membership' ),
			),
		);

		/**
		 * Filters the emails of the Emails screen (Membership Pro adds the trial
		 * emails). Each: label, when, optional, subject, body.
		 *
		 * @param array $catalog Emails by key.
		 */
		$more = (array) apply_filters( 'aluleth_membership/email_catalog', array() );
		foreach ( $more as $key => $email ) {
			if ( ! isset( $catalog[ $key ] ) && is_array( $email ) && isset( $email['label'], $email['subject'], $email['body'] ) ) {
				$catalog[ $key ] = $email + array(
					'when'     => '',
					'optional' => true,
				);
			}
		}
		return $catalog;
	}

	/**
	 * Saved text of the emails, with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::catalog() as $key => $email ) {
			$mine        = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();
			$out[ $key ] = array(
				'on'      => $email['optional'] ? ( isset( $mine['on'] ) ? (bool) $mine['on'] : true ) : true,
				'subject' => isset( $mine['subject'] ) && '' !== trim( $mine['subject'] ) ? $mine['subject'] : $email['subject'],
				'body'    => isset( $mine['body'] ) && '' !== trim( $mine['body'] ) ? $mine['body'] : $email['body'],
			);
		}
		return $out;
	}

	/**
	 * Saves the emails from the admin screen.
	 *
	 * @param array $raw Raw values (unslashed): key => on, subject, body.
	 * @return void
	 */
	public static function save( array $raw ) {
		$out = array();
		foreach ( self::catalog() as $key => $email ) {
			$mine        = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();
			$out[ $key ] = array(
				'on'      => ! $email['optional'] || ! empty( $mine['on'] ),
				'subject' => isset( $mine['subject'] ) ? substr( sanitize_text_field( $mine['subject'] ), 0, 200 ) : '',
				'body'    => isset( $mine['body'] ) ? substr( sanitize_textarea_field( $mine['body'] ), 0, 5000 ) : '',
			);
		}
		update_option( self::OPTION, $out, false );
	}

	/**
	 * Welcome email, with a sign-in link valid 7 days.
	 *
	 * @param \WP_User $user  User.
	 * @param array    $level Level.
	 * @return bool
	 */
	public static function welcome( $user, array $level ) {
		$redirect = $level['redirect'] && 'publish' === get_post_status( $level['redirect'] ) ? (string) get_permalink( $level['redirect'] ) : Settings::member_url();
		return self::send(
			'welcome',
			$user,
			array(
				'{level}'        => $level['name'],
				'{sign_in_link}' => array( Account::link( $user->ID, array( 'redirect' => $redirect ), 7 * DAY_IN_SECONDS ), __( 'Open my member area', 'aluleth-membership' ) ),
			)
		);
	}

	/**
	 * Confirmation of a free sign-up.
	 *
	 * @param \WP_User $user     User.
	 * @param array    $level    Level.
	 * @param int      $row_id   Pending access.
	 * @param string   $redirect Page to open after the click.
	 * @return bool
	 */
	public static function confirm( $user, array $level, $row_id, $redirect ) {
		return self::send(
			'confirm',
			$user,
			array(
				'{level}'        => $level['name'],
				'{confirm_link}' => array(
					Account::link(
						$user->ID,
						array(
							'row'      => (int) $row_id,
							'redirect' => $redirect,
						),
						2 * DAY_IN_SECONDS
					),
					/* translators: %s: level name. */
					sprintf( __( 'Confirm and join %s', 'aluleth-membership' ), $level['name'] ),
				),
			)
		);
	}

	/**
	 * Sign-in link.
	 *
	 * @param \WP_User $user     User.
	 * @param string   $redirect Page to open after signing in.
	 * @return bool
	 */
	public static function login( $user, $redirect ) {
		return self::send(
			'login',
			$user,
			array(
				'{sign_in_link}' => array( Account::link( $user->ID, array( 'redirect' => $redirect ), 30 * MINUTE_IN_SECONDS ), __( 'Sign in', 'aluleth-membership' ) ),
			)
		);
	}

	/**
	 * "Ends soon" reminder.
	 *
	 * @param object $row Access row.
	 * @return bool
	 */
	public static function ending_soon( $row ) {
		$user  = get_userdata( (int) $row->user_id );
		$level = Levels::get( $row->level_id );
		if ( ! $user || ! $level || ! $row->expires_at ) {
			return false;
		}
		$join = Levels::join_url( $level );
		return self::send(
			'ending',
			$user,
			array(
				'{level}'     => $level['name'],
				'{end_date}'  => wp_date( get_option( 'date_format' ), strtotime( $row->expires_at . ' UTC' ) ),
				'{join_link}' => array( '' !== $join ? $join : Settings::member_url(), __( 'Renew my membership', 'aluleth-membership' ) ),
			)
		);
	}

	/**
	 * "Has ended".
	 *
	 * @param \WP_User $user   User.
	 * @param array    $level  Level.
	 * @param string   $reason Reason.
	 * @return bool
	 */
	public static function ended( $user, array $level, $reason ) {
		$join = Levels::join_url( $level );
		$join = '' !== $join ? $join : ( Settings::join_url() ? Settings::join_url() : Settings::member_url() );
		return self::send(
			'ended',
			$user,
			array(
				'{level}'     => $level['name'],
				'{reason}'    => $reason,
				'{join_link}' => array( $join, __( 'Join again', 'aluleth-membership' ) ),
			)
		);
	}

	/**
	 * Fills an email and sends it.
	 *
	 * @param string   $key    Email key.
	 * @param \WP_User $user   Recipient.
	 * @param array    $tokens Token => text, or token => [ url, button label ].
	 * @return bool
	 */
	public static function send( $key, $user, array $tokens ) {
		$all = self::get();
		if ( ! isset( $all[ $key ] ) || ! $all[ $key ]['on'] || ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$email  = $all[ $key ];
		$first  = '' !== trim( (string) $user->first_name ) ? $user->first_name : $user->display_name;
		$tokens = array_merge(
			array(
				'{first_name}'  => $first,
				'{name}'        => $user->display_name,
				'{email}'       => $user->user_email,
				'{site}'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{member_area}' => array( Settings::member_url(), __( 'Member area', 'aluleth-membership' ) ),
			),
			$tokens
		);

		$plain   = array();
		$buttons = array();
		foreach ( $tokens as $token => $value ) {
			if ( is_array( $value ) ) {
				$plain[ $token ]   = $value[0];
				$buttons[ $token ] = '<a href="' . esc_url( $value[0] ) . '" style="display:inline-block;background:#0e1116;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:10px">' . esc_html( $value[1] ) . '</a>';
			} else {
				$plain[ $token ] = (string) $value;
			}
		}
		$subject = strtr( $email['subject'], $plain );
		$html    = '';
		foreach ( preg_split( "/\n\s*\n/", trim( $email['body'] ) ) as $para ) {
			$para = trim( $para );
			if ( isset( $buttons[ $para ] ) ) {
				$html .= '<p style="margin:22px 0">' . $buttons[ $para ] . '</p>';
				continue;
			}
			$text = esc_html( $para );
			foreach ( $tokens as $token => $value ) {
				if ( is_array( $value ) ) {
					$text = str_replace( esc_html( $token ), '<a href="' . esc_url( $value[0] ) . '">' . esc_html( $value[1] ) . '</a>', $text );
				} else {
					$text = str_replace( esc_html( $token ), esc_html( (string) $value ), $text );
				}
			}
			$html .= '<p>' . nl2br( $text ) . '</p>';
		}
		$contact = aluleth_core()->contacts()->get_by_email( $user->user_email );
		return aluleth_core()->mail()->send(
			$user->user_email,
			$subject,
			$html,
			array(
				'kind'    => 'transactional',
				'contact' => $contact ? (int) $contact->id : 0,
			)
		);
	}
}
