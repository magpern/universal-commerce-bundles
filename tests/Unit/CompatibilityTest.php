<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Woo\Compatibility;

final class CompatibilityTest extends TestCase {

	/**
	 * @dataProvider versionPairsProvider
	 */
	public function test_is_version_at_least( string $version, string $minimum, bool $expected ): void {
		self::assertSame( $expected, Compatibility::isVersionAtLeast( $version, $minimum ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function versionPairsProvider(): array {
		return array(
			'exact floor'      => array( '8.2', '8.2', true ),
			'above floor'      => array( '8.2.1', '8.2', true ),
			'far above floor'  => array( '11.0.1', '8.2', true ),
			'below floor'      => array( '8.1.9', '8.2', false ),
			'well below floor' => array( '7.9.0', '8.2', false ),
		);
	}

	/**
	 * Real assertion, not a simulation: this PHPUnit process never loads
	 * WooCommerce, so WC_VERSION is genuinely undefined and the
	 * `WooCommerce` class genuinely does not exist here.
	 */
	public function test_is_woocommerce_active_is_false_when_woocommerce_is_genuinely_absent(): void {
		self::assertFalse( defined( 'WC_VERSION' ) );
		self::assertFalse( class_exists( 'WooCommerce', false ) );

		self::assertFalse( Compatibility::isWooCommerceActive() );
	}

	public function test_is_woocommerce_version_supported_is_false_when_woocommerce_is_absent(): void {
		self::assertFalse( Compatibility::isWooCommerceVersionSupported() );
	}

	public function test_meets_requirements_is_false_when_woocommerce_is_absent(): void {
		self::assertFalse( Compatibility::meetsRequirements() );
	}

	/**
	 * Isolated in its own process because it defines the real WC_VERSION
	 * constant and a stand-in WooCommerce class, neither of which can be
	 * undefined again afterwards in the same PHP process.
	 */
	#[RunInSeparateProcess]
	public function test_meets_requirements_is_true_when_woocommerce_meets_the_floor_version(): void {
		define( 'WC_VERSION', '11.0.1' );
		require dirname( __DIR__ ) . '/Fixtures/WooCommerceStub.php';

		self::assertTrue( Compatibility::isWooCommerceActive() );
		self::assertTrue( Compatibility::isWooCommerceVersionSupported() );
		self::assertTrue( Compatibility::meetsRequirements() );
	}

	#[RunInSeparateProcess]
	public function test_meets_requirements_is_false_when_woocommerce_is_present_but_too_old(): void {
		define( 'WC_VERSION', '8.1.0' );

		self::assertTrue( Compatibility::isWooCommerceActive() );
		self::assertFalse( Compatibility::isWooCommerceVersionSupported() );
		self::assertFalse( Compatibility::meetsRequirements() );
	}
}
