# Spike S1-E — Refund-idempotency crash-safety correction

**Scope:** close one specific design defect found by review in S1-D's
refund-idempotency fix — an intent-then-mutate ordering that repeats the
exact mistake already rejected for stock mutation (see the "Crash safety"
section of `docs/ARCHITECTURE.md` and the S1-B spike) — via a real
`pending`→`completed` state machine with reconciliation, executed against
a disposable WordPress 7.0.2 + WooCommerce 11.0.1 + MariaDB 11.8.8 stack.

## 1. Overall verdict: PASS

All five required properties (three new failure-injection tests, plus two
regression checks re-confirming S1-D's original two properties still
hold) are closed with real, executed, observed evidence. No property is
asserted by reasoning alone.

---

## 2. The defect

S1-D's refund-idempotency fix (`docs/spikes/s1-d-architecture-b-closure.md`
§2.7) implemented a single write-then-done flag: write an operation-id
record to order meta marking it "applied" **before** calling the core
refund-creation function, and reject any later call carrying the same
operation id outright.

This is an **intent-then-mutate** ordering: the "intent" (the operation-id
record) is durably recorded before the "mutation" (the real refund/restock)
is known to have happened. If the process crashes, or the core
refund-creation call itself throws/fails, in the window **after** the
record is written but **before** the real refund is durably created, a
later retry sees the record already marked "applied" and is rejected —
permanently blocking a legitimate refund/restock that never actually
happened. S1-D's own proof covered only (a) successful execution and (b)
an exact duplicate *after* success; it never exercised this failure
window.

**Why this repeats an already-rejected pattern:** this plan's stock-lifecycle
design already rejected "intent-then-mutate ordering" for stock mutation,
and a dedicated crash-injection spike (S1-B) re-confirmed, by a real
forced-disconnect test against a live database transaction, that any
design recording "this operation happened" separately from, and ahead of,
the operation actually happening has an unrecoverable middle state unless
the two are made atomic or the record is reconciled against the real,
durable side-effect afterwards. S1-D's refund wrapper is a direct,
undetected re-instance of exactly this pattern, one layer up the stack
(order meta instead of a stock journal).

---

## 3. Corrected design

A `pending` → `completed` state machine, not a single write-then-done
flag, on a per-operation-id order-meta record, plus a **new** meta key
written onto the real refund object itself:

1. **Write a `pending` record for the operation id before calling the
   core refund-creation function.** Still required — this is what
   prevents two truly concurrent attempts (a race, not a crash) from both
   proceeding.
2. **Call the core refund function**, and on success, embed the operation
   id as meta on the *created refund object itself*. This makes the real
   refund record the durable, authoritative reconciliation target — the
   same principle already documented ("the core refund object itself
   becomes the durable record once it succeeds") but not previously wired
   into the recovery path.
3. **On success, mark the local record `completed`**, referencing the
   real refund id.
4. **Only `completed` suppresses a retry as an exact duplicate.** A
   `pending` record found on a later attempt triggers **reconciliation**,
   never automatic rejection: query the order's real refunds for one
   whose own meta carries this operation id.
   - **Found** → the earlier call actually succeeded before whatever
     interrupted it. Mark `completed` now (idempotent: no second refund,
     no second restock).
   - **Not found** → the earlier call never durably completed. Safe, and
     required, to retry the real refund call, reusing the existing
     `pending` record.
   - If the retried core call itself fails again, the record is left
     `pending` — never marked `completed` on failure — so a subsequent
     corrected retry remains possible.

Reference implementation (illustrative; a "Starter Kit"-style pseudo-shape
matching this repo's existing convention):

```php
function create_kit_refund( $order, $op_id, $line_items, $amount, $reason = '' ) {
    $ops = get_refund_ops( $order ); // reads the state-machine order meta

    if ( isset( $ops[$op_id] ) && $ops[$op_id]['status'] === 'completed' ) {
        return new WP_Error( 'refund_duplicate', 'already applied; skipping' );
    }

    if ( isset( $ops[$op_id] ) && $ops[$op_id]['status'] === 'pending' ) {
        $existing_refund_id = find_refund_by_op_id( $order, $op_id ); // queries
                                                                       // the order's real
                                                                       // refunds for the
                                                                       // op-id meta
        if ( $existing_refund_id ) {
            $ops[$op_id] = [ 'status' => 'completed', 'refund_id' => $existing_refund_id ];
            $order->update_meta_data( 'refund_ops', $ops ); $order->save();
            return $existing_refund_id; // reconciled, idempotent
        }
        // no real refund found -> fall through, safe & required to retry
    } else {
        $ops[$op_id] = [ 'status' => 'pending' ];
        $order->update_meta_data( 'refund_ops', $ops ); $order->save();
    }

    $refund = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => $amount,
        'reason' => $reason, 'line_items' => $line_items, 'restock_items' => true ] );

    if ( is_wp_error( $refund ) ) {
        return $refund; // pending record left as-is; retry permitted
    }

    $refund->update_meta_data( 'refund_op_id', $op_id ); $refund->save();

    $ops[$op_id] = [ 'status' => 'completed', 'refund_id' => $refund->get_id() ];
    $order->update_meta_data( 'refund_ops', $ops ); $order->save();

    return $refund->get_id();
}
```

---

## 4. Test environment

- **Images:** `wordpress:7.0.2-php8.4-apache`, `mariadb:11.8.8` — the same
  tags used throughout this spike history.
- **Containers:** two disposable containers on a fresh, isolated bridge
  network, both removed at the end of the session.
- **WP-CLI 2.12.0**, **WordPress 7.0.2**, **WooCommerce 11.0.1**,
  **PHP 8.4.24** — matched to prior spikes in this series.
- **Test products:** one unmanaged simple kit product and three managed
  component products (stock 50 each) — the same generic shape used
  throughout this documentation.
- **No other plugin source was involved** — this defect and its fix are
  scoped entirely to the refund-orchestration wrapper itself; no
  fulfillment/promotions/multicurrency interaction was in scope.

---

## 5. Test results

### 5.1 Before-core interruption: PASS

Write the `pending` record, then a simulated crash fires **before** the
core refund function is ever called. A fresh process (a genuinely
separate invocation, not a same-process retry) must then find no matching
real refund, safely proceed to call the core function for real, and the
refund/restock must actually happen.

Observed: after the simulated crash, stock was unchanged and the order
meta correctly showed `pending` with **zero** refund objects present. A
fresh-process retry of the same operation id then succeeded: stock
restocked exactly once, order meta advanced to `completed`, and exactly
one refund object existed, carrying the operation-id meta.

### 5.2 Core-call failure: PASS

Write the `pending` record, then call the core refund function with an
invalid amount so WooCommerce's own validation genuinely rejects it (a
real error, no refund object created). A corrected retry with valid
arguments, same operation id, must then succeed.

Observed: the failed attempt left stock unchanged, zero refund objects,
and the order meta correctly still `pending`. The corrected retry then
succeeded exactly once: stock restocked, one refund object created,
carrying the operation-id meta, order meta advanced to `completed`.

### 5.3 Post-core/pre-completion interruption: PASS (the exact gap S1-D left open)

Write the `pending` record, call the core refund function
**successfully** (a real refund object is created, carrying the
operation-id meta), then a simulated crash fires **before** the local
record is marked `completed`. A subsequent recovery/retry of the *same*
operation id, in a genuinely separate process, must reconcile against the
real, already-existing refund.

Observed immediately after the simulated crash: the real refund object
existed and had already restocked all three components, while the local
order-meta record still showed `pending` — precisely the defect state
this spike targets. A fresh-process reconciliation attempt then found the
existing refund by its operation-id meta, marked the local record
`completed`, created **no** second refund object, and caused **no**
further stock change.

### 5.4 Regression — distinct operation id still proceeds; exact duplicate after `completed` still rejected: PASS

Re-confirms the two properties already proved for the original design
still hold under the corrected state machine, using one order for two kit
units: refunding the first kit unit succeeds; retrying the identical
operation id afterward is correctly rejected with no further stock
change; refunding the second, genuinely different kit unit (a different
operation id) still proceeds normally, restocking the remainder.

Observed: exactly as expected in all three steps — first refund
succeeded and restocked one unit's worth of components; the exact-duplicate
retry was rejected with the stock unchanged; the distinct second
operation succeeded and restocked the remaining unit's worth, bringing
stock back to its starting level with two refund objects total on the
order.

---

## 6. Verdict

**PASS.** All three failure-injection tests and both regression checks
are closed with real, executed, observed evidence. Nothing is deferred or
marked "not executed."

---

## 7. Corrected contract

- **`_ucb_refund_ops`** (order meta): a `pending`→`completed` state
  machine keyed by refund operation id, not a single applied-flag.
- **`_ucb_refund_op_id`** (new — meta on the real refund object itself):
  lets a later attempt reconcile the order-meta record against reality
  instead of trusting the record alone.

See `docs/adr/0003-versioned-cart-order-snapshot-contract.md` for the full
corrected contract, and `docs/spikes/s1-d-architecture-b-closure.md` §2.7
for the visible correction note pointing here.
