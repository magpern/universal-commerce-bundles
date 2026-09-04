# Spike S1-A — Component Reservation

**Verdict: PASS — viable design identified and proven.**

This spike is part of Architecture A (see `docs/adr/0002-...md`), which was
independently proven viable on its own terms and then set aside in favour
of Architecture B. It is preserved as evidence for a rejected alternative.

## Environment / provenance

- WooCommerce **11.0.1** core source, read-only.
- MariaDB **11.8.8**, matching the reference stack's own database image
  tag; a disposable test database container ran the identical image tag,
  isolated on its own private Docker network, no host port published, not
  attached to any reverse proxy or to the reference stack's own internal
  network.
- PHP **8.3.32** (reference web container), WordPress **7.0.2**.
- All source citations were re-read directly from WooCommerce 11.0.1 in
  this session, not carried over from an earlier draft unverified.

## Approaches evaluated

### 1. Supported public-API solution avoiding direct reservation-table access — REJECTED (no scoped extension point exists)

WooCommerce's `ReserveStock::reserve_stock_for_order()` enumerates the
order's items inline, builds a per-stock-managed-id demand map in PHP, then
calls the **private** per-product reservation-write method once per row.
There is no action or filter *inside* this loop that a third party can use
to add extra demand entries before the sort/insert pass.

The only extension point that reaches this loop's input is
`WC_Abstract_Order::get_items()`'s own `woocommerce_order_get_items`
filter. This filter is **global** — every caller of `get_items()` anywhere
in WooCommerce (order totals, emails, admin display, tax calculation, REST)
receives the same injected items. Using it to make kit components visible
to the reservation loop would also make them visible everywhere else
`get_items()` is called, corrupting totals/tax/display. Verified by
reading the filter's call site directly, not assumed. This closes candidate
1 for kit orders: no supported hook lets a plugin participate in core's own
reservation pass without also being visible to every other `get_items()`
consumer.

### 2. Opt the kit order out of core reservation; the plugin reserves the whole order itself — RECOMMENDED, paired with 4

`woocommerce_order_hold_stock_minutes` is evaluated **inside**
`ReserveStock::reserve_stock_for_order()` — the shared entry point both
checkout paths call (see "Hook ordering" below) — receives the order, and
returning `0` for that order skips reservation for **every** line in it.
This is a genuine per-order (not per-product) opt-out, confirmed by
re-reading the filter call.

Combined with candidate 4 (below), the plugin becomes the **sole writer**
to the reservation table for any order containing a kit line, eliminating
the double-writer hazard proven under "Naive approach" below. Ordinary
orders (no kit line) are untouched and continue to use core's reservation
unmodified.

### 3. Plugin-owned reservation table + compensating availability check — REJECTED as primary, kept as fallback only

A separate plugin-owned reservation table would not be visible to core's
own reservation-read query, which is what a **standalone** purchase of the
same component consults for availability. A standalone checkout could then
oversell a component already held by a kit order, unless the plugin
additionally patches stock/availability filters store-wide to subtract its
own table's holds — which duplicates core's own locked read outside the
lock that makes it safe, reintroducing a race between the plugin's read
and core's own reservation insert for the same product. Rejected as the
primary design; retained only as a documented fallback if a future
WooCommerce version removes/renames the reservation table.

### 4. Direct, version-bound integration with the reservation table — RECOMMENDED, paired with 2

Given no supported seam exists (1, rejected) and a shadow table
under-protects standalone purchases (3, rejected as primary), the plugin
must write to the real reservation table using the **same algorithm** core
uses — reproduced faithfully, not approximated:

- Aggregate **all** order lines (standalone products *and* every kit's
  components) into one demand-per-stock-managed-id pass in PHP, exactly
  mirroring core's own aggregation, **before** issuing any SQL.
- Sort the aggregated rows before writing, to match core's documented
  deadlock-avoidance lock order.
- Issue exactly one locked `INSERT … SELECT … WHERE (stock FOR UPDATE) −
  (reserved LOCK IN SHARE MODE) >= qty ON DUPLICATE KEY UPDATE …` per
  stock-managed id, byte-for-byte the same query shape as core's private
  reservation-write method.
- Reproduce the 3-attempt retry loop on transient errors, and the
  "0 rows affected → not enough stock" exception mapping.
- Skip components exactly as core does: not-stock-managed or
  backorders-allowed components are skipped; an out-of-stock product
  throws — confirmed unchanged by direct source read.

This is explicitly a **version-bound compatibility layer**, not a
permanent integration. See "Compatibility boundary" below.

## Evidence — real database tests, separate OS processes, disposable DB

All tests ran against the disposable test database (MariaDB 11.8.8,
InnoDB), using its own client as **separate processes** (real separate
connections, not mocked SQL).

### Test A — two concurrent checkouts for the final available unit

A test product, stock=1. Two background processes each ran the exact
locked `INSERT…SELECT…FOR UPDATE…ON DUPLICATE KEY UPDATE` from core's own
reservation-write method, for two different orders.

**Result:** `affected_A = 0`, `affected_B = 1`. Exactly one reservation row
existed afterward. **PASS** — the `FOR UPDATE`/`LOCK IN SHARE MODE`
combination correctly admits only one writer for the last unit, regardless
of which process races ahead; the loser gets `0` affected rows, and, in the
real reservation code, that maps to an insufficient-stock exception. The
safety property (at most one winner) held regardless of the exact
interleaving.

### Test B — proof that separate writes REPLACE rather than ADD

```sql
INSERT … VALUES (order_1, product_1, 3, …) ON DUPLICATE KEY UPDATE …;   -- after first write  = 3
INSERT … VALUES (order_1, product_1, 2, …) ON DUPLICATE KEY UPDATE …;   -- after second write  = 2   (NOT 5)
```

**Result:** final value **2**, not the sum **5** — a silent
under-reservation of 3 units. This concretely reproduces the exact bug the
architecture's corrections table (C9/C11 in `docs/ARCHITECTURE.md`) warns
about, and proves that any design issuing two separate writes for the same
reservation key (e.g. "reserve the standalone line, then separately
reserve the kit's component demand") is unsafe. Compare with the
aggregate-first write:

```sql
INSERT … VALUES (order_1, product_1, 5, …) ON DUPLICATE KEY UPDATE …;   -- aggregated single write = 5
```

**Result: 5, correct.** This is why the recommended design aggregates in
PHP before any SQL is issued, rather than writing per-source-line.

### Test C — multiple demand sources resolving to the same stock-managed id

Simulated PHP-level aggregation (`standalone_qty=2 + kit_component_qty=3 =
5`) followed by **one** write. **Result: single row, quantity=5.** Confirms
the same-order, same-managed-id summing requirement holds when aggregation
happens before the write.

### Test D — reservation expiry

Inserted a reservation with `expires` in the past. Core's own
reservation-read query correctly excludes it: `reserved_visible_to_new_checkout
= 0`. Confirms an expired hold does not block a later checkout, matching
core's abandoned-checkout release semantics with no explicit sweep needed —
expiry is enforced purely by the read-time predicate, and the primary-key
upsert naturally reclaims an expired row on the next legitimate attempt.

### Test E — idempotent reservation retry

Three sequential identical-quantity upserts for the same reservation key
(simulating a checkout retry after a transient network error) leave the
quantity unchanged, not accumulated. **PASS** — confirms the upsert is
naturally idempotent for *same-order* retries; it is only unsafe for
*cross-line* aggregation within one order (Test B/C).

### Backorder-enabled and non-stock-managed components — verified by source, not by DB test

`ReserveStock.php`: a component is skipped from reservation entirely
(never held) when it is not stock-managed or allows backorders — read
directly from the 11.0.1 source. Consistent with the derived-availability
formula excluding backorder-enabled components from the kit's minimum. No
DB test is needed for this — pure PHP control flow, already exercised by
WooCommerce's own test suite.

## Hook ordering — proven, not assumed

Traced both checkout paths end to end in the 11.0.1 source:

- **Classic checkout:** registered on `woocommerce_checkout_order_created`
  at core's default priority 10.
- **Store API / Checkout Blocks:** the Store API checkout route calls the
  same reservation entry point **directly**, not via that action. The
  draft order was created earlier via a genuinely **different**, Store-API-
  specific action.

**Both paths funnel through the same reservation entry point**, which is
where the hold-duration filter is evaluated. **One filter callback
therefore governs the opt-out decision identically for both checkout
paths** — verified, not assumed, by reading the call graph.

For the plugin's *own* reservation pass (which must run after core's
attempt has no-op'd due to the opt-out), the two paths need separate hook
registrations because there is no shared "after reservation" action:
classic hooks the order-created action at a priority greater than core's
default; Store API hooks the order-processed action, confirmed by source
to fire strictly after the direct reservation call in the normal path.

## Reservation-to-reduction overlap window

Traced the registration order on the four shared status actions: reduction
always registers at priority 10 (default), release always registers at
priority 11 — one level later, on all four. **Finding: reduction always
runs before release, on the same request, for all four shared actions.**
This means there is a real (if brief) window where the reservation row and
the reduced stock coexist. Because reduction (which lowers the physical
stock number) runs first and release (which removes the already-counted
reservation) runs second, a concurrent read during that window sees stock
that is already reduced **and** still counted as reserved — availability is
momentarily *understated*, never overstated. This is the opposite of an
oversell risk and requires no compensating mechanism.

## Compatibility boundary (required — direct table use recommended)

- **WooCommerce version validated:** 11.0.1 only. The schema, the SQL shape
  of the private reservation-write method, and its method signature were
  all read from this exact version.
- **Versions actually exercised in a running container** (this spike plus
  its mandatory post-verification pass, see `s1-a-b-verification.md`):
  WordPress 7.0.2, PHP 8.4.24, WooCommerce 11.0.1, MariaDB 11.8.8 — the
  first time any of this spike's claims was exercised inside a running
  WordPress/WooCommerce process rather than only read from source or
  tested against a bare-database schema copy.
- **Version guards:** an activation-time check that the reservation table
  exists with the expected columns and primary key — via
  `INFORMATION_SCHEMA`, not a version-string compare alone. Fail closed
  (kit products become non-purchasable, admin notice) if the guard fails.
- **Integration tests that detect upstream drift:** a scheduled/CI test
  that reserves a known quantity on a throwaway order and asserts the row
  shape; asserts the upsert still *replaces* rather than adds; asserts the
  hold-duration filter is still evaluated inside the single shared
  reservation entry point for both a classic-style and a Store-API-style
  order-creation flow. Any of these failing must fail the CI matrix run
  for that WooCommerce version, not silently degrade.
- **Documented fallback when the guard trips:** kit products are marked
  non-purchasable and an admin notice states the reservation compatibility
  check failed. The plugin-owned shadow table (rejected candidate 3) is
  *not* silently substituted, because it under-protects standalone
  purchases.

## Rejected alternatives — summary

| Candidate | Verdict | Reason |
|---|---|---|
| 1. Public-API-only, avoid the reservation table | Rejected for kit orders | No scoped extension point in core's aggregation loop; the only reachable filter is global and would corrupt totals/tax/display store-wide |
| 2. Opt whole order out, plugin reserves everything | **Adopted**, paired with 4 | Only genuine per-order opt-out exists; combined with 4 it is the only design proven not to double-write |
| 3. Plugin-owned shadow table + compensating check | Rejected as primary | Not visible to core's own reservation-read query, so a standalone purchase can oversell a component held by a kit. Retained as documented emergency fallback only |
| 4. Direct, version-bound reservation-table integration | **Adopted**, paired with 2 | Proven safe under concurrency (Test A), proven to require aggregate-before-write (Test B/C), requires an explicit compatibility boundary (above) |

## Remaining limitations

- The compatibility layer must be re-verified against every future
  WooCommerce minor/major before upgrade.
- The core reservation-write method is `private`; the plugin cannot call
  it directly and must maintain a parallel implementation. Any core
  refactor of its internals (changed SQL shape, changed retry codes)
  requires re-validating Test A/B/C, not just a changelog read.
- Test A's interleaving demonstrates the safety property but not literal
  lock-wait blocking; a supplementary test holding the lock open via an
  explicit sleep *inside* the locked read would additionally demonstrate
  blocking behaviour — recommended as a follow-up before implementation.
- The "one filter governs both checkout paths" finding was verified by
  static call-graph tracing at the time this spike ran; see
  `s1-a-b-verification.md` for the subsequent live confirmation via a real
  HTTP request through both paths.

## Recommended design for ADR-0002 (summary)

For any order containing a kit order item:
1. Filter the hold-duration setting to return `0` for that order, opting it
   out of core's own reservation entirely.
2. On the order-created action (priority greater than core's default) for
   classic, and the order-processed action for Store API, run a dedicated
   reservation pass that aggregates **every** line (standalone + every
   kit's components, resolved through the stock-managed id) into one demand
   map exactly as core does, sorts it, and writes using the same locked SQL
   shape, retry loop, and exception mapping as core's private reservation
   method.
3. Skip backorder-enabled and non-stock-managed components exactly as
   core's own filter does.
4. Release the plugin's reservations on the same events core uses to
   release its own, by deleting reservation rows for the order id (safe —
   the plugin is the sole writer for these orders).
5. Guard the whole layer behind the compatibility boundary above; fail
   closed if the schema/behavior guard fails.

Orders with no kit line are entirely untouched — core's own reservation
path continues to run unmodified for them.
