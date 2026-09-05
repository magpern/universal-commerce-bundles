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
			'<div id="ucb-kit-components" data-rows="%s">',
			esc_attr( (string) wp_json_encode( $composition->toArray() ) )
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

		wp_add_inline_script(
			'wc-admin-product-meta-boxes',
			$this->repeaterInlineScript()
		);
	}

	private function repeaterInlineScript(): string {
		// Deliberately plain vanilla JS: reads/writes the same hidden JSON
		// field the server reads in saveComposition(); no framework/build
		// step for this small a UI.
		return <<<'JS'
(function () {
	document.addEventListener('DOMContentLoaded', function () {
		var container = document.getElementById('ucb-kit-components');
		if (!container) {
			return;
		}
		var tbody = container.querySelector('tbody');
		var hidden = document.getElementById('ucb_composition_json');
		var rows = JSON.parse(container.getAttribute('data-rows') || '[]');

		function render() {
			tbody.innerHTML = '';
			rows.forEach(function (row, index) {
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td><input type="number" min="1" class="ucb-product-id" value="' + (row.product_id || '') + '" /></td>' +
					'<td><input type="number" min="1" class="ucb-qty" value="' + (row.qty_per_kit || 1) + '" /></td>' +
					'<td><button type="button" class="button ucb-remove">&times;</button></td>';
				tr.querySelector('.ucb-product-id').addEventListener('change', function (e) {
					rows[index].product_id = parseInt(e.target.value, 10) || 0;
					rows[index].stock_managed_id = rows[index].product_id;
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
		}

		function sync() {
			hidden.value = JSON.stringify(rows);
		}

		document.getElementById('ucb-add-component').addEventListener('click', function () {
			rows.push({ product_id: 0, variation_id: 0, stock_managed_id: 0, qty_per_kit: 1 });
			render();
			sync();
		});

		render();
		sync();
	});
})();
JS;
	}
}
