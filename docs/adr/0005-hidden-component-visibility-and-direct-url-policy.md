# ADR-0005: Hidden-component visibility and direct-URL policy

## Status

Accepted — unaffected by the Architecture A → B decision (this ADR governs
the **catalogue product page** for a component, independent of ADR-0002's
order/cart *line-item* mechanism).

## Context

A kit's components are typically not meant to be discoverable or
purchasable as standalone catalogue products — the whole point of a fixed
kit is that its components are sold only as part of it. But a component is
still an ordinary WooCommerce product underneath (it needs a real product
row to be stock-managed, reserved, reduced and restored — see ADR-0002), so
its catalogue-facing behaviour needs an explicit, reversible policy rather
than being deleted or hidden by accident.

A component can also belong to more than one kit, which raises the
question of what a direct visit to that component's own product page
should do.

## Decision

| Concern | Meta | Default |
|---|---|---|
| Not purchasable standalone | `_ucb_not_purchasable_alone` | yes |
| Excluded from catalog + search | `_ucb_hidden_from_catalog` | yes |
| Excluded from sitemap | derived | yes |
| Excluded from REST / Store API listings | derived | yes |
| Direct URL target | `_ucb_canonical_kit_id` | unset |
| Internal availability (kit rendering, admin, fulfillment) | always visible | — |

**Multiple parent kits sharing one component.** A direct visit to the
component's own product page redirects only to an explicitly selected
`_ucb_canonical_kit_id`. If unset, the request returns **404**. There is no
automatic inference (e.g. "redirect to whichever kit was created first") —
an admin must make the choice explicit.

**All switches are reversible.** Every default above is a per-product
setting, not a hard-coded behaviour, so deciding to sell a component
standalone later — or to change which kit a component's direct URL points
to — is a configuration change, not a code change or data migration.

## Consequences

- A component remains fully visible and usable inside every internal
  context that needs it (kit composition editing, admin order views,
  fulfillment picking lists) regardless of these catalogue-visibility
  settings — this ADR only governs *customer-facing catalogue discovery*,
  never internal tooling.
- Because this policy is independent of ADR-0002's stock-lifecycle
  architecture, it required no rework when Architecture B was selected —
  it is exactly as valid for a component that is now a real order/cart
  line item as it was under the original single-line design.

## Rejected alternatives

- **Deleting or unpublishing components outright.** Rejected — a
  component must remain a normal, stock-managed WooCommerce product for
  reservation/reduction/restoration to work at all (ADR-0002); deleting or
  unpublishing it would also invalidate every kit it belongs to (see
  `docs/ARCHITECTURE.md`, "Derived availability and invalidation").
- **Inferring the canonical kit automatically** (e.g. "most recently
  created," "cheapest kit," "first kit alphabetically") for the
  multiple-parent-kit redirect case. Rejected — an automatic rule is
  fragile to future catalogue changes and hides an editorial decision an
  admin should make explicitly; 404 is the safe default in the absence of
  that explicit choice.
