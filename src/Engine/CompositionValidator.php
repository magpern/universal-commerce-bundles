<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

use UniversalCommerceBundles\Domain\Composition;

/**
 * "Invalid composition — missing or deleted component, or mixed tax
 * classes — makes the kit non-purchasable until corrected. A warning
 * alone is insufficient." (docs/ARCHITECTURE.md)
 *
 * Pure computation, no WooCommerce symbols: the caller (a Woo adapter)
 * supplies real, live ComponentState facts; this class only reasons about
 * them.
 */
final class CompositionValidator {

	/**
	 * @param ComponentState[] $states One entry per distinct stock-managed
	 *     id in $composition, in any order. A stock-managed id in
	 *     $composition with no matching entry here is treated as missing.
	 */
	public function validate( Composition $composition, array $states ): ValidationResult {
		if ( ! $composition->isStructurallyValid() ) {
			return ValidationResult::structurallyInvalid();
		}

		$statesById = array();

		foreach ( $states as $state ) {
			$statesById[ $state->stockManagedId ] = $state;
		}

		$missing        = array();
		$unpublished    = array();
		$taxClassesSeen = array();

		foreach ( $composition->stockManagedIds() as $stockManagedId ) {
			$state = $statesById[ $stockManagedId ] ?? null;

			if ( null === $state || ! $state->exists ) {
				$missing[] = $stockManagedId;

				continue;
			}

			if ( ! $state->published ) {
				$unpublished[] = $stockManagedId;
			}

			$taxClassesSeen[ $state->taxClass ] = true;
		}

		$mixedTaxClasses = array_keys( $taxClassesSeen );

		if ( array() === $missing && array() === $unpublished && count( $mixedTaxClasses ) <= 1 ) {
			return ValidationResult::valid();
		}

		return ValidationResult::invalid( $missing, $unpublished, count( $mixedTaxClasses ) > 1 ? $mixedTaxClasses : array() );
	}
}
