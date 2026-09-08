<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Admin\BundlesAdmin;
use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Engine\AvailabilityCalculator;
use UniversalCommerceBundles\Engine\CompositionValidator;
use UniversalCommerceBundles\Engine\KitOperationalAssessor;
use UniversalCommerceBundles\Woo\KitAvailability;
use UniversalCommerceBundles\Woo\KitOverviewData;
use UniversalCommerceBundles\Woo\ProductFacts;

/**
 * M2 KitAvailability read-only assessment must not refresh the cached
 * validity hint (acceptance item 11).
 */
final class KitAvailabilityAdminAssessmentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function availability(): KitAvailability {
		return new KitAvailability(
			new CompositionRepository(),
			new ProductFacts(),
			new AvailabilityCalculator(),
			new CompositionValidator()
		);
	}

	private function overviewData(): KitOverviewData {
		return new KitOverviewData( new CompositionRepository(), $this->availability() );
	}

	public function test_validate_live_does_not_write_validity_hint(): void {
		require_once dirname( __DIR__ ) . '/Fixtures/WCProductStub.php';

		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'get_post_meta' )
			->andReturnUsing(
				static function ( int $id, string $key, bool $single = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
					unset( $id, $single );
					if ( '_ucb_composition' === $key ) {
						return json_encode(
							array(
								array(
									'stock_managed_id' => 10,
									'product_id'       => 10,
									'variation_id'     => 0,
									'qty_per_kit'      => 1,
								),
							)
						);
					}

					return '';
				}
			);

		Functions\expect( 'wc_get_product' )->once()->with( 10 )->andReturn( new \WC_Product( 10 ) );
		Functions\expect( 'get_post_status' )->once()->with( 10 )->andReturn( 'publish' );

		$result = $this->availability()->validateLive( 42 );

		self::assertTrue( $result->valid );
	}

	public function test_assess_sellability_locked_kit_is_blocked_safety_lock(): void {
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'get_post_meta' )
			->andReturnUsing(
				static function ( int $id, string $key, bool $single = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
					unset( $id, $single );
					if ( '_ucb_locked' === $key ) {
						return 'yes';
					}
					if ( '_ucb_composition' === $key ) {
						return '[]';
					}

					return '';
				}
			);

		$sellability = $this->availability()->assessSellability( 7 );

		self::assertSame( KitOperationalAssessor::SELLABILITY_BLOCKED, $sellability->code );
		self::assertSame( KitOperationalAssessor::REASON_SAFETY_LOCK, $sellability->blockedReasonCode );
	}

	public function test_capability_constant_is_manage_woocommerce(): void {
		self::assertSame( 'manage_woocommerce', BundlesAdmin::CAPABILITY );
		self::assertSame( 'ucb-bundles', BundlesAdmin::MENU_SLUG );
		self::assertSame( 'ucb-bundles-settings', BundlesAdmin::SETTINGS_MENU_SLUG );
	}

	#[RunInSeparateProcess]
	public function test_unauthorized_render_dies(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_woocommerce' )
			->andReturn( false );
		Functions\expect( 'wp_die' )
			->once()
			->andThrow( new \RuntimeException( 'denied' ) );

		$admin = new BundlesAdmin( $this->overviewData(), static fn (): bool => true );

		$this->expectException( \RuntimeException::class );
		$admin->renderOverviewPage();
	}
}
