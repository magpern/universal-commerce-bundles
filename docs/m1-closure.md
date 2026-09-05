# M1 — Fixed Kits Core: Implementation & Validation Record

Status: implementation complete, validated (automated + live), **not** released.
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

### Not independently verified in this M1 pass (stated plainly, not glossed over)

- **Store API/Blocks checkout end-to-end via a real HTTP request.** Cart
  construction and the Store API guard's *logic* were verified (the guard
  directly, cart construction via the same real `WC_Cart` API the Store
  API itself calls), but a full HTTP round-trip through the Store API
  checkout endpoint specifically was not performed — only classic
  `WC_Checkout::create_order()` was exercised end-to-end for order
  construction.
- **HPOS (custom order tables) mode toggled on.** The disposable
  environment had already placed legacy-storage orders by the time this
  was attempted, and WooCommerce correctly refuses to switch the
  authoritative storage table while orders are unsynced; re-attempting
  this against a fresh environment before a real deployment is
  recommended. All order interaction in this codebase goes through the
  standard `WC_Order`/`get_items()`/meta API (never a direct table query),
  which is the storage-mode-portable way to write this — but the toggle-on
  mode itself was not independently re-confirmed live, matching S1-D's own
  stated limitation on this same point.
- **The refund crash-window test** (a real process kill between the
  refund's save and the post-save restock action completing, as S1-G
  performed) — the *ordering* half of this property was live-confirmed
  (above); the *crash-injection* half was not repeated in this pass, given
  time constraints. The design is unchanged from S1-G's already-proven
  mechanism (same two hooks, same responsibility split), so no new risk is
  introduced, but this specific property was not re-demonstrated with an
  injected kill in M1's own validation.
- **Multicurrency**, **VAT-specific correctness on a non-zero-rated tax
  class**, **Analytics batch action end-to-end** (the scope-flag mechanism
  itself was verified directly against the real bracketing pattern in
  code, not against a real fired `wc-admin_process_pending_orders_batch`
  Action Scheduler run), **`qty_per_kit > 1` combined with `kit_qty > 1`
  in the same cart** (qty-per-kit > 1 alone, and kit_qty > 1 alone, were
  each verified; not the combination), and **canonical-kit-redirect / 404
  behaviour for direct component-page visits** were not exercised in this
  pass.

## Repository / CI

PHPCS (WordPress-Extra + WooCommerce-Core + WordPress.WP.I18n), PHPStan
(level 5, WordPress + WooCommerce stubs), and the full PHPUnit suite (67
tests) all pass clean under both PHP 8.1 and PHP 8.4, from a fresh clone,
via the same Docker-based commands M0's CI workflow runs. `composer run
package` continues to produce a distributable ZIP excluding every dev-only
path.

## What remains before any real deployment

Everything listed under "Not independently verified" above; the host
MU-plugin guard (separate repository); the fulfillment-plugin parent-skip
change (separate repository); the promotions-plugin exclusion (separate
repository); a real acceptance/QA pass against a staging store; a security
review; and, per the architecture doc's own governance section, a
separate closure/acceptance decision — this document is an implementation
and validation record, not a release or deployment authorization.
