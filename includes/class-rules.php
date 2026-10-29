<?php
/**
 * Content access rules: what is for members, and which levels open it.
 *
 * Kinds of rules:
 * - post: one post, page or other content (set from its editor or the rules screen);
 * - term: everything in a category, tag or other taxonomy term ("category:12");
 * - type: every item of a content type ("post", "product"…);
 * - url:  addresses starting with a path ("/members/*" or an exact "/guides/").
 *
 * A rule on the post itself wins over the others (it can also open one post
 * of a members-only category to everyone). Otherwise every matching rule
 * applies and any of their levels opens the content.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Rules.
 */
final class Rules {

	/**
	 * What visitors without access see.
	 */
	const MODES = array( 'teaser', 'card', 'redirect', 'open' );

	/**
	 * Rules of this request.
	 *
	 * @var object[]|null
	 */
	private static $cache = null;

	/**
	 * Decisions per post for this request.
	 *
	 * @var array<int, array|null>
	 */
	private static $post_cache = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aluleth_member_rules';
	}

	/**
	 * Every rule.
	 *
	 * @return object[]
	 */
	public static function all() {
		global $wpdb;
		if ( null === self::$cache ) {
			$rows        = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::$cache = array();
			foreach ( $rows as $row ) {
				$row->level_ids = 'any' === $row->levels ? 'any' : array_values( array_filter( array_map( 'intval', explode( ',', $row->levels ) ) ) );
				self::$cache[]  = $row;
			}
		}
		return self::$cache;
	}

	/**
	 * Forgets the rules read in this request.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache      = null;
		self::$post_cache = array();
	}

	/**
	 * Adds a rule, or replaces the rule with the same target.
	 *
	 * @param string       $kind   post, term, type or url.
	 * @param string       $target Target.
	 * @param int[]|string $levels Level ids, or 'any' (any member).
	 * @param string       $mode   teaser, card, redirect or open.
	 * @param array        $extra  'drip_days' (days after joining before it opens), 'metered' (bool).
	 * @return int Rule id, 0 when not valid.
	 */
	public static function save( $kind, $target, $levels, $mode, array $extra = array() ) {
		global $wpdb;
		$target = self::clean_target( $kind, $target );
		if ( '' === $target ) {
			return 0;
		}
		$mode = in_array( $mode, self::MODES, true ) ? $mode : 'teaser';
		if ( 'open' === $mode && 'post' !== $kind ) {
			$mode = 'teaser';
		}
		if ( 'url' === $kind ) {
			$mode = 'redirect';
		}
		if ( 'any' !== $levels ) {
			$levels = array_values( array_intersect( array_map( 'intval', (array) $levels ), array_keys( Levels::all() ) ) );
			if ( ! $levels && 'open' !== $mode ) {
				$levels = 'any';
			}
		}
		$levels_text = 'any' === $levels ? 'any' : implode( ',', $levels );
		$drip        = isset( $extra['drip_days'] ) ? max( 0, min( 3650, absint( $extra['drip_days'] ) ) ) : 0;
		$metered     = ! empty( $extra['metered'] ) && in_array( $mode, array( 'teaser', 'card' ), true ) ? 1 : 0;
		if ( 'open' === $mode || 'url' === $kind ) {
			$drip = 0;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE kind = %s AND target = %s', self::table(), $kind, $target ) );
		if ( $existing ) {
			$wpdb->update(
				self::table(),
				array(
					'levels'    => $levels_text,
					'mode'      => $mode,
					'drip_days' => $drip,
					'metered'   => $metered,
				),
				array( 'id' => (int) $existing )
			);
			$id = (int) $existing;
		} else {
			$wpdb->insert(
				self::table(),
				array(
					'kind'       => $kind,
					'target'     => $target,
					'levels'     => $levels_text,
					'mode'       => $mode,
					'drip_days'  => $drip,
					'metered'    => $metered,
					'created_at' => Util::now(),
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		// phpcs:enable
		self::flush();

		/**
		 * Fires after a rule is saved.
		 *
		 * @param int   $id    Rule id.
		 * @param array $extra Extra settings; 'addon' holds the fields of add-ons
		 *                     (see "aluleth_membership/rule_fields").
		 */
		do_action( 'aluleth_membership/rule_saved', $id, $extra );
		return $id;
	}

	/**
	 * Clean target of a rule.
	 *
	 * @param string $kind   Kind.
	 * @param string $target Raw target.
	 * @return string '' when not valid.
	 */
	public static function clean_target( $kind, $target ) {
		$target = trim( (string) $target );
		switch ( $kind ) {
			case 'post':
				$id = absint( $target );
				return $id && get_post( $id ) ? (string) $id : '';
			case 'term':
				$parts = explode( ':', $target, 2 );
				if ( 2 !== count( $parts ) ) {
					return '';
				}
				$tax  = sanitize_key( $parts[0] );
				$term = get_term( absint( $parts[1] ), $tax );
				return $term && ! is_wp_error( $term ) ? $tax . ':' . (int) $term->term_id : '';
			case 'type':
				$type = sanitize_key( $target );
				return post_type_exists( $type ) && ! in_array( $type, array( 'attachment', Levels::POST_TYPE ), true ) ? $type : '';
			case 'url':
				$path = wp_parse_url( $target, PHP_URL_PATH );
				$path = is_string( $path ) ? $path : '';
				$star = '*' === substr( $target, -1 );
				$path = '/' . trim( preg_replace( '#[^A-Za-z0-9/_\-.%~]#', '', $path ), '/' );
				if ( '/' === $path && ! $star ) {
					return '';
				}
				return substr( rtrim( $path, '/' ) . ( $star ? '/*' : '/' ), 0, 191 );
		}
		return '';
	}

	/**
	 * Deletes a rule.
	 *
	 * @param int $id Rule id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();

		/**
		 * Fires after a rule is deleted.
		 *
		 * @param int $id Rule id.
		 */
		do_action( 'aluleth_membership/rule_deleted', (int) $id );
	}

	/**
	 * Takes a deleted level out of the rules; a rule left without levels
	 * is kept for "any member".
	 *
	 * @param int $level_id Level id.
	 * @return void
	 */
	public static function forget_level( $level_id ) {
		global $wpdb;
		foreach ( self::all() as $rule ) {
			if ( is_array( $rule->level_ids ) && in_array( (int) $level_id, $rule->level_ids, true ) ) {
				$left = array_diff( $rule->level_ids, array( (int) $level_id ) );
				$wpdb->update( self::table(), array( 'levels' => $left ? implode( ',', $left ) : 'any' ), array( 'id' => (int) $rule->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		self::flush();
	}

	/**
	 * Rule set on a post itself, if any.
	 *
	 * @param int $post_id Post id.
	 * @return object|null
	 */
	public static function post_rule( $post_id ) {
		foreach ( self::all() as $rule ) {
			if ( 'post' === $rule->kind && (int) $rule->target === (int) $post_id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Who can read a post.
	 *
	 * @param int $post_id Post id.
	 * @return array|null Null when the post is for everyone, else 'levels' (int[] or 'any'),
	 *                    'mode' and 'rules' (the matching rules).
	 */
	public static function for_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( array_key_exists( $post_id, self::$post_cache ) ) {
			return self::$post_cache[ $post_id ];
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		// The member area and the plans page stay open: people without access need them.
		$settings = Settings::get();
		if ( in_array( $post_id, array( (int) $settings['member_page'], (int) $settings['join_page'] ), true ) && $post_id ) {
			self::$post_cache[ $post_id ] = null;
			return null;
		}
		$own = self::post_rule( $post_id );
		if ( $own ) {
			self::$post_cache[ $post_id ] = self::filter_decision( 'open' === $own->mode ? null : self::merge( array( $own ) ), $post );
			return self::$post_cache[ $post_id ];
		}
		$matched = array();
		$terms   = null;
		foreach ( self::all() as $rule ) {
			if ( 'type' === $rule->kind && $rule->target === $post->post_type ) {
				$matched[] = $rule;
			} elseif ( 'term' === $rule->kind ) {
				list( $tax, $term_id ) = explode( ':', $rule->target, 2 );
				if ( is_object_in_taxonomy( $post->post_type, $tax ) && has_term( (int) $term_id, $tax, $post ) ) {
					$matched[] = $rule;
				}
			}
		}
		self::$post_cache[ $post_id ] = self::filter_decision( $matched ? self::merge( $matched ) : null, $post );
		return self::$post_cache[ $post_id ];
	}

	/**
	 * Lets add-ons decide who can read some content (Membership Pro: lessons).
	 *
	 * @param array|null $decision Decision.
	 * @param \WP_Post   $post     Post.
	 * @return array|null
	 */
	private static function filter_decision( $decision, $post ) {
		/**
		 * Filters who can read a post.
		 *
		 * @param array|null $decision Null for everyone, else 'levels' (int[] or 'any'), 'mode', 'drip', 'metered', 'rules'.
		 * @param \WP_Post   $post     Post.
		 */
		$filtered = apply_filters( 'aluleth_membership/post_decision', $decision, $post );
		return is_array( $filtered ) && isset( $filtered['levels'], $filtered['mode'] ) ? $filtered + array(
			'drip'    => 0,
			'metered' => false,
			'rules'   => array(),
		) : null;
	}

	/**
	 * Who can open an address (URL rules).
	 *
	 * @param string $path Request path.
	 * @return array|null
	 */
	public static function for_path( $path ) {
		$path    = '/' . trim( (string) $path, '/' ) . '/';
		$matched = array();
		foreach ( self::all() as $rule ) {
			if ( 'url' !== $rule->kind ) {
				continue;
			}
			if ( '/*' === substr( $rule->target, -2 ) ) {
				$prefix = substr( $rule->target, 0, -1 );
				if ( 0 === strpos( $path, $prefix ) || rtrim( $prefix, '/' ) . '/' === $path ) {
					$matched[] = $rule;
				}
			} elseif ( $rule->target === $path ) {
				$matched[] = $rule;
			}
		}
		return $matched ? self::merge( $matched ) : null;
	}

	/**
	 * Merges matching rules: any of their levels opens the content; the
	 * strictest display wins (redirect, then card only, then teaser).
	 *
	 * @param object[] $rules Rules.
	 * @return array
	 */
	private static function merge( array $rules ) {
		$levels  = array();
		$any     = false;
		$rank    = array(
			'teaser'   => 1,
			'card'     => 2,
			'redirect' => 3,
		);
		$mode    = 'teaser';
		$drip    = null;
		$metered = false;
		foreach ( $rules as $rule ) {
			// The most generous wait wins: any matching rule can open the content.
			$days = isset( $rule->drip_days ) ? (int) $rule->drip_days : 0;
			$drip = null === $drip ? $days : min( $drip, $days );
			if ( ! empty( $rule->metered ) ) {
				$metered = true;
			}
			if ( 'any' === $rule->level_ids ) {
				$any = true;
			} else {
				$levels = array_merge( $levels, $rule->level_ids );
			}
			if ( isset( $rank[ $rule->mode ] ) && $rank[ $rule->mode ] > $rank[ $mode ] ) {
				$mode = $rule->mode;
			}
		}
		return array(
			'levels'  => $any ? 'any' : array_values( array_unique( $levels ) ),
			'mode'    => $mode,
			'drip'    => (int) $drip,
			'metered' => $metered && 'redirect' !== $mode,
			'rules'   => $rules,
		);
	}

	/**
	 * Whether a user passes a decision.
	 *
	 * @param int        $user_id  User id (0 for visitors).
	 * @param array|null $decision Decision from for_post() or for_path().
	 * @return bool
	 */
	public static function allows( $user_id, $decision ) {
		if ( null === $decision ) {
			return true;
		}
		$opens = self::opens_at( $user_id, $decision );
		return null !== $opens && $opens <= time();
	}

	/**
	 * When a decision opens for a member: right away, or some days after
	 * they joined one of its levels (drip).
	 *
	 * @param int        $user_id  User id.
	 * @param array|null $decision Decision.
	 * @return int|null Timestamp, or null when the user has none of the levels.
	 */
	public static function opens_at( $user_id, $decision ) {
		if ( null === $decision ) {
			return 0;
		}
		$mine = Members::levels_of( $user_id );
		$hits = 'any' === $decision['levels'] ? $mine : array_values( array_intersect( $mine, $decision['levels'] ) );
		if ( ! $hits ) {
			return null;
		}
		$days  = isset( $decision['drip'] ) ? (int) $decision['drip'] : 0;
		$since = $days > 0 ? Members::since( $user_id, $hits ) : 0;
		$time  = $days > 0 && $since ? $since + $days * DAY_IN_SECONDS : 0;
		/**
		 * Filters when content opens for a member who has one of its levels.
		 *
		 * @param int   $time     Timestamp (0: open now).
		 * @param int   $user_id  User id.
		 * @param array $decision Decision.
		 * @param int[] $hits     The member's levels that open it.
		 */
		return max( 0, (int) apply_filters( 'aluleth_membership/opens_at', $time, $user_id, $decision, $hits ) );
	}

	/**
	 * Whether the current user can read a post (people who can edit it always can).
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function current_user_can_read( $post_id ) {
		$decision = self::for_post( $post_id );
		if ( null === $decision ) {
			return true;
		}
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		return self::allows( get_current_user_id(), $decision );
	}

	/**
	 * Levels that open a decision, for the join card (all levels shown on the
	 * card when any member can read).
	 *
	 * @param array|null $decision Decision.
	 * @return int[]
	 */
	public static function opening_levels( $decision ) {
		$all = Levels::all();
		if ( null === $decision || 'any' === $decision['levels'] ) {
			return array_keys( $all );
		}
		return array_values( array_intersect( array_keys( $all ), $decision['levels'] ) );
	}

	/**
	 * Latest content opened by some levels (for the member area).
	 *
	 * @param int[] $level_ids Levels of the member.
	 * @param int   $limit        Number of items.
	 * @param bool  $with_waiting Also content that opens later for the member (drip).
	 * @return \WP_Post[]
	 */
	public static function content_for( array $level_ids, $limit = 6, $with_waiting = false ) {
		if ( ! $level_ids ) {
			return array();
		}
		$ids   = array();
		$types = array();
		$taxes = array();
		foreach ( self::all() as $rule ) {
			$opens = 'any' === $rule->level_ids || array_intersect( $level_ids, $rule->level_ids );
			if ( ! $opens || 'open' === $rule->mode ) {
				continue;
			}
			if ( 'post' === $rule->kind ) {
				$ids[] = (int) $rule->target;
			} elseif ( 'type' === $rule->kind ) {
				$types[] = $rule->target;
			} elseif ( 'term' === $rule->kind ) {
				list( $tax, $term_id ) = explode( ':', $rule->target, 2 );
				$taxes[ $tax ][]       = (int) $term_id;
			}
		}
		$found = array();
		$base  = array(
			'post_status'         => 'publish',
			'posts_per_page'      => $limit * 2,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( $ids ) {
			$found = array_merge(
				$found,
				get_posts(
					array_merge(
						$base,
						array(
							'post__in'  => $ids,
							'post_type' => 'any',
						)
					)
				)
			);
		}
		if ( $types ) {
			$found = array_merge( $found, get_posts( array_merge( $base, array( 'post_type' => $types ) ) ) );
		}
		foreach ( $taxes as $tax => $term_ids ) {
			$found = array_merge(
				$found,
				get_posts(
					array_merge(
						$base,
						array(
							'post_type' => 'any',
							'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
								array(
									'taxonomy' => $tax,
									'terms'    => $term_ids,
								),
							),
						)
					)
				)
			);
		}
		$unique = array();
		foreach ( $found as $post ) {
			// Posts opened to everyone as an exception are not members' content.
			if ( Levels::POST_TYPE !== $post->post_type && null !== self::for_post( $post->ID ) && ( $with_waiting || 0 === (int) self::for_post( $post->ID )['drip'] || self::allows( get_current_user_id(), self::for_post( $post->ID ) ) ) ) {
				$unique[ $post->ID ] = $post;
			}
		}
		uasort(
			$unique,
			static function ( $a, $b ) {
				return strcmp( $b->post_date_gmt, $a->post_date_gmt );
			}
		);
		return array_slice( array_values( $unique ), 0, $limit );
	}

	/**
	 * Words describing who can read ("Club or Workshop pass", "Any member").
	 *
	 * @param int[]|string $levels Level ids or 'any'.
	 * @return string
	 */
	public static function levels_text( $levels ) {
		if ( 'any' === $levels ) {
			return __( 'Any member', 'aluleth-membership' );
		}
		$names = array();
		foreach ( Levels::pick( (array) $levels ) as $level ) {
			$names[] = $level['name'];
		}
		return $names ? implode( ', ', $names ) : __( 'Any member', 'aluleth-membership' );
	}

	/**
	 * Words describing a rule target ("Category: Repair guides").
	 *
	 * @param object $rule Rule.
	 * @return string
	 */
	public static function target_text( $rule ) {
		switch ( $rule->kind ) {
			case 'post':
				$post = get_post( (int) $rule->target );
				if ( ! $post ) {
					return __( 'Deleted content', 'aluleth-membership' );
				}
				$type = get_post_type_object( $post->post_type );
				return ( $type ? $type->labels->singular_name : $post->post_type ) . ': ' . ( '' !== $post->post_title ? $post->post_title : __( '(no title)', 'aluleth-membership' ) );
			case 'term':
				list( $tax, $term_id ) = explode( ':', $rule->target, 2 );
				$term                  = get_term( (int) $term_id, $tax );
				$taxonomy              = get_taxonomy( $tax );
				return ( $taxonomy ? $taxonomy->labels->singular_name : $tax ) . ': ' . ( $term && ! is_wp_error( $term ) ? $term->name : __( 'deleted', 'aluleth-membership' ) );
			case 'type':
				$type = get_post_type_object( $rule->target );
				/* translators: %s: content type name, plural. */
				return sprintf( __( 'All %s', 'aluleth-membership' ), $type ? $type->labels->name : $rule->target );
			case 'url':
				/* translators: %s: address. */
				return sprintf( __( 'Address %s', 'aluleth-membership' ), $rule->target );
		}
		return $rule->target;
	}
}
