<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Domain\KitSnapshot;
use UniversalCommerceBundles\Domain\MetaKeys;
use UniversalCommerceBundles\Engine\RefundLinkageCalculator;

/**
 * Native-refund-only linkage (ADR-0002, ADR-0003, spike S1-G — corrected
 * hook split). Two real, documented WooCommerce actions, not one:
 *
 * 1. `woocommerce_create_refund` fires on the fully-built, not-yet-saved
 *    refund object, before WooCommerce's own save and before its own
 *    restock call. Adds the derived, zero-total child refund lines only.
 *    No stock mutation happens here.
 * 2. `woocommerce_refund_created` fires only after that save has already
 *    succeeded, after WooCommerce's own restock call for whatever line
 *    items the caller supplied, and after the order's status update. Only
 *    if restocking was requested, re-reads the now-persisted refund's own
 *    line items, keeps the ones tagged in step 1, and calls WooCommerce's
 *    own exported restock function for exactly those derived quantities.
 *
 * UCB does not own refund creation, refund persistence, gateway refunds,
 * retries, duplicate-submission handling, concurrency control,
 * transactions, journals, locks, or recovery sweeps — see ADR-0002.
 */
final class Refunds {

	public function __construct(
		private readonly RefundLinkageCalculator $linkage,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_create_refund', array( $this, 'addDerivedChildRefundLines' ), 10, 2 );
		add_action( 'woocommerce_refund_created', array( $this, 'restockDerivedChildLines' ), 10, 2 );
	}

	/**
	 * @param array{order_id?: mixed, line_items?: mixed} $args
	 */
	public function addDerivedChildRefundLines( \WC_Order_Refund $refund, array $args ): void {
		$orderId = (int) ( $args['order_id'] ?? 0 );
		$order   = $orderId > 0 ? wc_get_order( $orderId ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$refundedLineItems = is_array( $args['line_items'] ?? null ) ? $args['line_items'] : array();

		foreach ( $refundedLineItems as $parentItemId => $refundedLine ) {
			$parentItemId = (int) $parentItemId;
			$parentItem   = $order->get_item( $parentItemId );

			if ( ! $parentItem instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$snapshot = $this->snapshotFor( $parentItem );

			if ( null === $snapshot ) {
				continue; // Not a kit-parent line — an ordinary refund is untouched.
			}

			$parentQtyRefunded = (int) ( $refundedLine['qty'] ?? 0 );

			if ( $parentQtyRefunded <= 0 ) {
				continue;
			}

			$originalParentQty = (int) $parentItem->get_quantity();

			foreach ( $this->childrenOf( $order, $parentItemId ) as $childItem ) {
				$originalChildQty = (int) $childItem->get_quantity();
				$refundQty        = $this->linkage->childRefundQty( $originalChildQty, $originalParentQty, $parentQtyRefunded );

				if ( $refundQty <= 0 ) {
					continue;
				}

				$refundItem = new \WC_Order_Item_Product();
				$refundItem->set_props(
					array(
						'product_id'   => $childItem->get_product_id(),
						'variation_id' => $childItem->get_variation_id(),
						'quantity'     => $refundQty * -1,
						'subtotal'     => 0,
						'total'        => 0,
						'name'         => $childItem->get_name(),
					)
				);
				$refundItem->add_meta_data( MetaKeys::REFUND_LINE_DERIVED, (string) $childItem->get_id(), true );
				$refundItem->add_meta_data( MetaKeys::LINE_COMPONENT, '1', true );
				$refundItem->add_meta_data( MetaKeys::LINE_PARENT_ITEM_ID, (string) $parentItemId, true );

				$refund->add_item( $refundItem );
			}
		}
	}

	/**
	 * @param int                                            $refundId
	 * @param array{order_id?: mixed, restock_items?: mixed} $args
	 */
	public function restockDerivedChildLines( int $refundId, array $args ): void {
		if ( empty( $args['restock_items'] ) ) {
			return;
		}

		$orderId = (int) ( $args['order_id'] ?? 0 );
		$order   = $orderId > 0 ? wc_get_order( $orderId ) : false;
		$refund  = wc_get_order( $refundId );

		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$lineItems = array();

		foreach ( $refund->get_items() as $refundLineItem ) {
			$originalChildItemId = $refundLineItem->get_meta( MetaKeys::REFUND_LINE_DERIVED, true );

			if ( '' === $originalChildItemId ) {
				continue;
			}

			$qty = abs( (int) $refundLineItem->get_quantity() );

			if ( $qty <= 0 ) {
				continue;
			}

			$lineItems[ (int) $originalChildItemId ] = array( 'qty' => $qty );
		}

		if ( array() === $lineItems ) {
			return;
		}

		wc_restock_refunded_items( $order, $lineItems );
	}

	/**
	 * @return \WC_Order_Item_Product[]
	 */
	private function childrenOf( \WC_Order $order, int $parentItemId ): array {
		$children = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			if ( (int) $item->get_meta( MetaKeys::LINE_PARENT_ITEM_ID, true ) === $parentItemId ) {
				$children[] = $item;
			}
		}

		return $children;
	}

	private function snapshotFor( \WC_Order_Item_Product $item ): ?KitSnapshot {
		$raw = $item->get_meta( MetaKeys::LINE_KIT_SNAPSHOT, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return KitSnapshot::fromArray( $decoded );
	}
}
