<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Domain\Composition;
use UniversalCommerceBundles\Engine\ComponentState;
use UniversalCommerceBundles\Engine\CompositionValidator;

/**
 * "Invalid composition — missing or deleted component, or mixed tax
 * classes — makes the kit non-purchasable until corrected."
 * (docs/ARCHITECTURE.md)
 */
final class CompositionValidatorTest extends TestCase {

	private CompositionValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new CompositionValidator();
	}

	private function composition(): Composition {
		return Composition::fromRows(
			array(
				array(
					'stock_managed_id' => 1,
					'product_id'       => 1,
					'variation_id'     => 0,
					'qty_per_kit'      => 1,
				),
				array(
					'stock_managed_id' => 2,
					'product_id'       => 2,
					'variation_id'     => 0,
					'qty_per_kit'      => 1,
				),
			)
		);
	}

	public function test_empty_composition_is_structurally_invalid(): void {
		$result = $this->validator->validate( Composition::fromRows( array() ), array() );

		self::assertFalse( $result->valid );
		self::assertTrue( $result->structurallyInvalid );
	}

	public function test_all_components_existing_published_same_tax_class_is_valid(): void {
		$states = array(
			new ComponentState( 1, true, true, 'standard' ),
			new ComponentState( 2, true, true, 'standard' ),
		);

		$result = $this->validator->validate( $this->composition(), $states );

		self::assertTrue( $result->valid );
	}

	public function test_missing_component_is_invalid(): void {
		$states = array(
			new ComponentState( 1, true, true, 'standard' ),
			// Component 2 not represented at all -> missing.
		);

		$result = $this->validator->validate( $this->composition(), $states );

		self::assertFalse( $result->valid );
		self::assertSame( array( 2 ), $result->missingComponentIds );
	}

	public function test_deleted_component_is_invalid(): void {
		$states = array(
			new ComponentState( 1, true, true, 'standard' ),
			new ComponentState( 2, false, false, '' ),
		);

		$result = $this->validator->validate( $this->composition(), $states );

		self::assertFalse( $result->valid );
		self::assertSame( array( 2 ), $result->missingComponentIds );
	}

	public function test_unpublished_component_is_invalid(): void {
		$states = array(
			new ComponentState( 1, true, true, 'standard' ),
			new ComponentState( 2, true, false, 'standard' ),
		);

		$result = $this->validator->validate( $this->composition(), $states );

		self::assertFalse( $result->valid );
		self::assertSame( array( 2 ), $result->unpublishedComponentIds );
	}

	public function test_mixed_tax_classes_are_invalid(): void {
		$states = array(
			new ComponentState( 1, true, true, 'standard' ),
			new ComponentState( 2, true, true, 'reduced-rate' ),
		);

		$result = $this->validator->validate( $this->composition(), $states );

		self::assertFalse( $result->valid );
		self::assertSame( array( 'standard', 'reduced-rate' ), $result->mixedTaxClasses );
	}
}
