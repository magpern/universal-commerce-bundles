<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Infrastructure\Plugin;

/**
 * The positive-path counterpart to PluginSafeFailTest: with WooCommerce
 * present and new enough, init() must emit `ucb_runtime_ready` exactly
 * once (ADR-0006, capability contract term 1), carrying the documented
 * payload keys, and must not register the self-deactivation path.
 *
 * Isolated in its own process because it defines the real WC_VERSION
 * constant, which cannot be undefined again afterwards in the same
 * process.
 */
final class PluginRuntimeReadyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		define( 'WC_VERSION', '11.0.1' );
		require dirname( __DIR__ ) . '/Fixtures/WooCommerceStub.php';

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

	#[RunInSeparateProcess]
	public function test_init_emits_ucb_runtime_ready_exactly_once_with_the_documented_payload(): void {
		Functions\expect( 'add_action' )->never();

		Actions\expectDone( 'ucb_runtime_ready' )
			->once()
			->whenHappen(
				static function ( array $payload ): void {
					self::assertSame( '0.1.0-dev', $payload['plugin_version'] );
					self::assertSame( 1, $payload['contract_version'] );
					self::assertSame( array( 1 ), $payload['snapshot_versions'] );
				}
			);

		Plugin::instance()->init();
	}
}
