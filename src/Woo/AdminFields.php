<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

/**
 * Thin wrapper around WooCommerce's own admin product-field render helpers,
 * so src/Admin (which builds the product-edit-screen UI) never has to call
 * a WooCommerce symbol directly — keeping the confinement rule
 * (docs/ARCHITECTURE.md) exception-free rather than carving Admin out of
 * it.
 */
final class AdminFields {

	/**
	 * @param array<string, mixed> $args
	 */
	public static function checkbox( array $args ): void {
		woocommerce_wp_checkbox( $args );
	}
}
