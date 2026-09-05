<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Engine;

/**
 * The outcome of CompositionValidator::validate() — deliberately more
 * detailed than a boolean, so an admin notice (docs/ARCHITECTURE.md,
 * "Invalidation and reverse lookup") can say *why* a kit is invalid.
 */
final class ValidationResult {

	/**
	 * @param bool     $valid
	 * @param int[]    $missingComponentIds
	 * @param int[]    $unpublishedComponentIds
	 * @param string[] $mixedTaxClasses distinct tax classes found across components, when inconsistent.
	 * @param bool     $structurallyInvalid
	 */
	private function __construct(
		public readonly bool $valid,
		public readonly array $missingComponentIds,
		public readonly array $unpublishedComponentIds,
		public readonly array $mixedTaxClasses,
		public readonly bool $structurallyInvalid,
	) {
	}

	public static function valid(): self {
		return new self( true, array(), array(), array(), false );
	}

	public static function structurallyInvalid(): self {
		return new self( false, array(), array(), array(), true );
	}

	/**
	 * @param int[]    $missingComponentIds
	 * @param int[]    $unpublishedComponentIds
	 * @param string[] $mixedTaxClasses
	 */
	public static function invalid( array $missingComponentIds, array $unpublishedComponentIds, array $mixedTaxClasses ): self {
		return new self( false, $missingComponentIds, $unpublishedComponentIds, $mixedTaxClasses, false );
	}
}
