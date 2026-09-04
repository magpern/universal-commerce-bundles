# S1-A / S1-B Post-Spike Runtime Verification

**Scope:** mandatory runtime (not static) verification of S1-A and S1-B's
conclusions, plus the custom-status and transaction-rollback items.
**Environment:** disposable WordPress 7.0.2 + WooCommerce 11.0.1 + MariaDB
11.8.8 in throwaway Docker containers, fully isolated from any live stack.
All resources removed at the end of the session.

This spike is part of Architecture A (see `docs/adr/0002-...md`), which was
independently proven viable on its own terms and then set aside in favour
of Architecture B. It is preserved as evidence for a rejected alternative.

## Overall outcome: PASS

All four mandatory items hold after live execution. One real design defect
was found and corrected in S1-B's restoration-suppression mechanism during
this verification pass (the corrected mechanism was then itself proven
live — see `s1-b-crash-safe-stock-mutation.md`). ADR-0002 integration
proceeded on the corrected text.

---

## 1. Classic + Store API reservation integration (S1-A) — PASS

A real disposable WordPress+WooCommerce install; a proof-of-concept
must-use plugin implementing exactly S1-A's recommended design (opt-out via
the hold-duration filter + a dedicated aggregated reservation write to the
real reservation table).

### Opt-out fires only for kit orders; non-kit order untouched

```
$ wp eval-file test-classic-checkout.php --allow-root
KIT ORDER ID=16
[...] opting order 16 out of core reservation
[...] reserved order=16 managed_id=13 qty=1 result=1
---- reserved stock (after kit order created action) ----
{"order_id":"16","product_id":"13","stock_quantity":"1","expires":"...+10min"}
NONKIT ORDER ID=17
[...] order 17 has no kit line, skipping the plugin's reservation (core handles it)
---- reserved stock (after non-kit order created action) ----
{"order_id":"16", ...}
{"order_id":"17","product_id":"15","stock_quantity":"1","expires":"...+60min"}
```

Order 17's expiry is +60min (core's own default hold), order 16's is
+10min (the plugin's own PoC window) — proof core reserved order 17
itself, untouched by the opt-out, which only applied to order 16.

### Sole writer for the full item set / combined standalone+kit demand summed

```
$ wp eval-file test-summation.php --allow-root
SUMMATION ORDER ID=18
[...] reserved order=18 managed_id=13 qty=3
---- reserved stock (after summation order created) ----
{"order_id":"18","product_id":"13","stock_quantity":"3", ...}
```

A component ordered standalone (qty 2) **and** via the kit (qty 1) in the
same order produced **one** row, `qty=3` — not two rows, not a
silently-overwritten `qty=1`.

### Hook ordering — real request lifecycle, both paths

- Classic: the order-created action fires core's own reservation callback
  (priority 10, no-ops due to opt-out) then the plugin's own pass
  (priority 20) — confirmed by log ordering.
- Store API: a **real HTTP request**, not simulated:
```
$ curl -sD h1 cart; curl -sD h2 -X POST cart/add-item id=14; curl -sD h3 -X POST checkout ...
HTTP/1.1 200 OK
{"order_id":26,"status":"processing", ...}
```
The real request-scoped log confirms the hook ordering exactly as
claimed — the hold-duration filter fired and opted order 26 out, **then**
the order-processed action fired the plugin's reservation pass, which
reserved the component.

### The Store API exception-type distinction — resolved by real request

- WooCommerce's typed Store API route exception → clean `400`:
```
POST .../checkout → HTTP/1.1 400 Bad Request
{"code":"ucb_poc_test_exception","message":"deliberate exception ...","data":{"status":400}}
```
- A plain, bare exception (genuine insufficient-stock case) → `500`, not
  clean:
```
POST .../checkout → HTTP/1.1 500 Internal Server Error
{"code":"woocommerce_rest_unknown_server_error","message":"insufficient stock: ...","data":{"status":500}}
```
Both left the draft order `pending`, no orphaned reservation row.
**Conclusion: the seam works, conditional on throwing the typed exception,
not a bare one** — a genuine, execution-only-discoverable requirement.

### Insufficient stock — no orphaned order/reservation

```
INSUFFICIENT ORDER ID=19
[...] reserved order=19 managed_id=13 qty=2 result=0
[...] insufficient stock for order=19, rolled back, throwing
threw=true
---- after insufficient attempt (expect NO row for order 19) ----
(empty)
order status after failed reservation: pending
```

### Retried failed checkout is idempotent

Tested faithfully as **two separate processes** (a real retry is a new
HTTP request = a new process, not a second call inside one script):

```
$ wp eval-file test-retry-p1.php --allow-root      # process 1: reserve
RETRY2 ORDER ID=25 ... result=1
$ wp eval-file test-retry-p2.php --allow-root      # process 2: retry
PROCESS2 retrying order 25 ... result=2
$ wp eval-file test-retry-p2.php --allow-root      # process 3: retry again
PROCESS2 retrying order 25 ... result=2
```

Quantity stayed `1` across all three attempts, single row throughout.
PASS.

### New finding: core's own release step cleans up the plugin's rows for free

Core's own reservation-release function deletes by order id alone (doesn't
check who wrote the row), and stays registered at priority 11 on the
standard completing/cancelling actions **regardless of the opt-out**
(which only gates core's *reservation*, never its *release*). Live-
confirmed: order 26's reservation was gone once it reached `processing`,
with no explicit plugin release call. This is a genuine simplification —
an explicit release call is only needed for the one Store API failure path
core's own release set doesn't cover.

### Backorder/non-managed skip

Not re-tested live (pure PHP control-flow, already exercised by
WooCommerce's own test suite).

---

## 2. Restoration suppression scoping (S1-B) — PASS, after a correction

Real disposable WordPress install, a proof-of-concept must-use plugin
using the **actual** WordPress hook-dispatcher class via genuine action
and status-transition calls — not a standalone snippet mimicking dispatch.

### First implementation attempt: FAILED (a real defect, not a caveat)

A first proof-of-concept — removing the callback at priority 5, re-adding
it via `try { ... } finally { ... }` **in the same priority-5 callback** —
produced **zero suppression**, 100% of the time:

```
SUPPRESSING core restoration for order 39 on the cancellation status action
RE-REGISTERED core restoration on the cancellation status action (finally block) for order 39
CORE RESTORATION ACTUALLY RAN for order 39
RESULT: core restoration ran for KIT order 39? true (expect FALSE - suppressed)
```

Root cause: the `finally` block executes synchronously, before the hook
dispatcher's loop advances past priority 5 — so the callback is back in
place before priority 10 is ever reached in the same dispatch. This
directly contradicted the design as first written; **withdrawn and
corrected**, not softened into a caveat.

### Corrected mechanism: proven live, all 8 required properties hold

Full mechanism and re-run output are recorded in
`s1-b-crash-safe-stock-mutation.md`. Also confirmed here: the "reduced
stock" flag on the suppressed kit order (39) remained `true` after the
cancellation attempt (not falsely cleared) — matching the deferral
semantics: still eligible for a later, correct restoration.

Re-run with the order-storage compatibility mode toggled on: identical
results, all 7 numbered properties (of the 8 total, one being the
HPOS-toggle re-run itself) hold.

---

## 3. Custom problem-status verification — PASS

A proof-of-concept must-use plugin registers the custom order status via
core's own status-registration APIs — **no reference to any
plugin-specific class, constant, or autoloader.**

```
$ wp eval-file test-status.php --allow-root   (order-storage compatibility mode OFF)
registered custom post status: true
wc_get_order_statuses() includes it: true
custom order-storage mode enabled: false
stock before transition to custom status: 491
order status after transition: custom-status
stock after transition to custom status: 491 (expect UNCHANGED)
reduction action fired during transition: false
restoration action fired during transition: false
any callbacks registered on the custom status's own reduction hook: false
any callbacks registered on the custom status's own restoration hook: false
bulk action list after filter: {"mark_processing":"x","mark_on-hold":"z"}   (no mark-to-custom-status key)
DONE
```

Then re-run with the order-storage compatibility mode actually toggled
on:

```
custom order-storage mode enabled: true
stock after transition to custom status: 490 (expect UNCHANGED from before: 490)
reduction action fired during transition: false
restoration action fired during transition: false
[... all other lines identical to the mode-off run ...]
```

**Findings, all live-confirmed, not reasoned from source alone:**
- Registers with zero plugin-specific code dependency.
- Compatible with the alternate order-storage mode: confirmed by actually
  toggling it on this install and re-running the full test — identical
  results.
- Transitioning into the custom status does **not** fire either the
  reduction or restoration action — confirmed both by action-count delta
  (0 in both cases) and directly by checking whether anything is
  registered on the custom status's own hook name (`false` — core simply
  never binds anything to a status name it doesn't know).
- Admin visibility: the status list includes the new status (shows in the
  order list/filters); it was explicitly excluded from the bulk
  "Change status to…" dropdown filter output, so it is not offered as a
  casual workflow choice.
- Non-recursion: transitioning FROM a stock-reduced status TO the custom
  status left stock unchanged and fired neither reduction nor restoration
  action — confirmed via real stock-value and action-count deltas, not
  merely absence of an error.

---

## 4. Transaction/rollback guarantees — reconfirmation — PASS

Re-run once against a **fresh** disposable MariaDB instance (a genuinely
new container — the original spike's own database container no longer
existed), per the "reconfirm, don't re-derive" instruction.

### Crash between mutation and durable record (KILL mid-transaction)

```
# victim: START TRANSACTION; UPDATE stock_tbl SET stock=stock-4 WHERE post_id=2001; SELECT SLEEP(20); (never reaches INSERT/COMMIT)
# killer (separate process): SET @vid=(SELECT conn_id FROM conn_registry WHERE label='crash_test_1'); KILL @vid;
ERROR 2026 (HY000): TLS/SSL error: unexpected eof while reading
post_id | stock  →  10   (UNCHANGED)
op_rows →  0
```
**PASS, reconfirmed** — matches the original finding exactly.

### Naive duplicate-key handling (no explicit rollback) — reconfirmed unsafe

```
$ mariadb --force -uroot ... < naive.sql
ERROR 1062 (23000): Duplicate entry 'dupe-reconfirm2' for key 'PRIMARY'
(script continues past the error to COMMIT, exit 0 — the faithful analogue
 of a typical PHP database wrapper, which does not abort execution on a
 query error by default)
post_id | stock  →  6   (should have stayed 10 — silently double-applied)
op_rows →  1
```
**PASS, reconfirmed** — same dangerous silent double-apply as the original
finding.

### Correct explicit-rollback handling

```
$ mariadb --force -uroot ... < correct1.sql   # real first attempt
exit 0
$ mariadb --force -uroot ... < correct2.sql   # replay, same op_id, ends in explicit ROLLBACK
ERROR 1062 (23000): Duplicate entry 'correct-op-1' for key 'PRIMARY'
exit 0
post_id | stock  →  6   (unchanged from the first attempt — replay's UPDATE was rolled back)
op_rows →  1
```
**PASS, reconfirmed** — matches the original finding exactly.

---

## 5. Compatibility facts

- **Minimum supported (unchanged by this verification):** PHP 8.1,
  WordPress 6.5, WooCommerce 8.2.
- **Reference validation target (co-resident with, not "tested to"):**
  WordPress 7.0.2, PHP 8.4, MariaDB 11.8.8, WooCommerce **11.0.1** —
  confirmed live inside the reference environment.
- **Intended CI matrix (proposal, not yet built):** PHP 8.1 (floor) ×
  WP 6.5/WC 8.2 (floor) — PHP 8.4 × WP 7.0.2/WC 11.0.1 (reference-
  exercised) — PHP 8.4 × latest WP/WC at time of writing. Three cells, not
  a full cross-product, to keep CI time bounded.
- **Versions ACTUALLY exercised in a running container, this verification
  pass:** WordPress 7.0.2, PHP 8.4.24, WooCommerce 11.0.1 (the real plugin
  tree, copied read-only, not a fresh install), MariaDB 11.8.8. **Versions
  actually exercised in the prior spike:** MariaDB 11.8.8 only — a bare
  schema/table proof-of-concept, no WordPress or WooCommerce process was
  ever started.

---

## 6. Proof disposable resources never touched a live stack

All resources created for this verification pass — a disposable WordPress
container, a disposable database container, and their own private network —
were newly created for this session, attached only to their own network, no
host ports published, and volume mounts were fresh named volumes, not bind
mounts of anything from a live deployment. WooCommerce was copied into the
disposable container via a read-only source copy, never written to. All
disposable resources were removed at the end of the session, and a
before/after listing of running containers on the host confirmed the set
was identical, proving nothing about a live deployment was touched.

---

## 7. Corrections made — summary

| File | What changed |
|---|---|
| `s1-b-crash-safe-stock-mutation.md` | **Correction (not caveat):** the restoration-suppression "re-add after dispatch" instruction, read the natural way (synchronous `finally` re-add inside the same priority-5 callback), was proven to produce **zero suppression**. Corrected mechanism: priority-15 idempotent re-add registered once at load, priority-5 does throwable work first inside a swallowing `try/catch`, removal call last. |
| `s1-b-crash-safe-stock-mutation.md` | Added live reconfirmation of the injected-crash acceptance test and the naive-duplicate-key/explicit-rollback pair, on a fresh MariaDB instance. |
| `s1-a-component-reservation.md` | Added live confirmation of: opt-out scoping, sole-writer aggregation, hook ordering (real HTTP Store API request, not simulated), the typed-vs-bare exception distinction for clean Store API errors (new requirement), insufficient-stock handling, retry idempotency (via genuinely separate processes), and a new simplification finding (core's own release step already cleans up the plugin's rows for the standard completing path). |
| ADR-0002 draft (superseded, see `docs/adr/0002-...md`) | Updated the reservation-release bullet and the restoration-deferral bullet to match the corrected/confirmed reports. |

No claim was silently overwritten — every correction is recorded as a
visible, explicit block with the old context preserved above it.

---

## 8. Remaining limitations / gaps, stated plainly

- The Store API tests used Cash on Delivery as the only payment gateway; a
  gateway with an asynchronous/redirect payment flow was not exercised,
  and could in principle change the timing between the order-processed
  action and order-status transitions. The core finding does not depend on
  the payment gateway, but this specific combination was not tested.
- The alternate order-storage mode was toggled on for the custom-status
  test and the restoration-suppression test, but the S1-A reservation
  tests were run only with it off. S1-A's design touches only the
  reservation table and order line items via the standard order/order-item
  API, not order-storage-table storage directly, so this is a low-risk
  gap, but it was not independently re-run under that mode.
- The Store API concurrency test (two simultaneous real HTTP checkouts
  racing for the last unit) was not repeated as a genuine live HTTP race
  in this pass — the original spike's database-level concurrency test
  (two real OS processes racing the locked SQL) remains the evidence for
  the locking property itself; this pass added the *hook-ordering and
  exception-handling* proof on top of it.
- Third-party listener deduplication (operation id in payload) remains a
  documented contract obligation on code not yet written.
- A fulfillment-side stock-readiness gate (requiring a completed journal
  row before intake) was not independently re-tested here — it lives in a
  separate repository with no code yet written against the corrected
  design.
- This verification pass, like the prior spike, exercised one WooCommerce
  version (11.0.1) and one WordPress version (7.0.2, this time live rather
  than only read from a config comment). No matrix was run.

---

## 9. Overall outcome: PASS

All four mandatory items (S1-A reservation, S1-B restoration suppression,
custom-status contract, transaction/rollback reconfirmation) hold under
live execution. One real defect in S1-B's originally-stated restoration-
suppression mechanism was found and is **corrected in place**, and the
**corrected** mechanism was itself proven live (8 properties, twice — with
the order-storage compatibility mode off and on). S1-A's reservation
design is confirmed with one new mandatory implementation detail (a typed
Store API exception, not a bare one, for shortfall errors) and one useful
simplification (core's own release step already covers the standard
completing path for free).

---

## Lifecycle and recovery state machine (Architecture A, for the record)

| State | Entered when | Exit / recovery |
|---|---|---|
| `no_op` | Order has no kit line, or kit line not yet reserved | → `reserved` on checkout |
| `reserved` | The reservation pass committed a reservation row | → `released` (checkout completes/fails/expires) |
| `released` | Reservation row deleted (explicit release) or naturally excluded (expiry) | terminal for reservation; independent of reduction state |
| `pending_reduce` | Payment/status transition fired, readiness signal present | → `reduced` (transaction commits) or → `deferred_reduce` (plugin unavailable) |
| `deferred_reduce` | The correct, earlier reduction filter returned `false` (plugin absent) | order enters the custom status; audit note added; → `pending_reduce` when readiness fires (explicit sweep) |
| `reduced` | A journal row (`op_type=reduce`) committed, outbox state progressing `pending→done` | → `pending_restore` on cancellation/pending/failed transition |
| `pending_restore` | Restoration-triggering transition fires, plugin present | → `restored` |
| `deferred_restore` | The host guard removed the core restoration callback for this dispatch (plugin absent) | order stays in the custom status; → `pending_restore` when the plugin returns |
| `restored` | A journal row (`op_type=restore`) committed | terminal for that reduce/restore pair |
| `partial_failure` | Some component operations in one reduce/restore pass failed | order → the custom status; fulfillment → `problem`; blocked until explicit idempotent operator recovery |
| `refund_restock_pending/done` | The refund-created action fired with restock enabled | Same transactional pattern, `op_type=refund_restock`, keyed to refund id |

Outbox sub-state (orthogonal to the above, per operation): `pending →
done`, with a durable sweep retrying anything stuck `pending` past a
threshold, idempotently.

## Public hook/action payload contracts (Architecture A, for the record)

New plugin actions (all fire post-commit, outside any open transaction,
per the outbox design): a stock-reduced action, a stock-restored action, a
refund-restock action, a deferred-operation action, and a partial-failure
action. **Every payload must carry the immutable operation id.** Delivery
guarantee: **possible duplicate delivery** (at-least-once, not
exactly-once) — a compatible listener must deduplicate on the operation
id.
