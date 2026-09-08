<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Structural;

use PHPUnit\Framework\TestCase;

/**
 * M2 admin overview must not invent a second stock formula and must keep
 * WooCommerce symbols confined to src/Woo (covered globally by
 * WooConfinementTest). This guard asserts the overview data class stays
 * under Woo and the assessor stays pure under Engine.
 */
final class M2AdminSurfaceStructuralTest extends TestCase {

	public function test_overview_data_lives_in_woo_namespace(): void {
		self::assertFileExists( dirname( __DIR__, 2 ) . '/src/Woo/KitOverviewData.php' );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/src/Woo/AdminDiagnostics.php' );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/src/Engine/KitOperationalAssessor.php' );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/src/Admin/BundlesAdmin.php' );
	}

	public function test_admin_php_files_do_not_reference_wc_symbols(): void {
		$adminDir = dirname( __DIR__, 2 ) . '/src/Admin';
		$patterns = array(
			'/\bWC_[A-Za-z0-9_]*\b/',
			'/\bWC\s*\(\s*\)/',
			'/\bwc_[a-z0-9_]+\s*\(/',
			'/Automattic\\\\WooCommerce\\\\/',
		);

		$files = glob( $adminDir . '/*.php' );
		self::assertIsArray( $files );

		foreach ( $files as $file ) {
			$contents = (string) file_get_contents( $file );
			foreach ( $patterns as $pattern ) {
				self::assertSame(
					0,
					preg_match( $pattern, $contents ),
					basename( $file ) . ' must not reference WooCommerce symbols'
				);
			}
		}
	}

	public function test_assessor_has_no_wordpress_or_woocommerce_calls(): void {
		$contents = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Engine/KitOperationalAssessor.php'
		);

		self::assertDoesNotMatchRegularExpression( '/\b(get_post_meta|update_post_meta|wc_|WC_)/', $contents );
	}
}
