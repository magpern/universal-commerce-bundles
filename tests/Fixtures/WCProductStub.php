<?php

declare(strict_types=1);

// Minimal stand-in for WooCommerce's WC_Product used by ProductFacts in
// Brain\Monkey unit tests (no live WooCommerce bootstrap).

if ( ! class_exists( 'WC_Product', false ) ) {
	class WC_Product {
		private int $id;
		private string $taxClass;
		private string $status;
		private string $catalogVisibility;
		private string $name;
		private string $sku;
		private string $price;
		private string $regularPrice;
		private bool $managingStock;
		private bool $backordersAllowed;
		private ?int $stockQuantity;

		public function __construct(
			int $id = 1,
			string $taxClass = '',
			string $status = 'publish',
			string $catalogVisibility = 'visible',
			string $name = 'Product',
			string $sku = '',
			string $price = '10',
			string $regularPrice = '10',
			bool $managingStock = true,
			bool $backordersAllowed = false,
			?int $stockQuantity = 5
		) {
			$this->id                = $id;
			$this->taxClass          = $taxClass;
			$this->status            = $status;
			$this->catalogVisibility = $catalogVisibility;
			$this->name              = $name;
			$this->sku               = $sku;
			$this->price             = $price;
			$this->regularPrice      = $regularPrice;
			$this->managingStock     = $managingStock;
			$this->backordersAllowed = $backordersAllowed;
			$this->stockQuantity     = $stockQuantity;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_tax_class(): string {
			return $this->taxClass;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_catalog_visibility(): string {
			return $this->catalogVisibility;
		}

		public function get_name(): string {
			return $this->name;
		}

		public function get_sku(): string {
			return $this->sku;
		}

		public function get_price(): string {
			return $this->price;
		}

		public function get_regular_price(): string {
			return $this->regularPrice;
		}

		public function managing_stock(): bool {
			return $this->managingStock;
		}

		public function backorders_allowed(): bool {
			return $this->backordersAllowed;
		}

		public function get_stock_quantity(): ?int {
			return $this->stockQuantity;
		}

		public function get_parent_id(): int {
			return 0;
		}
	}
}
