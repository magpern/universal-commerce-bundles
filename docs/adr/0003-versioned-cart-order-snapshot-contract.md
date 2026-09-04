# ADR-0003: Versioned cart/order snapshot contract

## Status

Accepted.

## Context

A kit's composition can change after an order is placed — a component can
be deleted, unpublished, re-priced, or have its `qty_per_kit` edited on the
kit product. Historical orders must always render, and be diffed against,
the composition *as it was at the time of purchase*, never the current
product definition (see `docs/ARCHITECTURE.md` §"C14"). Additionally,
Architecture B (ADR-0002) makes every component a real, separate order/cart
line item, which needs its own meta contract distinct from the original
single-line snapshot design.

WooCommerce's core refund-creation function was found, by live testing, to
have no idempotency guard of its own — a repeated call with an identical
line-items payload (a retried webhook, an accidental double-submit)
double-restocks every component. This is a defect in bare WooCommerce core,
not something this plugin can fix upstream, so a narrow guard is needed at
this plugin's own refund-orchestration boundary.

## Decision

### Parent-line snapshot (unchanged in shape from the original design)

A private, never-REST-exposed meta value, written on the **parent** order/
cart line at checkout on both the classic and Store API paths:

```json
{
  "v": 1,
  "kit_id": 0,
  "kit_sku": "",
  "kit_qty": 0,
  "components": [
    {
      "stock_managed_id": 0,
      "product_id": 0,
      "variation_id": 0,
      "sku": "",
      "name": "",
      "qty_per_kit": 0,
      "qty_total": 0
    }
  ]
}
```

Retained for historical composition rendering and refund-linkage
arithmetic. Within one kit line, a component listed twice in composition
becomes one child line with summed `qty_per_kit` (unchanged from
Architecture A's original merge rule).

### Per-child meta (new, Architecture B — replaces Architecture A's
single-line design)

Each real child order/cart line carries:

| Meta key | Purpose |
|---|---|
| `_ucb_parent_item_id` | links the child back to its parent kit line |
| `_ucb_component` | marks the line as a hidden kit-component child — the load-bearing exclusion key consumed by ADR-0007's cross-cutting contract |
| `_ucb_snapshot_version` | the parent-line snapshot schema version this child was created under |
| `_ucb_position` | stable ordering for Contents-line rendering |

### Refund-operation idempotency guard (new, closes a real WooCommerce
core defect)

Order meta `_ucb_refund_ops` records applied refund operation ids. An
operation id is derived from a stable hash of the order item, refund, and
component identity (so that a genuinely different, second partial refund
produces a different id, while a retried delivery of the *same* refund
produces the *same* id). The wrapper checks this record **before** calling
the core refund-creation function and rejects an already-applied operation
id with no stock change, while still allowing a genuinely different
operation to proceed. No transaction or outbox is needed for this guard,
because the core refund object itself becomes the durable record once the
call succeeds.

### Presentation contract

- Historical orders render **only** from the parent-line snapshot, never
  from the live product definition.
- A visible **Contents** line is shown to the customer, in emails, and in
  admin, built from the parent's snapshot — not from the (hidden) real
  child lines.
- Hiding of child lines from customer-facing surfaces is **server-side
  filtering, not CSS**, applied independently on every surface: classic
  cart/checkout (WooCommerce's cart-item-visibility filter family), Store
  API JSON (the REST post-dispatch filter), the Cart block's server-
  rendered hydration payload (WooCommerce's own documented back-compat
  hydration filter — the block's hydration path bypasses the standard REST
  server entirely, so the REST-level filter alone does not cover it),
  admin/email/account views (a narrowly-scoped order-items filter, active
  only inside the specific customer-facing hooks — never a blanket
  filter, which was found live to silently break another plugin's raw
  item-reading calls when tried), and REST v3 orders (the order-object
  REST-preparation filter).

## Consequences

- A future change to the snapshot schema requires a version bump
  (`v`/`_ucb_snapshot_version`) and an explicit migration/compat decision —
  never a silent reinterpretation of old orders under a new schema.
- The fulfillment plugin's contract with this snapshot narrows under
  Architecture B: it no longer needs to *parse and expand* the snapshot for
  the happy path (see ADR-0004) — it only needs to recognise the presence
  of a kit marker on the parent line, to skip it. The snapshot itself is
  still required, for historical rendering and refund-linkage arithmetic.
- The refund idempotency guard is a narrow reuse of the same
  "operation-id-in-a-durable-record" pattern that Architecture A's
  (rejected) stock-operations journal used more broadly — proof that the
  pattern itself is sound, even though its broader application (a whole
  journal/outbox subsystem) was not adopted.

## Rejected alternatives

- **Deriving historical composition from the live product definition.**
  Rejected — ambiguous and unsafe (C14): a component deleted or re-priced
  after the order was placed would silently corrupt the historical record.
- **A single-line child-expansion model** (Architecture A): one parent
  line, with the fulfillment plugin expanding it into synthetic picking
  rows derived from the snapshot at intake time, version-negotiated.
  Rejected in favour of real per-component lines (ADR-0002) — the
  expansion model requires every component to collapse onto one synthetic
  `order_item_id`, which was proven to break a fulfillment plugin's
  own change-detection diff (four components summing against a live one).
- **Observing WooCommerce's "can restock refunded items" filter** for the
  refund-idempotency side effect. Withdrawn — that filter runs only on the
  restock code path and would not fire for a kit's own (unmanaged) parent
  line; the real, documented `woocommerce_refund_created` action is used
  instead.
