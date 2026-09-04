<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Loads the actual plugin bootstrap file (universal-commerce-bundles.php)
 * end to end, outside a real WordPress install, with WooCommerce absent.
 * Proves the whole file — plugin header, constant definitions, autoloader
 * require, HPOS declaration, and hook registration — runs without a fatal
 * error under exactly the "WooCommerce absent" condition the safe-fail
 * requirement is about.
 *
 * Isolated in its own process because the file defines constants
 * (UCB_PLUGIN_FILE, etc.) that cannot be undefined afterwards, and because
 * it can only meaningfully run once per process (constants would collide
 * on a second require).
 */
final class BootstrapFileSmokeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[RunInSeparateProcess]
	public function test_bootstrap_file_loads_without_fatal_error_when_woocommerce_is_absent(): void {
		Functions\expect( 'add_action' )->atLeast()->once()->andReturnTrue();
		Functions\expect( 'register_activation_hook' )->once()->andReturnTrue();
		Functions\expect( 'register_deactivation_hook' )->once()->andReturnTrue();

		require dirname( __DIR__, 2 ) . '/universal-commerce-bundles.php';

		self::assertTrue( defined( 'UCB_PLUGIN_FILE' ) );
		self::assertTrue( defined( 'UCB_PLUGIN_DIR' ) );
		self::assertTrue( defined( 'UCB_PLUGIN_VERSION' ) );
		self::assertTrue( class_exists( \UniversalCommerceBundles\Infrastructure\Plugin::class ) );
		self::assertTrue( class_exists( \UniversalCommerceBundles\Woo\Compatibility::class ) );
	}
}
