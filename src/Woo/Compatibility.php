<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

/**
 * WooCommerce presence/version detection and feature-compatibility declaration.
 *
 * This is the ONLY class in the plugin permitted to reference WooCommerce
 * symbols (classes, constants, functions) directly. See
 * tests/Structural/WooConfinementTest.php, which enforces this by scanning
 * every other file under src/ for WooCommerce symbol references.
 *
 * M0 scope only: detection and the unconditional HPOS/Blocks compatibility
 * declaration. No kit, cart, order, or refund behavior lives here — that is
 * M1's scope.
 */
final class Compatibility {

	public const MINIMUM_WOOCOMMERCE_VERSION = '8.2';

	/**
	 * Whether WooCommerce is loaded at all, regardless of version.
	 */
	public static function isWooCommerceActive(): bool {
		return defined( 'WC_VERSION' ) || class_exists( 'WooCommerce', false );
	}

	/**
	 * Whether the loaded WooCommerce version meets this plugin's floor.
	 *
	 * Returns false (never fatals) when WooCommerce is not loaded at all.
	 */
	public static function isWooCommerceVersionSupported(): bool {
		if ( ! defined( 'WC_VERSION' ) ) {
			return false;
		}

		return self::isVersionAtLeast( (string) WC_VERSION, self::MINIMUM_WOOCOMMERCE_VERSION );
	}

	/**
	 * Pure version comparison, extracted for direct unit testing without
	 * needing to define WC_VERSION for every case.
	 */
	public static function isVersionAtLeast( string $version, string $minimum ): bool {
		return version_compare( $version, $minimum, '>=' );
	}

	/**
	 * The single gate the bootstrap uses to decide whether to proceed or
	 * self-deactivate safely.
	 */
	public static function meetsRequirements(): bool {
		return self::isWooCommerceActive() && self::isWooCommerceVersionSupported();
	}

	/**
	 * Declares High-Performance Order Storage (custom_order_tables) and
	 * cart/checkout Blocks compatibility.
	 *
	 * Per docs/ARCHITECTURE.md's M0 foundation scope, this MUST be
	 * registered unconditionally on `before_woocommerce_init`, even if the
	 * rest of this plugin's bootstrap fails for some other reason —
	 * WooCommerce reads compatibility declarations before this plugin's own
	 * `plugins_loaded`/init logic runs. Calling add_action() here is always
	 * safe: if WooCommerce is never loaded, `before_woocommerce_init` simply
	 * never fires and this callback never runs.
	 */
	public static function declareFeatureCompatibility(): void {
		add_action(
			'before_woocommerce_init',
			static function (): void {
				if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					return;
				}

				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables',
					UCB_PLUGIN_FILE,
					true
				);

				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'cart_checkout_blocks',
					UCB_PLUGIN_FILE,
					true
				);
			}
		);
	}
}
