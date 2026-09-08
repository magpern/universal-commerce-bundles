<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Admin;

use UniversalCommerceBundles\Engine\KitOverviewAssessment;
use UniversalCommerceBundles\Woo\KitOverviewData;

/**
 * Read-only kit overview table for WooCommerce → Bundles.
 * Deliberately does not extend WP_List_Table so unit/static analysis can
 * load this class without a WordPress bootstrap (same constraint as the
 * rest of this repository's PHPUnit suite).
 */
final class BundlesListTable {

	/** @var list<array<string, mixed>> */
	private array $items = array();

	private int $totalItems = 0;

	private int $totalPages = 1;

	private int $perPage = 20;

	private int $page = 1;

	public function __construct(
		private readonly KitOverviewData $overviewData,
	) {
	}

	public function prepare_items(): void {
		$this->page = isset( $_REQUEST['paged'] ) ? max( 1, (int) $_REQUEST['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$search     = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby    = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) $_REQUEST['orderby'] ) : 'title'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order      = isset( $_REQUEST['order'] ) ? sanitize_key( (string) $_REQUEST['order'] ) : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = $this->overviewData->queryKits(
			array(
				'page'     => $this->page,
				'per_page' => $this->perPage,
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
			)
		);

		$this->items      = $result['items'];
		$this->totalItems = $result['total'];
		$this->totalPages = max( 1, $result['pages'] );
	}

	public function search_box( string $text, string $inputId ): void {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<p class="search-box"><label class="screen-reader-text" for="%1$s-search-input">%2$s</label>'
			. '<input type="search" id="%1$s-search-input" name="s" value="%3$s" />'
			. '<input type="submit" class="button" value="%2$s" /></p>',
			esc_attr( $inputId ),
			esc_attr( $text ),
			esc_attr( $search )
		);
	}

	public function display(): void {
		echo '<table class="wp-list-table widefat fixed striped table-view-list">';
		echo '<thead><tr>';
		foreach ( $this->columns() as $key => $label ) {
			printf( '<th scope="col" class="column-%s">%s</th>', esc_attr( $key ), $this->sortableHeader( $key, $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sortableHeader escapes.
		}
		echo '</tr></thead><tbody>';

		if ( array() === $this->items ) {
			printf(
				'<tr><td colspan="%d">%s</td></tr>',
				count( $this->columns() ),
				esc_html__( 'No kit products found.', 'universal-commerce-bundles' )
			);
		} else {
			foreach ( $this->items as $item ) {
				echo '<tr>';
				foreach ( array_keys( $this->columns() ) as $key ) {
					printf( '<td class="column-%s">%s</td>', esc_attr( $key ), $this->renderColumn( $key, $item ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderColumn escapes.
				}
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		$this->pagination();
	}

	/**
	 * @return array<string, string>
	 */
	private function columns(): array {
		return array(
			'name'           => __( 'Bundle', 'universal-commerce-bundles' ),
			'id'             => __( 'ID', 'universal-commerce-bundles' ),
			'sku'            => __( 'SKU', 'universal-commerce-bundles' ),
			'exposure'       => __( 'Catalog exposure', 'universal-commerce-bundles' ),
			'price'          => __( 'Price', 'universal-commerce-bundles' ),
			'components'     => __( 'Components', 'universal-commerce-bundles' ),
			'sellability'    => __( 'Operational sellability', 'universal-commerce-bundles' ),
			'blocked_reason' => __( 'Blocked reason', 'universal-commerce-bundles' ),
		);
	}

	private function sortableHeader( string $key, string $label ): string {
		$sortable = array(
			'name' => 'title',
			'id'   => 'id',
		);

		if ( ! isset( $sortable[ $key ] ) ) {
			return esc_html( $label );
		}

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) $_REQUEST['orderby'] ) : 'title'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( (string) $_REQUEST['order'] ) : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$next    = ( $orderby === $sortable[ $key ] && 'asc' === $order ) ? 'desc' : 'asc';
		$url     = add_query_arg(
			array(
				'orderby' => $sortable[ $key ],
				'order'   => $next,
			)
		);

		return sprintf(
			'<a href="%s"><span>%s</span></a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function renderColumn( string $key, array $item ): string {
		return match ( $key ) {
			'name'           => $this->columnName( $item ),
			'id'             => esc_html( (string) (int) ( $item['id'] ?? 0 ) ),
			'sku'            => $this->columnSku( $item ),
			'exposure'       => $this->columnExposure( $item ),
			'price'          => $this->columnPrice( $item ),
			'components'     => $this->columnComponents( $item ),
			'sellability'    => $this->columnSellability( $item ),
			'blocked_reason' => $this->columnBlockedReason( $item ),
			default          => '',
		};
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function columnName( array $item ): string {
		$name = (string) ( $item['name'] ?? '' );
		$link = isset( $item['edit_link'] ) ? (string) $item['edit_link'] : '';

		if ( '' === $link ) {
			return esc_html( $name );
		}

		return sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $link ),
			esc_html( $name )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function columnSku( array $item ): string {
		$sku = (string) ( $item['sku'] ?? '' );

		return '' === $sku ? '&mdash;' : esc_html( $sku );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function columnExposure( array $item ): string {
		$assessment = $item['assessment'] ?? null;

		if ( ! $assessment instanceof KitOverviewAssessment ) {
			return '';
		}

		return esc_html(
			BundlesAdmin::labelForExposure(
				$assessment->catalogExposure->code,
				$assessment->catalogExposure->rawStatus
			)
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function columnPrice( array $item ): string {
		$price        = (string) ( $item['price'] ?? '' );
		$regularPrice = (string) ( $item['regular_price'] ?? '' );

		if ( '' === $price && '' === $regularPrice ) {
			return '&mdash;';
		}

		if ( '' !== $regularPrice && $regularPrice !== $price && '' !== $price ) {
			return esc_html( $price ) . ' <span class="description">(' . esc_html( $regularPrice ) . ')</span>';
		}

		return esc_html( '' !== $price ? $price : $regularPrice );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function columnComponents( array $item ): string {
		$components = $item['components'] ?? array();

		if ( ! is_array( $components ) || array() === $components ) {
			return '&mdash;';
		}

		$parts = array();

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			$name  = (string) ( $component['name'] ?? '' );
			$qty   = (int) ( $component['qty_per_kit'] ?? 0 );
			$link  = isset( $component['edit_link'] ) ? (string) $component['edit_link'] : '';
			$label = sprintf(
				/* translators: 1: component product name, 2: required quantity per kit */
				__( '%1$s × %2$d', 'universal-commerce-bundles' ),
				$name,
				$qty
			);

			if ( '' !== $link ) {
				$parts[] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $link ),
					esc_html( $label )
				);
			} else {
				$parts[] = esc_html( $label );
			}
		}

		return implode( '<br />', $parts );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function columnSellability( array $item ): string {
		$assessment = $item['assessment'] ?? null;

		if ( ! $assessment instanceof KitOverviewAssessment ) {
			return '';
		}

		$exposure    = BundlesAdmin::labelForExposure(
			$assessment->catalogExposure->code,
			$assessment->catalogExposure->rawStatus
		);
		$sellability = BundlesAdmin::labelForSellability( $assessment->sellability->code );

		return esc_html( $exposure . ' · ' . $sellability );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function columnBlockedReason( array $item ): string {
		$assessment = $item['assessment'] ?? null;

		if ( ! $assessment instanceof KitOverviewAssessment ) {
			return '';
		}

		$reason = BundlesAdmin::labelForBlockedReason( $assessment->sellability->blockedReasonCode );

		return '' === $reason ? '' : esc_html( $reason );
	}

	private function pagination(): void {
		if ( $this->totalPages <= 1 ) {
			return;
		}

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		printf(
			'<span class="displaying-num">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: number of kit products */
					_n( '%s item', '%s items', $this->totalItems, 'universal-commerce-bundles' ),
					number_format_i18n( $this->totalItems )
				)
			)
		);

		$baseUrl = remove_query_arg( 'paged' );
		for ( $i = 1; $i <= $this->totalPages; $i++ ) {
			$url = add_query_arg( 'paged', $i, $baseUrl );
			if ( $i === $this->page ) {
				echo ' <span class="tablenav-pages-navspan">' . esc_html( (string) $i ) . '</span>';
			} else {
				echo ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a>';
			}
		}
		echo '</div></div>';
	}
}
