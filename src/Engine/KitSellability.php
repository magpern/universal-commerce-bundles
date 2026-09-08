<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * Operational sellability for a kit (M2). Independent of catalog exposure.
 */
final class KitSellability {

	public function __construct(
		public readonly string $code,
		public readonly ?string $blockedReasonCode,
	) {
	}
}
