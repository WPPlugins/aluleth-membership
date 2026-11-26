<?php
/**
 * AI agents: abilities of Aluleth Membership for the WordPress Abilities API,
 * so an AI assistant connected to WordPress (for example through the MCP
 * Adapter) can read the numbers, prepare levels and, when allowed, look up
 * members and give or end access. Everything runs with the rights of the
 * WordPress user the assistant signs in with. Off by default
 * (Aluleth › Settings › Access).
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Admin as Core_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * AI agents.
 */
final class AI {

	/**
	 * Option name.
	 */
	const OPTION = 'aluleth_membership_ai';

	/**
	 * Ability category.
	 */
	const CATEGORY = 'aluleth-membership';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'abilities' ) );
		add_action( 'aluleth/settings/access', array( __CLASS__, 'render' ) );
		add_action( 'aluleth/settings/access_save', array( __CLASS__, 'save' ) );
		add_filter( 'aluleth/settings/access_anchors', array( __CLASS__, 'anchor' ) );
	}

	/**
	 * Choices: the whole feature, then each group of abilities.
	 *
	 * @return array{on: bool, read: bool, create: bool, members: bool, manage: bool}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'on'      => false,
				'read'    => true,
				'create'  => true,
				'members' => false,
				'manage'  => false,
			),
			is_array( $saved ) ? array_map( 'boolval', $saved ) : array()
		);
	}

	/**
	 * Whether a group of abilities is on.
	 *
	 * @param string $group read, create, members or manage.
	 * @return bool
	 */
	public static function allowed( $group ) {
		$conf = self::get();
		return $conf['on'] && ! empty( $conf[ $group ] );
	}

	/**
	 * Adds the anchor to the Access section.
	 *
	 * @param array $anchors Anchors.
	 * @return array
	 */
	public static function anchor( $anchors ) {
		$anchors['ai-membership'] = __( 'AI agents: membership', 'aluleth-membership' );
		return $anchors;
	}

	/**
	 * Registers the ability category.
	 *
	 * @return void
	 */
	public static function category() {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! self::get()['on'] ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Aluleth Membership', 'aluleth-membership' ),
				'description' => __( 'Membership levels, members and their access.', 'aluleth-membership' ),
			)
		);
	}

	/**
	 * Common metadata.
	 *
	 * @param bool $read_only   Does not change anything.
	 * @param bool $destructive May change existing data.
	 * @return array
	 */
	private static function meta( $read_only, $destructive = false ) {
		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => $read_only,
				'destructive' => $destructive,
				'idempotent'  => $read_only,
			),
		);
	}

	/**
	 * Permission check of a group.
	 *
	 * @param string $group Group.
	 * @return \Closure
	 */
	private static function can( $group ) {
		return static function () use ( $group ) {
			return self::allowed( $group ) && current_user_can( Core_Admin::cap( 'membership' ) );
		};
	}

	/**
	 * Registers the abilities that are switched on.
	 *
	 * @return void
	 */
	public static function abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::get()['on'] ) {
			return;
		}
		$email = array(
			'type'   => 'string',
			'format' => 'email',
		);

		if ( self::allowed( 'read' ) ) {
			wp_register_ability(
				'aluleth-membership/get-overview',
				array(
					'label'               => __( 'Get membership numbers', 'aluleth-membership' ),
					'description'         => __( 'Lists the membership levels with their price, length, how people join and their number of members today, plus the people who joined and left during the chosen period and the number of content rules and member files. Use it to answer questions such as "how many members joined this month?". Contains no personal data.', 'aluleth-membership' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'days' => array(
								'type'        => 'integer',
								'description' => __( 'Number of days to count, ending today (1 to 365). Default 30.', 'aluleth-membership' ),
								'minimum'     => 1,
								'maximum'     => 365,
								'default'     => 30,
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'days'        => array( 'type' => 'integer' ),
							'members_now' => array( 'type' => 'integer' ),
							'joined'      => array( 'type' => 'integer' ),
							'ended'       => array( 'type' => 'integer' ),
							'levels'      => array( 'type' => 'array' ),
							'rules'       => array( 'type' => 'integer' ),
							'files'       => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( __CLASS__, 'get_overview' ),
					'permission_callback' => self::can( 'read' ),
					'meta'                => self::meta( true ),
				)
			);
		}

		if ( self::allowed( 'create' ) ) {
			wp_register_ability(
				'aluleth-membership/create-level',
				array(
					'label'               => __( 'Prepare a membership level', 'aluleth-membership' ),
					'description'         => __( 'Creates a membership level from a name, a description, the price text and a length. It is not shown on join cards and nobody can join it until the site owner reviews it and chooses how people join (the answer gives its address).', 'aluleth-membership' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'required'             => array( 'name' ),
						'properties'           => array(
							'name'        => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 80,
							),
							'description' => array(
								'type'      => 'string',
								'maxLength' => 600,
							),
							'price'       => array(
								'type'        => 'string',
								'description' => __( 'Price as shown on the join card, for example "$7 / month". Text only: payments are set up in the store.', 'aluleth-membership' ),
								'maxLength'   => 60,
							),
							'duration'    => array(
								'type' => 'string',
								'enum' => array( 'lifetime', 'days', 'months', 'years' ),
							),
							'duration_n'  => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 3650,
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'integer' ),
							'edit_url' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( __CLASS__, 'create_level' ),
					'permission_callback' => self::can( 'create' ),
					'meta'                => self::meta( false ),
				)
			);
		}

		if ( self::allowed( 'members' ) ) {
			wp_register_ability(
				'aluleth-membership/get-members',
				array(
					'label'               => __( 'Get members', 'aluleth-membership' ),
					'description'         => __( 'Returns members with their level, status, start and end dates and where their access comes from. Members are personal data: only use them for the site owner\'s request.', 'aluleth-membership' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'level_id' => array( 'type' => 'integer' ),
							'status'   => array(
								'type'    => 'string',
								'enum'    => array( 'active', 'ended', 'paused', 'pending', 'all' ),
								'default' => 'active',
							),
							'search'   => array(
								'type'        => 'string',
								'description' => __( 'Part of an email address or a name.', 'aluleth-membership' ),
							),
							'limit'    => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 100,
								'default' => 30,
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'total'   => array( 'type' => 'integer' ),
							'members' => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( __CLASS__, 'get_members' ),
					'permission_callback' => self::can( 'members' ),
					'meta'                => self::meta( true ),
				)
			);
		}

		if ( self::allowed( 'manage' ) ) {
			wp_register_ability(
				'aluleth-membership/give-access',
				array(
					'label'               => __( 'Give a membership', 'aluleth-membership' ),
					'description'         => __( 'Gives a level to a person by email, like "Add a member" in the admin. An account is created when the email has none. The welcome email is sent only when asked for. Use only when the site owner asks for it.', 'aluleth-membership' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'required'             => array( 'email', 'level_id' ),
						'properties'           => array(
							'email'      => $email,
							'level_id'   => array( 'type' => 'integer' ),
							'first_name' => array( 'type' => 'string' ),
							'ends_on'    => array(
								'type'        => 'string',
								'format'      => 'date',
								'description' => __( 'Last day of access (YYYY-MM-DD). Empty: the length of the level.', 'aluleth-membership' ),
							),
							'welcome'    => array(
								'type'    => 'boolean',
								'default' => false,
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'member'   => array( 'type' => 'string' ),
							'level'    => array( 'type' => 'string' ),
							'ends'     => array( 'type' => array( 'string', 'null' ) ),
							'new_user' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( __CLASS__, 'give_access' ),
					'permission_callback' => self::can( 'manage' ),
					'meta'                => self::meta( false ),
				)
			);
			wp_register_ability(
				'aluleth-membership/end-access',
				array(
					'label'               => __( 'End a membership given by hand', 'aluleth-membership' ),
					'description'         => __( 'Ends the access to a level that was given by hand, by a free sign-up, a form or an import. Access paid in FluentCart, WooCommerce or Aluleth Commerce is never ended here: it follows the order or subscription in the store. Use only when the site owner asks for it.', 'aluleth-membership' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'required'             => array( 'email', 'level_id' ),
						'properties'           => array(
							'email'    => $email,
							'level_id' => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'ended'     => array( 'type' => 'integer' ),
							'still_has' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( __CLASS__, 'end_access' ),
					'permission_callback' => self::can( 'manage' ),
					'meta'                => self::meta( false, true ),
				)
			);
		}
	}

	/**
	 * Ability: numbers.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function get_overview( $input = array() ) {
		$days   = isset( $input['days'] ) ? max( 1, min( 365, (int) $input['days'] ) ) : 30;
		$since  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$counts = Members::counts();
		$levels = array();
		foreach ( Levels::all() as $id => $level ) {
			$ways = array();
			foreach ( array( 'fluentcart', 'woocommerce', 'commerce', 'forms' ) as $key ) {
				if ( $level[ $key ] ) {
					$ways[] = $key;
				}
			}
			if ( $level['free_signup'] ) {
				$ways[] = 'free_signup';
			}
			$levels[] = array(
				'id'      => (int) $id,
				'name'    => $level['name'],
				'price'   => $level['price'],
				'length'  => Levels::duration_text( $level ),
				'on_card' => (bool) $level['on_card'],
				'join'    => $ways,
				'members' => isset( $counts[ $id ] ) ? (int) $counts[ $id ] : 0,
			);
		}
		return array(
			'days'        => $days,
			'members_now' => Members::count_people(),
			'joined'      => Members::count_since( 'started_at', $since ),
			'ended'       => Members::count_since( 'ended_at', $since ),
			'levels'      => $levels,
			'rules'       => count( Rules::all() ),
			'files'       => count( Files::all() ),
		);
	}

	/**
	 * Ability: prepare a level.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function create_level( $input = array() ) {
		$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'alm_ai_name', __( 'A level needs a name.', 'aluleth-membership' ) );
		}
		$id = Levels::save(
			0,
			$name,
			Levels::clean(
				array(
					'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
					'price'       => isset( $input['price'] ) ? (string) $input['price'] : '',
					'duration'    => isset( $input['duration'] ) ? (string) $input['duration'] : 'lifetime',
					'duration_n'  => isset( $input['duration_n'] ) ? (int) $input['duration_n'] : 1,
					'on_card'     => 0,
				)
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'alm_ai_level', __( 'The level could not be created.', 'aluleth-membership' ) );
		}
		return array(
			'id'       => (int) $id,
			'edit_url' => Admin::url(
				array(
					'tab'   => 'levels',
					'level' => (int) $id,
				)
			),
		);
	}

	/**
	 * Ability: members.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function get_members( $input = array() ) {
		$result = Members::query(
			array(
				'level'    => isset( $input['level_id'] ) ? (int) $input['level_id'] : 0,
				'status'   => isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'active',
				'search'   => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
				'per_page' => isset( $input['limit'] ) ? max( 1, min( 100, (int) $input['limit'] ) ) : 30,
			)
		);
		$out    = array();
		foreach ( $result['items'] as $row ) {
			$level = Levels::get( $row->level_id );
			$out[] = array(
				'email'   => (string) $row->user_email,
				'name'    => (string) $row->display_name,
				'level'   => $level ? $level['name'] : '#' . (int) $row->level_id,
				'status'  => (string) $row->status,
				'source'  => Admin::source_label( $row->source ),
				'started' => $row->started_at ? mysql2date( 'c', $row->started_at . ' UTC' ) : null,
				'ends'    => $row->expires_at ? mysql2date( 'c', $row->expires_at . ' UTC' ) : null,
				'ended'   => $row->ended_at ? mysql2date( 'c', $row->ended_at . ' UTC' ) : null,
			);
		}
		return array(
			'total'   => (int) $result['total'],
			'members' => $out,
		);
	}

	/**
	 * Ability: give a level.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function give_access( $input = array() ) {
		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		$level = Levels::get( isset( $input['level_id'] ) ? (int) $input['level_id'] : 0 );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'alm_ai_email', __( 'Please give a valid email address.', 'aluleth-membership' ) );
		}
		if ( ! $level ) {
			return new \WP_Error( 'alm_ai_level', __( 'This level does not exist. Use get-overview to see the levels.', 'aluleth-membership' ) );
		}
		$expires = 'level';
		if ( ! empty( $input['ends_on'] ) ) {
			$time = strtotime( (string) $input['ends_on'] . ' 23:59:59' );
			if ( ! $time || $time < time() ) {
				return new \WP_Error( 'alm_ai_date', __( 'The end date must be a day in the future (YYYY-MM-DD).', 'aluleth-membership' ) );
			}
			$expires = get_gmt_from_date( gmdate( 'Y-m-d H:i:s', $time ) );
		}
		list( $user_id, $created ) = Members::ensure_user( $email, isset( $input['first_name'] ) ? sanitize_text_field( (string) $input['first_name'] ) : '' );
		if ( ! $user_id ) {
			return new \WP_Error( 'alm_ai_user', __( 'This account could not be created.', 'aluleth-membership' ) );
		}
		$row_id = Members::grant(
			$user_id,
			$level['id'],
			'manual',
			'by:' . get_current_user_id(),
			array(
				'expires_at' => $expires,
				'notify'     => ! empty( $input['welcome'] ),
			)
		);
		$row    = Members::row( $row_id );
		return array(
			'member'   => $email,
			'level'    => $level['name'],
			'ends'     => $row && $row->expires_at ? mysql2date( 'c', $row->expires_at . ' UTC' ) : null,
			'new_user' => (bool) $created,
		);
	}

	/**
	 * Ability: end a level given without a store.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function end_access( $input = array() ) {
		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		$level = isset( $input['level_id'] ) ? (int) $input['level_id'] : 0;
		if ( ! $user ) {
			return new \WP_Error( 'alm_ai_member', __( 'No member with this email.', 'aluleth-membership' ) );
		}
		$ended = 0;
		$store = 0;
		foreach ( Members::rows_of( $user->ID ) as $row ) {
			if ( (int) $row->level_id !== $level || 'ended' === $row->status ) {
				continue;
			}
			if ( in_array( $row->source, array( 'fluentcart', 'woocommerce', 'aluleth-commerce' ), true ) ) {
				++$store;
				continue;
			}
			Members::end( $row, 'removed' );
			++$ended;
		}
		Members::forget( $user->ID );
		if ( ! $ended && $store ) {
			return new \WP_Error( 'alm_ai_store', __( 'This membership is paid in the store: cancel or refund the order or subscription there and the access follows.', 'aluleth-membership' ) );
		}
		return array(
			'ended'     => $ended,
			'still_has' => Members::has_level( $user->ID, $level ),
		);
	}

	/**
	 * AI agents card in Aluleth › Settings › Access.
	 *
	 * @return void
	 */
	public static function render() {
		$conf      = self::get();
		$available = function_exists( 'wp_register_ability' );
		echo '<section class="al-card al-sec" id="ai-membership"><div class="al-sec-head"><div><h2>' . esc_html__( 'AI agents (MCP): membership', 'aluleth-membership' ) . '</h2><p>' . esc_html__( 'Let an AI assistant you connect to WordPress work with your memberships, using the abilities built into WordPress. It acts with the rights of the WordPress user it signs in with, never more.', 'aluleth-membership' ) . '</p></div>';
		echo '<label class="al-switch"><input type="checkbox" name="alm_ai_on" value="1"' . checked( $conf['on'], true, false ) . ( $available ? '' : ' disabled' ) . '> <span>' . esc_html__( 'On', 'aluleth-membership' ) . '</span></label></div>';
		if ( ! $available ) {
			echo '<p class="al-help">' . esc_html__( 'This needs the Abilities API of WordPress 6.9 or later.', 'aluleth-membership' ) . '</p>';
		}
		$groups = array(
			'read'    => array( __( 'Read levels and numbers', 'aluleth-membership' ), __( '"How many members joined this month?" No personal data.', 'aluleth-membership' ) ),
			'create'  => array( __( 'Prepare levels', 'aluleth-membership' ), __( 'Hidden from join cards until you review them.', 'aluleth-membership' ) ),
			'members' => array( __( 'Read members', 'aluleth-membership' ), __( 'Personal data: off by default.', 'aluleth-membership' ) ),
			'manage'  => array( __( 'Give and end memberships', 'aluleth-membership' ), __( 'Never ends access paid in a store. Off by default.', 'aluleth-membership' ) ),
		);
		echo '<div class="al-grid2">';
		foreach ( $groups as $key => $text ) {
			echo '<label class="al-checkcard"><input type="checkbox" name="alm_ai_' . esc_attr( $key ) . '" value="1"' . checked( $conf[ $key ], true, false ) . '> <span><strong>' . esc_html( $text[0] ) . '</strong><small>' . esc_html( $text[1] ) . '</small></span></label>';
		}
		echo '</div><p class="al-help">' . esc_html__( 'Install the MCP Adapter plugin and sign the assistant in with a WordPress user (an application password works). Abilities: aluleth-membership/get-overview, create-level, get-members, give-access, end-access.', 'aluleth-membership' ) . '</p></section>';
	}

	/**
	 * Saves the choices (with the Access section).
	 *
	 * @param array $post Unslashed $_POST.
	 * @return void
	 */
	public static function save( $post ) {
		$post = is_array( $post ) ? $post : array();
		update_option(
			self::OPTION,
			array(
				'on'      => ! empty( $post['alm_ai_on'] ),
				'read'    => ! empty( $post['alm_ai_read'] ),
				'create'  => ! empty( $post['alm_ai_create'] ),
				'members' => ! empty( $post['alm_ai_members'] ),
				'manage'  => ! empty( $post['alm_ai_manage'] ),
			),
			true
		);
	}
}
