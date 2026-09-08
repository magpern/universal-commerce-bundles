<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Admin;

use UniversalCommerceBundles\Engine\KitOperationalAssessor;
use UniversalCommerceBundles\Woo\AdminDiagnostics;
use UniversalCommerceBundles\Woo\KitOverviewData;

/**
 * WooCommerce → Bundles overview and Bundles → Settings diagnostics
 * (docs/m2-admin-usability-plan.md). Read-only; manage_woocommerce only.
 */
final class BundlesAdmin {

	public const MENU_SLUG          = 'ucb-bundles';
	public const SETTINGS_MENU_SLUG = 'ucb-bundles-settings';
	public const CAPABILITY         = 'manage_woocommerce';

	public function __construct(
		private readonly KitOverviewData $overviewData,
		private readonly \Closure $bootstrapCompleted,
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerMenus' ), 60 );
	}

	public function registerMenus(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Bundles', 'universal-commerce-bundles' ),
			__( 'Bundles', 'universal-commerce-bundles' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'renderOverviewPage' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Bundles Settings', 'universal-commerce-bundles' ),
			__( 'Bundles Settings', 'universal-commerce-bundles' ),
			self::CAPABILITY,
			self::SETTINGS_MENU_SLUG,
			array( $this, 'renderSettingsPage' )
		);
	}

	public function renderOverviewPage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-commerce-bundles' ) );
		}

		$listTable = new BundlesListTable( $this->overviewData );
		$listTable->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bundles', 'universal-commerce-bundles' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only overview of kit products. Edit composition on each product\'s Kit Components tab.', 'universal-commerce-bundles' ) . '</p>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		$listTable->search_box( __( 'Search bundles', 'universal-commerce-bundles' ), 'ucb-bundles' );
		$listTable->display();
		echo '</form>';
		echo '</div>';
	}

	public function renderSettingsPage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-commerce-bundles' ) );
		}

		$diagnostics = AdminDiagnostics::collect( $this->bootstrapCompleted );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bundles Settings', 'universal-commerce-bundles' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only diagnostics. There are no writable settings on this page.', 'universal-commerce-bundles' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';

		$this->diagnosticRow(
			__( 'UCB version', 'universal-commerce-bundles' ),
			$diagnostics['plugin_version']
		);
		$this->diagnosticRow(
			__( 'Bootstrap / runtime contract', 'universal-commerce-bundles' ),
			$diagnostics['bootstrap_label']
		);
		$this->diagnosticRow(
			__( 'Runtime contract shape', 'universal-commerce-bundles' ),
			sprintf(
				'contract_version=%d; snapshot_versions=%s',
				(int) $diagnostics['runtime_contract']['contract_version'],
				implode( ',', array_map( 'strval', $diagnostics['runtime_contract']['snapshot_versions'] ) )
			)
		);
		$this->diagnosticRow(
			__( 'WooCommerce', 'universal-commerce-bundles' ),
			$diagnostics['woocommerce_active']
				? sprintf(
					/* translators: 1: installed WC version, 2: minimum supported version, 3: supported yes/no */
					__( 'Active %1$s (minimum %2$s) — %3$s', 'universal-commerce-bundles' ),
					$diagnostics['woocommerce_version'],
					$diagnostics['woocommerce_minimum'],
					$diagnostics['woocommerce_supported']
						? __( 'supported', 'universal-commerce-bundles' )
						: __( 'unsupported', 'universal-commerce-bundles' )
				)
				: __( 'Not active', 'universal-commerce-bundles' )
		);
		$this->diagnosticRow(
			__( 'HPOS', 'universal-commerce-bundles' ),
			$diagnostics['hpos_state']
		);
		$this->diagnosticRow(
			__( 'Host guard (informational)', 'universal-commerce-bundles' ),
			$diagnostics['host_guard_label']
		);

		echo '</tbody></table>';
		echo '</div>';
	}

	private function diagnosticRow( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:40%%">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Translated labels for exposure / sellability / blocked reasons.
	 */
	public static function labelForExposure( string $code, string $rawStatus = '' ): string {
		return match ( $code ) {
			KitOperationalAssessor::EXPOSURE_PUBLISHED => __( 'Published', 'universal-commerce-bundles' ),
			KitOperationalAssessor::EXPOSURE_CATALOG_HIDDEN => __( 'Catalog-hidden', 'universal-commerce-bundles' ),
			KitOperationalAssessor::EXPOSURE_DRAFT => __( 'Draft', 'universal-commerce-bundles' ),
			KitOperationalAssessor::EXPOSURE_PENDING => __( 'Pending', 'universal-commerce-bundles' ),
			KitOperationalAssessor::EXPOSURE_PRIVATE => __( 'Private', 'universal-commerce-bundles' ),
			default => '' !== $rawStatus ? $rawStatus : $code,
		};
	}

	public static function labelForSellability( string $code ): string {
		return match ( $code ) {
			KitOperationalAssessor::SELLABILITY_ACTIVE => __( 'Active', 'universal-commerce-bundles' ),
			KitOperationalAssessor::SELLABILITY_BLOCKED => __( 'Blocked', 'universal-commerce-bundles' ),
			KitOperationalAssessor::SELLABILITY_BACKORDER_SUPPORTED => __( 'Backorder-supported', 'universal-commerce-bundles' ),
			default => $code,
		};
	}

	public static function labelForBlockedReason( ?string $code ): string {
		if ( null === $code || '' === $code ) {
			return '';
		}

		return match ( $code ) {
			KitOperationalAssessor::REASON_SAFETY_LOCK => __( 'Safety lock', 'universal-commerce-bundles' ),
			KitOperationalAssessor::REASON_INVALID_CONFIGURATION => __( 'Invalid configuration', 'universal-commerce-bundles' ),
			KitOperationalAssessor::REASON_MISSING_COMPONENT => __( 'Missing component', 'universal-commerce-bundles' ),
			KitOperationalAssessor::REASON_COMPONENT_NOT_PURCHASABLE => __( 'Component not purchasable', 'universal-commerce-bundles' ),
			KitOperationalAssessor::REASON_INSUFFICIENT_COMPONENT_STOCK => __( 'Insufficient component stock', 'universal-commerce-bundles' ),
			default => $code,
		};
	}
}
