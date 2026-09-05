# M1 — Fixed Kits Core: Implementation & Validation Record

Status: implementation complete, validated (automated + live + a later
acceptance pass closing all six previously-open live-HTTP/HPOS-on/crash-
injection gaps — see "Acceptance validation pass" below), **not** released.
Do not read this as release-readiness — no tag, no GitHub release, no
deployment of any kind has happened as part of this closure record.

## What M1 implements

Per `docs/ARCHITECTURE.md` and ADR-0001 through ADR-0007, on top of M0's
inert foundation:

1. **Fixed kits as `simple` products** (ADR-0001) — no custom product type,
   static price/tax, no runtime price summation. (`src/Woo/KitAvailability.php`
   only adds purchasability/availability filters; pricing is untouched.)
2. **Composition metadata, validation, reverse-index** (ADR-0002) —
   `src/Domain/Composition.php` (merge rule, structural validity),
   `src/Engine/CompositionValidator.php` (missing/unpublished/mixed-tax-class
   detection), `src/Application/CompositionRepository.php` +
   `ReverseIndexRepository.php` (storage), `src/Woo/Invalidation.php` (save/
   delete/stock/price/tax-class hooks + admin notice + `reconcileAll()`
   rebuild routine).
3. **Derived availability/purchasability** (ADR-0002) —
   `src/Engine/AvailabilityCalculator.php` implements
   `min(floor(component_available/qty_per_kit))`, backorder/unmanaged
   components excluded from the minimum; `src/Woo/KitAvailability.php`
   feeds it live product/reservation facts and hooks
   `woocommerce_is_purchasable`/`woocommerce_variation_is_purchasable`/
   `woocommerce_get_availability`. Never trusts the cached hint
   (`CompositionRepository::getCachedValidityHint()` is a display hint only).
   The `_ucb_is_kit` marker and `ucb_runtime_ready` capability-handshake
   contract were already emitted by M0's foundation; M1 adds no changes to
   that contract.
4. **Architecture B cart/order construction** (ADR-0002, ADR-0003) —
   `src/Woo/CartConstruction.php` (add-to-cart child injection, on-every-
   recalculation quantity sync + price/weight/dimension/shipping-class
   zeroing, cascade removal, non-editable child quantity render, classic
   cart-update-request guard), `src/Woo/StoreApiGuard.php` (Store API child-
   quantity-edit block via `rest_request_before_callbacks`, mirroring
   WooCommerce's own `RouteException` error shape), `src/Woo/OrderConstruction.php`
   (two-pass parent-link resolution + kit snapshot write at checkout, both
   classic and Store API/Blocks — they share the same underlying
   `WC_Checkout` hooks).
5. **Presentation** (ADR-0003, ADR-0007) — `src/Woo/Presentation.php`:
   the three classic cart-item-visibility filters, `rest_post_dispatch`
   (Store API JSON), `woocommerce_hydration_request_after_callbacks`
   (Blocks hydration), `woocommerce_rest_prepare_shop_order_object` (REST
   v3), and a narrowly-scoped `woocommerce_order_get_items` guard bracketed
   only around the specific customer-facing template/email hooks — never a
   blanket check. A Contents line built from the snapshot (order-level) or
   live composition (pre-purchase cart-level).
6. **Native refund linkage only** (ADR-0002, ADR-0003, spike S1-G) —
   `src/Woo/Refunds.php`: pre-save `woocommerce_create_refund` adds derived,
   correctly-quantified, zero-total, marker-tagged child refund lines only
   (no stock mutation); post-save `woocommerce_refund_created` re-reads the
   persisted refund's own tagged lines and calls WooCommerce's own exported
   `wc_restock_refunded_items()` for them, only when restocking was
   requested. `src/Engine/RefundLinkageCalculator.php` is the pure
   `child_refund_qty = (original_child_qty/original_parent_qty) ×
   parent_qty_refunded` formula. No operation ledger, lock, transaction, or
   reconciliation sweep — explicitly out of scope per ADR-0002/ADR-0003.
7. **Cross-cutting exclusion contract, UCB's own scope** (ADR-0007) —
   `src/Woo/Exclusions.php`: `woocommerce_coupon_get_items_to_validate`
   (coupon eligibility), a scope-flag bracket around the real recurring
   Analytics batch action. Shipping weight/dimension/shipping-class zeroing
   is folded into `CartConstruction`'s existing price-zeroing hook, per the
   doc's own instruction not to duplicate it. `_ucb_is_kit` and
   `_ucb_component` are the data-contract markers a separate promotions-
   plugin repository would consume — no promotions code exists here.
8. **Deactivation-lock policy** (ADR-0006, item 1-2, this plugin's own
   responsibility as distinct from the host MU-plugin guard) —
   `src/Woo/DeactivationLock.php`: deactivation locks every kit
   non-purchasable via persisted product state; reactivation does not
   auto-unlock; an explicit `unlock()` revalidates composition first.
9. **Component catalogue visibility & direct-URL policy** (ADR-0005) —
   `src/Woo/ComponentVisibility.php`: standalone-purchase gate (with an
   internal-call bypass for the plugin's own child-line construction),
   catalog/search visibility, sitemap and REST-listing exclusion,
   canonical-kit redirect / 404 for direct component-page visits.
10. A minimal, functional admin UI (`src/Admin/KitDataPanel.php` — a "Kit
    Components" product-data tab) to mark a product as a kit and edit its
    composition; kept deliberately simple (vanilla JS repeater over a
    hidden JSON field, no build step, no bundled framework).

### Explicitly NOT implemented (out of scope, per the M1 brief)

The host MU-plugin safety guard; the fulfillment-plugin parent-skip change
(a different repository); promotions-plugin conditions (a different
repository); gateway refunds, retries, or any refund concurrency handling;
quantity-based shipping-package filtering (documented residual, deferred);
the one-shot "Calculate suggested price from components" admin action from
ADR-0001 (not in this task's required-scope list; left for a future pass —
noted here rather than silently omitted); nested kits, variation
components, or customer-configurable bundles (all explicitly deferred by
the architecture).

## Validation — what was automated vs. manually verified live, and why

Per the architecture doc's own acceptance-coverage list. Automated tests
are real, executable PHPUnit tests committed to this branch
(`tests/Unit/*`, `tests/Structural/*`); "manually verified live" means a
disposable Docker WordPress + WooCommerce + MariaDB stack was stood up,
this branch's actual code was installed and exercised against it with real
`wp eval`/`wp eval-file` scripts, and this document reports the literal
captured output.

### Automated (PHPUnit, 67 tests total, all passing under PHP 8.1 and 8.4)

_(Grown to 72 tests by the later acceptance validation pass below — see
"Acceptance validation pass" for the 3 tests it added.)_

- The derived-availability formula, including both required backorder/
  unavailable-component combinations from the acceptance list
  (`tests/Unit/AvailabilityCalculatorTest.php`).
- The refund-linkage arithmetic, including the exact 2-kits-refund-1
  case spike S1-D's live evidence recorded
  (`tests/Unit/RefundLinkageCalculatorTest.php`).
- Composition validation (missing/unpublished/mixed-tax-class, each
  independently) (`tests/Unit/CompositionValidatorTest.php`).
- The composition merge rule (duplicate component rows sum their
  quantity) (`tests/Unit/CompositionTest.php`).
- The kit snapshot contract's build/serialize/deserialize round-trip and
  historical component lookup (`tests/Unit/KitSnapshotTest.php`).
- `CompositionRepository`/`ReverseIndexRepository` against WordPress-
  function mocks (`tests/Unit/CompositionRepositoryTest.php`,
  `tests/Unit/ReverseIndexRepositoryTest.php`).
- The WooCommerce-confinement structural test continues to pass with M1's
  much larger codebase (`tests/Structural/WooConfinementTest.php`) — every
  WooCommerce symbol (`WC_*` classes, `wc_*` functions,
  `Automattic\WooCommerce\*`) lives only under `src/Woo/`.
- All of M0's original safe-fail/bootstrap/HPOS-declaration tests still
  pass unchanged with the full M1 module wired into `plugins_loaded`.

Full business logic living in real WooCommerce hook callbacks
(`src/Woo/*`) was **not** additionally unit-tested with WooCommerce-class
mocks — WooCommerce's own classes (`WC_Cart`, `WC_Order`, `WC_Product`,
refund objects) are too large and stateful to usefully hand-mock; the real
Docker validation below exercises this code against the genuine classes
instead, which is a stronger guarantee for this layer than a mock would be.

### Manually verified live (disposable WordPress 7.0.2 + WooCommerce 11.0.1 + MariaDB 11.8.8, exact spike-exercised versions)

Environment: two containers (`ucb-m1-wp`, `ucb-m1-db`) on a dedicated
`ucb-m1-net` Docker network, no ports published to the host, this
repository's working tree bind-mounted **read-only** into the WordPress
container and symlinked into `wp-content/plugins/`. `docker ps` was
captured before and after; the container/network list was identical
afterwards. All resources were created fresh for this session and removed
at the end (`docker rm -f`, `docker volume rm`, `docker network rm`); no
compose file or real DEV/production resource was touched or referenced.

- **Plugin activates without a fatal error** against real WooCommerce
  11.0.1; `ucb_runtime_ready` fires exactly once (`did_action()` = 1).
- **Derived availability, live, real stock/reservation data:** Component A
  stock 20/qty-per-kit 1, Component B stock 20/qty-per-kit 2 → kit
  available quantity computed as 10 (correct: `min(20, floor(20/2))`).
- **Reverse-index reconciliation** (`Invalidation::reconcileAll()`) after
  composition was written directly to meta (bypassing save hooks, matching
  the "bulk import" scenario) correctly rebuilt the index for both
  components.
- **Cart construction, real `WC_Cart`:** one add-to-cart of the kit (qty 2)
  produced exactly 3 real cart lines — 1 parent (qty 2) + 2 children (qty 2
  and qty 4, matching qty-per-kit 1 and 2) — each child correctly linked to
  the parent's real cart-item key.
- **Parent quantity change synchronises children, live:** changing the
  parent's quantity 2→3 correctly scaled both children (→3, →6); cart
  subtotal stayed exactly `3 × 13 = 39` (children contribute zero).
- **No merge with a standalone purchase:** adding 5 standalone units of
  Component A into a cart already holding a kit-linked Component A child
  produced a **4th, distinct** cart line (verified by line count and by
  each line's own component flag), not a merge.
- **Removing the parent removes its children:** cart line count dropped
  from 4 to 1 (only the standalone line remained) — no orphaned child
  lines.
- **Classic checkout produces correctly linked real order lines:** a real
  order built via `WC_Checkout::create_order()` produced a parent order
  item (with the kit snapshot attached) plus two child order items, each
  carrying the parent's **real, resolved `order_item_id`** (not a
  leftover cart key) — proving the two-pass parent-link-resolution
  mechanism (`OrderConstruction::resolveParentLinks()`) works correctly
  against a real, saved order.
- **The decisive test, reproduced exactly as the spike specifies:** the
  above order was transitioned to `processing` (`$order->update_status()`)
  — with the plugin genuinely, fully deactivated first (`wp plugin
  deactivate`, confirmed by `class_exists(..., false)` returning false
  **during** the transition, not merely "disabled in the admin list").
  Component stock reduced correctly (20→18, 20→16, matching quantities 2
  and 4) via WooCommerce core's own unmodified reduction lifecycle, with
  zero plugin code loaded. The order was then cancelled, still with the
  plugin inactive, and stock was correctly restored to 20/20 — again fully
  unassisted.
- **Deactivation-lock policy, live:** deactivating the plugin locked the
  kit (`_ucb_locked` set, `is_purchasable()` → false); reactivating did
  **not** auto-unlock it (still locked, still non-purchasable); an
  explicit `DeactivationLock::unlock()` call revalidated composition and
  correctly unlocked it (`is_purchasable()` → true again).
- **Native refund, exact derived quantities, live:** a 2-kit order,
  partial refund of 1 kit with restocking enabled, produced derived child
  refund-line quantities of exactly **1** (Component A: `(2/2)×1`) and
  **2** (Component B: `(4/2)×1`) — not 2, not 0 — matching spike S1-D's own
  recorded case exactly, with each derived line correctly zero-total and
  tagged with the originating child order-item id.
- **The refund ordering assertion, live (the specific property S1-G's
  correction round was about):** a listener registered on
  `woocommerce_refund_created` at priority 5 (before this plugin's own
  priority-10 restock callback) captured stock as **unchanged** (18, 16);
  only once the whole `wc_create_refund()` call returned had stock changed
  to the derived values (19, 18) — proving the restock genuinely happens
  strictly after the refund's own save-and-core-restock phase, not before.
- **Refund with restocking disabled, live:** a further partial refund of
  the same order, restocking disabled, left stock **unchanged** (19, 18)
  while still creating the correctly-derived, correctly-linked child
  refund lines (qty −1, −2).
- **Presentation hiding, live, real filter invocation:** `woocommerce_cart_item_visible`
  correctly hid both real child cart lines (1 visible, 2 hidden) for a
  kit-only cart. The narrowly-scoped admin/email bracket
  (`woocommerce_order_details_before/after_order_table`) correctly reduced
  a real order's `get_items()` from 3 to 1 **only while the bracket was
  open**, and correctly restored the full count of 3 immediately after —
  confirming the scope-flag mechanism does not leak into other consumers
  (the property the "blanket filter broke fulfillment" lesson is about).
- **Coupon exclusion, live, real coupon object:** a real percentage
  coupon restricted to Component B was rejected (`apply_coupon()` →
  `false`, real "not applicable to selected products" notice) on a
  kit-only cart with no genuine standalone purchase of Component B, then
  correctly **accepted** (`apply_coupon()` → `true`) once a genuine
  standalone purchase of Component B was in the cart — matching S1-D's
  exact leak-then-fix-then-control sequence.
- **Shipping weight zeroing, live, real cart weight accessor:** with
  Component A/B given non-zero weights, a kit-only cart's
  `get_cart_contents_weight()` correctly returned the parent's weight
  alone (1.0), not the sum including hidden children.
- **Store API child-quantity guard, live (direct invocation against a
  real `WC_Cart` and a real `WP_REST_Request`):** a simulated
  `update-item` request targeting a real child cart-item key was rejected
  with a `WP_Error` (`woocommerce_rest_cart_component_quantity_locked`,
  HTTP 400) matching WooCommerce's own `RouteException` error shape; the
  identical request targeting the **parent's** own key was correctly left
  unblocked (`null`, i.e. "not this guard's concern").
- **Invalidation, live:** trashing Component A correctly invalidated the
  kit (`valid` → false, `is_purchasable()` → false); restoring it made the
  kit valid again. Giving Component A a different tax class than
  Component B correctly invalidated the kit for the same reason;
  restoring a consistent tax class made it valid again.

### Three real defects found and fixed by this live validation, not by review

Live execution against a real WooCommerce install caught three genuine
signature mistakes that static review and the PHPStan/PHPCS static-analysis
pass did not catch (WooCommerce's own stub package does not fully model
these dynamic/variadic-shaped filter signatures):

1. `rest_request_before_callbacks`'s second argument is the matched
   route's own handler-definition **array**, never a `WP_REST_Server`
   instance (`StoreApiGuard::blockChildQuantityUpdates()` had the wrong
   type hint, causing a real fatal `TypeError` on the very first REST
   request WordPress made after activation).
2. `woocommerce_order_get_items`'s third argument is the array of
   requested item **types** (e.g. `['line_item']`), never a single string
   (`Presentation::filterOrderGetItems()` and
   `Exclusions::filterOrderGetItemsDuringAnalyticsSync()` both had the
   wrong type hint, causing a real fatal `TypeError` on the very first
   order-item read after activation — i.e. every order in the store).
3. `WC_Order_Refund` extends `WC_Abstract_Order`, not `WC_Order` — the
   same two `woocommerce_order_get_items` callbacks above also had too
   narrow a type hint for the `$order` parameter, causing a real fatal
   `TypeError` the first time a refund (rather than an order) was read.

All three are now fixed in the committed code (see the class docblocks in
`src/Woo/StoreApiGuard.php`, `src/Woo/Presentation.php`,
`src/Woo/Exclusions.php`), and every scenario above was **re-run
successfully after each fix**, not merely patched and assumed correct.

### Acceptance validation pass — the six previously-open gaps, now closed

The six items below were the only ones this document's first pass had
marked "not independently verified" against real HTTP/HPOS-on/crash-
injection evidence (the rest of that original list — Store API/Blocks
checkout, HPOS-on, the crash window, multicurrency, VAT, the Analytics
batch action, and the combined-quantity case — is fully superseded by
this section). Environment: a fresh disposable WordPress 7.0.2 +
WooCommerce 11.0.1 + MariaDB 11.8.8 stack (a second, separate instance
stood up specifically for the HPOS case), no ports published to the host
except a single `127.0.0.1`-bound HTTP port for genuine Store API HTTP
requests, this branch's working tree bind-mounted read-only, all resources
disposable and torn down afterward. Real `universal-multicurrency` 1.2.0
(the exact version cited in docs/ARCHITECTURE.md's own spike record) was
loaded read-only for the multicurrency case.

**1. Store API/Blocks HTTP round-trip — PASS (after 3 defects fixed).**
Genuine `POST`/`GET` HTTP requests (no direct PHP method calls) against
`/wc/store/v1/cart` and `/wc/store/v1/checkout` proved: adding a kit
(`kit_qty=3`, `qty_per_kit` 1 and 2) created one parent cart line plus two
correctly-linked, correctly-quantified real child cart lines (verified
against the live WooCommerce session row, not just the API response); a
direct child-cart-item `update-item` request was rejected with a clean
`400 woocommerce_rest_cart_component_quantity_locked`; a parent-quantity
`update-item` request correctly rescaled both children server-side with no
duplicate or orphaned line; a completed checkout produced a real order
with the parent and two children correctly linked by real `order_item_id`,
the parent's `_ucb_kit` snapshot present, correct stock reduction, and
correct order totals/tax — with **zero** hidden child lines visible in
the Store API cart/add-item response, the Store API checkout response, or
the REST v3 `wc/v3/orders` response. Three genuine defects, findable only
by a real HTTP round-trip (not by direct method calls or WC-class mocking),
were found and fixed:
  - `Presentation::stripChildrenFromResponseData()` checked
    `$item['ucb_component']` — a key the real Store API cart-item schema
    never contains (no extension ever wrote it), so **every** genuine
    cart/hydration response leaked hidden child lines verbatim, contrary
    to ADR-0007. Fixed by cross-referencing each response item's own `key`
    against `WC()->cart->get_cart()`, where `CartConstruction` already
    tags hidden children with `MetaKeys::LINE_COMPONENT`; the stripper now
    also recurses one level to reach a Blocks hydration payload's nested
    per-route bodies. Regression test:
    `tests/Unit/PresentationStoreApiStrippingTest.php`.
  - `StoreApiGuard::blockChildQuantityUpdates()` read `WC()->cart` at the
    `rest_request_before_callbacks` hook point, but on a genuine Store API
    HTTP request that property is still `null` there — the Store API's own
    routes only load the session-backed cart from inside their own request
    handler, which runs *after* this filter. The guard's early-return on
    `null === WC()->cart` therefore never blocked anything on a real
    request; it only ever "worked" against a directly-constructed
    `WC_Cart` in earlier, non-HTTP validation. Fixed by calling
    WooCommerce's own public `wc_load_cart()` on demand, then reading
    `WC()->cart->get_cart()[$key]` directly rather than
    `get_cart_item($key)` — the latter reads `$this->cart_contents`
    directly, which core only actually populates from the session inside
    `get_cart()`'s own first call, another gap only a real request
    surfaced.
  - `OrderConstruction::resolveParentLinks()` (pass 2 of parent-link
    resolution) was wired only to `woocommerce_checkout_order_created` — a
    classic-checkout-only hook. A real Store API checkout instead builds
    the order via `OrderController::create_order_from_cart()` and fires a
    differently-named `woocommerce_store_api_checkout_order_created`
    action with the same `WC_Order` argument. Without also listening
    there, every Store API order's child lines were permanently linked by
    the stale cart-item key instead of the parent's real `order_item_id` —
    reproduced live (order 14 in this pass) before the fix, corrected
    live after it (order 14's fix-verification is order 14 itself; see
    order 17 and the HPOS order 13 for post-fix confirmation). Fixed by
    also registering `resolveParentLinks` on
    `woocommerce_store_api_checkout_order_created`. Regression test:
    `tests/Unit/OrderConstructionParentLinkResolutionTest.php` (against
    minimal `WC_Order`/`WC_Order_Item_Product` stand-ins in
    `tests/Fixtures/`, following the same require-on-demand pattern
    `tests/Fixtures/WooCommerceStub.php` already uses).

  All three fixes were re-verified against a fresh container restart (no
  stale opcode/state) with the identical live HTTP sequence, and the full
  PHPUnit/PHPCS/PHPStan suite was re-run clean (72 tests, both PHP 8.1 and
  8.4) after each fix.

**2. HPOS-on fresh-environment verification — PASS.** A second, wholly
separate disposable stack was stood up; HPOS (`custom_order_tables`) was
enabled — table creation + `woocommerce_custom_orders_table_enabled` =
`yes` via WooCommerce's own `CustomOrdersTableController`/
`DataSynchronizer` classes, the same objects its admin screen uses —
**before** any product or order existed. A real Store API checkout then
created the first order ever in that environment, confirmed authoritative
in `wp_wc_orders` (and **absent** from `wp_posts`) — genuinely HPOS-backed,
not legacy-with-sync. It produced correctly-linked parent/child order
lines, correct stock reduction, and a subsequent native partial refund
(restocking enabled) produced the exact derived child refund quantities
and restocked stock by the exact matching amount, with the refund-ordering
assertion reconfirmed under HPOS (stock unchanged at both
`woocommerce_order_partially_refunded` and at priority 5 on
`woocommerce_refund_created` — before UCB's own priority-10 restock
callback — and only changed once the whole call returned). Deactivation
locked the kit (`_ucb_locked` set); reactivation left it locked and
non-purchasable (enforcement is a live plugin-filter concern, correctly
absent while genuinely deactivated in a separate process, and correctly
reapplied on reactivation); an explicit `DeactivationLock::unlock()`
correctly revalidated and unlocked it. No code change was needed for this
case.

**3. Actual M1 refund crash-window injection — PASS, in both storage
modes.** A real, externally-triggered `SIGKILL` (a separate `docker exec
... kill -9 <pid>` process, not a simulated exception) was delivered to
the live `wp eval-file` process running `wc_create_refund(...,
restock_items: true)`, timed via a disposable-environment-only mu-plugin
(never part of UCB) that writes a PID marker file and sleeps on the same
real `woocommerce_refund_created` hook, at priority 5 — strictly before
`Refunds::restockDerivedChildLines()`'s own priority-10 callback — so the
kill genuinely lands after the refund is durable (refunds are durable by
the time `woocommerce_refund_created` fires at all — see V15) and before
that method has run at all, a fortiori before it completes. Reproduced in
both legacy CPT storage and HPOS:
  - The refund survives fully durable with the correct parent and derived
    child refund lines (verified via `$order->get_refunds()` after the
    kill, in a fresh process).
  - Component stock is confirmed **unrestocked** (identical to its
    pre-refund value).
  - The gap is detectable via the plain WooCommerce-native operational
    signal already documented: the order's own notes contain no "Stock
    increased" note anywhere (only the original checkout-time "Stock
    levels reduced" note).
  - Direct source read (not just behavioral testing) of the current
    `src/Woo/Refunds.php` confirms no `_ucb_refund_ops`-style ledger, no
    lock, no transaction, and no reconciliation sweep exist anywhere in
    the plugin (`grep` across `src/` for transaction/lock/ledger/
    reconciliation-sweep patterns finds only `Invalidation::reconcileAll()`
    — an unrelated composition-index rebuild routine, not a refund
    mechanism).
  - **Control, same environment, same kill timing:** an ordinary non-kit
    refund (a plain Component-A-only order), killed at the equivalent point
    relative to *its own* durability and restock (a disposable-only
    mu-plugin hook on `woocommerce_create_refund`, before core's own
    restock call), shows the identical durable-but-unrestocked shape
    natively — confirming this is WooCommerce's own pre-existing limitation
    (the same one S1-G already documented and the product owner accepted),
    not a new one, and it was not "fixed."

**4. Real Analytics batch-action execution — PASS.** WooCommerce's actual
recurring Action Scheduler action (`wc-admin_process_pending_orders_batch`,
confirmed by reading `OrdersScheduler.php`) was exercised for real:
`woocommerce_analytics_scheduled_import` was set to `yes` (deferred-import
mode, which also directly schedules the recurring batch action via
WooCommerce's own `schedule_recurring_batch_processor()`), a fresh kit
order and a fresh standalone (non-kit) Component-A order were created
while deferred, confirmed **absent** from `wc_order_product_lookup`
beforehand, then `wp action-scheduler run` executed the real due actions
to completion (`wc-admin_process_pending_orders_batch` ran and completed
among them). Resulting `wc_order_product_lookup` rows: the kit order has
exactly one row (the parent, correct qty and revenue) with **no row at
all** for either hidden component (not a zero-value row — genuinely
absent, the strongest form of "no pollution"); the standalone order's
Component A purchase is represented normally and unaffected. No code
change was needed for this case.

**5. Combined quantity case — PASS.** A single real cart/order used
`qty_per_kit=2` (Component B) together with `kit_qty` values of 2, 3 and 5
across the Store API and HPOS runs above. In every case: child cart
quantities, persisted child order quantities, stock reduction, and (via a
partial refund of the `kit_qty=3` order, refunding 2 of 3 kits) the
derived partial-refund quantities all matched
`child_refund_qty = (original_child_qty / original_parent_qty) ×
parent_qty_refunded` exactly (e.g. original child quantities 3 and 6,
parent qty 3, 2 kits refunded → derived −2 and −4) and
`qty = kit_qty × qty_per_kit` construction exactly, with stock restocked
by precisely those derived amounts.

**6. VAT and multicurrency — PASS.** VAT: a non-zero-rated 25% "Standard
VAT" tax class/rate was exercised through both a real Store API checkout
and a real classic `WC_Checkout::create_order()` checkout; in both, only
the parent line carried price and tax (e.g. parent total 60.00/tax 15.00
on a 3-kit Store API order; 40.00/10.00 on a classic 2-kit order), every
child line was exactly zero-priced and zero-taxed, and the order's total
tax equaled exactly what was charged. Multicurrency: the real
`universal-multicurrency` 1.2.0 plugin (the same version family cited in
docs/ARCHITECTURE.md's own spike record) was loaded read-only, a second
currency (EUR, manual rate 0.5) was configured through its own
`Settings` API, and a real Store API cart/checkout using its documented
`?currency=EUR` explicit-selection query parameter showed: the kit's
static price converted correctly (20.00 base → 10.00 EUR, `kit_qty=2` →
parent line 20.00 EUR); child lines remained exactly zero in the cart
response, the persisted session cart, and the completed order (`order_id`
17, currency `EUR`, parent total 20.00/tax 5.00, children 0); no
component-price summation occurred at any point (the parent's total is
its own static converted price times quantity, never a sum touching
component prices, live-confirmed by reading the persisted session cart
row directly).

### ADR-0005 direct component-page visit acceptance — the last outstanding M1 item, now closed

The one item the section above explicitly left open ("Canonical-kit-redirect
/ 404 behaviour for direct component-page visits") was independently
verified with genuine HTTP requests (curl against a real running WordPress
front end — not direct PHP method calls, not WP-CLI-only checks) in its own
separate disposable session. Environment: a fresh three-container stack
(MariaDB 10.11.9, WordPress 6.5 + WooCommerce 8.2.0 — the CI matrix's floor
combination — plus a `wp-cli` sidecar sharing the same WordPress
installation) on a dedicated bridge network, no ports published to the
host, this branch's working tree bind-mounted **read-only** into the
WordPress container's plugin directory, `curl` run from a throwaway
container on the same network. All containers, the network, and the named
volume were removed at the end (`docker compose down -v`); `docker ps`/
`docker network ls`/`docker volume ls` confirmed nothing was left over. No
other repository, no DEV, and no production resource was touched.

Real WooCommerce products were created via `wp post create` +
`wp post meta update`, with the exact `ComponentVisibility` meta key
constants (`MetaKeys::PRODUCT_HIDDEN_FROM_CATALOG` = `_ucb_hidden_from_catalog`,
`MetaKeys::PRODUCT_CANONICAL_KIT_ID` = `_ucb_canonical_kit_id`):

1. **Hidden component with a valid canonical kit — PASS.** A component
   (`_ucb_hidden_from_catalog = yes`, `_ucb_canonical_kit_id` = a real,
   published kit product's id) visited at its own product URL returned:
   ```
   HTTP/1.1 301 Moved Permanently
   Location: http://<container>/product/test-kit-alpha/
   X-Redirect-By: WordPress
   ```
   Following that `Location` confirmed the destination was the correct
   kit's own page (`HTTP/1.1 200 OK`, page title "Test Kit Alpha", the
   exact product the meta pointed at).
2. **Hidden component with no canonical kit set — PASS.** A component
   (`_ucb_hidden_from_catalog = yes`, `_ucb_canonical_kit_id` unset —
   confirmed absent via `wp post meta get`, exit code 1) visited at its own
   product URL returned a genuine `set_404()` outcome, not an accidental
   redirect or a PHP error page:
   ```
   HTTP/1.1 404 Not Found
   ```
   with WordPress's own real 404 template body rendered ("Page not found"
   / "Page Not Found" markers present), no `Location` header, and no
   PHP warning/notice/fatal in the response body or the container's error
   log for this request.
3. **Ordinary visible component/product — PASS.** A product with no
   `_ucb_hidden_from_catalog` meta at all returned a normal
   `HTTP/1.1 200 OK`, no redirect, correct page content; re-tested with
   the meta explicitly set to `no` (rather than merely absent) — same
   result, confirming the `'yes' !== ...` comparison in
   `redirectOrFourOhFourDirectVisits()` handles both the absent and
   explicit-`no` forms identically, as the code's own guard clause implies.

**No code change was required.** `src/Woo/ComponentVisibility.php`'s
`redirectOrFourOhFourDirectVisits()` already matched this policy exactly on
review, and all three live sub-cases confirmed it byte-for-byte —
`wp_safe_redirect( $url, 301 )` + `exit` on a valid canonical kit,
`$wp_query->set_404()` + `status_header( 404 )` otherwise, and an early
return for anything not hidden. The full PHPCS/PHPStan/PHPUnit suite (49
PHPCS checks, PHPStan level with 0 errors, 72 PHPUnit tests) was re-run
against the current `feature/m1-fixed-kits-core` head and remained green,
unchanged from the count already recorded above.

## Repository / CI

PHPCS (WordPress-Extra + WooCommerce-Core + WordPress.WP.I18n), PHPStan
(level 5, WordPress + WooCommerce stubs), and the full PHPUnit suite (72
tests, up from 67 — three new regression tests added by this acceptance
pass) all pass clean under both PHP 8.1 and PHP 8.4, from a fresh clone,
via the same Docker-based commands M0's CI workflow runs. `composer run
package` continues to produce a distributable ZIP excluding every dev-only
path.

## What remains before any real deployment

Canonical-kit-redirect / 404 behaviour for direct component-page visits
has since been independently verified live (see "ADR-0005 direct
component-page visit acceptance" above) — no code change was needed.

**The promotions-plugin hidden-child exclusion (separate repository) is
now closed** — `mp-commerce-promotions` has independently designed,
implemented, and live-validated its own accepted fix (that repository's
ADR-0001, PR #6, merged commit `9d9d8a18ccedd793fb77d7b8da803d01dd3be8d5`
on its `main` branch); see `docs/adr/0008-promotions-plugin-integration-closure.md`
in this repository for the reconciliation record. **Deployment-readiness
note, not a UCB blocker:** that commit is not yet in a tagged release of
that plugin — its latest tag, `v0.5.4`, predates the fix by 14 commits —
so a site must currently install `mp-commerce-promotions` from `main`
(or a later release once one is cut) to get the exclusion. This plugin's
own kit-level default sitewide-campaign exclusion (Part 1 of the
promotions-plugin milestone, distinct from the hidden-child exclusion) has
no evidence of implementation as of this reconciliation and remains open
in that repository's own scope.

What remains: the host MU-plugin guard (separate repository); the
fulfillment-plugin parent-skip change (separate repository); the
promotions-plugin's own Part-1 default-campaign-exclusion (separate
repository, open — see above); a real acceptance/QA pass against a
staging store; a security review; and, per the architecture doc's own
governance section, a separate closure/acceptance decision — this
document is an implementation and validation record, not a release or
deployment authorization.
