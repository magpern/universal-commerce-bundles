<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * Hidden-component visibility and direct-URL policy (ADR-0005) — governs
 * the *catalogue product page* for a component, independent of the
 * order/cart line-item mechanism (CartConstruction/Presentation).
 *
 * All switches are reversible per-product settings, defaulting to hidden/
 * not-purchasable-standalone; see docs/adr/0005-*.md for the full policy
 * table.
 */
final class ComponentVisibility {

	/**
	 * Request-scoped flag: true only while this plugin's own cart
	 * construction is adding a child line internally, so the standalone
	 * not-purchasable-alone gate never blocks the plugin's own mechanism.
	 */
	private bool $internalAddInProgress = false;

	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'blockStandaloneAddToCart' ), 10, 3 );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'hideFromCatalog' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'excludeFromSitemap' ), 10, 2 );
		add_filter( 'woocommerce_rest_product_object_query', array( $this, 'excludeFromRestListing' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'redirectOrFourOhFourDirectVisits' ) );

		add_action( 'ucb_internal_add_to_cart_before', array( $this, 'markInternalAddStart' ) );
		add_action( 'ucb_internal_add_to_cart_after', array( $this, 'markInternalAddEnd' ) );
	}

	public function markInternalAddStart(): void {
		$this->internalAddInProgress = true;
	}

	public function markInternalAddEnd(): void {
		$this->internalAddInProgress = false;
	}

	public function blockStandaloneAddToCart( bool $passed, int $productId, int $quantity ): bool {
		unset( $quantity );

		if ( $this->internalAddInProgress ) {
			return $passed;
		}

		if ( 'yes' === get_post_meta( $productId, MetaKeys::PRODUCT_NOT_PURCHASABLE_ALONE, true ) ) {
			wc_add_notice( __( 'This product is only available as part of a kit.', 'universal-commerce-bundles' ), 'error' );

			return false;
		}

		return $passed;
	}

	public function hideFromCatalog( bool $visible, int $productId ): bool {
		if ( ! $visible ) {
			return $visible;
		}

		if ( 'yes' === get_post_meta( $productId, MetaKeys::PRODUCT_HIDDEN_FROM_CATALOG, true ) ) {
			return false;
		}

		return $visible;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function excludeFromSitemap( array $args, string $postType ): array {
		if ( 'product' !== $postType ) {
			return $args;
		}

		$args['meta_query'] = array_merge(
			$args['meta_query'] ?? array(),
			array(
				array(
					'key'     => MetaKeys::PRODUCT_HIDDEN_FROM_CATALOG,
					'compare' => 'NOT EXISTS',
				),
			)
		);

		return $args;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function excludeFromRestListing( array $args, \WP_REST_Request $request ): array {
		unset( $request );

		$args['meta_query'] = array_merge(
			$args['meta_query'] ?? array(),
			array(
				array(
					'key'     => MetaKeys::PRODUCT_HIDDEN_FROM_CATALOG,
					'compare' => 'NOT EXISTS',
				),
			)
		);

		return $args;
	}

	public function redirectOrFourOhFourDirectVisits(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$productId = get_queried_object_id();

		if ( 0 === $productId || 'yes' !== get_post_meta( $productId, MetaKeys::PRODUCT_HIDDEN_FROM_CATALOG, true ) ) {
			return;
		}

		$canonicalKitId = (int) get_post_meta( $productId, MetaKeys::PRODUCT_CANONICAL_KIT_ID, true );

		if ( $canonicalKitId > 0 ) {
			$url = get_permalink( $canonicalKitId );

			if ( is_string( $url ) && '' !== $url ) {
				wp_safe_redirect( $url, 301 );
				exit;
			}
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	}
}
