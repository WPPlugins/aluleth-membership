<?php
/**
 * Settings of Aluleth Membership (one option).
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
final class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'aluleth_membership_settings';

	/**
	 * Settings of this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'member_page'    => 0,
			'join_page'      => 0,
			'role'           => 'subscriber',
			'hide_admin_bar' => true,
			'teaser_words'   => 60,
			'design'         => 'clean',
			'accent'         => '#2F5BEA',
			'card_title'     => '',
			'card_text'      => '',
			'card_login'     => true,
			'hide_comments'  => true,
			'meter_count'    => 3,
		);
	}

	/**
	 * Current settings.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * Whether a role is low-privilege: it exists and gives nothing beyond
	 * reading (`read`, `level_0`). Only such a role can be given to the
	 * accounts this plugin creates.
	 *
	 * @param string $role Role name.
	 * @return bool
	 */
	public static function low_role( $role ) {
		$object = get_role( (string) $role );
		if ( ! $object ) {
			return false;
		}
		foreach ( (array) $object->capabilities as $cap => $granted ) {
			if ( $granted && ! in_array( $cap, array( 'read', 'level_0' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Role given to the accounts this plugin creates: the chosen role while it
	 * is still low-privilege, else Subscriber.
	 *
	 * @return string
	 */
	public static function member_role() {
		$role = (string) self::get()['role'];
		return self::low_role( $role ) ? $role : 'subscriber';
	}

	/**
	 * Accent colour (a hex colour).
	 *
	 * @return string
	 */
	public static function accent() {
		$hex = sanitize_hex_color( (string) self::get()['accent'] );
		return $hex ? $hex : self::defaults()['accent'];
	}

	/**
	 * Cleans and saves settings sent by the admin screen.
	 *
	 * @param array $raw Raw values (unslashed).
	 * @return void
	 */
	public static function save( array $raw ) {
		$d      = self::defaults();
		$role   = isset( $raw['role'] ) ? sanitize_key( $raw['role'] ) : $d['role'];
		$accent = isset( $raw['accent'] ) ? sanitize_hex_color( (string) $raw['accent'] ) : '';
		$clean  = array(
			'member_page'    => isset( $raw['member_page'] ) ? absint( $raw['member_page'] ) : 0,
			'join_page'      => isset( $raw['join_page'] ) ? absint( $raw['join_page'] ) : 0,
			'role'           => self::low_role( $role ) ? $role : 'subscriber',
			'hide_admin_bar' => ! empty( $raw['hide_admin_bar'] ),
			'teaser_words'   => isset( $raw['teaser_words'] ) ? max( 0, min( 1000, absint( $raw['teaser_words'] ) ) ) : $d['teaser_words'],
			'design'         => isset( $raw['design'] ) && in_array( $raw['design'], array( 'clean', 'dark', 'soft' ), true ) ? $raw['design'] : $d['design'],
			'accent'         => $accent ? $accent : $d['accent'],
			'card_title'     => isset( $raw['card_title'] ) ? substr( sanitize_text_field( $raw['card_title'] ), 0, 120 ) : '',
			'card_text'      => isset( $raw['card_text'] ) ? substr( sanitize_textarea_field( $raw['card_text'] ), 0, 400 ) : '',
			'card_login'     => ! empty( $raw['card_login'] ),
			'hide_comments'  => ! empty( $raw['hide_comments'] ),
			'meter_count'    => isset( $raw['meter_count'] ) ? max( 0, min( 50, absint( $raw['meter_count'] ) ) ) : $d['meter_count'],
		);
		update_option( self::OPTION, $clean, false );
		self::$cache = null;
	}

	/**
	 * Only some keys (the content-access screen saves the card and teaser,
	 * the settings screen the pages and accounts).
	 *
	 * @param array $raw  Raw values.
	 * @param array $keys Keys saved from this screen.
	 * @return void
	 */
	public static function save_part( array $raw, array $keys ) {
		$current = self::get();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				// Unticked boxes are not sent.
				$current[ $key ] = is_bool( self::defaults()[ $key ] ) ? false : $current[ $key ];
			} else {
				$current[ $key ] = $raw[ $key ];
			}
		}
		self::save( $current );
	}

	/**
	 * Address of the member area, or the home page.
	 *
	 * @return string
	 */
	public static function member_url() {
		$page = (int) self::get()['member_page'];
		$url  = $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : '';
		return $url ? (string) $url : home_url( '/' );
	}

	/**
	 * Address of the plans page, or '' when there is none.
	 *
	 * @return string
	 */
	public static function join_url() {
		$page = (int) self::get()['join_page'];
		$url  = $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : '';
		return $url ? (string) $url : '';
	}
}
