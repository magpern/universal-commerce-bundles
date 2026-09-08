<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * Combined dual-field operational presentation for one kit overview row.
 */
final class KitOverviewAssessment {

	public function __construct(
		public readonly KitCatalogExposure $catalogExposure,
		public readonly KitSellability $sellability,
	) {
	}
}
