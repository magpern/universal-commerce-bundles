<?php

declare(strict_types=1);

// Minimal in-memory stand-in for WooCommerce's real WC_Order class — see
// the note on WC_Order_Item_Product in WCOrderItemProductStub.php.

if ( ! class_exists( 'WC_Order', false ) ) {
	class WC_Order {
		/** @var array<int, object> */
		private array $items;

		/**
		 * @param array<int, object> $items Order items keyed by order item id.
		 */
		public function __construct( array $items ) {
			$this->items = $items;
		}

		/**
		 * @return array<int, object>
		 */
		public function get_items() {
			return $this->items;
		}

		public function get_item( int $itemId ) {
			return $this->items[ $itemId ] ?? null;
		}
	}
}
