<?php
/**
 * Keeps members-only content for members: posts (teaser and join card),
 * excerpts, feeds, the REST API, comments, addresses, blocks, shortcode.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Restriction on the site.
 */
final class Restrict {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'the_content', array( __CLASS__, 'content' ), 9999 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), 20, 2 );
		add_filter( 'the_excerpt_rss', array( __CLASS__, 'excerpt_feed' ), 20 );
		add_filter( 'comments_open', array( __CLASS__, 'comments_open' ), 20, 2 );
		add_filter( 'pings_open', array( __CLASS__, 'comments_open' ), 20, 2 );
		add_filter( 'comments_array', array( __CLASS__, 'comments' ), 20, 2 );
		add_filter( 'get_comments_number', array( __CLASS__, 'comments_number' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'redirects' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_filters' ) );
		add_filter( 'render_block', array( __CLASS__, 'block' ), 10, 2 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_shortcode( 'aluleth_members', array( __CLASS__, 'shortcode' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'early_assets' ) );
	}

	/**
	 * Loads the styles in the page head when a card or the member area will
	 * be shown (they would otherwise come at the end of the page).
	 *
	 * @return void
	 */
	public static function early_assets() {
		$post = is_singular() ? get_queried_object() : null;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$content = (string) $post->post_content;
		if ( ! Rules::current_user_can_read( $post->ID ) || has_block( 'aluleth-membership/member-area', $post ) || has_block( 'aluleth-membership/join-card', $post ) || false !== strpos( $content, '[aluleth_' ) || in_array( (int) $post->ID, array( (int) Settings::get()['member_page'], (int) Settings::get()['join_page'] ), true ) ) {
			Plugin::enqueue_front();
		}
	}

	/**
	 * Post content: the full text for people who can read it, else the
	 * teaser and the join card (or the card alone).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function content( $content ) {
		$post = get_post();
		if ( ! $post || post_password_required( $post ) || Rules::current_user_can_read( $post->ID ) ) {
			return $content;
		}
		$decision = Rules::for_post( $post->ID );
		if ( ! is_feed() && Meter::opened( $post->ID ) ) {
			Plugin::enqueue_front();
			return Meter::banner() . $content;
		}
		$teaser = 'teaser' === $decision['mode'] ? self::teaser( $content ) : '';
		if ( is_feed() ) {
			return $teaser . '<p><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html__( 'Members only: read the rest on the site.', 'aluleth-membership' ) . '</a></p>';
		}
		$fade  = '' !== $teaser ? '<div class="alm-teaser">' . $teaser . '</div>' : '';
		$opens = Rules::opens_at( get_current_user_id(), $decision );
		/**
		 * Lets add-ons show their own locked view (Membership Pro: lessons).
		 *
		 * @param string|null $html     Null to keep the join card or the waiting card.
		 * @param \WP_Post    $post     Post.
		 * @param array       $decision Decision.
		 * @param int|null    $opens    When it opens for this member (null: not a member of its levels).
		 * @param string      $content  Full content (never show it).
		 */
		$own = apply_filters( 'aluleth_membership/locked_content', null, $post, $decision, $opens, $content );
		if ( is_string( $own ) ) {
			return $own;
		}
		if ( $opens && $opens > time() ) {
			// A member who joined recently: this content opens for them later.
			return $fade . Join_Card::waiting( $opens );
		}
		$args = array( 'redirect' => get_permalink( $post ) );
		if ( ! empty( $decision['metered'] ) && Meter::limit() > 0 && ! Meter::is_bot() ) {
			$args['notice'] = sprintf(
				/* translators: 1: number of free articles, 2: date. */
				_n( 'You have read your %1$d free article this month. It comes back on %2$s.', 'You have read your %1$d free articles this month. They come back on %2$s.', Meter::limit(), 'aluleth-membership' ),
				Meter::limit(),
				wp_date( get_option( 'date_format' ), Meter::resets_at() )
			);
		}
		return $fade . Join_Card::render( Rules::opening_levels( $decision ), $args );
	}

	/**
	 * Beginning of a content: up to the "More" block when there is one, else
	 * whole paragraphs until the number of words in the settings.
	 *
	 * @param string $html Rendered content.
	 * @return string
	 */
	public static function teaser( $html ) {
		$html = (string) $html;
		if ( preg_match( '/<span id="more-\d+"><\/span>|<!--more(.*?)?-->/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return force_balance_tags( substr( $html, 0, $m[0][1] ) );
		}
		$limit = (int) Settings::get()['teaser_words'];
		if ( $limit <= 0 || '' === trim( $html ) ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( $html ), $limit ) ) . '</p>';
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="alm-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $doc->getElementById( 'alm-root' );
		if ( ! $root ) {
			return '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( $html ), $limit ) ) . '</p>';
		}
		$out   = '';
		$words = 0;
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties.
		foreach ( iterator_to_array( $root->childNodes ) as $node ) {
			$text = trim( (string) $node->textContent );
			if ( XML_TEXT_NODE === $node->nodeType && '' === $text ) {
				continue;
			}
			$count = '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
			if ( $words > 0 && $words + $count > $limit * 1.5 ) {
				break;
			}
			$out   .= $doc->saveHTML( $node );
			$words += $count;
			if ( $words >= $limit ) {
				break;
			}
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Excerpt of a members-only post: its own excerpt when written (it is
	 * the public summary), else the start of the teaser.
	 *
	 * @param string   $excerpt Excerpt.
	 * @param \WP_Post $post    Post.
	 * @return string
	 */
	public static function excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( ! $post || '' !== trim( $post->post_excerpt ) || Rules::current_user_can_read( $post->ID ) ) {
			return $excerpt;
		}
		$decision = Rules::for_post( $post->ID );
		if ( 'teaser' !== $decision['mode'] ) {
			return '';
		}
		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) ), min( 55, max( 10, (int) Settings::get()['teaser_words'] ) ) );
	}

	/**
	 * Feed excerpt.
	 *
	 * @param string $excerpt Excerpt.
	 * @return string
	 */
	public static function excerpt_feed( $excerpt ) {
		$post = get_post();
		return $post ? self::excerpt( $excerpt, $post ) : $excerpt;
	}

	/**
	 * Whether comments are hidden for this visitor on this post.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function hides_comments( $post_id ) {
		return $post_id && Settings::get()['hide_comments'] && ! Rules::current_user_can_read( $post_id );
	}

	/**
	 * Comments closed for visitors without access.
	 *
	 * @param bool $open    Open.
	 * @param int  $post_id Post id.
	 * @return bool
	 */
	public static function comments_open( $open, $post_id ) {
		return $open && ! self::hides_comments( (int) $post_id );
	}

	/**
	 * Comments hidden for visitors without access.
	 *
	 * @param array $comments Comments.
	 * @param int   $post_id  Post id.
	 * @return array
	 */
	public static function comments( $comments, $post_id ) {
		return self::hides_comments( (int) $post_id ) ? array() : $comments;
	}

	/**
	 * Comment count hidden for visitors without access.
	 *
	 * @param int $count   Count.
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function comments_number( $count, $post_id ) {
		return self::hides_comments( (int) $post_id ) ? 0 : $count;
	}

	/**
	 * Address rules and "send to the plans page" rules.
	 *
	 * @return void
	 */
	public static function redirects() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || current_user_can( \Aluleth\Core\Admin::cap( 'membership' ) ) ) {
			return;
		}
		$own  = array_filter( array( (int) Settings::get()['join_page'], (int) Settings::get()['member_page'] ) );
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post && in_array( (int) $post->ID, $own, true ) ) {
			return;
		}
		$decision = null;
		if ( $post instanceof \WP_Post && ! Rules::current_user_can_read( $post->ID ) ) {
			$for_post = Rules::for_post( $post->ID );
			if ( 'redirect' === $for_post['mode'] ) {
				$decision = $for_post;
			}
		}
		if ( null === $decision ) {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$path = '/' . ltrim( substr( $uri, strlen( rtrim( $home, '/' ) ) ), '/' );
			$rule = Rules::for_path( $path );
			if ( $rule && ! Rules::allows( get_current_user_id(), $rule ) ) {
				$decision = $rule;
			}
		}
		if ( null === $decision ) {
			return;
		}
		$here   = home_url( add_query_arg( array() ) );
		$target = Settings::join_url();
		if ( '' === $target ) {
			$target = is_user_logged_in() ? Settings::member_url() : wp_login_url( $here );
		} else {
			$target = add_query_arg( 'alm_from', rawurlencode( $here ), $target );
		}
		nocache_headers();
		wp_safe_redirect( $target, 302, 'Aluleth Membership' );
		exit;
	}

	/**
	 * Members-only posts in the REST API: the teaser only, for every public
	 * content type.
	 *
	 * @return void
	 */
	public static function rest_filters() {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
			add_filter( 'rest_prepare_' . $type, array( __CLASS__, 'rest_post' ), 20, 2 );
		}
	}

	/**
	 * REST answer of one post.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 * @return \WP_REST_Response
	 */
	public static function rest_post( $response, $post ) {
		if ( ! $post instanceof \WP_Post || Rules::current_user_can_read( $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$decision                    = Rules::for_post( $post->ID );
			$data['content']['rendered'] = 'teaser' === $decision['mode'] && isset( $data['content']['rendered'] ) ? self::teaser( $data['content']['rendered'] ) : '';
			unset( $data['content']['raw'] );
			$data['content']['members_only'] = true;
		}
		if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) ) {
			unset( $data['excerpt']['raw'] );
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Members-only blocks: "Who sees this block" in the block settings.
	 *
	 * @param string $html  Block HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public static function block( $html, $block ) {
		if ( empty( $block['attrs']['alulethMembers'] ) || ! is_array( $block['attrs']['alulethMembers'] ) ) {
			return $html;
		}
		$rule = $block['attrs']['alulethMembers'];
		$show = isset( $rule['show'] ) ? (string) $rule['show'] : 'all';
		$ids  = isset( $rule['levels'] ) ? array_map( 'intval', (array) $rule['levels'] ) : array();
		return self::visible( $show, $ids ) ? $html : '';
	}

	/**
	 * Whether the current visitor sees something set to "members" or "not
	 * members" (blocks, Elementor, shortcode).
	 *
	 * @param string $show   all, members or guests.
	 * @param int[]  $levels Levels (empty: any level).
	 * @return bool
	 */
	public static function visible( $show, array $levels ) {
		if ( 'members' !== $show && 'guests' !== $show ) {
			return true;
		}
		$mine     = Members::levels_of( get_current_user_id() );
		$levels   = array_filter( $levels );
		$is_level = $levels ? (bool) array_intersect( $mine, $levels ) : (bool) $mine;
		return 'members' === $show ? $is_level : ! $is_level;
	}

	/**
	 * [aluleth_members levels="club,2" show="members|guests" card="yes"]…[/aluleth_members]
	 *
	 * @param array|string $atts    Attributes.
	 * @param string|null  $content Content.
	 * @return string
	 */
	public static function shortcode( $atts, $content = null ) {
		$atts   = shortcode_atts(
			array(
				'levels' => '',
				'show'   => 'members',
				'card'   => 'no',
			),
			$atts,
			'aluleth_members'
		);
		$levels = self::levels_from_text( $atts['levels'] );
		$show   = 'guests' === $atts['show'] ? 'guests' : 'members';
		if ( self::visible( $show, $levels ) || ( current_user_can( 'edit_posts' ) && 'members' === $show && is_preview() ) ) {
			return do_shortcode( (string) $content );
		}
		if ( 'members' === $show && in_array( strtolower( (string) $atts['card'] ), array( 'yes', '1', 'true' ), true ) ) {
			return Join_Card::render( $levels ? $levels : array_keys( Levels::all() ) );
		}
		return '';
	}

	/**
	 * Level ids from "club, 12, workshop-pass" (ids or slugs).
	 *
	 * @param string $text Text.
	 * @return int[]
	 */
	public static function levels_from_text( $text ) {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $text ) ) ) as $bit ) {
			foreach ( Levels::all() as $id => $level ) {
				if ( (string) $id === $bit || sanitize_title( $bit ) === $level['slug'] ) {
					$out[] = $id;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Hides the toolbar on the site for members who can't edit anything.
	 *
	 * @param bool $show Show.
	 * @return bool
	 */
	public static function admin_bar( $show ) {
		if ( $show && Settings::get()['hide_admin_bar'] && is_user_logged_in() && ! current_user_can( 'edit_posts' ) && Members::levels_of( get_current_user_id() ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Search engines may index the teaser, never more; the sign-in page is not indexed.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		$page = (int) Settings::get()['member_page'];
		if ( $page && is_page( $page ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}
}
