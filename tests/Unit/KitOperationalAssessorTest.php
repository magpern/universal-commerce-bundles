<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Engine\ComponentAvailability;
use UniversalCommerceBundles\Engine\KitOperationalAssessor;
use UniversalCommerceBundles\Engine\ValidationResult;

/**
 * M2 dual-field assessment: catalog exposure × operational sellability
 * (docs/m2-admin-usability-plan.md).
 */
final class KitOperationalAssessorTest extends TestCase {

	private KitOperationalAssessor $assessor;

	protected function setUp(): void {
		parent::setUp();
		$this->assessor = new KitOperationalAssessor();
	}

	public function test_published_visible_is_published_exposure(): void {
		$exposure = $this->assessor->assessCatalogExposure( 'publish', 'visible' );

		self::assertSame( KitOperationalAssessor::EXPOSURE_PUBLISHED, $exposure->code );
	}

	public function test_publish_hidden_is_catalog_hidden(): void {
		$exposure = $this->assessor->assessCatalogExposure( 'publish', 'hidden' );

		self::assertSame( KitOperationalAssessor::EXPOSURE_CATALOG_HIDDEN, $exposure->code );
	}

	public function test_draft_private_pending_exposure_independent_of_visibility(): void {
		self::assertSame(
			KitOperationalAssessor::EXPOSURE_DRAFT,
			$this->assessor->assessCatalogExposure( 'draft', 'visible' )->code
		);
		self::assertSame(
			KitOperationalAssessor::EXPOSURE_PRIVATE,
			$this->assessor->assessCatalogExposure( 'private', 'visible' )->code
		);
		self::assertSame(
			KitOperationalAssessor::EXPOSURE_PENDING,
			$this->assessor->assessCatalogExposure( 'pending', 'hidden' )->code
		);
	}

	public function test_other_status_uses_raw_slug(): void {
		$exposure = $this->assessor->assessCatalogExposure( 'trash', 'visible' );

		self::assertSame( KitOperationalAssessor::EXPOSURE_OTHER, $exposure->code );
		self::assertSame( 'trash', $exposure->rawStatus );
	}

	public function test_catalog_hidden_does_not_force_blocked_sellability(): void {
		// Exposure is independent: a healthy kit can be Catalog-hidden · Active.
		$sellability = $this->assessor->assessSellability(
			false,
			ValidationResult::valid(),
			3
		);

		self::assertSame( KitOperationalAssessor::SELLABILITY_ACTIVE, $sellability->code );
		self::assertNull( $sellability->blockedReasonCode );
	}

	public function test_safety_lock_blocks_first(): void {
		$sellability = $this->assessor->assessSellability(
			true,
			ValidationResult::structurallyInvalid(),
			0
		);

		self::assertSame( KitOperationalAssessor::SELLABILITY_BLOCKED, $sellability->code );
		self::assertSame( KitOperationalAssessor::REASON_SAFETY_LOCK, $sellability->blockedReasonCode );
	}

	public function test_structurally_invalid_reason(): void {
		$sellability = $this->assessor->assessSellability(
			false,
			ValidationResult::structurallyInvalid(),
			PHP_INT_MAX
		);

		self::assertSame( KitOperationalAssessor::REASON_INVALID_CONFIGURATION, $sellability->blockedReasonCode );
	}

	public function test_missing_component_reason_precedes_unpublished(): void {
		$sellability = $this->assessor->assessSellability(
			false,
			ValidationResult::invalid( array( 9 ), array( 8 ), array() ),
			0
		);

		self::assertSame( KitOperationalAssessor::REASON_MISSING_COMPONENT, $sellability->blockedReasonCode );
	}

	public function test_unpublished_component_reason(): void {
		$sellability = $this->assessor->assessSellability(
			false,
			ValidationResult::invalid( array(), array( 8 ), array() ),
			0
		);

		self::assertSame( KitOperationalAssessor::REASON_COMPONENT_NOT_PURCHASABLE, $sellability->blockedReasonCode );
	}

	public function test_mixed_tax_is_invalid_configuration(): void {
		$sellability = $this->assessor->assessSellability(
			false,
			ValidationResult::invalid( array(), array(), array( '', 'reduced-rate' ) ),
			0
		);

		self::assertSame( KitOperationalAssessor::REASON_INVALID_CONFIGURATION, $sellability->blockedReasonCode );
	}

	public function test_insufficient_stock_reason(): void {
		$sellability = $this->assessor->assessSellability(
			false,
			ValidationResult::valid(),
			0
		);

		self::assertSame( KitOperationalAssessor::SELLABILITY_BLOCKED, $sellability->code );
		self::assertSame(
			KitOperationalAssessor::REASON_INSUFFICIENT_COMPONENT_STOCK,
			$sellability->blockedReasonCode
		);
	}

	public function test_backorder_supported_when_unconstrained(): void {
		$sellability = $this->assessor->assessSellability(
			false,
			ValidationResult::valid(),
			PHP_INT_MAX
		);

		self::assertSame( KitOperationalAssessor::SELLABILITY_BACKORDER_SUPPORTED, $sellability->code );
		self::assertNull( $sellability->blockedReasonCode );
	}

	public function test_active_when_finite_positive_quantity(): void {
		$sellability = $this->assessor->assessSellability(
			false,
			ValidationResult::valid(),
			2
		);

		self::assertSame( KitOperationalAssessor::SELLABILITY_ACTIVE, $sellability->code );
	}

	public function test_availability_calculator_backorder_exclusion_feeds_assessor(): void {
		// Mirror acceptance: backorder-enabled component alone does not block;
		// unconstrained min → PHP_INT_MAX → Backorder-supported.
		$calc = new \UniversalCommerceBundles\Engine\AvailabilityCalculator();
		$qty  = $calc->calculate(
			array(
				new ComponentAvailability( 1, 1, 0, true, true ),
				new ComponentAvailability( 2, 1, 0, false, false ),
			)
		);

		self::assertSame( PHP_INT_MAX, $qty );
		self::assertSame(
			KitOperationalAssessor::SELLABILITY_BACKORDER_SUPPORTED,
			$this->assessor->assessSellability( false, ValidationResult::valid(), $qty )->code
		);
	}
}
