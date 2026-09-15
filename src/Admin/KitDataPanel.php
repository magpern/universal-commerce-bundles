<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Admin;

use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Application\ReverseIndexRepository;
use UniversalCommerceBundles\Domain\Composition;
use UniversalCommerceBundles\Woo\AdminFields;
use UniversalCommerceBundles\Woo\KitAvailability;

/**
 * A minimal, functional "Kit Components" WooCommerce product-data tab: an
 * admin marks a product as a kit and lists its components (product id +
 * qty-per-kit) as a small JSON-backed table. Deliberately simple — no
 * build step, no bundled JS framework, reusing WooCommerce's own
 * `wc-product-search` select2 widget (already enqueued by WooCommerce on
 * the product edit screen; no new AJAX endpoint needed).
 */
final class KitDataPanel {

	public function __construct(
		private readonly CompositionRepository $compositions,
		private readonly ReverseIndexRepository $reverseIndex,
		private readonly KitAvailability $availability,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'addTab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'renderPanel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'saveComposition' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueRepeaterScript' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs
	 * @return array<string, array<string, mixed>>
	 */
	public function addTab( array $tabs ): array {
		$tabs['ucb_kit'] = array(
			'label'    => __( 'Kit Components', 'universal-commerce-bundles' ),
			'target'   => 'ucb_kit_data',
			'class'    => array( 'show_if_simple' ),
			'priority' => 21,
		);

		return $tabs;
	}

	public function renderPanel(): void {
		global $post;

		$productId   = (int) $post->ID;
		$isKit       = $this->compositions->isKit( $productId );
		$composition = $this->compositions->getComposition( $productId );

		// Display-only rows (composition rows plus a resolved product title
		// for each existing component) so the repeater can pre-populate the
		// wc-product-search select2 widget's selected option on load. Never
		// sent to the server as-is: sync() below rebuilds the plain
		// stock_managed_id/product_id/variation_id/qty_per_kit shape that
		// saveComposition()/Composition::fromRows() expect.
		$displayRows = array_map(
			static function ( array $row ): array {
				$row['product_name'] = $row['product_id'] > 0
					? ( get_the_title( $row['product_id'] ) ?: sprintf( '#%d', $row['product_id'] ) )
					: '';

				return $row;
			},
			$composition->toArray()
		);

		echo '<div id="ucb_kit_data" class="panel woocommerce_options_panel">';

		AdminFields::checkbox(
			array(
				'id'          => '_ucb_is_kit_cb',
				'label'       => __( 'This is a fixed kit', 'universal-commerce-bundles' ),
				'description' => __( 'Sold as one SKU, composed of a fixed set of other products.', 'universal-commerce-bundles' ),
				'value'       => $isKit ? 'yes' : 'no',
			)
		);

		printf(
			'<p class="form-field"><label>%s</label></p>',
			esc_html__( 'Components (product, quantity per kit):', 'universal-commerce-bundles' )
		);

		printf(
			'<div id="ucb-kit-components" data-rows="%s" data-search-placeholder="%s">',
			esc_attr( (string) wp_json_encode( $displayRows ) ),
			esc_attr__( 'Search for a product&hellip;', 'universal-commerce-bundles' )
		);
		echo '<table class="widefat ucb-kit-components-table"><thead><tr>'
			. '<th>' . esc_html__( 'Product', 'universal-commerce-bundles' ) . '</th>'
			. '<th>' . esc_html__( 'Qty per kit', 'universal-commerce-bundles' ) . '</th>'
			. '<th></th></tr></thead><tbody></tbody></table>';
		printf(
			'<button type="button" class="button" id="ucb-add-component">%s</button>',
			esc_html__( 'Add component', 'universal-commerce-bundles' )
		);
		echo '<input type="hidden" name="ucb_composition_json" id="ucb_composition_json" value="' . esc_attr( (string) wp_json_encode( $composition->toArray() ) ) . '" />';
		echo '</div>';

		echo '</div>';
	}

	public function saveComposition( int $productId ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's own product-save nonce ("woocommerce_meta_nonce") already gates this entire action; verified by WooCommerce core before woocommerce_process_product_meta fires.
		$isKit = isset( $_POST['_ucb_is_kit_cb'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['_ucb_is_kit_cb'] ) );

		if ( $isKit ) {
			$this->compositions->markAsKit( $productId );
		} else {
			$this->compositions->unmarkAsKit( $productId );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- this is JSON, not text: sanitize_text_field() would corrupt valid JSON syntax. The real sanitization boundary is json_decode() immediately below plus Composition::fromRows()'s own strict (int)/(string) casting on every field of every row, which admits no data this class did not itself construct the shape of.
		$raw  = isset( $_POST['ucb_composition_json'] ) ? wp_unslash( $_POST['ucb_composition_json'] ) : '[]';
		$rows = json_decode( is_string( $raw ) ? $raw : '[]', true );

		$composition = Composition::fromRows( is_array( $rows ) ? $rows : array() );
		$this->compositions->saveComposition( $productId, $composition );
		$this->reverseIndex->setKitComponents( $productId, $isKit ? $composition->stockManagedIds() : array() );

		if ( $isKit ) {
			$this->availability->validate( $productId );
		}
	}

	public function enqueueRepeaterScript( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || 'product' !== $screen->post_type ) {
			return;
		}

		// A dedicated, always-registered handle: piggybacking this inline
		// script on a WooCommerce-owned handle (e.g.
		// 'wc-admin-product-meta-boxes') is a load-order race —
		// wp_add_inline_script() silently no-ops if that handle hasn't been
		// registered yet, which depends on plugin load order (UCB vs.
		// WooCommerce) and is not guaranteed.
		wp_register_script( 'ucb-kit-components-repeater', false, array( 'jquery', 'wc-enhanced-select' ), false, true );
		wp_enqueue_script( 'ucb-kit-components-repeater' );
		wp_add_inline_script(
			'ucb-kit-components-repeater',
			$this->repeaterInlineScript()
		);
	}

	private function repeaterInlineScript(): string {
		// Deliberately minimal JS reusing WooCommerce's own ajax product
		// search: a plain <select class="wc-product-search"> enhanced into
		// select2 via the 'wc-enhanced-select-init' event WooCommerce's own
		// wc-enhanced-select.js already listens for on document.body (same
		// widget WooCommerce's own Linked Products / Grouped Products
		// fields use) — no new AJAX endpoint, no bundled framework.
		return <<<'JS'
(function ($) {
	$(function () {
		var container = document.getElementById('ucb-kit-components');
		if (!container) {
			return;
		}
		var tbody = container.querySelector('tbody');
		var hidden = document.getElementById('ucb_composition_json');
		var placeholder = container.getAttribute('data-search-placeholder') || '';
		var rows = JSON.parse(container.getAttribute('data-rows') || '[]');

		function render() {
			tbody.innerHTML = '';
			rows.forEach(function (row, index) {
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td><select class="wc-product-search" style="width:100%" ' +
					'data-action="woocommerce_json_search_products_and_variations" ' +
					'data-placeholder="' + placeholder.replace(/"/g, '&quot;') + '"></select></td>' +
					'<td><input type="number" min="1" class="ucb-qty" value="' + (row.qty_per_kit || 1) + '" /></td>' +
					'<td><button type="button" class="button ucb-remove">&times;</button></td>';

				var $select = $(tr).find('.wc-product-search');

				if (row.product_id) {
					$select.append(new Option(row.product_name || ('#' + row.product_id), row.product_id, true, true));
				}

				$select.on('select2:select', function (e) {
					var id = parseInt(e.params.data.id, 10) || 0;
					rows[index].product_id = id;
					rows[index].stock_managed_id = id;
					sync();
				});

				tr.querySelector('.ucb-qty').addEventListener('change', function (e) {
					rows[index].qty_per_kit = parseInt(e.target.value, 10) || 1;
					sync();
				});
				tr.querySelector('.ucb-remove').addEventListener('click', function () {
					rows.splice(index, 1);
					render();
				});
				tbody.appendChild(tr);
			});

			$(document.body).trigger('wc-enhanced-select-init');
		}

		function sync() {
			hidden.value = JSON.stringify(rows.map(function (row) {
				return {
					product_id: row.product_id || 0,
					variation_id: row.variation_id || 0,
					stock_managed_id: row.stock_managed_id || row.product_id || 0,
					qty_per_kit: row.qty_per_kit || 1
				};
			}));
		}

		document.getElementById('ucb-add-component').addEventListener('click', function () {
			rows.push({ product_id: 0, variation_id: 0, stock_managed_id: 0, qty_per_kit: 1 });
			render();
			sync();
		});

		render();
		sync();
	});
})(jQuery);
JS;
	}
}
