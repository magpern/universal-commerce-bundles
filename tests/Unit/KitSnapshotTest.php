<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Domain\KitSnapshot;

/**
 * The parent-line snapshot contract (ADR-0003) — build/serialize/
 * deserialize round-trips exactly, and historical component lookup by
 * stock-managed id works (used by the refund-linkage arithmetic).
 */
final class KitSnapshotTest extends TestCase {

	public function test_build_computes_qty_total_from_qty_per_kit_and_kit_qty(): void {
		$snapshot = KitSnapshot::build(
			10,
			'KIT-1',
			3,
			array(
				array(
					'stock_managed_id' => 1,
					'product_id'       => 1,
					'variation_id'     => 0,
					'sku'              => 'CMPA',
					'name'             => 'Component A',
					'qty_per_kit'      => 2,
				),
			)
		);

		self::assertSame( 6, $snapshot->components[0]['qty_total'] );
	}

	public function test_round_trips_through_array(): void {
		$snapshot = KitSnapshot::build(
			10,
			'KIT-1',
			2,
			array(
				array(
					'stock_managed_id' => 1,
					'product_id'       => 1,
					'variation_id'     => 0,
					'sku'              => 'CMPA',
					'name'             => 'Component A',
					'qty_per_kit'      => 1,
				),
			)
		);

		$roundTripped = KitSnapshot::fromArray( $snapshot->toArray() );

		self::assertEquals( $snapshot->toArray(), $roundTripped->toArray() );
	}

	public function test_find_component_by_stock_managed_id(): void {
		$snapshot = KitSnapshot::build(
			10,
			'KIT-1',
			1,
			array(
				array(
					'stock_managed_id' => 42,
					'product_id'       => 42,
					'variation_id'     => 0,
					'sku'              => 'CMPA',
					'name'             => 'Component A',
					'qty_per_kit'      => 1,
				),
			)
		);

		$found = $snapshot->findComponentByStockManagedId( 42 );
		self::assertNotNull( $found );
		self::assertSame( 'CMPA', $found['sku'] );

		self::assertNull( $snapshot->findComponentByStockManagedId( 999 ) );
	}

	public function test_from_array_tolerates_missing_keys(): void {
		$snapshot = KitSnapshot::fromArray( array() );

		self::assertSame( 0, $snapshot->kitId );
		self::assertSame( array(), $snapshot->components );
	}
}
