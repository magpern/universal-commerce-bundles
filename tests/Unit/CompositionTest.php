<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Domain\Composition;

/**
 * "Merge rule: within one kit line, a component listed twice in
 * composition becomes one child line with summed per-kit quantity."
 * (docs/ARCHITECTURE.md, ADR-0003)
 */
final class CompositionTest extends TestCase {

	public function test_duplicate_component_rows_merge_with_summed_quantity(): void {
		$composition = Composition::fromRows(
			array(
				array(
					'stock_managed_id' => 5,
					'product_id'       => 5,
					'variation_id'     => 0,
					'qty_per_kit'      => 1,
				),
				array(
					'stock_managed_id' => 5,
					'product_id'       => 5,
					'variation_id'     => 0,
					'qty_per_kit'      => 2,
				),
			)
		);

		self::assertCount( 1, $composition->components );
		self::assertSame( 3, $composition->components[0]->qtyPerKit );
	}

	public function test_distinct_components_are_kept_separate_in_order(): void {
		$composition = Composition::fromRows(
			array(
				array(
					'stock_managed_id' => 5,
					'product_id'       => 5,
					'variation_id'     => 0,
					'qty_per_kit'      => 1,
				),
				array(
					'stock_managed_id' => 6,
					'product_id'       => 6,
					'variation_id'     => 0,
					'qty_per_kit'      => 2,
				),
			)
		);

		self::assertCount( 2, $composition->components );
		self::assertSame( 5, $composition->components[0]->stockManagedId );
		self::assertSame( 6, $composition->components[1]->stockManagedId );
	}

	public function test_empty_composition_is_empty_and_structurally_invalid(): void {
		$composition = Composition::fromRows( array() );

		self::assertTrue( $composition->isEmpty() );
		self::assertFalse( $composition->isStructurallyValid() );
	}

	public function test_a_row_with_zero_quantity_is_structurally_invalid(): void {
		$composition = Composition::fromRows(
			array(
				array(
					'stock_managed_id' => 5,
					'product_id'       => 5,
					'variation_id'     => 0,
					'qty_per_kit'      => 0,
				),
			)
		);

		self::assertFalse( $composition->isStructurallyValid() );
	}

	public function test_round_trips_through_to_array(): void {
		$rows = array(
			array(
				'stock_managed_id' => 5,
				'product_id'       => 5,
				'variation_id'     => 0,
				'qty_per_kit'      => 2,
			),
		);

		$composition = Composition::fromRows( $rows );

		self::assertSame( $rows, $composition->toArray() );
	}

	public function test_stock_managed_ids_are_unique_and_in_order(): void {
		$composition = Composition::fromRows(
			array(
				array(
					'stock_managed_id' => 5,
					'product_id'       => 5,
					'variation_id'     => 0,
					'qty_per_kit'      => 1,
				),
				array(
					'stock_managed_id' => 6,
					'product_id'       => 6,
					'variation_id'     => 0,
					'qty_per_kit'      => 1,
				),
			)
		);

		self::assertSame( array( 5, 6 ), $composition->stockManagedIds() );
	}
}
