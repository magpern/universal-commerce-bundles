<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * The refund-linkage arithmetic (ADR-0002, ADR-0003, spike S1-G):
 * `child_refund_qty = (original_child_qty / original_parent_qty) ×
 * parent_qty_refunded`.
 *
 * Pure computation, no WooCommerce symbols. The Woo\Refunds adapter reads
 * the real original/refunded quantities from the order/snapshot and calls
 * this; this class does not know what a refund, an order, or a line item
 * is.
 */
final class RefundLinkageCalculator {

	/**
	 * @throws \InvalidArgumentException When $originalParentQty is not positive
	 *     (division by zero is never a valid state to compute from).
	 */
	public function childRefundQty( int $originalChildQty, int $originalParentQty, int $parentQtyRefunded ): int {
		if ( $originalParentQty <= 0 ) {
			throw new \InvalidArgumentException( 'originalParentQty must be positive.' );
		}

		$exact = ( $originalChildQty / $originalParentQty ) * $parentQtyRefunded;

		// original_child_qty is always qty_per_kit * original_parent_qty
		// (see Domain\KitSnapshot), so this division is always exact in
		// practice; round() only guards against float noise, never masks
		// a real non-integer relationship.
		return (int) round( $exact );
	}
}
