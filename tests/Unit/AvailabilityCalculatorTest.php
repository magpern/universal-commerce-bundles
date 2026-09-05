<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Engine\AvailabilityCalculator;
use UniversalCommerceBundles\Engine\ComponentAvailability;

/**
 * The derived-availability formula (docs/ARCHITECTURE.md, ADR-0002):
 * min(floor(component_available/qty_per_kit)), with backorder-enabled and
 * non-stock-managed components excluded from the minimum — matching the
 * acceptance-coverage list's specific required cases.
 */
final class AvailabilityCalculatorTest extends TestCase {

	private AvailabilityCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new AvailabilityCalculator();
	}

	public function test_no_components_means_zero_availability(): void {
		self::assertSame( 0, $this->calculator->calculate( array() ) );
	}

	public function test_single_managed_component_limits_by_floor_division(): void {
		$components = array(
			new ComponentAvailability( 1, 3, 10, true, false ), // floor(10/3) is 3.
		);

		self::assertSame( 3, $this->calculator->calculate( $components ) );
	}

	public function test_two_shared_components_take_the_minimum(): void {
		$components = array(
			new ComponentAvailability( 1, 1, 10, true, false ),
			new ComponentAvailability( 2, 2, 10, true, false ), // floor(10/2) is 5.
		);

		self::assertSame( 5, $this->calculator->calculate( $components ) );
	}

	public function test_qty_per_kit_greater_than_one_divides_correctly(): void {
		$components = array(
			new ComponentAvailability( 1, 4, 21, true, false ), // floor(21/4) is 5.
		);

		self::assertSame( 5, $this->calculator->calculate( $components ) );
	}

	/**
	 * "Backordered component alongside a fully available component -> kit
	 * purchasable" (docs/ARCHITECTURE.md, acceptance coverage).
	 */
	public function test_backordered_component_alongside_available_component_is_purchasable(): void {
		$components = array(
			new ComponentAvailability( 1, 1, 5, true, false ),
			new ComponentAvailability( 2, 1, 0, true, true ), // Backorder-enabled, excluded.
		);

		self::assertSame( 5, $this->calculator->calculate( $components ) );
		self::assertTrue( $this->calculator->isPurchasable( $components ) );
	}

	/**
	 * "Backordered component alongside an unavailable component -> kit not
	 * purchasable" (docs/ARCHITECTURE.md, acceptance coverage).
	 */
	public function test_backordered_component_alongside_unavailable_component_is_not_purchasable(): void {
		$components = array(
			new ComponentAvailability( 1, 1, 0, true, false ),
			new ComponentAvailability( 2, 1, 0, true, true ),
		);

		self::assertSame( 0, $this->calculator->calculate( $components ) );
		self::assertFalse( $this->calculator->isPurchasable( $components ) );
	}

	/**
	 * "Non-stock-managed component: available, never reduced, never
	 * restored" — never constrains kit availability.
	 */
	public function test_non_stock_managed_component_never_constrains_availability(): void {
		$components = array(
			new ComponentAvailability( 1, 2, 7, true, false ), // floor(7/2) is 3.
			new ComponentAvailability( 2, 1, 0, false, false ), // Unmanaged.
		);

		self::assertSame( 3, $this->calculator->calculate( $components ) );
	}

	public function test_all_components_excluded_from_minimum_means_unlimited(): void {
		$components = array(
			new ComponentAvailability( 1, 1, 0, false, false ),
			new ComponentAvailability( 2, 1, 0, true, true ),
		);

		self::assertSame( PHP_INT_MAX, $this->calculator->calculate( $components ) );
		self::assertTrue( $this->calculator->isPurchasable( $components ) );
	}

	public function test_zero_available_stock_is_not_purchasable(): void {
		$components = array(
			new ComponentAvailability( 1, 1, 0, true, false ),
		);

		self::assertFalse( $this->calculator->isPurchasable( $components ) );
	}

	public function test_component_availability_reports_excluded_from_minimum_correctly(): void {
		$backordered = new ComponentAvailability( 1, 1, 0, true, true );
		$unmanaged   = new ComponentAvailability( 2, 1, 0, false, false );
		$ordinary    = new ComponentAvailability( 3, 1, 5, true, false );

		self::assertTrue( $backordered->isExcludedFromMinimum() );
		self::assertTrue( $unmanaged->isExcludedFromMinimum() );
		self::assertFalse( $ordinary->isExcludedFromMinimum() );
		self::assertNull( $backordered->possibleKits() );
		self::assertNull( $unmanaged->possibleKits() );
		self::assertSame( 5, $ordinary->possibleKits() );
	}
}
