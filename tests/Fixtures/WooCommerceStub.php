<?php

declare(strict_types=1);

// Minimal stand-in for the real WooCommerce class, used only by tests that
// run in their own isolated process (see CompatibilityTest and
// PluginRuntimeReadyTest) to simulate "WooCommerce is loaded" without
// requiring the real plugin.

if ( ! class_exists( 'WooCommerce', false ) ) {
	class WooCommerce {

	}
}
