<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * A single component's real, live availability facts, exactly as needed by
 * AvailabilityCalculator's formula (docs/ARCHITECTURE.md, "Derived
 * availability and invalidation") — deliberately just primitives, so the
 * calculator itself never needs a real product object or any other
 * WooCommerce symbol. The Woo adapter that populates this is where the
 * real product object, stock reservation lookup, etc. are actually
 * touched.
 */
final class ComponentAvailability {

	public function __construct(
		public readonly int $stockManagedId,
		public readonly int $qtyPerKit,
		/**
		 * Stock currently on hand minus existing reservations, for the
		 * stock-managed id (ADR-0002: "accounts for the component's own
		 * stock and existing reservations"). Meaningless when
		 * $managesStock is false.
		 */
		public readonly int $available,
		public readonly bool $managesStock,
		public readonly bool $backordersAllowed,
	) {
	}

	/**
	 * Per core's own convention (ADR-0002): a non-stock-managed component
	 * is always available and never constrains kit availability.
	 */
	public function isExcludedFromMinimum(): bool {
		return ! $this->managesStock || $this->backordersAllowed;
	}

	/**
	 * How many whole kits this component alone could supply, or null when
	 * this component is excluded from the minimum (backorder-enabled or
	 * unmanaged) and therefore never constrains kit availability.
	 */
	public function possibleKits(): ?int {
		if ( $this->isExcludedFromMinimum() ) {
			return null;
		}

		if ( $this->qtyPerKit <= 0 ) {
			return 0;
		}

		return intdiv( max( 0, $this->available ), $this->qtyPerKit );
	}
}
