<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * Pure dual-field assessment for M2 admin overview
 * (docs/m2-admin-usability-plan.md): catalog exposure is independent of
 * operational sellability; sellability reuses ValidationResult + the
 * AvailabilityCalculator quantity (no second stock formula).
 */
final class KitOperationalAssessor {

	public const EXPOSURE_PUBLISHED      = 'published';
	public const EXPOSURE_CATALOG_HIDDEN = 'catalog_hidden';
	public const EXPOSURE_DRAFT          = 'draft';
	public const EXPOSURE_PENDING        = 'pending';
	public const EXPOSURE_PRIVATE        = 'private';
	public const EXPOSURE_OTHER          = 'other';

	public const SELLABILITY_ACTIVE              = 'active';
	public const SELLABILITY_BLOCKED             = 'blocked';
	public const SELLABILITY_BACKORDER_SUPPORTED = 'backorder_supported';

	public const REASON_SAFETY_LOCK                  = 'safety_lock';
	public const REASON_INVALID_CONFIGURATION        = 'invalid_configuration';
	public const REASON_MISSING_COMPONENT            = 'missing_component';
	public const REASON_COMPONENT_NOT_PURCHASABLE    = 'component_not_purchasable';
	public const REASON_INSUFFICIENT_COMPONENT_STOCK = 'insufficient_component_stock';

	/**
	 * @param string $postStatus         WordPress post status.
	 * @param string $catalogVisibility  WooCommerce catalog visibility slug
	 *                                   (visible|catalog|search|hidden).
	 */
	public function assessCatalogExposure( string $postStatus, string $catalogVisibility ): KitCatalogExposure {
		if ( 'publish' !== $postStatus ) {
			return match ( $postStatus ) {
				'draft'   => new KitCatalogExposure( self::EXPOSURE_DRAFT, $postStatus ),
				'pending' => new KitCatalogExposure( self::EXPOSURE_PENDING, $postStatus ),
				'private' => new KitCatalogExposure( self::EXPOSURE_PRIVATE, $postStatus ),
				default   => new KitCatalogExposure( self::EXPOSURE_OTHER, $postStatus ),
			};
		}

		if ( 'hidden' === $catalogVisibility ) {
			return new KitCatalogExposure( self::EXPOSURE_CATALOG_HIDDEN, $postStatus );
		}

		return new KitCatalogExposure( self::EXPOSURE_PUBLISHED, $postStatus );
	}

	/**
	 * @param bool             $locked        Deactivation lock (_ucb_locked).
	 * @param ValidationResult $validation    Live composition validation.
	 * @param int              $availableQty  From AvailabilityCalculator::calculate()
	 *                                        when valid; ignored when invalid/locked.
	 */
	public function assessSellability( bool $locked, ValidationResult $validation, int $availableQty ): KitSellability {
		if ( $locked ) {
			return new KitSellability(
				self::SELLABILITY_BLOCKED,
				self::REASON_SAFETY_LOCK
			);
		}

		if ( ! $validation->valid ) {
			return new KitSellability(
				self::SELLABILITY_BLOCKED,
				$this->reasonFromInvalidValidation( $validation )
			);
		}

		if ( $availableQty <= 0 ) {
			return new KitSellability(
				self::SELLABILITY_BLOCKED,
				self::REASON_INSUFFICIENT_COMPONENT_STOCK
			);
		}

		if ( PHP_INT_MAX === $availableQty ) {
			return new KitSellability( self::SELLABILITY_BACKORDER_SUPPORTED, null );
		}

		return new KitSellability( self::SELLABILITY_ACTIVE, null );
	}

	private function reasonFromInvalidValidation( ValidationResult $validation ): string {
		if ( $validation->structurallyInvalid ) {
			return self::REASON_INVALID_CONFIGURATION;
		}

		if ( array() !== $validation->missingComponentIds ) {
			return self::REASON_MISSING_COMPONENT;
		}

		if ( array() !== $validation->unpublishedComponentIds ) {
			return self::REASON_COMPONENT_NOT_PURCHASABLE;
		}

		if ( array() !== $validation->mixedTaxClasses ) {
			return self::REASON_INVALID_CONFIGURATION;
		}

		return self::REASON_INVALID_CONFIGURATION;
	}
}
