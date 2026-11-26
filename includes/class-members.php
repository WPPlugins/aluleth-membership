<?php
/**
 * Members: who has which level, from where, and until when.
 *
 * Each row is one access given to a person for a level by one source (a
 * FluentCart order or subscription, a WooCommerce order, a form entry, the
 * free sign-up, the site owner). A person has a level while one of their rows
 * for it is active and not past its end date, so a refund of one order never
 * takes away an access paid by another.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Members.
 */
final class Members {

	/**
	 * Active levels per user for this request.
	 *
	 * @var array<int, int[]>
	 */
	private static $levels_cache = array();

	/**
	 * Rows per user for this request.
	 *
	 * @var array<int, object[]>
	 */
	private static $rows_cache = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aluleth_members';
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'aluleth_membership_hourly', array( __CLASS__, 'hourly' ) );
		add_action( 'deleted_user', array( __CLASS__, 'user_deleted' ) );
	}

	/**
	 * Finds the WordPress account of an email, or creates one.
	 *
	 * New accounts get a random password: members sign in with a link sent by
	 * email (or set a password with "Lost your password?").
	 *
	 * @param string $email Email.
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return array{0:int,1:bool} User id (0 on failure) and whether it was created.
	 */
	public static function ensure_user( $email, $first = '', $last = '' ) {
		$email = Util::email( $email );
		if ( '' === $email ) {
			return array( 0, false );
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return array( (int) $user->ID, false );
		}
		$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base  = '' !== $base ? substr( $base, 0, 50 ) : 'member';
		$login = $base;
		$n     = 1;
		while ( username_exists( $login ) ) {
			++$n;
			$login = $base . $n;
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => sanitize_text_field( $first ),
				'last_name'    => sanitize_text_field( $last ),
				'display_name' => trim( sanitize_text_field( $first . ' ' . $last ) ) ? trim( sanitize_text_field( $first . ' ' . $last ) ) : $login,
				// Always a low-privilege role (nothing beyond reading), whatever the settings say.
				'role'         => Settings::member_role(),
			)
		);
		if ( is_wp_error( $id ) ) {
			return array( 0, false );
		}
		update_user_meta( $id, '_aluleth_member_created', 1 );
		return array( (int) $id, true );
	}

	/**
	 * Gives a level to a person (or renews, extends or resumes their access
	 * from the same source).
	 *
	 * @param int    $user_id  User id.
	 * @param int    $level_id Level id.
	 * @param string $source   fluentcart, woocommerce, aluleth-commerce, form, signup, manual, import, pmpro, rcp or swpm.
	 * @param string $ref      Reference in the source (e.g. "order:12", "sub:4").
	 * @param array  $args     'expires_at' (UTC datetime, null for no end, or 'level' to use the
	 *                         level's duration — the default), 'status' ('active' or 'pending'),
	 *                         'notify' (bool, send the welcome email; default true),
	 *                         'started_at' (UTC datetime in the past, for a new row: imported memberships).
	 * @return int Row id, 0 on failure.
	 */
	public static function grant( $user_id, $level_id, $source, $ref = '', array $args = array() ) {
		global $wpdb;
		$user_id  = (int) $user_id;
		$level    = Levels::get( $level_id );
		$level_id = (int) $level_id;
		if ( ! $user_id || ! $level || ! get_userdata( $user_id ) ) {
			return 0;
		}
		$source  = substr( sanitize_key( $source ), 0, 30 );
		$ref     = substr( sanitize_text_field( (string) $ref ), 0, 64 );
		$status  = isset( $args['status'] ) && 'pending' === $args['status'] ? 'pending' : 'active';
		$expires = array_key_exists( 'expires_at', $args ) ? $args['expires_at'] : 'level';
		if ( 'level' === $expires ) {
			$expires = Levels::end_date( $level );
		}
		$had = self::has_level( $user_id, $level_id );
		$now = Util::now();

		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND level_id = %d AND source = %s AND source_ref = %s', $table, $user_id, $level_id, $source, $ref ) );
		$data = array(
			'status'     => $status,
			'expires_at' => $expires,
			'ended_at'   => null,
			'end_reason' => '',
			'reminded'   => 0,
			'updated_at' => $now,
		);
		if ( $row ) {
			if ( 'active' === $row->status && ! $had ) {
				$data['started_at'] = $now;
			}
			$wpdb->update( $table, $data, array( 'id' => (int) $row->id ) );
			$id = (int) $row->id;
		} else {
			$wpdb->insert(
				$table,
				array_merge(
					$data,
					array(
						'user_id'    => $user_id,
						'level_id'   => $level_id,
						'source'     => $source,
						'source_ref' => $ref,
						'started_at' => ! empty( $args['started_at'] ) && strtotime( $args['started_at'] . ' UTC' ) < time() ? gmdate( 'Y-m-d H:i:s', strtotime( $args['started_at'] . ' UTC' ) ) : $now,
						'created_at' => $now,
					)
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		// phpcs:enable
		self::forget( $user_id );

		if ( 'active' === $status && ! $had && self::has_level( $user_id, $level_id ) ) {
			self::joined( $user_id, $level, $source, ! isset( $args['notify'] ) || $args['notify'] );
		}
		return $id;
	}

	/**
	 * Turns a pending access (free sign-up waiting for the email check) into
	 * an active one.
	 *
	 * @param int $row_id Row id.
	 * @return bool
	 */
	public static function activate( $row_id ) {
		$row = self::row( $row_id );
		if ( ! $row || 'pending' !== $row->status ) {
			return false;
		}
		$level = Levels::get( $row->level_id );
		if ( ! $level ) {
			return false;
		}
		/**
		 * Filters the end date of a pending access when it opens (Membership Pro:
		 * the length of a free trial).
		 *
		 * @param string|null $expires UTC datetime, null for no end.
		 * @param object      $row     Pending row.
		 * @param array       $level   Level.
		 */
		$expires = apply_filters( 'aluleth_membership/activate_expires', Levels::end_date( $level ), $row, $level );
		self::grant(
			(int) $row->user_id,
			(int) $row->level_id,
			$row->source,
			$row->source_ref,
			array(
				'expires_at' => $expires,
				// A source whose emails an add-on sends gets no welcome email from here.
				'notify'     => ! Import::quiet( $row->source ),
			)
		);
		return true;
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Rows given by a source reference (an order, a subscription).
	 *
	 * @param string $source Source.
	 * @param string $ref    Reference.
	 * @return object[]
	 */
	public static function rows_by_ref( $source, $ref ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s AND source_ref = %s', self::table(), $source, $ref ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Ends one access.
	 *
	 * @param object|int $row    Row or id.
	 * @param string     $reason Expired, refunded, cancelled, removed or payment_failed.
	 * @param bool|null  $notify Send the "has ended" email (null: unless another plugin followed by the import tells its members).
	 * @return void
	 */
	public static function end( $row, $reason, $notify = null ) {
		global $wpdb;
		$row = is_object( $row ) ? $row : self::row( $row );
		if ( ! $row || 'ended' === $row->status ) {
			return;
		}
		$had = self::has_level( (int) $row->user_id, (int) $row->level_id );
		$now = Util::now();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'     => 'ended',
				'ended_at'   => $now,
				'end_reason' => substr( sanitize_key( $reason ), 0, 30 ),
				'updated_at' => $now,
			),
			array( 'id' => (int) $row->id )
		);
		self::forget( (int) $row->user_id );
		if ( $had && ! self::has_level( (int) $row->user_id, (int) $row->level_id ) ) {
			self::left( (int) $row->user_id, (int) $row->level_id, $reason, null === $notify ? ! Import::quiet( $row->source ) : (bool) $notify );
		}
	}

	/**
	 * Ends every access given by a source reference.
	 *
	 * @param string $source Source.
	 * @param string $ref    Reference.
	 * @param string $reason Reason.
	 * @return int Number of rows ended.
	 */
	public static function end_by_ref( $source, $ref, $reason ) {
		$n = 0;
		foreach ( self::rows_by_ref( $source, $ref ) as $row ) {
			if ( 'ended' !== $row->status ) {
				self::end( $row, $reason );
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Changes the end date or the paused state of the accesses of a reference
	 * (a subscription that was cancelled at the end of its period, paused,
	 * resumed).
	 *
	 * @param string $source Source.
	 * @param string $ref    Reference.
	 * @param array  $data   'status' (active or paused) and/or 'expires_at'.
	 * @return void
	 */
	public static function update_by_ref( $source, $ref, array $data ) {
		global $wpdb;
		foreach ( self::rows_by_ref( $source, $ref ) as $row ) {
			if ( 'ended' === $row->status ) {
				continue;
			}
			$had    = self::has_level( (int) $row->user_id, (int) $row->level_id );
			$update = array( 'updated_at' => Util::now() );
			if ( isset( $data['status'] ) && in_array( $data['status'], array( 'active', 'paused' ), true ) ) {
				$update['status'] = $data['status'];
			}
			if ( array_key_exists( 'expires_at', $data ) ) {
				$update['expires_at'] = $data['expires_at'];
				$update['reminded']   = 0;
			}
			$wpdb->update( self::table(), $update, array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::forget( (int) $row->user_id );
			$has = self::has_level( (int) $row->user_id, (int) $row->level_id );
			if ( $had && ! $has ) {
				self::left( (int) $row->user_id, (int) $row->level_id, 'paused' );
			} elseif ( ! $had && $has ) {
				$level = Levels::get( $row->level_id );
				if ( $level ) {
					self::joined( (int) $row->user_id, $level, $row->source, false );
				}
			}
		}
	}

	/**
	 * Level ids a user has now.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public static function levels_of( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		if ( ! isset( self::$levels_cache[ $user_id ] ) ) {
			$ids                            = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT level_id FROM %i WHERE user_id = %d AND status = %s AND ( expires_at IS NULL OR expires_at > %s )', self::table(), $user_id, 'active', Util::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$all                            = Levels::all();
			self::$levels_cache[ $user_id ] = array_values(
				array_filter(
					array_map( 'intval', (array) $ids ),
					static function ( $id ) use ( $all ) {
						return isset( $all[ $id ] );
					}
				)
			);
		}
		return self::$levels_cache[ $user_id ];
	}

	/**
	 * Since when a user has one of some levels without a break (the start of
	 * their oldest access that is active now), for content that opens some
	 * days after joining.
	 *
	 * @param int   $user_id   User id.
	 * @param int[] $level_ids Levels.
	 * @return int Timestamp, 0 when the user has none of them.
	 */
	public static function since( $user_id, array $level_ids ) {
		$first = 0;
		foreach ( self::rows_of( $user_id ) as $row ) {
			if ( 'active' !== $row->status || ! in_array( (int) $row->level_id, array_map( 'intval', $level_ids ), true ) ) {
				continue;
			}
			if ( null !== $row->expires_at && strtotime( $row->expires_at . ' UTC' ) <= time() ) {
				continue;
			}
			$start = (int) strtotime( $row->started_at . ' UTC' );
			$first = $first ? min( $first, $start ) : $start;
		}
		return $first;
	}

	/**
	 * Whether a user has a level now.
	 *
	 * @param int $user_id  User id.
	 * @param int $level_id Level id.
	 * @return bool
	 */
	public static function has_level( $user_id, $level_id ) {
		return in_array( (int) $level_id, self::levels_of( $user_id ), true );
	}

	/**
	 * Forgets the cached levels of a user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function forget( $user_id ) {
		unset( self::$levels_cache[ (int) $user_id ], self::$rows_cache[ (int) $user_id ] );
	}

	/**
	 * Every row of a user, newest first.
	 *
	 * @param int $user_id User id.
	 * @return object[]
	 */
	public static function rows_of( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! isset( self::$rows_cache[ $user_id ] ) ) {
			self::$rows_cache[ $user_id ] = $user_id ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY started_at DESC, id DESC', self::table(), $user_id ) ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$rows_cache[ $user_id ];
	}

	/**
	 * Members list for the admin: one line per person and level.
	 *
	 * @param array $args 'level' (id), 'status' (active, ended, paused, pending, all), 'search', 'page', 'per_page'.
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$per    = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 30;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$level  = isset( $args['level'] ) ? (int) $args['level'] : 0;
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'active';
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$now    = Util::now();

		// Every condition is in the query, switched off with a sentinel when not used.
		$like       = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';
		$active_now = 'active' === $status ? 1 : 0;
		$want       = in_array( $status, array( 'ended', 'paused', 'pending' ), true ) ? $status : '';
		$params     = array( self::table(), $wpdb->users, $level, $level, $active_now, $now, $want, $want, $like, $like, $like );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i m INNER JOIN %i u ON u.ID = m.user_id WHERE ( %d = 0 OR m.level_id = %d ) AND ( %d = 0 OR ( m.status = 'active' AND ( m.expires_at IS NULL OR m.expires_at > %s ) ) ) AND ( %s = '' OR m.status = %s ) AND ( %s = '' OR u.user_email LIKE %s OR u.display_name LIKE %s )", $params ) );
		$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.*, u.user_email, u.display_name FROM %i m INNER JOIN %i u ON u.ID = m.user_id WHERE ( %d = 0 OR m.level_id = %d ) AND ( %d = 0 OR ( m.status = 'active' AND ( m.expires_at IS NULL OR m.expires_at > %s ) ) ) AND ( %s = '' OR m.status = %s ) AND ( %s = '' OR u.user_email LIKE %s OR u.display_name LIKE %s ) ORDER BY m.started_at DESC, m.id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ) );
		// phpcs:enable
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Number of people with each level now.
	 *
	 * @return array<int, int>
	 */
	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT level_id, COUNT(DISTINCT user_id) AS n FROM %i WHERE status = %s AND ( expires_at IS NULL OR expires_at > %s ) GROUP BY level_id', self::table(), 'active', Util::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->level_id ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Number of people with at least one level now.
	 *
	 * @return int
	 */
	public static function count_people() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM %i WHERE status = %s AND ( expires_at IS NULL OR expires_at > %s )', self::table(), 'active', Util::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Accesses started or ended since a date (for the overview).
	 *
	 * @param string $column started_at or ended_at.
	 * @param string $since  UTC datetime.
	 * @return int
	 */
	public static function count_since( $column, $since ) {
		global $wpdb;
		$column = 'ended_at' === $column ? 'ended_at' : 'started_at';
		$status = 'ended_at' === $column ? 'ended' : 'active';
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i >= %s AND status = %s', self::table(), $column, $since, $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Latest joins and ends, for the overview.
	 *
	 * @param int $limit Number of lines.
	 * @return object[]
	 */
	public static function recent( $limit = 8 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT m.*, u.user_email, u.display_name, COALESCE( m.ended_at, m.started_at ) AS happened FROM %i m INNER JOIN %i u ON u.ID = m.user_id WHERE m.status IN ( %s, %s ) ORDER BY happened DESC LIMIT %d', self::table(), $wpdb->users, 'active', 'ended', (int) $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Hourly: ends accesses past their end date, sends the "ends soon"
	 * reminders and forgets free sign-ups never confirmed.
	 *
	 * @return void
	 */
	public static function hourly() {
		global $wpdb;
		$now   = Util::now();
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$due  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s LIMIT 200', $table, 'active', $now ) );
		$told = array();
		foreach ( $due as $row ) {
			// The access already stopped at its end date: this records it and tells the member,
			// once per person and level, unless another access still gives them the level.
			$wpdb->update(
				$table,
				array(
					'status'     => 'ended',
					'ended_at'   => $row->expires_at,
					'end_reason' => 'expired',
					'updated_at' => $now,
				),
				array( 'id' => (int) $row->id )
			);
			self::forget( (int) $row->user_id );
			$key = $row->user_id . ':' . $row->level_id;
			if ( ! isset( $told[ $key ] ) && ! self::has_level( (int) $row->user_id, (int) $row->level_id ) ) {
				$told[ $key ] = true;
				self::left( (int) $row->user_id, (int) $row->level_id, 'expired', ! Import::quiet( $row->source ) );
			}
		}

		$soon = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s AND reminded = 0 AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s AND source_ref NOT LIKE %s AND source_ref NOT LIKE %s LIMIT 200', $table, 'active', $now, $soon, 'sub:%', 'wcs:%' ) );
		foreach ( $rows as $row ) {
			$wpdb->update( $table, array( 'reminded' => 1 ), array( 'id' => (int) $row->id ) );
			if ( ! Import::quiet( $row->source ) ) {
				Emails::ending_soon( $row );
			}
		}

		$old = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status = %s AND created_at < %s', $table, 'pending', $old ) );
		// phpcs:enable
	}

	/**
	 * Removes the rows of a deleted user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function user_deleted( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::forget( (int) $user_id );
	}

	/**
	 * Someone got a level they did not have: contact, tag, history, email.
	 *
	 * @param int    $user_id User id.
	 * @param array  $level   Level.
	 * @param string $source  Source.
	 * @param bool   $notify  Send the welcome email.
	 * @return void
	 */
	private static function joined( $user_id, array $level, $source, $notify ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$core    = aluleth_core();
		$contact = $core->contacts()->upsert(
			$user->user_email,
			array(
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
			),
			array(
				'source'    => 'aluleth-membership',
				'fill_only' => true,
			)
		);
		if ( $contact ) {
			/* translators: %s: level name. */
			$core->tags()->add( $contact, array( sprintf( __( 'Member: %s', 'aluleth-membership' ), $level['name'] ) ) );
			$core->events()->record(
				'membership.joined',
				array(
					'contact' => $contact,
					'object'  => array( 'level', $level['id'] ),
					'data'    => array(
						'level'  => $level['name'],
						'source' => $source,
					),
					'source'  => 'aluleth-membership',
				)
			);
		}

		/**
		 * Fires when a person gets a level they did not have.
		 *
		 * @param int    $user_id User id.
		 * @param array  $level   Level.
		 * @param string $source  Where the access comes from.
		 */
		do_action( 'aluleth_membership/joined', $user_id, $level, $source );

		if ( $notify ) {
			Emails::welcome( $user, $level );
		}
	}

	/**
	 * Someone no longer has a level: tag removed, history, email.
	 *
	 * @param int    $user_id  User id.
	 * @param int    $level_id Level id.
	 * @param string $reason   Reason.
	 * @param bool   $notify   Send the "has ended" email.
	 * @return void
	 */
	private static function left( $user_id, $level_id, $reason, $notify = true ) {
		global $wpdb;
		$user  = get_userdata( $user_id );
		$level = Levels::get( $level_id );
		if ( ! $user || ! $level ) {
			return;
		}
		$core    = aluleth_core();
		$contact = $core->contacts()->get_by_email( $user->user_email );
		if ( $contact ) {
			/* translators: %s: level name. */
			$tag_id = $core->tags()->ensure( sprintf( __( 'Member: %s', 'aluleth-membership' ), $level['name'] ) );
			if ( $tag_id ) {
				$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'aluleth_contact_tags',
					array(
						'contact_id' => (int) $contact->id,
						'tag_id'     => (int) $tag_id,
					)
				);
			}
			$core->events()->record(
				'membership.ended',
				array(
					'contact' => (int) $contact->id,
					'object'  => array( 'level', $level_id ),
					'data'    => array(
						'level'  => $level['name'],
						'reason' => $reason,
					),
					'source'  => 'aluleth-membership',
				)
			);
		}

		/**
		 * Fires when a person no longer has a level.
		 *
		 * @param int    $user_id User id.
		 * @param array  $level   Level.
		 * @param string $reason  expired, refunded, cancelled, removed, paused, payment_failed.
		 */
		do_action( 'aluleth_membership/left', $user_id, $level, $reason );

		if ( 'paused' !== $reason && $notify ) {
			Emails::ended( $user, $level, $reason );
		}
	}
}
