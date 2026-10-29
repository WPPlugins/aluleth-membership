<?php
/**
 * Aluleth Membership main class.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Admin as Core_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks everything.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( Levels::class, 'register_post_type' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( Blocks::class, 'register' ), 20 );
		add_filter( 'aluleth/access/areas', array( $this, 'access_areas' ) );
		add_filter( 'aluleth/settings/uninstall', array( $this, 'uninstall_choice' ) );
		add_filter( 'aluleth/event/label', array( $this, 'event_label' ), 10, 2 );
		add_filter( 'aluleth/dashboard/kpis', array( $this, 'kpi' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporter' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ALULETH_MEMBERSHIP_FILE ), array( $this, 'action_links' ) );
		Blocks::early();
		Members::register();
		Sources::register();
		Restrict::register();
		Meter::register();
		Menus::register();
		Files::register();
		Bricks::register();
		Import::register();
		AI::register();
		Account::register();
		Elementor::register();
		if ( is_admin() ) {
			Admin::register();
			Editor::register();
		}
	}

	/**
	 * Updates the tables when the plugin was updated.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( get_option( 'aluleth_membership_db_version' ) !== Installer::DB_VERSION ) {
			Installer::install();
		}
		if ( ! wp_next_scheduled( 'aluleth_membership_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'aluleth_membership_hourly' );
		}
	}

	/**
	 * Registers the site styles and script (loaded only where a card or the
	 * member area is shown).
	 *
	 * @return void
	 */
	public function register_assets() {
		$url = plugins_url( 'assets/', ALULETH_MEMBERSHIP_FILE );
		wp_register_style( 'aluleth-membership', $url . 'membership.css', array(), ALULETH_MEMBERSHIP_VERSION );
		wp_register_script(
			'aluleth-membership',
			$url . 'membership.js',
			array(),
			ALULETH_MEMBERSHIP_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'aluleth-membership',
			'alulethMembershipFront',
			array(
				'error' => __( 'Something went wrong. Please try again.', 'aluleth-membership' ),
				'join'  => __( 'Join %s for free', 'aluleth-membership' ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- %s: level name.
				'nonce' => add_query_arg( 'action', 'aluleth_membership_nonce', admin_url( 'admin-ajax.php' ) ),
			)
		);
	}

	/**
	 * Loads the site styles and script.
	 *
	 * @return void
	 */
	public static function enqueue_front() {
		if ( ! wp_style_is( 'aluleth-membership', 'registered' ) ) {
			return;
		}
		wp_enqueue_style( 'aluleth-membership' );
		wp_enqueue_script( 'aluleth-membership' );
	}

	/**
	 * Access area (Aluleth › Settings › Access): administrators only by default.
	 *
	 * @param array $areas Areas.
	 * @return array
	 */
	public function access_areas( $areas ) {
		$areas = (array) $areas;
		$mine  = array(
			'membership' => array(
				'label'   => __( 'Manage memberships and members', 'aluleth-membership' ),
				'default' => array(),
			),
		);
		$out   = array();
		foreach ( $areas as $id => $area ) {
			if ( in_array( $id, array( 'contacts', 'settings' ), true ) && $mine ) {
				$out  = $out + $mine;
				$mine = array();
			}
			$out[ $id ] = $area;
		}
		return $out + $mine;
	}

	/**
	 * "Delete on uninstall" choice in Aluleth › Settings.
	 *
	 * @param array $choices Choices.
	 * @return array
	 */
	public function uninstall_choice( $choices ) {
		$choices['membership'] = array(
			'option' => 'aluleth_membership_delete_data',
			'label'  => __( 'Aluleth Membership: levels, members, content rules and emails', 'aluleth-membership' ),
			'help'   => __( 'Deleted with the plugin. WordPress accounts are kept.', 'aluleth-membership' ),
		);
		return $choices;
	}

	/**
	 * Translated labels of membership events in contact timelines.
	 *
	 * @param string $label Label.
	 * @param object $event Event.
	 * @return string
	 */
	public function event_label( $label, $event ) {
		$name = is_array( $event->data ) && ! empty( $event->data['level'] ) ? (string) $event->data['level'] : '';
		if ( 'membership.joined' === $event->type ) {
			/* translators: %s: level name. */
			return $name ? sprintf( __( 'Became a member: %s', 'aluleth-membership' ), $name ) : __( 'Became a member', 'aluleth-membership' );
		}
		if ( 'membership.ended' === $event->type ) {
			/* translators: %s: level name. */
			return $name ? sprintf( __( 'Membership ended: %s', 'aluleth-membership' ), $name ) : __( 'Membership ended', 'aluleth-membership' );
		}
		return $label;
	}

	/**
	 * "Members" on the Aluleth dashboard.
	 *
	 * @param array $kpis Key numbers.
	 * @return array
	 */
	public function kpi( $kpis ) {
		if ( ! current_user_can( Core_Admin::cap( 'membership' ) ) || ! Levels::all() ) {
			return $kpis;
		}
		$since  = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$kpis[] = array(
			'key'   => 'members',
			'label' => __( 'Members', 'aluleth-membership' ),
			'value' => number_format_i18n( Members::count_people() ),
			/* translators: %s: number of new memberships. */
			'note'  => sprintf( __( '%s joined in 30 days', 'aluleth-membership' ), number_format_i18n( Members::count_since( 'started_at', $since ) ) ),
			'order' => 35,
		);
		return $kpis;
	}

	/**
	 * Personal data export: the person's memberships.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function exporter( $exporters ) {
		$exporters['aluleth-membership'] = array(
			'exporter_friendly_name' => __( 'Aluleth Membership', 'aluleth-membership' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Exports the memberships of an email.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export( $email ) {
		$user = get_user_by( 'email', $email );
		$data = array();
		foreach ( $user ? Members::rows_of( $user->ID ) : array() as $row ) {
			$level  = Levels::get( $row->level_id );
			$data[] = array(
				'group_id'    => 'aluleth-membership',
				'group_label' => __( 'Memberships', 'aluleth-membership' ),
				'item_id'     => 'alm-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Level', 'aluleth-membership' ),
						'value' => $level ? $level['name'] : '#' . $row->level_id,
					),
					array(
						'name'  => __( 'Status', 'aluleth-membership' ),
						'value' => $row->status,
					),
					array(
						'name'  => __( 'From', 'aluleth-membership' ),
						'value' => $row->source . ( $row->source_ref ? ' ' . $row->source_ref : '' ),
					),
					array(
						'name'  => __( 'Started', 'aluleth-membership' ),
						'value' => $row->started_at,
					),
					array(
						'name'  => __( 'Ends', 'aluleth-membership' ),
						'value' => (string) ( $row->ended_at ? $row->ended_at : $row->expires_at ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Links under the plugin's name on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		if ( current_user_can( Core_Admin::cap( 'membership' ) ) ) {
			array_unshift( $links, '<a href="' . esc_url( Admin::url() ) . '">' . esc_html__( 'Membership', 'aluleth-membership' ) . '</a>' );
		}
		return $links;
	}
}
