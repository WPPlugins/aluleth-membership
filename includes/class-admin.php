<?php
/**
 * Aluleth › Membership: overview, levels, members, content access, emails
 * and settings.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Admin as Core_Admin;
use Aluleth\Core\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
final class Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'aluleth-membership';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'level_save', 'level_delete', 'level_move', 'member_add', 'member_end', 'rule_save', 'rule_delete', 'card_save', 'file_add', 'file_delete', 'emails_save', 'settings_save', 'import_run', 'import_check', 'import_follow' ) as $action ) {
			add_action( 'admin_post_aluleth_membership_' . $action, array( __CLASS__, 'do_' . $action ) );
		}
	}

	/**
	 * URL of a membership screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Adds Aluleth › Membership.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( Core_Admin::MENU, __( 'Membership', 'aluleth-membership' ), __( 'Membership', 'aluleth-membership' ), Core_Admin::cap( 'membership' ), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Styles and script of the screens, and of the post editor box.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		$url = plugins_url( 'assets/', ALULETH_MEMBERSHIP_FILE );
		wp_enqueue_style( 'aluleth-membership', $url . 'membership.css', array(), ALULETH_MEMBERSHIP_VERSION );
		wp_enqueue_style( 'aluleth-membership-admin', $url . 'admin.css', array(), ALULETH_MEMBERSHIP_VERSION );
		wp_enqueue_script( 'aluleth-membership-admin', $url . 'admin.js', array(), ALULETH_MEMBERSHIP_VERSION, true );
	}

	/**
	 * Stops when the user can't manage memberships.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! current_user_can( Core_Admin::cap( 'membership' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage memberships.', 'aluleth-membership' ) );
		}
	}

	/**
	 * Goes back to a screen with a message.
	 *
	 * @param array  $args Screen arguments.
	 * @param string $done Message key.
	 * @return void
	 */
	private static function back( array $args, $done ) {
		wp_safe_redirect( self::url( array_merge( $args, array( 'done' => $done ) ) ) );
		exit;
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function page() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screens.
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		// phpcs:enable
		$tabs = array(
			'overview' => __( 'Overview', 'aluleth-membership' ),
			'levels'   => __( 'Levels', 'aluleth-membership' ),
			'members'  => __( 'Members', 'aluleth-membership' ),
			'access'   => __( 'Content access', 'aluleth-membership' ),
			'emails'   => __( 'Emails', 'aluleth-membership' ),
			'settings' => __( 'Settings', 'aluleth-membership' ),
			'import'   => __( 'Import', 'aluleth-membership' ),
		);
		/**
		 * Filters the tabs of the Membership screen (Membership Pro adds Courses and Reports).
		 *
		 * @param array<string, string> $tabs Key => label.
		 */
		$tabs = (array) apply_filters( 'aluleth_membership/admin_tabs', $tabs );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		echo '<div class="wrap al-wrap alm-admin">';
		echo '<h1 class="al-title">' . esc_html__( 'Membership', 'aluleth-membership' ) . '</h1>';
		echo '<nav class="alm-tabs" aria-label="' . esc_attr__( 'Membership', 'aluleth-membership' ) . '">';
		foreach ( $tabs as $key => $label ) {
			echo '<a href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '"' . ( $key === $tab ? ' class="is-current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		self::notice();
		switch ( $tab ) {
			case 'levels':
				if ( '' !== $level ) {
					self::level_editor( 'new' === $level ? 0 : absint( $level ) );
				} else {
					self::levels();
				}
				break;
			case 'members':
				self::members();
				break;
			case 'access':
				self::access();
				break;
			case 'emails':
				self::emails();
				break;
			case 'settings':
				self::settings();
				break;
			case 'import':
				self::import();
				break;
			case 'overview':
				self::overview();
				break;
			default:
				/**
				 * Renders a tab added by an add-on.
				 */
				do_action( 'aluleth_membership/admin_tab_' . $tab );
		}
		echo '</div>';
	}

	/**
	 * Message after an action.
	 *
	 * @return void
	 */
	private static function notice() {
		$done     = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'      => __( 'Saved.', 'aluleth-membership' ),
			'deleted'    => __( 'Deleted.', 'aluleth-membership' ),
			'added'      => __( 'Membership given.', 'aluleth-membership' ),
			'ended'      => __( 'Access ended.', 'aluleth-membership' ),
			'rule'       => __( 'Rule saved. It applies right away.', 'aluleth-membership' ),
			'page'       => __( 'Page created and published.', 'aluleth-membership' ),
			'sent'       => __( 'Test email sent to your address.', 'aluleth-membership' ),
			'not_sent'   => __( 'The test email could not be sent. Check Aluleth › Email sending.', 'aluleth-membership' ),
			'no_email'   => __( 'Please type a valid email address.', 'aluleth-membership' ),
			'no_user'    => __( 'This account could not be created.', 'aluleth-membership' ),
			'bad_rule'   => __( 'Choose what to protect.', 'aluleth-membership' ),
			'need_level' => __( 'Create a level first.', 'aluleth-membership' ),
			'file'       => __( 'File added. Members see it in their member area.', 'aluleth-membership' ),
			'no_file'    => __( 'The file could not be added.', 'aluleth-membership' ),
			'imported'   => __( 'Import done. The result is on the right.', 'aluleth-membership' ),
			'no_import'  => __( 'The import could not run: nothing to import from this plugin.', 'aluleth-membership' ),
			'followed'   => __( 'Checked. Memberships are up to date.', 'aluleth-membership' ),
		);
		if ( isset( $messages[ $done ] ) ) {
			$error = in_array( $done, array( 'not_sent', 'no_email', 'no_user', 'bad_rule', 'need_level', 'no_file', 'no_import' ), true );
			echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $messages[ $done ] ) . '</p></div>';
		}
	}

	/**
	 * Overview: key numbers, levels, what to set up, latest activity.
	 *
	 * @return void
	 */
	private static function overview() {
		$levels = Levels::all();
		$counts = Members::counts();
		$since  = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$s      = Settings::get();
		$rules  = Rules::all();

		echo '<div class="alm-kpis">';
		self::kpi( __( 'Members now', 'aluleth-membership' ), number_format_i18n( Members::count_people() ), __( 'People with at least one level', 'aluleth-membership' ) );
		self::kpi( __( 'Joined', 'aluleth-membership' ), number_format_i18n( Members::count_since( 'started_at', $since ) ), __( 'Last 30 days', 'aluleth-membership' ) );
		self::kpi( __( 'Ended', 'aluleth-membership' ), number_format_i18n( Members::count_since( 'ended_at', $since ) ), __( 'Last 30 days', 'aluleth-membership' ) );
		self::kpi( __( 'Content rules', 'aluleth-membership' ), number_format_i18n( count( $rules ) ), __( 'What is for members', 'aluleth-membership' ) );
		echo '</div>';

		$steps = array(
			array(
				(bool) $levels,
				__( 'Create your first level', 'aluleth-membership' ),
				self::url(
					array(
						'tab'   => 'levels',
						'level' => 'new',
					)
				),
			),
			array( (bool) array_filter( $levels, array( __CLASS__, 'has_source' ) ), __( 'Choose how people join (a product, a free sign-up, a form)', 'aluleth-membership' ), self::url( array( 'tab' => 'levels' ) ) ),
			array( (bool) $rules, __( 'Choose what is for members', 'aluleth-membership' ), self::url( array( 'tab' => 'access' ) ) ),
			array( $s['member_page'] && 'publish' === get_post_status( $s['member_page'] ), __( 'Publish the member area page', 'aluleth-membership' ), self::url( array( 'tab' => 'settings' ) ) ),
		);
		$todo  = array_filter(
			$steps,
			static function ( $step ) {
				return ! $step[0];
			}
		);
		echo '<div class="alm-grid">';
		echo '<section class="al-card alm-card-admin"><h2>' . esc_html__( 'Levels', 'aluleth-membership' ) . '</h2>';
		if ( ! $levels ) {
			echo '<p>' . esc_html__( 'A level is what people get: "Club", "Pro", "Yearly pass". Each level opens the content you choose.', 'aluleth-membership' ) . '</p><a class="al-button al-button--accent" href="' . esc_url(
				self::url(
					array(
						'tab'   => 'levels',
						'level' => 'new',
					)
				)
			) . '">' . esc_html__( 'Create a level', 'aluleth-membership' ) . '</a>';
		} else {
			echo '<table class="widefat striped al-table alm-table"><thead><tr><th>' . esc_html__( 'Level', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'How people join', 'aluleth-membership' ) . '</th><th class="num">' . esc_html__( 'Members', 'aluleth-membership' ) . '</th></tr></thead><tbody>';
			foreach ( $levels as $id => $level ) {
				echo '<tr><td><a href="' . esc_url(
					self::url(
						array(
							'tab'   => 'levels',
							'level' => $id,
						)
					)
				) . '"><strong>' . esc_html( $level['name'] ) . '</strong></a>' . ( '' !== $level['price'] ? ' <span class="al-muted">' . esc_html( $level['price'] ) . '</span>' : '' ) . '</td><td>' . esc_html( self::sources_text( $level ) ) . '</td><td class="num"><a href="' . esc_url(
					self::url(
						array(
							'tab'   => 'members',
							'level' => $id,
						)
					)
				) . '">' . esc_html( number_format_i18n( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ) ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		echo '<section class="al-card alm-card-admin">';
		if ( $todo ) {
			echo '<h2>' . esc_html__( 'To set up', 'aluleth-membership' ) . '</h2><ol class="alm-steps">';
			foreach ( $steps as $step ) {
				echo '<li class="' . ( $step[0] ? 'is-done' : '' ) . '">' . ( $step[0] ? '<span>' . esc_html( $step[1] ) . '</span>' : '<a href="' . esc_url( $step[2] ) . '">' . esc_html( $step[1] ) . '</a>' ) . '</li>';
			}
			echo '</ol>';
		}
		echo '<h2>' . esc_html__( 'Latest', 'aluleth-membership' ) . '</h2>';
		$recent = Members::recent( 8 );
		if ( ! $recent ) {
			echo '<p class="al-muted">' . esc_html__( 'Joins and ends will show here.', 'aluleth-membership' ) . '</p>';
		} else {
			echo '<ul class="al-timeline">';
			foreach ( $recent as $row ) {
				$level = Levels::get( $row->level_id );
				$name  = $level ? $level['name'] : '#' . $row->level_id;
				$text  = 'ended' === $row->status
					/* translators: 1: person, 2: level. */
					? sprintf( __( '%1$s left %2$s', 'aluleth-membership' ), $row->display_name, $name )
					/* translators: 1: person, 2: level. */
					: sprintf( __( '%1$s joined %2$s', 'aluleth-membership' ), $row->display_name, $name );
				echo '<li><strong>' . esc_html( $text ) . '</strong><span>' . esc_html( Core_Admin::when( $row->happened ) . ' · ' . self::source_label( $row->source ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</section></div>';
	}

	/**
	 * Whether a level has a way to join besides "by hand".
	 *
	 * @param array $level Level.
	 * @return bool
	 */
	public static function has_source( array $level ) {
		return $level['free_signup'] || $level['fluentcart'] || $level['woocommerce'] || $level['forms'];
	}

	/**
	 * One key number.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $note  Note.
	 * @return void
	 */
	private static function kpi( $label, $value, $note ) {
		echo '<div class="alm-kpi"><span class="alm-kpi__label">' . esc_html( $label ) . '</span><strong class="alm-kpi__value">' . esc_html( $value ) . '</strong><span class="alm-kpi__note">' . esc_html( $note ) . '</span></div>';
	}

	/**
	 * Words for a source.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'fluentcart'  => 'FluentCart',
			'woocommerce' => 'WooCommerce',
			'form'        => __( 'Form', 'aluleth-membership' ),
			'signup'      => __( 'Free sign-up', 'aluleth-membership' ),
			'manual'      => __( 'By hand', 'aluleth-membership' ),
			'import'      => __( 'Import', 'aluleth-membership' ),
			'pmpro'       => 'Paid Memberships Pro',
			'rcp'         => 'Restrict Content',
			'swpm'        => 'Simple Membership',
			'team'        => __( 'Team', 'aluleth-membership' ),
		);
		$label  = isset( $labels[ $source ] ) ? $labels[ $source ] : $source;

		/**
		 * Filters the words shown for the source of an access.
		 *
		 * @param string $label  Words.
		 * @param string $source Source key.
		 */
		return (string) apply_filters( 'aluleth_membership/source_label', $label, $source );
	}

	/**
	 * Words for a source reference (" · order #12").
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	public static function ref_text( $ref ) {
		$parts = explode( ':', (string) $ref, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		/**
		 * Filters the words shown for the reference of an access
		 * (" · order #12"). Return a string to replace the default.
		 *
		 * @param string|null $text Words, null for the default.
		 * @param string      $ref  Reference ("order:12").
		 */
		$text = apply_filters( 'aluleth_membership/ref_text', null, (string) $ref );
		if ( is_string( $text ) ) {
			return $text;
		}
		$id = (int) $parts[1];
		switch ( $parts[0] ) {
			case 'order':
				/* translators: %d: order number. */
				return ' · ' . sprintf( __( 'order #%d', 'aluleth-membership' ), $id );
			case 'sub':
			case 'wcs':
				/* translators: %d: subscription number. */
				return ' · ' . sprintf( __( 'subscription #%d', 'aluleth-membership' ), $id );
			case 'entry':
				/* translators: %d: entry number. */
				return ' · ' . sprintf( __( 'entry #%d', 'aluleth-membership' ), $id );
			case 'team':
				/* translators: %d: team number. */
				return ' · ' . sprintf( __( 'team #%d', 'aluleth-membership' ), $id );
			case 'by':
				$user = get_userdata( $id );
				return $user ? ' · ' . $user->display_name : '';
		}
		return '';
	}

	/**
	 * Words for the ways to join a level.
	 *
	 * @param array $level Level.
	 * @return string
	 */
	private static function sources_text( array $level ) {
		$bits = array();
		if ( $level['fluentcart'] ) {
			$bits[] = 'FluentCart';
		}
		if ( $level['woocommerce'] ) {
			$bits[] = 'WooCommerce';
		}
		if ( $level['forms'] ) {
			$bits[] = __( 'Form', 'aluleth-membership' );
		}
		if ( $level['free_signup'] ) {
			$bits[] = __( 'Free sign-up', 'aluleth-membership' );
		}
		$bits[] = __( 'By hand', 'aluleth-membership' );
		return implode( ', ', $bits );
	}

	/**
	 * Levels list.
	 *
	 * @return void
	 */
	private static function levels() {
		$levels = Levels::all();
		$counts = Members::counts();
		echo '<div class="alm-head"><p class="al-muted">' . esc_html__( 'The order here is the order on join cards.', 'aluleth-membership' ) . '</p><a class="al-button al-button--accent" href="' . esc_url(
			self::url(
				array(
					'tab'   => 'levels',
					'level' => 'new',
				)
			)
		) . '">' . esc_html__( 'Add a level', 'aluleth-membership' ) . '</a></div>';
		if ( ! $levels ) {
			echo '<div class="al-empty"><p>' . esc_html__( 'No level yet. A level is what people get: "Club", "Pro", "Yearly pass".', 'aluleth-membership' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped al-table alm-table"><thead><tr><th>' . esc_html__( 'Level', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Price shown', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'How people join', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Length', 'aluleth-membership' ) . '</th><th class="num">' . esc_html__( 'Members', 'aluleth-membership' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Order', 'aluleth-membership' ) . '</span></th></tr></thead><tbody>';
		$i = 0;
		$n = count( $levels );
		foreach ( $levels as $id => $level ) {
			echo '<tr><td><a href="' . esc_url(
				self::url(
					array(
						'tab'   => 'levels',
						'level' => $id,
					)
				)
			) . '"><strong>' . esc_html( $level['name'] ) . '</strong></a>';
			if ( $level['popular'] ) {
				echo ' <span class="al-badge al-badge--new">' . esc_html__( 'Popular', 'aluleth-membership' ) . '</span>';
			}
			if ( ! $level['on_card'] ) {
				echo ' <span class="al-badge">' . esc_html__( 'Not on cards', 'aluleth-membership' ) . '</span>';
			}
			echo '</td><td>' . esc_html( '' !== $level['price'] ? $level['price'] : '—' ) . '</td><td>' . esc_html( self::sources_text( $level ) ) . '</td><td>' . esc_html( Levels::duration_text( $level ) ) . '</td>';
			echo '<td class="num"><a href="' . esc_url(
				self::url(
					array(
						'tab'   => 'members',
						'level' => $id,
					)
				)
			) . '">' . esc_html( number_format_i18n( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ) ) . '</a></td><td class="alm-move">';
			if ( $i > 0 ) {
				echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=alm_level_move&level=' . $id . '&dir=-1' ), 'aluleth_membership_level_move' ) ) . '" aria-label="' . esc_attr__( 'Move up', 'aluleth-membership' ) . '">↑</a>';
			}
			if ( $i < $n - 1 ) {
				echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=alm_level_move&level=' . $id . '&dir=1' ), 'aluleth_membership_level_move' ) ) . '" aria-label="' . esc_attr__( 'Move down', 'aluleth-membership' ) . '">↓</a>';
			}
			echo '</td></tr>';
			++$i;
		}
		echo '</tbody></table>';
	}

	/**
	 * Published items of a post type, for pickers.
	 *
	 * @param string $type Post type.
	 * @return \WP_Post[]
	 */
	private static function items( $type ) {
		if ( ! post_type_exists( $type ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * A list of checkboxes.
	 *
	 * @param string     $name    Field name.
	 * @param \WP_Post[] $items   Items.
	 * @param int[]      $checked Ticked ids.
	 * @param string     $none   Text when there is nothing.
	 * @return void
	 */
	private static function checklist( $name, array $items, array $checked, $none ) {
		if ( ! $items ) {
			echo '<p class="al-muted">' . esc_html( $none ) . '</p>';
			return;
		}
		echo '<div class="alm-checklist">';
		foreach ( $items as $item ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . (int) $item->ID . '"' . checked( in_array( (int) $item->ID, $checked, true ), true, false ) . '> ' . esc_html( '' !== $item->post_title ? $item->post_title : '#' . $item->ID ) . '</label>';
		}
		echo '</div>';
	}

	/**
	 * Level editor.
	 *
	 * @param int $id Level id (0: new).
	 * @return void
	 */
	private static function level_editor( $id ) {
		$level = $id ? Levels::get( $id ) : null;
		if ( $id && ! $level ) {
			echo '<div class="al-empty"><p>' . esc_html__( 'This level no longer exists.', 'aluleth-membership' ) . '</p></div>';
			return;
		}
		$level = $level ? $level : array_merge(
			Levels::defaults(),
			array(
				'id'   => 0,
				'name' => '',
			)
		);
		$count = $id ? ( isset( Members::counts()[ $id ] ) ? Members::counts()[ $id ] : 0 ) : 0;

		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'levels' ) ) ) . '">← ' . esc_html__( 'All levels', 'aluleth-membership' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alm-editor">';
		wp_nonce_field( 'aluleth_membership_level_save' );
		echo '<input type="hidden" name="action" value="aluleth_membership_level_save"><input type="hidden" name="level_id" value="' . (int) $id . '">';
		echo '<div class="alm-editor__main">';

		echo '<section class="al-card"><h2>' . esc_html__( 'Details', 'aluleth-membership' ) . '</h2>';
		echo '<p class="alm-field"><label for="alm-name">' . esc_html__( 'Name', 'aluleth-membership' ) . '</label><input id="alm-name" type="text" name="name" class="regular-text" required value="' . esc_attr( $level['name'] ) . '" placeholder="' . esc_attr__( 'Club', 'aluleth-membership' ) . '"></p>';
		echo '<p class="alm-field"><label for="alm-desc">' . esc_html__( 'What members get', 'aluleth-membership' ) . '</label><textarea id="alm-desc" name="level[description]" rows="3" class="large-text" placeholder="' . esc_attr__( 'Every guide, the video workshops and 10% off in the shop.', 'aluleth-membership' ) . '">' . esc_textarea( $level['description'] ) . '</textarea></p>';
		echo '<p class="alm-field"><label for="alm-price">' . esc_html__( 'Price shown on join cards', 'aluleth-membership' ) . '</label><input id="alm-price" type="text" name="level[price]" class="regular-text" value="' . esc_attr( $level['price'] ) . '" placeholder="' . esc_attr__( '$7 / month', 'aluleth-membership' ) . '"><span class="description">' . esc_html__( 'Only a text: the real price is the one of your product.', 'aluleth-membership' ) . '</span></p>';
		echo '<p><label class="al-switch"><input type="checkbox" name="level[on_card]" value="1"' . checked( $level['on_card'], true, false ) . '> ' . esc_html__( 'Show on join cards', 'aluleth-membership' ) . '</label> &nbsp; <label class="al-switch"><input type="checkbox" name="level[popular]" value="1"' . checked( $level['popular'], true, false ) . '> ' . esc_html__( 'Mark as popular', 'aluleth-membership' ) . '</label></p>';
		echo '</section>';

		echo '<section class="al-card"><h2>' . esc_html__( 'How people join', 'aluleth-membership' ) . '</h2>';
		echo '<p class="al-muted">' . esc_html__( 'Tick every way that gives this level. You can always add members by hand.', 'aluleth-membership' ) . '</p>';
		echo '<h3>FluentCart</h3>';
		if ( post_type_exists( 'fluent-products' ) ) {
			echo '<p class="description">' . esc_html__( 'Buying one of these products gives the level. With a subscription, access follows the subscription: paused when it is paused, kept until the end of the paid period when it is cancelled, ended when it expires. A full refund ends the access.', 'aluleth-membership' ) . '</p>';
			self::checklist( 'level[fluentcart]', self::items( 'fluent-products' ), $level['fluentcart'], __( 'No FluentCart product yet.', 'aluleth-membership' ) );
		} else {
			echo '<p class="al-muted">' . esc_html__( 'FluentCart is not active on this site.', 'aluleth-membership' ) . '</p>';
		}
		echo '<h3>WooCommerce</h3>';
		if ( class_exists( 'WooCommerce' ) ) {
			echo '<p class="description">' . esc_html__( 'A paid order (processing or completed) with one of these products gives the level; a refund or cancellation ends it. WooCommerce Subscriptions is followed too.', 'aluleth-membership' ) . '</p>';
			self::checklist( 'level[woocommerce]', self::items( 'product' ), $level['woocommerce'], __( 'No WooCommerce product yet.', 'aluleth-membership' ) );
		} else {
			echo '<p class="al-muted">' . esc_html__( 'WooCommerce is not active on this site.', 'aluleth-membership' ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Aluleth Forms', 'aluleth-membership' ) . '</h3>';
		if ( post_type_exists( 'aluleth_form' ) ) {
			echo '<p class="description">' . esc_html__( 'Sending one of these forms gives the level to the email of the form (after the payment, for a payment form). An account is created when needed.', 'aluleth-membership' ) . '</p>';
			self::checklist( 'level[forms]', self::items( 'aluleth_form' ), $level['forms'], __( 'No form yet.', 'aluleth-membership' ) );
		} else {
			echo '<p class="al-muted">' . esc_html__( 'Install the free Aluleth Forms plugin to give a level with a form.', 'aluleth-membership' ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Free sign-up', 'aluleth-membership' ) . '</h3>';
		echo '<p><label class="al-switch"><input type="checkbox" name="level[free_signup]" value="1"' . checked( $level['free_signup'], true, false ) . '> ' . esc_html__( 'Anyone can join for free from a join card', 'aluleth-membership' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Visitors type their name and email; the level opens when they click the link in their email. No password.', 'aluleth-membership' ) . '</p>';
		echo '</section>';

		echo '<section class="al-card"><h2>' . esc_html__( 'Length', 'aluleth-membership' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'For one-time purchases, sign-ups, forms and members added by hand. Subscriptions follow their own renewals.', 'aluleth-membership' ) . '</p>';
		echo '<p class="alm-inline"><select name="level[duration]" data-alm-duration>';
		foreach (
			array(
				'lifetime' => __( 'No end date', 'aluleth-membership' ),
				'days'     => __( 'Days', 'aluleth-membership' ),
				'months'   => __( 'Months', 'aluleth-membership' ),
				'years'    => __( 'Years', 'aluleth-membership' ),
			) as $key => $label
		) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $level['duration'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <input type="number" min="1" max="3650" name="level[duration_n]" value="' . (int) $level['duration_n'] . '" class="small-text" aria-label="' . esc_attr__( 'How many', 'aluleth-membership' ) . '" data-alm-duration-n></p>';
		echo '</section>';

		echo '<section class="al-card"><h2>' . esc_html__( 'Join button and welcome', 'aluleth-membership' ) . '</h2>';
		echo '<p class="alm-field"><label for="alm-blabel">' . esc_html__( 'Button text', 'aluleth-membership' ) . '</label><input id="alm-blabel" type="text" name="level[button_label]" class="regular-text" value="' . esc_attr( $level['button_label'] ) . '" placeholder="' . esc_attr__( 'Join the club', 'aluleth-membership' ) . '"></p>';
		echo '<p class="alm-field"><label for="alm-burl">' . esc_html__( 'Button link', 'aluleth-membership' ) . '</label><input id="alm-burl" type="url" name="level[button_url]" class="regular-text" value="' . esc_attr( $level['button_url'] ) . '" placeholder="https://"><span class="description">' . esc_html__( 'Empty: the page of the first product ticked above, or the free sign-up form.', 'aluleth-membership' ) . '</span></p>';
		echo '<p class="alm-field"><label for="alm-redirect">' . esc_html__( 'Page opened after joining', 'aluleth-membership' ) . '</label>';
		wp_dropdown_pages(
			array(
				'name'              => 'level[redirect]',
				'id'                => 'alm-redirect',
				'selected'          => (int) $level['redirect'],
				'show_option_none'  => esc_html__( 'The member area', 'aluleth-membership' ),
				'option_none_value' => '0',
			)
		);
		echo '</p></section>';

		/**
		 * Adds sections to the level editor, inside its form (Membership Pro:
		 * trial and plan changes). Fields named level[...] reach
		 * "aluleth_membership/level_saved".
		 *
		 * @param array $level Level (id 0 for a new one).
		 */
		do_action( 'aluleth_membership/level_editor', $level );
		echo '</div>';

		echo '<aside class="alm-editor__side"><section class="al-card"><p><button type="submit" class="al-button al-button--accent">' . esc_html( $id ? __( 'Save the level', 'aluleth-membership' ) : __( 'Create the level', 'aluleth-membership' ) ) . '</button></p>';
		if ( $id ) {
			/* translators: %s: number of members. */
			echo '<p class="al-muted">' . esc_html( sprintf( _n( '%s member now.', '%s members now.', $count, 'aluleth-membership' ), number_format_i18n( $count ) ) ) . '</p>';
			echo '<p><a href="' . esc_url(
				self::url(
					array(
						'tab'   => 'members',
						'level' => $id,
					)
				)
			) . '">' . esc_html__( 'See the members', 'aluleth-membership' ) . '</a></p>';
		}
		if ( $id && '' === Levels::join_url( $level ) && ! $level['free_signup'] ) {
			echo '<p class="alm-warn">' . esc_html__( 'Join cards show no button for this level yet: tick a product or the free sign-up, or add a button link.', 'aluleth-membership' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Join card preview:', 'aluleth-membership' ) . '</p>';
		$card = Join_Card::render(
			$id ? array( $id ) : array(),
			array(
				'login'   => false,
				'preview' => true,
			)
		);
		echo '<div class="alm-preview">' . $card . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped parts.
		echo '</section></aside></form>';

		if ( $id ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alm-danger" data-alm-confirm="' . esc_attr(
				$count
					/* translators: %s: number of members. */
					? sprintf( _n( 'Delete this level? %s person loses access now.', 'Delete this level? %s people lose access now.', $count, 'aluleth-membership' ), number_format_i18n( $count ) )
					: __( 'Delete this level?', 'aluleth-membership' )
			) . '">';
			wp_nonce_field( 'aluleth_membership_level_delete' );
			echo '<input type="hidden" name="action" value="aluleth_membership_level_delete"><input type="hidden" name="level_id" value="' . (int) $id . '"><button type="submit" class="button-link button-link-delete">' . esc_html__( 'Delete the level', 'aluleth-membership' ) . '</button></form>';
		}
	}

	/**
	 * Saves a level.
	 *
	 * @return void
	 */
	public static function do_level_save() {
		self::guard();
		check_admin_referer( 'aluleth_membership_level_save' );
		$id   = isset( $_POST['level_id'] ) ? absint( $_POST['level_id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$raw  = isset( $_POST['level'] ) && is_array( $_POST['level'] ) ? map_deep( wp_unslash( $_POST['level'] ), 'sanitize_textarea_field' ) : array();
		$id   = Levels::save( $id, $name, Levels::clean( $raw ) );
		if ( $id ) {
			/**
			 * Fires after a level is saved from its editor.
			 *
			 * @param int   $id  Level id.
			 * @param array $raw Posted level fields (sanitized text), add-on fields included.
			 */
			do_action( 'aluleth_membership/level_saved', $id, $raw );
		}
		self::back(
			array(
				'tab'   => 'levels',
				'level' => $id,
			),
			'saved'
		);
	}

	/**
	 * Deletes a level.
	 *
	 * @return void
	 */
	public static function do_level_delete() {
		self::guard();
		check_admin_referer( 'aluleth_membership_level_delete' );
		$id = isset( $_POST['level_id'] ) ? absint( $_POST['level_id'] ) : 0;
		if ( $id && Levels::get( $id ) ) {
			Levels::delete( $id );
		}
		self::back( array( 'tab' => 'levels' ), 'deleted' );
	}

	/**
	 * Moves a level.
	 *
	 * @return void
	 */
	public static function do_level_move() {
		self::guard();
		check_admin_referer( 'aluleth_membership_level_move' );
		$id  = isset( $_GET['level'] ) ? absint( $_GET['level'] ) : 0;
		$dir = isset( $_GET['dir'] ) ? (int) $_GET['dir'] : 1;
		Levels::move( $id, $dir );
		self::back( array( 'tab' => 'levels' ), 'saved' );
	}

	/**
	 * Members list, with "Add a member".
	 *
	 * @return void
	 */
	private static function members() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$level  = isset( $_GET['level'] ) ? absint( $_GET['level'] ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$levels = Levels::all();
		if ( ! $levels ) {
			echo '<div class="al-empty"><p>' . esc_html__( 'Create a level first: members are people with a level.', 'aluleth-membership' ) . '</p><a class="al-button al-button--accent" href="' . esc_url(
				self::url(
					array(
						'tab'   => 'levels',
						'level' => 'new',
					)
				)
			) . '">' . esc_html__( 'Create a level', 'aluleth-membership' ) . '</a></div>';
			return;
		}
		$result = Members::query(
			array(
				'level'    => $level,
				'status'   => $status,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => 30,
			)
		);

		echo '<div class="alm-grid alm-grid--members"><div>';
		echo '<form method="get" class="alm-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="members">';
		echo '<select name="level" aria-label="' . esc_attr__( 'Level', 'aluleth-membership' ) . '"><option value="0">' . esc_html__( 'All levels', 'aluleth-membership' ) . '</option>';
		foreach ( $levels as $id => $lv ) {
			echo '<option value="' . (int) $id . '"' . selected( $level, $id, false ) . '>' . esc_html( $lv['name'] ) . '</option>';
		}
		echo '</select><select name="status" aria-label="' . esc_attr__( 'Status', 'aluleth-membership' ) . '">';
		foreach (
			array(
				'active'  => __( 'Active now', 'aluleth-membership' ),
				'paused'  => __( 'Paused', 'aluleth-membership' ),
				'pending' => __( 'Waiting for the email click', 'aluleth-membership' ),
				'ended'   => __( 'Ended', 'aluleth-membership' ),
				'all'     => __( 'All', 'aluleth-membership' ),
			) as $key => $label
		) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name or email', 'aluleth-membership' ) . '"><button class="button">' . esc_html__( 'Filter', 'aluleth-membership' ) . '</button></form>';

		if ( ! $result['items'] ) {
			echo '<div class="al-empty"><p>' . esc_html__( 'Nobody here yet.', 'aluleth-membership' ) . '</p></div>';
		} else {
			/* translators: %s: number of lines. */
			echo '<p class="al-muted">' . esc_html( sprintf( _n( '%s line', '%s lines', $result['total'], 'aluleth-membership' ), number_format_i18n( $result['total'] ) ) ) . '</p>';
			echo '<table class="widefat striped al-table alm-table"><thead><tr><th>' . esc_html__( 'Person', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Level', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'From', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Since', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Until', 'aluleth-membership' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'aluleth-membership' ) . '</span></th></tr></thead><tbody>';
			foreach ( $result['items'] as $row ) {
				$lv      = Levels::get( $row->level_id );
				$contact = aluleth_core()->contacts()->get_by_email( $row->user_email );
				$person  = '<strong>' . esc_html( $row->display_name ) . '</strong><br><span class="al-muted">' . esc_html( $row->user_email ) . '</span>';
				if ( $contact ) {
					$person = '<a href="' . esc_url(
						add_query_arg(
							array(
								'page'    => 'aluleth-contacts',
								'contact' => (int) $contact->id,
							),
							admin_url( 'admin.php' )
						)
					) . '">' . $person . '</a>';
				}
				$until = $row->ended_at ? self::date( $row->ended_at ) : ( $row->expires_at ? self::date( $row->expires_at ) : ( 0 === strpos( $row->source_ref, 'sub:' ) || 0 === strpos( $row->source_ref, 'wcs:' ) ? __( 'Renews', 'aluleth-membership' ) : '—' ) );
				$badge = array(
					'active'  => '',
					'paused'  => __( 'Paused', 'aluleth-membership' ),
					'pending' => __( 'Waiting', 'aluleth-membership' ),
					'ended'   => __( 'Ended', 'aluleth-membership' ),
				);
				$state = isset( $badge[ $row->status ] ) ? $badge[ $row->status ] : $row->status;
				if ( 'active' === $row->status && $row->expires_at && strtotime( $row->expires_at . ' UTC' ) <= time() ) {
					$state = __( 'Ended', 'aluleth-membership' );
				}
				echo '<tr><td>' . $person . '</td><td>' . esc_html( $lv ? $lv['name'] : '#' . $row->level_id ) . ( '' !== $state ? ' <span class="al-badge">' . esc_html( $state ) . '</span>' : '' ) . '</td><td>' . esc_html( self::source_label( $row->source ) . self::ref_text( $row->source_ref ) ) . '</td><td>' . esc_html( self::date( $row->started_at ) ) . '</td><td>' . esc_html( $until ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $person is built with escaped parts.
				if ( 'ended' !== $row->status ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-alm-confirm="' . esc_attr__( 'End this access now? The person is told by email if the "Has ended" email is on.', 'aluleth-membership' ) . '">';
					wp_nonce_field( 'aluleth_membership_member_end' );
					echo '<input type="hidden" name="action" value="aluleth_membership_member_end"><input type="hidden" name="row" value="' . (int) $row->id . '"><button class="button-link button-link-delete">' . esc_html__( 'End access', 'aluleth-membership' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			$pages = (int) ceil( $result['total'] / 30 );
			if ( $pages > 1 ) {
				echo '<p class="alm-pages">';
				for ( $p = 1; $p <= $pages; $p++ ) {
					echo $p === $paged ? '<strong>' . (int) $p . '</strong> ' : '<a href="' . esc_url(
						self::url(
							array(
								'tab'    => 'members',
								'level'  => $level,
								'status' => $status,
								's'      => $search,
								'paged'  => $p,
							)
						)
					) . '">' . (int) $p . '</a> ';
				}
				echo '</p>';
			}
		}
		echo '</div>';

		echo '<aside><section class="al-card"><h2>' . esc_html__( 'Add a member', 'aluleth-membership' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aluleth_membership_member_add' );
		echo '<input type="hidden" name="action" value="aluleth_membership_member_add">';
		echo '<p class="alm-field"><label for="alm-m-email">' . esc_html__( 'Email', 'aluleth-membership' ) . '</label><input id="alm-m-email" type="email" name="email" required class="regular-text"></p>';
		echo '<p class="alm-field"><label for="alm-m-first">' . esc_html__( 'First name', 'aluleth-membership' ) . '</label><input id="alm-m-first" type="text" name="first_name" class="regular-text"></p>';
		echo '<p class="alm-field"><label for="alm-m-level">' . esc_html__( 'Level', 'aluleth-membership' ) . '</label><select id="alm-m-level" name="level">';
		foreach ( $levels as $id => $lv ) {
			echo '<option value="' . (int) $id . '"' . selected( $level, $id, false ) . '>' . esc_html( $lv['name'] ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="alm-field"><label for="alm-m-until">' . esc_html__( 'End date (optional)', 'aluleth-membership' ) . '</label><input id="alm-m-until" type="date" name="until"><span class="description">' . esc_html__( 'Empty: the length of the level.', 'aluleth-membership' ) . '</span></p>';
		echo '<p><label><input type="checkbox" name="notify" value="1" checked> ' . esc_html__( 'Send the welcome email', 'aluleth-membership' ) . '</label></p>';
		echo '<p><button class="al-button al-button--accent">' . esc_html__( 'Give the membership', 'aluleth-membership' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'An account is created when the email has none.', 'aluleth-membership' ) . '</p></form></section></aside></div>';
	}

	/**
	 * Local date of a UTC datetime.
	 *
	 * @param string $utc Datetime.
	 * @return string
	 */
	private static function date( $utc ) {
		return wp_date( get_option( 'date_format' ), strtotime( $utc . ' UTC' ) );
	}

	/**
	 * Adds a member by hand.
	 *
	 * @return void
	 */
	public static function do_member_add() {
		self::guard();
		check_admin_referer( 'aluleth_membership_member_add' );
		$email = isset( $_POST['email'] ) ? Util::email( sanitize_text_field( wp_unslash( $_POST['email'] ) ) ) : '';
		$first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$level = isset( $_POST['level'] ) ? absint( $_POST['level'] ) : 0;
		$until = isset( $_POST['until'] ) ? sanitize_text_field( wp_unslash( $_POST['until'] ) ) : '';
		$back  = array(
			'tab'   => 'members',
			'level' => $level,
		);
		if ( '' === $email ) {
			self::back( $back, 'no_email' );
		}
		if ( ! Levels::get( $level ) ) {
			self::back( $back, 'need_level' );
		}
		list( $user_id ) = Members::ensure_user( $email, $first );
		if ( ! $user_id ) {
			self::back( $back, 'no_user' );
		}
		$args = array( 'notify' => ! empty( $_POST['notify'] ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
			$local = date_create_immutable( $until . ' 23:59:59', wp_timezone() );
			if ( $local ) {
				$args['expires_at'] = gmdate( 'Y-m-d H:i:s', $local->getTimestamp() );
			}
		}
		Members::grant( $user_id, $level, 'manual', 'by:' . get_current_user_id(), $args );
		self::back( $back, 'added' );
	}

	/**
	 * Ends one access by hand.
	 *
	 * @return void
	 */
	public static function do_member_end() {
		self::guard();
		check_admin_referer( 'aluleth_membership_member_end' );
		$row = isset( $_POST['row'] ) ? absint( $_POST['row'] ) : 0;
		Members::end( $row, 'removed' );
		self::back( array( 'tab' => 'members' ), 'ended' );
	}

	/**
	 * Content access: rules and the join card.
	 *
	 * @return void
	 */
	private static function access() {
		$levels = Levels::all();
		$rules  = Rules::all();
		$s      = Settings::get();
		echo '<div class="alm-grid alm-grid--access"><div>';
		echo '<section class="al-card"><h2>' . esc_html__( 'What is for members', 'aluleth-membership' ) . '</h2>';
		echo '<p class="al-muted">' . esc_html__( 'A rule on a post itself wins (set it in the "Membership" box of the editor). Otherwise every matching rule applies, and any of their levels opens the content. Administrators and the authors of a post always see it.', 'aluleth-membership' ) . '</p>';
		if ( $rules ) {
			echo '<table class="widefat striped al-table alm-table"><thead><tr><th>' . esc_html__( 'What', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Who can read', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Others see', 'aluleth-membership' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'aluleth-membership' ) . '</span></th></tr></thead><tbody>';
			$modes = Editor::modes();
			foreach ( $rules as $rule ) {
				$edit = 'post' === $rule->kind && get_post( (int) $rule->target ) ? get_edit_post_link( (int) $rule->target ) : '';
				echo '<tr><td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( Rules::target_text( $rule ) ) . '</a>' : esc_html( Rules::target_text( $rule ) ) ) . '</td>';
				echo '<td>' . esc_html( 'open' === $rule->mode ? __( 'Everyone (exception)', 'aluleth-membership' ) : Rules::levels_text( $rule->level_ids ) ) . '</td>';
				$extras = array();
				if ( ! empty( $rule->drip_days ) ) {
					/* translators: %d: number of days. */
					$extras[] = sprintf( _n( 'opens %d day after joining', 'opens %d days after joining', (int) $rule->drip_days, 'aluleth-membership' ), (int) $rule->drip_days );
				}
				if ( ! empty( $rule->metered ) ) {
					$extras[] = __( 'counts in the free articles', 'aluleth-membership' );
				}

				/**
				 * Filters the notes shown under a rule in the Content access list.
				 *
				 * @param string[] $extras Notes.
				 * @param object   $rule   Rule.
				 */
				$extras = (array) apply_filters( 'aluleth_membership/rule_notes', $extras, $rule );
				echo '<td>' . esc_html( 'open' === $rule->mode ? '—' : ( isset( $modes[ $rule->mode ] ) ? $modes[ $rule->mode ] : $rule->mode ) ) . ( $extras ? '<br><span class="al-muted">' . esc_html( implode( ' · ', $extras ) ) . '</span>' : '' ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-alm-confirm="' . esc_attr__( 'Remove this rule? The content opens to everyone it covers.', 'aluleth-membership' ) . '">';
				wp_nonce_field( 'aluleth_membership_rule_delete' );
				echo '<input type="hidden" name="action" value="aluleth_membership_rule_delete"><input type="hidden" name="rule" value="' . (int) $rule->id . '"><button class="button-link button-link-delete">' . esc_html__( 'Remove', 'aluleth-membership' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<div class="al-empty"><p>' . esc_html__( 'Nothing is for members yet: everyone can read everything.', 'aluleth-membership' ) . '</p></div>';
		}

		echo '<h3>' . esc_html__( 'Add a rule', 'aluleth-membership' ) . '</h3>';
		if ( ! $levels ) {
			echo '<p class="al-muted">' . esc_html__( 'Create a level first.', 'aluleth-membership' ) . '</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alm-rule-form">';
			wp_nonce_field( 'aluleth_membership_rule_save' );
			echo '<input type="hidden" name="action" value="aluleth_membership_rule_save">';
			echo '<p class="alm-field"><label for="alm-r-target">' . esc_html__( 'What', 'aluleth-membership' ) . '</label><select id="alm-r-target" name="target" data-alm-target>';
			echo '<option value="">' . esc_html__( 'Choose…', 'aluleth-membership' ) . '</option>';
			echo '<optgroup label="' . esc_attr__( 'Everything of a type', 'aluleth-membership' ) . '">';
			foreach ( Editor::types() as $type ) {
				$obj = get_post_type_object( $type );
				/* translators: %s: content type name, plural. */
				echo '<option value="type|' . esc_attr( $type ) . '">' . esc_html( sprintf( __( 'All %s', 'aluleth-membership' ), $obj ? $obj->labels->name : $type ) ) . '</option>';
			}
			echo '</optgroup>';
			foreach ( get_taxonomies(
				array(
					'public'  => true,
					'show_ui' => true,
				),
				'objects'
			) as $tax ) {
				if ( 'post_format' === $tax->name ) {
					continue;
				}
				$terms = get_terms(
					array(
						'taxonomy'   => $tax->name,
						'hide_empty' => false,
						'number'     => 200,
					)
				);
				if ( is_wp_error( $terms ) || ! $terms ) {
					continue;
				}
				echo '<optgroup label="' . esc_attr( $tax->labels->name ) . '">';
				foreach ( $terms as $term ) {
					echo '<option value="term|' . esc_attr( $tax->name . ':' . $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
				}
				echo '</optgroup>';
			}
			echo '<optgroup label="' . esc_attr__( 'Other', 'aluleth-membership' ) . '"><option value="post|">' . esc_html__( 'One page or post…', 'aluleth-membership' ) . '</option><option value="url|">' . esc_html__( 'An address…', 'aluleth-membership' ) . '</option></optgroup></select></p>';
			echo '<p class="alm-field" data-alm-show="post" hidden><label for="alm-r-post">' . esc_html__( 'Page or post', 'aluleth-membership' ) . '</label>';
			echo '<select id="alm-r-post" name="post_id"><option value="">' . esc_html__( 'Choose…', 'aluleth-membership' ) . '</option>';
			foreach ( get_posts(
				array(
					'post_type'      => Editor::types(),
					'post_status'    => array( 'publish', 'private', 'draft', 'future' ),
					'posts_per_page' => 300,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			) as $item ) {
				$obj = get_post_type_object( $item->post_type );
				echo '<option value="' . (int) $item->ID . '">' . esc_html( ( '' !== $item->post_title ? $item->post_title : '#' . $item->ID ) . ' (' . ( $obj ? $obj->labels->singular_name : $item->post_type ) . ')' ) . '</option>';
			}
			echo '</select></p>';
			echo '<p class="alm-field" data-alm-show="url" hidden><label for="alm-r-url">' . esc_html__( 'Address', 'aluleth-membership' ) . '</label><input id="alm-r-url" type="text" name="url" class="regular-text" placeholder="/members/*"><span class="description">' . esc_html__( 'A path on this site. End with * for every address below it. Visitors without access are sent to the plans page.', 'aluleth-membership' ) . '</span></p>';
			echo '<fieldset class="alm-field"><legend>' . esc_html__( 'Levels that open it (none ticked: any member)', 'aluleth-membership' ) . '</legend><div class="alm-checklist">';
			foreach ( $levels as $id => $lv ) {
				echo '<label><input type="checkbox" name="levels[]" value="' . (int) $id . '"> ' . esc_html( $lv['name'] ) . '</label>';
			}
			echo '</div></fieldset>';
			echo '<p class="alm-field" data-alm-hide="url"><label for="alm-r-mode">' . esc_html__( 'Visitors without access see', 'aluleth-membership' ) . '</label><select id="alm-r-mode" name="mode">';
			foreach ( Editor::modes() as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
			echo '<p class="alm-field" data-alm-hide="url"><label for="alm-r-drip">' . esc_html__( 'Opens for members after (days)', 'aluleth-membership' ) . '</label><input id="alm-r-drip" type="number" min="0" max="3650" name="drip_days" value="0" class="small-text"><span class="description">' . esc_html__( '0: right away. 7: a week after they joined (content released step by step).', 'aluleth-membership' ) . '</span></p>';
			echo '<p data-alm-hide="url"><label><input type="checkbox" name="metered" value="1"> ' . esc_html__( 'Visitors can read it within their free articles of the month', 'aluleth-membership' ) . '</label></p>';

			/**
			 * Adds fields to a rule form (Membership Pro: content with an end).
			 * Name them {$prefix}[key]: they reach "aluleth_membership/rule_saved" in $extra['addon'].
			 *
			 * @param object|null $rule   Rule being edited (null: a new one).
			 * @param string      $prefix Field name prefix.
			 * @param string      $where  'access' (Content access screen) or 'editor' (post box).
			 */
			do_action( 'aluleth_membership/rule_fields', null, 'addon', 'access' );
			echo '<p><button class="al-button al-button--accent">' . esc_html__( 'Add the rule', 'aluleth-membership' ) . '</button></p></form>';
		}
		echo '</section>';
		echo '<section class="al-card"><h2>' . esc_html__( 'Also for members', 'aluleth-membership' ) . '</h2><ul class="alm-list">';
		echo '<li>' . esc_html__( 'Any block: "Who sees this block" in the block settings (Members, Everyone but members).', 'aluleth-membership' ) . '</li>';
		echo '<li>' . esc_html__( 'Elementor: Advanced › Membership on every widget, container, section and column.', 'aluleth-membership' ) . '</li>';
		echo '<li>' . esc_html__( 'Menus: "Membership: who sees this item" under every item in Appearance › Menus.', 'aluleth-membership' ) . '</li>';
		echo '<li>' . esc_html__( 'Bricks: Conditions › Aluleth Membership on every element (Member of, Can read this content).', 'aluleth-membership' ) . '</li>';
		echo '<li>' . esc_html__( 'Shortcode: [aluleth_members levels="club"]Members only[/aluleth_members], or show="guests" for the opposite.', 'aluleth-membership' ) . '</li>';
		echo '<li>' . esc_html__( 'Members-only text never reaches visitors: not in the page, the feed or the REST API. Search engines see the beginning only.', 'aluleth-membership' ) . '</li>';
		echo '</ul></section>';
		self::files( $levels );
		echo '</div>';

		echo '<aside><section class="al-card"><h2>' . esc_html__( 'Join card', 'aluleth-membership' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aluleth_membership_card_save' );
		echo '<input type="hidden" name="action" value="aluleth_membership_card_save">';
		echo '<fieldset class="alm-field"><legend>' . esc_html__( 'Design', 'aluleth-membership' ) . '</legend><div class="alm-designs">';
		foreach (
			array(
				'clean' => __( 'Clean', 'aluleth-membership' ),
				'soft'  => __( 'Soft', 'aluleth-membership' ),
				'dark'  => __( 'Dark', 'aluleth-membership' ),
			) as $key => $label
		) {
			echo '<label class="alm-design alm-design--' . esc_attr( $key ) . '"><input type="radio" name="card[design]" value="' . esc_attr( $key ) . '"' . checked( $s['design'], $key, false ) . '><span class="alm-design__swatch" aria-hidden="true"></span>' . esc_html( $label ) . '</label>';
		}
		echo '</div></fieldset>';
		echo '<p class="alm-field"><label for="alm-accent">' . esc_html__( 'Button colour', 'aluleth-membership' ) . '</label><input id="alm-accent" type="color" name="card[accent]" value="' . esc_attr( $s['accent'] ) . '"></p>';
		echo '<p class="alm-field"><label for="alm-ctitle">' . esc_html__( 'Title', 'aluleth-membership' ) . '</label><input id="alm-ctitle" type="text" name="card[card_title]" class="regular-text" value="' . esc_attr( $s['card_title'] ) . '" placeholder="' . esc_attr__( 'This is for members', 'aluleth-membership' ) . '"></p>';
		echo '<p class="alm-field"><label for="alm-ctext">' . esc_html__( 'Text', 'aluleth-membership' ) . '</label><textarea id="alm-ctext" name="card[card_text]" rows="2" class="large-text" placeholder="' . esc_attr__( 'Join to read the rest. It only takes a minute.', 'aluleth-membership' ) . '">' . esc_textarea( $s['card_text'] ) . '</textarea></p>';
		echo '<p class="alm-field"><label for="alm-words">' . esc_html__( 'Beginning shown to visitors (words)', 'aluleth-membership' ) . '</label><input id="alm-words" type="number" min="0" max="1000" name="card[teaser_words]" value="' . (int) $s['teaser_words'] . '" class="small-text"><span class="description">' . esc_html__( 'Whole paragraphs are kept. A "More" block in a post sets its own cut.', 'aluleth-membership' ) . '</span></p>';
		echo '<p class="alm-field"><label for="alm-meter">' . esc_html__( 'Free members-only articles a month', 'aluleth-membership' ) . '</label><input id="alm-meter" type="number" min="0" max="50" name="card[meter_count]" value="' . (int) $s['meter_count'] . '" class="small-text"><span class="description">' . esc_html__( 'For visitors and people without access, on the content whose rule allows it. Search engines never get free articles.', 'aluleth-membership' ) . '</span></p>';
		echo '<p><label><input type="checkbox" name="card[card_login]" value="1"' . checked( $s['card_login'], true, false ) . '> ' . esc_html__( 'Show "Get a sign-in link" for members', 'aluleth-membership' ) . '</label><br>';
		echo '<label><input type="checkbox" name="card[hide_comments]" value="1"' . checked( $s['hide_comments'], true, false ) . '> ' . esc_html__( 'Hide comments from visitors without access', 'aluleth-membership' ) . '</label></p>';
		echo '<p><button class="al-button al-button--accent">' . esc_html__( 'Save', 'aluleth-membership' ) . '</button></p></form>';
		$card = Join_Card::render(
			array_keys( $levels ),
			array(
				'redirect' => home_url( '/' ),
				'preview'  => true,
			)
		);
		echo '<div class="alm-preview">' . $card . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped parts.
		echo '</section></aside></div>';
	}

	/**
	 * Files for members.
	 *
	 * @param array $levels Levels.
	 * @return void
	 */
	private static function files( array $levels ) {
		$files = Files::all();
		echo '<section class="al-card" id="alm-files"><h2>' . esc_html__( 'Files for members', 'aluleth-membership' ) . '</h2>';
		echo '<p class="al-muted">' . esc_html__( 'Guides, templates, recordings: members download them from their member area or from a link in your content. The files are kept out of the media library and only open through this site, after the access check.', 'aluleth-membership' ) . '</p>';
		if ( $files ) {
			echo '<table class="widefat striped al-table alm-table"><thead><tr><th>' . esc_html__( 'File', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Who can download', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Downloads', 'aluleth-membership' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'aluleth-membership' ) . '</span></th></tr></thead><tbody>';
			foreach ( $files as $file ) {
				echo '<tr><td><strong>' . esc_html( $file['name'] ) . '</strong><br><span class="al-muted">' . esc_html( $file['filename'] . ' · ' . size_format( $file['size'] ) ) . '</span><br><code>[aluleth_file id="' . (int) $file['id'] . '"]</code></td>';
				echo '<td>' . esc_html( Rules::levels_text( $file['levels'] ) ) . '</td><td>' . (int) $file['downloads'] . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-alm-confirm="' . esc_attr__( 'Delete this file? Members can no longer download it.', 'aluleth-membership' ) . '">';
				wp_nonce_field( 'aluleth_membership_file_delete' );
				echo '<input type="hidden" name="action" value="aluleth_membership_file_delete"><input type="hidden" name="file" value="' . (int) $file['id'] . '"><button class="button-link button-link-delete">' . esc_html__( 'Delete', 'aluleth-membership' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! $levels ) {
			echo '<p class="al-muted">' . esc_html__( 'Create a level first.', 'aluleth-membership' ) . '</p></section>';
			return;
		}
		echo '<h3>' . esc_html__( 'Add a file', 'aluleth-membership' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="alm-rule-form">';
		wp_nonce_field( 'aluleth_membership_file_add' );
		echo '<input type="hidden" name="action" value="aluleth_membership_file_add">';
		/* translators: %s: maximum upload size, like 64 MB. */
		echo '<p class="alm-field"><label for="alm-f-file">' . esc_html__( 'File', 'aluleth-membership' ) . '</label><input id="alm-f-file" type="file" name="alm_file" required><span class="description">' . esc_html( sprintf( __( 'Up to %s.', 'aluleth-membership' ), size_format( wp_max_upload_size() ) ) ) . '</span></p>';
		echo '<p class="alm-field"><label for="alm-f-name">' . esc_html__( 'Name shown to members', 'aluleth-membership' ) . '</label><input id="alm-f-name" type="text" name="name" class="regular-text" placeholder="' . esc_attr__( 'The file name if empty', 'aluleth-membership' ) . '"></p>';
		echo '<fieldset class="alm-field"><legend>' . esc_html__( 'Levels that can download it (none ticked: any member)', 'aluleth-membership' ) . '</legend><div class="alm-checklist">';
		foreach ( $levels as $id => $lv ) {
			echo '<label><input type="checkbox" name="levels[]" value="' . (int) $id . '"> ' . esc_html( $lv['name'] ) . '</label>';
		}
		echo '</div></fieldset>';
		echo '<p><button class="al-button al-button--accent">' . esc_html__( 'Add the file', 'aluleth-membership' ) . '</button></p></form></section>';
	}

	/**
	 * Adds a file.
	 *
	 * @return void
	 */
	public static function do_file_add() {
		self::guard();
		check_admin_referer( 'aluleth_membership_file_add' );
		if ( ! current_user_can( 'upload_files' ) || empty( $_FILES['alm_file'] ) || ! is_array( $_FILES['alm_file'] ) ) {
			self::back( array( 'tab' => 'access' ), 'no_file' );
		}
		$upload = $_FILES['alm_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by wp_handle_upload().
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$levels = isset( $_POST['levels'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['levels'] ) ) : array();
		$id     = Files::add( $upload, $name, $levels ? $levels : 'any' );
		self::back( array( 'tab' => 'access' ), is_wp_error( $id ) ? 'no_file' : 'file' );
	}

	/**
	 * Deletes a file.
	 *
	 * @return void
	 */
	public static function do_file_delete() {
		self::guard();
		check_admin_referer( 'aluleth_membership_file_delete' );
		Files::delete( isset( $_POST['file'] ) ? absint( $_POST['file'] ) : 0 );
		self::back( array( 'tab' => 'access' ), 'deleted' );
	}

	/**
	 * Saves a rule.
	 *
	 * @return void
	 */
	public static function do_rule_save() {
		self::guard();
		check_admin_referer( 'aluleth_membership_rule_save' );
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$parts  = explode( '|', $target, 2 );
		$kind   = sanitize_key( $parts[0] );
		$value  = isset( $parts[1] ) ? $parts[1] : '';
		if ( 'post' === $kind ) {
			$value = isset( $_POST['post_id'] ) ? (string) absint( $_POST['post_id'] ) : '';
		} elseif ( 'url' === $kind ) {
			$value = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		}
		$levels = isset( $_POST['levels'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['levels'] ) ) : array();
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'teaser';
		$extra  = array(
			'drip_days' => isset( $_POST['drip_days'] ) ? absint( $_POST['drip_days'] ) : 0,
			'metered'   => ! empty( $_POST['metered'] ),
			'addon'     => isset( $_POST['addon'] ) && is_array( $_POST['addon'] ) ? map_deep( wp_unslash( $_POST['addon'] ), 'sanitize_text_field' ) : array(),
		);
		$id     = in_array( $kind, array( 'post', 'term', 'type', 'url' ), true ) ? Rules::save( $kind, $value, $levels ? $levels : 'any', $mode, $extra ) : 0;
		self::back( array( 'tab' => 'access' ), $id ? 'rule' : 'bad_rule' );
	}

	/**
	 * Deletes a rule.
	 *
	 * @return void
	 */
	public static function do_rule_delete() {
		self::guard();
		check_admin_referer( 'aluleth_membership_rule_delete' );
		Rules::delete( isset( $_POST['rule'] ) ? absint( $_POST['rule'] ) : 0 );
		self::back( array( 'tab' => 'access' ), 'deleted' );
	}

	/**
	 * Saves the join card settings.
	 *
	 * @return void
	 */
	public static function do_card_save() {
		self::guard();
		check_admin_referer( 'aluleth_membership_card_save' );
		$raw = isset( $_POST['card'] ) && is_array( $_POST['card'] ) ? map_deep( wp_unslash( $_POST['card'] ), 'sanitize_textarea_field' ) : array();
		Settings::save_part( $raw, array( 'design', 'accent', 'card_title', 'card_text', 'teaser_words', 'card_login', 'hide_comments', 'meter_count' ) );
		self::back( array( 'tab' => 'access' ), 'saved' );
	}

	/**
	 * Emails.
	 *
	 * @return void
	 */
	private static function emails() {
		$all = Emails::get();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alm-emails">';
		wp_nonce_field( 'aluleth_membership_emails_save' );
		echo '<input type="hidden" name="action" value="aluleth_membership_emails_save">';
		echo '<p class="al-muted">' . esc_html__( 'Words in braces are replaced: {first_name}, {name}, {email}, {level}, {site}, {member_area}, {end_date}. A link alone on its line becomes a button: {sign_in_link}, {confirm_link}, {join_link}.', 'aluleth-membership' ) . '</p>';
		foreach ( Emails::catalog() as $key => $email ) {
			$mine = $all[ $key ];
			echo '<section class="al-card"><div class="alm-email-head"><h2>' . esc_html( $email['label'] ) . '</h2>';
			if ( $email['optional'] ) {
				echo '<label class="al-switch"><input type="checkbox" name="emails[' . esc_attr( $key ) . '][on]" value="1"' . checked( $mine['on'], true, false ) . '> ' . esc_html__( 'On', 'aluleth-membership' ) . '</label>';
			}
			echo '</div><p class="description">' . esc_html( $email['when'] ) . '</p>';
			echo '<p class="alm-field"><label for="alm-e-' . esc_attr( $key ) . '-s">' . esc_html__( 'Subject', 'aluleth-membership' ) . '</label><input id="alm-e-' . esc_attr( $key ) . '-s" type="text" class="large-text" name="emails[' . esc_attr( $key ) . '][subject]" value="' . esc_attr( $mine['subject'] ) . '"></p>';
			echo '<p class="alm-field"><label for="alm-e-' . esc_attr( $key ) . '-b">' . esc_html__( 'Text', 'aluleth-membership' ) . '</label><textarea id="alm-e-' . esc_attr( $key ) . '-b" class="large-text" rows="8" name="emails[' . esc_attr( $key ) . '][body]">' . esc_textarea( $mine['body'] ) . '</textarea></p>';
			echo '<p><button type="submit" name="test" value="' . esc_attr( $key ) . '" class="button">' . esc_html__( 'Save and send me a test', 'aluleth-membership' ) . '</button></p></section>';
		}
		echo '<p><button type="submit" class="al-button al-button--accent">' . esc_html__( 'Save the emails', 'aluleth-membership' ) . '</button></p></form>';
	}

	/**
	 * Saves the emails (and sends a test when asked).
	 *
	 * @return void
	 */
	public static function do_emails_save() {
		self::guard();
		check_admin_referer( 'aluleth_membership_emails_save' );
		$raw = isset( $_POST['emails'] ) && is_array( $_POST['emails'] ) ? map_deep( wp_unslash( $_POST['emails'] ), 'sanitize_textarea_field' ) : array();
		Emails::save( $raw );
		$test = isset( $_POST['test'] ) ? sanitize_key( wp_unslash( $_POST['test'] ) ) : '';
		if ( '' !== $test ) {
			self::back( array( 'tab' => 'emails' ), self::send_test( $test ) ? 'sent' : 'not_sent' );
		}
		self::back( array( 'tab' => 'emails' ), 'saved' );
	}

	/**
	 * Sends one email to the current user with example values.
	 *
	 * @param string $key Email key.
	 * @return bool
	 */
	private static function send_test( $key ) {
		$user   = wp_get_current_user();
		$levels = Levels::all();
		$level  = $levels ? reset( $levels ) : array_merge(
			Levels::defaults(),
			array(
				'id'   => 0,
				'name' => __( 'Club', 'aluleth-membership' ),
			)
		);
		$demo   = array( Settings::member_url(), __( 'Example button (test email)', 'aluleth-membership' ) );
		return Emails::send(
			$key,
			$user,
			array(
				'{level}'        => $level['name'],
				'{end_date}'     => wp_date( get_option( 'date_format' ), time() + 7 * DAY_IN_SECONDS ),
				'{sign_in_link}' => $demo,
				'{confirm_link}' => $demo,
				'{join_link}'    => $demo,
			)
		);
	}

	/**
	 * Import from another membership plugin.
	 *
	 * @return void
	 */
	private static function import() {
		$found = Import::detect();
		echo '<div class="alm-grid alm-grid--access alm-import"><div>';
		if ( ! $found ) {
			echo '<section class="al-card"><h2>' . esc_html__( 'Import from another membership plugin', 'aluleth-membership' ) . '</h2>';
			echo '<div class="al-empty"><p>' . esc_html__( 'Nothing to import: no Paid Memberships Pro, Restrict Content (Kadence Memberships) or Simple Membership data was found on this site.', 'aluleth-membership' ) . '</p><p class="al-muted">' . esc_html__( 'Their data is read from their own tables, so the import also works after you deactivate them.', 'aluleth-membership' ) . '</p></div></section></div></div>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choice of screen.
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$source = isset( $found[ $source ] ) ? $source : (string) key( $found );
		$name   = $found[ $source ];
		$levels = Import::levels( $source );
		$map    = Import::default_map( $source );
		$conf   = Import::conf( $source );
		$sum    = Import::summary( $source );
		$ours   = Levels::all();
		$active = Import::source_active( $source );

		if ( count( $found ) > 1 ) {
			echo '<nav class="alm-pills" aria-label="' . esc_attr__( 'Import from', 'aluleth-membership' ) . '">';
			foreach ( $found as $key => $label ) {
				echo '<a href="' . esc_url(
					self::url(
						array(
							'tab'    => 'import',
							'source' => $key,
						)
					)
				) . '"' . ( $key === $source ? ' class="is-current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}

		echo '<section class="al-card">';
		/* translators: %s: plugin name. */
		echo '<h2>' . esc_html( sprintf( __( 'Import from %s', 'aluleth-membership' ), $name ) ) . '</h2>';
		/* translators: %s: plugin name. */
		echo '<p class="al-muted">' . esc_html( sprintf( __( 'Levels, the members who have access today and the content rules. Nothing is changed or deleted in %s, and no email is sent to members. You can run it again: what is already here is not imported twice.', 'aluleth-membership' ), $name ) ) . '</p>';
		echo '<p class="alm-import__state' . ( $active ? ' is-on' : '' ) . '">' . esc_html(
			$active
				/* translators: %s: plugin name. */
				? sprintf( __( '%s is active: payments and renewals keep happening there.', 'aluleth-membership' ), $name )
				/* translators: %s: plugin name. */
				: sprintf( __( '%s is not active: its data is read from the database.', 'aluleth-membership' ), $name )
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alm-import__form">';
		wp_nonce_field( 'aluleth_membership_import_run' );
		echo '<input type="hidden" name="action" value="aluleth_membership_import_run"><input type="hidden" name="source" value="' . esc_attr( $source ) . '">';
		echo '<h3>' . esc_html__( '1. Levels', 'aluleth-membership' ) . '</h3>';
		echo '<table class="widefat striped al-table alm-table"><thead><tr><th>' . esc_html( $name ) . '</th><th>' . esc_html__( 'Members today', 'aluleth-membership' ) . '</th><th>' . esc_html__( 'Becomes', 'aluleth-membership' ) . '</th></tr></thead><tbody>';
		foreach ( $levels as $id => $level ) {
			$facts = array_filter( array( $level['price'], Levels::duration_text( array_merge( Levels::defaults(), $level ) ) ) );
			echo '<tr><td><strong>' . esc_html( $level['name'] ) . '</strong>' . ( $facts ? '<br><span class="al-muted">' . esc_html( implode( ' · ', $facts ) ) . '</span>' : '' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Members today', 'aluleth-membership' ) . '">' . (int) ( isset( $sum['per_level'][ $id ] ) ? $sum['per_level'][ $id ] : 0 ) . '</td><td>';
			echo '<select name="map[' . (int) $id . ']" aria-label="' . esc_attr( $level['name'] ) . '">';
			/* translators: %s: level name. */
			echo '<option value="new"' . selected( $map[ $id ], 'new', false ) . '>' . esc_html( sprintf( __( 'Create "%s"', 'aluleth-membership' ), $level['name'] ) ) . '</option>';
			if ( $ours ) {
				echo '<optgroup label="' . esc_attr__( 'An existing level', 'aluleth-membership' ) . '">';
				foreach ( $ours as $our_id => $our ) {
					echo '<option value="' . (int) $our_id . '"' . selected( $map[ $id ], $our_id, false ) . '>' . esc_html( $our['name'] ) . '</option>';
				}
				echo '</optgroup>';
			}
			echo '<option value="0"' . selected( $map[ $id ], 0, false ) . '>' . esc_html__( 'Don\'t import', 'aluleth-membership' ) . '</option>';
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( '2. What comes over', 'aluleth-membership' ) . '</h3><div class="alm-import__what">';
		/* translators: %d: number of members. */
		echo '<label class="al-checkcard"><input type="checkbox" name="members" value="1" checked> <span><strong>' . esc_html( sprintf( _n( '%d member with access today', '%d members with access today', $sum['members'], 'aluleth-membership' ), $sum['members'] ) ) . '</strong><small>' . esc_html__( 'Their WordPress accounts are kept, with their start and end dates. People without an account get one and sign in with an email link.', 'aluleth-membership' ) . '</small></span></label>';
		/* translators: %d: number of rules. */
		echo '<label class="al-checkcard"><input type="checkbox" name="rules" value="1" checked> <span><strong>' . esc_html( sprintf( _n( '%d content rule', '%d content rules', $sum['rules'], 'aluleth-membership' ), $sum['rules'] ) ) . '</strong><small>' . esc_html__( 'Pages, posts, categories and content types for members. Content that already has an Aluleth rule keeps it.', 'aluleth-membership' ) . '</small></span></label>';
		/* translators: %s: plugin name. */
		echo '<label class="al-checkcard"><input type="checkbox" name="follow" value="1"' . checked( $conf['follow'] || ! $conf['last'], true, false ) . '> <span><strong>' . esc_html( sprintf( __( 'Keep following %s', 'aluleth-membership' ), $name ) ) . '</strong><small>' . esc_html(
			/* translators: %s: plugin name. */
			sprintf( __( 'Every hour while %1$s is active: new members get their level here, and renewals and ends follow. Aluleth sends no member emails for these memberships: %1$s keeps writing to them.', 'aluleth-membership' ), $name )
		) . '</small></span></label>';
		echo '<label class="al-checkcard"><input type="checkbox" name="test" value="1"> <span><strong>' . esc_html__( 'Test with the first 10 members', 'aluleth-membership' ) . '</strong><small>' . esc_html__( 'Check them in Members, then run the import again for everyone.', 'aluleth-membership' ) . '</small></span></label>';
		echo '</div>';
		if ( ! empty( $sum['skipped']['role'] ) ) {
			/* translators: %d: number of rules. */
			echo '<p class="al-help">' . esc_html( sprintf( _n( '%d restriction by role or access level is not imported: Aluleth opens content by membership level.', '%d restrictions by role or access level are not imported: Aluleth opens content by membership level.', $sum['skipped']['role'], 'aluleth-membership' ), $sum['skipped']['role'] ) ) . '</p>';
		}
		if ( ! empty( $sum['skipped']['no_level'] ) ) {
			/* translators: %d: number of items. */
			echo '<p class="al-help">' . esc_html( sprintf( _n( '%d protected item is open to no level and is not imported.', '%d protected items are open to no level and are not imported.', $sum['skipped']['no_level'], 'aluleth-membership' ), $sum['skipped']['no_level'] ) ) . '</p>';
		}
		/* translators: %s: plugin name. */
		echo '<p class="al-help">' . esc_html( sprintf( __( 'Imported levels are not shown on join cards. When you are ready to stop selling with %s, choose how people join each level (FluentCart, WooCommerce, free sign-up) and show it on the cards.', 'aluleth-membership' ), $name ) ) . '</p>';
		echo '<p><button class="al-button al-button--accent">' . esc_html__( 'Import', 'aluleth-membership' ) . '</button></p></form></section></div>';

		echo '<aside>';
		$last = $conf['last'];
		if ( $last ) {
			echo '<section class="al-card"><h2>' . esc_html( ! empty( $last['test'] ) ? __( 'Last test import', 'aluleth-membership' ) : __( 'Last import', 'aluleth-membership' ) ) . '</h2><p class="al-muted">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ) ) . '</p><ul class="alm-result">';
			$lines = array(
				/* translators: %d: number. */
				array( $last['levels_new'], _n( '%d level created', '%d levels created', (int) $last['levels_new'], 'aluleth-membership' ) ),
				/* translators: %d: number. */
				array( $last['levels_mapped'], _n( '%d level joined to an existing one', '%d levels joined to existing ones', (int) $last['levels_mapped'], 'aluleth-membership' ) ),
				/* translators: %d: number. */
				array( $last['members'], _n( '%d member imported', '%d members imported', (int) $last['members'], 'aluleth-membership' ) ),
				/* translators: %d: number. */
				array( $last['already'] + $last['updated'], _n( '%d already here', '%d already here', (int) ( $last['already'] + $last['updated'] ), 'aluleth-membership' ) ),
				/* translators: %d: number. */
				array( $last['rules'], _n( '%d content rule added', '%d content rules added', (int) $last['rules'], 'aluleth-membership' ) ),
				/* translators: %d: number. */
				array( $last['rules_kept'], _n( '%d content already had a rule', '%d contents already had a rule', (int) $last['rules_kept'], 'aluleth-membership' ) ),
				/* translators: %d: number. */
				array( $last['no_level'], _n( '%d member of a level not imported', '%d members of levels not imported', (int) $last['no_level'], 'aluleth-membership' ) ),
				/* translators: %d: number. */
				array( $last['no_account'], _n( '%d member without a valid email', '%d members without a valid email', (int) $last['no_account'], 'aluleth-membership' ) ),
			);
			foreach ( $lines as $line ) {
				if ( $line[0] ) {
					echo '<li>' . esc_html( sprintf( $line[1], (int) $line[0] ) ) . '</li>';
				}
			}
			echo '</ul>';
			if ( ! empty( $last['left'] ) ) {
				/* translators: %d: number of members. */
				echo '<p class="alm-result__left">' . esc_html( sprintf( _n( '%d member left to import: run the import again.', '%d members left to import: run the import again.', (int) $last['left'], 'aluleth-membership' ), (int) $last['left'] ) ) . '</p>';
			}
			echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'members' ) ) ) . '">' . esc_html__( 'See the members', 'aluleth-membership' ) . '</a></p></section>';
		}
		if ( $conf['follow'] && $conf['last'] ) {
			echo '<section class="al-card"><h2>' . esc_html__( 'Following', 'aluleth-membership' ) . '</h2><p class="al-muted">' . esc_html(
				$active
					/* translators: %s: plugin name. */
					? sprintf( __( 'Checked every hour while %s is active.', 'aluleth-membership' ), $name )
					/* translators: %s: plugin name. */
					: sprintf( __( '%s is not active: nothing to follow. Imported memberships keep their end dates.', 'aluleth-membership' ), $name )
			) . '</p>';
			if ( $active ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'aluleth_membership_import_follow' );
				echo '<input type="hidden" name="action" value="aluleth_membership_import_follow"><input type="hidden" name="source" value="' . esc_attr( $source ) . '"><button class="button">' . esc_html__( 'Check now', 'aluleth-membership' ) . '</button></form>';
			}
			echo '</section>';
		}

		echo '<section class="al-card"><h2>' . esc_html__( 'Check one member', 'aluleth-membership' ) . '</h2><p class="al-muted">' . esc_html__( 'What a person has today and what the import gives them.', 'aluleth-membership' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alm-inline">';
		wp_nonce_field( 'aluleth_membership_import_check' );
		echo '<input type="hidden" name="action" value="aluleth_membership_import_check"><input type="hidden" name="source" value="' . esc_attr( $source ) . '">';
		echo '<label class="screen-reader-text" for="alm-ic-email">' . esc_html__( 'Email', 'aluleth-membership' ) . '</label><input id="alm-ic-email" type="email" name="email" required placeholder="name@example.com"> <button class="button">' . esc_html__( 'Check', 'aluleth-membership' ) . '</button></form>';
		$check = get_transient( 'aluleth_membership_import_check_' . get_current_user_id() );
		if ( is_array( $check ) && $check['source'] === $source ) {
			delete_transient( 'aluleth_membership_import_check_' . get_current_user_id() );
			echo '<div class="alm-check"><p><strong>' . esc_html( $check['email'] ) . '</strong></p>';
			if ( ! $check['rows'] ) {
				/* translators: %s: plugin name. */
				echo '<p>' . esc_html( sprintf( __( 'No membership in %s for this email.', 'aluleth-membership' ), $name ) ) . '</p>';
			}
			echo '<ul class="alm-result">';
			foreach ( $check['rows'] as $row ) {
				$until = $row['end'] ? sprintf(
					/* translators: %s: date. */
					__( 'until %s', 'aluleth-membership' ),
					self::date( $row['end'] )
				) : __( 'no end date', 'aluleth-membership' );
				if ( ! $row['active'] ) {
					/* translators: 1: level, 2: status in the other plugin. */
					$text = sprintf( __( '%1$s: %2$s, not imported', 'aluleth-membership' ), $row['level'], $row['status'] );
				} elseif ( $row['done'] ) {
					/* translators: 1: level, 2: our level. */
					$text = sprintf( __( '%1$s: already imported as %2$s', 'aluleth-membership' ), $row['level'], $row['to'] );
				} elseif ( 'new' === $row['to'] ) {
					/* translators: 1: level name, 2: the end of the access, for example until a date. */
					$text = sprintf( __( '%1$s: gets the new level of the same name, %2$s', 'aluleth-membership' ), $row['level'], $until );
				} elseif ( '' !== $row['to'] ) {
					/* translators: 1: level, 2: our level, 3: "until …" or "no end date". */
					$text = sprintf( __( '%1$s: gets %2$s, %3$s', 'aluleth-membership' ), $row['level'], $row['to'], $until );
				} else {
					/* translators: %s: level. */
					$text = sprintf( __( '%s: level not imported', 'aluleth-membership' ), $row['level'] );
				}
				echo '<li>' . esc_html( $text ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '</section></aside></div>';
	}

	/**
	 * Runs an import.
	 *
	 * @return void
	 */
	public static function do_import_run() {
		self::guard();
		check_admin_referer( 'aluleth_membership_import_run' );
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$map    = array();
		if ( isset( $_POST['map'] ) && is_array( $_POST['map'] ) ) {
			foreach ( map_deep( wp_unslash( $_POST['map'] ), 'sanitize_key' ) as $id => $pick ) {
				$map[ absint( $id ) ] = 'new' === $pick ? 'new' : absint( $pick );
			}
		}
		$result = Import::run(
			$source,
			array(
				'map'     => $map,
				'members' => ! empty( $_POST['members'] ),
				'rules'   => ! empty( $_POST['rules'] ),
				'follow'  => ! empty( $_POST['follow'] ),
				'test'    => ! empty( $_POST['test'] ),
			)
		);
		self::back(
			array(
				'tab'    => 'import',
				'source' => $source,
			),
			is_wp_error( $result ) ? 'no_import' : 'imported'
		);
	}

	/**
	 * "Check one member".
	 *
	 * @return void
	 */
	public static function do_import_check() {
		self::guard();
		check_admin_referer( 'aluleth_membership_import_check' );
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( isset( Import::sources()[ $source ] ) && is_email( $email ) ) {
			set_transient(
				'aluleth_membership_import_check_' . get_current_user_id(),
				array(
					'source' => $source,
					'email'  => $email,
					'rows'   => Import::check( $source, $email ),
				),
				10 * MINUTE_IN_SECONDS
			);
		}
		wp_safe_redirect(
			self::url(
				array(
					'tab'    => 'import',
					'source' => $source,
				)
			)
		);
		exit;
	}

	/**
	 * "Check now" for a followed plugin.
	 *
	 * @return void
	 */
	public static function do_import_follow() {
		self::guard();
		check_admin_referer( 'aluleth_membership_import_follow' );
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		if ( isset( Import::sources()[ $source ] ) && Import::source_active( $source ) ) {
			Import::follow( $source );
		}
		self::back(
			array(
				'tab'    => 'import',
				'source' => $source,
			),
			'followed'
		);
	}

	/**
	 * Settings: pages and accounts.
	 *
	 * @return void
	 */
	private static function settings() {
		$s = Settings::get();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alm-settings">';
		wp_nonce_field( 'aluleth_membership_settings_save' );
		echo '<input type="hidden" name="action" value="aluleth_membership_settings_save">';
		echo '<section class="al-card"><h2>' . esc_html__( 'Pages', 'aluleth-membership' ) . '</h2>';
		foreach (
			array(
				'member_page' => array( __( 'Member area', 'aluleth-membership' ), __( 'Where members see their membership, billing and content. Sign-in links open it. It holds the "Member area" block, widget or the [aluleth_member_area] shortcode.', 'aluleth-membership' ) ),
				'join_page'   => array( __( 'Plans page', 'aluleth-membership' ), __( 'Where visitors are sent by address rules and "Sent to the plans page". It holds a "Join card" block, widget or the [aluleth_join_card] shortcode.', 'aluleth-membership' ) ),
			) as $key => $info
		) {
			$page = (int) $s[ $key ];
			echo '<div class="alm-field alm-page-pick"><label for="alm-' . esc_attr( $key ) . '">' . esc_html( $info[0] ) . '</label><span class="alm-inline">';
			wp_dropdown_pages(
				array(
					'name'              => 'settings[' . esc_attr( $key ) . ']',
					'id'                => 'alm-' . esc_attr( $key ),
					'selected'          => (int) $page,
					'show_option_none'  => esc_html__( 'None', 'aluleth-membership' ),
					'option_none_value' => '0',
					'post_status'       => array( 'publish', 'draft', 'private' ),
				)
			);
			if ( $page && get_post( $page ) ) {
				echo ' <a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'aluleth-membership' ) . '</a>';
			}
			echo ' <button type="submit" class="button" name="create" value="' . esc_attr( $key ) . '">' . esc_html__( 'Create the page', 'aluleth-membership' ) . '</button></span><span class="description">' . esc_html( $info[1] ) . '</span></div>';
		}
		echo '</section>';
		echo '<section class="al-card"><h2>' . esc_html__( 'Accounts', 'aluleth-membership' ) . '</h2>';
		echo '<p class="alm-field"><label for="alm-role">' . esc_html__( 'Role of new accounts', 'aluleth-membership' ) . '</label><select id="alm-role" name="settings[role]">';
		foreach ( wp_roles()->get_names() as $role => $name ) {
			// Only low-privilege roles (nothing beyond reading) can be given to new accounts.
			if ( ! Settings::low_role( $role ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( $role ) . '"' . selected( Settings::member_role(), $role, false ) . '>' . esc_html( translate_user_role( $name ) ) . '</option>';
		}
		echo '</select><span class="description">' . esc_html__( 'Accounts made for buyers, sign-ups and forms when the email has none. Only roles that can do nothing more than read are offered.', 'aluleth-membership' ) . '</span></p>';
		echo '<p><label><input type="checkbox" name="settings[hide_admin_bar]" value="1"' . checked( $s['hide_admin_bar'], true, false ) . '> ' . esc_html__( 'Hide the WordPress toolbar from members on the site', 'aluleth-membership' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Members sign in with a link sent by email (it works once, for 30 minutes). Administrators and editors always use their password.', 'aluleth-membership' ) . '</p>';
		echo '</section><p><button type="submit" class="al-button al-button--accent">' . esc_html__( 'Save', 'aluleth-membership' ) . '</button></p></form>';
	}

	/**
	 * Saves the settings.
	 *
	 * @return void
	 */
	public static function do_settings_save() {
		self::guard();
		check_admin_referer( 'aluleth_membership_settings_save' );
		if ( ! empty( $_POST['create'] ) ) {
			self::create_page();
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();
		Settings::save_part( $raw, array( 'member_page', 'join_page', 'role', 'hide_admin_bar' ) );
		self::back( array( 'tab' => 'settings' ), 'saved' );
	}

	/**
	 * Creates the member area or plans page (and saves the other settings).
	 *
	 * @return void
	 */
	private static function create_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in do_settings_save().
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();
		$key = isset( $_POST['create'] ) ? sanitize_key( wp_unslash( $_POST['create'] ) ) : '';
		if ( 'member_page' === $key || 'join_page' === $key ) {
			$page = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'member_page' === $key ? __( 'Member area', 'aluleth-membership' ) : __( 'Memberships', 'aluleth-membership' ),
					'post_content' => 'member_page' === $key ? '<!-- wp:aluleth-membership/member-area /-->' : '<!-- wp:aluleth-membership/join-card /-->',
				)
			);
			if ( $page && ! is_wp_error( $page ) ) {
				$raw[ $key ] = (int) $page;
			}
		}
		// phpcs:enable
		Settings::save_part( $raw, array( 'member_page', 'join_page', 'role', 'hide_admin_bar' ) );
		self::back( array( 'tab' => 'settings' ), 'page' );
	}
}
