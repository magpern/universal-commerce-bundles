<?php

declare(strict_types=1);

// Minimal in-memory stand-in for WooCommerce's real WC_Order_Item_Product
// class, used only by OrderConstructionParentLinkResolutionTest — just
// enough of the meta API OrderConstruction::resolveParentLinks() uses.
// Not a general-purpose WooCommerce mock; see docs/m1-closure.md on why
// the rest of src/Woo/ is validated live rather than hand-mocked. Guarded
// so loading this file alongside a real WooCommerce autoload is harmless.

if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
	class WC_Order_Item_Product {
		/** @var array<string, string> */
		private array $meta;

		/**
		 * @param array<string, string> $meta Initial meta key/value pairs.
		 */
		public function __construct( array $meta = array() ) {
			$this->meta = $meta;
		}

		public function get_meta( string $key, bool $single = true ) {
			unset( $single );

			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = (string) $value;
		}

		public function delete_meta_data( string $key ): void {
			unset( $this->meta[ $key ] );
		}

		public function save_meta_data(): void {
			// In-memory only; nothing to persist in this stand-in.
		}
	}
}
