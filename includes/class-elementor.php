<?php
/**
 * Elementor: "Membership" visibility on every widget, container, section and
 * column, and the "Member area" and "Join card" widgets.
 *
 * The hooks only fire when Elementor is active, so nothing is loaded otherwise.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor integration.
 */
final class Elementor {

	/**
	 * Category shared by every Aluleth widget.
	 */
	const CATEGORY = 'aluleth';

	/**
	 * Hooks into Elementor.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'category' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'widgets' ) );
		add_action( 'elementor/element/after_section_end', array( __CLASS__, 'maybe_controls' ), 10, 2 );
		foreach ( array( 'widget', 'container', 'section', 'column' ) as $type ) {
			add_filter( 'elementor/frontend/' . $type . '/should_render', array( __CLASS__, 'should_render' ), 10, 2 );
		}
	}

	/**
	 * Adds the "Aluleth" category, unless another Aluleth plugin already did.
	 *
	 * @param \Elementor\Elements_Manager $manager Elements manager.
	 * @return void
	 */
	public static function category( $manager ) {
		$categories = method_exists( $manager, 'get_categories' ) ? $manager->get_categories() : array();
		if ( isset( $categories[ self::CATEGORY ] ) ) {
			return;
		}
		$manager->add_category(
			self::CATEGORY,
			array(
				'title' => 'Aluleth',
				'icon'  => 'eicon-lock-user',
			)
		);
	}

	/**
	 * Registers the widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets Widgets manager.
	 * @return void
	 */
	public static function widgets( $widgets ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		$widgets->register( new Elementor_Area() );
		$widgets->register( new Elementor_Card() );
	}

	/**
	 * Level choices.
	 *
	 * @return array<string, string>
	 */
	public static function level_options() {
		$out = array();
		foreach ( Levels::all() as $id => $level ) {
			$out[ (string) $id ] = $level['name'];
		}
		return $out;
	}

	/**
	 * Adds the section after "Responsive" in the Advanced tab of widgets (both
	 * Elementor widget bases: "common" and "common-optimized"),
	 * containers, sections and columns.
	 *
	 * @param \Elementor\Element_Base $element    Element.
	 * @param string                  $section_id Section that just ended.
	 * @return void
	 */
	public static function maybe_controls( $element, $section_id ) {
		if ( '_section_responsive' !== $section_id || ! is_object( $element ) || ! method_exists( $element, 'get_name' ) ) {
			return;
		}
		if ( ! in_array( $element->get_name(), array( 'common', 'common-optimized', 'container', 'section', 'column' ), true ) ) {
			return;
		}
		self::controls( $element );
	}

	/**
	 * "Membership" section in the Advanced tab.
	 *
	 * @param \Elementor\Element_Base $element Element.
	 * @return void
	 */
	public static function controls( $element ) {
		$element->start_controls_section(
			'alm_section',
			array(
				'label' => __( 'Membership', 'aluleth-membership' ),
				'tab'   => Controls_Manager::TAB_ADVANCED,
			)
		);
		$element->add_control(
			'alm_show',
			array(
				'label'   => __( 'Who sees this', 'aluleth-membership' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''        => __( 'Everyone', 'aluleth-membership' ),
					'members' => __( 'Members', 'aluleth-membership' ),
					'guests'  => __( 'Everyone but members', 'aluleth-membership' ),
				),
			)
		);
		$element->add_control(
			'alm_levels',
			array(
				'label'       => __( 'Levels', 'aluleth-membership' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => self::level_options(),
				'description' => __( 'Empty: any level.', 'aluleth-membership' ),
				'condition'   => array( 'alm_show!' => '' ),
			)
		);
		$element->end_controls_section();
	}

	/**
	 * Hides an element from the wrong visitors (always shown in the editor).
	 *
	 * @param bool                    $should  Render.
	 * @param \Elementor\Element_Base $element Element.
	 * @return bool
	 */
	public static function should_render( $should, $element ) {
		if ( ! $should || ! is_object( $element ) || ! method_exists( $element, 'get_settings' ) ) {
			return $should;
		}
		$show = (string) $element->get_settings( 'alm_show' );
		if ( '' === $show ) {
			return $should;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return $should;
		}
		return Restrict::visible( $show, array_map( 'intval', (array) $element->get_settings( 'alm_levels' ) ) );
	}
}
