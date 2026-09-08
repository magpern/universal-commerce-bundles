<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Woo\AdminDiagnostics;
use UniversalCommerceBundles\Woo\Compatibility;

/**
 * M2 diagnostics are read-only and must not claim ucb_runtime_ready emission
 * or infer host-guard presence from action listeners.
 */
final class AdminDiagnosticsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[RunInSeparateProcess]
	public function test_bootstrap_label_does_not_claim_emission(): void {
		if ( ! defined( 'UCB_PLUGIN_VERSION' ) ) {
			define( 'UCB_PLUGIN_VERSION', '0.2.0' );
		}
		if ( ! defined( 'WC_VERSION' ) ) {
			define( 'WC_VERSION', '8.2.0' );
		}

		$data = AdminDiagnostics::collect( static fn (): bool => true );

		self::assertSame( 'UCB bootstrap completed / runtime contract available', $data['bootstrap_label'] );
		self::assertStringNotContainsString( 'emitted', strtolower( $data['bootstrap_label'] ) );
		self::assertSame( Compatibility::MINIMUM_WOOCOMMERCE_VERSION, $data['woocommerce_minimum'] );
	}

	public function test_host_guard_label_is_unavailable_without_inferring_action_listeners(): void {
		// A listener count must never be treated as guard presence.
		Functions\expect( 'has_action' )->never();

		if ( ! defined( 'UCB_PLUGIN_VERSION' ) ) {
			define( 'UCB_PLUGIN_VERSION', '0.2.0' );
		}

		$data = AdminDiagnostics::collect( static fn (): bool => false );

		self::assertSame( 'Guard detection unavailable', $data['host_guard_label'] );
		self::assertSame( AdminDiagnostics::HOST_GUARD_LABEL_UNAVAILABLE, $data['host_guard_label'] );
		self::assertArrayNotHasKey( 'host_guard_listeners', $data );
		self::assertSame( 'UCB bootstrap not completed', $data['bootstrap_label'] );
	}
}
