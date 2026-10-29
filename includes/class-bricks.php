<?php
/**
 * Bricks: "Aluleth Membership" conditions on every element (Conditions in
 * the element's settings).
 *
 * - Membership level: is / is not a member of a level (or of any level).
 * - Access to this content: the visitor can / cannot read the current post.
 *
 * Nothing is loaded when Bricks is not the active theme.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks conditions.
 */
final class Bricks {

	/**
	 * Group key.
	 */
	const GROUP = 'aluleth_membership';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'bricks/conditions/groups', array( __CLASS__, 'groups' ) );
		add_filter( 'bricks/conditions/options', array( __CLASS__, 'options' ) );
		add_filter( 'bricks/conditions/result', array( __CLASS__, 'result' ), 10, 3 );
	}

	/**
	 * Adds the group.
	 *
	 * @param array $groups Groups.
	 * @return array
	 */
	public static function groups( $groups ) {
		$groups   = (array) $groups;
		$groups[] = array(
			'name'  => self::GROUP,
			'label' => __( 'Aluleth Membership', 'aluleth-membership' ),
		);
		return $groups;
	}

	/**
	 * Adds the conditions.
	 *
	 * @param array $options Conditions.
	 * @return array
	 */
	public static function options( $options ) {
		$options = (array) $options;
		$levels  = array( 'any' => __( 'Any level', 'aluleth-membership' ) );
		foreach ( Levels::all() as $id => $level ) {
			$levels[ (string) $id ] = $level['name'];
		}
		$compare   = array(
			'type'        => 'select',
			'options'     => array(
				'==' => __( 'is', 'aluleth-membership' ),
				'!=' => __( 'is not', 'aluleth-membership' ),
			),
			'placeholder' => __( 'is', 'aluleth-membership' ),
		);
		$options[] = array(
			'key'     => 'alm_level',
			'group'   => self::GROUP,
			'label'   => __( 'Member of', 'aluleth-membership' ),
			'compare' => $compare,
			'value'   => array(
				'type'        => 'select',
				'options'     => $levels,
				'placeholder' => __( 'Any level', 'aluleth-membership' ),
			),
		);
		$options[] = array(
			'key'     => 'alm_access',
			'group'   => self::GROUP,
			'label'   => __( 'Can read this content', 'aluleth-membership' ),
			'compare' => $compare,
			'value'   => array(
				'type'        => 'select',
				'options'     => array(
					'yes' => __( 'Yes', 'aluleth-membership' ),
					'no'  => __( 'No', 'aluleth-membership' ),
				),
				'placeholder' => __( 'Yes', 'aluleth-membership' ),
			),
		);
		return $options;
	}

	/**
	 * Result of a condition.
	 *
	 * @param bool   $result        Result so far.
	 * @param string $condition_key Condition key.
	 * @param array  $condition     Condition settings ('compare', 'value').
	 * @return bool
	 */
	public static function result( $result, $condition_key, $condition ) {
		if ( ! in_array( $condition_key, array( 'alm_level', 'alm_access' ), true ) ) {
			return $result;
		}
		$condition = (array) $condition;
		$compare   = isset( $condition['compare'] ) && '!=' === $condition['compare'] ? '!=' : '==';
		$value     = isset( $condition['value'] ) && '' !== (string) $condition['value'] ? (string) $condition['value'] : '';
		$match     = 'alm_level' === $condition_key ? self::is_member( $value ) : self::can_read( 'no' !== $value );
		return '==' === $compare ? $match : ! $match;
	}

	/**
	 * Whether the visitor is a member of a level ('' or 'any': any level).
	 *
	 * @param string $level Level id or 'any'.
	 * @return bool
	 */
	public static function is_member( $level ) {
		$mine = Members::levels_of( get_current_user_id() );
		if ( '' === $level || 'any' === $level ) {
			return (bool) $mine;
		}
		return in_array( (int) $level, $mine, true );
	}

	/**
	 * Whether "can read this content" matches.
	 *
	 * @param bool $wanted True for "Yes".
	 * @return bool
	 */
	public static function can_read( $wanted ) {
		$post_id = get_the_ID();
		$can     = $post_id ? Rules::current_user_can_read( (int) $post_id ) : true;
		return $wanted ? $can : ! $can;
	}
}
