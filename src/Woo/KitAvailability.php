<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Domain\MetaKeys;
use UniversalCommerceBundles\Engine\AvailabilityCalculator;
use UniversalCommerceBundles\Engine\CompositionValidator;
use UniversalCommerceBundles\Engine\KitCatalogExposure;
use UniversalCommerceBundles\Engine\KitOperationalAssessor;
use UniversalCommerceBundles\Engine\KitOverviewAssessment;
use UniversalCommerceBundles\Engine\KitSellability;
use UniversalCommerceBundles\Engine\ValidationResult;

/**
 * Derived kit availability and purchasability (docs/ARCHITECTURE.md,
 * ADR-0002), computed live at every decision point — never trusted from
 * the cached display hint (CompositionRepository::getCachedValidityHint()
 * is a hint for admin display only).
 *
 * Registers the WooCommerce purchasability/availability filters; delegates
 * all actual math to Engine\AvailabilityCalculator / CompositionValidator,
 * and all actual product facts to ProductFacts — this class is the seam
 * between them and real WooCommerce hooks.
 */
final class KitAvailability {

	public function __construct(
		private readonly CompositionRepository $compositions,
		private readonly ProductFacts $facts,
		private readonly AvailabilityCalculator $availabilityCalculator,
		private readonly CompositionValidator $validator,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filterIsPurchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filterIsPurchasable' ), 20, 2 );
		add_filter( 'woocommerce_get_availability', array( $this, 'filterAvailabilityHtml' ), 10, 2 );
	}

	public function filterIsPurchasable( bool $purchasable, \WC_Product $product ): bool {
		if ( ! $purchasable ) {
			return $purchasable;
		}

		$kitId = $this->kitIdFor( $product );

		if ( 0 === $kitId || ! $this->compositions->isKit( $kitId ) ) {
			return $purchasable;
		}

		return $this->isPurchasable( $kitId );
	}

	/**
	 * @param array{availability: string, class: string} $availability
	 * @return array{availability: string, class: string}
	 */
	public function filterAvailabilityHtml( array $availability, \WC_Product $product ): array {
		$kitId = $this->kitIdFor( $product );

		if ( 0 === $kitId || ! $this->compositions->isKit( $kitId ) ) {
			return $availability;
		}

		if ( ! $this->isPurchasable( $kitId ) ) {
			$availability['availability'] = __( 'Currently unavailable', 'universal-commerce-bundles' );
			$availability['class']        = 'out-of-stock';
		}

		return $availability;
	}

	public function isPurchasable( int $kitId, int $excludeOrderId = 0 ): bool {
		if ( 'yes' === get_post_meta( $kitId, MetaKeys::PRODUCT_LOCKED_BY_DEACTIVATION, true ) ) {
			return false;
		}

		$validation = $this->validate( $kitId );

		if ( ! $validation->valid ) {
			return false;
		}

		return $this->availabilityCalculator->isPurchasable(
			$this->liveComponentAvailability( $kitId, $excludeOrderId )
		);
	}

	public function availableKitQuantity( int $kitId, int $excludeOrderId = 0 ): int {
		if ( ! $this->validate( $kitId )->valid ) {
			return 0;
		}

		return $this->availabilityCalculator->calculate(
			$this->liveComponentAvailability( $kitId, $excludeOrderId )
		);
	}

	/**
	 * Live composition validation without refreshing the cached display
	 * hint. Used by M2 admin overview so read-only screens never write
	 * product meta (docs/m2-admin-usability-plan.md acceptance item 11).
	 */
	public function validateLive( int $kitId ): ValidationResult {
		$composition = $this->compositions->getComposition( $kitId );
		$states      = array();

		foreach ( $composition->stockManagedIds() as $stockManagedId ) {
			$states[] = $this->facts->stateFor( $stockManagedId );
		}

		return $this->validator->validate( $composition, $states );
	}

	public function validate( int $kitId ): ValidationResult {
		$result = $this->validateLive( $kitId );

		$this->compositions->setCachedValidityHint( $kitId, $result->valid );

		return $result;
	}

	/**
	 * Read-only operational sellability for admin presentation. Does not
	 * write product meta and does not invent a second stock formula.
	 */
	public function assessSellability( int $kitId, int $excludeOrderId = 0 ): KitSellability {
		$assessor     = new KitOperationalAssessor();
		$locked       = 'yes' === get_post_meta( $kitId, MetaKeys::PRODUCT_LOCKED_BY_DEACTIVATION, true );
		$validation   = $this->validateLive( $kitId );
		$availableQty = 0;

		if ( $validation->valid ) {
			$availableQty = $this->availabilityCalculator->calculate(
				$this->liveComponentAvailability( $kitId, $excludeOrderId )
			);
		}

		return $assessor->assessSellability( $locked, $validation, $availableQty );
	}

	/**
	 * Dual-field M2 assessment: catalog exposure + sellability.
	 *
	 * @param string $postStatus        WordPress post status.
	 * @param string $catalogVisibility WooCommerce catalog visibility slug.
	 */
	public function assessOverview(
		int $kitId,
		string $postStatus,
		string $catalogVisibility,
		int $excludeOrderId = 0
	): KitOverviewAssessment {
		$assessor = new KitOperationalAssessor();

		return new KitOverviewAssessment(
			$assessor->assessCatalogExposure( $postStatus, $catalogVisibility ),
			$this->assessSellability( $kitId, $excludeOrderId )
		);
	}

	public function assessCatalogExposure( string $postStatus, string $catalogVisibility ): KitCatalogExposure {
		return ( new KitOperationalAssessor() )->assessCatalogExposure( $postStatus, $catalogVisibility );
	}

	/**
	 * @return \UniversalCommerceBundles\Engine\ComponentAvailability[]
	 */
	private function liveComponentAvailability( int $kitId, int $excludeOrderId ): array {
		$composition = $this->compositions->getComposition( $kitId );

		return array_map(
			fn ( $component ) => $this->facts->availabilityFor( $component, $excludeOrderId ),
			$composition->components
		);
	}

	private function kitIdFor( \WC_Product $product ): int {
		$parentId = $product->get_parent_id();
		$id       = $parentId > 0 ? $parentId : $product->get_id();

		return $id > 0 ? $id : 0;
	}

	public function compositions(): CompositionRepository {
		return $this->compositions;
	}
}
