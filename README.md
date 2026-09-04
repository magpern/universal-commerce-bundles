# Universal Commerce Bundles

A generic WooCommerce plugin architecture for **fixed product kits** — simple,
statically-priced "bundle" products composed of a fixed set of components,
sold as one SKU, picked to order.

## Status

**Documentation frozen, pre-implementation.** This repository currently
contains only the frozen architecture plan, seven Architecture Decision
Records, and the live-executed spike evidence that led to them. No plugin
code has been written yet. Nothing in this repository authorizes
implementation on its own — see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
for the governance workflow this project follows.

## What this is

Two competing designs (referred to throughout as **Architecture A** and
**Architecture B**) were designed, then proven or disproven by *live
execution* against a real, disposable WordPress + WooCommerce + MariaDB
stack — not by reading source code alone. Architecture B — a priced parent
kit line plus hidden, zero-priced, **real** WooCommerce child order lines per
component — was selected as the target architecture because it delegates
stock reservation, reduction and restoration entirely to WooCommerce core,
removing the need for a custom stock-transaction engine. Architecture A (a
single parent line plus a custom reservation writer, transactional journal,
outbox and crash-recovery subsystem) was fully designed and proven viable on
its own terms, then set aside in favour of the simpler, lower-risk design.
Both are documented here: Architecture A as evidence for a rejected
alternative, Architecture B as the accepted target.

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — the full architecture plan:
  context, settled decisions, verified WooCommerce/WordPress core findings,
  the Architecture A vs. B comparison, the M0/M1 design, the cross-plugin
  contracts, and the acceptance-test coverage.
- [`docs/adr/`](docs/adr) — the seven Architecture Decision Records, one per
  file, each in standard ADR format (Context / Decision / Consequences /
  Rejected alternatives), status **Accepted**.
- [`docs/spikes/`](docs/spikes) — the live-executed spike evidence: test
  methodology, exact commands, captured output, and verdicts for every design
  question the architecture depended on.

## Why "generic"

This design was developed against a real store's requirements but contains
no store-specific logic. Every example in this documentation uses a generic
"Starter Kit" composed of generic "Component A / B / C" placeholders — the
technical shape of every example (multi-unit kits, components shared across
kits, backorder handling, partial refunds, etc.) is preserved exactly; only
the naming was made portfolio-agnostic before publishing.

## Licence

[GPL-2.0-or-later](LICENSE), matching WordPress/WooCommerce's own licensing
and the plugin's own M0 licensing decision (see ADR register in
`docs/ARCHITECTURE.md`).

## Package identity

Composer package: `magpern/universal-commerce-bundles`.
