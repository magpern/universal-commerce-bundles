# M2 — Admin usability: Implementation & Validation Record

Status: implementation complete for version **0.2.0**, validated with the
repository PHPUnit / PHPCS / PHPStan / package suite. **Not** tagged,
released, or deployed as part of this closure record.

## What M2 implements

Per `docs/m2-admin-usability-plan.md` on top of Architecture B / `v0.1.0`:

1. **WooCommerce → Bundles** — read-only paginated / searchable / sortable
   overview of products with `_ucb_is_kit = yes`
   (`src/Admin/BundlesAdmin.php`, `src/Admin/BundlesListTable.php`,
   `src/Woo/KitOverviewData.php`).
2. **Dual fields** — catalog exposure and operational sellability via
   `src/Engine/KitOperationalAssessor.php`, fed by
   `KitAvailability::assessOverview()` / `assessSellability()`.
3. **Read-only assessment** — `KitAvailability::validateLive()` does not
   refresh `_ucb_composition_valid`; overview never writes product meta,
   cart, or stock.
4. **WooCommerce → Bundles → Settings** — diagnostics only
   (`src/Woo/AdminDiagnostics.php`): version, bootstrap/runtime-contract
   availability (without claiming `ucb_runtime_ready` emission), WooCommerce
   version vs minimum, HPOS state, and host-guard line
   `Guard detection unavailable` (UCB documents no portable public
   guard-owned symbol; diagnostics must not infer from
   `has_action( 'ucb_runtime_ready' )`).
5. Capability: `manage_woocommerce`. Registration only through
   `KitModule::register()` after WooCommerce requirements pass.

## Explicit non-changes

- No Architecture B / `_ucb_kit` / `_ucb_component` / snapshot changes.
- No host MU guard, fulfillment, or promotions repository changes.
- No migration, unlock UI, global kill-switch, logging toggle, or
  composition editor on Settings.
- No tag, GitHub Release, or deployment.

## Automated evidence

- Unit: `tests/Unit/KitOperationalAssessorTest.php`,
  `KitAvailabilityAdminAssessmentTest.php`, `AdminDiagnosticsTest.php`,
  `BundlesAdminMenuTest.php`.
- Structural: `tests/Structural/M2AdminSurfaceStructuralTest.php` (+ existing
  `WooConfinementTest`).
- HPOS declaration: existing `CompatibilityHposDeclarationTest` remains
  green; overview is product-meta based (orders storage mode does not
  change kit listing).

## Live / disposable HPOS checklist (overview)

Use an isolated disposable WordPress + WooCommerce stack (not served DEV):

1. Enable HPOS via WooCommerce → Settings → Advanced → Features.
2. Confirm at least one kit (`_ucb_is_kit = yes`) exists in varied states
   (published, catalog-hidden, draft/private, locked, insufficient stock,
   backorder-only components).
3. Open **WooCommerce → Bundles**: only kits listed; dual labels correct;
   component qty / edit links correct; no fatals.
4. Open **Bundles → Settings**: diagnostics render; HPOS shows enabled.
5. Confirm product meta / stock / cart unchanged after visiting both pages
   (no unintended writes).

## Acceptance mapping

| # | Criterion | Evidence |
|---|---|---|
| 1 | Only kits in overview | `KitOverviewData` meta_query `_ucb_is_kit=yes` + unit/structural |
| 2 | Published + Active | Assessor + `KitOperationalAssessorTest` |
| 3 | Catalog-hidden / draft / private dual fields | Assessor tests |
| 4 | Insufficient stock → Blocked reason | Assessor test |
| 5 | Backorder-supported rule | Assessor + calculator test |
| 6 | Missing/invalid safe states | Assessor reason tests |
| 7 | Component qty / links | `KitOverviewData::rowFor` |
| 8 | Search/pagination/escape | List table escaping + kit-only query |
| 9 | Capability gate | `BundlesAdminMenuTest`, unauthorized `wp_die` test |
| 10 | HPOS | Declaration test + live checklist above |
| 11 | No meta/cart/stock writes | `validateLive` never `update_post_meta` test |
| 12 | Existing suite green | `composer check` / CI |

## Version

Plugin header + `UCB_PLUGIN_VERSION` = `0.2.0`. See `CHANGELOG.md`.
