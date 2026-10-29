<?php
/**
 * Where access comes from: FluentCart and WooCommerce purchases (one-time
 * or subscription) and Aluleth Forms entries. The free sign-up is in
 * Account, the site owner's own additions in the members screen.
 *
 * References kept on each access:
 * - FluentCart: "order:{id}" (one-time) or "sub:{id}" (subscription);
 * - WooCommerce: "order:{id}" or "wcs:{id}" (WooCommerce Subscriptions);
 * - Aluleth Forms: "entry:{id}".
 *
 * @package Aluleth\Membership
 */

namespace Aluleth\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * Sources.
 */
final class Sources {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		// FluentCart.
		add_action( 'fluent_cart/order_paid_done', array( __CLASS__, 'fc_paid' ), 20 );
		add_action( 'fluent_cart/order_fully_refunded', array( __CLASS__, 'fc_refunded' ), 20 );
		add_action( 'fluent_cart/order_status_changed_to_canceled', array( __CLASS__, 'fc_canceled' ), 20 );
		foreach ( array( 'subscription_activated', 'subscription_reactivated', 'subscription_renewed', 'subscription_resumed', 'subscription_eot' ) as $hook ) {
			add_action( 'fluent_cart/' . $hook, array( __CLASS__, 'fc_sub_active' ), 20 );
		}
		add_action( 'fluent_cart/subscription_canceled', array( __CLASS__, 'fc_sub_canceled' ), 20 );
		add_action( 'fluent_cart/subscription_paused', array( __CLASS__, 'fc_sub_paused' ), 20 );
		add_action( 'fluent_cart/subscription_expired_validity', array( __CLASS__, 'fc_sub_expired' ), 20 );

		// WooCommerce.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'wc_paid' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'wc_paid' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'wc_refunded' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'wc_cancelled' ), 20 );
		add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'wcs_status' ), 20, 3 );

		// Aluleth Forms.
		add_action( 'aluleth_forms/entry_created', array( __CLASS__, 'form_entry' ), 20, 4 );
	}

	/**
	 * Levels given by some products of a store.
	 *
	 * @param string $store       fluentcart or woocommerce.
	 * @param int[]  $product_ids Product ids.
	 * @return array<int, int[]> Level id => matching product ids.
	 */
	private static function levels_for( $store, array $product_ids ) {
		$out = array();
		foreach ( Levels::all() as $id => $level ) {
			$match = array_intersect( array_map( 'intval', $product_ids ), $level[ $store ] );
			if ( $match ) {
				$out[ $id ] = array_values( $match );
			}
		}
		return $out;
	}

	/**
	 * Reads a value from a FluentCart model or array.
	 *
	 * @param mixed  $thing Model, array or null.
	 * @param string $key   Key.
	 * @return mixed
	 */
	private static function val( $thing, $key ) {
		if ( is_array( $thing ) ) {
			return isset( $thing[ $key ] ) ? $thing[ $key ] : null;
		}
		if ( is_object( $thing ) ) {
			if ( isset( $thing->$key ) ) {
				return $thing->$key;
			}
			if ( method_exists( $thing, '__get' ) ) {
				// FluentCart models load relations (order items, customer) when they are read.
				try {
					return $thing->$key;
				} catch ( \Throwable $e ) {
					return null;
				}
			}
		}
		return null;
	}

	/**
	 * WordPress account of a FluentCart customer.
	 *
	 * @param mixed $customer Customer.
	 * @return int
	 */
	private static function fc_user( $customer ) {
		$user_id = (int) self::val( $customer, 'user_id' );
		if ( $user_id && get_userdata( $user_id ) ) {
			return $user_id;
		}
		list( $user_id ) = Members::ensure_user( (string) self::val( $customer, 'email' ), (string) self::val( $customer, 'first_name' ), (string) self::val( $customer, 'last_name' ) );
		return $user_id;
	}

	/**
	 * A FluentCart order was paid: gives the levels of its products.
	 *
	 * @param array $data Event data (order, customer, subscription).
	 * @return void
	 */
	public static function fc_paid( $data ) {
		$order = self::val( $data, 'order' );
		if ( ! $order || 'renewal' === self::val( $order, 'type' ) ) {
			return;
		}
		$items    = self::val( $order, 'order_items' );
		$products = array();
		foreach ( $items ? $items : array() as $item ) {
			$products[] = (int) self::val( $item, 'post_id' );
		}
		$levels = self::levels_for( 'fluentcart', $products );
		if ( ! $levels ) {
			return;
		}
		$customer = self::val( $data, 'customer' );
		$customer = $customer ? $customer : self::val( $order, 'customer' );
		$user_id  = self::fc_user( $customer );
		if ( ! $user_id ) {
			return;
		}
		$sub = self::val( $data, 'subscription' );
		foreach ( $levels as $level_id => $matched ) {
			if ( $sub && in_array( (int) self::val( $sub, 'product_id' ), $matched, true ) ) {
				Members::grant( $user_id, $level_id, 'fluentcart', 'sub:' . (int) self::val( $sub, 'id' ), array( 'expires_at' => null ) );
			} else {
				Members::grant( $user_id, $level_id, 'fluentcart', 'order:' . (int) self::val( $order, 'id' ) );
			}
		}
	}

	/**
	 * Ends the accesses of a FluentCart order and of its subscription.
	 *
	 * @param mixed  $order  Order.
	 * @param string $reason Reason.
	 * @return void
	 */
	private static function fc_end_order( $order, $reason ) {
		global $wpdb;
		$order_id = (int) self::val( $order, 'id' );
		if ( ! $order_id ) {
			return;
		}
		Members::end_by_ref( 'fluentcart', 'order:' . $order_id, $reason );
		$parent = 'renewal' === self::val( $order, 'type' ) ? 0 : $order_id;
		if ( $parent ) {
			$subs = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE parent_order_id = %d', $wpdb->prefix . 'fct_subscriptions', $parent ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $subs as $sub_id ) {
				Members::end_by_ref( 'fluentcart', 'sub:' . (int) $sub_id, $reason );
			}
		}
	}

	/**
	 * A FluentCart order was fully refunded.
	 *
	 * @param array $data Event data.
	 * @return void
	 */
	public static function fc_refunded( $data ) {
		self::fc_end_order( self::val( $data, 'order' ), 'refunded' );
	}

	/**
	 * A FluentCart order was cancelled.
	 *
	 * @param array $data Event data.
	 * @return void
	 */
	public static function fc_canceled( $data ) {
		self::fc_end_order( self::val( $data, 'order' ), 'cancelled' );
	}

	/**
	 * A FluentCart subscription is active again (renewed, resumed, reactivated,
	 * or all its payments are made).
	 *
	 * @param array $data Event data.
	 * @return void
	 */
	public static function fc_sub_active( $data ) {
		$sub = self::val( $data, 'subscription' );
		if ( ! $sub ) {
			return;
		}
		$ref  = 'sub:' . (int) self::val( $sub, 'id' );
		$rows = Members::rows_by_ref( 'fluentcart', $ref );
		if ( ! $rows ) {
			// A level linked to the product after the first payment: give it now.
			$levels = self::levels_for( 'fluentcart', array( (int) self::val( $sub, 'product_id' ) ) );
			$user   = $levels ? self::fc_user( self::val( $data, 'customer' ) ? self::val( $data, 'customer' ) : self::val( $sub, 'customer' ) ) : 0;
			foreach ( $user ? array_keys( $levels ) : array() as $level_id ) {
				Members::grant( $user, $level_id, 'fluentcart', $ref, array( 'expires_at' => null ) );
			}
			return;
		}
		foreach ( $rows as $row ) {
			if ( 'ended' === $row->status ) {
				Members::grant( (int) $row->user_id, (int) $row->level_id, 'fluentcart', $ref, array( 'expires_at' => null ) );
			}
		}
		Members::update_by_ref(
			'fluentcart',
			$ref,
			array(
				'status'     => 'active',
				'expires_at' => null,
			)
		);
	}

	/**
	 * A FluentCart subscription was cancelled: access stays until the end of
	 * the period already paid.
	 *
	 * @param array $data Event data.
	 * @return void
	 */
	public static function fc_sub_canceled( $data ) {
		$sub = self::val( $data, 'subscription' );
		if ( ! $sub ) {
			return;
		}
		$ref  = 'sub:' . (int) self::val( $sub, 'id' );
		$next = (string) self::val( $sub, 'next_billing_date' );
		$end  = '' !== $next ? strtotime( $next . ' UTC' ) : 0;
		if ( $end && $end > time() ) {
			Members::update_by_ref( 'fluentcart', $ref, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', $end ) ) );
		} else {
			Members::end_by_ref( 'fluentcart', $ref, 'cancelled' );
		}
	}

	/**
	 * A FluentCart subscription was paused.
	 *
	 * @param array $data Event data.
	 * @return void
	 */
	public static function fc_sub_paused( $data ) {
		$sub = self::val( $data, 'subscription' );
		if ( $sub ) {
			Members::update_by_ref( 'fluentcart', 'sub:' . (int) self::val( $sub, 'id' ), array( 'status' => 'paused' ) );
		}
	}

	/**
	 * A FluentCart subscription is no longer valid (unpaid, or the paid period
	 * of a cancelled one is over).
	 *
	 * @param array $data Event data.
	 * @return void
	 */
	public static function fc_sub_expired( $data ) {
		$sub = self::val( $data, 'subscription' );
		if ( $sub ) {
			Members::end_by_ref( 'fluentcart', 'sub:' . (int) self::val( $sub, 'id' ), 'expired' );
		}
	}

	/**
	 * WordPress account of a WooCommerce order.
	 *
	 * @param \WC_Order|\WC_Subscription $order Order.
	 * @return int
	 */
	private static function wc_user( $order ) {
		$user_id = (int) $order->get_user_id();
		if ( $user_id && get_userdata( $user_id ) ) {
			return $user_id;
		}
		list( $user_id ) = Members::ensure_user( (string) $order->get_billing_email(), (string) $order->get_billing_first_name(), (string) $order->get_billing_last_name() );
		return $user_id;
	}

	/**
	 * Product ids of a WooCommerce order (variations count as their product).
	 *
	 * @param \WC_Order $order Order.
	 * @return int[]
	 */
	private static function wc_products( $order ) {
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( is_callable( array( $item, 'get_product_id' ) ) ) {
				$ids[] = (int) $item->get_product_id();
			}
		}
		return $ids;
	}

	/**
	 * A WooCommerce order is paid (processing or completed).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function wc_paid( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) ) {
			return;
		}
		// Orders that start or renew a WooCommerce Subscription are handled by the subscription.
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, array( 'parent', 'renewal', 'resubscribe', 'switch' ) ) ) {
			return;
		}
		$levels = self::levels_for( 'woocommerce', self::wc_products( $order ) );
		if ( ! $levels ) {
			return;
		}
		$user_id = self::wc_user( $order );
		foreach ( $user_id ? array_keys( $levels ) : array() as $level_id ) {
			Members::grant( $user_id, $level_id, 'woocommerce', 'order:' . (int) $order->get_id() );
		}
	}

	/**
	 * A WooCommerce order was refunded.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function wc_refunded( $order_id ) {
		Members::end_by_ref( 'woocommerce', 'order:' . (int) $order_id, 'refunded' );
	}

	/**
	 * A WooCommerce order was cancelled.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function wc_cancelled( $order_id ) {
		Members::end_by_ref( 'woocommerce', 'order:' . (int) $order_id, 'cancelled' );
	}

	/**
	 * WooCommerce Subscriptions: active, on hold, pending cancel, cancelled, expired.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $new_status   New status.
	 * @param string           $old_status   Old status.
	 * @return void
	 */
	public static function wcs_status( $subscription, $new_status, $old_status ) {
		unset( $old_status );
		if ( ! is_object( $subscription ) || ! is_callable( array( $subscription, 'get_id' ) ) ) {
			return;
		}
		$ref = 'wcs:' . (int) $subscription->get_id();
		switch ( $new_status ) {
			case 'active':
				$levels  = self::levels_for( 'woocommerce', self::wc_products( $subscription ) );
				$user_id = $levels ? self::wc_user( $subscription ) : 0;
				foreach ( $user_id ? array_keys( $levels ) : array() as $level_id ) {
					Members::grant( $user_id, $level_id, 'woocommerce', $ref, array( 'expires_at' => null ) );
				}
				break;
			case 'on-hold':
				Members::update_by_ref( 'woocommerce', $ref, array( 'status' => 'paused' ) );
				break;
			case 'pending-cancel':
				$end = is_callable( array( $subscription, 'get_time' ) ) ? (int) $subscription->get_time( 'end' ) : 0;
				if ( ! $end && is_callable( array( $subscription, 'get_time' ) ) ) {
					$end = (int) $subscription->get_time( 'next_payment' );
				}
				if ( $end > time() ) {
					Members::update_by_ref( 'woocommerce', $ref, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', $end ) ) );
				}
				break;
			case 'cancelled':
			case 'expired':
				Members::end_by_ref( 'woocommerce', $ref, 'cancelled' === $new_status ? 'cancelled' : 'expired' );
				break;
		}
	}

	/**
	 * An Aluleth Forms entry (after payment for a payment form).
	 *
	 * @param int   $entry_id   Entry id.
	 * @param array $form       Form.
	 * @param array $values     Values.
	 * @param int   $contact_id Contact id.
	 * @return void
	 */
	public static function form_entry( $entry_id, $form, $values, $contact_id ) {
		unset( $values );
		$form_id = is_array( $form ) && isset( $form['id'] ) ? (int) $form['id'] : 0;
		$levels  = array();
		foreach ( Levels::all() as $id => $level ) {
			if ( $form_id && in_array( $form_id, $level['forms'], true ) ) {
				$levels[] = $id;
			}
		}
		if ( ! $levels || ! $contact_id ) {
			return;
		}
		$contact = aluleth_core()->contacts()->get( (int) $contact_id );
		if ( ! $contact ) {
			return;
		}
		list( $user_id ) = Members::ensure_user( $contact->email, (string) $contact->first_name, (string) $contact->last_name );
		foreach ( $user_id ? $levels : array() as $level_id ) {
			Members::grant( $user_id, $level_id, 'form', 'entry:' . (int) $entry_id );
		}
	}

	/**
	 * Renewal and billing of one access, for the member area.
	 *
	 * @param object $row Access row.
	 * @return array{next: int, url: string, renews: bool}
	 */
	public static function billing( $row ) {
		global $wpdb;
		$out = array(
			'next'   => 0,
			'url'    => '',
			'renews' => false,
		);
		if ( 'fluentcart' === $row->source ) {
			$settings = get_option( 'fluent_cart_store_settings' );
			$page     = is_array( $settings ) && ! empty( $settings['customer_profile_page_id'] ) ? (int) $settings['customer_profile_page_id'] : 0;
			if ( $page && 'publish' === get_post_status( $page ) ) {
				$out['url'] = (string) get_permalink( $page );
			}
			if ( 0 === strpos( $row->source_ref, 'sub:' ) ) {
				$sub = $wpdb->get_row( $wpdb->prepare( 'SELECT status, next_billing_date FROM %i WHERE id = %d', $wpdb->prefix . 'fct_subscriptions', (int) substr( $row->source_ref, 4 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $sub && in_array( $sub->status, array( 'active', 'trialing' ), true ) && $sub->next_billing_date ) {
					$out['next']   = (int) strtotime( $sub->next_billing_date . ' UTC' );
					$out['renews'] = true;
				}
			}
		} elseif ( 'woocommerce' === $row->source && function_exists( 'wc_get_account_endpoint_url' ) ) {
			$out['url'] = (string) wc_get_account_endpoint_url( 'orders' );
			if ( 0 === strpos( $row->source_ref, 'wcs:' ) && function_exists( 'wcs_get_subscription' ) ) {
				$sub = wcs_get_subscription( (int) substr( $row->source_ref, 4 ) );
				if ( $sub ) {
					$out['url'] = (string) $sub->get_view_order_url();
					if ( $sub->has_status( 'active' ) ) {
						$out['next']   = (int) $sub->get_time( 'next_payment' );
						$out['renews'] = $out['next'] > 0;
					}
				}
			}
		}
		return $out;
	}
}
