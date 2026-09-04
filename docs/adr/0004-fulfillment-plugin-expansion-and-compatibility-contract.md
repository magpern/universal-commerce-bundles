# ADR-0004: Fulfillment-plugin expansion and compatibility contract

## Status

Accepted — "one-line skip," replacing the originally planned "expansion"
design, following the Architecture A → B decision (ADR-0002).

## Context

A companion fulfillment plugin builds a picking list from an order's real
line items and keys its own change-detection diff on `order_item_id`. Under
Architecture A, a kit was a single order line, so the fulfillment plugin
would have needed to independently detect that marker and *expand* it into
synthetic picking rows derived from the immutable order snapshot
(ADR-0003), version-negotiated against what the fulfillment plugin's
current release understood.

That design had two proven problems (see `docs/ARCHITECTURE.md` §"V1",
§"V6"):

1. **The change-detector collision.** If several components collapsed onto
   one synthetic `order_item_id` (an expansion artifact, not a real
   database row), the detector's stored/live comparison maps would key on
   that same id for every component — any admin save of a kit order would
   spuriously flag the whole line as changed ("sums many against a live
   one"). Raw aggregation is not a fix; it fails again whenever a
   component's `qty_per_kit > 1`.
2. **Asynchronous, retriable intake.** The fulfillment plugin's own intake
   hooks include a scheduled retry action; "checkout succeeds, then the
   bundling plugin becomes unavailable, then intake finally runs" is a
   normal operational sequence, not a hypothetical. Any design where
   correct expansion depends on a bundles-registered callback being
   present at intake time would silently produce one undifferentiated kit
   picking row instead of real per-component rows.

Architecture B (ADR-0002) removes the premise these problems attach to:
each component is now a real, distinct WooCommerce order line with its own
real, stable `order_item_id`, created at checkout time, not derived later.

## Decision

**No expansion.** The fulfillment plugin needs exactly one guard: skip the
non-pickable parent line. In the fulfillment plugin's order-source class,
inside its existing loop over line items, immediately after its existing
line-item type guard:

```php
if ( $item->get_meta( '_ucb_kit', true ) ) {
    continue;
}
```

This reads only persisted order-item meta — **no dependency on this
plugin's classes, autoloader, or constants** — satisfying the independent-
detection requirement (C13) for free. Every other line, including every
real component line, is ingested by the fulfillment plugin completely
unmodified.

**The change detector needs zero modification.** Because each component
already has its own real, stable `order_item_id`, the existing,
unmodified, `order_item_id`-keyed diff is already correct — the collision
that made a rewrite mandatory under Architecture A cannot occur under
Architecture B. This was proven live: re-firing the order-items-saved event
for an unmodified order, both without and with the parent-skip guard
applied, left fulfillment state `queued` in both cases — no spurious
`problem` flag.

**The stock-readiness intake gate is removed, not merely relaxed.**
Architecture A needed a "completed journal operation" precondition before
intake, because core skipped the kit's own (unmanaged) parent line and only
a plugin-owned journal proved reduction had actually happened. Under
Architecture B, core reduces the real child lines itself; by the time any
status transition that would trigger fulfillment intake has occurred,
core's own `_reduced_stock` record on the child line already reflects
reality. No separate fulfillment-side stock-readiness check is required.

**Works with the bundling plugin fully deactivated.** Live-proven: a fresh
order was built entirely with the bundling plugin **deactivated** (no
plugin hooks running at all), with the snapshot/component meta attached
manually to simulate a real checkout that had happened while the plugin
was active — matching the independently-established finding that product/
order meta persists regardless of plugin state (C18). The guard still
correctly skipped the parent, and picking rows were correctly produced only
for the real children.

**Unknown snapshot version.** The fulfillment plugin fails closed on the
*parent* line — it skips it regardless of version, since the skip key is
the presence of the kit marker, not its version. There is no longer a
version-negotiated *expansion* to fail on, since the fulfillment plugin
never expands anything.

## Consequences

- No schema migration is required in the fulfillment plugin — every
  comparison-key field the change detector needs (`order_item_id`,
  `product_id`, `variation_id`, `qty_ordered`) is already persisted on real
  rows, and picking-row identity for barcodes/scanning already uses the
  fulfillment plugin's own row primary key (V4), unaffected by this
  decision.
- **Recorded residual, unchanged from Architecture A:** with `kit_qty > 1`,
  a component's real child line carries a *summed* quantity as one line
  (`qty = kit_qty × qty_per_kit`); the fulfillment plugin still cannot
  attribute a picked unit to a specific kit instance without further work.
  Accepted for picking; no per-instance traceability in v1.
- The version-negotiated expansion-provider contract this ADR originally
  specified under Architecture A is retired entirely, along with its
  compatibility-matrix maintenance burden.

## Rejected alternatives

- **Expansion from the immutable snapshot, version-negotiated** (the
  original Architecture A design). Rejected in favour of the parent-skip
  guard: proven to require a mandatory rewrite of the fulfillment plugin's
  change detector, a stock-readiness precondition tightly coupled to
  Architecture A's own journal, and a version-negotiation contract that
  Architecture B makes entirely unnecessary.
- **A blanket "hide unless admin" filter** on order-item reads, tried as a
  first implementation of the presentation-hiding contract (ADR-0003) and
  found, live, to silently delete real component rows from the
  fulfillment plugin's own raw item-reading calls whenever those calls ran
  outside a normal web-admin request context (e.g. from a CLI process).
  Rejected; replaced with a narrowly-scoped filter active only inside
  specific customer-facing hooks (ADR-0003, ADR-0007).
