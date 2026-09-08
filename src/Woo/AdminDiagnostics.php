<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Woo;

/**
 * Read-only operational diagnostics for M2 Settings. Never writes options,
 * never loads host MU files, never claims ucb_runtime_ready emission unless
 * a same-request recorder is supplied.
 */
final class AdminDiagnostics {

	/**
	 * @param callable():bool|null $bootstrapCompleted
	 * @return array{
	 *     plugin_version: string,
	 *     bootstrap_label: string,
	 *     runtime_contract: array{plugin_version: string, contract_version: int, snapshot_versions: int[]},
	 *     woocommerce_active: bool,
	 *     woocommerce_version: string,
	 *     woocommerce_minimum: string,
	 *     woocommerce_supported: bool,
	 *     hpos_state: string,
	 *     host_guard_listeners: int,
	 *     host_guard_label: string
	 * }
	 */
	public static function collect( ?callable $bootstrapCompleted = null ): array {
		$wcActive    = Compatibility::isWooCommerceActive();
		$wcVersion   = defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
		$wcSupported = Compatibility::meetsRequirements();
		$bootOk      = null !== $bootstrapCompleted ? (bool) $bootstrapCompleted() : false;

		$listeners = function_exists( 'has_action' ) ? (int) has_action( 'ucb_runtime_ready' ) : 0;

		return array(
			'plugin_version'        => defined( 'UCB_PLUGIN_VERSION' ) ? (string) UCB_PLUGIN_VERSION : '',
			'bootstrap_label'       => $bootOk
				? 'UCB bootstrap completed / runtime contract available'
				: 'UCB bootstrap not completed',
			'runtime_contract'      => array(
				'plugin_version'    => defined( 'UCB_PLUGIN_VERSION' ) ? (string) UCB_PLUGIN_VERSION : '',
				'contract_version'  => 1,
				'snapshot_versions' => array( 1 ),
			),
			'woocommerce_active'    => $wcActive,
			'woocommerce_version'   => $wcVersion,
			'woocommerce_minimum'   => Compatibility::MINIMUM_WOOCOMMERCE_VERSION,
			'woocommerce_supported' => $wcSupported,
			'hpos_state'            => self::hposState(),
			'host_guard_listeners'  => $listeners,
			'host_guard_label'      => $listeners > 0
				? 'At least one listener is registered on ucb_runtime_ready (informational)'
				: 'No ucb_runtime_ready listeners detected (host guard optional / absent)',
		);
	}

	private static function hposState(): string {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return 'unknown / unavailable';
		}

		try {
			$enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

			return $enabled ? 'enabled' : 'disabled';
		} catch ( \Throwable $e ) {
			return 'unknown / unavailable';
		}
	}
}
