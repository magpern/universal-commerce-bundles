<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * Server-side hiding of hidden child lines from every documented
 * customer-facing surface (ADR-0003, ADR-0007) — never a data-layer
 * deletion: internal consumers (stock, fulfillment, refund reconciliation)
 * always see real rows via ordinary get_items(). Presentation only.
 *
 * A narrowly-scoped guard, not a blanket one: the admin/email/My-Account
 * exclusion below only activates inside the specific customer-facing
 * template/email hooks it brackets, exactly the lesson S1-C/S1-D's own
 * "blanket filter broke a raw item-reading consumer" finding requires.
 */
final class Presentation {

	/**
	 * Request-scoped flag (not persisted): true only while a bracketed
	 * customer-facing order-items render is actually in progress.
	 */
	private bool $customerFacingScope = false;

	public function register(): void {
		// Classic cart/checkout — the three purpose-built visibility filters.
		add_filter( 'woocommerce_cart_item_visible', array( $this, 'hideChildCartItem' ), 10, 2 );
		add_filter( 'woocommerce_widget_cart_item_visible', array( $this, 'hideChildCartItem' ), 10, 2 );
		add_filter( 'woocommerce_order_item_visible', array( $this, 'hideChildOrderItemVisibility' ), 10, 2 );

		// Store API JSON (genuine HTTP requests through the standard REST
		// dispatch path).
		add_filter( 'rest_post_dispatch', array( $this, 'filterStoreApiResponse' ), 10, 3 );

		// Cart/Checkout block server-rendered hydration payload — bypasses
		// the standard REST dispatch path entirely, so needs its own,
		// separate, WooCommerce-documented back-compat seam.
		add_filter( 'woocommerce_hydration_request_after_callbacks', array( $this, 'filterHydrationResponse' ), 10, 3 );

		// REST v3 orders.
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'filterRestOrderResponse' ), 10, 3 );

		// Admin order view / emails / My Account — narrowly scoped, active
		// only inside these specific customer-facing hooks.
		add_action( 'woocommerce_order_details_before_order_table', array( $this, 'enterCustomerFacingScope' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'exitCustomerFacingScope' ) );
		add_action( 'woocommerce_email_order_details', array( $this, 'enterCustomerFacingScope' ), 5 );
		add_action( 'woocommerce_email_order_details', array( $this, 'exitCustomerFacingScope' ), 20 );
		add_filter( 'woocommerce_order_get_items', array( $this, 'filterOrderGetItems' ), 10, 3 );

		// The visible "Contents" line, built from the parent's snapshot.
		add_filter( 'woocommerce_order_item_name', array( $this, 'appendContentsLine' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'appendContentsLineForCartItem' ), 10, 3 );
	}

	/**
	 * @param array<string, mixed> $cartItem
	 */
	public function hideChildCartItem( bool $visible, array $cartItem ): bool {
		if ( ! empty( $cartItem[ MetaKeys::LINE_COMPONENT ] ) ) {
			return false;
		}

		return $visible;
	}

	public function hideChildOrderItemVisibility( bool $visible, \WC_Order_Item $item ): bool {
		if ( $item->get_meta( MetaKeys::LINE_COMPONENT, true ) ) {
			return false;
		}

		return $visible;
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public function filterStoreApiResponse( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		unset( $server );

		if ( ! $result instanceof \WP_REST_Response ) {
			return $result;
		}

		if ( ! $this->isStoreApiCartRoute( $request->get_route() ) ) {
			return $result;
		}

		$result->set_data( $this->stripChildrenFromResponseData( $result->get_data() ) );

		return $result;
	}

	/**
	 * @param mixed $response
	 * @return mixed
	 */
	public function filterHydrationResponse( $response, $handler, \WP_REST_Request $request ) {
		unset( $handler, $request );

		if ( is_array( $response ) && isset( $response['body'] ) ) {
			$response['body'] = $this->stripChildrenFromResponseData( $response['body'] );
		}

		return $response;
	}

	/**
	 * @param \WP_REST_Response $response
	 */
	public function filterRestOrderResponse( \WP_REST_Response $response, \WC_Order $order, \WP_REST_Request $request ): \WP_REST_Response {
		unset( $order, $request );

		$data = $response->get_data();

		if ( isset( $data['line_items'] ) && is_array( $data['line_items'] ) ) {
			$data['line_items'] = array_values(
				array_filter(
					$data['line_items'],
					static fn ( array $lineItem ): bool => empty( $lineItem['meta_data'] ) || ! self::metaContainsChildMarker( $lineItem['meta_data'] )
				)
			);
		}

		$response->set_data( $data );

		return $response;
	}

	public function enterCustomerFacingScope(): void {
		$this->customerFacingScope = true;
	}

	public function exitCustomerFacingScope(): void {
		$this->customerFacingScope = false;
	}

	/**
	 * Live-corrected signature: `woocommerce_order_get_items`'s third
	 * argument is the array of requested item types (e.g. `['line_item']`),
	 * never a single string — confirmed by a real fatal TypeError this
	 * plugin's own live M1 validation caught (see docs/m1-closure.md).
	 *
	 * @param \WC_Order_Item[] $items
	 * @param string|string[]  $type
	 * @return \WC_Order_Item[]
	 */
	public function filterOrderGetItems( array $items, \WC_Abstract_Order $order, $type ) {
		unset( $order, $type );

		if ( ! $this->customerFacingScope ) {
			return $items;
		}

		return array_filter(
			$items,
			static fn ( \WC_Order_Item $item ): bool => ! $item->get_meta( MetaKeys::LINE_COMPONENT, true )
		);
	}

	public function appendContentsLine( string $name, \WC_Order_Item $item ): string {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $name;
		}

		$snapshot = $this->snapshotFor( $item );

		if ( null === $snapshot ) {
			return $name;
		}

		return $name . $this->renderContentsHtml( $snapshot );
	}

	/**
	 * Cart/checkout Contents line: no persisted snapshot exists yet before
	 * checkout, so this reads the kit's *current* composition directly —
	 * acceptable pre-purchase (nothing historical to protect yet); the
	 * order-level appendContentsLine() above is what renders from the
	 * immutable snapshot once an order exists.
	 *
	 * @param array<string, mixed> $cartItem
	 */
	public function appendContentsLineForCartItem( string $name, array $cartItem, string $cartItemKey ): string {
		unset( $cartItemKey );

		if ( ! empty( $cartItem[ MetaKeys::LINE_COMPONENT ] ) ) {
			return $name;
		}

		$productId   = (int) ( $cartItem['product_id'] ?? 0 );
		$composition = ( new \UniversalCommerceBundles\Application\CompositionRepository() )->getComposition( $productId );

		if ( $composition->isEmpty() ) {
			return $name;
		}

		$kitQty = (int) ( $cartItem['quantity'] ?? 1 );
		$rows   = array();

		foreach ( $composition->components as $component ) {
			$componentProduct = wc_get_product( $component->variationId > 0 ? $component->variationId : $component->productId );
			$componentName    = $componentProduct instanceof \WC_Product ? $componentProduct->get_name() : '';

			$rows[] = sprintf( '<li>%s &times; %d</li>', esc_html( $componentName ), $component->qtyPerKit * $kitQty );
		}

		return $name . sprintf(
			'<div class="ucb-kit-contents"><strong>%s</strong><ul>%s</ul></div>',
			esc_html__( 'Contents:', 'universal-commerce-bundles' ),
			implode( '', $rows )
		);
	}

	private function snapshotFor( \WC_Order_Item_Product $item ): ?\UniversalCommerceBundles\Domain\KitSnapshot {
		$raw = $item->get_meta( MetaKeys::LINE_KIT_SNAPSHOT, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return \UniversalCommerceBundles\Domain\KitSnapshot::fromArray( $decoded );
	}

	private function renderContentsHtml( \UniversalCommerceBundles\Domain\KitSnapshot $snapshot ): string {
		if ( array() === $snapshot->components ) {
			return '';
		}

		$rows = array_map(
			static fn ( array $component ): string => sprintf(
				'<li>%s &times; %d</li>',
				esc_html( (string) $component['name'] ),
				(int) $component['qty_total']
			),
			$snapshot->components
		);

		return sprintf(
			'<div class="ucb-kit-contents"><strong>%s</strong><ul>%s</ul></div>',
			esc_html__( 'Contents:', 'universal-commerce-bundles' ),
			implode( '', $rows )
		);
	}

	private function isStoreApiCartRoute( string $route ): bool {
		return 1 === preg_match( '#^/wc/store(?:/v[0-9]+)?/(cart|checkout)#', $route );
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	private function stripChildrenFromResponseData( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$data['items'] = array_values(
				array_filter(
					$data['items'],
					static fn ( $item ): bool => ! ( is_array( $item ) && ! empty( $item['ucb_component'] ) )
				)
			);
		}

		return $data;
	}

	/**
	 * @param array<int, array{key?: string, value?: mixed}> $metaData
	 */
	private static function metaContainsChildMarker( array $metaData ): bool {
		foreach ( $metaData as $meta ) {
			if ( is_array( $meta ) && ( $meta['key'] ?? '' ) === MetaKeys::LINE_COMPONENT ) {
				return true;
			}
		}

		return false;
	}
}
