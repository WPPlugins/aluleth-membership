<?php
/**
 * Aluleth Membership tables.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	/**
	 * Database version of the membership tables.
	 */
	const DB_VERSION = '0.3.0';

	/**
	 * Meta renamed in 0.4.0 of the plugin (DB 0.3.0): type => old key => new key.
	 */
	const META_RENAMES = array(
		'post' => array(
			'_alm_level' => '_aluleth_level',
			'_alm_menu'  => '_aluleth_menu_access',
			'_alm_file'  => '_aluleth_member_file',
		),
		'user' => array(
			'_alm_links'   => '_aluleth_signin_links',
			'_alm_meter'   => '_aluleth_meter',
			'_alm_created' => '_aluleth_member_created',
		),
	);

	/**
	 * Creates or updates the tables.
	 *
	 * - aluleth_members: one row per access given (a level, a person, where the
	 *   access comes from). A person has a level while one of their rows for
	 *   it is active and not past its end date.
	 * - aluleth_member_rules: what is for members (a post, a category, a content type,
	 *   an address), which levels open it, how many days after joining
	 *   (drip_days) and whether visitors may read it within their free
	 *   articles of the month (metered).
	 *
	 * Before 0.4.0 the tables were "alm_members" and "alm_rules" and the
	 * meta keys started with "_alm_": they are renamed here.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$map = array(
			$wpdb->prefix . 'alm_members' => Members::table(),
			$wpdb->prefix . 'alm_rules'   => Rules::table(),
		);
		\Aluleth\Core\Installer::rename_tables( $map );
		$charset = $wpdb->get_charset_collate();
		$members = Members::table();
		$rules   = Rules::table();
		dbDelta(
			"CREATE TABLE {$members} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  level_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  source varchar(30) NOT NULL DEFAULT 'manual',
  source_ref varchar(64) NOT NULL DEFAULT '',
  started_at datetime NOT NULL,
  expires_at datetime DEFAULT NULL,
  ended_at datetime DEFAULT NULL,
  end_reason varchar(30) NOT NULL DEFAULT '',
  reminded tinyint(1) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY grant_key (user_id,level_id,source,source_ref),
  KEY level_status (level_id,status),
  KEY status_expires (status,expires_at)
) $charset;
CREATE TABLE {$rules} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL,
  target varchar(191) NOT NULL,
  levels varchar(191) NOT NULL DEFAULT '',
  mode varchar(20) NOT NULL DEFAULT 'teaser',
  drip_days smallint(5) unsigned NOT NULL DEFAULT 0,
  metered tinyint(1) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY kind_target (kind,target)
) $charset;"
		);
		\Aluleth\Core\Installer::finish_renames( $map );
		foreach ( self::META_RENAMES['post'] as $old => $new ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time rename.
		}
		foreach ( self::META_RENAMES['user'] as $old => $new ) {
			$wpdb->update( $wpdb->usermeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time rename.
		}
		wp_cache_flush_group( 'post_meta' );
		wp_cache_flush_group( 'user_meta' );
		if ( ! wp_next_scheduled( 'aluleth_membership_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'aluleth_membership_hourly' );
		}
		update_option( 'aluleth_membership_db_version', self::DB_VERSION, false );
	}
}
