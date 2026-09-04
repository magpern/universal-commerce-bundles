# ADR-0002: Component availability, reservation, reduction and restoration lifecycle

## Status

Accepted — Architecture B, proven by live spikes S1-C and S1-D. Architecture
A (spikes S1-A/S1-B) was independently proven viable on its own terms and is
retained below as the record of a rejected alternative, not as
implementation work.

## Context

A kit product does not itself manage WooCommerce stock — its availability
must be *derived* from its components' availability, and any stock
reservation/reduction/restoration triggered by an order must actually apply
to the components, not the kit line. WooCommerce's built-in stock lifecycle
(`ReserveStock`, `wc_maybe_reduce_stock_levels`, `wc_maybe_increase_stock_levels`)
was verified (see `docs/ARCHITECTURE.md` §"V2", §"V3", §"V10", §"V11") to
have no hooks that let a third party contribute *extra* line demand into
its own aggregation/locking pass without also being visible to every other
consumer of `WC_Order::get_items()` — meaning a kit's components cannot
simply "ride along" inside core's own reservation pass unless they are
themselves real order line items core already iterates.

Two full architectures were designed and independently proven live before
one was selected.

### Availability formula (unaffected by which architecture is chosen)

A kit's available quantity is `min( floor( component_available /
qty_per_kit ) )` across components, where `component_available` accounts
for the component's own stock **and** existing reservations, keyed on the
stock-managed id (not the raw product/variation id — a parent-managed
variation resolves to its parent). A backorder-enabled component is
excluded from the minimum rather than treated as satisfying it — every
*other* required component must still satisfy its own availability
requirement, so a backordered component never single-handedly makes a kit
purchasable while a genuinely unavailable component blocks it. Non-stock-
managed components are always available, never reduced, never restored.
Validity is computed live at the decision point (inside the purchasability
and add-to-cart-validation filters); a cached "composition valid" flag is a
display hint only and is never authoritative.

## Decision

**Architecture B is selected.** For every kit purchase: one customer-
facing, priced `simple`-product parent order/cart line carrying the full
static kit price and tax, plus one real, zero-priced WooCommerce child
order/cart line **per component** (quantity = kit quantity × per-kit
quantity), linked by parent-item/component/snapshot-version/position meta
(see ADR-0003).

Because each component is a real, stock-managed WooCommerce order/cart
line, **core's own unmodified `ReserveStock`, `wc_maybe_reduce_stock_levels`
and `wc_maybe_increase_stock_levels` already reserve, reduce and restore
correctly** — no plugin-owned reservation writer, no stock-operations
journal, no explicit transaction, no transactional outbox, no crash-
recovery mechanism, and no custom order status is required for stock-
lifecycle correctness. This was proven decisively by live spike execution:
a real order was checked out with a proof-of-concept plugin implementing
Architecture B active, the plugin was then **fully deactivated** (confirmed
by checking its functions were no longer even defined), and a subsequent
status transition — standing in for a scheduled/cron pass with the plugin
unavailable — was still correctly handled: core reduced, and later
restored, the real component stock **with zero plugin code running at
all**.

Refunds: restock remains opt-in via the real, documented
`woocommerce_refund_created` action; the *linkage arithmetic*
(`child_qty = (child_line_qty / kit_line_qty) × kit_qty_refunded`) is this
plugin's, but the restock *execution* itself is core's own. A real defect
was found in bare WooCommerce core during this work: its refund-creation
function has no idempotency guard of its own, and a repeated call with an
identical line-items payload double-restocks every component. This is
closed by an operation-id-guarded order-meta wrapper (see ADR-0003),
checked before calling the core refund function.

Pricing/VAT/multicurrency/shipping: a cart-totals hook forces every child
line's price, weight, dimensions and shipping class to zero on the in-cart
product clone on every recalculation (see ADR-0007 for why weight/
dimensions/shipping-class needed to be added to this hook, not only price).

## What is required, unchanged, regardless of which architecture is chosen

- The purchasability guard (blocking **new** purchases while the plugin
  can't validate composition) — unrelated to which stock-lifecycle
  mechanism is chosen, since the kit product itself is an ordinary
  `simple` product either way. See ADR-0006.
- Deactivation-writes-persistent-lock-state / reactivation-does-not-
  auto-unlock policy. See ADR-0006.
- The derived-availability formula above, and the reverse-index
  invalidation machinery — this is about whether the kit *should currently
  be purchasable*, not about the stock-lifecycle *mechanism*.
- The order snapshot contract (ADR-0003) — though its consumer changes
  (the fulfillment plugin no longer *expands* it, only *skips* the parent
  line it marks — see ADR-0004).

## Rejected alternative: Architecture A

A single, priced parent order line, with **no real order lines for
components at all**. Stock lifecycle handled entirely by a custom,
plugin-owned subsystem:

- **Reservation:** the whole order opted out of core's own reservation
  (`woocommerce_order_hold_stock_minutes` filtered to `0` for any order
  carrying a kit line); the plugin became the sole writer to WooCommerce's
  internal reservation table, replicating core's private aggregation/
  locking/retry algorithm exactly (byte-for-byte the same SQL shape,
  3-attempt retry loop, lock-order discipline). Proven live, including
  correct scoping (a sibling non-kit order in the same request was
  untouched) and a genuine live-only finding: core's own release function
  already deletes a plugin's reservation rows for free on the standard
  completing/cancelling path, since it deletes by order id alone without
  checking who wrote the row.
- **Reduction/restoration:** a plugin-owned journal table
  (`op_id` unique, `order_id`, `order_item_id`, `op_type`,
  `stock_managed_id`, `qty_delta`, InnoDB) as the authoritative record,
  with a minimal transaction (relative stock UPDATE + journal INSERT,
  nothing else) and **mandatory explicit rollback on a duplicate operation
  id** — a real defect was found live: the database engine does *not*
  auto-rollback a transaction on a duplicate-key error, only on deadlock/
  lock-timeout, so trusting the unique constraint alone silently
  double-applies the stock mutation on replay.
- **Transactional outbox:** commit the mutation + journal row
  (`pending`) → commit → perform WooCommerce synchronisation and fire
  public actions outside any transaction → mark `done` → a durable
  recovery sweep retries anything still `pending` past a threshold,
  idempotently. Proven live for both crash points (mid-transaction via a
  real forced connection kill; post-commit-pre-outbox-completion via a
  simulated worker death).
- **Restoration deferral, a two-part mechanism, corrected after a live
  defect was found:** the first-stated design ("remove the core
  restoration callback, re-add it after the dispatch completes,"
  implemented the natural way as a synchronous `try/finally` re-add inside
  the *same* priority-5 callback) was live-tested and **produced zero
  suppression, 100% of the time** — the `finally` block runs before
  WordPress's hook dispatcher ever reaches the later priority in the same
  dispatch. The corrected mechanism — a priority-15 idempotent re-add
  registered once at plugin load, plus a priority-5 suppressor that does
  its own throwable work first inside a swallowing `try/catch` before
  calling the remove function last — was proven live across 8 required
  properties, twice (with the order-storage compatibility mode off and
  on).
- **A custom order status** ("stock problem"), chosen over reusing the
  built-in "on hold" status specifically because core binds none of its
  four reduction/restoration triggers to a custom status name — eliminating
  a re-entrancy hazard by construction rather than by a guard flag. Proven
  live, including under the order-storage compatibility mode.
- **Compare-and-set** on the stock value, instead of an operation-id
  journal, was tried and rejected: a live test raced two different,
  both-legitimate concurrent operations, and the CAS-guarded one was
  silently defeated (zero affected rows) by the other's unrelated
  legitimate change.

Both halves of Architecture A (S1-A "reservation," S1-B "crash-safe
mutation") independently reported **PASS** and were re-verified a second
time by mandatory live execution against a real running WordPress/
WooCommerce install (not merely a bare-database proof-of-concept), because
static source tracing alone had already been shown elsewhere in this
project to produce a design that looks correct on paper and is not (see
`docs/ARCHITECTURE.md` §"V11"). One real defect (the restoration-
suppression mechanism above) was found and corrected during that
re-verification pass.

**Why it was set aside in favour of Architecture B:** side-by-side
comparison (spike S1-C) showed Architecture B achieves the identical safety
properties — including the crash-safety and inactive-plugin properties
Architecture A's entire custom subsystem exists to guarantee — using
**zero** plugin-owned reservation/journal/outbox/crash-recovery code,
because the mutation core performs on a real order line already *is* the
atomic, crash-safe primitive Architecture A spent most of its effort
re-deriving. Architecture A remains a fully viable, independently-proven
design and is preserved here as evidence, should a future WooCommerce
version ever remove the extension points Architecture B currently relies
on.

## Consequences

- No new database tables are introduced by this plugin under Architecture
  B — WooCommerce's own reservation table and per-item `_reduced_stock`
  meta already are the ledger, for free.
- This plugin has a version-bound dependency only on WooCommerce's stable,
  documented action/filter seams under Architecture B (the cart-totals
  hook, the refund-created action, the various visibility/exclusion
  filters in ADR-0007) — a materially smaller compatibility surface than
  Architecture A's dependency on core's *private* reservation-write
  algorithm.
- A new risk class is introduced by making components real line items:
  any WooCommerce-native or third-party subsystem that iterates real order/
  cart lines can now see them. This is addressed by ADR-0007's
  cross-cutting exclusion contract, and must be re-evaluated for any future
  consumer added to the plugin ecosystem.
- A residual, explicitly accepted limitation: a shipping-cost formula keyed
  on cart-line *quantity* (rather than weight) still double-counts hidden
  children, since they remain real, separate cart lines by design. A full
  fix requires filtering child items out of the shipping package's
  contents, not implemented — relevant only if a real deployment
  specifically configures that rate shape.

## Rejected alternatives (summary)

| Approach | Verdict | Reason |
|---|---|---|
| Public-API-only reservation, avoiding the private reservation table | Rejected | No scoped extension point inside core's own aggregation loop |
| Plugin-owned shadow reservation table as primary | Rejected | Not visible to core's own reservation-read query, so a standalone purchase could oversell a component held by a kit |
| Compare-and-set stock mutation | Rejected | Proven unreliable under concurrent, legitimate, unrelated stock changes |
| Built-in "on hold" status as the deferral state | Rejected | Re-entrant by construction — bound to core's own reduction trigger |
| Waiting for core to re-fire a status transition to trigger recovery | Rejected | No future event is guaranteed once the reduced-stock flag is false |
| **Architecture A in full** (custom reservation writer + journal + outbox + custom status) | Rejected in favour of B | Proven viable and safe on its own terms; an order of magnitude more custom code than Architecture B for an equivalent safety property |
