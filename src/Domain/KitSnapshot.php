<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Domain;

/**
 * The parent-line snapshot contract (ADR-0003): written once, at checkout,
 * on the parent cart/order line; historical orders render and are diffed
 * only from this, never from the current, possibly-changed product
 * definition.
 *
 * Pure value object — no WooCommerce symbols, no meta I/O.
 */
final class KitSnapshot {

	/**
	 * @param array<int, array{stock_managed_id: int, product_id: int, variation_id: int, sku: string, name: string, qty_per_kit: int, qty_total: int}> $components
	 */
	private function __construct(
		public readonly int $version,
		public readonly int $kitId,
		public readonly string $kitSku,
		public readonly int $kitQty,
		public readonly array $components,
	) {
	}

	/**
	 * @param array<int, array{stock_managed_id: int, product_id: int, variation_id: int, sku: string, name: string, qty_per_kit: int}> $componentDetails
	 *     One row per component, without qty_total — this computes it as
	 *     qty_per_kit * $kitQty, matching the documented snapshot shape.
	 */
	public static function build( int $kitId, string $kitSku, int $kitQty, array $componentDetails ): self {
		$components = array_map(
			static function ( array $component ) use ( $kitQty ): array {
				$qtyPerKit = (int) ( $component['qty_per_kit'] ?? 0 );

				return array(
					'stock_managed_id' => (int) ( $component['stock_managed_id'] ?? 0 ),
					'product_id'       => (int) ( $component['product_id'] ?? 0 ),
					'variation_id'     => (int) ( $component['variation_id'] ?? 0 ),
					'sku'              => (string) ( $component['sku'] ?? '' ),
					'name'             => (string) ( $component['name'] ?? '' ),
					'qty_per_kit'      => $qtyPerKit,
					'qty_total'        => $qtyPerKit * $kitQty,
				);
			},
			$componentDetails
		);

		return new self( MetaKeys::SNAPSHOT_VERSION, $kitId, $kitSku, $kitQty, $components );
	}

	/**
	 * @param array{v?: mixed, kit_id?: mixed, kit_sku?: mixed, kit_qty?: mixed, components?: mixed} $data
	 */
	public static function fromArray( array $data ): self {
		$components = array();

		foreach ( (array) ( $data['components'] ?? array() ) as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			$components[] = array(
				'stock_managed_id' => (int) ( $component['stock_managed_id'] ?? 0 ),
				'product_id'       => (int) ( $component['product_id'] ?? 0 ),
				'variation_id'     => (int) ( $component['variation_id'] ?? 0 ),
				'sku'              => (string) ( $component['sku'] ?? '' ),
				'name'             => (string) ( $component['name'] ?? '' ),
				'qty_per_kit'      => (int) ( $component['qty_per_kit'] ?? 0 ),
				'qty_total'        => (int) ( $component['qty_total'] ?? 0 ),
			);
		}

		return new self(
			(int) ( $data['v'] ?? 0 ),
			(int) ( $data['kit_id'] ?? 0 ),
			(string) ( $data['kit_sku'] ?? '' ),
			(int) ( $data['kit_qty'] ?? 0 ),
			$components
		);
	}

	/**
	 * @return array{v: int, kit_id: int, kit_sku: string, kit_qty: int, components: array<int, array{stock_managed_id: int, product_id: int, variation_id: int, sku: string, name: string, qty_per_kit: int, qty_total: int}>}
	 */
	public function toArray(): array {
		return array(
			'v'          => $this->version,
			'kit_id'     => $this->kitId,
			'kit_sku'    => $this->kitSku,
			'kit_qty'    => $this->kitQty,
			'components' => $this->components,
		);
	}

	/**
	 * Finds a component row by its stock-managed id, for refund-linkage
	 * arithmetic (ADR-0002) — returns null when not found (e.g. a
	 * since-deleted component, historical orders still render from this
	 * snapshot regardless).
	 *
	 * @return array{stock_managed_id: int, product_id: int, variation_id: int, sku: string, name: string, qty_per_kit: int, qty_total: int}|null
	 */
	public function findComponentByStockManagedId( int $stockManagedId ): ?array {
		foreach ( $this->components as $component ) {
			if ( $component['stock_managed_id'] === $stockManagedId ) {
				return $component;
			}
		}

		return null;
	}
}
