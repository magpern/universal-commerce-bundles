# ADR-0007: Cross-cutting cart/order-line exclusion contract

## Status

Ready for final review — accepted upon merge of the documentation-freeze
pull request, not before. New ADR, introduced by spike S1-D's findings, not
present in the original six-ADR register.

## Context

Architecture B's core property (ADR-0002) — that a kit's components are
real WooCommerce order/cart line items — has one systemic consequence:
**any consumer that iterates real order/cart lines can now see hidden kit
components**, and several native or third-party subsystems treat *presence
of a line* as meaning "the customer selected this," which is false for a
hidden, zero-priced child line.

Spike S1-D found and closed four such leaks, each through a different real
extension seam, none requiring WooCommerce core to be patched:

| Consumer | Leak found live | Real seam used to close it |
|---|---|---|
| A companion promotions plugin's condition engine | product/category/quantity conditions satisfied by a hidden child alone, with no genuine standalone purchase of that component in the cart | a new `is_kit_component` field added to the plugin's cart-context projection, checked at each condition's matching point |
| WooCommerce core coupon eligibility | a product-restricted coupon — including a **free-shipping** coupon — validated as eligible off the hidden child alone, unlocking a real customer benefit with no genuine standalone purchase | `woocommerce_coupon_get_items_to_validate` (a real, documented core filter) |
| WooCommerce core shipping | cart weight, dimensions, and shipping class all double-counted the hidden children on top of the parent | the existing price-zeroing cart-totals hook, extended to also zero weight/dimensions/shipping-class on the in-cart product clone |
| WooCommerce core Analytics | hidden children's units-sold figure, and (via allocated shipping) their gross-revenue figure, were non-zero, even though net revenue was correctly zero | a scope-flag order-items filter, bracketed narrowly around the real recurring Analytics sync batch action |

A fifth, closely related leak — the Cart block's server-rendered hydration
payload bypassing the normal REST-level visibility filter — is closed by
the same pattern and is documented in ADR-0003's presentation contract
rather than duplicated here, since it concerns *where* child lines are
hidden from view rather than whether they are treated as a genuine
selection by a downstream consumer.

Each fix was proven, live, both to close the leak **and** to leave a
genuine standalone purchase of the same product, and a sitewide/eligibility
discount reading cart subtotal, unaffected — over-exclusion was checked,
not assumed away. A first attempt at a general exclusion mechanism — a
blanket "hide order items unless in wp-admin" filter — was tried and found,
live, to silently break a companion fulfillment plugin's own raw
`get_items()` calls (which run in non-admin contexts such as a CLI process)
had it shipped that way; this is exactly the shape of mistake an earlier
verified finding (`docs/ARCHITECTURE.md` §"V2") already warned about for a
differently-named core filter ("too broad to use safely").

## Decision

**The rule:** a line item carrying the component marker (`_ucb_component`
order-item meta) or the hidden child cart-item flag must never be treated
as a genuine, independent customer selection by any cart/order/coupon/
shipping/analytics/promotion consumer — native WooCommerce or
third-party — unless that consumer's job is specifically stock/
fulfillment/refund reconciliation, where the real line must remain visible
and real (ADR-0002, ADR-0004).

**The pattern, repeated per consumer, and to be repeated for any future
consumer:**

1. Find the real extension seam the specific consumer already offers (a
   documented filter, a scoped hook, a narrow data-projection point) —
   never patch WooCommerce core itself.
2. Add a narrow, precisely-scoped guard keyed on the child-line marker at
   that exact seam — never a blanket condition like "not in admin" or
   "not a REST request," both of which were shown, live, to
   over-exclude real, legitimate consumers of the same underlying data.
3. Verify, live, that:
   - the leak is closed for the kit-only case (no genuine standalone
     purchase of the component present);
   - a **genuine** standalone purchase of the same product still correctly
     triggers the consumer's normal behaviour (no over-exclusion);
   - a sitewide or subtotal-based rule (which reads aggregate cart state,
     not per-item rows) is unaffected.

**Consumers covered at this freeze**, and their exact seam:

| Consumer | Seam | What it does |
|---|---|---|
| Promotions condition engine | cart-context projection + cart-item matcher edits | excludes hidden children from product/category/quantity condition matching |
| WooCommerce core coupon eligibility | `woocommerce_coupon_get_items_to_validate` | excludes hidden children from product/category coupon-restriction validation |
| WooCommerce core shipping | the existing cart-totals zeroing hook, extended | prevents double-counted weight, dimensions and shipping-class bucketing |
| WooCommerce core Analytics | a scope-flag order-items filter around the recurring Analytics sync action | prevents units-sold/gross-revenue pollution from hidden children |
| Cart block server-render (documented in ADR-0003) | WooCommerce's own back-compat hydration filter | prevents child-line exposure in the first-paint hydration payload |

## Consequences

- Every future third-party integration this plugin adds (a different
  promotions engine, a subscriptions extension, a loyalty/points plugin, a
  different analytics package) must be evaluated against the same
  question — does it iterate real line items in a way that could treat a
  hidden child as a genuine customer selection? — before being declared
  kit-compatible. This ADR is the standing checklist for that evaluation,
  not a one-time fix list.
- A known, explicitly accepted residual gap remains for a shipping method
  whose cost formula is keyed on cart-line *quantity* (rather than weight)
  — the zeroing-hook approach cannot fix that specific configuration, since
  children remain real, separate cart lines with real quantities by
  design. A correct fix would filter child items out of the shipping
  *package's* contents, not implemented; it only matters if a real
  deployment specifically configures a quantity-based rate.
- This contract exists *because* Architecture B was selected (ADR-0002).
  Architecture A never made this class of leak possible, since components
  were never real line items under that design — this is the one place
  where Architecture B trades a category of new risk for its much larger
  reduction in custom stock-lifecycle code, and this ADR is the mitigation
  for that trade.

## Rejected alternatives

- **A blanket "hide order items outside wp-admin" filter**, as a single
  general mechanism instead of per-consumer scoped guards. Rejected,
  live-disproven: it silently deleted real component rows from a
  companion fulfillment plugin's own raw `get_items()` calls whenever
  those calls ran outside a normal web-admin request (e.g. a CLI process),
  which would have broken fulfillment intake in production had it shipped.
- **A new promotions condition *type*** for kit-awareness, instead of a
  per-row exclusion flag on existing condition types. Rejected — an
  unrecognised condition type makes the whole promotion ineligible in the
  companion promotions plugin's evaluator (a verified finding,
  `docs/ARCHITECTURE.md` §"V7"), which is a materially worse failure mode
  than the narrow, precedented per-row flag actually adopted.
