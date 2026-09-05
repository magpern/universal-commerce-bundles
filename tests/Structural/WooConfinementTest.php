<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Structural;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces docs/ARCHITECTURE.md's M0 requirement: "WooCommerce symbols
 * confined to UniversalCommerceBundles\Woo, enforced by a structural test."
 *
 * This scans every PHP file under src/ that is NOT under src/Woo/ for any
 * reference to a WooCommerce symbol — a `WC_*` class, the `WC()` accessor,
 * a `wc_*` helper function, or the `Automattic\WooCommerce\*` namespace —
 * and fails if it finds one. It is deliberately a plain filesystem/regex
 * scan, not a runtime check, so it also catches confinement violations in
 * code paths that unit tests never execute.
 */
final class WooConfinementTest extends TestCase {

	/**
	 * Patterns for real WooCommerce symbols. Kept narrow and
	 * WooCommerce-specific so this does not false-positive on this
	 * plugin's own `UCB_`/`ucb_`-prefixed identifiers, and on words like
	 * "WooCommerce" appearing only in comments/docblocks would also match —
	 * deliberately, since even a *reference* to a WooCommerce class or
	 * function by name belongs in the Woo namespace's own docblocks, not
	 * scattered through the rest of the codebase.
	 */
	private const FORBIDDEN_PATTERNS = array(
		'/\bWC_[A-Za-z0-9_]*\b/',
		'/\bWC\s*\(\s*\)/',
		'/\bwc_[a-z0-9_]+\s*\(/',
		'/Automattic\\\\WooCommerce\\\\/',
		'/\\\\Automattic\\\\WooCommerce\\\\/',
	);

	public function test_no_source_file_outside_woo_namespace_references_woocommerce_symbols(): void {
		$srcDir     = dirname( __DIR__, 2 ) . '/src';
		$violations = array();

		foreach ( $this->phpFilesUnder( $srcDir ) as $file ) {
			$path = $file->getPathname();

			if ( $this->isUnderWooNamespace( $path, $srcDir ) ) {
				continue;
			}

			$contents = file_get_contents( $path );
			self::assertIsString( $contents, "Could not read {$path}" );

			foreach ( self::FORBIDDEN_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, $contents, $matches ) === 1 ) {
					$violations[] = sprintf( '%s references WooCommerce symbol "%s"', $path, $matches[0] );
				}
			}
		}

		self::assertSame(
			array(),
			$violations,
			"WooCommerce symbols must be confined to UniversalCommerceBundles\\Woo:\n"
				. implode( "\n", $violations )
		);
	}

	public function test_woo_namespace_directory_exists_and_is_the_only_confinement_boundary(): void {
		$wooDir = dirname( __DIR__, 2 ) . '/src/Woo';

		self::assertDirectoryExists( $wooDir );
	}

	/**
	 * @return iterable<SplFileInfo>
	 */
	private function phpFilesUnder( string $dir ): iterable {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				yield $file;
			}
		}
	}

	private function isUnderWooNamespace( string $path, string $srcDir ): bool {
		$relative = ltrim( str_replace( $srcDir, '', $path ), '/' );

		return str_starts_with( $relative, 'Woo/' ) || str_starts_with( $relative, 'Woo' . DIRECTORY_SEPARATOR );
	}
}
