<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * Architecture B cart construction (docs/ARCHITECTURE.md, ADR-0002,
 * ADR-0003): one add-to-cart of a kit produces one priced parent cart line
 * plus one real, zero-priced child cart line per component. Parent
 * quantity changes (however they happen — a fresh add-to-cart merge, or a
 * classic/Store API quantity edit) synchronise children; a standalone
 * purchase of the same product never merges with a kit-linked child;
 * removing the parent removes its children; customers cannot directly
 * manipulate child quantities.
 */
final class CartConstruction {

	public function __construct(
		private readonly CompositionRepository $compositions,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_add_to_cart', array( $this, 'maybeAddChildren' ), 10, 6 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'syncAndZeroChildren' ), 100 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'cascadeRemoveChildren' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'renderChildQuantity' ), 10, 3 );
		add_action( 'wp_loaded', array( $this, 'stripChildQuantityFromCartUpdateRequest' ), 5 );
	}

	/**
	 * @param array<string, mixed> $cartItemData
	 */
	public function maybeAddChildren( string $cartItemKey, int $productId, int $quantity, int $variationId, array $variationAttributes, array $cartItemData ): void {
		unset( $variationAttributes, $cartItemData );

		if ( ! $this->compositions->isKit( $productId ) ) {
			return;
		}

		if ( $this->hasExistingChildren( $cartItemKey ) ) {
			// A repeat add-to-cart merged into an existing parent line
			// (same product, same cart-item data => WooCommerce's own
			// merge, stable cart item key). Its children already exist and
			// are synchronised to the new quantity by
			// syncAndZeroChildren() below — do not add a second set.
			return;
		}

		$composition = $this->compositions->getComposition( $productId );

		if ( $composition->isEmpty() ) {
			return;
		}

		$cart     = WC()->cart;
		$position = 0;

		foreach ( $composition->components as $component ) {
			$childQty = $component->qtyPerKit * $quantity;

			if ( $childQty <= 0 ) {
				continue;
			}

			/**
			 * Fires immediately before this plugin adds a component child
			 * line to the cart on its own behalf, so ComponentVisibility can
			 * bypass its standalone not-purchasable-alone gate for exactly
			 * this internal call.
			 *
			 * @since 0.1.0-dev
			 */
			do_action( 'ucb_internal_add_to_cart_before' );

			$cart->add_to_cart(
				$component->productId,
				$childQty,
				$component->variationId,
				array(),
				array(
					MetaKeys::LINE_COMPONENT        => 1,
					MetaKeys::LINE_PARENT_ITEM_ID   => $cartItemKey,
					MetaKeys::LINE_SNAPSHOT_VERSION => MetaKeys::SNAPSHOT_VERSION,
					MetaKeys::LINE_POSITION         => $position,
					'unique_key'                    => wp_generate_uuid4(),
				)
			);

			/**
			 * Fires immediately after the internal add above completes —
			 * the counterpart to `ucb_internal_add_to_cart_before`.
			 *
			 * @since 0.1.0-dev
			 */
			do_action( 'ucb_internal_add_to_cart_after' );

			++$position;
		}
	}

	/**
	 * Every cart recalculation: derives each child's correct quantity from
	 * its parent's *current* quantity (however it got there) and zeros the
	 * child's price/weight/dimensions/shipping-class on the in-cart
	 * product clone (never persisted back to the real product) — ADR-0002,
	 * ADR-0007.
	 */
	public function syncAndZeroChildren( \WC_Cart $cart ): void {
		$contents       = $cart->get_cart();
		$parentQtyByKey = array();

		foreach ( $contents as $key => $item ) {
			if ( empty( $item[ MetaKeys::LINE_COMPONENT ] ) ) {
				$parentQtyByKey[ $key ] = (int) $item['quantity'];
			}
		}

		foreach ( $contents as $key => $item ) {
			if ( empty( $item[ MetaKeys::LINE_COMPONENT ] ) ) {
				continue;
			}

			if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				$item['data']->set_price( '0' );
				$item['data']->set_regular_price( '0' );
				$item['data']->set_sale_price( '' );
				$item['data']->set_weight( '0' );
				$item['data']->set_length( '0' );
				$item['data']->set_width( '0' );
				$item['data']->set_height( '0' );
				$item['data']->set_shipping_class_id( 0 );
			}

			$parentKey = (string) ( $item[ MetaKeys::LINE_PARENT_ITEM_ID ] ?? '' );

			if ( '' === $parentKey || ! isset( $parentQtyByKey[ $parentKey ] ) ) {
				continue;
			}

			$parentProductId = (int) $contents[ $parentKey ]['product_id'];
			$qtyPerKit       = $this->qtyPerKitFor( $parentProductId, (int) $item['product_id'], (int) $item['variation_id'] );

			if ( null === $qtyPerKit ) {
				continue;
			}

			$expectedQty = $parentQtyByKey[ $parentKey ] * $qtyPerKit;

			if ( (int) $cart->cart_contents[ $key ]['quantity'] !== $expectedQty ) {
				$cart->cart_contents[ $key ]['quantity'] = $expectedQty;
			}
		}
	}

	public function cascadeRemoveChildren( string $cartItemKey, \WC_Cart $cart ): void {
		unset( $cartItemKey );

		// The removed item is already gone from $cart->get_cart(); any
		// remaining child whose parent key no longer resolves to a real
		// cart line is an orphan and must be removed too (no orphaned
		// child lines are possible — docs/ARCHITECTURE.md).
		$contents     = $cart->get_cart();
		$existingKeys = array_keys( $contents );

		foreach ( $contents as $key => $item ) {
			if ( empty( $item[ MetaKeys::LINE_COMPONENT ] ) ) {
				continue;
			}

			$parentKey = (string) ( $item[ MetaKeys::LINE_PARENT_ITEM_ID ] ?? '' );

			if ( '' === $parentKey || ! in_array( $parentKey, $existingKeys, true ) ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * @param array<string, mixed> $cartItem
	 */
	public function renderChildQuantity( string $html, string $cartItemKey, array $cartItem ): string {
		unset( $cartItemKey );

		if ( empty( $cartItem[ MetaKeys::LINE_COMPONENT ] ) ) {
			return $html;
		}

		return sprintf( '<span class="ucb-component-quantity">%d</span>', (int) $cartItem['quantity'] );
	}

	/**
	 * Classic cart-update guard: strips any direct quantity-change attempt
	 * on a child line from the raw update-cart request before WooCommerce
	 * processes it, so a crafted form post cannot move a child's quantity
	 * out of sync even for the single request before the next
	 * recalculation would have corrected it anyway.
	 */
	public function stripChildQuantityFromCartUpdateRequest(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only existence/shape check; WooCommerce's own update-cart handler (hooked later, at the default priority, on this same wp_loaded action) verifies its own "woocommerce-cart" nonce before acting on this data. This guard only ever removes entries, never uses their values.
		if ( ! isset( $_POST['update_cart'], $_POST['cart'] ) || ! is_array( $_POST['cart'] ) ) {
			return;
		}

		if ( null === WC()->cart ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the keys (cart item keys) are read, immediately sanitized below; values are never read, only unset.
		$rawCart      = $_POST['cart'];
		$cartItemKeys = array_map( 'sanitize_text_field', array_map( 'wp_unslash', array_keys( $rawCart ) ) );

		foreach ( $cartItemKeys as $index => $cartItemKey ) {
			$item = WC()->cart->get_cart_item( $cartItemKey );

			if ( is_array( $item ) && ! empty( $item[ MetaKeys::LINE_COMPONENT ] ) ) {
				$originalKey = array_keys( $rawCart )[ $index ];
				unset( $_POST['cart'][ $originalKey ] );
			}
		}
	}

	private function hasExistingChildren( string $parentCartItemKey ): bool {
		if ( null === WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ( $item[ MetaKeys::LINE_PARENT_ITEM_ID ] ?? null ) === $parentCartItemKey ) {
				return true;
			}
		}

		return false;
	}

	private function qtyPerKitFor( int $kitProductId, int $componentProductId, int $componentVariationId ): ?int {
		$composition = $this->compositions->getComposition( $kitProductId );

		foreach ( $composition->components as $component ) {
			if ( $component->productId === $componentProductId && $component->variationId === $componentVariationId ) {
				return $component->qtyPerKit;
			}
		}

		return null;
	}
}
