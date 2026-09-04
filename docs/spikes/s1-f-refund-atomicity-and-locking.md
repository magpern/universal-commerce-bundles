# Spike S1-F — Refund atomicity and locking

**Scope:** close the two windows that spike S1-E did **not** close in the
refund-idempotency protocol — (1) a durable refund and restock existing with
no durable identity linking either to its operation id, and (2) the
`pending` record being treated as a concurrency lock when it is not one —
and prove the correction with live failure injection (real process kills)
and real multi-process concurrency, under **both** WooCommerce order-storage
modes.

## 1. Overall verdict: PASS

Every required test was executed live and passed. Both windows were first
**demonstrated to be real** under the superseded design (controls that fail),
then closed under the corrected design (the same tests pass).

Two findings from real source reading changed the design mid-spike and
invalidated the most obvious candidate fix. They are the most important
output of this spike:

- **The core refund-creation function durably persists the refund *before*
  the action it documents as firing "before save".** Hooking that action —
  the obvious fix for window 1 — would not have closed it.
- **Moving the identity marker earlier creates a third window** that neither
  the earlier spikes nor the original review named: once "identified" no
  longer implies "restocked", reconciliation that trusts the marker alone
  silently loses the restock.

Residual limitations that this spike does **not** eliminate are stated
plainly in §7. None of them is one of the two windows it was chartered to
close.

---

## 2. The two windows, stated precisely

### Window 1 — durable refund/restock with no durable identity

The superseded wrapper wrote the operation id onto the refund with a
**separate** save made **after** the core refund call had already returned.
By then the refund row, its line items and the restock were all durable. A
process death in that interval leaves a real refund and a real restock
carrying **no** identity. Recovery reads `pending`, queries for a refund
bearing the operation id, finds none, concludes "never completed", and
retries — a second refund and a second restock.

The earlier spike's "post-core" test began from a refund that *already
carried* the marker, so it proved reconciliation but never proved marker
durability. The window was real.

### Window 2 — `pending` is not a concurrency lock

The operation record is ordinary order meta, read then written with no
atomic claim. Two simultaneous requests both read "absent", both write
`pending`, both find no marked refund, and both call the core refund
function. The earlier claim that the `pending` write "stops two genuinely
concurrent attempts from both proceeding" was unproven, and is **false**.

---

## 3. Real source findings

Read directly from the pinned WooCommerce release installed in the test
container (the same version the earlier spikes in this series used).

### 3.1 The real internal ordering of the core refund-creation function

In sequence, within one call:

1. Construct an in-memory refund object; validate the amount against the
   order's remaining refundable amount.
2. Set the refund amount (which is stored as *meta*, not a column) and the
   reason; build and add the negative line items.
3. **Call the tax-update helper — which ends with a save of the refund
   object. This is the refund's CREATE.**
4. **Call the totals-calculation helper — which also ends with a save.**
5. Set the refund total.
6. **Fire the action documented as "adjust refund before save".**
7. Save the refund again; if that succeeds, optionally refund the payment
   through the gateway, then optionally restock.
8. Fire the partially-/fully-refunded actions (notification emails, and the
   parent order's status transition), save the order, then fire the
   refund-created and order-refunded actions.
9. On any exception, delete the refund and return an error.

**The decisive detail is steps 3 and 4: both total-recalculation helpers end
with `$this->save()`.** The refund row, its line items and its amount are
therefore already durable *before* the action at step 6. That action's
docblock is misleading — it is before the *final* save, not before creation.

This was not reasoned. It was caught by a failing test: an early build of the
corrected protocol attached the identity there and bracketed a transaction
there, and a process kill at that point still left a durable refund row with
the full amount, four line items, and **no** identity — only possible if the
row was already committed before the action ran.

### 3.2 The only seam that actually precedes the refund's durable creation

The abstract order class's `save()` fires a generic **before**-object-save
action, then calls the data store's `create()` (or `update()`), then saves the
line items, then fires the matching **after**-object-save action. The refund
class sets its own object type, so those hooks carry a refund-specific name.

The `before` hook receives the refund object *before* `create()`, so meta
attached there is written by that same creating save. That is the only seam
available.

### 3.3 Neither data store writes the row and its meta atomically

- **Legacy post storage:** insert the post row, then write the internal meta,
  then write the object's own meta — three separate autocommitted statements,
  no transaction.
- **Custom order tables:** persist the order row, then update the order meta,
  then save the object's meta — likewise no transaction.

So **no arbitrary meta key can be made atomic with the refund row by
attaching it alone**. The atomicity has to come from an explicit transaction.

### 3.4 Encoding the identity in the `reason` field does not work

Under legacy post storage the reason *is* part of the row insert. Under the
custom order tables it is **not a column at all** — both the reason and the
refund amount are listed as internal *meta* keys and written after the row.
The trick would be correct on one storage backend and silently wrong on the
other. It also overloads operator- and customer-visible text with machine
identity. Rejected on both counts.

### 3.5 The core restock function is neither idempotent nor atomic

Per item it performs a stock increase, then updates two bookkeeping meta
values, then saves the item — two separate durable steps per item, in a loop,
with no transaction. So bare core can leave a partially-restocked order, and
repeated calls with the same payload re-restock (already proven in an earlier
spike).

### 3.6 WordPress core's own lock pattern

WordPress core's upgrader takes an update lock with
`INSERT IGNORE INTO {options} (option_name, option_value, autoload) VALUES
(..., 'off') /* LOCK */`, relying on the `option_name` unique key to decide
the winner, and releases it by deleting the option. The claim itself is
genuinely atomic. Its **expiry-takeover path is not**: it deletes the expired
lock and then re-creates it, so two processes can both pass the expiry check.
This spike keeps the atomic claim and replaces the takeover with an atomic
compare-and-swap `UPDATE`.

---

## 4. Chosen protocol

Three independent mechanisms, each doing exactly one job.

### 4.1 Mutex — two layers, both atomic claims (window 2)

**Layer 1 — a database named lock**, taken with a zero timeout on entry and
released in a `finally`. It is owned by the *connection*, so it is released
the instant a holder's process dies — no expiry heuristic, and no takeover of
a live-but-slow holder. Its weakness is that it depends on the request
keeping one connection; a reconnect, or a proxy/replica layer that re-routes,
silently drops it.

**Layer 2 — a durable options-row lease**, WordPress core's own pattern,
hardened: claim with `INSERT IGNORE` and check rows-affected; if the claim
loses, read the current holder and refuse while its timestamp is still within
the expiry; only an *expired* lease may be taken over, and only by an atomic
compare-and-swap `UPDATE` that succeeds for exactly one taker.

Layer 2 covers exactly the case where layer 1 fails; layer 1 covers exactly
the case where layer 2 is weakest. A duplicate now requires **both** to fail
in the same instant. Both are released on the success path and the failure
path alike.

### 4.2 Identity — atomic with the refund's creation (window 1)

- Attach the operation id on the **before**-object-save action, gated to the
  *creating* save (the refund has no id yet), so the marker is written by the
  same `create()` that inserts the row.
- Bracket **exactly that one creating save** in a database transaction:
  `START TRANSACTION` in the `before` hook, `COMMIT` in the matching `after`
  hook. Row, line items and identity commit together or not at all.
- The bracket is deliberately narrow: it excludes the gateway refund call,
  the restock, the refunded-status transition and the notification emails,
  all of which happen later — so no external side effect is ever inside it.

### 4.3 Restock — atomic with its own completion record (the third window)

Because the identity now lands *before* the restock, "identified" no longer
implies "restocked". So:

- The core refund call is made with restocking **disabled**.
- This plugin then calls core's own restock function itself, inside a second
  transaction that also commits a per-refund restock-completion marker. The
  stock change, core's own per-item bookkeeping and the completion record
  become one atomic unit — which as a side effect also fixes core's
  partial-restock window from §3.5.

### 4.4 Reconciliation — unconditional, and before any decision to create

Under the mutex:

1. `completed` → reject as a duplicate (unchanged).
2. **Always** query the order's refunds for one carrying this operation id —
   *including when there is no local record at all*, which is the state a
   predecessor that died before its `pending` write leaves behind. If found:
   repair the refund's total if the predecessor died before it was written,
   ensure the restock (a no-op if already recorded), mark `completed`, return
   the existing refund id. **Never** create a second one.
3. Only if no refund carries the operation id does the protocol write
   `pending` (if absent) and call the core function.
4. A core-call failure leaves the record `pending` — never `completed` — so a
   corrected retry is still permitted.

The order-meta operation record is therefore demoted: it is an audit and
short-circuit projection, **not** the authoritative state and **not** a lock.

### 4.5 Candidates rejected

| Candidate | Rejected because |
|---|---|
| Hook the core "adjust refund before save" action to attach the identity | **Verified false premise.** The refund is already durable two steps earlier (§3.1). Live-proven: a process kill at that action left an unmarked, fully-formed refund row. |
| Encode the operation id in the refund `reason`, or any single insert column | Works on one storage backend only (§3.4); also overloads human-visible text. |
| Derive identity from data the refund already commits | No column of the refund row is unique per operation — amount, date and parent all collide across legitimate repeat refunds of the same order. |
| Derive restock completion from core's own cumulative per-item counters | A shop manager refunding through the admin increments the same counters, so the derivation is unsound on a real store. The per-refund completion marker is exact and storage-agnostic. |
| The `pending` record as the lock (the superseded claim) | **Live-disproven: 10/10 and 5/5 violations.** Not a claim needing hardening — a claim that is false. |
| A plugin-owned table with a unique key on the operation id | Would be atomic, but the options table already provides exactly the same unique-key claim with no new table. The architecture deliberately eliminated the plugin-owned operations table; reintroducing one buys nothing. |
| `SELECT ... FOR UPDATE` on the order row | Requires the whole critical section in one transaction, which is mutually exclusive with the two narrow brackets (no nested transactions) and would hold a row lock across emails, the status transition and, in production, the gateway refund call. |
| One transaction wrapping the entire core refund call | Would put an external gateway refund and notification emails inside a database transaction. A committed gateway refund with a rolled-back database is strictly worse than the problem being solved. |

---

## 5. Test environment and isolation

- Disposable WordPress + WooCommerce + MariaDB containers on a fresh, isolated
  bridge network, image tags matching the rest of this spike series; removed
  at the end of the session.
- Both storage modes exercised: legacy post storage **and** the custom order
  tables (sync disabled, order tables authoritative). All relevant tables
  confirmed to be on a transactional storage engine.
- Fixture: one unmanaged simple kit product plus three managed components
  (stock 50 each) — the generic shape used throughout this documentation.
  Orders of 2 kits, refunding 1, chosen deliberately so that a duplicate
  refund is **not** masked by WooCommerce's own remaining-amount validation.
- **Crash injection:** a real, uncatchable process kill (signal 9) sent by the
  process to itself at a chosen point. Verified genuinely fatal — the probe
  run exited with status 137. Each invocation is its own process and its own
  database connection, so this is a true process/connection boundary, not a
  thrown exception.
- **Concurrency:** N separate process invocations per iteration, each with its
  own runtime and database connection, released together by a shared
  wall-clock barrier (each busy-waits until a common start timestamp) — not
  sequential calls inside one process.
- **Isolation:** the containers mount only their own anonymous
  Docker-managed volumes (no bind mount to any host path), sit on their own
  bridge network, publish no ports, and are referenced by no real deployment
  configuration. The container inventory before and after the session was
  byte-identical; the disposable containers and network were removed and the
  removal verified.

---

## 6. Test results

### 6.0 Controls — both windows are real (these must fail, and do)

**Window 1, superseded design, legacy post storage** — process killed after
the core refund call returned and before the identity save. Observed
immediately after the kill: a real refund row with the full amount and four
line items, `op_id` **empty**, and the restock already applied (components
48 → 49, core's own restock tally at 1). Recovery in a fresh process then
found no marked refund and created a **second** one:

```
after crash:    refund rows = 1, op_id = "", total refunded = 15, stock 49/49/49
after recovery: refund rows = 2, total refunded = 30, stock 50/50/50, restock tally = 2
```

Two refunds, twice the refunded amount, double restock. Window 1 confirmed.

**Window 2, superseded design, legacy post storage**, 10 iterations × 2
concurrent processes:

```
iter 1  refund_rows=2 stock=50 total_refunded=30 -> VIOLATION
...
iter 10 refund_rows=2 stock=50 total_refunded=30 -> VIOLATION
SUMMARY iterations=10 racers=2 ok=0 violations=10
```

**10 out of 10.** Not a rare race — it loses essentially every time.

**Window 2, superseded design, custom order tables**, 5 iterations × 4
concurrent processes:

```
iter 1 stock_before_order=50 refund_rows=4 stock_after=52 (expect 49) total_refunded=60 -> VIOLATION
...
SUMMARY iterations=5 racers=4 ok=0 violations=5
```

With four racers every attempt wins: four refunds, four times the refunded
amount, and stock inflated to **52 — two units above where it started** —
because all four processes read the same pre-decrement bookkeeping value
before any of them wrote. Stock is not merely double-counted, it is
manufactured.

**The residual sub-window without a transaction** — process killed between
the refund row insert and the rest of its save, superseded design: an orphan
zero-amount, zero-item refund row survives, and recovery adds a second row
beside it. This is precisely what the creating-save transaction eliminates.

### 6.1 Two (and four) simultaneous identical refund requests: PASS

Separate processes, wall-clock barrier, repeated. Requirement: exactly one
refund created, stock restocked exactly once.

| Storage mode | Iterations | Concurrent processes | Result |
|---|---|---|---|
| legacy posts (lease layer only) | 12 | 4 | **12 OK / 0 violations** |
| custom order tables (lease layer only) | 15 | 4 | **15 OK / 0 violations** |
| custom order tables (both lock layers) | 20 | 4 | **20 OK / 0 violations** |
| legacy posts (both lock layers) | 12 | 4 | **12 OK / 0 violations** |

**Total: 59 iterations at four concurrent processes, across both storage
modes, zero violations.** Every iteration produced exactly one refund row,
exactly one restock, and the correct refunded amount; exactly one process
returned a refund id and the rest were refused with a "busy" error rather
than silently duplicating.

The named-lock layer was separately verified to actually engage rather than
silently no-op: while a holder slept, a second process was refused and the
lock's owning connection id matched the holder's; once the holder's process
exited, the lock was immediately free again.

### 6.2 Crash after core refund creation, before the identity is durable: PASS

**Structurally:** the identity is attached before the data store's `create()`
and that whole creating save is inside a transaction, so the instant the
refund row becomes durable is the instant its identity becomes durable — the
same commit.

**Confirmed by injected kill anyway**, at three distinct points inside that
window (immediately after the row insert; immediately before the identity
meta row is written; and after the full save but before the commit). In every
case, immediately after the kill:

```
refund rows = 0, refunds = [], total refunded = 0
```

The row insert itself rolled back. Compare the control at the identical
point, which left an orphan row behind. The same holds under the custom order
tables (a kill anywhere inside the creating save leaves zero refund rows).

**Invariant scan** across every refund row produced by the final suite:

```
refund_rows_total=22 refund_rows_without_op_id=0
```

No durable refund row without an identity was ever observed.

### 6.3 Recovery completes the operation exactly once after every interruption: PASS

Eleven injection points, each: fresh order → process kill → observe → recover
in fresh processes → observe. Pass criterion in every case: exactly one refund
row carrying its operation id and its line items, all three components
restocked exactly once, correct refunded amount.

| Injection point | What is durable at the kill | Result |
|---|---|---|
| before the core call (after the `pending` write) | `pending` only, 0 refunds | PASS (both modes) |
| inside the creating transaction, after the row insert | nothing (rolled back) | PASS |
| inside the creating transaction, before the identity meta row | nothing (rolled back) | PASS |
| inside the creating transaction, before the save | nothing | PASS (both modes) |
| inside the creating transaction, after the save, before commit | nothing | PASS (both modes) |
| creating transaction committed, restock not started | refund + identity, **no** restock | PASS (both modes) |
| core call returned, restock not started | refund + identity, no restock | PASS (both modes) |
| inside the restock transaction, before the restock | refund + identity | PASS (both modes) |
| inside the restock transaction, **after** the stock update | stock change **rolled back** | PASS (both modes) |
| inside the restock transaction, after the completion marker | rolled back | PASS (both modes) |
| restock transaction committed, `completed` not yet written | refund + identity + restock | PASS (both modes) |

Two deserve calling out.

**Identity durable, restock not** — the third window. Under a naive
"identity found → mark completed" reconciliation this loses the restock
silently. Observed after the kill: the refund exists, carries its operation
id, has no restock-completion marker, and the components are still at their
reduced level with core's restock tally empty. Recovery **reused the same
refund**, restocked exactly once, and created nothing new.

**A stock change rolled back after it had already executed** — killed inside
the restock transaction after the stock increase had run. Immediately after
the kill the components were still at their reduced level: the increment was
undone by the connection dying. This is the same forced-disconnect rollback
property an earlier spike established, now applied to the restock.

**Lease expiry and the crashed holder.** After killing a process that held
the lease: the lease row remains; an immediate retry configured with a long
expiry is correctly **refused** rather than proceeding; a retry once the
lease has expired takes it over atomically and completes the operation
against the existing refund; and the lease row is gone afterwards. Across
every test in this spike, the final state showed zero leftover lease rows —
they are released on the success path and the failure path alike.

### 6.4 Earlier regressions still hold: PASS (both storage modes)

- **Before-core interruption still retries successfully** — first row of the
  table in §6.3.
- **A genuine core-call failure still permits a corrected retry.** A call with
  an impossible amount is rejected by WooCommerce's own validation, leaving
  the record `pending`, zero refunds, and stock unchanged. A corrected retry
  with the same operation id then succeeds: one refund carrying its identity
  and its restock marker, stock restocked once, record `completed`.
- **A true duplicate after `completed` is still rejected** — duplicate error
  returned, refund count and stock unchanged.
- **A genuinely distinct operation id still proceeds** — the second kit unit
  refunds normally: two refund rows, twice the refunded amount, stock
  restored by the second unit's worth, both records `completed`.

---

## 7. What this does not close

1. **The lease expiry is a heuristic, and the named lock is a
   connection-identity assumption.** If a holder stalls past its expiry
   *and* its named lock has been lost (a reconnect, or a database proxy that
   does not preserve the connection), a second process can take over while the
   first is still alive and mid-refund; the taker cannot see the
   predecessor's uncommitted transaction and would create a second refund.
   Both layers must fail together, but this is a real bound, not zero. It was
   not reproduced here — both layers held in all 59 concurrent iterations —
   and it cannot be eliminated by any expiring lock.
2. **Recovery is required, and is currently only triggered by a retry
   carrying the same operation id.** A crash between the creating
   transaction's commit and the point where the refund's total is set leaves
   the refund durable and correctly identified but with its total column
   unwritten — under the custom order tables the order's refunded total then
   reads as zero until something repairs it. The reconciliation path repairs
   it, and did in every test, **but only because a retry ran**. A periodic
   sweep over `pending` records is therefore a design requirement, not an
   optional extra. It was not built or tested in this spike.
3. **Refunds created outside this plugin's orchestration are outside the
   protocol** — a shop manager refunding through the WooCommerce admin with
   restock enabled carries no operation id and is not attributed.
4. **The two transactions put third-party code inside a database
   transaction.** Anything hooked on the refund's creating save, or on the
   per-item restock actions, now runs inside one. Any such code issuing DDL
   would implicitly commit and break the bracket. Nothing else was loaded in
   the test environment, so this was not exercised.
5. **Nested-transaction hazard.** The database has no nested transactions: if
   a caller already has one open, starting another implicitly commits it. A
   production implementation must detect that and skip its own bracket, or
   refuse. The spike wrapper does not.
6. **Non-transactional storage engines were not tested.** The brackets assume
   transactional tables; every relevant table was on a transactional engine
   here. On a non-transactional engine the protocol degrades to the
   superseded design's residual.
7. **A database configuration where the named lock is unavailable or
   differently scoped was not tested.** The options lease is the intended
   fallback, and it alone was proven sufficient over 27 concurrent iterations
   before the named lock was added — so the fallback is evidenced, but not on
   such a configuration.
8. **Gateway refunds, the refunded-status transition and notification emails
   were not exercised** (payment refunding disabled, mail short-circuited).
   They are deliberately outside both transaction brackets, but their
   interaction with a crash mid-sequence is untested.

---

## 8. Corrected contract

- **Operation record (order meta):** an audit and short-circuit projection
  only. Not the authoritative state, and **not a lock**.
- **Operation id (meta on the real refund object):** attached on the refund's
  *creating* save, so it commits in the same transaction as the refund row
  and its line items. The durable, authoritative identity.
- **Restock-completion marker (meta on the real refund object):** committed
  in the same transaction as the restock itself.
- **Operation lease (options row):** the durable half of the two-layer lock —
  an `INSERT IGNORE` claim with a timestamp, an expiry, and an atomic
  compare-and-swap takeover; deleted on both the success and the failure path.

See [`../adr/0003-versioned-cart-order-snapshot-contract.md`](../adr/0003-versioned-cart-order-snapshot-contract.md)
for the full corrected contract,
[`../adr/0002-component-availability-reservation-reduction-and-restoration-lifecycle.md`](../adr/0002-component-availability-reservation-reduction-and-restoration-lifecycle.md)
for its place in the stock lifecycle, and
[`s1-e-refund-idempotency-recovery.md`](s1-e-refund-idempotency-recovery.md)
for the superseded design and the visible correction note pointing here.
