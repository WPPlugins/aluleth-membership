<?php
/**
 * "Membership" box in the post editor: who can read this post.
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

use Aluleth\Core\Admin as Core_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Post editor box.
 */
final class Editor {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Content types the box is shown on.
	 *
	 * @return string[]
	 */
	public static function types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Adds the box.
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      Post.
	 * @return void
	 */
	public static function add( $post_type, $post = null ) {
		unset( $post );
		if ( ! in_array( $post_type, self::types(), true ) || ! current_user_can( Core_Admin::cap( 'membership' ) ) ) {
			return;
		}
		add_meta_box( 'aluleth-membership', __( 'Membership', 'aluleth-membership' ), array( __CLASS__, 'render' ), $post_type, 'side', 'default', array( '__back_compat_meta_box' => false ) );
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function render( $post ) {
		$levels = Levels::all();
		$own    = Rules::post_rule( $post->ID );
		$choice = $own ? ( 'open' === $own->mode ? 'open' : 'members' ) : 'inherit';
		$picked = $own && is_array( $own->level_ids ) ? $own->level_ids : array();
		$mode   = $own && 'open' !== $own->mode ? $own->mode : 'teaser';

		// What the other rules say, without this post's own rule.
		$inherited = null;
		if ( $own ) {
			Rules::flush();
		}
		$matched = array();
		foreach ( Rules::all() as $rule ) {
			if ( 'post' === $rule->kind ) {
				continue;
			}
			if ( 'type' === $rule->kind && $rule->target === $post->post_type ) {
				$matched[] = $rule;
			} elseif ( 'term' === $rule->kind ) {
				list( $tax, $term_id ) = explode( ':', $rule->target, 2 );
				if ( is_object_in_taxonomy( $post->post_type, $tax ) && has_term( (int) $term_id, $tax, $post ) ) {
					$matched[] = $rule;
				}
			}
		}

		wp_nonce_field( 'aluleth_membership_post_' . $post->ID, '_almpostnonce' );
		echo '<div class="alm-box-editor">';
		if ( ! $levels ) {
			echo '<p>' . esc_html__( 'Create a membership level first.', 'aluleth-membership' ) . ' <a href="' . esc_url( Admin::url( array( 'tab' => 'levels' ) ) ) . '">' . esc_html__( 'Levels', 'aluleth-membership' ) . '</a></p></div>';
			return;
		}
		if ( $matched ) {
			$names = array();
			foreach ( $matched as $rule ) {
				$names[] = Rules::target_text( $rule ) . ' → ' . Rules::levels_text( $rule->level_ids );
			}
			echo '<p class="description">' . esc_html__( 'Already for members because of:', 'aluleth-membership' ) . '<br>' . esc_html( implode( '; ', $names ) ) . '</p>';
		}
		echo '<p><label><input type="radio" name="alm_post[who]" value="inherit"' . checked( 'inherit', $choice, false ) . '> ' . esc_html( $matched ? __( 'Follow the rules above', 'aluleth-membership' ) : __( 'Everyone', 'aluleth-membership' ) ) . '</label><br>';
		echo '<label><input type="radio" name="alm_post[who]" value="members"' . checked( 'members', $choice, false ) . '> ' . esc_html__( 'Members only', 'aluleth-membership' ) . '</label><br>';
		if ( $matched ) {
			echo '<label><input type="radio" name="alm_post[who]" value="open"' . checked( 'open', $choice, false ) . '> ' . esc_html__( 'Everyone, as an exception', 'aluleth-membership' ) . '</label>';
		}
		echo '</p><fieldset class="alm-box-editor__levels"><legend>' . esc_html__( 'Levels that open it (none ticked: any member)', 'aluleth-membership' ) . '</legend>';
		foreach ( $levels as $id => $level ) {
			echo '<label style="display:block"><input type="checkbox" name="alm_post[levels][]" value="' . (int) $id . '"' . checked( in_array( $id, $picked, true ), true, false ) . '> ' . esc_html( $level['name'] ) . '</label>';
		}
		echo '</fieldset><p><label for="alm-post-mode">' . esc_html__( 'Visitors without access see', 'aluleth-membership' ) . '</label><br><select id="alm-post-mode" name="alm_post[mode]" style="width:100%">';
		foreach ( self::modes() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $mode, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="alm-post-drip">' . esc_html__( 'Opens for members after (days)', 'aluleth-membership' ) . '</label><br><input id="alm-post-drip" type="number" min="0" max="3650" name="alm_post[drip_days]" value="' . (int) ( $own ? $own->drip_days : 0 ) . '" class="small-text"></p>';
		echo '<p><label><input type="checkbox" name="alm_post[metered]" value="1"' . checked( $own && ! empty( $own->metered ), true, false ) . '> ' . esc_html__( 'Counts in the free articles of the month', 'aluleth-membership' ) . '</label></p>';
		/** This action is documented in includes/class-admin.php */
		do_action( 'aluleth_membership/rule_fields', $own && 'open' !== $own->mode ? $own : null, 'alm_post[addon]', 'editor' );
		echo '<p class="description">' . esc_html__( 'Tip: add a "More" block where the free part ends.', 'aluleth-membership' ) . '</p></div>';
	}

	/**
	 * Display choices.
	 *
	 * @return array
	 */
	public static function modes() {
		return array(
			'teaser'   => __( 'The beginning, then the join card', 'aluleth-membership' ),
			'card'     => __( 'The join card only', 'aluleth-membership' ),
			'redirect' => __( 'Sent to the plans page', 'aluleth-membership' ),
		);
	}

	/**
	 * Saves the box.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['_almpostnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_almpostnonce'] ) ), 'aluleth_membership_post_' . $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( Core_Admin::cap( 'membership' ) ) || ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}
		$raw    = isset( $_POST['alm_post'] ) && is_array( $_POST['alm_post'] ) ? map_deep( wp_unslash( $_POST['alm_post'] ), 'sanitize_text_field' ) : array();
		$who    = isset( $raw['who'] ) ? sanitize_key( $raw['who'] ) : 'inherit';
		$levels = isset( $raw['levels'] ) ? array_map( 'absint', (array) $raw['levels'] ) : array();
		$mode   = isset( $raw['mode'] ) ? sanitize_key( $raw['mode'] ) : 'teaser';
		$own    = Rules::post_rule( $post_id );
		if ( 'members' === $who ) {
			Rules::save(
				'post',
				(string) $post_id,
				$levels ? $levels : 'any',
				$mode,
				array(
					'drip_days' => isset( $raw['drip_days'] ) ? absint( $raw['drip_days'] ) : 0,
					'metered'   => ! empty( $raw['metered'] ),
					'addon'     => isset( $raw['addon'] ) && is_array( $raw['addon'] ) ? $raw['addon'] : array(),
				)
			);
		} elseif ( 'open' === $who ) {
			Rules::save( 'post', (string) $post_id, array(), 'open' );
		} elseif ( $own ) {
			Rules::delete( (int) $own->id );
		}
	}
}
