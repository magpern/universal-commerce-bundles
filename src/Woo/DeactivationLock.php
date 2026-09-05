<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * ADR-0006 policy items 1-2: "Deactivation writes persistent safety state
 * before completing" and "Reactivation does not auto-unlock." This is this
 * plugin's own responsibility (as opposed to the host MU-plugin guard,
 * which is a separate repository, out of scope here) — the data these
 * write survive even when no plugin code runs at all.
 */
final class DeactivationLock {

	/**
	 * Called from the deactivation hook. Marks every known kit
	 * non-purchasable through persisted product state (out-of-stock status
	 * plus the `_ucb_locked` marker), which survives independently of
	 * whether this plugin's code ever runs again.
	 */
	public function lockAllKits(): void {
		foreach ( $this->allKitProductIds() as $kitId ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $kitId ) : false;

			if ( $product instanceof \WC_Product ) {
				$product->set_stock_status( 'outofstock' );
				$product->save();
			}

			update_post_meta( $kitId, MetaKeys::PRODUCT_LOCKED_BY_DEACTIVATION, 'yes' );
		}
	}

	/**
	 * Explicit admin action only — reactivation never calls this
	 * automatically (ADR-0006 item 2: "the reason for deactivation is
	 * unknown to the plugin and stock may have moved meanwhile").
	 * Revalidates composition before offering/performing the unlock.
	 */
	public function unlock( int $kitId, KitAvailability $availability ): bool {
		if ( ! $availability->validate( $kitId )->valid ) {
			return false;
		}

		delete_post_meta( $kitId, MetaKeys::PRODUCT_LOCKED_BY_DEACTIVATION );

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $kitId ) : false;

		if ( $product instanceof \WC_Product ) {
			$product->set_stock_status( 'instock' );
			$product->save();
		}

		return true;
	}

	public function isLocked( int $kitId ): bool {
		return 'yes' === get_post_meta( $kitId, MetaKeys::PRODUCT_LOCKED_BY_DEACTIVATION, true );
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
				'meta_key'       => MetaKeys::PRODUCT_IS_KIT,
				'meta_value'     => 'yes',
			)
		);

		return array_map( 'intval', $ids );
	}
}
