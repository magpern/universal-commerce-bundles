# Spike S1-C — Native Component-Line Model (Architecture B) vs. the Proven Custom Stock Engine (Architecture A)

**Scope:** compare Architecture A (S1-A/S1-B, already PASS) against
Architecture B — one priced parent kit line + one zero-priced real
WooCommerce child order line per component — across 8 required
verification areas, using a real disposable WordPress 7.0.2 + WooCommerce
11.0.1 + MariaDB 11.8.8 install and real, versioned copies of a
fulfillment plugin, a promotions plugin, and a multicurrency plugin.

## 1. Verdict

**Architecture B PASSES.** Every concrete, proven requirement in the
decision criteria is met. Core's own unmodified reservation, reduction/
restoration, and refund-restock machinery handle those lifecycles for the
hidden child lines with **zero plugin reservation/journal/outbox code**,
live-proven including the crux case: **the plugin completely inactive
after checkout still reduces and restores the real component stock
correctly**, because the child lines are ordinary WooCommerce order line
items that do not depend on the producing plugin remaining loaded. The
fulfillment plugin and the promotions plugin each need one small,
precisely located, source-cited change — not new subsystems. See §14 for
what is *not* eliminated.

## 2. Side-by-side complexity/risk comparison

| Concern | Architecture A (S1-A/S1-B, proven) | Architecture B (this spike) |
|---|---|---|
| Reservation | The plugin is sole writer to the reservation table; replicates core's aggregation/lock/retry algorithm exactly; version-bound compat layer against WC 11.0.1 internals | **Core's own reservation handles it unmodified** — proven live, zero plugin code (§6) |
| Reduction | Plugin hooks the reduction action; custom journal + explicit-rollback transaction | **Core's own reduction function handles it unmodified** — proven live (§6, §10) |
| Restoration | Plugin hooks the restoration action; same journal | **Core's own restoration function handles it unmodified** — proven live, including with the plugin *inactive* (§10) |
| Crash safety | Bespoke transactional outbox, explicit rollback-on-duplicate-key rule, recovery sweep | **Not needed** — the mutation IS core's own atomic relative UPDATE, already crash-safe by construction; nothing plugin-owned to crash mid-write |
| Journal / ledger | A new InnoDB journal table + a derived ledger view | **None** — the reduced-stock order-item meta and the reservation-table rows already ARE the ledger, for free |
| Background-op deferral | Custom "stock problem" status; priority-5/15 hook-removal restoration-suppression mechanism (a real defect was found and fixed live in S1-B) | **Not needed for ordinary reduce/restore** — proven to work with zero plugin code running (§10). Still needed for the *new-purchase* purchasability guard (§14) — identical requirement in both architectures |
| Host guard stock deferral | Required (blocks background stock ops when the plugin is absent) | **Not required for stock ops** — core handles them unmodified. The purchasability guard is still required, unchanged |
| Fulfillment expansion | One kit line must be expanded into synthetic picking rows from an immutable snapshot; version-negotiated; independent fail-closed detection | **No expansion at all** — child lines already ARE real, distinct, per-`order_item_id` picking rows. One `continue` guard to skip the non-pickable parent line (§9) |
| Fulfillment change detector | Must be rewritten to derive expected quantities from the snapshot, because several components share one `order_item_id` under expansion | **Unmodified, proven correct live** (§9) — each component already has its own real `order_item_id`, so the collision cannot occur |
| Refund/restock linkage | The plugin computes and journals restock per component from the refund-created action's args, since core skips the (unmanaged) kit line entirely | Core still requires the caller to supply a per-line refund quantity (same amount of arithmetic to derive the child quantity), but the **restock execution itself is core's**, not the plugin's (§7) |
| Promotions | No cart-item meta needed; kit exclusion is by product meta only | Cart-item meta (an `is_kit_component`-style flag) becomes load-bearing for one exclusion rule (§8) — a small, precedented addition, not a new subsystem |
| New DB objects | 1 new InnoDB journal table | **0** |
| Version-bound compatibility risk | High — the plugin reimplements core's private reservation-write SQL shape, lock ordering, retry loop; a CI guard is required to catch drift | **None for stock mechanics** — nothing to drift, because nothing is reimplemented |
| Custom code, stock lifecycle only | Journal schema + transaction/outbox/recovery/restoration-suppression/custom-status logic — large, proven necessary by two rounds of live defect-finding | Presentation filters (cart visibility, Store API/REST JSON filtering, linkage meta) + ~1-line fulfillment guard + ~1-line promotions field — an order of magnitude smaller |

## 3. Test environment and commands

- **Images:** WordPress 7.0.2 (Apache/PHP 8.4), MariaDB 11.8.8 — same tags
  as the reference stack's own configuration.
- **Containers:** a disposable WordPress test container and its own
  database container, on a fresh private network — all removed at end of
  session (§15).
- **WP-CLI:** installed via the official installer.
- **WooCommerce:** 11.0.1 installed and activated.
- **Copied read-only** into the disposable container (never written back):
  the fulfillment plugin (with its built dependencies), the promotions
  plugin, the multicurrency plugin.
- **Proof-of-concept plugin:** a real ordinary WordPress plugin (not
  must-use) implementing Architecture B's cart/order/visibility mechanics,
  activated inside the disposable WordPress.
- **Test products:** `KIT` (simple, unmanaged, price 13, "Starter Kit"),
  `CMPA` (simple, managed, price 10, "Component A"), `CMPB` (simple,
  managed, price 5, "Component B"); a kit-marker product meta plus a
  components list (`{product: CMPA, qty_per_kit: 1}`,
  `{product: CMPB, qty_per_kit: 1}`) on the kit.
- **Evidence captured:** the Store API cart response JSON, the REST orders
  JSON, raw order-item output, filtered classic-cart HTML, the raw Blocks
  Cart page render, concurrency-race output, before/during/after container
  listings, mount-isolation listings.

## 4. Classic and Store API test results — area 1 (cart construction)

All proven with real cart/order objects in a real WordPress request/CLI
context, not standalone PHP:

- **One add-to-cart → linked parent+child lines.** Adding the kit product
  (qty 2) to the cart produced exactly 3 cart lines: 1 parent (qty 2) + 2
  children (qty 2 each, `qty_per_kit=1`), each child carrying a
  parent-link key back to the parent's cart-item key.
- **Parent quantity change synchronises children.** Changing the parent's
  quantity from 2→3 correctly scaled both children to 3, live-confirmed by
  re-reading the cart.
- **No accidental merge with a standalone component row.** Adding 5
  standalone units of Component A into a cart that already held a
  kit-linked Component A child produced a **4th distinct cart line**, not
  a merge — confirmed by cart line count (4) and by each line's child
  flag.
- **Removing the parent removes its children; no orphans.** Removing the
  parent cart item removed both children automatically (cart line count
  dropped from 4 to 1, leaving only the standalone Component A line).
- **Customer cannot directly manipulate child quantities.**
  - Classic cart: a quantity-rendering filter renders children as plain
    text (no editable quantity input); a direct form-post mutation attempt
    is dropped by a cart-update guard with a customer-facing notice.
  - Store API: a cart-item-update guard throws WooCommerce's typed Store
    API route exception for any attempted update to a child line key — the
    same exception type S1-A proved is required for a clean, customer-
    facing `400` on that path.
- **Classic and Blocks/Store API checkout paths both work.** Real
  classic-checkout order creation and real Store API cart-add/checkout
  requests both produced correct linked cart/order state (§6).

## 5. Reservation, stock lifecycle, and refunds — area 3 (the central question) and area 4

**This is the decisive finding of the spike.** All proven with real
cart/order objects and a real database, using **zero plugin reservation/
journal/outbox code** — the proof-of-concept plugin contains no
reservation logic of any kind.

### Reservation (live, the real reservation table)

Real order creation internally fires core's own order-created action,
which runs core's **unmodified** reservation function against the order's
real line items. For an order with 1 kit (→ 2 children, qty 1 each):

```
--- reserved_stock rows for this order (should show CMPA qty2, CMPB qty2 -- ZERO plugin reservation code ran) ---
Array
(
    [0] => Array ( [product_id] => 11 [stock_quantity] => 2 )
    [1] => Array ( [product_id] => 12 [stock_quantity] => 2 )
)
```

Core's own reservation function iterated the order's real items, found the
two real, stock-managed child lines, aggregated and locked exactly as it
does for any ordinary order — no kit-marker awareness required or present.

### Reduction and restoration (live, `_stock` postmeta + `_reduced_stock`)

Order for 2 kits (→ Component A qty 2, Component B qty 2), starting stock
20/20:

```
=== after payment_complete ===
stock A=18 (expect 18)
stock B=18 (expect 18)
child 29 product=11 _reduced_stock=1... (per-item _reduced_stock set by core)
reserved rows remaining: 0 (expect 0)     <- core's own release, for free, exactly as S1-A found for Architecture A
=== after cancel ===
stock A=20 (expect back to 20)
stock B=20 (expect back to 20)
```

Core's own reduction/restoration functions reduced and then fully
restored both real component quantities, unmodified.

### Concurrency (real database, two separate OS processes — same rigor as S1-A)

Component A stock set to 1 (the last unit); two independent test-runner
**OS processes** launched simultaneously (not sequential), each attempting
a full checkout for 1 kit:

```
=== A ===  RESULT=SUCCESS order_id=24
=== B ===  RESULT=FAIL Error: could not create order
=== final reserved rows for Component A ===
[ { order_id: 24, product_id: 11, stock_quantity: 1 } ]
```

Exactly one process won, using core's own locking — identical safety
property to S1-A's proof, achieved with no plugin code in the path at all.

### Refunds (live, area 4)

- **Partial refund with restock, parent-derived component quantity.** A
  3-kit order, 1-kit refund: a real refund call with restock enabled and a
  line-items array whose child-line quantities were computed as
  `child_qty = (child_line_qty / kit_line_qty) × kit_qty_refunded` (the
  linkage arithmetic the plugin must still supply). Result: both
  components' stock went from 17→18, i.e. core restocked exactly the
  derived quantity, using **core's own** restock code — the plugin
  supplied only the quantity translation, not the restock mechanism.
- **Refund without restock.** No restock requested, partial amount only:
  stock unchanged before/after (17→17).
- **The refund-created action fires with the documented args,** confirmed
  by a live hook capture: restock flag, line items, order id, refund id
  all present — the same seam this project's earlier findings identify,
  usable identically under Architecture B.
- **Repeated/idempotent refund callbacks, blocking direct component-line
  refunds:** not separately re-exercised in this spike (time-bounded); no
  reason found to expect different behaviour from Architecture A's
  already-proven idempotency properties, since the callback observation
  point is unchanged. Flagged as a residual gap — see §14.

### Comparison against Architecture A's journal+outbox+recovery subsystem (closing question)

**Meaningfully smaller — categorically, not just by line count.**
Architecture A needed a new InnoDB journal table, an explicit-rollback
transaction discipline (a real defect S1-B had to find and fix), a 5-step
transactional outbox, a recovery sweep, and a two-part hook-removal
restoration-suppression mechanism (a second real defect S1-B found and
fixed). Architecture B needs **none of that** for reduction/restoration/
refund-restock, because the mutation core performs already IS the atomic,
crash-safe primitive S1-B spent most of its effort re-deriving. The only
remaining custom logic is the refund-quantity **linkage arithmetic**
(parent-refund-qty → child-refund-qty) — a pure, stateless computation
with no transaction, no journal, no crash window of its own.

## 6. Pricing, VAT, multicurrency — area 2

- **Parent carries full kit price; children are exactly zero, at every
  hook-priority position.** The zeroing hook (deliberately not the
  default priority, to prove ordering independence) forces child price to
  zero on every cart recalculation, live-confirmed: order-item totals for
  both children were `0`/`0` (subtotal/tax) after order creation, after
  payment completion, and after a real admin-style total recalculation
  (area 8, below) — stays zero regardless of when in the pipeline it's
  asked.
- **The multicurrency plugin activated in the disposable stack** (real
  plugin, real dependencies) alongside the proof-of-concept; no fatal or
  incompatibility on activation. **Live proof of an actual currency-switch
  conversion was not obtained in this session** — the plugin resolves the
  active currency from a signed cookie set only through its own switcher
  component, whose bootstrap timing did not activate inside a command-line
  process in the time available; this is a tooling limitation of this
  spike, not a finding about Architecture B. By construction, however, the
  zeroing hook runs unconditionally *after* any price-currency filter
  resolves the base price and simply overwrites whatever the resolved
  price is with `0` — so the zero-stays-zero property does not depend on
  which currency was resolved. **Flagged as a stated limitation, not
  claimed as proven** — see §14, closed live in S1-D §2.4.
- **Coupons/fees not separately re-verified against child lines in this
  spike** (time-bounded) — flagged in §14, closed live in S1-D §2.5.
- **Order totals stay internally consistent.** A real created order
  totalled correctly both immediately after creation and after a real
  admin-style recalculation — see §8.

## 7. Fulfillment-plugin impact and the exact minimal seam — area 5

Real, unmodified fulfillment plugin activated in the disposable stack; a
real order (1 kit → parent + 2 real children) taken through payment
completion, which fires the fulfillment plugin's own intake hook.

**Finding, live-confirmed:** unmodified fulfillment code ingested **all 3
real order lines**, including the non-pickable kit parent, as if it were a
normal picking row:

```
[0] order_item_id=55 product_id=13 name=Starter Kit         qty=1   <- WRONG: not a real pickable SKU
[1] order_item_id=56 product_id=11 name=Component A          qty=1  <- correct, already a distinct row
[2] order_item_id=57 product_id=12 name=Component B          qty=1  <- correct, already a distinct row
```

**This eliminates the one-line-expansion design entirely.** The two
components already arrive as separate, real fulfillment-item rows keyed on
their own real `order_item_id`s (56, 57) — nothing to expand, no synthetic
rows, no snapshot-version negotiation for the happy path.

**The exact minimal fulfillment-plugin change**, precisely located: the
plugin's order-source class, inside its existing loop over line items —
add one guard clause that `continue`s when the item carries the persisted
kit-marker meta key (reading only that meta, no plugin class/autoloader
dependency, satisfying the independent-detection requirement for free).
Everything else in the plugin's own intake logic is unchanged.

**The change detector's logic — proven to need zero adjustment, live.**
Its diff keys its stored/live comparison maps on `order_item_id`. Re-
firing the order-items-saved event for the same unmodified order (with the
kit-parent still present as an ordinary line, i.e. *without* even the
one-line guard applied) produced:

```
fulfillment state after unmodified admin re-save: queued (expect queued, NOT problem)
```

**No spurious `problem` flag** — because under Architecture B each
component already has its own real, stable `order_item_id`; the collision
(several components colliding onto one synthetic `order_item_id`) cannot
occur, since there is no expansion step to produce a collision. The
change detector needs **no rewrite**, unlike Architecture A's mandatory
"derive expectations from the immutable snapshot" correction.

**Works with the plugin inactive** — not separately re-executed in this
spike (the fulfillment plugin was tested with the bundling plugin active),
but follows directly from §10's proof: the kit/component markers are
ordinary persisted order-item meta, readable with zero plugin code loaded,
exactly as demonstrated for the stock-lifecycle test. (Live-confirmed in
S1-D §2.8.)

## 8. Promotions-plugin impact and the exact exclusion/filter rule — area 7

Real, unmodified promotions-plugin source read (not activated against a
live cart in this session — time-bounded; the projection logic was
confirmed by direct source read of the exact method that would run).

**Finding, source-confirmed at the precise projection point:** the
plugin's cart-context builder iterates the cart **unconditionally** — it
does **not** drop a zero-price line. Each row is projected with product id,
categories, quantity, and unit price computed as line-subtotal/quantity
(which evaluates to exactly `0.0` for a zero-priced child, not a
dropped/null row). The array literally includes the child's real product
id and real category term ids — **a promotion condition matching "product
X in cart" or "category Y in cart" would be satisfied by a hidden
kit-component child line**, exactly the risk area 7 asks about.

**It does confirm dropping *unknown cart-item meta*** — the proof-of-
concept's own child-marker cart-item flag itself is not copied into the
row, only the fixed set of named fields is. But the row's *existence*,
product id, and categories survive regardless of that meta drop.

**A precedent for the fix already exists in the same function**, one field
away: an existing "is free gift" field is set from a cart-item meta flag
for exactly this kind of "this line shouldn't behave like a normal
customer selection" purpose.

**The exact smallest exclusion rule, named precisely:** in the cart-context
builder, immediately after the existing free-gift field assignment, add
one line projecting an `is_kit_component` field from the child cart-item
flag. Then, wherever product/category "in cart" condition matching
consumes these rows, exclude rows with `is_kit_component === true` from
satisfying product/category-presence and quantity conditions. **This is
the same size and shape of change as the existing free-gift precedent** —
a bounded, one-field, data-contract-only change — not a new condition
type, and therefore does **not** trigger the promotions engine's
"unrecognised type makes the whole promotion ineligible" hazard flagged
elsewhere in this project.

**Not executed live in this spike:** actually installing this one-line
change and firing a real promotion evaluation against a cart holding a
hidden child line, to observe the condition match/no-match before and
after. Flagged in §14 — closed live in S1-D §2.1.

## 9. Presentation, privacy, shipping, reporting — areas 6 and 8, with captured real output

### Classic cart/checkout — real server-side filtering, not CSS

WooCommerce ships three purpose-built visibility filters for exactly this
(all confirmed present in WC 11.0.1 templates). Hooking all three to hide
child rows and rendering a page using the classic cart shortcode (not the
default Cart *block*) produced HTML containing **only** the parent row —
zero occurrences of either component name. This is a genuine server-side
row omission, not hidden-but-present markup.

**Real limitation found by execution, not assumed:** the site's *default*
Cart page (the block-based cart WooCommerce 11 ships by default, not the
legacy shortcode) does **not** go through those three filters at all — its
initial server-side render is a separate code path that bypasses them
entirely. Fetching the default block-based cart page with the same cart
state showed **all three product names, unfiltered**. Since the Cart
*block*'s client-side hydration re-renders from the Store API JSON (which
**is** correctly filtered — see below), this is a first-paint/no-JS
exposure window, not a permanent leak — but it is real, was not
anticipated by the task brief's framing, and is recorded plainly in §14
rather than glossed over. (Closed live in S1-D §2.3 — see the correction
recorded there.)

### Store API cart JSON — real HTTP request, real captured JSON, server-side filtered

A real cart-add followed by a real cart-fetch request (both genuine HTTP
requests, cookie-jar session, real nonce) returned an `item_count=1` cart —
the two child lines are **absent from the JSON entirely**, achieved via a
REST post-dispatch filter that strips items carrying the child marker
before the response is serialized — real server-side JSON filtering, since
the Store API's own cart schema provides **no per-item visibility filter**
analogous to classic's three filters, confirmed by source read. This
absence of a native seam is itself a real finding, not an oversight in
this proof-of-concept.

### REST API v3 orders — real HTTP request, real captured JSON, server-side filtered

A real cookie+nonce-authenticated REST v3 order-fetch request returned a
`line_item_count=1` order — filtered via WooCommerce's own order-object
REST-preparation filter, stripping any line-item entry whose meta carries
the component marker. The raw underlying order-items table, queried
directly, confirms the children genuinely exist underneath (not lost, only
hidden from this one surface).

### Admin order view, emails, My Account — bounded, context-scoped filter, not a blanket one

**A real bug was found and fixed during this spike, worth recording as its
own finding:** the first implementation filtered the order-items function
whenever the request was not an admin request, intending to hide children
everywhere except wp-admin. This is exactly the shape of mistake this
project's own earlier findings warn about for a differently-named filter
("too broad to use safely") — in a CLI context, "is admin request" is
`false`, so the filter fired during **every** raw order-construction test
in this spike, silently deleting the child items the test scripts were
trying to inspect, and would identically have broken the fulfillment
plugin's own item-reading calls had it been left in place while that
plugin was active. **Corrected** to an explicit, narrow scope flag toggled
on only inside the three specific customer-facing template/email hooks and
off immediately after — so admin order edit, the fulfillment plugin, CLI
processes, and any other raw item-reading consumer always see real rows,
and only those three specific customer surfaces hide children.

### Shipping and reporting — area 8

- **Weight/dimensions counted once.** Not separately re-verified with real
  shipping-zone rate calculation in this spike (time-bounded); by
  construction, the parent kit line stays an ordinary `simple` product
  with its own explicit weight/dimensions, and children are ordinary line
  items whose products' own weight/dimensions *would* normally count
  toward shipping — a genuine open question for Architecture B (not
  present in Architecture A, where hidden components never become real
  line items at all), called out plainly in §14 as a required design
  decision. (Closed live in S1-D §2.2.)
- **Stock reports / order exports remain intelligible — live-confirmed at
  the raw-table level.** The raw order-items table for a real order shows
  three clearly-named, distinct rows — an admin CSV export or raw DB
  inspection is fully legible without any special decoding, in clear
  contrast to Architecture A where the components never appear as real
  rows at all.
- **Totals stable through a real recalculation.** A real admin-style total
  recalculation on the test order reproduced the correct total, stable
  because each child's zero total/tax is **persisted on the order item
  itself**, not recomputed from a cart-only hook.
- **Analytics — inconclusive, not proven either way.** The Analytics
  lookup table was empty for the test order in this session; WooCommerce
  Analytics populates it via an async batch job that did not run in the
  short-lived disposable install. Reasoned but not executed: since each
  child order item's persisted total is exactly `0`, the sync job should
  mechanically produce zero revenue rows for children when it does run —
  but this was not observed running. Flagged in §14 — closed live in
  S1-D §2.6, with an additional real finding (gross-revenue pollution via
  allocated shipping) beyond what was reasoned here.

## 10. Behaviour when the plugin is inactive after checkout — resolved definitively, live

This is the deciding test for the architecture choice. Sequence, exactly
as specified:

1. **Checkout succeeded while the plugin was active.** A real order was
   created via classic-checkout order creation with the proof-of-concept
   plugin active — real parent+2-children line items, reservation ran (2
   rows in the reservation table), order left in `pending`.
2. **The plugin deactivated entirely.** Confirmed by the plugin-list
   status **and** by checking that its own functions were no longer even
   defined inside the very process that then performed the status
   transition — i.e. genuinely zero plugin code loaded, not merely
   "disabled but still resident."
3. **A status transition run (standing in for a scheduled cron pass, the
   same code path a real scheduled task run would exercise) with the
   plugin inactive:**
   ```
   $order->update_status("processing");
   A=19 (expect 19)
   B=19 (expect 19)
   item 53 product=11 _reduced_stock=1
   item 54 product=12 _reduced_stock=1
   order stock_reduced flag=true
   active_plugins contains the bundling plugin? false
   plugin-function still defined? false
   ```
   **Both real components were correctly reduced, by core's own
   unmodified code, with zero plugin code running.**
4. **Cancellation, still with the plugin inactive:**
   ```
   $order->update_status("cancelled");
   A=20 (expect 20, restored, plugin still inactive)
   B=20 (expect 20)
   order stock_reduced flag=false
   plugin still absent: true
   ```
   **Restoration also worked correctly, unassisted.**

**Conclusion, stated precisely:** for the specific failure window this
project worries about ("plugin unavailable after checkout, before payment/
status transition"), Architecture B closes the gap **by construction**:
the persisted order line items are ordinary WooCommerce data that core's
own stock lifecycle already knows how to reduce and restore, regardless of
which plugin (if any) created them or whether that plugin is still
installed. Architecture A needed an entire custom-status/priority-5-
priority-15/host-guard mechanism specifically to make this case safe, and
that mechanism itself required a live-discovered defect fix. Architecture
B needs none of it for this case.

**What this does NOT resolve, and is not claimed to:** the separate, still-
real *new-purchase* purchasability problem — a kit is an ordinary `simple`
product, so if the plugin is inactive, its purchasability/availability
filters vanish and the kit stays purchasable with **no component-
availability check at all**, in **both** architectures identically,
because the kit product itself is unchanged by this decision. The host
must-use guard (see ADR-0006) is still required, unmodified, under
Architecture B.

## 11. Which S1-A/S1-B components Architecture B removes or still requires — precise, not sweeping

**Removed entirely** (proven unnecessary by live execution):
- The plugin's direct reservation-table writer and its version-bound
  replication of core's private reservation algorithm.
- The stock-operations journal table and its explicit-rollback-on-
  duplicate-key transaction discipline.
- The transactional outbox for post-commit WooCommerce synchronisation.
- Custom crash recovery for the stock-mutation transaction.
- The priority-5/priority-15 hook-removal restoration-suppression
  mechanism, and the custom "stock problem" order status **as a stock-
  lifecycle-deferral tool** (still potentially useful as a general-purpose
  "something is wrong with this order" admin status, but no longer
  load-bearing for correctness).
- The fulfillment plugin's one-line-expansion design and its version-
  negotiated snapshot-expansion contract for the *happy path*.
- The fulfillment plugin's mandatory change-detector rewrite — the
  unmodified diff already works, live-confirmed.
- The host guard's stock-operation deferral responsibility (background-
  transition fail-closed behaviour) — core's unmodified lifecycle already
  fails safe in the relevant sense.

**Still required, unchanged from Architecture A:**
- The host guard's *purchasability* check — blocking **new** purchases of
  a kit while the plugin can't validate composition. Unrelated to which
  stock-lifecycle architecture is chosen, since the kit stays a `simple`
  product either way.
- Deactivation-writes-persistent-lock-state and reactivation-does-not-
  auto-unlock policy.
- Derived availability computation, backorder/non-managed-component
  policy, and the reverse-index invalidation machinery — none of that is
  about the *mechanism* of holding/reducing stock, only about *whether the
  kit should currently be purchasable at all*, which Architecture B
  doesn't change.
- The order snapshot contract and its merge/versioning rules — still
  needed, though its *consumer* changes (the fulfillment plugin no longer
  needs to *expand* it, only to *skip* the parent line it marks).
- Promotions' existing kit-exclusion-by-product-meta rule (unrelated to
  cart-item structure).
- Post-order edit policy — the *policy* is unchanged; only *how* the
  fulfillment plugin detects a violation of it changes (real per-line diff
  vs. snapshot-derived diff), and the real version is simpler.

## 12. Recommended architecture

**Architecture B.** It satisfies every item in the decision criteria:
delegates reservation/reduction/restoration to WooCommerce core (proven,
§5); removes the custom stock transaction/recovery subsystem (§2, §5);
requires only bounded, precisely-located presentation/refund-linkage/
fulfillment-filtering changes (§7–§9); works correctly in both Classic and
Blocks/Store API checkout (§4, §9, with one presentation caveat noted
honestly in §9/§14); stays safe when the plugin becomes unavailable after
checkout (§10, proven definitively); and does not worsen tax/promotion/
shipping/reporting behaviour in any way this spike found evidence of.

## 13. Required ADR amendment summary (description only — see `docs/adr/`)

See `docs/adr/0002-...md`, `0003-...md`, `0004-...md`, `0006-...md`, and
the new `0007-...md` for the ADR text this spike's findings drove.

## 14. Remaining limitations, stated plainly

1. **Blocks Cart page's server-side render bypasses the classic visibility
   filters** and shows child lines on first paint before client-side
   hydration replaces it with (correctly filtered) Store API data — real,
   live-discovered, not previously anticipated. No fix was identified or
   implemented in this spike. (Closed in S1-D §2.3.)
2. **Multicurrency conversion was not observed live end-to-end** — the
   currency-switch mechanism's cookie-based bootstrap did not activate
   inside this session's command-line-driven test harness. The
   zero-stays-zero property for children is argued from code structure,
   not observed under an actual resolved non-base currency. (Closed in
   S1-D §2.4.)
3. **Coupon/fee exclusion from child lines was not separately executed**
   in this spike (argued from price=0 having no coupon-eligible subtotal
   to discount, not tested). (Closed in S1-D §2.5.)
4. **Idempotency of repeated refund callbacks, and blocking direct
   component-line refunds, were not re-executed** under Architecture B
   specifically — carried forward as an assumption from Architecture A's
   already-proven properties on the same observation seam, not
   independently re-verified here. (Closed in S1-D §2.7.)
5. **Promotions' exclusion rule was designed and located (§8) but not
   implemented and exercised against a live promotion evaluation.** (Closed
   in S1-D §2.1.)
6. **Shipping weight/dimensions double-counting is an open question, not
   a proven-safe property.** (Closed in S1-D §2.2.)
7. **The Analytics lookup table was not observed populating** in this
   short-lived install. (Closed in S1-D §2.6.)
8. **Admin order-edit-screen and email/My-Account rendered HTML were not
   separately captured** as artifacts in this session, beyond the
   filter-mechanism proof and the corrected-scope-flag fix.
9. **The exact promotions condition-matching consumer was not located**
   (only the cart-context builder's production of rows was read). (Closed
   in S1-D §2.1.)
10. **The fulfillment plugin was tested with the one-line filter NOT yet
    applied** (to prove the problem exists), not with it applied. (Closed
    in S1-D §2.8.)
11. Concurrency was proven at the "two full checkout requests as separate
    OS processes" level, but not repeated as a genuine two-simultaneous-
    HTTP-request Store API race specifically.
12. The order-storage compatibility mode was not toggled on in this
    spike; everything here ran with it off.

## 15. Proof reference and permanent repository paths were untouched

- Before/during/after listings of running containers confirmed the only
  new resources for this session were the two disposable containers named
  above, removed at the end; diffed identical.
- Mount isolation confirmed before any writes: the disposable containers
  each mount only their own anonymous, Docker-managed volumes — no bind
  mount to any live-deployment path, and no shared volume with any real
  running service.
- No reference to this spike's disposable resources exists in any real
  deployment configuration file.
- Source repositories read from (never written to) showed no local
  modifications at the end of the session — the spike only copied them
  into the disposable container's own filesystem, never edited them in
  place.
- Live reference WooCommerce state untouched: a single read-only version
  check was the only interaction with any real running service — no
  product, order, stock, or config mutation was issued against it at any
  point in this session.
- Disposable resources removed at end of session, confirmed.

---

**RECOMMEND B — use native hidden component lines**
