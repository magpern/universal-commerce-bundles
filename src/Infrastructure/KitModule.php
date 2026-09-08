<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Infrastructure;

use UniversalCommerceBundles\Admin\BundlesAdmin;
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
use UniversalCommerceBundles\Woo\KitOverviewData;
use UniversalCommerceBundles\Woo\OrderConstruction;
use UniversalCommerceBundles\Woo\Presentation;
use UniversalCommerceBundles\Woo\ProductFacts;
use UniversalCommerceBundles\Woo\Refunds;
use UniversalCommerceBundles\Woo\StoreApiGuard;

/**
 * Wires M1's fixed-kit core and M2's admin overview/diagnostics:
 * composition/availability, Architecture B cart/order construction,
 * presentation, native-refund linkage, the cross-cutting exclusion
 * contract, and the read-only Bundles admin surface
 * (docs/ARCHITECTURE.md, docs/m2-admin-usability-plan.md). Registered by
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

	private ?KitAvailability $availability = null;

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

		$this->availability = $availability;

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
		( new BundlesAdmin(
			new KitOverviewData( $compositions, $availability ),
			fn (): bool => $this->registered
		) )->register();
	}

	public function isRegistered(): bool {
		return $this->registered;
	}

	/**
	 * Wired KitAvailability singleton for this request, or null before
	 * register(). Admin and other UCB-owned callers must reuse this rather
	 * than constructing a second availability graph.
	 */
	public function availability(): ?KitAvailability {
		return $this->availability;
	}
}
