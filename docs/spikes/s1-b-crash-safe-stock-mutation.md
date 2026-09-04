# Spike S1-B — Crash-Safe, Exactly-Once Stock Mutation

**Verdict: PASS — a design was proven that passes the injected-crash
acceptance test, including the crash-after-commit case.**

This spike is part of Architecture A (see `docs/adr/0002-...md`), which was
independently proven viable on its own terms and then set aside in favour
of Architecture B. It is preserved as evidence for a rejected alternative.

## Checkpoint carried in from Spike S1-A

Spike S1-A ("Component Reservation") established, before this spike began:

1. The plugin is the sole writer to the reservation table for kit orders —
   core is opted out entirely — so this spike's mutation/journal design
   only has to be atomic with respect to the stock mutation and the
   plugin's own reservation-release step, not with a concurrently-writing
   core reservation pass.
2. Reservation release is an explicit plugin-owned step on the same
   trigger events core uses to release its own reservations, and it
   naturally self-heals via the reservation row's own expiry timestamp
   even if an explicit release step were ever lost.
3. The reduce/restore/reserve/release lifecycle is four operation types
   sharing one operation-identity mechanism, not two — reflected in the
   journal schema below.
4. The reservation-to-reduction overlap window is proven safe (understates
   availability, never oversells) — this spike's crash-recovery design
   needs no special case to compensate for it.

No STOP condition applied at this checkpoint.

## Environment / provenance

Same disposable database instance as S1-A, InnoDB throughout. The
postmeta-equivalent table (holding `_stock`), the order-item-meta table,
and the orders-meta table were all confirmed InnoDB on the real reference
database via a read-only `information_schema` query (no writes).

## Question 1 — plugin-owned journal with a UNIQUE op id, committed with the stock mutation

**Answer: yes, but only if the application explicitly detects and rolls
back on a duplicate-key error. InnoDB does NOT do this automatically.**

This is the single most important finding of this spike, discovered by
actually executing the design, not by restating an existing finding.

### Test: naive "let the DB handle it" design — FAILS

```sql
START TRANSACTION;
UPDATE postmeta_stock SET meta_value = meta_value - 4 WHERE post_id = 2001;
INSERT INTO ucb_stock_ops (op_id, ...) VALUES ('dupe-test', ...);   -- duplicate op_id, second attempt
INSERT INTO ucb_stock_ops (op_id, ...) VALUES ('dupe-test', ...);   -- fails: ERROR 1062 Duplicate entry
COMMIT;                                                              -- STILL RUNS
```

Result: `ERROR 1062 (23000): Duplicate entry 'dupe-test' for key
'PRIMARY'` on the second INSERT — but the script (via the real database
client, non-interactive, continuing past errors — the faithful analogue of
how a typical PHP database wrapper behaves by default) **continued past the
error and reached `COMMIT`, which succeeded**. Final state: stock reduced
by 4 (the UPDATE persisted), and exactly one journal row (the duplicate
INSERT itself failed, but its sibling UPDATE in the same transaction did
not roll back). Exit code `0` — no error surfaced to the caller.

**Root cause, confirmed:** InnoDB rolls back the *entire* transaction
automatically only for a **deadlock (error 1213)** or, depending on
configuration, a **lock-wait timeout (1205)**. A duplicate-key violation
(**1062**) is a *statement-level* failure only — the transaction remains
open and valid, and the offending statement's non-conflicting siblings
(here, the UPDATE) stand unless the application explicitly issues
`ROLLBACK`.

**Implication:** a design that "commits the mutation and the unique
operation record in the same transaction" and assumes the database will
reject the whole transaction on replay is **wrong as stated** — it will
silently commit the stock mutation a second time while only the duplicate
journal insert fails, defeating exactly-once.

### Test: correct design — explicit catch-and-rollback — PASSES

```sql
START TRANSACTION;
UPDATE postmeta_stock SET meta_value = meta_value - 4 WHERE post_id = 2001;
INSERT INTO ucb_stock_ops (op_id, ...) VALUES ('op-correct-1', ...);
COMMIT;                                    -- first (real) attempt: succeeds, stock 10→6

-- replay of the SAME operation:
START TRANSACTION;
UPDATE postmeta_stock SET meta_value = meta_value - 4 WHERE post_id = 2001;
INSERT INTO ucb_stock_ops (op_id, ...) VALUES ('op-correct-1', ...);  -- ERROR 1062, application catches it
ROLLBACK;                                  -- application EXPLICITLY issues this on catching 1062
```

Result: final stock = **6** (unchanged from the first commit — the
replay's UPDATE was rolled back), exactly one journal row. **PASS.**

**Mandatory implementation requirement:** every write path MUST wrap the
operation-record INSERT in a duplicate-key check and explicitly call
`ROLLBACK` — never rely on the transaction auto-aborting. A structural
CI/code-review check (asserting every stock-mutation transaction start has
a corresponding explicit rollback branch on insert failure) is recommended
to prevent regression.

## Question 2 — journal as authoritative, a derived ledger view

**Answer: yes, and it removes the ledger write from the critical path
entirely.** With the journal (unique op id, committed atomically with the
stock mutation) as the source of truth, a per-order-item ledger meta value
becomes a read model: rebuildable at any time by summing journal rows.
Its write can happen post-commit, asynchronously, as part of the outbox's
"internal synchronisation" work (idempotent by construction, since it is a
pure derivation).

## Question 3 — is it safe to run WordPress/WooCommerce code inside the open transaction?

**Answer: no, and the transaction boundary must be the absolute
minimum** — the relative stock UPDATE plus the journal INSERT, nothing
else. This follows directly from already-verified facts (saving a product
issues its own queries and fires arbitrary third-party hooks), and is
reinforced by the Question-1 finding: the shorter and simpler the
transaction, the less surface area for a statement-level error to produce
the "transaction stays open, wrong statements commit" failure mode just
demonstrated.

```
BEGIN;
UPDATE {postmeta-equivalent} SET meta_value = meta_value %+f WHERE post_id = %d AND meta_key = '_stock';
INSERT INTO ucb_stock_ops (op_id UNIQUE, order_id, order_item_id, op_type, stock_managed_id, qty_delta, outbox_state='pending') VALUES (...);
COMMIT;   -- or explicit ROLLBACK on 1062, per Question 1
```

Everything else (stock-status sync, cache invalidation, product-object
refresh, third-party notification) is deferred to the outbox's post-commit
phase (Question 4/4b).

## Question 4 / 4b — direct stock SQL + transactional outbox, both crash points

### Candidate 5-step shape, tested

1. One transaction: stock UPDATE + unique journal row (`outbox_state =
   'pending'`).
2. `COMMIT`.
3. Post-commit: perform WooCommerce synchronisation (stock-status
   recompute, cache clear) and fire public actions — **outside** any open
   transaction.
4. Mark the work item `done`.
5. A durable recovery sweep retries any row still `pending` past a
   threshold.

### Crash point B — after commit, before post-commit work completes

**Test:** committed a stock mutation + journal row with
`outbox_state='pending'`, then — *without running any post-commit work*
(simulating the worker process dying immediately after commit) — ran a
separate "recovery sweep" query that finds the pending row, performs the
(idempotent, in this proof-of-concept a placeholder no-op standing in for
cache-clear/status-sync) synchronisation, and marks it `done`. Ran the
sweep a **second** time to simulate a duplicate cron firing.

```sql
-- after "crash": row still pending, stock already correctly at 6
SELECT op_id, outbox_state FROM ucb_stock_ops WHERE outbox_state='pending';   --> op-outbox-1 | pending
UPDATE ucb_stock_ops SET outbox_state='done' WHERE op_id='op-outbox-1';
SELECT meta_value FROM postmeta_stock WHERE post_id=2001;                     --> 6 (unchanged by recovery)
-- second sweep:
SELECT COUNT(*) FROM ucb_stock_ops WHERE outbox_state='pending';              --> 0
```

**PASS.** The stock mutation is untouched by recovery (it was already
correctly applied exactly once at commit time); recovery only performs the
deferred synchronisation work, and a second sweep is a genuine no-op.

## Question 4c — the four-guarantee table, restated with evidence

| Concern | Guarantee | Evidence |
|---|---|---|
| Stock mutation + operation record | Exactly-once | Injected-crash test below + Question 1's explicit-rollback requirement |
| Post-commit processing | Durable, at-least-once | Crash-point-B test: pending row survives the "crash" and is found by the sweep |
| Internal synchronisation (stock status, caches) | Idempotent, so at-least-once is harmless | Crash-point-B test's second sweep is a correct no-op |
| Third-party listeners | Possible duplicate delivery, mitigated by operation id in payload | Documented contract requirement — action payloads MUST carry the operation id so a compatible consumer can deduplicate |

## Question 5 — compare-and-set (CAS) alternative

**Answer: rejected — proven unreliable under concurrent legitimate stock
changes, with a real test.**

Two genuinely different, both-legitimate operations (not replays of each
other) raced on the same product:

```sql
-- session A (a real, non-duplicate reduction of 3 units):
SELECT meta_value INTO @before FROM postmeta_stock WHERE post_id=2002;   -- reads 10
SELECT SLEEP(2);                                                          -- represents work done between read and write
UPDATE postmeta_stock SET meta_value = meta_value - 3
  WHERE post_id=2002 AND meta_value = @before;                            -- CAS guard

-- session B (a different, unrelated, also-legitimate reduction of 2 units, races ahead):
UPDATE postmeta_stock SET meta_value = meta_value - 2 WHERE post_id=2002; -- succeeds, stock 10→8
```

**Result:** session A's CAS-guarded UPDATE affected **0 rows** even though
A's operation was never a replay of anything — it was simply unlucky in
timing. Session B's legitimate change silently defeated a legitimate,
distinct operation. Final stock = 8, not the correct 5.

**Conclusion:** CAS on expected-before/after stock values is unreliable
because stock is legitimately mutated by many actors. The unique-op-id
design does not suffer this problem because it never inspects the
*current value* of stock at all — it uses core's own relative `UPDATE
meta_value = meta_value %+f` (safe under row locking regardless of
concurrent legitimate changes) and gates only on whether *this specific
operation id* has been recorded before, a property of the operation, not
of the row's value.

## Question 6 — does the answer hold for restoration and refund-restocking?

**Yes, identically.** Restoration and refund-restocking use the exact same
transaction shape: relative UPDATE + unique op-id INSERT + explicit
rollback-on-duplicate. The op-id for a refund-restock operation should
incorporate the refund id, so that a partial refund followed by a second,
different partial refund produces two distinct op ids (correctly
additive), while a retried webhook/cron re-delivery of the *same* refund
produces the *same* op id (correctly deduplicated).

## Question 7 — must the postmeta table's engine remain an asserted runtime precondition?

**Yes.** Confirmed all four relevant tables are InnoDB on the real
reference database. This must be re-checked at plugin activation time and
fail closed if it is ever anything other than InnoDB — a non-transactional
storage engine would silently make the "same transaction" premise this
entire design rests on false, without any other symptom until a crash
actually occurs.

## Background stock operations while the plugin is unavailable

### The correct filter seam

Re-read core's restoration-trigger and reduction-trigger functions directly
in the 11.0.1 source: the reduction path checks `stock_reduced`, evaluates
the correct, earlier filter (`woocommerce_payment_complete_reduce_order_stock`)
*before* both the reduction call and the flag write, and returns early if
the filter vetoes. This confirms the earlier finding exactly, including
the trap: the *other*, later filter (`woocommerce_can_reduce_order_stock`)
is checked **inside** the reduction function itself, and vetoing there
still lets the caller mark the order reduced on the next line, regardless
of the internal veto. A host guard must use the correct, earlier filter,
never the trap one.

### Restoration deferral mechanism — proven via direct read of WordPress core's hook dispatcher, then live

**Mechanism (a host must-use plugin, always loaded, independent of the
main plugin):** register a priority-5 callback on the restoration-
triggering status actions. If the order carries a kit line and the
request-local readiness signal is absent, call the hook-removal function
for the core restoration callback before core's priority-10 callback runs
on the same dispatch. This prevents restoration from running at all for
this transition — leaving the order's "reduced" flag untouched, which is
exactly the deferral semantics needed.

> **A real correction, found only by live execution, not merely a
> caveat.** The mechanism above was first verified only by reading
> WordPress's own hook-dispatcher implementation, not executed. Mandatory
> post-spike runtime verification (real WordPress + WooCommerce, real hook
> dispatch via genuine status-transition calls, disposable Docker
> containers) found the literal reading of this recommendation — "call the
> removal function, then re-add it (e.g. in a `try/finally`) after the
> dispatch completes" — is ambiguous in a way that, implemented the obvious
> way, **PRODUCES NO SUPPRESSION AT ALL.**
>
> **What failed:** a first implementation removed the callback at
> priority 5, then re-added it inside a `try { ... } finally { ... }`
> block **within that same priority-5 callback**. Since `finally` executes
> synchronously, before the hook dispatcher's loop ever advances past
> priority 5 to priority 10, the callback was back in place *before*
> priority 10 was reached — so priority 10 still ran, every time, for
> every kit order. This is not a corner case — it reproduced on every
> kit order tested, 100% of the time, with the finally-based pattern.
>
> **Corrected mechanism, live-verified to actually suppress, with the
> order-storage compatibility mode both off and on:**
> 1. **Once, unconditionally, at plugin load** (not per-dispatch), register
>    a **priority-15** callback on each restoration-triggering hook that
>    re-adds the core restoration callback at priority 10. Re-adding an
>    already-present callback+priority is idempotent/a no-op, so this
>    callback is harmless to leave registered permanently and fire on
>    *every* dispatch, kit or not.
> 2. The priority-5 suppressor does **all** of its own throwable/IO work
>    (order-note writing, meta updates) **first**, wrapped in a `try { ...
>    } catch (\Throwable $e) { /* log, swallow */ }` — swallowed, because
>    the core hook dispatcher has **no try/catch of its own**: a plain
>    loop with direct function calls, confirmed by direct read — so an
>    uncaught exception at priority 5 aborts the **entire** dispatch,
>    including the priority-15 restorer, leaving the hook broken for the
>    remainder of the request.
> 3. **Only after** that try/catch, as the final, non-throwing statement,
>    remove the core restoration callback. The already-registered
>    priority-15 callback then reliably re-adds it once priority 10 has
>    been skipped for *this* dispatch.
>
> **Re-run, full result set, real WordPress+WooCommerce, both order-storage
> modes (all 7 required properties):**
> ```
> TEST 1 (suppression fires for kit order): core restoration ran for KIT order? false ✓
> TEST 2 (non-kit order unaffected, same request): core restoration ran for NON-KIT order? true ✓
> TEST 3 (hook fires twice in one request for the same kit order, re-suppressed both times): 0 (expect 0) ✓
> TEST 4 (two kit orders sequentially, no cross-contamination): kitA=false, kitB=false ✓
> TEST 5 (nested/re-entrant firing from within the handler, for a different order, terminates): kitC=false, kitD=false ✓
> TEST 6 (exception thrown inside the handler — caught internally before the removal call ever runs, nothing left stuck; still registered at priority 10 after the run, and a fresh non-kit order restores normally afterward): true / true ✓
> TEST 7 (no duplicate order notes across the two-firing case in Test 3): 1 note (not 2) ✓
> ```
> Re-run with the order-storage compatibility mode toggled on: identical
> results, all 7 properties hold.

### On-hold re-entrancy hazard — resolved by NOT reusing the built-in "on-hold" status

Register a **custom order status** for the controlled deferral/problem
state, rather than routing through WooCommerce's built-in "on hold"
status. Because this custom status is not one of the four statuses core's
reduction/restoration triggers are registered against, transitioning an
order into it **cannot** recursively invoke the reduction path — the
hazard is eliminated definitionally, not merely mitigated by a guard flag
that could itself have a bug. The genuine "on hold" status is untouched and
continues to behave exactly as WooCommerce intends — only the plugin's own
deferral state avoids the hazard by not being "on hold" at all.

### Explicit trigger for deferred recovery

Recovery does not wait for core's reduction/restoration functions to fire
again naturally — no future event is guaranteed. Instead, when the
plugin's readiness signal fires, it runs an explicit sweep: select all
orders with a kit line, in the custom "stock problem" status, with no
completed journal row for the expected reduce/restore operation — and
performs the deferred operation using the same unique-op-id transactional
design, naturally idempotent if the sweep is ever accidentally run twice.

## Injected-crash acceptance test — the required gate

> "A crash between stock mutation and durable record must leave stock and
> record consistent, and recovery must never double-apply."

**Test, run against the real disposable database, using a genuine second
OS process to sever the first connection mid-transaction (not a mock, not
a simulated exception):**

```
# Victim session registers its own CONNECTION_ID(), then:
START TRANSACTION;
UPDATE postmeta_stock SET meta_value = meta_value - 4 WHERE post_id = 2001;   -- mutation applied, uncommitted
SELECT SLEEP(30);                                                              -- stands in for "process about to crash"
INSERT INTO ucb_stock_ops (...) VALUES ('op-crash-1', ...);                    -- never reached
COMMIT;                                                                          -- never reached

# Killer session, from a separate process:
SELECT conn_id INTO @vid FROM conn_registry WHERE label='crash_test_1';
KILL @vid;    -- forcibly severs the connection, exactly as SIGKILL/OOM/host-reboot would
```

**Result:**
```
ERROR 2026 (HY000): TLS/SSL error: unexpected eof while reading      -- victim's SELECT SLEEP(30) killed mid-flight
ERROR 2006 (HY000): Server has gone away                              -- victim's subsequent statements can't even run
stock_after_crash_MUST_BE_10  = 10   -- UNCHANGED, InnoDB auto-rolled-back the uncommitted UPDATE
op_records_MUST_BE_0          = 0    -- no partial journal row
```

**PASS.** InnoDB's automatic rollback-on-disconnect means a crash at *any*
point before `COMMIT` leaves **both** the stock value and the journal in
their pre-transaction state — there is no possible "mutation applied but
not recorded" state for recovery to misinterpret as a shortfall. This test
was reconfirmed once on a genuinely fresh, separate disposable database
instance during the post-spike verification pass (see
`s1-a-b-verification.md`), with identical results.

## Test inventory (commands + results)

| # | Test | Result |
|---|---|---|
| 1 | Crash between mutation and record (KILL mid-transaction) | PASS — stock unchanged, 0 op rows |
| 2 | Naive duplicate-key handling | **Confirmed unsafe** — stock double-applied, exit 0, no error surfaced |
| 3 | Correct explicit-rollback handling | PASS — stock correct, one op row |
| 4 | Outbox: crash after commit, before post-commit completion | PASS — recovery idempotent, stock unaffected |
| 5 | CAS reliability under concurrent legitimate change | **Confirmed unreliable** — a legitimate op silently dropped (0 rows) |
| 6 | Storage-engine assertion on the real reference database | Confirmed InnoDB for all 4 relevant tables |
| 7 | Hook-removal-during-dispatch mechanism | Confirmed supported core behaviour, then corrected and re-proven live — see above |

## Rejected alternatives — summary

| Approach | Verdict | Reason |
|---|---|---|
| Intent-then-mutate ordering | Rejected (carried from an earlier draft) | Does not cover a crash between mutation and record persistence; re-confirmed by the crash-injection test |
| "Let the unique constraint abort the transaction" | Rejected (new finding) | InnoDB does not auto-rollback on a duplicate-key error, only deadlock/lock-timeout; proven to silently double-apply if not explicitly handled |
| Compare-and-set on expected stock value | Rejected | Proven unreliable — indistinguishable from, and defeated by, concurrent legitimate stock changes |
| The built-in "on hold" status as the controlled deferral state | Rejected | Re-enters the reduction path by definition; replaced with a distinct custom order status |
| Waiting for core to re-fire a status transition to trigger recovery | Rejected | No future event guaranteed once the reduced-stock flag is false; replaced with an explicit op-id-driven sweep |

## Remaining limitations

- The outbox "post-commit work" in the proof-of-concept was a placeholder
  no-op standing in for real product-save/cache-clear/action-firing calls
  — those require a running WordPress/WooCommerce instance to test
  faithfully and are deferred to M1 integration tests.
- Third-party listener duplicate-delivery mitigation (operation id in
  payload) is a documented contract requirement, not something a
  disposable-database spike can itself prove — it depends on the
  discipline of code not yet written.

## Proposed design (for the record — not implemented)

This spike's design is preserved for the record; **it was not implemented
— Architecture B (see `docs/adr/0002-...md`) replaced it before any
freeze.** A plugin-owned journal table (`ucb_stock_ops`) would have been
the authoritative record of every stock mutation the plugin performs
(`reserve`, `release`, `reduce`, `restore`, `refund_restock`):

```sql
CREATE TABLE ucb_stock_ops (
  op_id VARCHAR(64) NOT NULL PRIMARY KEY,   -- immutable operation identity
  order_id BIGINT(20) NOT NULL,
  order_item_id BIGINT(20) NOT NULL,
  op_type VARCHAR(16) NOT NULL,             -- reserve|release|reduce|restore|refund_restock
  stock_managed_id BIGINT(20) NOT NULL,
  qty_delta DOUBLE NOT NULL,                -- signed
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  outbox_state ENUM('pending','done') NOT NULL DEFAULT 'pending',
  KEY order_idx (order_id),
  KEY outbox_idx (outbox_state)
) ENGINE=InnoDB;
```
