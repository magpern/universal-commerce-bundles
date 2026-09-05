# Changelog

All notable changes to Universal Commerce Bundles are documented here.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [0.1.0] - 2026-09-06

### Added

- Architecture B fixed-kit commerce: priced kit parent line plus real zero-priced component child cart/order lines.
- Persisted markers `_ucb_kit` (parent) and `_ucb_component` (children) for cross-plugin readers.
- Request-local `ucb_runtime_ready` capability signal for the independent host MU safety guard.
- Classic cart and Store API kit construction, refund/restock paths, and admin kit configuration (M1).

### Notes

- First tagged release. Intended for coordinated DEV acceptance with fulfillment parent-skip and promotions component exclusion. Not a production enablement authorization.
