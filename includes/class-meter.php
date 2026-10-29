<?php
/**
 * Free articles of the month (metered paywall): visitors and signed-in
 * people without access can read a few members-only articles each month
 * before they see the join card. Only content whose rule allows it counts.
 *
 * The articles read are kept in a signed cookie for visitors and in the
 * user's meta for signed-in people. Search engines and other robots never
 * use the free articles: they see what visitors without any left see.
 * Pages read this way are never stored by page caches.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Meter.
 */
final class Meter {

	/**
	 * Cookie and user meta name.
	 */
	const COOKIE = 'aluleth_meter';

	/**
	 * Posts opened by the meter in this request.
	 *
	 * @var array<int, bool>
	 */
	private static $open = array();

	/**
	 * State of this request.
	 *
	 * @var array|null
	 */
	private static $state = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'track' ), 6 );
	}

	/**
	 * Free articles a month.
	 *
	 * @return int
	 */
	public static function limit() {
		return max( 0, (int) Settings::get()['meter_count'] );
	}

	/**
	 * Whether the visitor is a search engine or another robot.
	 *
	 * @return bool
	 */
	public static function is_bot() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return '' === $agent || (bool) preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|curl|wget|python|headless/i', $agent );
	}

	/**
	 * On a members-only article that allows free reads: counts it for this
	 * visitor if they still have free articles this month.
	 *
	 * @return void
	 */
	public static function track() {
		if ( self::limit() <= 0 || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$decision = Rules::for_post( $post->ID );
		if ( null === $decision || empty( $decision['metered'] ) || Rules::current_user_can_read( $post->ID ) ) {
			return;
		}
		// Members waiting for this content (it opens some days after joining) don't use free articles.
		if ( null !== Rules::opens_at( get_current_user_id(), $decision ) ) {
			return;
		}
		// What this visitor sees depends on their count: page caches must not keep it.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constant read by page cache plugins.
		}
		nocache_headers();
		if ( self::is_bot() ) {
			return;
		}
		$state = self::read();
		$id    = (int) $post->ID;
		if ( in_array( $id, $state['ids'], true ) ) {
			self::$open[ $id ] = true;
			return;
		}
		if ( count( $state['ids'] ) >= self::limit() ) {
			return;
		}
		$state['ids'][] = $id;
		self::write( $state );
		self::$open[ $id ] = true;
	}

	/**
	 * Whether the meter opened a post in this request.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function opened( $post_id ) {
		return isset( self::$open[ (int) $post_id ] );
	}

	/**
	 * Free articles read this month.
	 *
	 * @return int
	 */
	public static function used() {
		return count( self::read()['ids'] );
	}

	/**
	 * First day of next month, when the free articles come back.
	 *
	 * @return int Timestamp.
	 */
	public static function resets_at() {
		$next = new \DateTimeImmutable( 'first day of next month 00:00', wp_timezone() );
		return $next->getTimestamp();
	}

	/**
	 * Current month in the site's time zone.
	 *
	 * @return string
	 */
	private static function month() {
		return wp_date( 'Y-m' );
	}

	/**
	 * Signature of a cookie value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function sign( $value ) {
		return substr( hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) ), 0, 24 );
	}

	/**
	 * Articles read this month.
	 *
	 * @return array{m: string, ids: int[]}
	 */
	private static function read() {
		if ( null !== self::$state ) {
			return self::$state;
		}
		$empty = array(
			'm'   => self::month(),
			'ids' => array(),
		);
		$raw   = '';
		if ( is_user_logged_in() ) {
			$raw = (string) get_user_meta( get_current_user_id(), '_' . self::COOKIE, true );
		} elseif ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		$parts = explode( '|', $raw );
		if ( 3 === count( $parts ) && hash_equals( self::sign( $parts[0] . '|' . $parts[1] ), $parts[2] ) && self::month() === $parts[0] ) {
			$ids          = array_values( array_filter( array_map( 'intval', explode( '-', $parts[1] ) ) ) );
			$empty['ids'] = array_slice( $ids, 0, 50 );
		}
		self::$state = $empty;
		return self::$state;
	}

	/**
	 * Saves the articles read this month.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function write( array $state ) {
		self::$state = $state;
		$value       = $state['m'] . '|' . implode( '-', array_map( 'intval', $state['ids'] ) );
		$value      .= '|' . self::sign( $value );
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), '_' . self::COOKIE, $value );
			return;
		}
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => self::resets_at() + DAY_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * Line shown above an article opened by the meter.
	 *
	 * @return string
	 */
	public static function banner() {
		$used = self::used();
		$join = Settings::join_url();
		$text = sprintf(
			/* translators: 1: free articles read this month, 2: free articles a month. */
			__( 'Free members-only article %1$d of %2$d this month.', 'aluleth-membership' ),
			$used,
			self::limit()
		);
		return '<p class="alm-meter" style="--alm-accent:' . esc_attr( Settings::get()['accent'] ) . '"><span>' . esc_html( $text ) . '</span>'
			. ( '' !== $join ? ' <a href="' . esc_url( $join ) . '">' . esc_html__( 'Join for unlimited access', 'aluleth-membership' ) . '</a>' : '' ) . '</p>';
	}
}
