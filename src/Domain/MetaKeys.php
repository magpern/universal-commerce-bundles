<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Domain;

/**
 * Every private, never-REST-exposed meta key this plugin reads or writes,
 * in one place (ADR-0003, ADR-0005, ADR-0006). Pure constants — no
 * WooCommerce symbols — so any layer can reference the contract without
 * violating the WooCommerce confinement rule.
 */
final class MetaKeys {

	/**
	 * Product meta: marks a product as a fixed kit (boolean-ish, 'yes'/'').
	 */
	public const PRODUCT_IS_KIT = '_ucb_is_kit';

	/**
	 * Product meta: the kit's composition, as a JSON-encoded array of
	 * component rows (see Composition::toArray()).
	 */
	public const PRODUCT_COMPOSITION = '_ucb_composition';

	/**
	 * Product meta: cached display-only validity hint (never authoritative
	 * — see docs/ARCHITECTURE.md, "Derived availability and invalidation").
	 */
	public const PRODUCT_COMPOSITION_VALID_HINT = '_ucb_composition_valid';

	/**
	 * Product meta on a *component*: not purchasable standalone (ADR-0005).
	 */
	public const PRODUCT_NOT_PURCHASABLE_ALONE = '_ucb_not_purchasable_alone';

	/**
	 * Product meta on a *component*: excluded from catalog/search (ADR-0005).
	 */
	public const PRODUCT_HIDDEN_FROM_CATALOG = '_ucb_hidden_from_catalog';

	/**
	 * Product meta on a *component*: the canonical kit id a direct visit to
	 * this component's own product page redirects to (ADR-0005).
	 */
	public const PRODUCT_CANONICAL_KIT_ID = '_ucb_canonical_kit_id';

	/**
	 * Product meta: deactivation-lock marker (ADR-0006). Presence means a
	 * kit was locked non-purchasable by plugin deactivation and stays
	 * locked until an explicit admin unlock.
	 */
	public const PRODUCT_LOCKED_BY_DEACTIVATION = '_ucb_locked';

	/**
	 * Option: the reverse index, component stock-managed id => [kit ids].
	 */
	public const OPTION_REVERSE_INDEX = 'ucb_component_reverse_index';

	/**
	 * Cart-item / order-item meta: kit snapshot, written on the *parent*
	 * line at checkout (ADR-0003).
	 */
	public const LINE_KIT_SNAPSHOT = '_ucb_kit';

	/**
	 * Cart-item / order-item meta on a *child* line: links back to the
	 * parent line (ADR-0003).
	 */
	public const LINE_PARENT_ITEM_ID = '_ucb_parent_item_id';

	/**
	 * Cart-item / order-item meta on a *child* line: marks the line as a
	 * hidden kit-component child — the load-bearing exclusion key (ADR-0007).
	 */
	public const LINE_COMPONENT = '_ucb_component';

	/**
	 * Cart-item / order-item meta on a *child* line: the snapshot schema
	 * version this child was created under (ADR-0003).
	 */
	public const LINE_SNAPSHOT_VERSION = '_ucb_snapshot_version';

	/**
	 * Cart-item / order-item meta on a *child* line: stable ordering for
	 * Contents-line rendering (ADR-0003).
	 */
	public const LINE_POSITION = '_ucb_position';

	/**
	 * Refund-item meta: marks a refund line item as one this plugin added
	 * for a linked component, so the post-save restock hook can find
	 * exactly the lines the pre-save hook added (ADR-0002 refund clause).
	 */
	public const REFUND_LINE_DERIVED = '_ucb_derived_refund_line';

	/**
	 * The current snapshot schema version this build writes and understands.
	 */
	public const SNAPSHOT_VERSION = 1;

	private function __construct() {
	}
}
