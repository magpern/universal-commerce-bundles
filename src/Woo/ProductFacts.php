<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Domain\ComponentRequirement;
use UniversalCommerceBundles\Engine\ComponentAvailability;
use UniversalCommerceBundles\Engine\ComponentState;

/**
 * The only place real WC_Product / core reservation facts are read for a
 * component. Everything this returns is a plain value object the Engine
 * layer can reason about without ever touching a WooCommerce symbol
 * itself (see docs/ARCHITECTURE.md's WooCommerce confinement rule).
 */
final class ProductFacts {

	/**
	 * @param int $excludeOrderId Excluded from the reservation query —
	 *     e.g. the order currently being validated/re-validated, so its
	 *     own existing reservation doesn't count against itself.
	 */
	public function availabilityFor( ComponentRequirement $component, int $excludeOrderId = 0 ): ComponentAvailability {
		$product = wc_get_product( $component->stockManagedId );

		if ( ! $product instanceof \WC_Product ) {
			return new ComponentAvailability(
				$component->stockManagedId,
				$component->qtyPerKit,
				0,
				false,
				false
			);
		}

		$managesStock = $product->managing_stock();
		$backorders   = $product->backorders_allowed();

		if ( ! $managesStock ) {
			return new ComponentAvailability(
				$component->stockManagedId,
				$component->qtyPerKit,
				0,
				false,
				$backorders
			);
		}

		$stock    = (int) $product->get_stock_quantity();
		$reserved = ( new \Automattic\WooCommerce\Checkout\Helpers\ReserveStock() )
			->get_reserved_stock( $product, $excludeOrderId );

		return new ComponentAvailability(
			$component->stockManagedId,
			$component->qtyPerKit,
			$stock - $reserved,
			true,
			$backorders
		);
	}

	public function stateFor( int $stockManagedId ): ComponentState {
		$product = wc_get_product( $stockManagedId );

		if ( ! $product instanceof \WC_Product ) {
			return new ComponentState( $stockManagedId, false, false, '' );
		}

		$status = get_post_status( $product->get_id() );

		return new ComponentState(
			$stockManagedId,
			true,
			'publish' === $status,
			$product->get_tax_class()
		);
	}

	/**
	 * Resolves the id WooCommerce actually manages stock against for a
	 * product/variation pair — a variation whose stock is parent-managed
	 * resolves to the *parent* product's id (ADR-0002).
	 */
	public function stockManagedIdFor( int $productId, int $variationId = 0 ): int {
		$product = wc_get_product( $variationId > 0 ? $variationId : $productId );

		if ( ! $product instanceof \WC_Product ) {
			return $variationId > 0 ? $variationId : $productId;
		}

		if ( $product->managing_stock() ) {
			return $product->get_id();
		}

		$parentId = $product instanceof \WC_Product_Variation ? $product->get_parent_id() : 0;

		return $parentId > 0 ? $parentId : $product->get_id();
	}
}
