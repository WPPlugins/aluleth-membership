<?php
/**
 * Uninstall. Nothing is deleted unless the site owner asked for it in
 * Aluleth › Settings:
 * - "Aluleth Membership": levels, members, content rules, member files,
 *   emails and the plugin's options (WordPress accounts are kept);
 * - shared data (contacts, consents, connections, send log): only when no
 *   other Aluleth plugin is left on the site.
 *
 * @package Aluleth\Membership
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/core/uninstall.php';

/**
 * Cleans the current site.
 *
 * @return void
 */
function aluleth_membership_uninstall_site() {
	global $wpdb;
	wp_clear_scheduled_hook( 'aluleth_membership_hourly' );
	if ( get_option( 'aluleth_membership_delete_data' ) ) {
		$ids = get_posts(
			array(
				'post_type'      => 'aluleth_level',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		$files = get_posts(
			array(
				'post_type'      => 'aluleth_mfile',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $files as $id ) {
			wp_delete_post( $id, true );
		}
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'aluleth-membership-files';
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		// Current names, and the names used before 0.4.0.
		foreach ( array( '_aluleth_menu_access', '_alm_menu' ) as $aluleth_membership_key ) {
			delete_post_meta_by_key( $aluleth_membership_key );
		}
		foreach ( array( '_aluleth_meter', '_aluleth_signin_links', '_alm_meter', '_alm_links', '_alm_created' ) as $aluleth_membership_key ) {
			delete_metadata( 'user', 0, $aluleth_membership_key, '', true );
		}
		foreach ( array( 'aluleth_members', 'aluleth_member_rules', 'alm_members', 'alm_rules' ) as $aluleth_membership_table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $aluleth_membership_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		delete_metadata( 'user', 0, '_aluleth_member_created', '', true );
		delete_option( 'aluleth_membership_db_version' );
		delete_option( 'aluleth_membership_settings' );
		delete_option( 'aluleth_membership_files_format' );
		delete_option( 'aluleth_membership_emails' );
		delete_option( 'aluleth_membership_delete_data' );
		delete_option( 'aluleth_membership_import' );
		delete_option( 'aluleth_membership_ai' );
	}
	aluleth_core_uninstall( __DIR__ );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $aluleth_membership_site ) {
		switch_to_blog( $aluleth_membership_site );
		aluleth_membership_uninstall_site();
		restore_current_blog();
	}
} else {
	aluleth_membership_uninstall_site();
}
