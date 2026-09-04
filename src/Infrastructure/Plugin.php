<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Infrastructure;

use UniversalCommerceBundles\Woo\Compatibility;

/**
 * Plugin bootstrap orchestrator.
 *
 * M0 scope only: activation/deactivation lifecycle scaffolding, the
 * WooCommerce-dependency safe-fail gate, and emitting the generic
 * `ucb_runtime_ready` capability-handshake signal (ADR-0006) once
 * bootstrap has fully succeeded. No kit, cart, order, or refund behavior
 * is registered here — that is M1's scope.
 */
final class Plugin {

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * Reset the singleton. Test-only seam; never called from production code.
	 *
	 * @internal
	 */
	public static function resetForTests(): void {
		self::$instance = null;
	}

	public function registerHooks(): void {
		register_activation_hook( UCB_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( UCB_PLUGIN_FILE, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Activation lifecycle hook. M0: no kit-specific behavior — ADR-0006's
	 * deactivation-lock policy is implemented in M1, once kit products
	 * exist to lock.
	 */
	public function activate(): void {
	}

	/**
	 * Deactivation lifecycle hook. M0: no kit-specific behavior.
	 */
	public function deactivate(): void {
	}

	/**
	 * Runs on `plugins_loaded`. Gates everything else on the WooCommerce
	 * dependency check, and never fatals: an absent or too-old WooCommerce
	 * is handled by self-deactivating safely with an admin notice.
	 */
	public function init(): void {
		if ( ! Compatibility::meetsRequirements() ) {
			$this->selfDeactivateWithNotice();

			return;
		}

		/**
		 * Fires exactly once, as the final successful bootstrap step, once
		 * this plugin has fully initialised (ADR-0006 capability contract,
		 * term 1). The host MU-plugin guard listens for this to set its
		 * request-local readiness signal to true; it must never infer
		 * current health from anything else. M0 is otherwise inert: no
		 * kit/cart/order/refund behavior is registered here.
		 *
		 * @param array{plugin_version: string, contract_version: int, snapshot_versions: int[]} $payload {
		 *     @type string $plugin_version    This plugin's own version.
		 *     @type int    $contract_version  The capability-contract version (ADR-0006).
		 *     @type int[]  $snapshot_versions Kit/child-line snapshot schema versions this build understands (ADR-0003).
		 * }
		 *
		 * @since 0.1.0-dev
		 */
		do_action(
			'ucb_runtime_ready',
			array(
				'plugin_version'    => UCB_PLUGIN_VERSION,
				'contract_version'  => 1,
				'snapshot_versions' => array( 1 ),
			)
		);
	}

	/**
	 * Never throws, never requires a file that might not exist. Registers a
	 * visible admin notice and, only inside wp-admin (where
	 * deactivate_plugins() is actually loaded), deactivates the plugin.
	 */
	private function selfDeactivateWithNotice(): void {
		add_action( 'admin_notices', array( $this, 'renderRequirementsNotice' ) );
		add_action( 'admin_init', array( $this, 'deactivateSelf' ) );
	}

	/**
	 * @internal Public only so it can be used as an admin_init callback.
	 */
	public function deactivateSelf(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			return;
		}

		deactivate_plugins( plugin_basename( UCB_PLUGIN_FILE ) );

		// Read-only query-arg check mirroring WordPress core's own
		// activation-notice suppression pattern; nothing is mutated based on
		// this beyond unsetting the local copy below.
		if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			unset( $_GET['activate'] );
		}
	}

	/**
	 * @internal Public only so it can be used as an admin_notices callback.
	 */
	public function renderRequirementsNotice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum required WooCommerce version, e.g. "8.2". */
					__( 'Universal Commerce Bundles requires WooCommerce %s or later. It has been deactivated.', 'universal-commerce-bundles' ),
					Compatibility::MINIMUM_WOOCOMMERCE_VERSION
				)
			)
		);
	}
}
