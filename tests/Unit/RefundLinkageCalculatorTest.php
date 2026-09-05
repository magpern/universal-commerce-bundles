<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Engine\RefundLinkageCalculator;

/**
 * The formula: `child_refund_qty = (original_child_qty / original_parent_qty) ×
 * parent_qty_refunded` (ADR-0002, ADR-0003, spike S1-G) — including the
 * exact case spike S1-D's live evidence recorded: a 2-kit order refunding
 * 1 kit derives exactly 1 unit per component (2/2 × 1 = 1), not 2, not 0.
 */
final class RefundLinkageCalculatorTest extends TestCase {

	private RefundLinkageCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new RefundLinkageCalculator();
	}

	public function test_full_refund_of_a_single_kit(): void {
		self::assertSame( 1, $this->calculator->childRefundQty( 1, 1, 1 ) );
	}

	/**
	 * The exact case from spike S1-D §2.7's live evidence.
	 */
	public function test_partial_refund_of_two_kits_derives_exactly_one(): void {
		self::assertSame( 1, $this->calculator->childRefundQty( 2, 2, 1 ) );
	}

	public function test_qty_per_kit_greater_than_one_scales_correctly(): void {
		// 3 kits, qty_per_kit 4 => original_child_qty 12; refunding 1 kit.
		self::assertSame( 4, $this->calculator->childRefundQty( 12, 3, 1 ) );
	}

	public function test_refunding_every_kit_derives_the_full_child_quantity(): void {
		self::assertSame( 12, $this->calculator->childRefundQty( 12, 3, 3 ) );
	}

	public function test_zero_parent_qty_refunded_derives_zero(): void {
		self::assertSame( 0, $this->calculator->childRefundQty( 12, 3, 0 ) );
	}

	public function test_rejects_non_positive_original_parent_qty(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->calculator->childRefundQty( 1, 0, 1 );
	}
}
