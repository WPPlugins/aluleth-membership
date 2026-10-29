<?php
/**
 * Menu items for members: "Who sees this item" on every item of the classic
 * menus (Appearance › Menus). Items of the block Navigation use "Who sees
 * this block" like any other block.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Menus.
 */
final class Menus {

	/**
	 * Meta key on the menu item.
	 */
	const META = '_aluleth_menu_access';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, 'fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter' ), 20 );
	}

	/**
	 * Setting of an item.
	 *
	 * @param int $item_id Menu item id.
	 * @return array{show: string, levels: int[]}
	 */
	public static function get( $item_id ) {
		$saved = get_post_meta( (int) $item_id, self::META, true );
		return array(
			'show'   => is_array( $saved ) && isset( $saved['show'] ) && in_array( $saved['show'], array( 'members', 'guests' ), true ) ? $saved['show'] : 'all',
			'levels' => is_array( $saved ) && isset( $saved['levels'] ) ? array_values( array_filter( array_map( 'intval', (array) $saved['levels'] ) ) ) : array(),
		);
	}

	/**
	 * Fields under each menu item.
	 *
	 * @param int    $item_id Menu item id.
	 * @param object $item    Menu item.
	 * @return void
	 */
	public static function fields( $item_id, $item ) {
		unset( $item );
		$levels = Levels::all();
		if ( ! $levels ) {
			return;
		}
		$cur = self::get( $item_id );
		$id  = (int) $item_id;
		wp_nonce_field( 'aluleth_membership_menu_' . $id, 'alm_menu_nonce_' . $id );
		echo '<fieldset class="field-alm-members description description-wide" style="margin:6px 0 10px"><legend style="font-weight:600">' . esc_html__( 'Membership: who sees this item', 'aluleth-membership' ) . '</legend>';
		echo '<select name="alm_menu[' . (int) $id . '][show]" style="margin:4px 0">';
		foreach (
			array(
				'all'     => __( 'Everyone', 'aluleth-membership' ),
				'members' => __( 'Members', 'aluleth-membership' ),
				'guests'  => __( 'Everyone but members', 'aluleth-membership' ),
			) as $key => $label
		) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $cur['show'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><br>';
		foreach ( $levels as $level_id => $level ) {
			echo '<label style="margin-right:12px"><input type="checkbox" name="alm_menu[' . (int) $id . '][levels][]" value="' . (int) $level_id . '"' . checked( in_array( $level_id, $cur['levels'], true ), true, false ) . '> ' . esc_html( $level['name'] ) . '</label>';
		}
		echo '<br><span class="description">' . esc_html__( 'No level ticked: any level.', 'aluleth-membership' ) . '</span></fieldset>';
	}

	/**
	 * Saves the fields of an item.
	 *
	 * @param int $menu_id Menu id.
	 * @param int $item_id Menu item id.
	 * @return void
	 */
	public static function save( $menu_id, $item_id ) {
		unset( $menu_id );
		$item_id = (int) $item_id;
		$nonce   = isset( $_POST[ 'alm_menu_nonce_' . $item_id ] ) ? sanitize_key( wp_unslash( $_POST[ 'alm_menu_nonce_' . $item_id ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'aluleth_membership_menu_' . $item_id ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$raw    = isset( $_POST['alm_menu'][ $item_id ] ) && is_array( $_POST['alm_menu'][ $item_id ] ) ? map_deep( wp_unslash( $_POST['alm_menu'][ $item_id ] ), 'sanitize_text_field' ) : array();
		$show   = isset( $raw['show'] ) && in_array( $raw['show'], array( 'members', 'guests' ), true ) ? $raw['show'] : 'all';
		$levels = isset( $raw['levels'] ) ? array_values( array_filter( array_map( 'absint', (array) $raw['levels'] ) ) ) : array();
		if ( 'all' === $show ) {
			delete_post_meta( $item_id, self::META );
			return;
		}
		update_post_meta(
			$item_id,
			self::META,
			array(
				'show'   => $show,
				'levels' => $levels,
			)
		);
	}

	/**
	 * Removes the items (and their sub-items) the visitor must not see.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function filter( $items ) {
		if ( is_admin() ) {
			return $items;
		}
		$hidden = array();
		foreach ( (array) $items as $item ) {
			$setting = self::get( $item->ID );
			if ( 'all' !== $setting['show'] && ! Restrict::visible( $setting['show'], $setting['levels'] ) ) {
				$hidden[ (int) $item->ID ] = true;
			}
		}
		if ( ! $hidden ) {
			return $items;
		}
		$out = array();
		foreach ( (array) $items as $item ) {
			$parent = (int) $item->menu_item_parent;
			if ( isset( $hidden[ (int) $item->ID ] ) || ( $parent && isset( $hidden[ $parent ] ) ) ) {
				$hidden[ (int) $item->ID ] = true;
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}
}
