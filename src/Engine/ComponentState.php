<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * A single component's real, live existence/publication/tax facts, exactly
 * as needed by CompositionValidator — primitives only, so the validator
 * never needs a real product object. The Woo adapter that populates this
 * is where the real product-lookup and post-status calls are actually
 * made.
 */
final class ComponentState {

	public function __construct(
		public readonly int $stockManagedId,
		public readonly bool $exists,
		public readonly bool $published,
		public readonly string $taxClass,
	) {
	}
}
