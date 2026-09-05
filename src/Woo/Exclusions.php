<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * Cross-cutting cart/order-line exclusion contract (ADR-0007), UCB's own
 * in-scope pieces only: WooCommerce core coupon-eligibility validation and
 * WooCommerce core Analytics. Shipping weight/dimension/shipping-class
 * zeroing is handled by extending the existing price-zeroing cart-totals
 * hook (see CartConstruction::syncAndZeroChildren()), per the doc's own
 * guidance not to duplicate that hook. The promotions-plugin exclusion is
 * explicitly a separate repository's milestone — UCB exposes only the
 * `_ucb_component` data-contract marker for it to consume.
 */
final class Exclusions {

	/**
	 * The real recurring Action Scheduler batch action WooCommerce's own
	 * Analytics sync runs on (found live by spike S1-D).
	 */
	private const ANALYTICS_BATCH_HOOK = 'wc-admin_process_pending_orders_batch';

	public function register(): void {
		add_filter( 'woocommerce_coupon_get_items_to_validate', array( $this, 'excludeChildrenFromCouponValidation' ), 10, 2 );

		add_action( self::ANALYTICS_BATCH_HOOK, array( $this, 'enterAnalyticsScope' ), 5 );
		add_action( self::ANALYTICS_BATCH_HOOK, array( $this, 'exitAnalyticsScope' ), 20 );
		add_filter( 'woocommerce_order_get_items', array( $this, 'filterOrderGetItemsDuringAnalyticsSync' ), 10, 3 );
	}

	/**
	 * @param array<int, mixed> $items
	 */
	public function excludeChildrenFromCouponValidation( array $items, \WC_Discounts $discounts ): array {
		unset( $discounts );

		foreach ( $items as $key => $item ) {
			$cartItem = is_object( $item ) && isset( $item->object ) ? $item->object : null;

			if ( is_array( $cartItem ) && ! empty( $cartItem[ MetaKeys::LINE_COMPONENT ] ) ) {
				unset( $items[ $key ] );
			}
		}

		return $items;
	}

	public function enterAnalyticsScope(): void {
		$GLOBALS['ucb_analytics_sync_scope'] = true;
	}

	public function exitAnalyticsScope(): void {
		$GLOBALS['ucb_analytics_sync_scope'] = false;
	}

	/**
	 * @param \WC_Order_Item[] $items
	 * @param string|string[]  $type
	 * @return \WC_Order_Item[]
	 */
	public function filterOrderGetItemsDuringAnalyticsSync( array $items, \WC_Abstract_Order $order, $type ) {
		unset( $order, $type );

		if ( empty( $GLOBALS['ucb_analytics_sync_scope'] ) ) {
			return $items;
		}

		return array_filter(
			$items,
			static fn ( \WC_Order_Item $item ): bool => ! $item->get_meta( MetaKeys::LINE_COMPONENT, true )
		);
	}
}
