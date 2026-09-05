<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Domain\KitSnapshot;
use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * Copies Architecture B's cart-item linkage onto real order line items at
 * checkout (ADR-0003), on both the classic and Store API/Blocks paths —
 * both funnel through the same `WC_Checkout::create_order()` machinery and
 * therefore the same two real, documented hooks used here.
 *
 * Two passes, because order-item ids do not exist until the order is
 * saved: pass 1 (`woocommerce_checkout_create_order_line_item`, before
 * save) copies cart-item meta and stamps the still-cart-item-key-shaped
 * parent link, and writes the kit snapshot on the parent (which needs no
 * order-item id); pass 2 (`woocommerce_checkout_order_created`, after
 * save) resolves every child's parent link from the parent's cart item key
 * to the parent's now-real order item id.
 */
final class OrderConstruction {

	private const TEMP_CART_KEY_META = '_ucb_temp_cart_key';

	public function __construct(
		private readonly CompositionRepository $compositions,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copyLineItemMeta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'resolveParentLinks' ) );
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function copyLineItemMeta( \WC_Order_Item_Product $item, string $cartItemKey, array $values, \WC_Order $order ): void {
		unset( $order );

		if ( ! empty( $values[ MetaKeys::LINE_COMPONENT ] ) ) {
			$item->add_meta_data( MetaKeys::LINE_COMPONENT, '1', true );
			$item->add_meta_data( MetaKeys::LINE_PARENT_ITEM_ID, (string) ( $values[ MetaKeys::LINE_PARENT_ITEM_ID ] ?? '' ), true );
			$item->add_meta_data( MetaKeys::LINE_SNAPSHOT_VERSION, (string) ( (int) ( $values[ MetaKeys::LINE_SNAPSHOT_VERSION ] ?? MetaKeys::SNAPSHOT_VERSION ) ), true );
			$item->add_meta_data( MetaKeys::LINE_POSITION, (string) ( (int) ( $values[ MetaKeys::LINE_POSITION ] ?? 0 ) ), true );

			return;
		}

		$productId = $item->get_product_id();

		if ( ! $this->compositions->isKit( $productId ) ) {
			return;
		}

		$item->add_meta_data( self::TEMP_CART_KEY_META, $cartItemKey, true );
		$item->add_meta_data( MetaKeys::LINE_KIT_SNAPSHOT, wp_json_encode( $this->buildSnapshot( $productId, $item->get_quantity() ) ), true );
	}

	public function resolveParentLinks( \WC_Order $order ): void {
		$cartKeyToOrderItemId = array();
		$childItemIds         = array();

		foreach ( $order->get_items() as $itemId => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$tempKey = $item->get_meta( self::TEMP_CART_KEY_META, true );

			if ( '' !== $tempKey ) {
				$cartKeyToOrderItemId[ $tempKey ] = $itemId;
				$item->delete_meta_data( self::TEMP_CART_KEY_META );
				$item->save_meta_data();

				continue;
			}

			if ( $item->get_meta( MetaKeys::LINE_COMPONENT, true ) ) {
				$childItemIds[] = $itemId;
			}
		}

		if ( array() === $cartKeyToOrderItemId || array() === $childItemIds ) {
			return;
		}

		foreach ( $childItemIds as $itemId ) {
			$item      = $order->get_item( $itemId );
			$parentKey = $item instanceof \WC_Order_Item_Product ? $item->get_meta( MetaKeys::LINE_PARENT_ITEM_ID, true ) : '';

			if ( ! isset( $cartKeyToOrderItemId[ $parentKey ] ) ) {
				continue;
			}

			$item->update_meta_data( MetaKeys::LINE_PARENT_ITEM_ID, (string) $cartKeyToOrderItemId[ $parentKey ] );
			$item->save_meta_data();
		}
	}

	/**
	 * @return array{v: int, kit_id: int, kit_sku: string, kit_qty: int, components: array<int, array<string, mixed>>}
	 */
	private function buildSnapshot( int $kitProductId, int $kitQty ): array {
		$product     = wc_get_product( $kitProductId );
		$sku         = $product instanceof \WC_Product ? $product->get_sku() : '';
		$composition = $this->compositions->getComposition( $kitProductId );

		$details = array_map(
			function ( $component ) {
				$componentProduct = wc_get_product( $component->variationId > 0 ? $component->variationId : $component->productId );

				return array(
					'stock_managed_id' => $component->stockManagedId,
					'product_id'       => $component->productId,
					'variation_id'     => $component->variationId,
					'sku'              => $componentProduct instanceof \WC_Product ? $componentProduct->get_sku() : '',
					'name'             => $componentProduct instanceof \WC_Product ? $componentProduct->get_name() : '',
					'qty_per_kit'      => $component->qtyPerKit,
				);
			},
			$composition->components
		);

		return KitSnapshot::build( $kitProductId, $sku, $kitQty, $details )->toArray();
	}
}
