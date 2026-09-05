<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * The derived-availability formula (docs/ARCHITECTURE.md, ADR-0002),
 * `min( floor( component_available / qty_per_kit ) )` across components,
 * where a backorder-enabled or non-stock-managed
 * component is *excluded* from the minimum rather than treated as
 * satisfying it — every other required component must still satisfy its
 * own requirement.
 *
 * Pure computation, no WooCommerce symbols, no meta I/O — always computed
 * fresh from live facts the caller supplies (never trusted from a cached
 * hint, per the doc's "Validity is computed live at the decision point").
 */
final class AvailabilityCalculator {

	/**
	 * @param ComponentAvailability[] $components
	 */
	public function calculate( array $components ): int {
		if ( array() === $components ) {
			return 0;
		}

		$limits = array();

		foreach ( $components as $component ) {
			$possibleKits = $component->possibleKits();

			if ( null === $possibleKits ) {
				continue;
			}

			$limits[] = $possibleKits;
		}

		if ( array() === $limits ) {
			// Every component is excluded from the minimum (all backorder-
			// enabled or unmanaged) — nothing constrains kit availability.
			return PHP_INT_MAX;
		}

		return min( $limits );
	}

	/**
	 * @param ComponentAvailability[] $components
	 */
	public function isPurchasable( array $components ): bool {
		return $this->calculate( $components ) > 0;
	}
}
