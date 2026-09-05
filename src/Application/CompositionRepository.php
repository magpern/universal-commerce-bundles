<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Application;

use UniversalCommerceBundles\Domain\Composition;
use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * Reads and writes a kit's composition and its cached validity hint, as
 * ordinary WordPress post meta on the kit product's post id. Uses only
 * generic WordPress meta functions — no WooCommerce symbols — because
 * kit composition is, at the storage level, just product meta; the
 * WooCommerce confinement rule (docs/ARCHITECTURE.md) is about
 * WooCommerce-prefixed classes and functions, not about WordPress core
 * functions.
 */
final class CompositionRepository {

	public function isKit( int $productId ): bool {
		return 'yes' === get_post_meta( $productId, MetaKeys::PRODUCT_IS_KIT, true );
	}

	public function markAsKit( int $productId ): void {
		update_post_meta( $productId, MetaKeys::PRODUCT_IS_KIT, 'yes' );
	}

	public function unmarkAsKit( int $productId ): void {
		delete_post_meta( $productId, MetaKeys::PRODUCT_IS_KIT );
	}

	public function getComposition( int $productId ): Composition {
		$raw = get_post_meta( $productId, MetaKeys::PRODUCT_COMPOSITION, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return Composition::fromRows( array() );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return Composition::fromRows( array() );
		}

		return Composition::fromRows( $decoded );
	}

	public function saveComposition( int $productId, Composition $composition ): void {
		update_post_meta( $productId, MetaKeys::PRODUCT_COMPOSITION, wp_json_encode( $composition->toArray() ) );
	}

	/**
	 * The cached "composition valid" flag: a display hint only, never
	 * authoritative (docs/ARCHITECTURE.md).
	 */
	public function getCachedValidityHint( int $productId ): bool {
		return 'yes' === get_post_meta( $productId, MetaKeys::PRODUCT_COMPOSITION_VALID_HINT, true );
	}

	public function setCachedValidityHint( int $productId, bool $valid ): void {
		update_post_meta( $productId, MetaKeys::PRODUCT_COMPOSITION_VALID_HINT, $valid ? 'yes' : 'no' );
	}
}
