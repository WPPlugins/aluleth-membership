<?php
/**
 * "Member area" Elementor widget.
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
 * Member area widget.
 */
class Elementor_Area extends Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'aluleth-member-area';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Member area', 'aluleth-membership' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-person';
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
		return array( 'member', 'membership', 'account', 'login', 'aluleth' );
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
			array( 'label' => __( 'Member area', 'aluleth-membership' ) )
		);
		$this->add_control(
			'show_content',
			array(
				'label'        => __( 'Show "Opened for you"', 'aluleth-membership' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'count',
			array(
				'label'     => __( 'Number of items', 'aluleth-membership' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 6,
				'min'       => 1,
				'max'       => 24,
				'condition' => array( 'show_content' => 'yes' ),
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
		$html = Member_Area::render(
			array(
				'content' => isset( $s['show_content'] ) && 'yes' === $s['show_content'],
				'count'   => isset( $s['count'] ) ? (int) $s['count'] : 6,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped parts.
	}
}
