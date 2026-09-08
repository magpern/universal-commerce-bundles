<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Domain\MetaKeys;
use UniversalCommerceBundles\Engine\KitOverviewAssessment;

/**
 * WooCommerce-backed read model for the M2 Bundles overview. Keeps all
 * WC_Product / wc_* access inside the Woo confinement boundary so
 * src/Admin never references WooCommerce symbols.
 */
final class KitOverviewData {

	public function __construct(
		private readonly CompositionRepository $compositions,
		private readonly KitAvailability $availability,
	) {
	}

	/**
	 * @param array<string, mixed> $args Query args: page, per_page, search, orderby, order.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int}
	 */
	public function queryKits( array $args ): array {
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$perPage = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$search  = isset( $args['search'] ) ? (string) $args['search'] : '';
		$orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'title';
		$order   = isset( $args['order'] ) && 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC';

		$queryArgs = array(
			'post_type'              => 'product',
			'post_status'            => 'any',
			'posts_per_page'         => $perPage,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'order'                  => $order,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'   => MetaKeys::PRODUCT_IS_KIT,
					'value' => 'yes',
				),
			),
		);

		$queryArgs['orderby'] = match ( $orderby ) {
			'id'   => 'ID',
			'date' => 'date',
			default => 'title',
		};

		if ( '' !== $search ) {
			$queryArgs['s'] = $search;
		}

		$query = new \WP_Query( $queryArgs );
		$ids   = array_map( 'intval', $query->posts );
		$items = array();

		foreach ( $ids as $kitId ) {
			$row = $this->rowFor( $kitId );

			if ( null !== $row ) {
				$items[] = $row;
			}
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function rowFor( int $kitId ): ?array {
		if ( ! $this->compositions->isKit( $kitId ) ) {
			return null;
		}

		$product = wc_get_product( $kitId );

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$postStatus        = (string) $product->get_status();
		$catalogVisibility = (string) $product->get_catalog_visibility();
		$assessment        = $this->availability->assessOverview( $kitId, $postStatus, $catalogVisibility );
		$composition       = $this->compositions->getComposition( $kitId );
		$components        = array();

		foreach ( $composition->components as $component ) {
			$componentProduct = wc_get_product( $component->productId > 0 ? $component->productId : $component->stockManagedId );
			$components[]     = array(
				'product_id'  => $component->productId,
				'name'        => $componentProduct instanceof \WC_Product
					? $componentProduct->get_name()
					: sprintf( '#%d', $component->productId ),
				'qty_per_kit' => $component->qtyPerKit,
				'edit_link'   => get_edit_post_link( $component->productId, 'raw' ),
			);
		}

		return array(
			'id'                 => $kitId,
			'name'               => $product->get_name(),
			'sku'                => $product->get_sku(),
			'edit_link'          => get_edit_post_link( $kitId, 'raw' ),
			'regular_price'      => $product->get_regular_price(),
			'price'              => $product->get_price(),
			'post_status'        => $postStatus,
			'catalog_visibility' => $catalogVisibility,
			'components'         => $components,
			'assessment'         => $assessment,
		);
	}

	public function assessmentForProduct( int $kitId ): ?KitOverviewAssessment {
		$product = wc_get_product( $kitId );

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		return $this->availability->assessOverview(
			$kitId,
			(string) $product->get_status(),
			(string) $product->get_catalog_visibility()
		);
	}
}
