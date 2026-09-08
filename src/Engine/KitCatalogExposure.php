<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * Catalog exposure label for a kit product (M2). Independent of sellability.
 */
final class KitCatalogExposure {

	public function __construct(
		public readonly string $code,
		public readonly string $rawStatus,
	) {
	}
}
