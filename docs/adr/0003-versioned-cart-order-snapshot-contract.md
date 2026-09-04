# ADR-0003: Versioned cart/order snapshot contract

## Status

**Proposed — NOT accepted.** The snapshot and per-child line contract
(`_ucb_kit`, `_ucb_parent_item_id`, `_ucb_component`, `_ucb_snapshot_version`,
`_ucb_position`) is settled and proven. The **refund-operation portion**
(`_ucb_refund_ops`, `_ucb_refund_op_id`) is not yet accepted:

- Spike S1-F closed the two windows S1-E left open (identity durability, and the
  absence of an atomic claim) with live evidence, including 59 concurrent
  iterations against a control that fails.
- It also established that a **periodic reconciliation sweep over `pending`
  records is a design requirement, not an optional extra** — and that sweep was
  neither built nor tested. Without it, a crash in a narrow window after the
  refund is created but before its total is written leaves a durable refund
  whose total reads as `-0`, repaired only if a retry with the same operation
  ID happens to run.
- The protocol also now brackets third-party hook code inside two database
  transactions, which is in tension with the principle established elsewhere in
  this design that arbitrary hooks must not run inside an open transaction.

This ADR is accepted only once the sweep is specified and proven, and the
transaction-scope question is decided.

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

### Refund-operation idempotency guard (closes a real WooCommerce core
defect; corrected twice after review findings — see "Corrections" below)

A refund operation id is derived from a stable hash of the order item,
refund, and component identity, so that a genuinely different, second
partial refund produces a different id, while a retried delivery of the
*same* refund produces the *same* id.

Three separate mechanisms, each doing exactly one job. Order meta
`_ucb_refund_ops` is an **audit and short-circuit projection only** — it
is neither the authoritative state nor a lock.

**1. Mutual exclusion — an atomic claim, in two layers.**

- *Layer 1:* a MySQL named lock (`GET_LOCK(<name>, 0)`), owned by the
  database connection, so a crashed holder releases it the instant its
  process dies and a live-but-slow holder is never displaced.
- *Layer 2:* a durable lease row claimed with
  `INSERT IGNORE INTO {options} (option_name, option_value, autoload)
  VALUES (..., 'off') /* LOCK */` followed by a rows-affected check — the
  same update-lock pattern WordPress core itself uses — carrying a
  timestamp and an expiry. Unlike core's version, the expired-lease
  takeover is an **atomic compare-and-swap `UPDATE`** (set the new value
  only where the old value still matches), not core's non-atomic
  delete-then-reinsert.

Both are released on the success path and the failure path alike. Layer 2
covers the case where connection identity is not preserved (a reconnect,
or a proxy/replica layer); layer 1 covers the case where a holder outlives
its lease expiry.

**2. Identity, atomic with the refund's creation.** The operation id is
attached to the refund object on the **object-save action that fires
before the data store creates it**, gated to the creating save, and that
one save is bracketed in a database transaction. The refund row, its line
items and its operation-id meta therefore become durable in the same
commit — there is no instant at which a durable refund exists without a
queryable identity.

> The action documented in WooCommerce as adjusting the refund "before
> save" is **not** usable for this. Verified against the current release
> source: the core refund-creation function already persists the refund
> inside its two total-recalculation helpers (each of which ends with a
> save of its own) *before* that action fires. Attaching the identity
> there was tried and failed a live crash test.

**3. Restock, atomic with its own completion record.** The core refund
call is made with restocking **disabled**, and core's own restock function
is then invoked by this plugin inside a second transaction that also
commits a per-refund restock-completion marker. This is required because
moving the identity marker earlier means "identified" no longer implies
"restocked"; it additionally makes core's own per-item restock
bookkeeping — which is a stock update and a separate meta write per item,
in a loop, with no transaction — all-or-nothing.

Both transactions are deliberately narrow. The gateway refund call, the
refunded-status transition and the notification emails all fall **outside**
them.

**4. Reconciliation runs unconditionally, under the lock, before any
decision to create** — including when no local record exists at all, which
is exactly the state a predecessor that died before writing one leaves
behind.

- `completed` → reject the retry as an exact duplicate, with no stock
  change.
- Otherwise, query the order's real refunds for one carrying this
  operation id in its own meta. **Found** → repair the refund's total if
  the predecessor died before it was written, ensure the restock (a no-op
  if already recorded), mark `completed`, and return the existing refund.
  Never create a second one. **Not found** → write `pending` if absent and
  call the core function.
- A retry that itself fails leaves the record `pending`, never
  `completed`, so a subsequently corrected retry remains possible.

**Operational requirement:** a periodic reconciliation sweep over
`pending` records is part of this contract, not an optional extra — an
operation interrupted by a crash is otherwise only healed by a later retry
that happens to carry the same operation id.

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
- **The guard needs two narrow database transactions.** This supersedes the
  earlier claim that none was needed. The durable refund object is still
  the authoritative record, but making its identity and its restock durable
  *together with it* cannot be done by attaching meta alone, because
  neither order data store writes a refund's row and its meta in one
  transaction. Both brackets are deliberately narrow, and neither contains
  an external side effect. Two operational consequences follow: third-party
  code hooked on the refund's creating save or on the per-item restock
  actions now runs inside a transaction, and an implementation must detect
  a transaction already opened by its caller rather than silently
  committing it by starting its own.
- **A periodic reconciliation sweep over `pending` records is part of the
  contract.** Without it, an operation interrupted by a crash is only
  healed if some later retry happens to carry the same operation id.

> **Correction, found by review, then closed by live execution.** The
> refund-operation guard first documented here recorded a single
> write-before-call "applied" flag, not a state machine: the operation id
> was marked applied as soon as it was written, before the core refund
> call was even attempted. This repeats the intent-then-mutate ordering
> already rejected elsewhere in this plan for stock mutation — an
> interruption (crash, or a genuine core-call failure) between writing the
> flag and the refund actually completing would leave the flag set with no
> real refund having happened, permanently blocking a legitimate retry. A
> follow-up spike (S1-E) reproduced this exact window live and closed it
> with the `pending`→`completed` state machine and refund-level
> `_ucb_refund_op_id` reconciliation documented above — this ADR's
> "Decision" section already reflects the corrected design; this note
> records that the design changed, and why, rather than silently
> rewriting the original text out of history. See
> `docs/spikes/s1-e-refund-idempotency-recovery.md`.

> **Second correction, found by review, then closed by live execution.**
> The state-machine design recorded in the note above still left two real
> windows open, and both have now been reproduced live and closed by a
> follow-up spike (S1-F). Preserving what was previously claimed here:
>
> 1. *The identity marker was not durable when the refund was.* The
>    operation id was written onto the refund by a **separate** save made
>    **after** the core refund call had already returned — that is, after
>    the refund row, its line items and the restock were all durable. A
>    process killed in that interval left a real refund and a real restock
>    carrying no identity at all; recovery saw `pending`, found no marked
>    refund, concluded "never completed", and created a **second** refund
>    with a **second** restock. Live-proven: after one injected process
>    kill, the order ended with two refund rows, twice the refunded amount,
>    and stock restocked twice.
> 2. *The `pending` record was claimed to stop two concurrent attempts. It
>    does not, and never did.* It is ordinary order meta, read then written
>    with no atomic claim: two concurrent requests both read "absent", both
>    write `pending`, both find no marked refund, and both proceed.
>    Live-disproven — **10 out of 10** iterations with two genuinely
>    concurrent processes produced two refunds and a double restock, and
>    with four processes **5 out of 5** produced four refunds and inflated
>    stock *above* its starting level.
>
> The "Decision" section above now records the corrected protocol: an
> atomic two-layer lock, identity made durable in the same commit as the
> refund's creation, the restock made atomic with its own completion
> record, and unconditional reconciliation before any decision to create.
> See `docs/spikes/s1-f-refund-atomicity-and-locking.md`.

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
- **A single write-before-call "applied" flag for the refund-operation
  guard** (the original version of this ADR). Rejected — found by review
  to have an unrecoverable failure window (see "Correction" note above);
  replaced by the `pending`→`completed` state machine reconciled against
  the real refund object's own meta.
- **Treating the `pending` record as a concurrency guard** (the second
  version of this ADR). Rejected — it is ordinary order meta with no
  atomic claim, and was live-disproven at 10/10 and 5/5 violation rates.
  Replaced by the two-layer atomic lock.
- **Attaching the operation id on the action WooCommerce documents as
  firing "before save"** on the refund. Rejected — verified against the
  current release source, and confirmed by an injected process kill, that
  the refund is already durably persisted before that action runs.
  Replaced by the object-save action that fires before the data store's
  create, bracketed in a transaction.
- **Encoding the operation id inside the refund's `reason` text** so that
  identity would be part of the row insert by construction. Rejected —
  it is a real column only under legacy post storage; under the custom
  order tables the reason (and the refund amount) are meta written after
  the row, so the trick would be correct on one storage backend and
  silently wrong on the other. It also overloads operator- and
  customer-visible text with machine identity.
- **Deriving restock completion from WooCommerce's own cumulative
  per-item restock counters**, instead of a per-refund completion marker.
  Rejected — a shop manager refunding through the WooCommerce admin
  increments the same counters, so the derivation is unsound on a real
  store.
- **A plugin-owned table with a unique key on the operation id** as the
  lock. Rejected — the options table already provides exactly the same
  unique-key atomic claim, and ADR-0002 deliberately eliminated the
  plugin-owned operations table; reintroducing one buys nothing here.
- **`SELECT ... FOR UPDATE` on the order row**, or wrapping the entire
  core refund call in one transaction. Rejected — both require the whole
  critical section inside a single transaction, which is mutually
  exclusive with the two narrow brackets above (the database has no
  nested transactions) and would hold locks across the notification
  emails, the status transition and, in production, the payment-gateway
  refund call. A committed gateway refund with a rolled-back database is
  strictly worse than the problem being solved.
- **Observing WooCommerce's "can restock refunded items" filter** for the
  refund-idempotency side effect. Withdrawn — that filter runs only on the
  restock code path and would not fire for a kit's own (unmanaged) parent
  line; the real, documented `woocommerce_refund_created` action is used
  instead.
