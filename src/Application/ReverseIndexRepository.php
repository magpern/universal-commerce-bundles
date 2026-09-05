<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Application;

use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * The reverse index from component stock-managed id => kit product ids
 * (docs/ARCHITECTURE.md, "Invalidation and reverse lookup"), stored as one
 * WordPress option. A rebuildable read model, not a source of truth — the
 * source of truth is always each kit's own composition meta
 * (CompositionRepository); this index only exists so a component save/
 * delete/stock/price/tax-class change can cheaply find which kits to
 * revalidate, without scanning every product.
 *
 * Keyed by stock-managed id as a plain int, not a string: a purely numeric
 * string used as a PHP array key is silently coerced back to int by the
 * language itself, so declaring these keys as strings would misdescribe
 * the real runtime type.
 */
final class ReverseIndexRepository {

	/**
	 * @return int[] kit product ids that list this stock-managed id as a component.
	 */
	public function kitsForComponent( int $stockManagedId ): array {
		$index = $this->readIndex();

		return array_map( 'intval', $index[ $stockManagedId ] ?? array() );
	}

	/**
	 * Replaces every entry this kit id appears under, with the given set of
	 * stock-managed ids (typically called with the kit's freshly-saved
	 * Composition::stockManagedIds()).
	 *
	 * @param int[] $stockManagedIds
	 */
	public function setKitComponents( int $kitId, array $stockManagedIds ): void {
		$index = $this->readIndex();

		foreach ( $index as $componentKey => $kitIds ) {
			$index[ $componentKey ] = array_values( array_diff( $kitIds, array( $kitId ) ) );

			if ( array() === $index[ $componentKey ] ) {
				unset( $index[ $componentKey ] );
			}
		}

		foreach ( $stockManagedIds as $stockManagedId ) {
			if ( ! isset( $index[ $stockManagedId ] ) ) {
				$index[ $stockManagedId ] = array();
			}

			if ( ! in_array( $kitId, $index[ $stockManagedId ], true ) ) {
				$index[ $stockManagedId ][] = $kitId;
			}
		}

		$this->writeIndex( $index );
	}

	public function removeKit( int $kitId ): void {
		$this->setKitComponents( $kitId, array() );
	}

	/**
	 * Rebuild routine (docs/ARCHITECTURE.md: "for use after imports, bulk
	 * edits or a restore — anything that bypasses the save hooks").
	 *
	 * @param array<int, int[]> $kitIdToStockManagedIds every known kit id
	 *     mapped to its current composition's stock-managed ids, gathered
	 *     by the Woo adapter (which knows how to enumerate kit products).
	 */
	public function rebuild( array $kitIdToStockManagedIds ): void {
		$index = array();

		foreach ( $kitIdToStockManagedIds as $kitId => $stockManagedIds ) {
			foreach ( $stockManagedIds as $stockManagedId ) {
				if ( ! isset( $index[ $stockManagedId ] ) ) {
					$index[ $stockManagedId ] = array();
				}

				if ( ! in_array( $kitId, $index[ $stockManagedId ], true ) ) {
					$index[ $stockManagedId ][] = $kitId;
				}
			}
		}

		$this->writeIndex( $index );
	}

	/**
	 * @return array<int, int[]>
	 */
	private function readIndex(): array {
		$raw = get_option( MetaKeys::OPTION_REVERSE_INDEX, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$index = array();

		foreach ( $raw as $key => $kitIds ) {
			$index[ (int) $key ] = array_values( array_map( 'intval', (array) $kitIds ) );
		}

		return $index;
	}

	/**
	 * @param array<int, int[]> $index
	 */
	private function writeIndex( array $index ): void {
		update_option( MetaKeys::OPTION_REVERSE_INDEX, $index, false );
	}
}
