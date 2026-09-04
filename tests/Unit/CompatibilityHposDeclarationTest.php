<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Woo\Compatibility;

final class CompatibilityHposDeclarationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'UCB_PLUGIN_FILE' ) ) {
			define( 'UCB_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/universal-commerce-bundles.php' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_declares_feature_compatibility_registers_before_woocommerce_init(): void {
		$captured = null;

		Functions\expect( 'add_action' )
			->once()
			->with( 'before_woocommerce_init', \Mockery::type( 'callable' ) )
			->andReturnUsing(
				static function ( string $hook, callable $callback ) use ( &$captured ): bool {
					$captured = $callback;

					return true;
				}
			);

		Compatibility::declareFeatureCompatibility();

		self::assertIsCallable( $captured );
	}

	/**
	 * The registered callback must never fatal even when WooCommerce's
	 * FeaturesUtil class genuinely does not exist in this process — the
	 * real condition whenever WooCommerce is absent or too old to have it.
	 */
	public function test_the_registered_callback_is_a_safe_noop_without_featuresutil(): void {
		self::assertFalse( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, false ) );

		$captured = null;

		Functions\expect( 'add_action' )
			->once()
			->andReturnUsing(
				static function ( string $hook, callable $callback ) use ( &$captured ): bool {
					$captured = $callback;

					return true;
				}
			);

		Compatibility::declareFeatureCompatibility();

		self::assertIsCallable( $captured );

		// Must not throw.
		( $captured )();

		self::assertTrue( true );
	}
}
