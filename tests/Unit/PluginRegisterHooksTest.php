<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Infrastructure\Plugin;

/**
 * Smoke test for the activation/deactivation lifecycle scaffolding
 * (docs/ARCHITECTURE.md M0 foundation scope: "Activation, upgrade,
 * deactivation... hooks registered, no kit-specific behavior").
 */
final class PluginRegisterHooksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'UCB_PLUGIN_FILE' ) ) {
			define( 'UCB_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/universal-commerce-bundles.php' );
		}

		Plugin::resetForTests();
	}

	protected function tearDown(): void {
		Plugin::resetForTests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_wires_activation_deactivation_and_init(): void {
		Functions\expect( 'register_activation_hook' )
			->once()
			->with( UCB_PLUGIN_FILE, array( Plugin::instance(), 'activate' ) );

		Functions\expect( 'register_deactivation_hook' )
			->once()
			->with( UCB_PLUGIN_FILE, array( Plugin::instance(), 'deactivate' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'plugins_loaded', array( Plugin::instance(), 'init' ) );

		Plugin::instance()->registerHooks();

		self::assertTrue( true );
	}

	public function test_activate_and_deactivate_are_inert_in_m0(): void {
		Plugin::instance()->activate();
		Plugin::instance()->deactivate();

		self::assertTrue( true, 'M0 activation/deactivation hooks run with no kit-specific behavior.' );
	}

	public function test_instance_is_a_singleton(): void {
		self::assertSame( Plugin::instance(), Plugin::instance() );
	}
}
