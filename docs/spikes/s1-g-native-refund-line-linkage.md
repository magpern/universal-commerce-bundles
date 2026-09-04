# Spike S1-G — Native refund line linkage

**Scope:** find out whether this plugin can add the derived component refund lines within
WooCommerce's own supported native refund-creation flow, **without** taking ownership of
refund creation, restocking, persistence, transactions or retry idempotency — the narrow
replacement scope a product-owner decision substituted for the custom refund-orchestration
subsystem spikes S1-E and S1-F explored and which was ultimately rejected. This spike does not
revisit S1-E/S1-F's subject matter (crash recovery, concurrency, an operation-id ledger) at
all; it answers a different, narrower question.

## 1. Overall verdict: **PASS**

A single, standard, documented WooCommerce action — `woocommerce_create_refund` — is
sufficient. All 4 required cases pass in both WooCommerce order-storage modes (legacy post
storage and the custom order tables), 8/8 evidence points. None of five prohibited shapes
(an order-wide transaction, a private/internal API, a custom table, a custom operation
record, a broad item-hiding filter) is present in the working design — see §4. Limitations
are stated plainly in §7; none of them required inventing a replacement custom protocol.

## 2. The question

> Can this plugin reliably add the derived component refund lines within WooCommerce's
> supported native refund-creation flow, without taking ownership of refund creation,
> restocking, persistence, transactions or retry idempotency?

## 3. The seam found

### 3.1 What was tried first, and rejected: hooking before the refund-creation call is made

The most literal reading of the brief — a filter/action that runs early enough to let extra
`line_items` entries be added to the refund-creation call's arguments before WooCommerce itself
creates the refund — was checked against real WooCommerce 11.0.1 source directly, not assumed:

- The core refund-creation function applies **no filter to its own arguments** before
  processing them — confirmed by reading the full function body: arguments are normalized once
  at the top, with no `apply_filters` call on them anywhere before the item-building loop that
  follows.
- Its two real WooCommerce-core callers were also read directly: the admin "Refund" button's
  AJAX handler, and the REST API's refund controllers. Both build a `line_items` array from the
  incoming request and call the refund-creation function directly — **neither applies a filter
  to that array before the call.** There is no pre-call seam to inject extra `line_items`
  entries from outside these two callers without patching them.

This path does not exist as a supported seam. It was abandoned rather than papered over — the
brief itself allowed for a narrower, honestly-limited answer as an acceptable outcome.

### 3.2 What does exist, and works: `woocommerce_create_refund`, plus core's own restock function called directly

Reading the refund-creation function's body in full shows its real shape:

```
  ...        builds the refund's own line items from the caller's line_items array,
             keyed by real order item id
  ...        $refund->update_taxes(); $refund->calculate_totals( false );
  ...        $refund->set_total( $args['amount'] * -1 );
  ...        /** Action hook to adjust refund before save. @since 3.0.0 */
             do_action( 'woocommerce_create_refund', $refund, $args );
  ...        if ( $refund->save() ) {
  ...          if ( $args['restock_items'] ) { wc_restock_refunded_items( $order, $args['line_items'] ); }
```

`woocommerce_create_refund` fires with the **fully-built, not-yet-saved** refund object and the
original arguments, immediately before the refund is saved and before the restock call. It has
carried a real doc comment (`@since 3.0.0`) in WooCommerce core continuously since that
version — a long-standing, documented, public hook, not an internal implementation detail
(contrast with the object-save action S1-F used for a different, lower-level purpose — refund
*identity* durability — which is also real and documented but serves a narrower need than this
spike's).

Two things follow from reading the object semantics, both verified live in §5-§6:

1. **The refund is a live PHP object.** Adding an item to it inside the hook attaches the item
   to the in-memory refund; WooCommerce's own save — called immediately after the hook
   returns, not by this plugin — persists it via the ordinary items-save path every other
   refund item goes through. No separate save, no extra transaction: the object mutated is the
   exact object core is about to persist.
2. **Restock does *not* read the refund object's items — it re-reads the caller's original
   `line_items` array**, a plain value, not something a hook can mutate back into the calling
   function's own local state (verified directly against WordPress's own hook-dispatch
   implementation) — and in any case the admin/REST callers never name the hidden child item
   ids there at all (children are not shown in the refund UI). So restocking the derived child
   quantities requires **calling WooCommerce's own exported restock function directly**, from
   inside the same hook, with just the derived child entries. This is not a plugin
   reimplementation of restock — it is the same public function core itself calls, given the
   additional arguments core's own call site never sees.

### 3.3 The design

One action-hook callback, on `woocommerce_create_refund`:

1. For each item in the refund arguments' line-item list: skip unless the corresponding real
   order item carries this plugin's kit-parent marker meta (an ordinary refund's items never
   carry it, so the loop finds nothing and the hook is a no-op — the whole answer to required
   case 4).
2. Read the parent quantity being refunded from the arguments, and the parent's original order
   quantity from the real order item.
3. Find every real order line whose parent-link meta points at this parent item id (every
   component already has its own real, linked order item — settled by earlier spikes,
   unaffected by this one or by the refund scope reset).
4. For each: `child_refund_qty = round( (child_original_qty / parent_original_qty) ×
   parent_qty_refunded )` — the exact formula the product-owner decision specified. Skip if
   already explicitly present in the caller's own line-item list (never add twice).
5. Build the refund line item exactly as core's own loop builds one for the parent (clone the
   real child item's class, reset its id, link it back via the same linkage meta core itself
   uses, zero total/tax since children are always zero-priced), and attach it to the refund
   object.
6. Only if restocking was requested, call WooCommerce's own restock function directly for
   exactly the derived quantity — nothing else.

No transaction. No lock. No ledger. No table. No reconciliation. About 90 lines total, in one
file.

## 4. Point 5 — confirmed absent

| Prohibited shape | Present? | Why not |
|---|---|---|
| Order-wide transaction | **No** | The callback issues no explicit transaction statement at all. |
| Private/internal WooCommerce API | **No** | Every call is a public function/method: get the order, get/read its items, read/add item meta, attach an item to the refund, call the exported restock function. `woocommerce_create_refund` itself is `@since 3.0.0`-documented, not an internal-namespace class. |
| Custom refund table | **No** | Nothing is written outside the real refund object and its real order-item rows, via WooCommerce's own save — the same core call path every other refund item goes through. |
| Custom operation record | **No** | No operation ledger, no operation id, no `pending`/`completed` state anywhere in this design. Retry/duplicate-submission handling is explicitly out of scope by product-owner decision and is not attempted here. |
| Broad global item-hiding filter | **No** | The hook only ever *adds* items to one specific, already-in-memory refund object for one specific refund-creation call; it filters nothing globally and hides nothing from any other code path. |

## 5. Test environment and isolation

- Disposable WordPress + WooCommerce + MariaDB containers on a fresh, isolated bridge network,
  image tags matching the rest of this spike series (WordPress 7.0.2, WooCommerce **11.0.1**,
  MariaDB 11.8.8); removed at the end of the session.
- **Both storage modes exercised:** legacy post storage (default) and the custom order tables,
  enabled mid-spike and confirmed via WooCommerce's own storage-mode query, with every
  HPOS-mode order in this spike created **after** that mode was enabled (a fresh, native order,
  not a migrated legacy one).
- **Fixture:** a generic unmanaged "Starter Kit" product (and, for case 2, two distinct kit
  products) each with its own managed-stock components — the same generic shape used
  throughout this documentation series.
- **Isolation:** the containers mount only their own anonymous Docker-managed volumes (no bind
  mount to any host path), sit on their own bridge network, publish no ports, and are
  referenced by no real deployment configuration. The container inventory before and after the
  session was byte-identical; the disposable containers and network were removed and the
  removal verified.

## 6. Test results — all 4 cases × 2 storage modes (8/8)

Fixture per case: components start at stock 50; one kit unit consumes 1 unit of each
component. Each order is built with real, linked, zero-priced child lines and transitioned to
processing, so WooCommerce's own, unmodified stock-reduction code reduces stock exactly as a
live checkout would — this spike does not re-prove the reservation/reduction/restoration
lifecycle, already proven by earlier spikes in this series; it starts from that already-proven
state and tests only the refund-linkage seam.

### 6.1 Case 1 — full refund of 1 kit, restock ON

**Legacy post storage:**
```
order_id=15
stock after checkout: CMPA=49 CMPB=49 CMPC=49
refund_id=16
  refund_item id=5 product=14(kit)   qty=-1 total=-15
  refund_item id=6 product=11(CMPA)  qty=-1 total=0  linked to parent
  refund_item id=7 product=12(CMPB)  qty=-1 total=0  linked to parent
  refund_item id=8 product=13(CMPC)  qty=-1 total=0  linked to parent
stock after refund: CMPA=50 CMPB=50 CMPC=50
```
One refund object, containing the parent refund line and three correctly linked child refund
lines at qty -1 each, matching the 1 kit refunded. Each component restocked from 49 → 50,
exactly once, by WooCommerce's own restock function.

**Custom order tables:** identical shape — a fresh order created after the storage mode was
enabled, one refund object, parent line plus three correctly linked children, stock 49→50 for
all three components.

### 6.2 Case 2 — partial refund, order with two distinct kits, refund 1-of-2 units of Kit A only

**Legacy post storage:**
```
order_id=23 (Kit A x2, Kit B x1)
stock after checkout: CMPA1=48 CMPA2=48 CMPB1=49 CMPB2=49
refunding 1 of Kit A's 2 units only; Kit B untouched in the refund payload
refund_id=24
  refund_item product=19(Kit A) qty=-1 total=-7.5
  refund_item product=17(CMPA1) qty=-1 total=0   linked to Kit A's parent item
  refund_item product=18(CMPA2) qty=-1 total=0   linked to Kit A's parent item
stock after refund: CMPA1=49 CMPA2=49 CMPB1=49 CMPB2=49
```
`child_original_qty=2 / parent_original_qty=2 × parent_qty_refunded=1 = 1` — derived quantity
exact (not 2, not 0). Only Kit A's two child refund lines were created; Kit B's parent item
never appears anywhere in the refund, and its components' stock is **identical before and
after** the refund — the other kit is genuinely untouched, not merely under-refunded.

**Custom order tables:** identical shape — refund of Kit A's item only, Kit B's components
(untouched) stayed at their post-checkout level throughout.

### 6.3 Case 3 — full refund of 1 kit, restock OFF

**Legacy post storage:**
```
order_id=29
stock after checkout: CMPA=49 CMPB=49 CMPC=49
refund_id=30
  refund_item product=28(kit)  qty=-1 total=-15
  refund_item product=25(CMPA) qty=-1 total=0   linked to parent
  refund_item product=26(CMPB) qty=-1 total=0   linked to parent
  refund_item product=27(CMPC) qty=-1 total=0   linked to parent
stock after refund: CMPA=49 CMPB=49 CMPC=49
```
The three linked child refund lines were still created correctly (the linking step is
unconditional), but stock is **unchanged** before and after, because restocking was not
requested. Confirms linkage and restock are genuinely independent responsibilities in this
design.

**Custom order tables:** identical shape — three linked children created, stock unchanged.

### 6.4 Case 4 — ordinary non-kit refund, unaffected

**Legacy post storage:**
```
order_id=32 (3x an ordinary product, no kit meta anywhere)
refund_id=33
refund line-item count (expect exactly 1, the ordinary product itself): 1
order line-item count before/after unchanged: before=1 after=1
```
**Custom order tables:** identical — refund line-item count 1, order line-item count unchanged.

The hook's own guard (skip unless the refunded item carries the kit-parent marker) means an
ordinary product's refund never enters the linkage branch at all — live-confirmed, not merely
reasoned: the refund object for the ordinary product contains exactly the one line item
WooCommerce itself would have produced with no plugin code active.

## 7. Limitations, stated plainly

1. **Refund creation was driven directly through the same function the admin AJAX handler and
   the REST controllers call**, the same methodology used throughout this documentation series,
   rather than a full HTTP round-trip through the admin UI or the REST route. Since neither
   caller does anything to its arguments beyond building them and calling that one function
   (§3.1), and the seam under test fires *inside* that shared function, this is not expected to
   differ by caller, but it is not independently confirmed end-to-end over HTTP.
2. **Gateway refunds were not exercised** — out of scope by product-owner decision (this plugin
   does not own gateway-refund execution).
3. **The derived-quantity formula assumes integer component-per-kit ratios**, consistent with
   the rest of this design (fixed kits, integer per-kit quantities). Rounding is used
   defensively; a configuration producing a genuinely fractional per-unit ratio was not tested
   (the fixed-kit model does not produce one).
4. **No retry/duplicate-submission behaviour was tested, by design** — a second, independent
   refund-creation call with the same or overlapping line items would create a second refund
   and, if the derived child item ids weren't already present in that second call's own
   arguments, a second linkage pass, exactly as bare WooCommerce core would for the parent line
   under the same circumstances — a real, pre-existing defect in bare core (documented
   elsewhere in this series), not something this spike's seam adds or removes. Preventing a
   duplicate refund submission is explicitly the calling integration's own responsibility per
   the product-owner decision, not this plugin's, and this spike does not attempt it.
5. **Concurrent refund requests for the same order were not tested** — again, out of scope:
   WooCommerce's own remaining-refund-amount/quantity validation is the only guard against
   over-refunding under concurrency, unchanged and unaugmented by this design, exactly as it
   would be for any ordinary product refunded through two concurrent requests.
6. Orders and refunds in this spike were built directly against WooCommerce's own order/item
   APIs, not through a real cart/checkout flow — consistent with earlier spikes' own
   methodology for this exact test, and orthogonal to the refund-linkage question this spike
   answers (cart/checkout construction is already proven elsewhere in this series).

None of the above required a workaround, a custom protocol, or a broader hook than the one
documented action. They are the honest edges of a narrow, working answer.

## 8. Relationship to earlier spikes

This spike does not revisit, does not re-test, and does not overturn any evidence in the
earlier closure spike's refund section, or in S1-E or S1-F. Those are retained unmodified
(each carrying a visible correction note) as the historical record of the rejected custom
refund-orchestration path. This spike answers a narrower, different question the product-owner
decision substituted in its place, using a real WooCommerce hook the earlier reports also cite
for a different purpose (the object-save action used there for identity durability inside a
now-rejected transaction/locking protocol). This spike's own hook, `woocommerce_create_refund`,
is not that hook, and carries none of that protocol's apparatus.
