<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Infrastructure\Plugin;

/**
 * Proves the "WooCommerce-dependency check that fails safely" requirement:
 * with WooCommerce genuinely absent from this process (never loaded by
 * PHPUnit), Plugin::init() must not fatal, must never fire the
 * `ucb_runtime_ready` capability signal, and must register a safe
 * self-deactivation path instead.
 */
final class PluginSafeFailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'UCB_PLUGIN_FILE' ) ) {
			define( 'UCB_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/universal-commerce-bundles.php' );
		}
		if ( ! defined( 'UCB_PLUGIN_VERSION' ) ) {
			define( 'UCB_PLUGIN_VERSION', '0.1.0-dev' );
		}

		Plugin::resetForTests();
	}

	protected function tearDown(): void {
		Plugin::resetForTests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_never_fatals_and_never_emits_readiness_when_woocommerce_absent(): void {
		self::assertFalse( defined( 'WC_VERSION' ), 'This test requires a real WooCommerce-absent process.' );

		Functions\expect( 'add_action' )->twice();

		Actions\expectDone( 'ucb_runtime_ready' )->never();

		Plugin::instance()->init();

		self::assertTrue( true, 'init() completed without a fatal error.' );
	}

	public function test_init_registers_admin_notice_and_admin_init_deactivation(): void {
		$registeredHooks = array();

		Functions\expect( 'add_action' )
			->twice()
			->andReturnUsing(
				static function ( string $hook ) use ( &$registeredHooks ): bool {
					$registeredHooks[] = $hook;

					return true;
				}
			);

		Plugin::instance()->init();

		self::assertSame( array( 'admin_notices', 'admin_init' ), $registeredHooks );
	}

	public function test_deactivate_self_is_a_safe_noop_when_deactivate_plugins_is_unavailable(): void {
		self::assertFalse( function_exists( 'deactivate_plugins' ) );

		Plugin::instance()->deactivateSelf();

		self::assertTrue( true, 'deactivateSelf() completed without a fatal error.' );
	}

	public function test_render_requirements_notice_prints_a_safe_admin_notice(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );

		ob_start();
		Plugin::instance()->renderRequirementsNotice();
		$output = ob_get_clean();

		self::assertStringContainsString( 'notice-error', (string) $output );
		self::assertStringContainsString( 'WooCommerce', (string) $output );
	}
}
