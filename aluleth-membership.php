<?php
/**
 * Plugin Name:       Aluleth Membership
 * Description:       Membership levels for your site: access given by a FluentCart or WooCommerce purchase, a free sign-up, a form or by hand. Members-only content with a teaser and a designed join card, a member area and sign-in links by email. No fee on your sales.
 * Version:           0.4.3
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Aluleth
 * Author URI:        https://profiles.wordpress.org/aluleth/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aluleth-membership
 * Domain Path:       /languages
 *
 * @package Aluleth\Membership
 */

defined( 'ABSPATH' ) || exit;

define( 'ALULETH_MEMBERSHIP_VERSION', '0.4.3' );
define( 'ALULETH_MEMBERSHIP_FILE', __FILE__ );
define( 'ALULETH_MEMBERSHIP_DIR', __DIR__ );
define( 'ALULETH_MEMBERSHIP_CORE_MIN', '0.18.0' );

require_once __DIR__ . '/core/loader.php';

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Aluleth\\Membership\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = ALULETH_MEMBERSHIP_DIR . '/includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Starts the plugin once the core is loaded.
 *
 * @return void
 */
function aluleth_membership_boot() {
	if ( ! function_exists( 'aluleth_core' ) || ! aluleth_core()->at_least( ALULETH_MEMBERSHIP_CORE_MIN ) ) {
		add_action( 'admin_notices', 'aluleth_membership_core_notice' );
		return;
	}
	\Aluleth\Membership\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'aluleth_membership_boot', 10 );

/**
 * Notice shown when another Aluleth plugin ships an older core (Plugins screen only).
 *
 * @return void
 */
function aluleth_membership_core_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! current_user_can( 'activate_plugins' ) || ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Aluleth Membership needs a newer version of your other Aluleth plugins. Please update them.', 'aluleth-membership' ) . '</p></div>';
}

/**
 * Activation: tables and the hourly check of end dates.
 *
 * @return void
 */
function aluleth_membership_activate() {
	aluleth_core_elect();
	if ( ! function_exists( 'aluleth_core' ) ) {
		return;
	}
	\Aluleth\Core\Installer::install();
	\Aluleth\Membership\Installer::install();
}
register_activation_hook( __FILE__, 'aluleth_membership_activate' );

/**
 * Deactivation: no data is deleted, members keep their access when the
 * plugin comes back.
 *
 * @return void
 */
function aluleth_membership_deactivate() {
	wp_clear_scheduled_hook( 'aluleth_membership_hourly' );
	require_once __DIR__ . '/core/uninstall.php';
	aluleth_core_deactivate( __DIR__ );
}
register_deactivation_hook( __FILE__, 'aluleth_membership_deactivate' );
