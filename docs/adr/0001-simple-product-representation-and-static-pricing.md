# ADR-0001: Simple-product representation and static pricing

## Status

Accepted.

## Context

A fixed kit needs a WooCommerce product representation. WooCommerce ships
several existing "grouped product" patterns via third-party bundle/composite
product types, but those types carry substantial custom rendering, cart and
pricing machinery that this project does not need — a fixed kit is a single,
statically-priced SKU, not a customer-configurable bundle. Introducing a
custom product type would also touch many WooCommerce/WordPress core
extension points that natively assume `simple`, `variable`, and their
sibling core types.

A companion multicurrency plugin filters WooCommerce's product-price
getters, guarded by re-entrancy protection. Any product type that summed
child component prices at runtime inside its own price getter would observe
*base-currency* component prices rather than the resolved currency's,
because of that re-entrancy guard — a currency bug baked into any
"computed" pricing mode.

## Decision

A fixed kit is a published, ordinary WooCommerce **`simple`** product with:

- its own SKU and a normal static `_regular_price`/`_sale_price`;
- a validated composition metadata field (component product/variation ids,
  quantity per kit);
- no custom WooCommerce product type;
- no runtime summation of component prices — the kit's price is
  authoritative and static, set directly on the product, exactly like any
  other simple product;
- a one-shot admin **"Calculate suggested price from components"** action
  that writes an ordinary product price only when explicitly clicked,
  recording the calculation basis (component prices and percentage) at the
  moment of acceptance. An admin notice appears later if current component
  prices drift from that recorded basis. Live prices are never
  auto-recalculated on the customer-facing side.

## Consequences

- Every WooCommerce/WordPress core extension point that already understands
  `simple` products (search, catalog, sitemaps, REST, multicurrency, tax,
  shipping, order totals) works for a kit with zero special-casing on the
  product-type dimension.
- Multicurrency conversion works correctly and simply: the kit's price is
  filtered by the multicurrency plugin exactly as any other simple
  product's would be, with no re-entrancy hazard, because there is no
  runtime summation to trigger it.
- The kit's price can silently drift from the sum of its components' prices
  over time; the one-shot "suggested price" action plus a drift notice is
  the mitigation, not an automatic recalculation, so that a merchandiser's
  explicit pricing decision (e.g. "10% off the sum") is never silently
  overwritten by a later component price change.
- This decision does not depend on, and is unaffected by, the later choice
  between Architecture A and Architecture B for stock lifecycle (ADR-0002)
  — the kit product's own representation is identical either way; only the
  order/cart *line item* structure differs.

## Rejected alternatives

- **A custom WooCommerce product type** (mirroring third-party bundle/
  composite plugins). Rejected: pulls in a large amount of custom cart,
  order-line, and pricing-integration surface that a fixed, statically-
  priced kit does not need, and would need to be re-integrated with every
  WooCommerce core and third-party extension point this project already
  gets for free from `simple`.
- **Runtime price summation of components** (a "computed" pricing mode).
  Rejected: breaks correctly under multicurrency due to the price-getter
  re-entrancy guard (verified finding, see `docs/ARCHITECTURE.md` §"V8"),
  and removes a merchant's ability to set an independent kit price (e.g. a
  discount percentage against list prices) without also chasing every
  future component price change.
