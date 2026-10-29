<?php
/**
 * Import from Paid Memberships Pro, Restrict Content (Kadence Memberships,
 * ex Restrict Content Pro) and Simple Membership: levels, members with an
 * access today and the content rules.
 *
 * Nothing is changed or deleted in the other plugin. Its data is read from
 * its own tables, so the import also works when it is deactivated.
 * Payments stay with the other plugin: while it is active, "Keep following"
 * checks every hour for new members, renewals and ends, and updates the
 * imported accesses.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Import.
 */
final class Import {

	/**
	 * Option: per source, the level map, "keep following" and the last result.
	 */
	const OPTION = 'aluleth_membership_import';

	/**
	 * Most members imported in one run (the next run goes on).
	 */
	const BATCH = 1500;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'aluleth_membership_hourly', array( __CLASS__, 'follow_all' ), 20 );
	}

	/**
	 * Sources this importer knows.
	 *
	 * @return array<string, string>
	 */
	public static function sources() {
		return array(
			'pmpro' => 'Paid Memberships Pro',
			'rcp'   => __( 'Restrict Content (Kadence Memberships)', 'aluleth-membership' ),
			'swpm'  => 'Simple Membership',
		);
	}

	/**
	 * Saved choices of a source.
	 *
	 * @param string $source Source.
	 * @return array{map: array<int,int>, follow: bool, last: array}
	 */
	public static function conf( $source ) {
		$all  = get_option( self::OPTION, array() );
		$conf = is_array( $all ) && isset( $all[ $source ] ) && is_array( $all[ $source ] ) ? $all[ $source ] : array();
		return array(
			'map'    => isset( $conf['map'] ) ? array_map( 'intval', (array) $conf['map'] ) : array(),
			'follow' => ! empty( $conf['follow'] ),
			'last'   => isset( $conf['last'] ) && is_array( $conf['last'] ) ? $conf['last'] : array(),
		);
	}

	/**
	 * Saves the choices of a source.
	 *
	 * @param string $source Source.
	 * @param array  $conf   Choices.
	 * @return void
	 */
	private static function save_conf( $source, array $conf ) {
		$all            = get_option( self::OPTION, array() );
		$all            = is_array( $all ) ? $all : array();
		$all[ $source ] = $conf;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Full name of a table of the site.
	 *
	 * @param string $name Name without the prefix.
	 * @return string
	 */
	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $name Name without the prefix.
	 * @return bool
	 */
	private static function has_table( $name ) {
		global $wpdb;
		$table = self::t( $name );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Sources with data on this site.
	 *
	 * @return array<string, string>
	 */
	public static function detect() {
		$out = array();
		foreach ( self::sources() as $key => $label ) {
			$tables = self::tables( $key );
			$ok     = true;
			foreach ( $tables as $name ) {
				if ( ! self::has_table( $name ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok && self::levels( $key ) ) {
				$out[ $key ] = $label;
			}
		}
		return $out;
	}

	/**
	 * Tables a source needs.
	 *
	 * @param string $source Source.
	 * @return string[]
	 */
	private static function tables( $source ) {
		switch ( $source ) {
			case 'pmpro':
				return array( 'pmpro_membership_levels', 'pmpro_memberships_users' );
			case 'rcp':
				return array( 'restrict_content_pro', 'rcp_memberships' );
			case 'swpm':
				return array( 'swpm_membership_tbl', 'swpm_members_tbl' );
		}
		return array( 'x-none' );
	}

	/**
	 * Whether the other plugin is active now (payments and new members happen there).
	 *
	 * @param string $source Source.
	 * @return bool
	 */
	public static function source_active( $source ) {
		switch ( $source ) {
			case 'pmpro':
				return defined( 'PMPRO_VERSION' );
			case 'rcp':
				return defined( 'RCP_PLUGIN_VERSION' ) || function_exists( 'rcp_get_membership' );
			case 'swpm':
				return defined( 'SIMPLE_WP_MEMBERSHIP_VER' ) || class_exists( 'SimpleWpMembership', false );
		}
		return false;
	}

	/**
	 * Converts a date of the other plugin (site time) to UTC, or null.
	 *
	 * @param string|null $date Date.
	 * @return string|null
	 */
	private static function utc( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date || 0 === strpos( $date, '0000-00-00' ) || 'none' === strtolower( $date ) ) {
			return null;
		}
		if ( 10 === strlen( $date ) ) {
			$date .= ' 00:00:00';
		}
		return get_gmt_from_date( $date );
	}

	/**
	 * Money text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private static function money( $amount, $currency ) {
		$symbols  = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'CAD' => 'CA$',
			'AUD' => 'A$',
			'JPY' => '¥',
			'INR' => '₹',
			'BRL' => 'R$',
			'CHF' => 'CHF ',
		);
		$currency = strtoupper( (string) $currency );
		$amount   = (float) $amount;
		$number   = number_format_i18n( $amount, floor( $amount ) === $amount ? 0 : 2 );
		return ( isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ' ) . $number;
	}

	/**
	 * "$7 / month", "$20 / 3 months".
	 *
	 * @param string $money Money text.
	 * @param int    $n     Number of units.
	 * @param string $unit  day, week, month or year.
	 * @return string
	 */
	private static function per( $money, $n, $unit ) {
		$n     = max( 1, (int) $n );
		$units = array(
			'day'   => array(
				__( 'day', 'aluleth-membership' ),
				/* translators: %d: number of days. */
				_n( '%d day', '%d days', $n, 'aluleth-membership' ),
			),
			'week'  => array(
				__( 'week', 'aluleth-membership' ),
				/* translators: %d: number of weeks. */
				_n( '%d week', '%d weeks', $n, 'aluleth-membership' ),
			),
			'month' => array(
				__( 'month', 'aluleth-membership' ),
				/* translators: %d: number of months. */
				_n( '%d month', '%d months', $n, 'aluleth-membership' ),
			),
			'year'  => array(
				__( 'year', 'aluleth-membership' ),
				/* translators: %d: number of years. */
				_n( '%d year', '%d years', $n, 'aluleth-membership' ),
			),
		);
		$pick  = isset( $units[ $unit ] ) ? $units[ $unit ] : $units['month'];
		$text  = 1 === $n ? $pick[0] : sprintf( $pick[1], $n );
		return $money . ' / ' . $text;
	}

	/**
	 * Length of a level in our terms.
	 *
	 * @param int    $n    Number.
	 * @param string $unit day, week, month, year (any case).
	 * @return array{duration: string, duration_n: int}
	 */
	private static function length( $n, $unit ) {
		$n    = (int) $n;
		$unit = strtolower( (string) $unit );
		if ( $n <= 0 ) {
			return array(
				'duration'   => 'lifetime',
				'duration_n' => 1,
			);
		}
		if ( 0 === strpos( $unit, 'week' ) ) {
			return array(
				'duration'   => 'days',
				'duration_n' => $n * 7,
			);
		}
		$map = array(
			'day'   => 'days',
			'month' => 'months',
			'year'  => 'years',
		);
		$key = rtrim( $unit, 's' );
		return array(
			'duration'   => isset( $map[ $key ] ) ? $map[ $key ] : 'lifetime',
			'duration_n' => $n,
		);
	}

	/**
	 * Levels of a source.
	 *
	 * @param string $source Source.
	 * @return array<int, array{id:int, name:string, description:string, price:string, duration:string, duration_n:int, paid:bool}>
	 */
	public static function levels( $source ) {
		global $wpdb;
		$out = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		switch ( $source ) {
			case 'pmpro':
				if ( ! self::has_table( 'pmpro_membership_levels' ) ) {
					return array();
				}
				$currency = (string) get_option( 'pmpro_currency', 'USD' );
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', self::t( 'pmpro_membership_levels' ) ) ) as $row ) {
					$billing = (float) $row->billing_amount;
					$initial = (float) $row->initial_payment;
					if ( $billing > 0 && (int) $row->cycle_number > 0 ) {
						$price = self::per( self::money( $billing, $currency ), (int) $row->cycle_number, strtolower( (string) $row->cycle_period ) );
					} else {
						$price = self::money( $initial, $currency );
					}
					$out[ (int) $row->id ] = array_merge(
						array(
							'id'          => (int) $row->id,
							'name'        => (string) $row->name,
							'description' => wp_strip_all_tags( (string) $row->description ),
							'price'       => $price,
							'paid'        => $billing > 0 || $initial > 0,
						),
						self::length( $row->expiration_number, $row->expiration_period )
					);
				}
				break;
			case 'rcp':
				if ( ! self::has_table( 'restrict_content_pro' ) ) {
					return array();
				}
				$opts     = get_option( 'rcp_settings', array() );
				$currency = is_array( $opts ) && ! empty( $opts['currency'] ) ? (string) $opts['currency'] : 'USD';
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY list_order, id', self::t( 'restrict_content_pro' ) ) ) as $row ) {
					$amount                = (float) $row->price;
					$out[ (int) $row->id ] = array_merge(
						array(
							'id'          => (int) $row->id,
							'name'        => (string) $row->name,
							'description' => wp_strip_all_tags( (string) $row->description ),
							'price'       => $amount > 0 && (int) $row->duration > 0 ? self::per( self::money( $amount, $currency ), (int) $row->duration, rtrim( strtolower( (string) $row->duration_unit ), 's' ) ) : self::money( $amount, $currency ),
							'paid'        => $amount > 0,
						),
						self::length( $row->duration, $row->duration_unit )
					);
				}
				break;
			case 'swpm':
				if ( ! self::has_table( 'swpm_membership_tbl' ) ) {
					return array();
				}
				$units = array(
					1 => 'day',
					2 => 'week',
					3 => 'month',
					4 => 'year',
				);
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id <> %d ORDER BY id', self::t( 'swpm_membership_tbl' ), 1 ) ) as $row ) {
					$type                  = (int) $row->subscription_duration_type;
					$out[ (int) $row->id ] = array_merge(
						array(
							'id'          => (int) $row->id,
							'name'        => (string) $row->alias,
							'description' => '',
							'price'       => '',
							'paid'        => false,
						),
						isset( $units[ $type ] ) ? self::length( (int) $row->subscription_period, $units[ $type ] ) : self::length( 0, '' )
					);
				}
				break;
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Memberships of a source: the ones giving access today, or every one of
	 * an email (for "Check one member").
	 *
	 * @param string $source Source.
	 * @param array  $args   'email' (only this person, every status), 'limit'.
	 * @return array[]|null  Each: ref, user_id, email, first, last, level, status, active, start, end. Null when the source can't be read.
	 */
	public static function memberships( $source, array $args = array() ) {
		global $wpdb;
		$email = isset( $args['email'] ) ? sanitize_email( (string) $args['email'] ) : '';
		$one   = '' !== $email ? 1 : 0;
		$limit = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50000;
		$out   = array();
		foreach ( self::tables( $source ) as $name ) {
			if ( ! self::has_table( $name ) ) {
				return null;
			}
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		switch ( $source ) {
			case 'pmpro':
				$local = current_time( 'mysql' );
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT mu.id, mu.user_id, mu.membership_id, mu.status, mu.startdate, mu.enddate, u.user_email FROM %i mu INNER JOIN %i u ON u.ID = mu.user_id WHERE ( %d = 1 AND u.user_email = %s ) OR ( %d = 0 AND mu.status = 'active' AND ( mu.enddate IS NULL OR mu.enddate < '1971-01-01' OR mu.enddate > %s ) ) ORDER BY mu.id LIMIT %d", self::t( 'pmpro_memberships_users' ), $wpdb->users, $one, $email, $one, $local, $limit ) );
				if ( null === $rows && '' !== $wpdb->last_error ) {
					return null;
				}
				foreach ( (array) $rows as $row ) {
					$end   = self::utc( $row->enddate );
					$out[] = array(
						'ref'     => 'mu:' . (int) $row->id,
						'user_id' => (int) $row->user_id,
						'email'   => (string) $row->user_email,
						'first'   => '',
						'last'    => '',
						'level'   => (int) $row->membership_id,
						'status'  => (string) $row->status,
						'active'  => 'active' === $row->status && ( null === $end || $end > Util::now() ),
						'start'   => self::utc( $row->startdate ),
						'end'     => $end,
					);
				}
				break;
			case 'rcp':
				$local = current_time( 'mysql' );
				$cust  = self::has_table( 'rcp_customers' ) ? self::t( 'rcp_customers' ) : self::t( 'rcp_memberships' );
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT m.id, m.object_id, m.status, m.created_date, m.activated_date, m.expiration_date, m.auto_renew, u.ID AS uid, u.user_email FROM %i m LEFT JOIN %i c ON c.id = m.customer_id INNER JOIN %i u ON u.ID = COALESCE( NULLIF( m.user_id, 0 ), c.user_id ) WHERE ( m.disabled IS NULL OR m.disabled = 0 ) AND ( m.object_type IS NULL OR m.object_type = '' OR m.object_type = 'membership' ) AND ( ( %d = 1 AND u.user_email = %s ) OR ( %d = 0 AND ( m.status = 'active' OR ( m.status = 'cancelled' AND m.expiration_date > %s ) ) ) ) ORDER BY m.id LIMIT %d", self::t( 'rcp_memberships' ), $cust, $wpdb->users, $one, $email, $one, $local, $limit ) );
				if ( null === $rows && '' !== $wpdb->last_error ) {
					return null;
				}
				foreach ( (array) $rows as $row ) {
					$end = self::utc( $row->expiration_date );
					// A membership that renews by itself has no end here: it follows Restrict Content.
					if ( 'active' === $row->status && (int) $row->auto_renew ) {
						$end = null;
					}
					$start = self::utc( $row->activated_date );
					$out[] = array(
						'ref'     => 'm:' . (int) $row->id,
						'user_id' => (int) $row->uid,
						'email'   => (string) $row->user_email,
						'first'   => '',
						'last'    => '',
						'level'   => (int) $row->object_id,
						'status'  => (string) $row->status,
						'active'  => ( 'active' === $row->status || 'cancelled' === $row->status ) && ( null === $end || $end > Util::now() ),
						'start'   => $start ? $start : self::utc( $row->created_date ),
						'end'     => $end,
					);
				}
				break;
			case 'swpm':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT member_id, user_name, email, first_name, last_name, membership_level, more_membership_levels, account_state, subscription_starts, member_since FROM %i WHERE ( %d = 1 AND email = %s ) OR ( %d = 0 AND account_state = 'active' ) ORDER BY member_id LIMIT %d", self::t( 'swpm_members_tbl' ), $one, $email, $one, $limit ) );
				if ( null === $rows && '' !== $wpdb->last_error ) {
					return null;
				}
				$levels = array();
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, subscription_period, subscription_duration_type FROM %i', self::t( 'swpm_membership_tbl' ) ) ) as $lv ) {
					$levels[ (int) $lv->id ] = $lv;
				}
				foreach ( (array) $rows as $row ) {
					$user    = get_user_by( 'login', (string) $row->user_name );
					$user    = $user ? $user : get_user_by( 'email', (string) $row->email );
					$start   = $row->subscription_starts && 0 !== strpos( (string) $row->subscription_starts, '0000' ) ? (string) $row->subscription_starts : (string) $row->member_since;
					$level_s = array_merge( array( (int) $row->membership_level ), array_map( 'intval', array_filter( explode( ',', (string) $row->more_membership_levels ) ) ) );
					foreach ( array_unique( array_filter( $level_s ) ) as $level_id ) {
						$end   = isset( $levels[ $level_id ] ) ? self::swpm_end( $levels[ $level_id ], $start ) : null;
						$out[] = array(
							'ref'     => 'm:' . (int) $row->member_id . ':' . $level_id,
							'user_id' => $user ? (int) $user->ID : 0,
							'email'   => (string) $row->email,
							'first'   => (string) $row->first_name,
							'last'    => (string) $row->last_name,
							'level'   => $level_id,
							'status'  => (string) $row->account_state,
							'active'  => 'active' === $row->account_state && ( null === $end || $end > Util::now() ),
							'start'   => self::utc( $start ),
							'end'     => $end,
						);
					}
				}
				break;
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * End date of a Simple Membership level for a member, in UTC.
	 *
	 * @param object $level Level row.
	 * @param string $start Start date (Y-m-d).
	 * @return string|null
	 */
	private static function swpm_end( $level, $start ) {
		$type   = (int) $level->subscription_duration_type;
		$period = (string) $level->subscription_period;
		$units  = array(
			1 => 'day',
			2 => 'week',
			3 => 'month',
			4 => 'year',
		);
		if ( isset( $units[ $type ] ) && (int) $period > 0 && $start ) {
			$time = strtotime( $start . ' +' . (int) $period . ' ' . $units[ $type ] );
			return $time ? self::utc( gmdate( 'Y-m-d H:i:s', $time ) ) : null;
		}
		if ( 5 === $type && strtotime( $period ) ) {
			return self::utc( gmdate( 'Y-m-d', strtotime( $period ) ) );
		}
		if ( 6 === $type && strtotime( $period ) ) {
			// Every year on the same day: the next one.
			$next = strtotime( gmdate( 'Y' ) . '-' . gmdate( 'm-d', strtotime( $period ) ) );
			if ( $next && $next < time() ) {
				$next = strtotime( '+1 year', $next );
			}
			return $next ? self::utc( gmdate( 'Y-m-d', $next ) ) : null;
		}
		return null;
	}

	/**
	 * Content rules of a source.
	 *
	 * @param string $source Source.
	 * @return array{rules: array[], skipped: array<string, int>}
	 *   Each rule: kind, target, levels (source level ids, 'any' or 'any-paid'), mode.
	 */
	public static function rules( $source ) {
		global $wpdb;
		$rules   = array();
		$skipped = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		switch ( $source ) {
			case 'pmpro':
				$mode  = get_option( 'pmpro_showexcerpts' ) ? 'teaser' : 'card';
				$posts = array();
				if ( self::has_table( 'pmpro_memberships_pages' ) ) {
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT membership_id, page_id FROM %i', self::t( 'pmpro_memberships_pages' ) ) ) as $row ) {
						$posts[ (int) $row->page_id ][] = (int) $row->membership_id;
					}
				}
				foreach ( $posts as $post_id => $levels ) {
					$rules[] = self::rule( 'post', (string) $post_id, $levels, $mode );
				}
				$terms = array();
				if ( self::has_table( 'pmpro_memberships_categories' ) ) {
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT membership_id, category_id FROM %i', self::t( 'pmpro_memberships_categories' ) ) ) as $row ) {
						$terms[ (int) $row->category_id ][] = (int) $row->membership_id;
					}
				}
				foreach ( $terms as $term_id => $levels ) {
					$rules[] = self::rule( 'term', 'category:' . $term_id, $levels, $mode );
				}
				break;
			case 'rcp':
				$opts    = get_option( 'rcp_settings', array() );
				$excerpt = is_array( $opts ) && isset( $opts['content_excerpts'] ) ? (string) $opts['content_excerpts'] : 'individual';
				$metas   = array();
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_key, meta_value FROM %i WHERE meta_key IN ( %s, %s, %s, %s, %s )', $wpdb->postmeta, 'rcp_subscription_level', '_is_paid', 'rcp_access_level', 'rcp_user_level', 'rcp_show_excerpt' ) ) as $row ) {
					$metas[ (int) $row->post_id ][ $row->meta_key ] = maybe_unserialize( $row->meta_value );
				}
				foreach ( $metas as $post_id => $meta ) {
					$levels = self::rcp_levels( isset( $meta['rcp_subscription_level'] ) ? $meta['rcp_subscription_level'] : '', ! empty( $meta['_is_paid'] ) );
					$mode   = 'always' === $excerpt || ( 'individual' === $excerpt && ! empty( $meta['rcp_show_excerpt'] ) ) ? 'teaser' : 'card';
					if ( null !== $levels ) {
						$rules[] = self::rule( 'post', (string) $post_id, $levels, $mode );
					} elseif ( self::rcp_other( $meta ) ) {
						$skipped['role'] = isset( $skipped['role'] ) ? $skipped['role'] + 1 : 1;
					}
				}
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT term_id, meta_value FROM %i WHERE meta_key = %s', $wpdb->termmeta, 'rcp_restricted_meta' ) ) as $row ) {
					$meta = maybe_unserialize( $row->meta_value );
					$term = get_term( (int) $row->term_id );
					if ( ! is_array( $meta ) || ! $term || is_wp_error( $term ) ) {
						continue;
					}
					$levels = self::rcp_levels( ! empty( $meta['subscriptions'] ) ? (array) $meta['subscriptions'] : '', ! empty( $meta['paid_only'] ) );
					if ( null !== $levels ) {
						$rules[] = self::rule( 'term', $term->taxonomy . ':' . (int) $term->term_id, $levels, 'always' === $excerpt ? 'teaser' : 'card' );
					} elseif ( ! empty( $meta['access_level'] ) && 'none' !== strtolower( (string) $meta['access_level'] ) ) {
						$skipped['role'] = isset( $skipped['role'] ) ? $skipped['role'] + 1 : 1;
					}
				}
				foreach ( (array) get_option( 'rcp_restricted_post_types', array() ) as $type => $meta ) {
					$meta   = (array) $meta;
					$levels = self::rcp_levels( isset( $meta['subscription_level'] ) ? $meta['subscription_level'] : '', ! empty( $meta['is_paid'] ) );
					if ( null !== $levels && post_type_exists( $type ) ) {
						$rules[] = self::rule( 'type', (string) $type, $levels, 'always' === $excerpt ? 'teaser' : 'card' );
					} elseif ( self::rcp_other( $meta ) ) {
						$skipped['role'] = isset( $skipped['role'] ) ? $skipped['role'] + 1 : 1;
					}
				}
				break;
			case 'swpm':
				if ( ! self::has_table( 'swpm_membership_tbl' ) ) {
					break;
				}
				$lists = array(
					'post'     => array( 'post_list', 'page_list', 'custom_post_list' ),
					'category' => array( 'category_list' ),
				);
				$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, post_list, page_list, custom_post_list, category_list FROM %i', self::t( 'swpm_membership_tbl' ) ) );
				$prot  = array(
					'post'     => array(),
					'category' => array(),
				);
				$allow = array(
					'post'     => array(),
					'category' => array(),
				);
				foreach ( $rows as $row ) {
					foreach ( $lists as $kind => $columns ) {
						foreach ( $columns as $column ) {
							$ids = maybe_unserialize( (string) $row->$column );
							foreach ( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) as $id ) {
								if ( 1 === (int) $row->id ) {
									$prot[ $kind ][ $id ] = true;
								} else {
									$allow[ $kind ][ $id ][] = (int) $row->id;
								}
							}
						}
					}
				}
				foreach ( $prot as $kind => $ids ) {
					foreach ( array_keys( $ids ) as $id ) {
						if ( empty( $allow[ $kind ][ $id ] ) ) {
							$skipped['no_level'] = isset( $skipped['no_level'] ) ? $skipped['no_level'] + 1 : 1;
							continue;
						}
						$rules[] = 'post' === $kind ? self::rule( 'post', (string) $id, $allow[ $kind ][ $id ], 'card' ) : self::rule( 'term', 'category:' . $id, $allow[ $kind ][ $id ], 'card' );
					}
				}
				break;
		}
		// phpcs:enable
		return array(
			'rules'   => $rules,
			'skipped' => $skipped,
		);
	}

	/**
	 * One rule of a source.
	 *
	 * @param string       $kind   Kind.
	 * @param string       $target Target.
	 * @param int[]|string $levels Source levels, 'any' or 'any-paid'.
	 * @param string       $mode   Mode.
	 * @return array
	 */
	private static function rule( $kind, $target, $levels, $mode ) {
		return array(
			'kind'   => $kind,
			'target' => $target,
			'levels' => is_array( $levels ) ? array_values( array_unique( array_map( 'intval', $levels ) ) ) : $levels,
			'mode'   => $mode,
		);
	}

	/**
	 * Levels of a Restrict Content restriction, or null when it has none.
	 *
	 * @param mixed $value   Saved levels: ids, 'any', 'any-paid' or 'all'.
	 * @param bool  $is_paid "Paid members only".
	 * @return int[]|string|null
	 */
	private static function rcp_levels( $value, $is_paid ) {
		if ( 'any' === $value || 'any-paid' === $value ) {
			return $value;
		}
		if ( is_array( $value ) || ( is_numeric( $value ) && (int) $value > 0 ) ) {
			$ids = array_values( array_filter( array_map( 'intval', array_filter( (array) $value, 'is_numeric' ) ) ) );
			if ( $ids ) {
				return $ids;
			}
		}
		return $is_paid ? 'any-paid' : null;
	}

	/**
	 * Whether a Restrict Content restriction uses a role or an access level (not imported).
	 *
	 * @param array $meta Restriction.
	 * @return bool
	 */
	private static function rcp_other( array $meta ) {
		$access = isset( $meta['rcp_access_level'] ) ? $meta['rcp_access_level'] : ( isset( $meta['access_level'] ) ? $meta['access_level'] : '' );
		$role   = isset( $meta['rcp_user_level'] ) ? $meta['rcp_user_level'] : ( isset( $meta['user_level'] ) ? $meta['user_level'] : '' );
		$role   = is_array( $role ) ? reset( $role ) : $role;
		return ( '' !== (string) $access && 'none' !== strtolower( (string) $access ) && '0' !== (string) $access ) || ( '' !== (string) $role && ! in_array( strtolower( (string) $role ), array( 'all', 'none' ), true ) );
	}

	/**
	 * Our levels for source levels.
	 *
	 * @param int[]|string $levels Source levels, 'any' or 'any-paid'.
	 * @param array        $map    Source level => our level (0: not imported).
	 * @param array        $source Source levels.
	 * @return int[]|string|null   Null when none of them was imported.
	 */
	private static function map_levels( $levels, array $map, array $source ) {
		if ( 'any' === $levels ) {
			return 'any';
		}
		if ( 'any-paid' === $levels ) {
			$levels = array();
			foreach ( $source as $id => $level ) {
				if ( $level['paid'] ) {
					$levels[] = $id;
				}
			}
		}
		$out = array();
		foreach ( (array) $levels as $id ) {
			if ( ! empty( $map[ (int) $id ] ) && Levels::get( $map[ (int) $id ] ) ) {
				$out[] = (int) $map[ (int) $id ];
			}
		}
		$out = array_values( array_unique( $out ) );
		return $out ? $out : null;
	}

	/**
	 * Level map shown first: what was used last time, or "create" for each.
	 *
	 * @param string $source Source.
	 * @return array<int, int|string> Source level => our level id, 'new' or 0.
	 */
	public static function default_map( $source ) {
		$saved = self::conf( $source )['map'];
		$out   = array();
		foreach ( self::levels( $source ) as $id => $level ) {
			if ( isset( $saved[ $id ] ) && ( 0 === $saved[ $id ] || Levels::get( $saved[ $id ] ) ) ) {
				$out[ $id ] = $saved[ $id ];
			} else {
				$out[ $id ] = 'new';
			}
		}
		return $out;
	}

	/**
	 * Runs an import.
	 *
	 * @param string $source Source.
	 * @param array  $args   'map' (source level => 'new', our id or 0), 'members', 'rules', 'follow', 'test' (bool).
	 * @return array|\WP_Error Result.
	 */
	public static function run( $source, array $args ) {
		if ( ! isset( self::detect()[ $source ] ) ) {
			return new \WP_Error( 'alm_import_source', __( 'Nothing to import from this plugin.', 'aluleth-membership' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long imports.
		}
		$src    = self::levels( $source );
		$choice = isset( $args['map'] ) ? (array) $args['map'] : array();
		$map    = array();
		$result = array(
			'source'        => $source,
			'time'          => time(),
			'test'          => ! empty( $args['test'] ),
			'levels_new'    => 0,
			'levels_mapped' => 0,
			'members'       => 0,
			'already'       => 0,
			'updated'       => 0,
			'no_level'      => 0,
			'no_account'    => 0,
			'left'          => 0,
			'rules'         => 0,
			'rules_kept'    => 0,
			'rules_skipped' => array(),
		);
		foreach ( $src as $id => $level ) {
			$pick = isset( $choice[ $id ] ) ? (string) $choice[ $id ] : 'new';
			if ( 'new' === $pick ) {
				$new = Levels::save(
					0,
					$level['name'],
					Levels::clean(
						array(
							'description' => $level['description'],
							'price'       => $level['price'],
							'on_card'     => 0,
							'duration'    => $level['duration'],
							'duration_n'  => $level['duration_n'],
						)
					)
				);
				Levels::flush();
				$map[ $id ] = (int) $new;
				if ( $new ) {
					++$result['levels_new'];
				}
			} else {
				$map[ $id ] = Levels::get( (int) $pick ) ? (int) $pick : 0;
				if ( $map[ $id ] ) {
					++$result['levels_mapped'];
				}
			}
		}
		$conf        = self::conf( $source );
		$conf['map'] = $map;
		if ( isset( $args['follow'] ) ) {
			$conf['follow'] = (bool) $args['follow'];
		}

		if ( ! empty( $args['members'] ) ) {
			$all = self::memberships( $source );
			if ( null === $all ) {
				return new \WP_Error( 'alm_import_read', __( 'The members of this plugin could not be read.', 'aluleth-membership' ) );
			}
			$done = 0;
			$cap  = ! empty( $args['test'] ) ? 10 : self::BATCH;
			foreach ( $all as $m ) {
				if ( ! $m['active'] ) {
					continue;
				}
				if ( empty( $map[ $m['level'] ] ) ) {
					++$result['no_level'];
					continue;
				}
				$status = self::apply( $source, $m, $map[ $m['level'] ] );
				if ( 'no_account' === $status ) {
					++$result['no_account'];
					continue;
				}
				if ( 'already' === $status ) {
					++$result['already'];
					continue;
				}
				if ( 'updated' === $status ) {
					++$result['updated'];
					continue;
				}
				++$result['members'];
				++$done;
				if ( $done >= $cap ) {
					break;
				}
			}
			// Still to import (a test, or a very large site: run again).
			foreach ( $all as $m ) {
				if ( $m['active'] && ! empty( $map[ $m['level'] ] ) && ! self::has_ref( $source, $m['ref'], $map[ $m['level'] ] ) ) {
					++$result['left'];
				}
			}
		}

		if ( ! empty( $args['rules'] ) ) {
			$found = self::rules( $source );
			foreach ( $found['skipped'] as $why => $n ) {
				$result['rules_skipped'][ $why ] = $n;
			}
			$existing = array();
			foreach ( Rules::all() as $rule ) {
				$existing[ $rule->kind . '|' . $rule->target ] = true;
			}
			foreach ( $found['rules'] as $rule ) {
				$levels = self::map_levels( $rule['levels'], $map, $src );
				if ( null === $levels ) {
					$result['rules_skipped']['level'] = isset( $result['rules_skipped']['level'] ) ? $result['rules_skipped']['level'] + 1 : 1;
					continue;
				}
				$target = Rules::clean_target( $rule['kind'], $rule['target'] );
				if ( '' === $target ) {
					$result['rules_skipped']['gone'] = isset( $result['rules_skipped']['gone'] ) ? $result['rules_skipped']['gone'] + 1 : 1;
					continue;
				}
				if ( isset( $existing[ $rule['kind'] . '|' . $target ] ) ) {
					++$result['rules_kept'];
					continue;
				}
				if ( Rules::save( $rule['kind'], $target, $levels, $rule['mode'] ) ) {
					$existing[ $rule['kind'] . '|' . $target ] = true;
					++$result['rules'];
				}
			}
		}

		$conf['last'] = $result;
		self::save_conf( $source, $conf );
		return $result;
	}

	/**
	 * Whether an access of a source membership exists (not ended).
	 *
	 * @param string $source Source.
	 * @param string $ref    Reference.
	 * @param int    $level  Our level.
	 * @return object|null
	 */
	private static function has_ref( $source, $ref, $level ) {
		foreach ( Members::rows_by_ref( $source, $ref ) as $row ) {
			if ( (int) $row->level_id === (int) $level && 'ended' !== $row->status ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Gives or updates the access of one source membership.
	 *
	 * @param string $source Source.
	 * @param array  $m      Membership.
	 * @param int    $level  Our level.
	 * @return string imported, already, updated or no_account.
	 */
	private static function apply( $source, array $m, $level ) {
		$row = self::has_ref( $source, $m['ref'], $level );
		if ( $row ) {
			$now_end = $row->expires_at ? (string) $row->expires_at : null;
			if ( $now_end !== $m['end'] ) {
				Members::update_by_ref( $source, $m['ref'], array( 'expires_at' => $m['end'] ) );
				return 'updated';
			}
			return 'already';
		}
		$user_id = (int) $m['user_id'];
		if ( ! $user_id && is_email( $m['email'] ) ) {
			list( $user_id ) = Members::ensure_user( $m['email'], $m['first'], $m['last'] );
		}
		if ( ! $user_id ) {
			return 'no_account';
		}
		$id = Members::grant(
			$user_id,
			$level,
			$source,
			$m['ref'],
			array(
				'expires_at' => $m['end'],
				'started_at' => $m['start'],
				'notify'     => false,
			)
		);
		return $id ? 'imported' : 'no_account';
	}

	/**
	 * Whether the accesses of a source get no member emails from Aluleth:
	 * while the import follows another plugin, that plugin writes to its members.
	 *
	 * @param string $source Source of an access.
	 * @return bool
	 */
	public static function quiet( $source ) {
		$quiet = isset( self::sources()[ $source ] ) && self::conf( $source )['follow'];

		/**
		 * Filters whether the "ends soon" and "has ended" emails are skipped for
		 * the accesses of a source (an add-on that sends its own: Membership Pro
		 * trials).
		 *
		 * @param bool   $quiet  Skip the emails.
		 * @param string $source Source.
		 */
		return (bool) apply_filters( 'aluleth_membership/quiet_source', $quiet, $source );
	}

	/**
	 * Hourly: for each source followed, while its plugin is active, gives the
	 * access to its new members, updates end dates and ends the accesses it
	 * ended.
	 *
	 * @return void
	 */
	public static function follow_all() {
		foreach ( array_keys( self::sources() ) as $source ) {
			$conf = self::conf( $source );
			if ( $conf['follow'] && $conf['map'] && self::source_active( $source ) ) {
				self::follow( $source );
			}
		}
	}

	/**
	 * Follows one source now.
	 *
	 * @param string $source Source.
	 * @return array{added: int, updated: int, ended: int}|null Null when the source can't be read.
	 */
	public static function follow( $source ) {
		global $wpdb;
		$conf = self::conf( $source );
		$all  = self::memberships( $source );
		$out  = array(
			'added'   => 0,
			'updated' => 0,
			'ended'   => 0,
		);
		if ( null === $all || ! $conf['map'] ) {
			return null;
		}
		$live = array();
		foreach ( $all as $m ) {
			if ( ! $m['active'] || empty( $conf['map'][ $m['level'] ] ) ) {
				continue;
			}
			$level                            = (int) $conf['map'][ $m['level'] ];
			$live[ $m['ref'] . '|' . $level ] = true;
			$status                           = self::apply( $source, $m, $level );
			if ( 'imported' === $status ) {
				++$out['added'];
			} elseif ( 'updated' === $status ) {
				++$out['updated'];
			}
		}
		// Accesses the other plugin no longer gives.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s AND status <> %s', Members::table(), $source, 'ended' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			if ( ! isset( $live[ $row->source_ref . '|' . (int) $row->level_id ] ) ) {
				Members::end( $row, 'cancelled', false );
				++$out['ended'];
			}
		}
		return $out;
	}

	/**
	 * "Check one member": what the other plugin has for an email and what the
	 * import gives.
	 *
	 * @param string $source Source.
	 * @param string $email  Email.
	 * @return array[]
	 */
	public static function check( $source, $email ) {
		$rows = self::memberships( $source, array( 'email' => $email ) );
		$src  = self::levels( $source );
		$map  = self::default_map( $source );
		$out  = array();
		foreach ( (array) $rows as $m ) {
			$pick  = isset( $map[ $m['level'] ] ) ? $map[ $m['level'] ] : 0;
			$out[] = array(
				'level'  => isset( $src[ $m['level'] ] ) ? $src[ $m['level'] ]['name'] : '#' . $m['level'],
				'status' => $m['status'],
				'active' => $m['active'],
				'start'  => $m['start'],
				'end'    => $m['end'],
				'to'     => 'new' === $pick ? 'new' : ( $pick && Levels::get( $pick ) ? Levels::get( $pick )['name'] : '' ),
				'done'   => $pick && 'new' !== $pick ? (bool) self::has_ref( $source, $m['ref'], (int) $pick ) : false,
			);
		}
		return $out;
	}

	/**
	 * Numbers shown before importing.
	 *
	 * @param string $source Source.
	 * @return array{levels: int, members: int, per_level: array<int,int>, rules: int, skipped: array}
	 */
	public static function summary( $source ) {
		$members = (array) self::memberships( $source );
		$per     = array();
		$people  = 0;
		foreach ( $members as $m ) {
			if ( $m['active'] ) {
				$per[ $m['level'] ] = isset( $per[ $m['level'] ] ) ? $per[ $m['level'] ] + 1 : 1;
				++$people;
			}
		}
		$rules = self::rules( $source );
		return array(
			'levels'    => count( self::levels( $source ) ),
			'members'   => $people,
			'per_level' => $per,
			'rules'     => count( $rules['rules'] ),
			'skipped'   => $rules['skipped'],
		);
	}
}
