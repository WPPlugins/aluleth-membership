<?php
/**
 * Membership levels (stored as a private post type, settings in one meta).
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Levels.
 */
final class Levels {

	/**
	 * Post type.
	 */
	const POST_TYPE = 'aluleth_level';

	/**
	 * Meta key of the settings.
	 */
	const META = '_aluleth_level';

	/**
	 * Levels of this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Registers the post type (managed on the plugin's own screens).
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Membership levels', 'aluleth-membership' ),
					'singular_name' => __( 'Membership level', 'aluleth-membership' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => array( 'aluleth_level', 'aluleth_levels' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Default settings of a level.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'description'  => '',
			'price'        => '',
			'popular'      => false,
			'on_card'      => true,
			'free_signup'  => false,
			'fluentcart'   => array(),
			'woocommerce'  => array(),
			'forms'        => array(),
			'duration'     => 'lifetime',
			'duration_n'   => 1,
			'button_label' => '',
			'button_url'   => '',
			'redirect'     => 0,
		);
	}

	/**
	 * All levels, in their order.
	 *
	 * @return array[]
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$posts       = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);
		self::$cache = array();
		foreach ( $posts as $post ) {
			self::$cache[ (int) $post->ID ] = self::from_post( $post );
		}
		return self::$cache;
	}

	/**
	 * One level.
	 *
	 * @param int $id Level id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Level array from its post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private static function from_post( $post ) {
		$meta           = get_post_meta( $post->ID, self::META, true );
		$level          = array_merge( self::defaults(), is_array( $meta ) ? $meta : array() );
		$level['id']    = (int) $post->ID;
		$level['name']  = $post->post_title;
		$level['slug']  = $post->post_name;
		$level['order'] = (int) $post->menu_order;
		return $level;
	}

	/**
	 * Forgets the levels read in this request.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Cleans the settings sent by the level editor.
	 *
	 * @param array $raw Raw values (already unslashed).
	 * @return array
	 */
	public static function clean( array $raw ) {
		$d   = self::defaults();
		$out = array(
			'description'  => isset( $raw['description'] ) ? substr( sanitize_textarea_field( $raw['description'] ), 0, 600 ) : '',
			'price'        => isset( $raw['price'] ) ? substr( sanitize_text_field( $raw['price'] ), 0, 60 ) : '',
			'popular'      => ! empty( $raw['popular'] ),
			'on_card'      => ! empty( $raw['on_card'] ),
			'free_signup'  => ! empty( $raw['free_signup'] ),
			'fluentcart'   => self::ids( isset( $raw['fluentcart'] ) ? $raw['fluentcart'] : array() ),
			'woocommerce'  => self::ids( isset( $raw['woocommerce'] ) ? $raw['woocommerce'] : array() ),
			'forms'        => self::ids( isset( $raw['forms'] ) ? $raw['forms'] : array() ),
			'duration'     => isset( $raw['duration'] ) && in_array( $raw['duration'], array( 'lifetime', 'days', 'months', 'years' ), true ) ? $raw['duration'] : $d['duration'],
			'duration_n'   => isset( $raw['duration_n'] ) ? max( 1, min( 3650, absint( $raw['duration_n'] ) ) ) : 1,
			'button_label' => isset( $raw['button_label'] ) ? substr( sanitize_text_field( $raw['button_label'] ), 0, 60 ) : '',
			'button_url'   => isset( $raw['button_url'] ) ? esc_url_raw( trim( (string) $raw['button_url'] ) ) : '',
			'redirect'     => isset( $raw['redirect'] ) ? absint( $raw['redirect'] ) : 0,
		);
		return $out;
	}

	/**
	 * List of positive ids.
	 *
	 * @param mixed $value Value.
	 * @return int[]
	 */
	private static function ids( $value ) {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
	}

	/**
	 * Creates or updates a level.
	 *
	 * @param int    $id       Level id (0 to create).
	 * @param string $name     Name.
	 * @param array  $settings Clean settings.
	 * @return int Level id, 0 on failure.
	 */
	public static function save( $id, $name, array $settings ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			$name = __( 'New level', 'aluleth-membership' );
		}
		$post = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		);
		if ( $id && self::get( $id ) ) {
			$post['ID'] = (int) $id;
			$id         = wp_update_post( $post, true );
		} else {
			$post['menu_order'] = count( self::all() );
			$id                 = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_post_meta( $id, self::META, array_merge( self::defaults(), $settings ) );
		self::flush();
		return (int) $id;
	}

	/**
	 * Deletes a level, its members' rows and its place in the rules.
	 *
	 * @param int $id Level id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( Members::table(), array( 'level_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Rules::forget_level( $id );
		wp_delete_post( $id, true );
		self::flush();
	}

	/**
	 * Moves a level one place up or down.
	 *
	 * @param int $id        Level id.
	 * @param int $direction -1 up, 1 down.
	 * @return void
	 */
	public static function move( $id, $direction ) {
		$ids = array_keys( self::all() );
		$pos = array_search( (int) $id, $ids, true );
		if ( false === $pos ) {
			return;
		}
		$to = $pos + ( $direction < 0 ? -1 : 1 );
		if ( $to < 0 || $to >= count( $ids ) ) {
			return;
		}
		$tmp         = $ids[ $to ];
		$ids[ $to ]  = $ids[ $pos ];
		$ids[ $pos ] = $tmp;
		foreach ( $ids as $order => $level_id ) {
			wp_update_post(
				array(
					'ID'         => $level_id,
					'menu_order' => $order,
				)
			);
		}
		self::flush();
	}

	/**
	 * End date of an access given now for this level, or null for no end
	 * (lifetime). Purchases with a subscription follow the subscription.
	 *
	 * @param array    $level Level.
	 * @param int|null $from  Start timestamp (now by default).
	 * @return string|null UTC datetime.
	 */
	public static function end_date( array $level, $from = null ) {
		if ( 'lifetime' === $level['duration'] ) {
			return null;
		}
		$from = null === $from ? time() : (int) $from;
		$n    = max( 1, (int) $level['duration_n'] );
		$unit = array(
			'days'   => 'day',
			'months' => 'month',
			'years'  => 'year',
		);
		$time = strtotime( '+' . $n . ' ' . $unit[ $level['duration'] ], $from );
		return gmdate( 'Y-m-d H:i:s', $time ? $time : $from );
	}

	/**
	 * Words for the duration ("1 year", "Lifetime").
	 *
	 * @param array $level Level.
	 * @return string
	 */
	public static function duration_text( array $level ) {
		$n = (int) $level['duration_n'];
		switch ( $level['duration'] ) {
			case 'days':
				/* translators: %d: number of days. */
				return sprintf( _n( '%d day', '%d days', $n, 'aluleth-membership' ), $n );
			case 'months':
				/* translators: %d: number of months. */
				return sprintf( _n( '%d month', '%d months', $n, 'aluleth-membership' ), $n );
			case 'years':
				/* translators: %d: number of years. */
				return sprintf( _n( '%d year', '%d years', $n, 'aluleth-membership' ), $n );
		}
		return __( 'No end date', 'aluleth-membership' );
	}

	/**
	 * Where the join button of a level goes: its own link, else the page of
	 * the first product that gives it, else the join form (free sign-up).
	 *
	 * @param array $level Level.
	 * @return string URL, or '' when the level is joined with the sign-up form.
	 */
	public static function join_url( array $level ) {
		if ( '' !== $level['button_url'] ) {
			return $level['button_url'];
		}
		foreach ( array_merge( $level['fluentcart'], $level['woocommerce'] ) as $product_id ) {
			if ( 'publish' === get_post_status( $product_id ) ) {
				return (string) get_permalink( $product_id );
			}
		}
		return '';
	}

	/**
	 * Levels in a list of ids, keeping only existing ones.
	 *
	 * @param int[] $ids Ids.
	 * @return array[]
	 */
	public static function pick( array $ids ) {
		$out = array();
		foreach ( self::all() as $id => $level ) {
			if ( in_array( $id, $ids, true ) ) {
				$out[ $id ] = $level;
			}
		}
		return $out;
	}
}
