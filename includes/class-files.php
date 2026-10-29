<?php
/**
 * Files for members: uploaded to a folder of their own with a random name,
 * downloaded only through the site after the access check.
 *
 * The folder has an .htaccess that refuses direct access (Apache,
 * LiteSpeed); on other servers the random part of each file name keeps it
 * from being guessed.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Admin as Core_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Files.
 */
final class Files {

	/**
	 * Post type of the files.
	 */
	const POST_TYPE = 'aluleth_mfile';

	/**
	 * Meta key.
	 */
	const META = '_aluleth_member_file';

	/**
	 * Folder in uploads.
	 */
	const DIR = 'aluleth-membership-files';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'template_redirect', array( __CLASS__, 'download' ), 2 );
		add_shortcode( 'aluleth_file', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Registers the private post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Member files', 'aluleth-membership' ),
					'singular_name' => __( 'Member file', 'aluleth-membership' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => array( 'aluleth_mfile', 'aluleth_mfiles' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Absolute path of the folder, created with its protections.
	 *
	 * @return string
	 */
	public static function dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "# Aluleth Membership: files are downloaded through the site.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Every file.
	 *
	 * @return array[]
	 */
	public static function all() {
		$out = array();
		foreach (
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			) as $post
		) {
			$file = self::get( $post->ID );
			if ( $file ) {
				$out[] = $file;
			}
		}
		return $out;
	}

	/**
	 * One file.
	 *
	 * @param int $id File id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$meta = get_post_meta( $post->ID, self::META, true );
		$meta = is_array( $meta ) ? $meta : array();
		return array(
			'id'        => (int) $post->ID,
			'name'      => $post->post_title,
			'file'      => isset( $meta['file'] ) ? (string) $meta['file'] : '',
			'filename'  => isset( $meta['filename'] ) ? (string) $meta['filename'] : '',
			'type'      => isset( $meta['type'] ) ? (string) $meta['type'] : 'application/octet-stream',
			'size'      => isset( $meta['size'] ) ? (int) $meta['size'] : 0,
			'levels'    => isset( $meta['levels'] ) && 'any' !== $meta['levels'] ? array_map( 'intval', (array) $meta['levels'] ) : 'any',
			'downloads' => isset( $meta['downloads'] ) ? (int) $meta['downloads'] : 0,
		);
	}

	/**
	 * Saves an uploaded file.
	 *
	 * @param array        $upload File from $_FILES.
	 * @param string       $name   Name shown to members ('' for the file name).
	 * @param int[]|string $levels Levels or 'any'.
	 * @return int|\WP_Error File id.
	 */
	public static function add( array $upload, $name, $levels ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$dir    = self::dir();
		$filter = static function ( $dirs ) use ( $dir ) {
			$dirs['path']   = $dir;
			$dirs['url']    = '';
			$dirs['subdir'] = '';
			return $dirs;
		};
		$rename = static function ( $dir_path, $name_part, $ext ) {
			unset( $dir_path );
			return strtolower( wp_generate_password( 24, false, false ) ) . '-' . sanitize_file_name( $name_part . $ext );
		};
		add_filter( 'upload_dir', $filter );
		$result = wp_handle_upload(
			$upload,
			array(
				'test_form'                => false,
				'unique_filename_callback' => $rename,
			)
		);
		remove_filter( 'upload_dir', $filter );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'alm_upload', $result['error'] );
		}
		$original = sanitize_file_name( isset( $upload['name'] ) ? (string) $upload['name'] : basename( $result['file'] ) );
		$title    = '' !== trim( (string) $name ) ? sanitize_text_field( $name ) : $original;
		$id       = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $result['file'] );
			return $id;
		}
		$levels = 'any' === $levels ? 'any' : array_values( array_intersect( array_map( 'intval', (array) $levels ), array_keys( Levels::all() ) ) );
		update_post_meta(
			$id,
			self::META,
			array(
				'file'      => basename( $result['file'] ),
				'filename'  => $original,
				'type'      => $result['type'],
				'size'      => (int) filesize( $result['file'] ),
				'levels'    => $levels ? $levels : 'any',
				'downloads' => 0,
			)
		);
		return (int) $id;
	}

	/**
	 * Deletes a file.
	 *
	 * @param int $id File id.
	 * @return void
	 */
	public static function delete( $id ) {
		$file = self::get( $id );
		if ( ! $file ) {
			return;
		}
		$path = self::dir() . '/' . basename( $file['file'] );
		if ( '' !== $file['file'] && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		wp_delete_post( $file['id'], true );
	}

	/**
	 * Whether the current user can download a file.
	 *
	 * @param array $file File.
	 * @return bool
	 */
	public static function can_download( array $file ) {
		if ( current_user_can( Core_Admin::cap( 'membership' ) ) ) {
			return true;
		}
		$mine = Members::levels_of( get_current_user_id() );
		if ( 'any' === $file['levels'] ) {
			return (bool) $mine;
		}
		return (bool) array_intersect( $mine, $file['levels'] );
	}

	/**
	 * Download address of a file.
	 *
	 * @param int $id File id.
	 * @return string
	 */
	public static function url( $id ) {
		return add_query_arg( 'alm_file', (int) $id, home_url( '/' ) );
	}

	/**
	 * Files a user can download.
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	public static function for_user( $user_id ) {
		$mine = Members::levels_of( $user_id );
		if ( ! $mine ) {
			return array();
		}
		$out = array();
		foreach ( self::all() as $file ) {
			if ( 'any' === $file['levels'] || array_intersect( $mine, $file['levels'] ) ) {
				$out[] = $file;
			}
		}
		return $out;
	}

	/**
	 * ?alm_file=ID: sends the file to people who have access.
	 *
	 * @return void
	 */
	public static function download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a download link; access is checked below.
		$id = isset( $_GET['alm_file'] ) ? absint( $_GET['alm_file'] ) : 0;
		if ( ! $id ) {
			return;
		}
		$file = self::get( $id );
		if ( ! $file || '' === $file['file'] ) {
			status_header( 404 );
			nocache_headers();
			wp_die( esc_html__( 'This file no longer exists.', 'aluleth-membership' ), '', array( 'response' => 404 ) );
		}
		if ( ! self::can_download( $file ) ) {
			if ( ! is_user_logged_in() ) {
				$page = (int) Settings::get()['member_page'];
				$to   = $page && 'publish' === get_post_status( $page ) ? add_query_arg( 'alm_back', rawurlencode( self::url( $id ) ), Settings::member_url() ) : wp_login_url( self::url( $id ) );
				wp_safe_redirect( $to );
				exit;
			}
			nocache_headers();
			$join = Settings::join_url();
			wp_die(
				wp_kses_post( esc_html__( 'This file is for members. Your membership does not include it.', 'aluleth-membership' ) . ( $join ? ' <a href="' . esc_url( $join ) . '">' . esc_html__( 'See the memberships', 'aluleth-membership' ) . '</a>' : '' ) ),
				esc_html__( 'Members only', 'aluleth-membership' ),
				array( 'response' => 403 )
			);
		}
		$path = self::dir() . '/' . basename( $file['file'] );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This file no longer exists.', 'aluleth-membership' ), '', array( 'response' => 404 ) );
		}
		$meta              = get_post_meta( $id, self::META, true );
		$meta['downloads'] = isset( $meta['downloads'] ) ? (int) $meta['downloads'] + 1 : 1;
		update_post_meta( $id, self::META, $meta );

		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		header( 'Content-Type: ' . $file['type'] );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $file['filename'] ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams a protected file.
		exit;
	}

	/**
	 * [aluleth_file id="12" label="Download the guide"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'label' => '',
			),
			$atts,
			'aluleth_file'
		);
		$file = self::get( absint( $atts['id'] ) );
		if ( ! $file ) {
			return '';
		}
		Plugin::enqueue_front();
		$label = '' !== $atts['label'] ? sanitize_text_field( $atts['label'] ) : $file['name'];
		if ( self::can_download( $file ) ) {
			return '<p class="alm-file"><a class="alm-file__link" href="' . esc_url( self::url( $file['id'] ) ) . '">' . esc_html( $label ) . '</a> <span class="alm-file__size">' . esc_html( size_format( $file['size'] ) ) . '</span></p>';
		}
		$join = Settings::join_url();
		return '<p class="alm-file alm-file--locked"><span>' . esc_html( $label ) . '</span> <span class="alm-file__size">' . esc_html__( 'Members only', 'aluleth-membership' ) . '</span>' . ( '' !== $join ? ' <a href="' . esc_url( $join ) . '">' . esc_html__( 'Join', 'aluleth-membership' ) . '</a>' : '' ) . '</p>';
	}
}
