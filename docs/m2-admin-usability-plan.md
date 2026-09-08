# M2 — Admin usability (Bundles overview + diagnostics)

Status: **implemented in `0.2.0`** (see `docs/m2-closure.md`). This plan was
frozen by the documentation-only merge before implementation. Architecture B
contracts, host MU-guard behaviour, fulfillment / promotions contracts, and
runtime purchasability maths remain unchanged.

Baseline: released UCB `v0.1.0` (Architecture B). This milestone must not
change Architecture B contracts, host MU-guard behaviour, fulfillment or
promotions contracts, product data, or runtime purchasability maths.

Proposed implementation version: **`0.2.0`** (minor SemVer feature). This
planning document does not bump the plugin version.

No ADR accompanies this milestone: governance requires superseding ADRs for
accepted identity / cross-repo contracts; an operator admin surface does
not change meta keys, snapshots, `_ucb_kit` / `_ucb_component`, or
`ucb_runtime_ready`.

---

## Source forensics (v0.1.0)

| Concern | Evidence |
|---|---|
| Bootstrap | `universal-commerce-bundles.php` → `src/Infrastructure/Plugin.php` → WooCommerce gate → `src/Infrastructure/KitModule.php` → `do_action( 'ucb_runtime_ready', … )` |
| Admin today | Product-data tab only: `src/Admin/KitDataPanel.php` — no `admin_menu`, no custom capabilities |
| Kit identity | `src/Domain/MetaKeys.php`: `PRODUCT_IS_KIT` = `_ucb_is_kit`; composition `_ucb_composition`; lock `_ucb_locked` |
| Authoritative purchasability | `src/Woo/KitAvailability.php`: lock → `CompositionValidator` / `ValidationResult` → `AvailabilityCalculator` (backorders / unmanaged excluded from the minimum) |
| Kit listing pattern | `src/Woo/DeactivationLock.php` / `src/Woo/Invalidation.php`: `meta_key=_ucb_is_kit`, `meta_value=yes`, `post_status=any` |
| Settings / logging | None — no settings API options; no logger usage in `src/` |
| Unlock | `src/Woo/DeactivationLock::unlock()` exists and is **unwired** to any UI — Settings must **not** add unlock / bypass |
| Tests | PHPUnit 10 + Brain\Monkey (`phpunit.xml.dist`); HPOS declaration unit test + live checklist in `docs/m1-closure.md` |
| Contracts to preserve | Architecture B parent `_ucb_kit` / child `_ucb_component`; fulfillment skips `_ucb_kit`; promotions exclude `_ucb_component`; host MU owns readiness enforcement |

Composition row shape (`ComponentRequirement`): `stock_managed_id`,
`product_id`, `variation_id`, `qty_per_kit`.

`ValidationResult` already carries structured invalidity reasons suitable
for admin presentation (`structurallyInvalid`, `missingComponentIds`,
`unpublishedComponentIds`, `mixedTaxClasses`).

`_ucb_composition_valid` is a **display hint only** and must never be
trusted for overview sellability.

Calling `KitAvailability::validate()` today also refreshes the cached
validity hint (a product-meta write). Overview / diagnostics assessment
must use a **read-only** live validation path that does not write meta.

---

## Frozen decisions

### 1. Menu and capabilities

- WooCommerce → **Bundles** (overview).
- WooCommerce → **Bundles → Settings** (diagnostics only).
- Capability: `manage_woocommerce` on every page and action.
- Menu slugs: `ucb-bundles` / `ucb-bundles-settings`.
- Register only from `KitModule` after WooCommerce requirements pass.
- No admin surface when WooCommerce requirements are not met.
- No custom capabilities.

### 2. Authoritative availability path

- Single decision source: the wired `KitAvailability` graph (same as
  checkout filters).
- Expose the wired instance from `KitModule` (accessor) so admin code does
  not construct a second calculator.
- Presentation mapper produces two **independent** fields — catalog
  exposure and operational sellability (+ blocked reason) — from lock
  flag + live `ValidationResult` + live availability quantity / component
  availability facts + product post/catalog facts.
- **No second stock / purchasability formula.**
- Do not trust `_ucb_composition_valid`.

### 3. Overview is read-only

- Read-only list plus ordinary links to edit kit / component products
  (`post.php?post={id}&action=edit`).
- No bulk edit, inline composition edit, unlock, stock override, or any
  meta / cart / stock writes from overview or Settings.

### 4. Dual fields: catalog exposure and operational sellability

Do **not** use one mutually exclusive
`Hidden / Blocked / Backorder-supported / Active` label. Catalog exposure
and sellability are independent: a catalog-hidden product can still be
published and purchasable via direct URL, and a hidden locked / invalid
kit must still surface the operational fault.

**Overview columns**

- Kit name (+ edit link)
- Product ID
- SKU
- Catalog exposure
- Current / regular price
- Component summary (name, `qty_per_kit`, edit link)
- Operational sellability
- Blocked reason (empty unless sellability is Blocked)

**Catalog exposure** (from `post_status` + Woo catalog visibility;
independent of stock / lock):

| Label | Rule |
|---|---|
| **Published** | `post_status = publish` and catalog visibility includes shop/search |
| **Catalog-hidden** | `post_status = publish` but catalog visibility is hidden / excludes shop and search |
| **Draft** | `post_status = draft` |
| **Pending** | `post_status = pending` |
| **Private** | `post_status = private` |

Any other non-publish `post_status` uses the raw status slug as the
exposure label (escaped). Catalog exposure never replaces or suppresses
operational sellability.

**Operational sellability** (from `KitAvailability` only — **not** gated
on catalog exposure):

1. **Blocked** — not purchasable (lock, invalid composition, or
   insufficient non-backorder stock).
2. **Backorder-supported** — purchasable, and availability is unconstrained
   solely because every managed component is backorder-enabled or
   non-stock-managed (`AvailabilityCalculator` → `PHP_INT_MAX`).
3. **Active** — purchasable and constrained by positive on-hand component
   stock (`calculate() > 0` and finite).

Examples: healthy catalog-hidden kit → `Catalog-hidden · Active`; locked
catalog-hidden fixture → `Catalog-hidden · Blocked: Safety lock`. Draft /
private kits keep Draft / Private exposure while sellability may still
read Active / Blocked for operator diagnostics — never present them as
catalog-Published listings solely because components are in stock.

**Blocked reason precedence** (deterministic, first match):

1. `safety_lock` — `_ucb_locked = yes`
2. `invalid_configuration` — structurally invalid composition
3. `missing_component` — non-empty missing component ids
4. `component_not_purchasable` — non-empty unpublished component ids
5. `invalid_configuration` — mixed tax classes
6. `insufficient_component_stock` — valid composition but stock formula
   yields `0`

Human-readable, translated labels are shown in the UI. Host MU readiness
is **not** an overview row field; storefront purchasability can still be
vetoed by the host MU at priority 999.

### 5. Settings / diagnostics (include and reject)

**Include (read-only):**

- UCB version.
- **“UCB bootstrap completed / runtime contract available”** when
  `KitModule` has registered and the readiness payload *shape* (plugin
  version, contract version, snapshot versions) is known from code
  constants. Do **not** claim `ucb_runtime_ready` “emitted” unless the
  implementation deliberately records emission during the same request.
- WooCommerce active + installed version vs minimum `8.2`.
- HPOS state via WooCommerce API when available; else
  “unknown / unavailable”.
- Host-guard detection only if a stable public symbol can be safely
  probed (informational): no include/require, no dependency, no failure
  if absent, no enforcement / bypass / unlock / configuration.

**Reject (explicit non-settings):**

- Global UCB enable / disable
- Opt-in diagnostic logging
- Unlock / guard bypass controls
- Bundle composition editor
- Stock overrides
- Fulfillment or promotion controls
- Environment / deployment / MU-plugin configuration

### 6. Query / performance

- Paginated product query: kits only (`_ucb_is_kit = yes`),
  `post_status => any`, searchable by title / SKU, sortable where cheap.
- Compute operational state **only for the current page** of kits.
- No new persistent index for M2; reverse index remains for invalidation
  only.

### 7. Caching

- **None.** Live decision points already mandated; overview follows that.

### 8. Migration / version

- **No data migration.** Overview reads existing meta only.
- Implementation ships as **`0.2.0`**.

### 9. Non-goals

- Second stock / purchasability engine
- Composition editor on Settings
- Unlock UI
- MU / fulfillment / promotions changes
- Writable global kill-switch
- Production enablement, tags, GitHub Releases, or deployment from this
  milestone’s implementation PR alone

### 10. Staged implementation work packages

1. `KitModule` accessor + read-only assessment API on `KitAvailability` +
   Admin menu / list table registration.
2. Dual-field presentation mapper + translated reason labels.
3. Settings diagnostics page (read-only).
4. Unit / structural tests covering acceptance cases 1–12.
5. Version bump `0.2.0`, CHANGELOG, `docs/m2-closure.md` (implementation
   PR — not this freeze).

---

## Required acceptance coverage

Implementation must prove all of the following (self-contained; do not
defer to chat history):

1. Only kit products appear in the overview.
2. Published, purchasable kits are labelled operational sellability
   `Active` (with catalog exposure `Published` when listed in catalog).
3. Catalog-hidden / draft / private kits show the correct **catalog
   exposure** label and are never falsely presented as catalog-published
   customer listings solely because components are in stock; sellability
   is still computed independently (e.g. `Catalog-hidden · Active` or
   `Draft · Blocked: …`).
4. A kit blocked by insufficient non-backorder component stock is
   labelled `Blocked` with the correct reason
   (`insufficient_component_stock`).
5. Backorder-supported components follow the established UCB rule
   (operational sellability `Backorder-supported` when the calculator is
   unconstrained solely by backorder / unmanaged exclusion).
6. Missing, deleted, invalid, and non-purchasable components produce safe
   deterministic states; no admin fatal errors.
7. Component quantities and edit links are accurate.
8. Pagination / search / sort do not expose non-kit products or leak
   unescaped data.
9. Capabilities prevent unauthorized access.
10. The overview works with HPOS enabled.
11. The overview does not alter cart, stock, product metadata,
    fulfillment, promotions, or the MU guard (no product / cart / stock /
    meta writes from the overview or diagnostics pages).
12. All existing UCB tests remain green.

---

## Architecture boundaries (must preserve)

- Architecture B: priced kit parent + hidden zero-priced component lines.
- Fulfillment: only `_ucb_kit` parent lines are skipped.
- Promotions: only cart items with non-empty `_ucb_component` are
  excluded; kit parents remain promotion-eligible.
- Independent MU guard: UCB emits `ucb_runtime_ready`; the host decides
  enforcement. UCB must not gain a guard bypass mechanism.
