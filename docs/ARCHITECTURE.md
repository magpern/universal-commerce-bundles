# Universal Commerce Bundles — Architecture

**Status: needs final review.** This document completed a
`plan → review → documentation-only freeze` cycle, was then reopened for a
refund-design correction, and has now been corrected back to a complete and
internally consistent state — but that correction is a recent decision that
has not yet had the same independent review the rest of this document's
frozen content had, hence "needs final review" rather than a bare "frozen."
**The selected target architecture is Architecture B — a priced parent kit
line plus hidden, zero-priced, real WooCommerce child order lines per
component** (spikes S1-C, S1-D — both PASS). Architecture B is the selected
target architecture because it delegates stock reservation, reduction and
restoration to WooCommerce core and removes the need for a custom stock
engine.

The earlier **Architecture A** design (a single parent line plus a custom
reservation writer, a stock-operations journal, a transactional outbox, and
a custom "stock problem" deferral status — spikes S1-A/S1-B, both
independently PASS) is retained in this document only as evidence for a
rejected alternative, not as implementation work. It is superseded, not
deleted, so the reasoning that ruled it out remains visible.

**Refunds went through the same arc, one layer up.** Spikes S1-E and S1-F
(see [`docs/spikes/`](spikes/)) designed and live-proved a custom refund
idempotency/orchestration subsystem — a `pending`→`completed` operation
ledger, a two-layer distributed lock, database transactions bracketing
refund creation and restocking, and a required-but-unbuilt reconciliation
sweep — real, working engineering that the product owner nonetheless
**rejected** as disproportionate scope for a generic v1 bundles plugin. That
subsystem is retained in `docs/spikes/s1-e-*` and `docs/spikes/s1-f-*`,
each carrying a visible correction note, as evidence for why it doesn't fit
— not as accepted design. The **replacement v1 refund scope** — UCB adds
the derived component refund lines through WooCommerce's own native refund
flow, and takes on none of refund creation, persistence, restocking,
locking or retry idempotency — was proven live by spike S1-G (PASS); see
[`docs/spikes/s1-g-native-refund-line-linkage.md`](spikes/s1-g-native-refund-line-linkage.md)
and the M1 Refunds section below.

Every ADR in `docs/adr/` reflects Architecture B and is ready for final
review — becoming accepted upon merge of this documentation-freeze pull
request, not before — including ADR-0002's refund clause and ADR-0003, at
the narrow native-refund-only scope described above. **No implementation
authorization is granted by this document** — none of the milestones described below (M0/M1/the
fulfillment-plugin milestone/the promotions-plugin milestone/the host
safety guard) are implemented, and this repository's code tree is empty.
Full spike evidence is preserved in [`docs/spikes/`](spikes/).

## Context

This architecture supports fixed product kits — for example a "Starter Kit"
composed of a core item plus three supporting components ("Component A",
"Component B", "Component C"), sold as one product at a discount, with the
supporting components not sold separately. More kits can follow the same
pattern. The target store type is **picked to order**.

Every technical claim below was verified against WooCommerce **11.0.1**
core source and against real, versioned copies of three companion plugins:
a fulfillment plugin (v1.0.0), a promotions plugin (v0.5.3), and a
multicurrency plugin (v1.2.0). Claims that did not survive verification were
removed rather than carried forward.

## Settled decisions

1. Plugin identity: `universal-commerce-bundles`.
2. Core stays generic — no store- or kit-specific logic.
3. A fixed kit is a WooCommerce `simple` product with validated composition
   metadata.
4. The kit owns a normal static WooCommerce product price.
5. No runtime summation of component prices.
6. No promotions integration in v1 (see the separate promotions-plugin
   milestone below).
7. Composition is snapshotted onto the order item.
8. Kits are picked to order.
9. Fulfillment-plugin integration is a separate repository milestone, ready
   before a kit is first sold.
10. Customer-configurable bundles, nested bundles, component-level refunds,
    variation components and mixed-tax allocation remain deferred.

---

## Verified WooCommerce/WordPress core findings

These findings (numbered V1–V13, numbering preserved from the internal
working plan for cross-reference stability) were all verified by direct
source reading of WooCommerce 11.0.1 / WordPress core, and — where marked —
by live execution against a disposable stack.

### V1 — a naive fulfillment-side change detector needs per-line identity

A fulfillment plugin's order-item-change detector is a change *detector*,
not a reconciler — for a line-item-save event it performs a real diff
against what was snapshotted at intake; any addition, removal or quantity
change flags a "problem". If four components all shared one synthetic
`order_item_id` (as they would under a naive "expand one kit line into
several picking rows" design), every admin save of a kit order would
spuriously flag a problem, and raw aggregation is not a fix — it fails
again whenever a component's `qty_per_kit > 1`. This became the deciding
argument, later in this document, for giving every component its own real,
stable `order_item_id` rather than expanding a single line (see Architecture
B, below).

### V2 — a kit gets no stock reservation by default, and naive component reservation would corrupt core's

WooCommerce's `ReserveStock::reserve_stock_for_order()` throws when a
product `is_in_stock()` is false, and skips reservation entirely when a
product does not manage its own stock or allows backorders. A kit product
does not manage its own stock, so **availability is checked but nothing is
held** — neither the kit nor its components — unless something else
reserves the components explicitly.

Four further constraints apply to any fix:

1. **Core aggregates demand per stock-managed id in PHP before writing.**
2. **The reservation upsert replaces, it does not add** (`ON DUPLICATE KEY
   UPDATE`). A naive second, separate reservation write for a component that
   is *also* a standalone line in the same order **silently overwrites**
   core's own row and under-reserves — worse than a key collision, because
   it fails quietly.
3. **The reservation key is the stock-managed id, not the raw product or
   variation id.** A variation whose stock is parent-managed resolves to the
   *parent* product's id.
4. The core reservation-write function is `private`; it uses a locked
   `INSERT … SELECT … WHERE (stock FOR UPDATE) − (reserved LOCK IN SHARE
   MODE) >= qty` with a 3-attempt deadlock-retry loop and lock-order
   ("ksort") discipline.

Both the classic checkout and the Store API / Checkout Blocks path funnel
through the same reservation entry point; the reservation-hold duration
filter (`woocommerce_order_hold_stock_minutes`) governs both identically,
and returning `0` for an order skips reservation for that order entirely.
The reservation table is keyed `PRIMARY KEY (order_id, product_id)`,
queried by product id, so holds are visible across orders.

### V3 — core's authoritative stock lifecycle seams

| Concern | Detail |
|---|---|
| Reduction triggers | `wc_maybe_reduce_stock_levels` fires on payment-complete and three order-status transitions (completed/processing/on-hold) — four actions |
| Duplicate-reduction guard | An order-level "stock reduced" flag |
| Per-item record | A `_reduced_stock` meta value — a **quantity**, not a boolean flag |
| Authoritative reduction seam | A `woocommerce_reduce_order_stock` action, fired once per actual reduction, already de-duplicated |
| Restoration triggers | `wc_maybe_increase_stock_levels` on cancelled/pending/failed status transitions |
| Restoration amount | Increases stock by the **recorded** `_reduced_stock` value |
| Authoritative restoration seam | A `woocommerce_restore_order_stock` action |
| Veto filters | `woocommerce_can_reduce_order_stock`, `woocommerce_can_restore_order_stock` |
| Non-stock-managed products | Core skips them — never reduced, never restored |

Because a kit does not manage its own stock, core **skips the kit line
entirely** — no `_reduced_stock` is written and nothing is reduced for it.
Under a design where components are never real order lines (Architecture A),
a plugin-owned ledger is therefore the *only* record of what happened; under
Architecture B (selected), the components are real lines and core's own
mechanism already produces the record for free — this distinction drives
the whole architecture decision below.

### V4 — fulfillment-row identity should be per-row, not per-order-line

A real fulfillment plugin's picking-list assembler keys its rows on its own
autoincrement primary key, not on `order_item_id` — good practice already
present in the companion fulfillment plugin, and a strong argument for
giving every kit component its own real order line (so its `order_item_id`
is itself stable and unique) rather than expanding one synthetic line into
several picking rows after the fact.

### V5 — refund restocking is opt-in, and a real core action is the seam

WooCommerce's refund-creation function defaults to *not* restocking; restock
only runs when the caller explicitly requests it. After the refund is
saved, core fires a `woocommerce_refund_created` action carrying both the
restock flag and the affected line items — a genuine seam that reports the
operator's actual intent, and fires regardless of whether core itself
restocked anything (which matters, since core always skips the kit's own,
unmanaged, parent line).

### V15 — the core refund call persists the refund before the action that says "before save"

Verified by source read against the pinned WooCommerce release, and then by
an injected process kill. Inside the core refund-creation function, the two
total-recalculation helpers invoked just before the "adjust refund before
save" action — the tax-update helper and the totals-calculation helper —
**each end with a save of the refund object**. The refund row, its line
items and its amount are therefore already durable by the time that action
fires. The action is before the *final* save, not before creation.

Consequently the only seam that genuinely precedes a refund's durable
creation is the generic object-save action pair fired from the abstract
order class's own `save()`: one immediately **before** the data store's
`create()`, one after it and after the line items are written. Any meta
that must be atomic with the refund's creation has to be attached on the
first of those, and that one save has to be bracketed in a transaction —
because neither order data store (legacy posts or custom order tables)
writes the row and its meta in a single transaction of its own.

### V16 — an order-meta record is not a lock

Reading order meta and then writing it back is not an atomic claim. Two
concurrent requests both read "absent", both write, and both proceed.
Live-disproven at a 10/10 violation rate with two concurrent OS processes,
and 5/5 with four — in the four-process case restocking a component
*above* the level it started at, because every process read the same
pre-decrement bookkeeping value before any of them wrote. Mutual exclusion
requires a genuinely atomic claim: a database named lock, or the
`INSERT IGNORE` + rows-affected pattern WordPress core itself uses for
update locks.

### V17 — product-owner scope-reset decision: the custom refund-orchestration path is a rejected alternative, not v1 scope

V15/V16 closed the two named windows and then surfaced a real choice: keep
hardening a refund protocol that had grown a two-layer lock, two database
transactions, a mandatory repair routine and a required background
reconciliation sweep, or scope the responsibility down. **The product owner
chose to descope.** `_ucb_refund_ops`-style operation ledgers, the
`pending`→`completed` state machine, the two-layer mutex, any UCB-owned
transaction around refund creation or restocking, and the reconciliation
sweep are all removed from the accepted design.

**Replacement v1 refund scope, proven live by spike S1-G:** UCB supports
only WooCommerce's native refund flow. Its entire refund responsibility is
adding the correctly linked component-child refund lines, at the derived
quantity `child_refund_qty = (original_child_qty / original_parent_qty) ×
parent_qty_refunded`, via a real, documented WooCommerce action
(`woocommerce_create_refund`) that fires on the not-yet-saved refund object
before WooCommerce's own save and restock. WooCommerce remains responsible
for creating and persisting the refund, optional restocking, totals, admin
request handling, gateway execution, and API/webhook retry semantics. UCB
does not expose or own a generic refund API, a webhook wrapper, a
gateway-refund flow, a retry mechanism, or an exactly-once promise; a
duplicate refund submitted through an external integration is that
integration's own responsibility to prevent, not UCB's. Full mechanism and
live evidence, both WooCommerce order-storage modes: see
[`docs/spikes/s1-g-native-refund-line-linkage.md`](spikes/s1-g-native-refund-line-linkage.md).

### V6 — fulfillment intake can run long after checkout, asynchronously

A real fulfillment plugin's intake hooks include a scheduled retry action
alongside its synchronous ones; inline intake falls back to a scheduled
retry when it fails, and the retry runs later in a wholly separate request.
So "checkout succeeds, then the bundles plugin becomes unavailable, then
intake finally runs" is a normal operational sequence, not a hypothetical —
any design where correct behaviour depends on a bundles-registered callback
being present at intake time will silently produce a wrong result.

### V7 — a promotions engine cannot see kit membership by default, and its default discount can be untaxed

A companion promotions plugin's cart-context projector drops all unknown
cart-item meta with no filter when building its evaluation context, and its
default discount mechanism applies a **non-taxable** cart fee. Its price-
mutation guard excludes custom bundle/composite product types, permitting
only simple products, variations and virtual products — so a new condition
type needs edits across several files, and an unrecognised condition type
makes the **whole promotion ineligible**. This shapes how any kit-awareness
is later added to promotions (see the cross-cutting exclusion contract,
below): as data on existing rows, never as a new condition type.

### V8 — multicurrency conversion works cleanly for a static-priced simple product

A companion multicurrency plugin filters the product price getters,
guarded by re-entrancy protection — meaning any design that tried to *sum*
child prices at runtime inside `get_price()` would see base-currency
component prices, not the resolved currency's. This is avoided entirely by
decision 5 above (no runtime price summation).

### V9 — vendoring precedent for a bundled design-system dependency is incomplete

A vendored design-system directory in the companion fulfillment plugin has
a checksum manifest and a single prose attribution line, but no recorded
upstream version, no source commit, no licence, and no update procedure — it
detects drift without recording what it drifted from. M0 (below) closes
this gap for any future vendored dependency in this plugin.

### V10 — how core mutates stock, and what that allows

Core's stock-update function resolves the stock-managed id, fires
before/after actions, delegates to the data store, refreshes the product
object, saves it (syncing stock status and clearing caches), then fires
further actions. The mutation itself is a **single atomic relative SQL
statement** (`UPDATE … SET meta_value = meta_value %+f WHERE post_id = %d
AND meta_key='_stock'`), filterable, atomic and concurrency-safe — but it
**carries no operation identity**, so it is not idempotent: replaying it
reduces stock again.

All tables relevant to a potential stock-mutation journal (the table
holding `_stock`, the order-item-meta table, the reservation table) were
confirmed InnoDB on the reference installation — meaning a stock mutation
and a plugin-owned journal write *can* share one transaction. That makes a
provably idempotent design *possible*; it does not by itself establish
which design to use (see spike S1-B).

### V11 — the fail-closed seam is asymmetric, and the obvious one is a trap

Blocking stock reduction for a kit order is **not** achievable through the
filter that appears designed for it.

**Reduction — the "can-reduce" filter is a trap.** It is evaluated inside
the reduction function, which returns early on veto — but the *caller* then
unconditionally marks the order "stock reduced" on the very next line
regardless of the veto. Vetoing there produces the worst possible state:
**the order is permanently marked stock-reduced while nothing was
reduced**, and can never be retried.

The correct seam is a **different**, earlier filter —
`woocommerce_payment_complete_reduce_order_stock` — evaluated *before* both
the reduction call and the flag write. Returning `false` there leaves the
order cleanly un-reduced and still eligible for a later attempt: exactly
the deferral semantics a fail-closed guard needs.

**Restoration — there is no equivalent seam.** The restoration-trigger
function computes its "should restore" decision with **no filter at all**,
then unconditionally clears the reduced-stock flag regardless of whether
restoration is vetoed via the separate "can-restore" filter — the
mirror-image corruption: **the order is marked not-reduced while component
stock is still held down.** Any deferral design for restoration must
therefore intercept *before* the status transition itself, or own the flag
transition directly (resolved in spike S1-B, and superseded by Architecture
B's simpler answer — see V13).

### V12 — Architecture A, proven by live execution, not just static tracing

Architecture A's two building blocks — component reservation and
crash-safe stock mutation — were each proven by a proof-of-concept run
against a disposable stack, **then re-executed a second time** against a
real running WordPress/WooCommerce install (not just a bare database),
because static source tracing alone (as V11 shows) can produce a design
that looks correct on paper and is not.

**Reservation — confirmed live**, with one requirement only execution could
surface: on the Store API checkout path, a component shortfall must be
signalled by throwing WooCommerce's own typed Store API route exception,
not a bare generic exception. A real HTTP checkout request proved the typed
exception yields a clean, customer-facing `400`; a bare exception from the
identical seam yields an opaque `500`. Both leave no orphaned order or
reservation row, but only one is a usable checkout error. Also
live-confirmed: the reservation-hold opt-out applies only to orders
carrying a kit line, a sibling non-kit order in the same request reserves
through core unmodified, and standalone-plus-kit demand for the same
component in one order aggregates into a single written row. **A
simplification found only by execution:** core's own reservation-release
function deletes reservation rows by order id alone — it does not check who
wrote them — and stays registered regardless of the opt-out, so it releases
a plugin's reservation rows for free on every standard completing/
cancelling path.

**Restoration suppression — a real defect found and fixed, not merely
confirmed.** The design as first stated — "remove the core restoration
callback, re-add it after the dispatch completes" — is ambiguous, and the
natural first implementation (re-adding synchronously inside a
`try/finally` within the *same* priority-5 callback) was live-tested and
**produced zero suppression, 100% of the time**: the `finally` block runs
*before* WordPress's hook-dispatch loop ever reaches the later priority, so
the callback is already back in place. **Corrected mechanism, proven live
across 8 required properties, twice — with the order-storage compatibility
mode off and on:**

1. A **priority-15** callback, registered **once, unconditionally, at
   plugin load** — not per-dispatch — idempotently re-adds the core
   restoration callback at its normal priority. Harmless when nothing was
   removed.
2. The priority-5 suppressor performs all of its own throwable work (order
   notes, meta writes) **first**, inside a `try/catch` that **swallows**
   any exception — necessary because WordPress's hook dispatcher has no
   exception handling of its own, so an uncaught exception at priority 5
   would abort the whole dispatch, taking the priority-15 restorer down
   with it and leaving core restoration permanently disabled — then calls
   the remove function only as its final, non-throwing statement.

This corrected mechanism is the only restoration-deferral design that
survived; the ambiguous single-callback version is withdrawn, not merely
clarified.

**A custom "stock problem" order status — confirmed live**, including
under the order-storage compatibility mode toggled on: registers with zero
plugin-specific code dependency; transitioning into it fires neither the
reduction nor the restoration action (confirmed by both hook-count deltas
and direct hook-registration inspection — core genuinely never binds
anything to a status name it doesn't know); excluded from the bulk
"Change status to…" admin dropdown, so it isn't offered as a casual
workflow choice; transitioning *from* a stock-reduced status *into* it
leaves stock unchanged and fires neither action.

**Transaction/rollback rule — reconfirmed on a fresh database instance,**
same result as first found: the database engine does **not** auto-rollback
on a duplicate-key error, only on deadlock/lock-timeout; a naive
duplicate-key handler that doesn't explicitly issue a rollback silently
double-applies the stock mutation on replay. The explicit-rollback design
is mandatory, not a nicety.

**Stated limitations, carried forward rather than hidden:** the Store API
tests used Cash on Delivery only, not an asynchronous/redirect gateway;
reservation tests ran with the order-storage compatibility mode off (the
crash-safety and custom-status tests ran both ways); a two-simultaneous-
checkout race was proven at the database-locking level (two separate OS
processes racing the locked SQL) but not repeated as a second live HTTP
race; third-party listener deduplication via an operation id remains a
contract obligation on code not yet written; a fulfillment-side
stock-readiness gate remains unimplemented, in a separate repository.

**V12's design (Architecture A) is superseded by V13 below and is retained
in this document only as evidence for a rejected alternative.**

### V13 — Architecture B, proven live: core's native lifecycle already governs kit stock, unassisted

Spikes S1-C and S1-D compared Architecture A (V12, above) against
**Architecture B**: one priced parent kit line plus one real, zero-priced,
linked WooCommerce child order line per component, both PASS.

**The decisive test:** a real order was checked out with a proof-of-concept
plugin implementing Architecture B active; the plugin was then **fully
deactivated** (confirmed by checking that none of its functions were even
defined any more); a status transition was then run, standing in for a
scheduled cron pass with the plugin unavailable. Core's own unmodified
reduction/restoration functions correctly reduced, and later correctly
restored, the real component stock — **with no plugin code running at
all**. This is the exact failure window Architecture A needed an entire
custom journal/outbox/crash-recovery/restoration-suppression/custom-status
subsystem to make safe (V12). Architecture B closes it by construction: the
child lines are ordinary WooCommerce order-item rows that do not depend on
the plugin that created them remaining loaded, because nothing about them
is plugin-owned.

**What this eliminates** (precisely, not sweepingly): a direct reservation-
table writer and its version-bound replication of core's private locking/
retry/aggregation algorithm; a stock-operations journal and its
explicit-rollback transaction discipline; a transactional outbox; custom
crash recovery; the priority-5/priority-15 restoration-suppression
mechanism; a custom "stock problem" status **as a stock-lifecycle tool**
(no longer load-bearing for correctness); a fulfillment plugin's
one-line-expansion design and any mandatory rewrite of its change detector
(V1's collision cannot occur — each component already has its own real,
stable `order_item_id`); a host guard's stock-operation-deferral
responsibility.

**What this does not touch, unchanged from Architecture A:** a host guard's
*purchasability* check (blocking **new** purchases while the plugin can't
validate composition — unrelated to which stock-lifecycle mechanism is
chosen, since the kit product itself is unchanged); deactivation-locks-kits
/ reactivation-does-not-auto-unlock policy; derived availability
computation and backorder/non-managed-component policy; the order snapshot
contract, though its consumer changes (a fulfillment plugin now *skips* the
parent line rather than *expanding* it).

**A new risk class, found and closed by S1-D, not anticipated by S1-C:**
because Architecture B makes components real order/cart lines, every
WooCommerce-native or third-party subsystem that iterates real line items
can now see them — and several treat presence as meaning "the customer
selected this," which is false for a hidden child. S1-D found and closed
four such leaks, each via a different real extension seam, none requiring
WooCommerce core to be patched:

| Consumer | Leak found live | Real seam used to close it |
|---|---|---|
| Promotions condition engine | product/category/quantity conditions satisfied by a hidden child alone (kit-only cart, no standalone purchase) | a new `is_kit_component` projected field, checked at each condition's matching point |
| WooCommerce core coupon eligibility | a product-restricted coupon — including a **free-shipping** coupon — validated as eligible off the hidden child alone, unlocking a real benefit with no genuine standalone purchase | `woocommerce_coupon_get_items_to_validate` (a real, documented core filter) |
| WooCommerce core shipping | cart weight, dimensions, and shipping class all double-counted the hidden children on top of the parent | the existing price-zeroing cart-totals hook, extended to also zero weight/dimensions/shipping-class on the in-cart clone |
| WooCommerce core Analytics | hidden children's units-sold and (via allocated shipping) gross-revenue figures were non-zero, even though net revenue was correctly zero | a scope-flag order-items filter, bracketed narrowly around the real recurring Analytics sync action |

Each fix is proven closed for the kit-only case while a genuine standalone
purchase of the same product, and a sitewide/eligibility discount on the
parent kit line, both continue to work correctly — over-exclusion was
checked, not assumed away.

**A fifth surface, presentation, closed by a version-specific WooCommerce
API, not CSS:** the default Cart block's server-rendered hydration payload
calls the Store API route directly, genuinely bypassing the normal REST
dispatch path — so the REST-level filter that correctly hides children from
every genuine HTTP request never fires for this one code path. The real,
documented, purpose-built parallel seam — WooCommerce's own back-compat
hydration filter for exactly this situation — closes it, reusing the same
item-stripping logic already registered on the REST-level filter.

**A real defect found in bare WooCommerce core itself, not a design flaw of
this plugin:** the core refund-creation function has no idempotency guard
of its own — a live test proved a repeated call with an identical
line-items payload (simulating a retried webhook or accidental
double-submit) double-restocks every component. Closed by a guard with
three parts, each doing exactly one job (full contract in ADR-0003):

1. **An atomic operation lock**, in two layers: a database named lock
   (released the instant a crashed holder's connection dies, and never
   taken from a live-but-slow holder) plus a durable `INSERT IGNORE` lease
   row with an expiry and an atomic compare-and-swap takeover — the update-
   lock pattern WordPress core itself uses, hardened. Both released on the
   success and failure paths alike.
2. **The operation id attached on the refund's creating save**, with that
   save bracketed in a transaction, so the refund row, its line items and
   its identity commit together. No durable refund ever exists without a
   queryable identity (V15).
3. **The restock invoked by this plugin through core's own restock
   function, inside a second transaction** that also commits a per-refund
   restock-completion marker — necessary because once identity lands
   before the restock, "identified" no longer implies "restocked".

The order-meta operation record is an audit and short-circuit projection
only: reconciliation against the real refund object runs unconditionally
under the lock before any decision to create, including when no local
record exists at all.

> **Correction, found by review, then closed by live execution — not
> merely a caveat.** An earlier version of this fix recorded a single
> write-then-done flag (write the operation id as "applied" **before**
> calling the core refund function, reject any repeat outright). This is
> the same intent-then-mutate ordering already rejected elsewhere in this
> document for stock mutation (see "Crash safety" below): if the process
> is interrupted, or the core call itself fails, in the window after the
> flag is written but before the real refund exists, a later retry saw the
> flag already set and was permanently blocked from a refund that never
> actually happened. A dedicated spike (S1-E) reproduced this exact
> window live — a real refund succeeding, then a simulated interruption
> before the local record was updated — and proved the corrected
> `pending`→`completed`-with-reconciliation design above resolves it: the
> retry finds the real refund by its own `op_id` meta, marks the local
> record `completed`, and creates no second refund. See
> `docs/spikes/s1-e-refund-idempotency-recovery.md`.

> **Second correction, found by review, then closed by live execution.**
> The state machine described in the note above still left two real windows
> open. Preserving what was previously claimed, and what turned out to be
> wrong about it:
>
> - *The identity marker was not durable when the refund was.* The
>   operation id was written by a **separate** save made **after** the core
>   refund call had already returned — after the refund row, its line items
>   and the restock were all durable. A process killed in that interval
>   left a real refund and a real restock carrying no identity at all;
>   recovery found no marked refund, concluded "never completed", and
>   created a second refund with a second restock. Live-proven with a real
>   `SIGKILL`: two refund rows, twice the refunded amount, stock restocked
>   twice. The obvious fix — hooking the core action documented as firing
>   "before save" — does **not** work either, because the refund is already
>   persisted by then (V15). The identity has to be attached on the
>   object-save action that precedes the data store's `create()`, with that
>   save bracketed in a transaction.
> - *The `pending` record was claimed to stop two concurrent attempts. It
>   does not.* Live-disproven at 10/10 and 5/5 violation rates (V16).
>
> A follow-up spike (S1-F) reproduced both windows live and closed them
> with the three-part guard described above, re-proving every earlier
> property in the process, under both WooCommerce order-storage modes. It
> also found and closed a third window created by the fix itself: with
> identity landing before the restock, reconciliation that trusts the
> identity alone silently loses the restock. See
> `docs/spikes/s1-f-refund-atomicity-and-locking.md`.

**Stated limitations, carried forward rather than hidden:** a shipping-cost
formula keyed on cart-line *quantity* still double-counts, since children
remain real, separate cart lines with real quantities by design — a
correct fix would filter child items out of the shipping *package*
contents, not implemented; it only matters if a real deployment
specifically configures a quantity-based rate. Full automatic triggering of
the Analytics fix via WooCommerce's real recurring batch action was not
independently confirmed end-to-end in a short-lived disposable container
(the fix's mechanism is proven directly against the real sync methods it
brackets); recommended as a deployment acceptance check. The literal,
visible-HTML Blocks-Cart leak described earlier was not reproduced in this
exact WooCommerce/WordPress configuration (the Cart block's server-rendered
markup is genuinely empty, fully client-rendered) — the underlying
hydration-JSON leak is real and is fixed regardless of whether it printed as
visible text in a given theme/config.

---

## Corrections to earlier drafts

| # | Prior claim | Status |
|---|---|---|
| C1 | The "stock reduced" order flag is an idempotency flag | Wrong — core stores a quantity (V3) |
| C2 | Aggregate fulfillment rows per `order_item_id` | Wrong — sums many against a live one (V1) |
| C3 | Reduction triggers are payment-complete / processing only | Incomplete — four actions; correct seam is the dedicated reduction action (V3) |
| C4 | Partial failure: proceed, record, flag | Withdrawn — fail-safe halt instead |
| C5 | Order edits adjust stock by delta | Withdrawn — edit-window policy instead |
| C6 | Barcodes keyed on `order_item_id` | Wrong — keyed on the fulfillment row's own primary key (V4) |
| C7 | Residual race documented as a limitation | Insufficient — replaced by spike S1-A |
| C8 | Vendoring removes version-management obligation | Wrong (V9) |
| C9 | Insert component reservations after core, into the reservation table | Wrong — the upsert replaces rather than adds and would silently under-reserve. Resolved by S1-A (V12): aggregate all demand into one row before any write, exactly as core does internally |
| C10 | Reservation keys on raw product/variation id | Wrong — must key on the stock-managed id |
| C11 | The primary key makes re-reservation an idempotent upsert | Wrong — a duplicate key is not an upsert; core's own `ON DUPLICATE KEY UPDATE` replaces |
| C12 | Observe a "can restock refunded items" filter | Withdrawn — that filter is misused for side effects and would not fire for kit lines. Replaced by the real `woocommerce_refund_created` action (V5) |
| C13 | Fulfillment consumes a bundles-registered expansion callback | Insufficient — bundles may be inactive at intake, and intake can run asynchronously (V6). Fulfillment must detect the kit marker independently and fail closed |
| C14 | "Expand the live order" for a corrected diff | Ambiguous — must expand from the immutable snapshot, never the current product definition |
| C15 | Kits excluded from campaigns "by default" via an admin report | Not a default — a report enforces nothing. Replaced by a separate promotions-plugin milestone |
| C16 | Intent-then-mutate makes reduction crash-safe | Wrong. Does not cover a crash *between* the stock mutation and persisting the reduced quantity; recovery would see a shortfall and reduce again. Resolved by S1-B's journal design (V12) — the journal insert and the mutation commit in one transaction, with explicit rollback on duplicate operation id |
| C17 | Deactivating the plugin is safe because fulfillment fails closed | Wrong. Fulfillment's protection covers orders already placed. A kit is an ordinary `simple` product, so once the plugin is inactive its purchasability and availability filters vanish and the kit stays purchasable with no component checks. New fail-safe policy in ADR-0006 |
| C18 | With the plugin inactive, nothing carries the kit marker, so nothing is excluded | Factually wrong. Product meta persists in the database independently of plugin state. A promotions plugin can and should keep excluding those products while this plugin is inactive — a safety property, not a degradation |
| C19 | Restoration suppression: remove the core callback, re-add it after the dispatch completes (single-callback `try/finally`) | Wrong, live-disproven (V12). Corrected to the two-part mechanism described in V12 |
| C20 | The re-entrancy hazard for a controlled deferral state needs a guard (flag, dedup, deferred transition) | Superseded, not merely addressed. A dedicated custom order status removes the hazard by construction — core binds its stock triggers only to literal built-in status names |
| C21 | Architecture A is the design to implement | Superseded by the decision recorded as V13. Architecture B was proven to delegate reservation/reduction/restoration to WooCommerce core unmodified, including with the plugin fully inactive after checkout — eliminating the entire custom stock-transaction/journal/outbox/recovery/restoration-suppression subsystem. Architecture A's spikes (S1-A/S1-B, V12) remain correct and are retained as rejected-alternative evidence |
| C22 | The refund-idempotency guard (S1-D) is a single write-before-call "applied" flag | Wrong — found by review to repeat the intent-then-mutate ordering already rejected for stock mutation (C16/V10/S1-B): an interruption between writing the flag and the core call succeeding could permanently block a refund that never happened. Corrected in S1-E to a `pending`→`completed` state machine reconciled against the real refund object's own meta; live-proven for the three interruption windows then known |
| C23 | The `pending` record stops two genuinely concurrent attempts from both proceeding (S1-E) | **Wrong, and live-disproven.** Order meta read-then-written is not an atomic claim: 10 out of 10 iterations with two concurrent OS processes, and 5 out of 5 with four, produced duplicate refunds and multiplied restocks — the four-process case restocking a component *above* its starting level. Corrected in S1-F to a two-layer atomic lock (database named lock + `INSERT IGNORE` lease with an atomic compare-and-swap takeover) |
| C24 | Writing the operation id onto the refund after the core call makes it a durable reconciliation target (S1-E) | **Wrong.** By then the refund row, its line items and the restock are all durable; a process killed in that interval leaves a real refund and a real restock with no identity, and recovery duplicates both — live-proven. Hooking the core action documented as firing "before save" does not fix it either, because the refund is already persisted by then (V15). Corrected in S1-F: the identity is attached on the object-save action that precedes the data store's `create()`, and that save is bracketed in a transaction |
| C25 | UCB should own a custom refund idempotency/orchestration subsystem (S1-E/S1-F: operation ledger, two-layer locking, transactions, reconciliation sweep) | **Rejected by product-owner decision (V17), not closed by further engineering.** A generic v1 bundles plugin does not get a custom refund-orchestration subsystem. Replaced by the narrow native-refund-only scope (V17), proven sufficient by spike S1-G (PASS). S1-E and S1-F are retained as evidence for why the custom approach doesn't fit a generic v1 plugin — valuable history — not as an implementation design that was ever accepted |

---

## Architecture A (rejected alternative — retained as evidence)

**This entire section documents Architecture A, superseded by the decision
to select Architecture B (V13, spikes S1-C/S1-D). Both S1-A and S1-B
independently reported PASS on their own terms — the work is sound and is
kept as the record of a real, viable, but ultimately more complex
alternative. Nothing in this section is implementation-authorized.**

### S1-A — component reservation (PASS)

Core pre-aggregates demand per stock-managed id in PHP, then upserts with
**replace** semantics — any second writer to the same reservation row
overwrites the first. The same order may contain a component demanded both
standalone and via a kit; that demand must be summed into one row, not
written twice. Core's own reservation-write function is private, so a
compatible implementation would have to reproduce its locking, retry loop
and lock ordering. The reservation table is an internal core table; writing
to it directly is a version-bound compatibility layer, not a permanent
integration.

**Design questions resolved:**

1. *Can this be done without writing to the reservation table directly?*
   No — no scoped extension point exists inside core's own aggregation
   loop; the only reachable filter is too broad and would corrupt totals,
   tax and display store-wide if used.
2. *Opt the whole order out of core reservation, and reserve it entirely
   from a plugin?* Yes — the adopted design. Proven live: the opt-out fires
   only for orders carrying a kit line; a sibling non-kit order in the same
   request reserves through core, untouched.
3. *A plugin-owned reservation table instead?* Rejected — it would leave
   core's own standalone-purchase path blind to kit-held component demand,
   under-protective by construction.
4. *Hook ordering* — proven live for both the classic and Store API
   checkout paths, not assumed.
5. *Reservation-to-reduction overlap* — traced live: reduction always runs
   before release on the shared status actions, so the overlap window can
   only *understate* availability, never oversell.
6. *Backorder/non-managed skip* — confirmed by direct source read; pure PHP
   branching, no concurrency dimension.

**Design (final):** for any order containing at least one kit line item:

1. Filter the reservation-hold-duration setting to `0` for that order.
2. Run a dedicated reservation pass, hooked after core's own no-op attempt
   on both checkout paths, which aggregates every line — standalone
   products and every kit's components — into one per-stock-managed-id
   total before any write, sorts it for lock-order parity with core, and
   writes using the identical locked SQL shape, retry loop, and exception
   mapping as core's own private reservation function. Skips backorder-
   enabled and non-stock-managed components, matching core.
3. **On the Store API path, a shortfall must be signalled by throwing
   WooCommerce's typed Store API route exception, never a bare exception.**
   Live-confirmed via a real HTTP request: the typed exception yields a
   clean `400` with a customer-facing JSON error; a bare exception from the
   identical seam yields an opaque `500`. Both leave no orphaned order or
   reservation row — only one gives the customer an actionable error.
4. Relies on core's own release function for the standard completing/
   cancelling path (confirmed live to cover the plugin's rows for free);
   adds an explicit release only for the one Store API failure path core's
   own release set doesn't cover.
5. Orders with no kit line are untouched.

This is an explicit, version-bound compatibility layer against WooCommerce
11.0.1, with an activation-time schema/engine guard, CI integration tests
that fail if core's upsert semantics, SQL shape, or hook-ordering
guarantees ever change, and a documented fail-closed fallback (kit products
become non-purchasable, admin notice) if a guard trips — never a silent
fallback to an under-protective shadow table.

**Concurrency proof:** real database tests with separate OS processes (not
mocks) — two concurrent checkouts for the last unit of stock, exactly one
succeeds; sequential upserts for the same reservation row reproduced the
replace-not-add bug live (two writes of 3 then 2 ended at 2, not 5),
confirming demand must be aggregated before one write; the aggregate-first
write correctly produced 5; a retried checkout, as genuinely separate
processes, left one row, unchanged quantity, across three retries.

### S1-B — crash-safe, exactly-once stock mutation (PASS)

Writing an intent record before mutating stock does **not** make the
operation crash-safe: a process can be reduce stock, then crash before the
intent/record write persists, so recovery sees an apparent shortfall and
reduces again. The stock mutation is atomic but carries no operation
identity, so replay is indistinguishable from a first attempt.

**Design questions resolved:**

1. *Exactly-once via a unique-operation-id journal committed with the
   mutation?* Yes, with one mandatory correction found only by execution:
   the database engine does **not** auto-rollback a transaction on a
   duplicate-key error — only on deadlock/lock-timeout. A design that
   trusted the unique constraint alone **silently double-applied the stock
   mutation on replay** in a live test (clean exit, no surfaced error). The
   fix, proven correct in a second live test: the application **must
   explicitly catch the duplicate-key error and issue an explicit
   rollback**.
2. *Journal authoritative, a derived ledger view a rebuildable read model?*
   Yes — adopted, and it removes the ledger write from the critical path.
3. *Safe to run WordPress/WooCommerce code inside the open transaction?*
   No. The transaction is minimal: the raw stock update plus the journal
   insert, nothing else — WooCommerce/third-party synchronisation happens
   entirely post-commit.
4. *A transactional outbox for post-commit work, proven live for both crash
   points:*
   1. one transaction commits the stock mutation, the unique operation
      record, and a pending post-commit work item;
   2. commit;
   3. WooCommerce synchronisation and public actions run afterwards, outside
      any transaction;
   4. the work item is marked done; a durable recovery sweep retries any
      row still pending past a threshold, idempotently.

   **Crash between mutation and durable record** (a real forced connection
   kill mid-transaction, a genuine second OS process, not simulated): stock
   unchanged, zero partial journal rows — the database engine's automatic
   rollback-on-disconnect makes the "mutated but not recorded" state
   impossible. **Crash after commit, before outbox work completes:** a
   recovery sweep found the pending row, completed idempotently; a second
   sweep was a correct no-op; stock was never re-mutated.

   Delivery guarantee, stated precisely: stock mutation + operation record
   is **exactly-once**; post-commit processing is **durable, at-least-once**;
   internal synchronisation (stock status, caches) is **idempotent**, so
   at-least-once is harmless; third-party listeners face **possible
   duplicate delivery** — every payload carries the operation id so a
   compatible consumer can deduplicate.
5. *Compare-and-set instead?* Rejected, live-disproven. A live test raced
   two different, both-legitimate concurrent operations; the CAS-guarded
   one was silently defeated (zero affected rows) by the other's unrelated
   legitimate change — CAS is unreliable whenever other actors can
   legitimately touch the same row.
6. *Same answer for restoration and refund restocking?* Yes for the
   journal/outbox mechanics; restoration additionally needs its own
   deferral mechanism because it has no pre-flag veto (V11).
7. *The postmeta table's engine as an asserted runtime precondition?* Yes —
   adopted. Checked at activation; fails closed if the engine is ever
   anything other than the transactional engine this design depends on.

**Background stock operations while the plugin is unavailable — resolved
(V11, V12):**

1. **Fail closed on background stock operations.** A host guard hooks the
   correct, earlier filter (`woocommerce_payment_complete_reduce_order_stock`),
   returning `false` for kit orders when a request-local readiness signal is
   absent. **Never** the later, trap filter (V11).
2. **Controlled state — a custom order status, not the built-in "on hold"
   status.** Chosen specifically because core binds none of its four
   reduction/restoration triggers to a custom status name — eliminating the
   re-entrancy hazard by construction rather than by a guard flag.
3. **Fulfillment gate strengthened.** Requires a completed "reduce" row in
   the journal for the order item before intake — a readable kit snapshot
   alone is insufficient.
4. **Restoration deferral — the corrected, live-proven two-part mechanism**
   described under V12 above.
5. **Idempotent deferred recovery, explicit trigger.** Recovery uses an
   explicit, operation-id-driven sweep run when the plugin's readiness
   signal fires — never dependent on a status transition happening to
   recur.
6. **Tested through a scheduled-task run with the plugin unavailable:**
   payment/status transition, cancellation, and the refund-restock path.

**Design (final):** a plugin-owned journal table is the authoritative
record of every stock mutation the plugin performs (reserve, release,
reduce, restore, refund-restock). The proof-of-concept passed the
injected-crash acceptance test (real forced connection kill mid-transaction,
real second process) and the crash-after-commit-before-outbox-completion
test. **This design is not implemented — Architecture B replaced it before
any freeze.**

---

## Architecture B — Spikes S1-C / S1-D (selected)

Both PASS. This section is the active design reference for the ADRs in
`docs/adr/`; V13 above is the condensed narrative.

### The architecture

For every kit purchase: one customer-facing, priced `simple`-product parent
order/cart line carrying the full static kit price and tax, plus one real,
zero-priced WooCommerce child order/cart line per component (quantity =
kit quantity × per-kit quantity), linked by parent/component/snapshot-
version/position meta. Children are ordinary WooCommerce line items for
every purpose core cares about — reservation, reduction, restoration,
refund-restock — and are hidden or grouped behind the parent's "Contents"
summary on every customer-facing surface. The kit product itself remains an
ordinary `simple` product; this does not reopen ADR-0001's static-pricing
decision.

### S1-C — proves the core mechanism, live

- **Cart construction:** one add-to-cart produces linked parent+children;
  parent quantity changes synchronise children; no merge with a standalone
  purchase of the same component; removing the parent removes its
  children, no orphans; customers cannot directly manipulate child
  quantities (both classic and Store API checkout paths, the latter via
  the same typed exception S1-A proved is required).
- **Reservation, reduction, restoration — the decisive finding.** Core's
  own unmodified reservation/reduction/restoration functions govern the
  real child lines with **zero plugin reservation/journal/outbox code.**
  Concurrency proven live with two genuine OS processes racing the last
  unit — exactly one wins, via core's own locking. Reduction and
  restoration proven live via real order-completion and cancellation
  calls — reduced-stock records written and cleared by core, unassisted.
- **Plugin inactive after checkout — proven, not the trap it was under
  Architecture A.** With the proof-of-concept plugin fully deactivated, a
  status transition still correctly reduced and later restored real
  component stock. No host guard stock-deferral responsibility, no custom
  status, no restoration-suppression mechanism required for this case.
- **Fulfillment:** unmodified fulfillment-plugin code ingests the real
  children as already-correct, separate picking rows; wrongly also ingests
  the non-pickable parent — fixed by a one-line guard. The change detector
  needed **zero** changes — each component already has its own stable
  `order_item_id`, so V1's collision cannot occur.
- **Promotions:** the cart-context projector puts hidden children's real
  product id/categories into promotion matching unless excluded — a
  genuine leak, closed in S1-D.
- **Presentation:** classic cart/checkout, Store API JSON, and REST orders
  JSON all proven hidden server-side (not CSS) via real captured output.
  One gap found: the Cart block's server-rendered hydration payload
  bypassed the REST-level filter — closed in S1-D.

### S1-D — closes every gap S1-C left open, each with a real fix, live-proven

| Gap | Real leak proven live | Real fix, live-proven closed |
|---|---|---|
| Promotions | product/category/quantity conditions fire off a hidden child alone | an `is_kit_component` field added to the cart-context projection, checked at each condition-matching point |
| Shipping | cart weight, dimensions, and shipping class all double-counted hidden children | extend the existing price-zeroing cart-totals hook to also zero weight/dimensions/shipping-class on the in-cart clone (residual: a quantity-based flat-rate formula still double-counts — needs shipping-package filtering if a real deployment uses that specific rate shape) |
| Cart-block server-render | the block's hydration path calls the Store API route directly, bypassing the normal REST filter | WooCommerce's own documented back-compat hydration filter (for exactly this situation) |
| Multicurrency | (no leak — S1-C's earlier "not obtained" result was a command-line bootstrap-timing artifact) | no fix needed; proven live via real HTTP+cookie flow: parent converts correctly, children stay exactly zero, persists through a fresh-process session reload |
| Coupons | a product-restricted coupon — including a **free-shipping** coupon — validated eligible off the hidden child alone | a real, documented core filter for coupon-item validation |
| Analytics | units-sold and (via allocated shipping) gross-revenue figures were non-zero for hidden children, though net revenue was correctly zero | a scope-flag order-items filter, bracketed around the real recurring Analytics batch action |
| Refunds | derived component refund lines must be linked and quantified correctly when a kit-parent line is refunded, and any restock must only ever happen once that refund is durable | `woocommerce_create_refund` for line-addition (pre-save) plus `woocommerce_refund_created` for restocking (post-save) — two real, documented core actions, live-proven by spike S1-G (PASS, corrected hook split) — see the M1 Refunds section. **Not** a fix for bare core's own missing idempotency guard against a repeated identical refund call; that real core gap is left as-is by product-owner decision (V17) — not this plugin's to fix, and the operation-ledger/locking/transaction design this row once described was rejected (C25) |
| Fulfillment parent-skip | unmodified fulfillment code ingests the non-pickable parent as a picking row | a one-line guard in the fulfillment plugin's order-source class, live-proven with the guard applied, with the change detector re-run, and with the bundling plugin fully inactive |

Every fix was proven closed for the leak case while a genuine standalone
purchase and a sitewide/eligibility discount on the parent both continued
to work correctly — no over-exclusion.

### New cross-cutting pattern, recorded as ADR-0007

S1-D's four non-fulfillment/non-refund fixes (promotions, coupons,
shipping, analytics) share one shape: find the real extension seam a given
consumer offers; add a narrow, precisely-scoped guard keyed on the
child-line marker; verify the genuine-purchase and sitewide-discount
control cases are untouched. This pattern will recur for any future
consumer not yet audited (a different promotions plugin, a subscriptions
extension, a different analytics package). It warrants its own ADR rather
than four buried point-fixes — see ADR-0007.

---

## ADR register

All statuses below describe the state each ADR reaches **upon merge of
this documentation-freeze pull request, not before** — see the governance
note above.

| ADR | Subject | Status |
|---|---|---|
| ADR-0001 | Simple-product representation and static pricing | Ready for acceptance upon merge — unaffected by the Architecture A→B decision |
| ADR-0002 | Component availability, reservation, reduction and restoration lifecycle | Ready for acceptance upon merge, for the stock lifecycle — Architecture B (V13). Reservation/reduction/restoration delegated to WooCommerce core, unmodified. Architecture A (S1-A/S1-B, V12) is superseded and retained only as rejected-alternative evidence. **Its refund clause is ready for acceptance at the narrow native-refund-line-linkage scope (V17), proven by spike S1-G (PASS).** The custom refund idempotency/orchestration subsystem S1-E/S1-F explored is a **rejected alternative by product-owner decision (C25)**, not carried forward |
| ADR-0003 | Versioned cart/order snapshot contract | Ready for acceptance upon merge — the kit snapshot and per-child meta contract (S1-C/S1-D) were settled and never in question. The refund-*operation* contract this ADR briefly grew (an operation ledger and refund-object operation-id meta, S1-E/S1-F) is **withdrawn by product-owner decision (V17/C25)** — refunds carry no plugin-owned operation contract in v1; the derived child-refund-line linkage lives in ADR-0002's refund clause instead, at the scope S1-G proved |
| ADR-0004 | Fulfillment-plugin expansion and compatibility contract | Ready for acceptance upon merge — "one-line skip," not expansion (S1-C/S1-D). The fulfillment plugin ignores any kit-marked line; every other line, including components, is ingested unmodified |
| ADR-0005 | Hidden-component visibility and direct-URL policy | Ready for acceptance upon merge — unaffected by the architecture decision |
| ADR-0006 | Cross-plugin rollout / readiness gate, and inactive-plugin safety | Ready for acceptance upon merge, narrowed — purchasability guard/capability handshake/deactivation-lock policy retained unchanged; the custom-status ownership term and background-stock-deferral responsibility are removed — proven unnecessary for stock-lifecycle correctness under Architecture B |
| ADR-0007 | Cross-cutting cart/order-line exclusion contract (promotions, coupons, shipping, analytics) | New; ready for acceptance upon merge — a hidden kit-component line must never be treated as a genuine customer selection by any cart/order/coupon/shipping/analytics consumer, native or third-party |

Full ADR text lives in [`docs/adr/`](adr/).

---

## M0 — Foundation (inert scaffold)

Separate implementation authorization and closure from M1.

### Immutable technical identity

| Item | Value |
|---|---|
| Display name | `Universal Commerce Bundles` |
| Repo / folder / slug / text domain | `universal-commerce-bundles` |
| PHP namespace | `UniversalCommerceBundles` |
| Hook + option prefix | `ucb_` |
| Private meta prefix | `_ucb_` |
| Composer package | `magpern/universal-commerce-bundles` |
| Minimum supported | PHP 8.1, WordPress 6.5, WooCommerce 8.2 |
| Actually exercised during spikes and their verification | WordPress 7.0.2, PHP 8.4.24, WooCommerce 11.0.1, MariaDB 11.8.8 |
| Intended CI matrix (proposal — not yet built) | PHP 8.1 × WP 6.5/WC 8.2 (floor) — PHP 8.4 × WP 7.0.2/WC 11.0.1 (spike-exercised) — PHP 8.4 × latest WP/WC at time of writing |
| Repository visibility | Public |
| Licence | GPL-2.0-or-later |

No version is described as "tested" unless it was actually run in a
container.

This identity supersedes an earlier internal working name, as part of a
portfolio-wide transition away from a legacy plugin-name prefix. Because no
implementation exists and no data has ever been released under the old
identity, **no compatibility aliases are retained** — not for the text
domain, hook and option prefixes, meta keys, namespace or package name.

**The identity becomes immutable once this plan is frozen.** After that
point a change requires a superseding ADR, because meta keys and the
snapshot contract are consumed across repository boundaries by the
fulfillment plugin and the storefront layer, and by then may exist in live
order records.

A vendored design-system dependency, if used, keeps its own upstream
identity — vendored code is recorded as its upstream names it, and renaming
it here would break the manifest/version record required below.

### Foundation scope

- Composer / PSR-4; layered `src/Domain`, `src/Application`, `src/Engine`,
  `src/Woo`, `src/Infrastructure`, `src/Admin`.
- **WooCommerce symbols confined to `UniversalCommerceBundles\Woo`**,
  enforced by a structural test.
- Activation, upgrade, deactivation; uninstall and data-retention policy —
  default **retain** plugin-prefixed order meta and ledgers, which are
  financial records.
- Capabilities, nonces, validation, sanitization, escaping.
- Logging, audit and redaction policy.
- High-Performance Order Storage (`custom_order_tables`) and
  `cart_checkout_blocks` declared unconditionally, even if boot fails.
- Classic and Blocks compatibility statement.
- **REST/Store API privacy boundary:** plugin-prefixed meta is private,
  never registered for REST, never surfaced through the Store API. Only the
  Contents line is customer-visible.
- CI matrix across supported PHP / WordPress / WooCommerce; coding-standard
  and static-analysis checks, unit tests, structural guards, documentation
  link checks, built-ZIP validation.
- Tag-driven GitHub release + release-artifact publishing.
- Release and rollback documentation.
- Public hook/API contract documented from day one.

### Vendored dependency record (closes V9)

If any dependency is vendored, record in the vendored directory's own
README: upstream repository and **exact version / commit SHA**; licence and
attribution; **local modifications not permitted** (drift is a bug); update
procedure (re-vendor, regenerate checksum manifest, diff, re-run CI); and a
CI check that the manifest hashes match the tree. Vendoring removes the
runtime dependency, not the version obligation.

---

## M1 — Fixed kits core

### Composition and pricing (ADR-0001)

A kit is a published **`simple`** product with its own SKU and a normal
static price, plus a components-metadata field. No custom product type.
Standard regular/sale price fields are authoritative; no runtime summation,
no computed pricing mode.

A one-shot admin **"Calculate suggested price from components"** action
writes an ordinary product price only when clicked, recording the basis
(component prices and percentage at acceptance). An admin notice appears
when current prices drift from that basis. Live prices are never
auto-recalculated.

### Derived availability and invalidation (ADR-0002)

**Availability formula:** a kit's available quantity is
`min( floor( component_available / qty_per_kit ) )` across components,
where `component_available` accounts for the component's own stock **and**
existing reservations, keyed on the stock-managed id.

- **Backorder policy:** a backorder-enabled component **does not
  constrain** calculated kit availability — it is excluded from the
  minimum rather than treated as satisfying it. **Every other component
  must still satisfy its own availability requirement.** A backordered
  component therefore never makes a kit purchasable while a different
  required component is unavailable. Backordered components are still
  recorded in the ledger, and the picking list must show them explicitly.
- **Non-stock-managed components:** per core's own convention, they are
  always available, never reduced, never restored, and recorded as
  unmanaged.
- **Combined standalone and kit demand:** the same component requested
  both standalone and via a kit in one cart must be summed before the
  availability check, and again before reservation.
- **Deleted or unpublished components** invalidate the composition.

**Validity is computed live at the decision point, never trusted from
cache.** A composition-validity flag is a **cached hint for admin display
only**; the authoritative check runs inside the purchasability and
add-to-cart-validation filters. A stale hint must never be able to make an
invalid kit purchasable.

**Invalidation and reverse lookup:** maintain a reverse index from
component → parent kits (rebuildable). On component save, delete,
unpublish, stock change, price change or tax-class change, mark the
affected kits for revalidation, refresh the cached hint, and raise an admin
notice. Provide a reconciliation routine that rebuilds the index and
revalidates every kit, for use after imports, bulk edits or a restore —
anything that bypasses the save hooks.

**Invalid composition — missing or deleted component, or mixed tax
classes — makes the kit non-purchasable until corrected.** A warning alone
is insufficient.

### Cart and order construction — Architecture B (ADR-0002, ADR-0003)

One add-to-cart of a kit produces one priced parent cart line plus one
real, zero-priced child cart line per component (quantity = kit quantity ×
per-kit quantity), linked by parent-key/parent-item-id meta. Parent
quantity changes synchronise children. A standalone purchase of the same
product never merges with a kit-linked child — proven live as a distinct
cart line. Removing the parent removes its children; no orphaned child
lines are possible. Customers cannot directly manipulate child quantities:
classic cart renders children as non-editable text and a cart-update guard
drops any direct mutation attempt; the Store API path throws the same typed
exception S1-A proved gives a clean, customer-facing `400` for any
attempted update to a child line key. Both classic and Store API/Blocks
checkout produce correct linked cart/order state.

### Reservation, reduction and restoration (ADR-0002)

**Delegated entirely to WooCommerce core, unmodified.** Because each
component is a real, stock-managed order/cart line, core's own reservation,
reduction and restoration functions already reserve, reduce and restore
correctly — no plugin reservation writer, no journal, no explicit
transaction, no transactional outbox, no crash-recovery mechanism.
Live-proven: two concurrent OS processes racing the last unit of a
component (one via a kit, exercising the same locking core always uses);
real order-completion/cancellation calls correctly reducing and restoring
the reduced-stock record on the real child order items; and, decisively,
the same lifecycle working correctly with the producing plugin **fully
deactivated** — because none of this depends on the plugin's code
remaining loaded.

**Architecture A's entire custom stock-transaction subsystem is not
implemented under this decision.** It remains correct on its own terms and
is retained as evidence for the rejected alternative (see "Architecture A"
above).

### Pricing, VAT, multicurrency, shipping — zeroing the child lines (ADR-0002, S1-C/S1-D)

A cart-totals hook (priority chosen deliberately non-default, proven
ordering-independent) forces every child line's price, weight, dimensions
and shipping class to zero on the in-cart product clone (never persisted
back to the real product) on every cart recalculation. Proven live: parent
carries the full kit price and tax; children stay exactly zero through
session reload, admin recalculation, and every hook-priority position
tested; a companion multicurrency plugin converts the parent correctly
while children stay zero in every currency, proven via a real HTTP+cookie
round-trip through cart, checkout and the persisted order.
Weight/dimensions/shipping-class are zeroed the same way, closing a real
double-counting leak (a component's own catalogue weight and shipping
class otherwise leaked into cart shipping calculation). **Residual, not
blocking:** a shipping method whose cost formula is keyed on cart-line
*quantity* still double-counts, since children remain real, separate lines
by design; closing that specific configuration would need a shipping-
package content filter, not implemented — relevant only if a real
deployment configures a quantity-based rate.

### Refunds — native flow only, derived component-line linkage (ADR-0002, ADR-0003, hook-ordering corrected — see `docs/spikes/s1-g-native-refund-line-linkage.md`)

**v1 scope, stated precisely, per product-owner decision:** UCB does not
own refund creation, refund persistence, gateway refunds, retries,
duplicate-submission handling, concurrency control, transactions, journals,
locks, or recovery sweeps. UCB does add derived component refund lines
before the refund is saved (the pre-save `woocommerce_create_refund` hook).
After the refund is durable, UCB invokes WooCommerce's exported restock
function for those persisted derived child lines, only when the caller
requested restocking (the post-save `woocommerce_refund_created` hook). The
residual crash window (refund saved, restock not yet run) is intentionally
no better and no worse than WooCommerce's own native refund/restock flow
for an ordinary product — it is surfaced for ordinary operational
correction, not solved by a custom protocol.

Restated in flow terms: this plugin supports only WooCommerce's normal,
native refund flow. Its entire refund responsibility is: when a kit-parent
order line is refunded through that native flow, add the correctly linked
component-child refund lines at the correct derived quantity, then, once
the refund is durable and only if restocking was requested, trigger
WooCommerce's own restock for those derived lines. Everything else about a
refund — creating and persisting the refund row, optional restocking of the
line items the caller actually supplied, refund totals, admin
nonce/request handling, payment-gateway refund execution, and API/webhook
retry semantics — remains WooCommerce's, unmodified.

**The linkage arithmetic:**

```
child_refund_qty = (original_child_qty / original_parent_qty) × parent_qty_refunded
```

**The seam — two hooks, not one.** An earlier version of this design put
both line-addition and restocking in a single pre-save hook; review found
that restocking real, shared component stock before the refund that
justifies the mutation is durable is unsafe (a crash or a failed save in
between would leave stock adjusted with no refund record to justify it).
The corrected, live-proven design (PASS, both legacy and custom
order-storage modes — full detail and evidence in
`docs/spikes/s1-g-native-refund-line-linkage.md`, including its correction
section) splits the two responsibilities across two real, documented
WooCommerce actions:

1. A refund-creation action fires on the fully-built, **not-yet-saved**
   refund object, before WooCommerce's own save and before WooCommerce's
   own restock call. A callback gated to only fire when a refunded line
   carries this plugin's kit-parent marker (an ordinary product's refund is
   untouched — live-confirmed) finds each real, linked child order item,
   computes its derived quantity, and attaches a correctly linked,
   zero-total child refund line — tagged with a private marker so it can be
   found again once persisted — to the in-memory refund object, persisted
   by WooCommerce's own save immediately after, not by any separate write
   from this plugin. **No stock mutation happens here.**
2. A **second** action, which fires only after that save has already
   succeeded, after WooCommerce's own restock call for whatever line items
   the caller supplied, and after the order's refunded-status update: only
   if restocking was requested, this plugin re-reads the now-persisted
   refund's own line items, keeps the ones it tagged in step 1, and calls
   WooCommerce's own exported restock function for exactly those derived
   quantities. WooCommerce's own restock call at step 1's point in the flow
   only ever sees whatever line items the admin UI or REST caller supplied,
   which never name the hidden child lines (ADR-0005), so this second,
   later call is how WooCommerce's own restock function ends up covering
   them; it is not a reimplementation of restocking, and it only ever runs
   once the refund it is restocking for is already durable.

Full mechanism, both storage modes, and all required live-proven cases
(full refund with restock; partial refund of one kit from an order holding
two distinct kits, with exact derived quantities and the other kit's
components left untouched; refund with restocking disabled leaving stock
unchanged while still creating correct linked child lines; an ordinary
non-kit refund entirely unaffected) — **plus a live ordering assertion
(stock is unchanged immediately after the refund's save succeeds, and
changes only once the second, post-save action has run) and a real
crash-window test (a process killed between the refund becoming durable
and the post-save restock action completing leaves the refund correct and
unrestocked — reproduced identically, with no plugin code loaded at all,
against bare WooCommerce's own restock call, proving this is an accepted,
pre-existing native limitation and not a new one)** — are in
`docs/spikes/s1-g-native-refund-line-linkage.md`.

**Confirmed absent from this design, in either hook:** no order-wide
transaction; no private/internal WooCommerce API (every call used is
public, and both actions used are documented core hooks); no custom
refund table; no custom operation record; no broad item-hiding filter.

**Accepted residual crash window, not a new failure mode:** if the process
dies after the refund becomes durable but before the post-save restock
action completes, the refund is correct and durable but the affected
components are not restocked. This is the same limitation bare WooCommerce
already accepts for its own restock call, which sits in exactly the same
kind of unprotected gap after its own save succeeds — live-confirmed
identical with no plugin code involved. It is surfaced for manual operator
correction (the absence of WooCommerce's own "stock increased" order note
is itself the detectable signal), not solved with a transaction, lock,
journal or reconciliation sweep — building one would repeat the exact
complexity this design was deliberately scoped away from.

**Explicit non-goals, so they are never quietly re-added:** this plugin
does not expose or own a generic refund API, a webhook wrapper, a
gateway-refund flow, a retry mechanism, or any exactly-once promise across
crashes, concurrent requests, HTTP retries, gateways or webhooks. **A real,
live-proven defect in bare WooCommerce core** — its refund-creation
function has no idempotency guard of its own; a repeated call with an
identical line-items payload double-restocks every component — is left
as-is; this plugin does not attempt to fix it, and does not need to,
because it owns no refund-creation or restocking step for such a duplicate
to corrupt beyond what bare core already risks for an ordinary product
today. **Duplicate-refund prevention for any external integration** (a
retried webhook, a double-submitted API call) **is that integration's own
responsibility**, using its own idempotency mechanism, enforced before it
ever calls into WooCommerce's refund flow — not a problem this plugin
solves internally.

**Rejected alternative, retained as evidence, not carried forward:** S1-E
and S1-F designed and live-proved a custom refund idempotency/
orchestration subsystem — an operation ledger, a `pending`→`completed`
state machine, a two-layer distributed lock, database transactions
bracketing refund creation and restocking, and a required-but-unbuilt
reconciliation sweep — all of it real, working engineering, and all of it
rejected by the product owner as disproportionate to a generic v1 plugin's
responsibility. It is preserved in `docs/spikes/s1-e-*` and
`docs/spikes/s1-f-*`, each carrying a visible correction note, as the
record of why that path does not fit — not as an accepted design.

### Partial-failure handling — fail safe

If a component operation fails partway (out of stock at the exact moment
of a real, non-atomic multi-line reduction — a residual risk not
eliminated by delegating to core, since core reduces each line
independently):

1. Record the actual per-component outcome via an order note and a
   structured audit entry.
2. Put the order into a controlled problem state; block normal
   progression. A dedicated status (optionally reusing a "needs attention"
   status slug from the rejected Architecture A design, now purely a
   general marker with no stock-lifecycle load-bearing role) may be used
   for operator visibility, but is not required for correctness under
   Architecture B.
3. Transition the associated fulfillment record to `problem` so it cannot
   enter picking.
4. Provide an explicit operator recovery action; no automatic compensation
   (rolling back a successful reduction is unsafe — another process may
   have consumed the freed quantity in the interval).

### Post-order edit policy

Quantity changes are permitted **only before** component stock reduction
**and before** fulfillment intake. After either, the change is blocked; if
it happens anyway, the fulfillment record transitions to reconciliation/
`problem` — the unmodified change-detector diff already produces this
correctly, since each component now has its own real, stable
`order_item_id` and the V1 collision this used to require a rewrite for
cannot occur. Historical composition is never rebuilt from the current
product definition.

### Component visibility (ADR-0005)

| Concern | Default |
|---|---|
| Not purchasable standalone | yes |
| Excluded from catalog + search | yes |
| Excluded from sitemap | yes |
| Excluded from REST / Store API listings | yes |
| Direct URL target | unset |
| Internal availability (kit rendering, admin, fulfillment) | always |

**Multiple parent kits:** redirect only to an explicitly selected canonical
kit; if unset, return **404**. No inference. All switches are reversible,
so selling a component standalone later is a setting change. This is about
the **catalogue product page** for a component, independent of the
Architecture A→B decision, which only concerns order/cart *line items*.

### Order and cart-line snapshot contract (ADR-0003)

A private, never-REST-exposed kit-snapshot meta value is written on the
**parent** line at checkout on both the classic and Store API paths —
retained for historical composition rendering and refund-linkage
arithmetic:

```
{ v: 1, kit_id, kit_sku, kit_qty,
  components: [ { stock_managed_id, product_id, variation_id, sku, name,
                  qty_per_kit, qty_total } ] }
```

**New, per real child order/cart line (S1-C/S1-D, replacing Architecture
A's single-line design):**

| Meta key purpose | Purpose |
|---|---|
| Parent-item link | links the child back to its parent kit line |
| Component marker | marks the line as a hidden kit-component child (the load-bearing exclusion key consumed by ADR-0007's cross-cutting contract) |
| Snapshot version | the snapshot schema version this child was created under |
| Position | stable ordering for Contents-line rendering |

**Not part of this contract, by product-owner decision:** an earlier draft
of this ADR grew a refund-*operation* contract here — an order-meta
operation ledger and a refund-object operation-id meta key — to support the
custom refund idempotency/orchestration subsystem S1-E/S1-F explored. That
subsystem was rejected; neither meta key is written by v1. The
refund-linkage arithmetic lives in ADR-0002's refund clause instead, added
via the two hooks S1-G proved (a refund-creation action for line-addition,
a post-save refund-created action for restocking — corrected split, see
`docs/spikes/s1-g-native-refund-line-linkage.md`), and needs no meta
contract of its own beyond the standard refunded-item linkage meta already
placed on each derived refund line item at creation time, plus one small
marker meta letting the post-save hook find exactly the lines the pre-save
hook added (see the M1 Refunds section).

Contract rules:
- **Merge rule:** within one kit line, a component listed twice in
  composition becomes one child line with summed per-kit quantity —
  unchanged from Architecture A.
- Historical orders render **only** from the snapshot.
- Plus a visible **Contents** line for the customer, emails and admin,
  built from the parent's snapshot, not from the (hidden) real child
  lines.
- Presentation hiding of child lines is **server-side filtering, not
  CSS**, on every surface: classic cart/checkout via WooCommerce's
  cart-item-visibility filters; Store API JSON via the REST post-dispatch
  filter; the Cart block's server-rendered hydration via WooCommerce's own
  back-compat hydration filter (the seam that actually governs it, since
  the block's hydration path calls the Store API route directly, bypassing
  the standard REST server entirely); admin order view, emails, and
  account pages via a narrowly-scoped order-items filter, active **only**
  inside the specific customer-facing template/email hooks — never a
  blanket "not admin" check, which a real defect found in a
  proof-of-concept would have broken the fulfillment plugin's own
  item-reading calls had it shipped that way; REST v3 orders via
  WooCommerce's order-object REST-preparation filter.

---

## Fulfillment-plugin milestone — parent-line skip (formerly "component expansion")

Separate repository, own plan, branch, PR, validation and closure.

### Parent-line skip (ADR-0004)

Because each component is now a real, distinct WooCommerce order line with
its own real `order_item_id`, the fulfillment plugin needs **no
expansion** — the picking rows already exist. The entire "independently
detect and expand a versioned snapshot" design (Architecture A) is
replaced by one guard: **skip the non-pickable parent.**

In the fulfillment plugin's order-source class, inside the existing loop
over line items, immediately after the existing product-line-item type
guard:

```php
if ( $item->get_meta( '_ucb_kit', true ) ) {
    continue;
}
```

Reads only persisted order-item meta — no plugin class, autoloader or
constant dependency, satisfying the independent-detection requirement (C13)
for free. Live-proven: intake produces picking rows only for the real
children, never the parent; and, with the bundling plugin **fully
deactivated**, a fresh order built with the snapshot/component meta already
attached (simulating a real checkout that happened while the plugin was
active) is still correctly skipped — meta persists independently of plugin
state (C18).

**Stock-readiness precondition — removed, not merely relaxed.** Architecture
A needed a "completed journal operation" gate before intake, because core
skipped the (unmanaged) kit line and only a plugin-owned journal proved
reduction happened. Under Architecture B, core reduces the real child lines
itself — by the time any status transition that would trigger fulfillment
intake has occurred, core's own reduced-stock record on the child line
already reflects reality. No separate fulfillment-side stock-readiness
check is required.

### The change detector — unmodified (closes C2, C14, live-confirmed no rewrite needed)

Architecture A required rewriting the change detector to derive
expectations from the immutable snapshot, because expansion collapsed
multiple components onto one synthetic `order_item_id` (V1's "sums many
against a live one"). **Under Architecture B this collision cannot
occur** — each component already has its own real, stable `order_item_id`,
so the existing, unmodified diff (keyed on `order_item_id`) is already
correct. Live-proven: re-firing the order-items-saved event for an
unmodified order, both without and with the parent-skip guard applied,
left fulfillment state `queued` — no spurious `problem` flag in either
case. The detector's contract is otherwise unchanged: any real difference
(a genuine quantity edit, a removed/added component line) still flags
`problem`.

### Schema sufficiency

No migration is required — every comparison-key field is already
persisted, and row identity for barcodes/scanning already uses the row's
own primary key, unaffected by the architecture decision.

**Recorded residual, unchanged:** with `kit_qty > 1`, a component's real
child line carries a summed quantity as one line — the fulfillment plugin
still cannot attribute a picked unit to a specific kit instance without
further work. Same limitation as Architecture A; not introduced or
worsened by Architecture B. Acceptable for picking; no per-instance
traceability in v1.

---

## Promotions-plugin milestone — default kit exclusion (closes C15) and hidden-component exclusion (ADR-0007)

An admin report listing kit ids enforces nothing; every existing and
future campaign could still omit the exclusion. Rather than weaken the
settled policy, add a **separate promotions-plugin milestone** that
implements default kit exclusion before launch.

**Kit-level exclusion, unaffected by the architecture decision:**
- The promotions plugin owns the rule natively, keyed on a documented
  "is a kit" product meta value — **no runtime plugin dependency in either
  direction**, but a documented **data-contract dependency**.
- Default: kit products are excluded from sitewide percentage campaigns. A
  campaign must **explicitly opt kits in**.
- **Keeps working when the bundling plugin is inactive**, a safety
  property. Product meta persists in the database independently of plugin
  state (C18).

**New, Architecture B-specific: hidden child-line exclusion (ADR-0007,
closes a real leak found and closed live by S1-D).** The promotions
plugin's cart-context projector projects every cart row unconditionally,
including a hidden zero-priced child's real product id and categories — a
product/category/quantity condition targeting a kit component was
live-proven to fire off the hidden child alone, with **no genuine
standalone purchase** of that component in the cart. Fix, live-proven
closed, touching three files:

1. The cart-context builder, immediately after the existing free-gift
   field assignment: add an `is_kit_component` boolean sourced from
   cart-item meta.
2. The product/variation cart-item matcher used by product-in-cart and
   product-quantity conditions: return `false` early when
   `is_kit_component` is set.
3. The category matcher used by category-in-cart conditions, and the
   category-quantity condition (which does not route through the shared
   matcher): skip past rows with `is_kit_component` set.

Live-proven, all four condition types: closed for a kit-only cart, while a
**genuine** standalone purchase of the same product still correctly fires
every condition, and a sitewide "minimum subtotal" condition (reading cart
subtotal, not per-item rows) is unaffected and correctly counts the parent
kit's real price. No new promotion is ever made ineligible — this is a
per-row exclusion, not a new *condition type*, so it does not trigger the
promotions engine's "unrecognised type" hazard (V7).

This milestone gates launch alongside the fulfillment-plugin one. Decision
6 (no promotions integration in v1) is preserved: this is promotions-side
work with no code dependency in either direction.

---

## Cross-cutting cart/order-line exclusion contract (ADR-0007)

Architecture B's core property — components are real WooCommerce line
items — has one systemic consequence: **any consumer that iterates real
order/cart lines can now see hidden kit components**, and several treat
presence as meaning "the customer selected this," which is false for a
hidden child. S1-D found and closed four such leaks, each via a different
real, native or third-party extension seam, none requiring WooCommerce core
to be patched. This ADR records the pattern once, rather than leaving four
unrelated point-fixes for future implementers to rediscover independently —
and the same pattern will recur for any consumer not yet audited (a
different promotions plugin, a subscriptions extension, a different
analytics package).

**The rule:** a line item carrying the component marker (order-item meta)
or the child cart-item flag must never be treated as a genuine,
independent customer selection by any cart/order/coupon/shipping/
analytics/promotion consumer — native WooCommerce or third-party — unless
that consumer's job is specifically stock/fulfillment/refund
reconciliation (where the real line must be visible and real).

**The pattern, repeated per consumer:**

| Consumer | Real seam | What it does |
|---|---|---|
| Promotions condition engine | cart-context/cart-item-selector edits (above) | excludes hidden children from product/category/quantity condition matching |
| WooCommerce core coupon eligibility | `woocommerce_coupon_get_items_to_validate` (real, documented core filter) | excludes hidden children from product/category coupon-restriction validation — closes a genuine leak where a **free-shipping** coupon restricted to a component was validated eligible off the hidden child alone, unlocking a real benefit with no genuine standalone purchase |
| WooCommerce core shipping | the existing cart-totals price-zeroing hook, extended to weight/dimensions/shipping-class | prevents double-counted weight, dimensions and shipping-class bucketing (residual: a quantity-based flat-rate formula needs a separate shipping-package fix if configured) |
| WooCommerce core Analytics | a scope-flag order-items filter, bracketed around the real recurring Analytics batch action | prevents units-sold/gross-revenue pollution from hidden children (net revenue was already correctly zero) |
| Cart block server-render | WooCommerce's own documented back-compat hydration filter (since the block's hydration path bypasses the normal REST filter) | prevents child-line exposure in the first-paint hydration payload |

Every fix in this table was proven, live, both to close the leak **and**
to leave a genuine standalone purchase and a sitewide/eligibility discount
on the parent line unaffected — this is an exclusion contract, not a
blanket line-item filter, and a blanket approach (e.g. a bare "not admin"
check) was tried, found to silently break the fulfillment plugin's own
item-reading calls, and explicitly rejected in favour of narrowly-scoped
filters active only inside the specific consumer's own extension point.

**Future consumers:** any new cart/order-line consumer added to this
plugin's ecosystem (a different promotions engine, a subscriptions
extension, a loyalty/points plugin, a different analytics package) must be
evaluated against this same question — does it iterate real line items in
a way that could treat a hidden child as a genuine selection? — before
being declared compatible with kits.

---

## Cross-plugin contract and rollout gate (ADR-0004, ADR-0006, ADR-0007)

| Element | Publisher | Consumer |
|---|---|---|
| Kit snapshot schema (parent line) | this plugin | fulfillment plugin (skip guard), storefront (Contents line) |
| Component/parent-item-id/snapshot-version/position meta (child lines) | this plugin | promotions, coupons/shipping/analytics exclusion filters (ADR-0007), fulfillment plugin (implicitly, via the parent-skip guard only) |
| Parent-skip contract | this plugin documents the kit marker as the skip key | fulfillment plugin, independently (no expansion, no version negotiation needed) |
| Readiness signal | fulfillment plugin answers | this plugin gates purchasability |
| "Is a kit" product meta | this plugin | promotions (data-contract dependency, no code dependency) |
| Capability signal (persisted option + readiness handshake) | this plugin, only after successful init | host safety-guard MU-plugin |

**Unknown snapshot version:** the fulfillment plugin fails closed on the
*parent* line (skips it regardless, since the skip key is the presence of
the kit marker, not its version) — there is no longer a version-negotiated
*expansion* to fail on, since the fulfillment plugin never expands.

**The stock-readiness intake gate from Architecture A is removed**
(ADR-0004, V13): under Architecture B, core reduces the real child lines
itself before any status transition that would trigger fulfillment intake,
so there is no separate "completed ledger" state for the fulfillment
plugin to check.

**Rollout gate:** this plugin exposes a readiness filter defaulting to
`false`; a compatible fulfillment plugin answers `true` for the versions it
implements. When the site is configured picked-to-order and no compatible
consumer answers, kit products are **not purchasable** and the admin shows
why. "Both plugins installed and rolled out individually" is an
operational habit, not a gate. The gate is a per-site setting, so the
plugin stays generic for sites with no fulfillment plugin.

### Inactive-plugin safety (closes C17)

A kit is an ordinary `simple` product. If this plugin stops running, its
purchasability and availability filters simply disappear and **the kit
remains purchasable as a normal product** — with no component availability
check, no reservation and no component reduction. The fulfillment plugin's
fail-closed intake protects orders already placed; it does nothing about
new unsafe orders. No plugin can enforce behaviour while its own code is
inactive, so protection must live in **persisted product state** plus an
**independently loaded host guard**.

**Policy:**

1. **Deactivation writes persistent safety state before completing.** The
   deactivation hook marks every kit non-purchasable through data that
   survives without the plugin — an out-of-stock status plus a "locked"
   marker recording that the lock was set by deactivation. Persisted state
   is the only mechanism that still works when no plugin code runs.
2. **Reactivation does not auto-unlock.** Locked kits stay locked until an
   explicit admin action, because the reason for deactivation is unknown to
   the plugin and stock may have moved meanwhile. Revalidate composition
   before offering the unlock.
3. **Host guard — a must-use plugin.** An ordinary "storefront" plugin is
   *not* sufficient: it is an ordinary plugin and can itself be
   deactivated or fail, so it is not independent of the failure it is
   meant to catch. The guard must be a **must-use (mu) plugin**, which
   loads unconditionally and cannot be deactivated from the admin. It
   must:
   - read the "is a kit" flag directly from product meta, which persists
     regardless of plugin state (C18);
   - have **no dependency on this plugin's classes, autoloader or
     constants** — it must function when none of it is loaded;
   - check for a **healthy runtime capability signal**;
   - block purchase of kit products whenever that signal is absent;
   - remain active when ordinary plugins are disabled;
   - be covered by deployment and acceptance tests.

4. **Two separate signals — a persisted contract record and a
   request-local readiness handshake.** A persisted option alone **cannot
   prove current health** and goes stale in exactly the case that matters:
   the plugin initialises once and writes the option; its files are later
   removed or corrupted; a subsequent request runs without it; the stale
   "healthy" option remains and the guard wrongly allows kit purchases.
   Therefore:

   - **Persisted contract record** — expected plugin version, supported
     snapshot versions and configuration. Survives requests, is
     **informational only**, and is never sufficient on its own to permit
     a purchase.
   - **Request-local readiness handshake** — starts `false` on **every**
     request. The plugin emits a stable hook, `ucb_runtime_ready`, only
     after complete initialisation. The MU-plugin listens for it.

   **The purchasability guard requires the request-local signal.** The
   MU-plugin must never infer current health from the persisted record
   alone; the record is used to check *which* contract version is
   expected, not *whether* the plugin is alive.

   Bootstrap-failure protection is thereby the guard's responsibility, not
   this plugin's: a plugin that dies before registering hooks cannot
   protect anything, so any design where this plugin is expected to notice
   its own failure is circular. This plugin's only obligation is to emit
   `ucb_runtime_ready` if and only if it has fully initialised.

5. The custom "stock problem" order status is **removed as a required
   MU-plugin responsibility under Architecture B.** That status existed to
   give a rejected custom stock-transaction subsystem a safe deferral
   state; it is no longer load-bearing for correctness, since core's own
   reduction/restoration lifecycle already works correctly with the
   bundling plugin inactive. A general-purpose "needs attention" status
   may still be useful for the partial-failure case, but its registration
   is no longer part of the MU-plugin's required cross-repository
   contract, and it is no longer specifically named by necessity — that
   naming is an implementation detail if the status is used at all, not a
   contract term.

### Capability contract (closed)

| # | Term |
|---|---|
| 1 | The plugin emits `ucb_runtime_ready` **exactly once**, as the final successful bootstrap step |
| 2 | Payload carries `plugin_version`, `contract_version`, `snapshot_versions` |
| 3 | The MU-plugin starts **every** request with readiness `false` |
| 4 | It listens for the action and sets readiness `true` **only after validating the payload** |
| 5 | At a **late purchasability-filter priority**, a product marked as a kit requires readiness `true` |
| 6 | The persisted option is **separately named and versioned, non-authoritative**, and can never set runtime readiness |
| 7 | The guard references **no plugin class, constant or autoloader** |

Term 1 makes a partially initialised plugin indistinguishable from an
absent one, which is the desired behaviour. Term 5 places the check late so
any other plugin's earlier veto still wins. Terms 6 and 7 are what keep the
guard functional when the plugin is not merely inactive but entirely gone
from disk. **The former term 8 (custom order-status registration) is
removed under Architecture B.**

This contract is published and documented by this plugin (ADR-0006) and
implemented by the host repository. It is a cross-repository contract and
therefore falls under the post-freeze immutability rule.

Division of responsibility: terms 1, 2 and the publishing half of term 4
belong to the generic plugin; terms 3 and the enforcing half of term 4 are
host configuration and are therefore **not** part of the generic plugin —
consistent with decision 2, and recorded in the integration documentation
as a deployment requirement for any picked-to-order site.

### Host MU-plugin placement — general guidance

A host guard of this kind should be:

- deployed as an individually mounted or otherwise deployed, read-only,
  single-file must-use plugin — never a whole-directory mount that could
  shadow other, unrelated must-use plugins already present on the host;
- present in **every** runtime context that can execute WooCommerce
  operations, not only the primary web process — including any CLI/cron
  runner used for scheduled WooCommerce work — so the purchasability guard
  is available wherever `is_purchasable()` might be evaluated outside a
  normal web request;
- tracked in version control as a first-class file, not left as an
  untracked file directly in a runtime data directory — a safety guard
  whose entire job is to survive plugin failure must not itself be
  unversioned;
- kept out of scope for unrelated pre-existing must-use plugins on the
  same host — bringing pre-existing untracked must-use plugins under
  version control is a separate infrastructure concern, not something this
  milestone should absorb "as a side benefit."

**The justification for the dual runtime-context deployment changed under
Architecture B, but the conclusion did not.** Under the rejected
Architecture A, this was load-bearing: cron-driven stock reduction needed
the guard present in every WooCommerce-capable runtime context specifically
to defer background stock operations. Under Architecture B, core's own
stock lifecycle handles that case correctly with no guard involvement at
all — so that specific justification no longer applies. The guidance is
retained anyway, defensively: the MU-plugin's remaining responsibility, the
*purchasability* guard, should still be present wherever WooCommerce
operations can run.

---

## Governance

Workflow per milestone: `plan → review → documentation-only freeze →
implementation → validation → closure`.

- M0 and M1 each require separate implementation authorization and
  closure.
- The fulfillment-plugin and promotions-plugin milestones each have their
  own plan, branch, PR, validation and closure in their own repository.
- ADR-0001 through ADR-0007 are authored and reviewed, and **become
  accepted upon merge of this documentation-freeze pull request, not
  before**. ADR-0002's precondition was two full rounds of
  live-executed spike evidence: S1-A/S1-B (Architecture A, V12, both PASS
  on their own terms, retained as rejected-alternative evidence) and
  S1-C/S1-D (Architecture B, V13, both PASS, selected). ADR-0004 and
  ADR-0006 reflect Architecture B's narrower fulfillment/host-guard
  contracts. ADR-0007 is new, covering the cross-cutting exclusion contract
  S1-D's four closed leaks share.
- This document becomes frozen upon merge of this documentation-only
  freeze pull request, not before. Any future substantive change to an
  accepted ADR requires a superseding ADR, per the immutability rule in
  M0's identity section.
- **No implementation authorization is granted by this freeze.** M0, M1,
  the fulfillment-plugin milestone, and the promotions-plugin milestone
  each still require their own separate implementation authorization before
  any code is written.

---

## Acceptance coverage

**Composition, availability and invalidation**
- Kit order quantity greater than one; component with `qty_per_kit > 1`.
- Two kits sharing a component.
- Mixed tax classes → kit non-purchasable until corrected.
- Component deleted / unpublished → parent kits invalidated and
  non-purchasable.
- Component stock, price or tax-class change → affected kits revalidated,
  notice raised.
- Stale composition-validity hint cannot make an invalid kit purchasable.
- Reverse-index reconciliation after a bulk import that bypasses save
  hooks.
- Backordered component alongside a **fully available** component → kit
  purchasable, backorder shown on the picking list.
- Backordered component alongside an **unavailable** component → kit
  **not** purchasable.
- Non-stock-managed component: available, never reduced, never restored.
- Currency switch converts on PDP, cart, checkout and created order.
- VAT on the created order equals VAT on the amount paid — classic **and**
  block checkout.

**Cart and order construction (Architecture B — S1-C, executed)**
- One add-to-cart produces one parent + N linked child cart lines; parent
  quantity change synchronises children.
- Standalone purchase of a component and a kit holding the same component
  in one cart never merge — distinct cart lines.
- Removing the parent removes its children; no orphaned child lines are
  possible.
- Customer cannot directly manipulate child quantities: classic
  (non-editable quantity field, cart-update guard) and Store API (typed
  route exception → clean customer-facing `400`, not a bare exception →
  opaque `500` — regression-test this distinction explicitly).
- Classic and Store API/Blocks checkout both produce correct linked
  cart/order state.

**Reservation, reduction, restoration — delegated to core (Architecture B — S1-C, executed)**
- Two simultaneous checkouts for the last available kit — exactly one
  succeeds, via core's own unmodified locking (proven with real, separate
  OS processes).
- Reduction and restoration on the real child order items, via core's own
  reduction/restoration functions — **no plugin reservation/journal/outbox
  code involved.**
- **Plugin fully deactivated after checkout, before a status transition
  (simulating a scheduled cron pass with the plugin unavailable)** → core
  still correctly reduces and later restores real component stock,
  unassisted. This is the decisive test and must be reproduced during M1
  implementation exactly as executed in the spike: deactivate the plugin,
  confirm zero plugin code loaded, then transition status.
- Backorder-enabled and non-stock-managed components behave per core's own
  convention on the real child line — unchanged from ordinary WooCommerce
  products, no kit-specific logic needed.
- Reservation expiry releases the hold; abandoned checkout releases it —
  core's existing behaviour, unmodified.

**Pricing, VAT, multicurrency, shipping (Architecture B — S1-C/S1-D, executed)**
- Parent carries the full kit price and tax; children are exactly zero —
  through session reload, admin recalculation, and regardless of
  zeroing-hook priority.
- The multicurrency plugin converts the parent correctly; children stay
  exactly zero in every currency, proven via a real HTTP+cookie round-trip
  through cart, checkout, and the persisted order, and through a
  fresh-process session reload.
- **Shipping weight, dimensions and shipping class must not double-count**
  the hidden children on top of the parent — regression-test via a real
  cart shipping-calculation call, not a manual sum.
- **Known residual, not a defect:** a shipping method whose cost formula is
  keyed on cart-line quantity still double-counts; avoid that specific
  rate configuration, or implement the shipping-package filtering fix if
  it's required.
- Coupons: a product/category-restricted coupon — **including a
  free-shipping coupon** — must not validate as eligible off a hidden
  child alone (S1-D found this is a real, not merely theoretical,
  exploit); a genuine standalone purchase of the same product must still
  make the coupon eligible; a sitewide coupon must still apply to the
  parent kit line's real price.

**Refunds — native flow only (Architecture B — S1-C/S1-D executed; native-refund seam proven by S1-G — PASS; hook-ordering corrected, see the spike's correction section)**
- Native refund **with** "Restock refunded items" enabled creates one
  normal WooCommerce refund object containing both the parent refund line
  and the correctly linked derived child refund lines, and restocks each
  component exactly once, via WooCommerce's own restock code (not a
  reimplementation).
- Partial refund of kit quantity (e.g. 1 of 2 kits, or one kit among
  several distinct kits in one order) derives the child quantities
  **exactly** —
  `child_refund_qty = (original_child_qty / original_parent_qty) × parent_qty_refunded`
  — not double, not zero; only the refunded kit's own components are
  restocked, every other kit's components remain correctly untouched.
- Native refund **with restocking disabled** still creates the correctly
  linked derived child refund lines; component stock does not change.
- An ordinary, non-kit product's refund is entirely unaffected — no plugin
  code path interferes with it (live-confirmed, S1-G).
- **Ordering assertion (both storage modes):** stock is unchanged
  immediately after the refund's save succeeds, and changes only once the
  post-save restock action has actually run — this is what distinguishes
  the corrected design from an earlier version that restocked from the
  pre-save hook instead.
- **Accepted native-shaped crash-window limitation, not a new failure
  mode:** if the process dies after the refund becomes durable but before
  the post-save restock action completes, the refund is correct and
  durable but the affected components are not restocked — live-confirmed
  by a real process kill at that exact point, both storage modes, with a
  control test (no plugin code loaded at all) showing bare WooCommerce's
  own restock call has the identical gap natively. Surfaced for manual
  operator correction, not solved with a transaction, lock, journal or
  reconciliation sweep.
- **This plugin does not need to pass, and is not required to attempt,
  retry/duplicate-submission or concurrent-refund tests, by design** — it
  owns no refund creation, persistence or restocking step of its own for a
  crash, a retry, or a race to interrupt; those are WooCommerce's and the
  calling integration's responsibilities. The failure-injection and
  concurrency/atomicity/recovery-sweep guards formerly required here tested
  a custom orchestration design that is no longer part of the accepted
  scope — removed, not merely relaxed. See
  `docs/spikes/s1-e-refund-idempotency-recovery.md` and
  `docs/spikes/s1-f-refund-atomicity-and-locking.md` for why that design
  existed and was rejected, and
  `docs/spikes/s1-g-native-refund-line-linkage.md` for the accepted design's
  own evidence.

**Partial-failure handling**
- Partial component-reduction failure (a residual risk even with core
  doing the reduction, since core reduces each line independently, not
  atomically as a set): every component's actual outcome recorded, order
  in a controlled problem state, fulfillment `problem`, picking blocked,
  explicit operator recovery action available, no automatic compensation.

**Quantity edits**
- Quantity edit **before** reduction and intake succeeds; **after** either
  is blocked or forces reconciliation — the unmodified change-detector
  diff already produces this correctly (no rewrite needed under
  Architecture B).

**Fulfillment — parent-skip (Architecture B — S1-C/S1-D, executed)**
- Intake produces picking rows only for the real children, **never** the
  parent line — with the kit-marker skip guard applied.
- Saving a kit order in wp-admin does **not** spuriously flag `problem` —
  proven both without and with the skip guard applied.
- A later change to the kit product definition does **not** alter the
  diff outcome for an existing order (unchanged from Architecture A's
  requirement; still holds).
- **Guard works with the plugin fully deactivated**, reading only
  persisted snapshot/component order-item meta.
- Picking list shows component rows with component SKUs and distinct
  barcodes, keyed on the real, distinct `order_item_id` per component — no
  per-instance traceability when `kit_qty > 1` (accepted residual,
  unchanged from Architecture A).

**Cross-cutting exclusion contract (ADR-0007 — S1-D, executed)**
- Promotions: a product/category/quantity condition targeting a kit
  component must **not** fire for a kit-only cart with no genuine
  standalone purchase of that component; the same condition **must** still
  fire for a genuine standalone purchase; a sitewide/eligibility condition
  reading cart subtotal must still correctly count the parent kit's real
  price.
- Analytics: order-product-lookup rows for hidden children must show zero
  units-sold **and** zero gross revenue after the exclusion filter is
  applied (S1-D found gross revenue was polluted too, via allocated
  shipping, not only quantity) — verify against the real recurring
  Analytics batch action during deployment acceptance, since S1-D's
  disposable container could not reliably trigger that specific batch
  end-to-end.
- Cart block page: the hydration payload (server-rendered JSON embedded
  for client-side rehydration) must contain only the parent line — verify
  via direct inspection of the hydration response, not only the rendered
  page HTML (S1-D found the two are governed by different, non-overlapping
  filters).
- Any **new** cart/order-line consumer added to the ecosystem in the
  future (a different promotions engine, a subscriptions extension, a
  loyalty plugin) must be evaluated against this same question before
  being declared kit-compatible: does it iterate real line items in a way
  that could treat a hidden child as a genuine customer selection?

**Inactive-plugin safety (purchasability guard only — background stock-op deferral removed under Architecture B)**
- Intentional deactivation → every kit becomes non-purchasable before
  deactivation completes.
- Reactivation → kits stay locked until explicit admin unlock; composition
  revalidated first.
- Plugin files removed with no deactivation hook → MU-plugin guard blocks
  kit purchase.
- Plugin active but bootstrap fails (WooCommerce absent) → capability
  signal absent, MU-plugin blocks kit purchase.
- MU-plugin guard reads the kit-marker meta with **no plugin code loaded
  at all**.
- MU-plugin remains active while every ordinary plugin is deactivated.
- **Stale-health case:** the plugin initialises once and writes the
  persisted contract record; its files are then removed; a later request
  runs without the plugin → the guard **still blocks**, because the
  readiness hook did not fire this request.
- The readiness hook starts false every request and fires only after
  complete initialisation; a partially initialised plugin never emits it.
- Persisted contract record alone never permits a purchase.
- Promotions continues excluding kit products while the bundling plugin is
  inactive.

**Contract and rollout**
- **Checkout succeeds → the bundling plugin becomes unavailable →
  fulfillment intake runs (inline and via scheduled retry) → skip guard
  still correctly omits the parent, never one synthetic kit picking row.**
- Fulfillment plugin unavailable or incompatible → kits non-purchasable,
  admin explains why.
- Multiple parent kits for a hidden component → 404 unless a canonical kit
  is set.
- Campaign exclusion default holds for a newly created campaign; explicit
  kit opt-in works.

**Repository**
- Install / activate / upgrade / uninstall from the built ZIP; WP-CLI
  activation.
- Structural guards: WooCommerce confinement; no plugin-prefixed meta
  registered for REST.
- Vendored dependency manifest hashes match the tree.
- CI green across the PHP / WordPress / WooCommerce matrix.

---

## Open items

**Freeze blockers — none currently active. Refund design resolved by scope reset, not by further engineering.**

1. ~~S1-A/S1-B — Architecture A~~ **RESOLVED, PASS on its own terms — then
   superseded.** Both reported PASS (V12); Architecture B was selected
   instead after comparing the two (S1-C/S1-D, V13).
2. ~~S1-C — Architecture B core mechanism~~ **RESOLVED, PASS.** Reservation,
   reduction, restoration all delegated to WooCommerce core, unmodified,
   including with the plugin inactive after checkout.
3. ~~S1-D — Architecture B closure spike~~ **RESOLVED, PASS.** All 8
   required items closed with real, live-proven fixes (promotions,
   coupons, shipping, cart-block server-render, multicurrency, analytics,
   refunds, fulfillment parent-skip).
4. **ADR-0006 — ready for acceptance upon merge, narrowed.** Purchasability
   guard/capability handshake/deactivation-lock policy retained;
   custom problem-status ownership term removed (no longer load-bearing
   under Architecture B).
5. **ADR-0007 — new; ready for acceptance upon merge.** Cross-cutting
   cart/order-line exclusion contract; see the dedicated section above.
6. ~~S1-E/S1-F — custom refund idempotency/orchestration subsystem~~
   **RESOLVED BY DESCOPING, not by closing the reconciliation-sweep/
   transaction-scope questions S1-F raised (V16).** Both named windows
   were closed live by S1-F with strong evidence, but a third — a required,
   unbuilt reconciliation sweep, plus third-party hooks now running inside
   two database transactions — emerged in the process. Rather than build
   the sweep and settle the transaction-scope question, the product owner
   chose to descope the entire subsystem (V17, C25). **Current state:**
   spike S1-G answered the narrower question the descope left standing —
   whether this plugin can add derived component refund lines via
   WooCommerce's native refund flow alone — and **PASSED** (both
   order-storage modes, all required cases). ADR-0002's refund clause and
   ADR-0003 are accepted at that narrow scope; see the ADR register and the
   M1 Refunds section.

**Remaining, stated plainly — do not block the freeze, but must be
tracked into M1/fulfillment/promotions implementation**

6. A shipping method whose cost formula is keyed on cart-line quantity
   still double-counts hidden children — avoid that specific rate
   configuration, or implement the shipping-package filtering fix if a
   real deployment requires it.
7. The Analytics exclusion fix's automatic triggering via WooCommerce's
   real recurring Analytics batch action was not independently confirmed
   end-to-end in a short-lived disposable container — verify during
   deployment acceptance testing.
8. The literal, visible-HTML Blocks-Cart leak S1-C described was not
   reproduced in the exact WooCommerce/WordPress configuration used (the
   Cart block's server-rendered markup is genuinely empty in that
   configuration); the real underlying leak — the unfiltered hydration
   JSON — is proven and fixed regardless.
9. Store API checkout was verified with Cash on Delivery only; an
   asynchronous/redirect payment gateway was not exercised.
10. Coupon/fee exclusion beyond the specific product/category-restriction
    and free-shipping cases S1-D tested was not separately re-verified
    against every coupon type.
11. Third-party listener deduplication is no longer a concern for stock
    operations (no outbox under Architecture B). **Superseded (V17/C25):**
    this item used to track a concurrency gap in the now-rejected custom
    refund-orchestration guard; that guard does not exist in v1, so there
    is nothing of that shape left to test. What remains, by design (V17): a
    genuinely concurrent duplicate refund attempt is bounded only by
    WooCommerce's own remaining-refund-amount/quantity validation,
    unmodified and unaugmented by this plugin — the same protection (and
    the same limits on it) any ordinary product's refund has today. Not a
    gap this plugin needs to close.
12. The order-storage compatibility mode was not toggled on for every
    S1-C/S1-D test (S1-A/S1-B's own concerns were tested both ways;
    several of S1-D's fixes were not independently re-verified under that
    mode).

**Accepted for v1**

13. **Per-kit-instance traceability** does not exist when `kit_qty > 1`.
    Worth a product decision before scaling the kit range, since batch or
    serial tracking is plausible for some product categories. Unchanged
    by the Architecture A→B decision.

---

## Deferred

- Customer-configurable bundles, optional components, quantity choice.
- Refund of an individual component within a kit (refunding kit
  *quantity* is in M1).
- Per-component tax classes and price allocation.
- Variation components; nested bundles; subscriptions.
- Component-derived shipping weight/dimensions — M1 sets kit weight
  explicitly.
- A generic, registerable-condition seam in the promotions plugin.
- A shipping-package filtering fix for quantity-based flat-rate shipping
  methods (open item 6) — implement only if a real deployment configures
  that specific rate shape.
