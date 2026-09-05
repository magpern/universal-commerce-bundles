<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Application\ReverseIndexRepository;

/**
 * "On component save, delete, unpublish, stock change, price change or
 * tax-class change, mark the affected kits for revalidation, refresh the
 * cached hint, and raise an admin notice." (docs/ARCHITECTURE.md)
 *
 * Also provides the maintain-the-index write path (KitAvailability owns
 * reading composition + revalidating; this class owns keeping the reverse
 * index and the admin notice queue current) and the reconciliation
 * routine for imports/bulk edits/restores that bypass save hooks.
 */
final class Invalidation {

	private const NOTICE_OPTION = 'ucb_invalidation_notices';

	public function __construct(
		private readonly CompositionRepository $compositions,
		private readonly ReverseIndexRepository $reverseIndex,
		private readonly KitAvailability $availability,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_update_product', array( $this, 'handleComponentChanged' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'handleComponentRemoved' ) );
		add_action( 'woocommerce_trash_product', array( $this, 'handleComponentRemoved' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'handleComponentStockChanged' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'handleComponentStockChanged' ) );

		// A kit is itself a product; saving it must (re)index its own
		// composition against the reverse index.
		add_action( 'woocommerce_update_product', array( $this, 'reindexIfKit' ), 20 );

		add_action( 'admin_notices', array( $this, 'renderNotices' ) );
	}

	public function handleComponentChanged( int $productId ): void {
		$this->revalidateKitsFor( $productId );
	}

	public function handleComponentRemoved( int $productId ): void {
		$this->revalidateKitsFor( $productId );
	}

	public function handleComponentStockChanged( \WC_Product $product ): void {
		$this->revalidateKitsFor( $product->get_id() );
	}

	public function reindexIfKit( int $productId ): void {
		if ( ! $this->compositions->isKit( $productId ) ) {
			return;
		}

		$composition = $this->compositions->getComposition( $productId );
		$this->reverseIndex->setKitComponents( $productId, $composition->stockManagedIds() );
		$this->availability->validate( $productId );
	}

	private function revalidateKitsFor( int $stockManagedId ): void {
		foreach ( $this->reverseIndex->kitsForComponent( $stockManagedId ) as $kitId ) {
			$result = $this->availability->validate( $kitId );

			if ( ! $result->valid ) {
				$this->queueNotice( $kitId, $result );
			}
		}
	}

	private function queueNotice( int $kitId, \UniversalCommerceBundles\Engine\ValidationResult $result ): void {
		$notices           = get_option( self::NOTICE_OPTION, array() );
		$notices           = is_array( $notices ) ? $notices : array();
		$notices[ $kitId ] = array(
			'missing'     => $result->missingComponentIds,
			'unpublished' => $result->unpublishedComponentIds,
			'mixed_tax'   => $result->mixedTaxClasses,
		);

		update_option( self::NOTICE_OPTION, $notices, false );
	}

	public function renderNotices(): void {
		$notices = get_option( self::NOTICE_OPTION, array() );

		if ( ! is_array( $notices ) || array() === $notices ) {
			return;
		}

		foreach ( $notices as $kitId => $reason ) {
			$product = wc_get_product( (int) $kitId );
			$name    = $product instanceof \WC_Product ? $product->get_name() : sprintf( '#%d', (int) $kitId );

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: kit product name. */
						__( 'Universal Commerce Bundles: kit "%s" has an invalid composition (missing, unpublished, or mixed-tax-class component) and is not purchasable until corrected.', 'universal-commerce-bundles' ),
						$name
					)
				)
			);
		}
	}

	/**
	 * Rebuilds the reverse index and revalidates every kit — for use after
	 * an import, a bulk edit, or a restore that bypassed the save hooks
	 * above (docs/ARCHITECTURE.md).
	 */
	public function reconcileAll(): void {
		$kitIds = $this->allKitProductIds();
		$map    = array();

		foreach ( $kitIds as $kitId ) {
			$map[ $kitId ] = $this->compositions->getComposition( $kitId )->stockManagedIds();
		}

		$this->reverseIndex->rebuild( $map );

		foreach ( $kitIds as $kitId ) {
			$this->availability->validate( $kitId );
		}
	}

	/**
	 * @return int[]
	 */
	private function allKitProductIds(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
				'meta_key'       => \UniversalCommerceBundles\Domain\MetaKeys::PRODUCT_IS_KIT,
				'meta_value'     => 'yes',
			)
		);

		return array_map( 'intval', $ids );
	}
}
