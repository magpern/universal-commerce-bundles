<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Domain;

/**
 * A kit's full composition: an ordered list of component requirements.
 *
 * Pure value object — no WooCommerce symbols, no meta I/O, no live stock
 * knowledge. Structural merge/validation only; live availability is
 * Engine\AvailabilityCalculator's job, and persistence is
 * Application\CompositionRepository's job (see docs/ARCHITECTURE.md,
 * "Derived availability and invalidation").
 */
final class Composition {

	/**
	 * @param ComponentRequirement[] $components Already merge-deduplicated
	 *     (see fromRows()) and in stable, caller-provided order.
	 */
	public function __construct(
		public readonly array $components,
	) {
	}

	/**
	 * Builds a Composition from raw component rows, applying the merge
	 * rule (ADR-0003): a component listed twice becomes one row with
	 * summed qty_per_kit, keeping the position of its first occurrence.
	 *
	 * @param array<int, array{stock_managed_id?: mixed, product_id?: mixed, variation_id?: mixed, qty_per_kit?: mixed}> $rows
	 */
	public static function fromRows( array $rows ): self {
		/** @var array<int, ComponentRequirement> $merged keyed by stock_managed_id, insertion-ordered */
		$merged = array();

		foreach ( $rows as $row ) {
			$requirement = ComponentRequirement::fromArray( $row );

			if ( isset( $merged[ $requirement->stockManagedId ] ) ) {
				$merged[ $requirement->stockManagedId ] = $merged[ $requirement->stockManagedId ]
					->withAddedQuantity( $requirement->qtyPerKit );

				continue;
			}

			$merged[ $requirement->stockManagedId ] = $requirement;
		}

		return new self( array_values( $merged ) );
	}

	/**
	 * @return array<int, array{stock_managed_id: int, product_id: int, variation_id: int, qty_per_kit: int}>
	 */
	public function toArray(): array {
		return array_map(
			static fn ( ComponentRequirement $component ): array => $component->toArray(),
			$this->components
		);
	}

	public function isEmpty(): bool {
		return array() === $this->components;
	}

	/**
	 * Structural validity only (docs/ARCHITECTURE.md: "Invalid composition
	 * — missing or deleted component, or mixed tax classes — makes the kit
	 * non-purchasable"). This checks the *shape* of the stored data; it
	 * does not check whether the referenced products still exist, are
	 * published, or share a tax class — those checks need live product
	 * data and live in Engine\CompositionValidator, which this plugin's
	 * Woo adapter feeds with real product lookups.
	 */
	public function isStructurallyValid(): bool {
		if ( $this->isEmpty() ) {
			return false;
		}

		foreach ( $this->components as $component ) {
			if ( ! $component->isStructurallyValid() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return int[] every distinct stock-managed id this composition
	 *     requires, used to build/maintain the component => kits reverse
	 *     index (docs/ARCHITECTURE.md, "Invalidation and reverse lookup").
	 */
	public function stockManagedIds(): array {
		return array_values(
			array_unique(
				array_map(
					static fn ( ComponentRequirement $component ): int => $component->stockManagedId,
					$this->components
				)
			)
		);
	}
}
