<?php
/**
 * Plugin Name:          Universal Commerce Bundles
 * Plugin URI:           https://github.com/magpern/universal-commerce-bundles
 * Description:          Fixed-kit product bundles for WooCommerce — a priced parent line plus hidden, real WooCommerce child order lines per component, picked to order. Documentation-driven, generic, no store-specific logic.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * WC requires at least: 8.2
 * WC tested up to:      11.0
 * Author:               magpern
 * Author URI:           https://github.com/magpern
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          universal-commerce-bundles
 * Domain Path:          /languages
 *
 * Architecture B fixed kits (see docs/ARCHITECTURE.md, docs/m1-closure.md).
 * First tagged DEV release package: priced kit parent + component child lines.
 *
 * @package UniversalCommerceBundles
 */

declare(strict_types=1);

namespace UniversalCommerceBundles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- these are
// deliberately plain global constants (not namespace-scoped), matching
// common WordPress plugin convention, so that WordPress core functions and
// this plugin's own classes can read them without qualification. Their
// `UCB_` prefix is this plugin's documented global-constant prefix.
if ( ! defined( 'UCB_PLUGIN_FILE' ) ) {
	define( 'UCB_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'UCB_PLUGIN_DIR' ) ) {
	define( 'UCB_PLUGIN_DIR', __DIR__ );
}

if ( ! defined( 'UCB_PLUGIN_VERSION' ) ) {
	define( 'UCB_PLUGIN_VERSION', '0.1.0' );
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

$ucbAutoloader = __DIR__ . '/vendor/autoload.php';

if ( is_readable( $ucbAutoloader ) ) {
	require_once $ucbAutoloader;
}

unset( $ucbAutoloader );

// Declared unconditionally, even if the rest of bootstrap below never runs
// or fails for some other reason — WooCommerce reads compatibility
// declarations on `before_woocommerce_init`, before this plugin's own
// `plugins_loaded` init logic ever gets a chance to run. See the
// "Foundation scope" section of docs/ARCHITECTURE.md.
if ( class_exists( Woo\Compatibility::class ) ) {
	Woo\Compatibility::declareFeatureCompatibility();
}

if ( class_exists( Infrastructure\Plugin::class ) ) {
	Infrastructure\Plugin::instance()->registerHooks();
}
