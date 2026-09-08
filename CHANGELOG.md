# Changelog

All notable changes to Universal Commerce Bundles are documented here.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [0.2.1] - 2026-09-08

### Fixed

- Correct Settings diagnostics wording in the `0.2.0` release notes: host-guard line is **Guard detection unavailable** (no `has_action( 'ucb_runtime_ready' )` listener probe). Runtime behaviour was already correct in `0.2.0`; this is a documentation-only hotfix.

## [0.2.0] - 2026-09-08

### Added

- WooCommerce → Bundles read-only kit overview (paginated, searchable, sortable) with dual catalog-exposure and operational-sellability fields.
- WooCommerce → Bundles → Settings read-only diagnostics (bootstrap/runtime contract, WooCommerce, HPOS, and host-guard line **Guard detection unavailable** — UCB documents no portable public guard-owned symbol; no `has_action` inference).
- Read-only `KitAvailability::validateLive()` / `assessSellability()` / `assessOverview()` so admin presentation reuses the authoritative availability graph without writing product meta.

### Notes

- Implements the frozen M2 plan in `docs/m2-admin-usability-plan.md`. No Architecture B contract changes, no migration, no MU-guard / fulfillment / promotions changes. Not a production enablement authorization.

## [0.1.0] - 2026-09-06

### Added

- Architecture B fixed-kit commerce: priced kit parent line plus real zero-priced component child cart/order lines.
- Persisted markers `_ucb_kit` (parent) and `_ucb_component` (children) for cross-plugin readers.
- Request-local `ucb_runtime_ready` capability signal for the independent host MU safety guard.
- Classic cart and Store API kit construction, refund/restock paths, and admin kit configuration (M1).

### Notes

- First tagged release. Intended for coordinated DEV acceptance with fulfillment parent-skip and promotions component exclusion. Not a production enablement authorization.
