<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * Blocks direct customer manipulation of a child line's quantity on the
 * Store API / Checkout Blocks path with a clean, customer-facing `400`
 * (docs/ARCHITECTURE.md, ADR-0002/ADR-0003) — the same typed exception
 * shape spike S1-A proved is required for a usable checkout/cart error on
 * this path (a bare exception yields an opaque `500` instead).
 *
 * Hooks the generic WordPress REST `rest_request_before_callbacks` filter
 * (not a WooCommerce symbol) rather than a Store API internal class, since
 * the Store API cart/items update route is dispatched through the
 * standard REST server like any other REST route — this filter runs
 * before the route's own callback, letting a short-circuit response stand
 * in for it. `Automattic\WooCommerce\StoreApi\Exceptions\RouteException`
 * is still the class this plugin's shape mirrors (same error-code/message/
 * status-code contract WooCommerce's own Store API routes use), even
 * though it is constructed here for its data shape rather than thrown from
 * inside WooCommerce's own route-dispatch try/catch.
 */
final class StoreApiGuard {

	private const CART_ITEM_ROUTE_PATTERN = '#^/wc/store(?:/v[0-9]+)?/cart/(?:update-item|items(?:/(?P<key>[\w-]+))?)#';

	public function register(): void {
		add_filter( 'rest_request_before_callbacks', array( $this, 'blockChildQuantityUpdates' ), 10, 3 );
	}

	/**
	 * Live-corrected signature: `rest_request_before_callbacks`'s second
	 * argument is the matched route's own handler definition array (with
	 * 'callback'/'permission_callback' keys), never a WP_REST_Server
	 * instance — confirmed by a real fatal TypeError this plugin's own
	 * live M1 validation caught (see docs/m1-closure.md), not by
	 * documentation alone.
	 *
	 * @param mixed                $response
	 * @param array<string, mixed> $handler
	 * @return mixed
	 */
	public function blockChildQuantityUpdates( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler );

		if ( is_wp_error( $response ) || $response instanceof \WP_REST_Response ) {
			return $response;
		}

		if ( 1 !== preg_match( self::CART_ITEM_ROUTE_PATTERN, $request->get_route() ) ) {
			return $response;
		}

		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}

		$key = (string) ( $request->get_param( 'key' ) ?? '' );

		if ( '' === $key || null === $request->get_param( 'quantity' ) ) {
			return $response;
		}

		if ( null === WC()->cart ) {
			return $response;
		}

		$item = WC()->cart->get_cart_item( $key );

		if ( ! is_array( $item ) || empty( $item[ MetaKeys::LINE_COMPONENT ] ) ) {
			return $response;
		}

		return new \WP_Error(
			'woocommerce_rest_cart_component_quantity_locked',
			__( 'This item is part of a kit and its quantity cannot be changed directly. Change the kit\'s own quantity instead.', 'universal-commerce-bundles' ),
			array( 'status' => 400 )
		);
	}
}
