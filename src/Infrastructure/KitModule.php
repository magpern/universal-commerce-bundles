<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Infrastructure;

use UniversalCommerceBundles\Admin\KitDataPanel;
use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Application\ReverseIndexRepository;
use UniversalCommerceBundles\Engine\AvailabilityCalculator;
use UniversalCommerceBundles\Engine\CompositionValidator;
use UniversalCommerceBundles\Engine\RefundLinkageCalculator;
use UniversalCommerceBundles\Woo\CartConstruction;
use UniversalCommerceBundles\Woo\ComponentVisibility;
use UniversalCommerceBundles\Woo\Exclusions;
use UniversalCommerceBundles\Woo\Invalidation;
use UniversalCommerceBundles\Woo\KitAvailability;
use UniversalCommerceBundles\Woo\OrderConstruction;
use UniversalCommerceBundles\Woo\Presentation;
use UniversalCommerceBundles\Woo\ProductFacts;
use UniversalCommerceBundles\Woo\Refunds;
use UniversalCommerceBundles\Woo\StoreApiGuard;

/**
 * Wires M1's fixed-kit core: composition/availability, Architecture B
 * cart/order construction, presentation, native-refund linkage, and the
 * cross-cutting exclusion contract (docs/ARCHITECTURE.md). Registered by
 * Infrastructure\Plugin::init() only once the WooCommerce dependency check
 * has passed.
 *
 * This is deliberately a thin composition root, not a service container:
 * every class it wires is a small, independently-testable unit (see
 * src/Domain, src/Engine, src/Application, src/Woo); this class only
 * decides which of them exist and calls their own register().
 */
final class KitModule {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @internal Test-only seam.
	 */
	public static function resetForTests(): void {
		self::$instance = null;
	}

	private bool $registered = false;

	private function __construct() {
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		$compositions = new CompositionRepository();
		$reverseIndex = new ReverseIndexRepository();
		$facts        = new ProductFacts();
		$availability = new KitAvailability(
			$compositions,
			$facts,
			new AvailabilityCalculator(),
			new CompositionValidator()
		);

		$availability->register();
		( new Invalidation( $compositions, $reverseIndex, $availability ) )->register();
		( new CartConstruction( $compositions ) )->register();
		( new StoreApiGuard() )->register();
		( new OrderConstruction( $compositions ) )->register();
		( new Presentation() )->register();
		( new Refunds( new RefundLinkageCalculator() ) )->register();
		( new Exclusions() )->register();
		( new ComponentVisibility() )->register();
		( new KitDataPanel( $compositions, $reverseIndex, $availability ) )->register();
	}
}
