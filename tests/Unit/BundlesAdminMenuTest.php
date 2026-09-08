<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Admin\BundlesAdmin;
use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Engine\AvailabilityCalculator;
use UniversalCommerceBundles\Engine\CompositionValidator;
use UniversalCommerceBundles\Infrastructure\KitModule;
use UniversalCommerceBundles\Woo\KitAvailability;
use UniversalCommerceBundles\Woo\KitOverviewData;
use UniversalCommerceBundles\Woo\ProductFacts;

/**
 * M2 admin menu registration is gated on KitModule (WooCommerce path).
 */
final class BundlesAdminMenuTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		KitModule::resetForTests();
	}

	protected function tearDown(): void {
		KitModule::resetForTests();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function overviewData(): KitOverviewData {
		return new KitOverviewData(
			new CompositionRepository(),
			new KitAvailability(
				new CompositionRepository(),
				new ProductFacts(),
				new AvailabilityCalculator(),
				new CompositionValidator()
			)
		);
	}

	public function test_register_menus_requires_manage_woocommerce_capability_argument(): void {
		Functions\when( '__' )->returnArg( 1 );

		$captured = array();
		Functions\expect( 'add_submenu_page' )
			->twice()
			->andReturnUsing(
				static function ( ...$args ) use ( &$captured ) {
					$captured[] = $args;

					return 'woocommerce_page_ucb-bundles';
				}
			);

		$admin = new BundlesAdmin( $this->overviewData(), static fn (): bool => true );
		$admin->registerMenus();

		self::assertCount( 2, $captured );
		self::assertSame( 'woocommerce', $captured[0][0] );
		self::assertSame( BundlesAdmin::CAPABILITY, $captured[0][3] );
		self::assertSame( BundlesAdmin::MENU_SLUG, $captured[0][4] );
		self::assertSame( BundlesAdmin::SETTINGS_MENU_SLUG, $captured[1][4] );
		self::assertSame( BundlesAdmin::CAPABILITY, $captured[1][3] );
	}

	public function test_labels_escape_safe_for_known_codes(): void {
		Functions\when( '__' )->returnArg( 1 );

		self::assertSame( 'Published', BundlesAdmin::labelForExposure( 'published' ) );
		self::assertSame( 'Catalog-hidden', BundlesAdmin::labelForExposure( 'catalog_hidden' ) );
		self::assertSame( 'Active', BundlesAdmin::labelForSellability( 'active' ) );
		self::assertSame( 'Safety lock', BundlesAdmin::labelForBlockedReason( 'safety_lock' ) );
		self::assertSame( '', BundlesAdmin::labelForBlockedReason( null ) );
		self::assertSame( 'future', BundlesAdmin::labelForExposure( 'other', 'future' ) );
	}
}
