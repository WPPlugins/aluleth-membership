<?php
/**
 * "Join card" Elementor widget.
 *
 * Only loaded from Elementor's widgets hook, so Elementor's classes exist.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Join card widget.
 */
class Elementor_Card extends Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'aluleth-join-card';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Join card', 'aluleth-membership' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-price-table';
	}

	/**
	 * Widget categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( Elementor::CATEGORY );
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'member', 'membership', 'pricing', 'plans', 'join', 'aluleth' );
	}

	/**
	 * Front-end styles.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'aluleth-membership' );
	}

	/**
	 * Front-end script.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'aluleth-membership' );
	}

	/**
	 * Controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content',
			array( 'label' => __( 'Join card', 'aluleth-membership' ) )
		);
		$this->add_control(
			'levels',
			array(
				'label'       => __( 'Levels', 'aluleth-membership' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => Elementor::level_options(),
				'description' => __( 'Empty: every level shown on join cards.', 'aluleth-membership' ),
			)
		);
		$this->add_control(
			'title',
			array(
				'label' => __( 'Title', 'aluleth-membership' ),
				'type'  => Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'text',
			array(
				'label' => __( 'Text', 'aluleth-membership' ),
				'type'  => Controls_Manager::TEXTAREA,
			)
		);
		$this->add_control(
			'design',
			array(
				'label'   => __( 'Design', 'aluleth-membership' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''      => __( 'As in the settings', 'aluleth-membership' ),
					'clean' => __( 'Clean', 'aluleth-membership' ),
					'soft'  => __( 'Soft', 'aluleth-membership' ),
					'dark'  => __( 'Dark', 'aluleth-membership' ),
				),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 *
	 * @return void
	 */
	protected function render() {
		$s    = $this->get_settings_for_display();
		$ids  = isset( $s['levels'] ) ? array_filter( array_map( 'intval', (array) $s['levels'] ) ) : array();
		$html = Join_Card::render(
			$ids ? $ids : array_keys( Levels::all() ),
			array(
				'title'  => isset( $s['title'] ) ? sanitize_text_field( $s['title'] ) : '',
				'text'   => isset( $s['text'] ) ? sanitize_text_field( $s['text'] ) : '',
				'design' => isset( $s['design'] ) ? sanitize_key( $s['design'] ) : '',
				'page'   => true,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped parts.
	}
}
