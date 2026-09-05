<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Domain;

/**
 * One row of a kit's composition: a stock-managed component product (or
 * variation), and how many units of it one unit of the kit requires.
 *
 * Pure value object — no WooCommerce symbols, no meta I/O. Deliberately
 * flat/JSON-friendly (see Composition::fromArray()/toArray()).
 */
final class ComponentRequirement {

	public function __construct(
		/**
		 * The id WooCommerce actually manages stock against for this
		 * component (a variation's *parent* product id when the variation
		 * itself does not manage its own stock — ADR-0002, "stock-managed
		 * id, not the raw product or variation id").
		 */
		public readonly int $stockManagedId,
		/**
		 * The product id as configured (may equal $stockManagedId, or be a
		 * variable product's parent when $variationId is set).
		 */
		public readonly int $productId,
		/**
		 * 0 when this component is a simple product, otherwise the
		 * specific variation id.
		 */
		public readonly int $variationId,
		/**
		 * How many units of this component one unit of the kit consumes.
		 * Integer only — ADR-0002 does not support fractional components.
		 */
		public readonly int $qtyPerKit,
	) {
	}

	/**
	 * @param array{stock_managed_id?: mixed, product_id?: mixed, variation_id?: mixed, qty_per_kit?: mixed} $data
	 */
	public static function fromArray( array $data ): self {
		return new self(
			(int) ( $data['stock_managed_id'] ?? 0 ),
			(int) ( $data['product_id'] ?? 0 ),
			(int) ( $data['variation_id'] ?? 0 ),
			(int) ( $data['qty_per_kit'] ?? 0 )
		);
	}

	/**
	 * @return array{stock_managed_id: int, product_id: int, variation_id: int, qty_per_kit: int}
	 */
	public function toArray(): array {
		return array(
			'stock_managed_id' => $this->stockManagedId,
			'product_id'       => $this->productId,
			'variation_id'     => $this->variationId,
			'qty_per_kit'      => $this->qtyPerKit,
		);
	}

	/**
	 * A component row is only structurally valid when it names a real
	 * product and a positive integer quantity — this is a *structural*
	 * check only (does the row make sense at all), never the live
	 * availability/purchasability decision, which belongs to
	 * Engine\AvailabilityCalculator and is always computed fresh.
	 */
	public function isStructurallyValid(): bool {
		return $this->productId > 0 && $this->stockManagedId > 0 && $this->qtyPerKit > 0;
	}

	/**
	 * Merge rule (ADR-0003): within one kit, the same component listed
	 * twice becomes one row with summed qty_per_kit.
	 */
	public function withAddedQuantity( int $additionalQtyPerKit ): self {
		return new self(
			$this->stockManagedId,
			$this->productId,
			$this->variationId,
			$this->qtyPerKit + $additionalQtyPerKit
		);
	}
}
