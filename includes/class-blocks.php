<?php
/**
 * Block editor: "Member area" and "Join card" blocks, and "Who sees this
 * block" in the settings of every block.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks.
 */
final class Blocks {

	/**
	 * Hooks that must run before blocks are registered.
	 *
	 * @return void
	 */
	public static function early() {
		add_filter( 'register_block_type_args', array( __CLASS__, 'attribute' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
	}

	/**
	 * Registers the blocks.
	 *
	 * @return void
	 */
	public static function register() {
		register_block_type(
			'aluleth-membership/member-area',
			array(
				'api_version'     => 3,
				'title'           => __( 'Member area', 'aluleth-membership' ),
				'category'        => 'widgets',
				'icon'            => 'id-alt',
				'description'     => __( 'Memberships, billing, content opened for the member and profile. Visitors see the sign-in form.', 'aluleth-membership' ),
				'attributes'      => array(
					'content' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'count'   => array(
						'type'    => 'number',
						'default' => 6,
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
				'style_handles'   => array( 'aluleth-membership' ),
				'render_callback' => array( __CLASS__, 'render_area' ),
			)
		);
		register_block_type(
			'aluleth-membership/join-card',
			array(
				'api_version'     => 3,
				'title'           => __( 'Join card', 'aluleth-membership' ),
				'category'        => 'widgets',
				'icon'            => 'groups',
				'description'     => __( 'Your memberships with their price and join button, the free sign-up and the sign-in link form.', 'aluleth-membership' ),
				'attributes'      => array(
					'levels' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'number' ),
					),
					'title'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'text'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'design' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide' ),
				),
				'style_handles'   => array( 'aluleth-membership' ),
				'render_callback' => array( __CLASS__, 'render_card' ),
			)
		);
		add_shortcode( 'aluleth_join_card', array( __CLASS__, 'shortcode_card' ) );
	}

	/**
	 * Adds "alulethMembers" to every block.
	 *
	 * @param array  $args Block type arguments.
	 * @param string $name Block name.
	 * @return array
	 */
	public static function attribute( $args, $name ) {
		unset( $name );
		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}
		$args['attributes']['alulethMembers'] = array( 'type' => 'object' );
		return $args;
	}

	/**
	 * Script and data of the editor.
	 *
	 * @return void
	 */
	public static function editor_assets() {
		$levels = array();
		foreach ( Levels::all() as $id => $level ) {
			$levels[] = array(
				'id'   => $id,
				'name' => $level['name'],
			);
		}
		wp_enqueue_script(
			'aluleth-membership-blocks',
			plugins_url( 'assets/blocks.js', ALULETH_MEMBERSHIP_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-hooks', 'wp-compose', 'wp-server-side-render', 'wp-i18n' ),
			ALULETH_MEMBERSHIP_VERSION,
			true
		);
		wp_set_script_translations( 'aluleth-membership-blocks', 'aluleth-membership', ALULETH_MEMBERSHIP_DIR . '/languages' );
		wp_localize_script(
			'aluleth-membership-blocks',
			'alulethMembershipBlocks',
			array(
				'levels' => $levels,
				'admin'  => Admin::url( array( 'tab' => 'levels' ) ),
			)
		);
	}

	/**
	 * Member area block.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_area( $attrs ) {
		return '<div ' . get_block_wrapper_attributes() . '>' . Member_Area::render(
			array(
				'content' => ! isset( $attrs['content'] ) || $attrs['content'],
				'count'   => isset( $attrs['count'] ) ? (int) $attrs['count'] : 6,
			)
		) . '</div>';
	}

	/**
	 * Join card block.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_card( $attrs ) {
		$ids = isset( $attrs['levels'] ) ? array_filter( array_map( 'intval', (array) $attrs['levels'] ) ) : array();
		return '<div ' . get_block_wrapper_attributes() . '>' . Join_Card::render(
			$ids ? $ids : array_keys( Levels::all() ),
			array(
				'title'  => isset( $attrs['title'] ) ? sanitize_text_field( $attrs['title'] ) : '',
				'text'   => isset( $attrs['text'] ) ? sanitize_text_field( $attrs['text'] ) : '',
				'design' => isset( $attrs['design'] ) ? sanitize_key( $attrs['design'] ) : '',
				'page'   => true,
			)
		) . '</div>';
	}

	/**
	 * [aluleth_join_card levels="club,workshop-pass" title="" text="" design="dark"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_card( $atts ) {
		$atts = shortcode_atts(
			array(
				'levels' => '',
				'title'  => '',
				'text'   => '',
				'design' => '',
			),
			$atts,
			'aluleth_join_card'
		);
		$ids  = Restrict::levels_from_text( $atts['levels'] );
		return Join_Card::render(
			$ids ? $ids : array_keys( Levels::all() ),
			array(
				'title'  => sanitize_text_field( $atts['title'] ),
				'text'   => sanitize_text_field( $atts['text'] ),
				'design' => sanitize_key( $atts['design'] ),
				'page'   => true,
			)
		);
	}
}
