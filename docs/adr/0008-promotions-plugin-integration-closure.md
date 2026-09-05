# ADR-0008: Promotions-plugin integration closure — accepted mechanism, versioned prerequisite, fail-safe behaviour

## Status

**Accepted.** Closes the "promotions-plugin milestone"
(`docs/ARCHITECTURE.md`, "Promotions-plugin milestone" section) and
partially supersedes ADR-0007's "Promotions condition engine" row only
(see that ADR's Status section) — no other row of ADR-0007 is affected.

This ADR is a **documentation-only reconciliation**, not new UCB
implementation work. It records, on the UCB side, that the companion
`mp-commerce-promotions` repository has designed, implemented, tested, and
merged its own accepted exclusion mechanism — that repository's
`docs/adr/0001-ucb-component-cart-line-exclusion.md`, PR #6, merged commit
`9d9d8a18ccedd793fb77d7b8da803d01dd3be8d5` on its `main` branch. No UCB
source file changes with this ADR.

## Context

`docs/ARCHITECTURE.md`'s "Promotions-plugin milestone" section and
ADR-0007's "Promotions condition engine" row both recorded, at
documentation-freeze time, spike S1-D's finding against a proof-of-concept,
mocked promotions engine: a hidden, zero-priced kit-component child line
can satisfy a `product_in_cart`/`category_in_cart`/quantity-style promotion
condition on its own, with no genuine standalone purchase of that
component — and proposed a fix shape (an `is_kit_component` field added to
the promotions plugin's cart-context projection, checked separately at
each condition-matching point).

The real `mp-commerce-promotions` repository has since independently
designed and shipped its own fix for exactly this leak, arrived at through
its own source-led forensics of its own codebase (not available to UCB's
spike, which only had a mocked engine to test against). Its accepted
design differs from the spike's proposed shape in one material way: rather
than adding a new field checked separately at every condition/action's own
matching point, it excludes the marked row once, upstream, at the single
place its cart-context object is constructed
(`CartContextBuilder::build_from_cart()`) — before any condition, action,
or discount-allocation code ever sees the row. That repository's own
forensics found a live, provable reason to prefer this shape: their
existing analogous per-consumer exclusion pattern (for gift-card product
lines) had a real, unpatched gap — two condition types never applied it —
and a single upstream choke point cannot have that class of gap by
construction.

**This is exactly the outcome ADR-0007's own text anticipated and
explicitly allowed for**: "The promotions plugin owns the rule natively...
no runtime plugin dependency in either direction" (`docs/ARCHITECTURE.md`,
"Promotions-plugin milestone" section) — UCB specified the *marker* and
the *invariant* (a hidden child must never be treated as a genuine
selection), not the promotions plugin's own internal implementation
mechanism. The mechanism differing from the spike's original guess is not
a contradiction; it is the promotions repository correctly exercising the
ownership this architecture always assigned to it.

## Decision

1. **The published data contract is unchanged and is hereby confirmed
   correct by an independent, real implementation.** UCB writes
   `_ucb_component` (truthy) as a plain array key in the `$cart_item_data`
   passed to `WC_Cart::add_to_cart()` for every component-child cart line
   (`src/Woo/CartConstruction.php`, `MetaKeys::LINE_COMPONENT`). This is
   the same literal key WooCommerce auto-persists as order-item meta after
   checkout. UCB adds, changes, or requires nothing new for this closure.
2. **Ownership is confirmed: the promotions plugin owns exclusion,
   natively, with no duplication in UCB.** `mp-commerce-promotions` reads
   the literal `_ucb_component` cart-item array key at its own
   `CartContextBuilder::build_from_cart()` and excludes the row from its
   `EvaluationContext` before any condition, action, or discount-allocation
   code runs. UCB contains, and must never contain, any promotions
   condition/matcher/allocation logic, any class or constant from that
   repository, or any call into it. This was true before this ADR and
   remains true after it; this ADR changes no UCB source file.
3. **The kit parent remains the commercially visible, promotion-eligible
   cart item, confirmed by live evidence from the consuming side.** The
   parent cart/order line is never marked `_ucb_component` — only children
   are — so it is never excluded by the promotions plugin's guard.
   `mp-commerce-promotions`' own live acceptance evidence (recorded in its
   PR #6 and `docs/UNIVERSAL_COMMERCE_BUNDLES_COMPONENT_EXCLUSION.md`)
   demonstrates this with a real, disposable environment: a kit-parent-
   targeted promotion fired identically (`-$7.00`) via both classic
   add-to-cart and a real `POST /wc/store/v1/cart/add-item` Store API
   request, on a kit-only cart, while a component-targeted promotion
   correctly did not fire on the same cart — and did fire, identically
   between both entry paths, once a genuine standalone purchase of the
   component was added.
4. **The integration is a versioned, data-contract prerequisite — not a
   runtime code dependency, and explicitly not a purchasability gate like
   ADR-0006's fulfillment-readiness handshake.** These are deliberately
   different in kind:
   - **Fulfillment (ADR-0006):** an unfulfillable order is a real
     operational failure (a picking list references stock that was never
     actually reserved for a specific customer's kit), so UCB gates kit
     *purchasability* on a request-local readiness handshake from a
     compatible fulfillment plugin.
   - **Promotions (this ADR):** an incorrect promotion decision is a
     pricing/discount-accuracy defect confined entirely to the promotions
     plugin's own domain. It never affects stock, reservation, reduction,
     restoration, refund correctness, order construction, or whether a
     kit can be purchased or fulfilled at all. Gating purchasability on a
     promotions-plugin signal would require UCB to detect, version-check,
     or otherwise depend on that plugin at runtime — precisely the
     coupling `docs/ARCHITECTURE.md`'s settled decision 6 and the
     "Promotions-plugin milestone" section both rule out. **No
     purchasability gate, capability handshake, or version check is added
     to UCB for promotions, and none should be.**

   The "versioned" part of the prerequisite is a **documentation and
   deployment fact**, not a runtime mechanism: a site needs
   `mp-commerce-promotions` at or after commit
   `9d9d8a18ccedd793fb77d7b8da803d01dd3be8d5` on its `main` branch (which
   implements its own ADR-0001) for the exclusion to be present at all.
   **As of this ADR, that commit exists only on that repository's `main`
   branch — its most recent tagged/released version, `v0.5.4`, predates
   it by 14 commits and does not contain the fix.** This is recorded here
   as a factual, evidenced deployment-readiness note (see "Consequences"),
   not resolved by this ADR, since cutting a release in that repository is
   outside this repository's authority and outside this ADR's scope.
5. **Fail-safe behaviour when the promotions plugin is absent, inactive,
   or present at an older version without the fix: fails *open* for
   purchasability, fails *closed* only for promotion-eligibility
   correctness.**
   - **Absent or inactive:** no promotions plugin runs at all, so no
     condition can incorrectly fire off a hidden child — there is nothing
     to fail. The kit remains fully purchasable and functional; it simply
     participates in zero promotions, exactly like every other product
     when no promotions plugin is present.
   - **Present, active, but older than the fix (pre-`9d9d8a1`):** the
     kit remains fully purchasable and functional. The bounded, known
     degradation is exactly the pre-fix behaviour ADR-0007 and this ADR's
     Context both describe: a hidden component child *may* incorrectly
     satisfy a product/category/quantity condition, or incorrectly
     receive an allocated discount, with no genuine standalone purchase
     of that component present. This is a promotion-accuracy defect on
     the promotions plugin's own side, not a UCB defect and not a
     stock/fulfillment/order-integrity hazard — UCB's own cart/order
     construction, stock lifecycle, and refund behaviour are completely
     unaffected regardless of which promotions-plugin version, if any, is
     installed.
   - **No UCB-side detection of promotions version or activation state is
     implemented, or should be implemented** — consistent with decision 4
     above and with `docs/ARCHITECTURE.md`'s repeated "no runtime plugin
     dependency in either direction" statement.

## Rejected alternatives

- **A UCB-side readiness/version-handshake gate for promotions, mirroring
  ADR-0006's fulfillment pattern.** Rejected: promotion-eligibility
  accuracy is not a stock- or fulfillment-safety property, so there is no
  operational harm proportionate to justify blocking a legitimate purchase
  over it, and building the gate would itself create the runtime
  dependency the architecture forbids in both directions.
- **UCB implementing its own promotions-exclusion logic, or shipping a
  bundled/optional promotions integration.** Rejected — out of scope for a
  generic kits plugin (settled decision 6); ownership stays entirely with
  the promotions plugin, which is the only party that can see its own
  condition/action/allocation code paths.
- **A generic, registerable-condition seam in the promotions plugin for
  UCB (or any bundle plugin) to plug into**, previously carried in
  `docs/ARCHITECTURE.md`'s Deferred list as an open possibility. Rejected,
  not merely deferred: `mp-commerce-promotions`' own ADR-0001 explicitly
  rejected an extension API / self-registering condition type for exactly
  this integration, citing the same "unrecognised condition type makes the
  whole promotion ineligible" hazard this repository's own V7 finding
  already named. There is no seam left to defer — the item is closed as
  rejected, and `docs/ARCHITECTURE.md`'s Deferred list is updated
  accordingly.
- **Treating `mp-commerce-promotions` `v0.5.4` (the latest tag) as
  satisfying the prerequisite.** Rejected — verified false by direct
  inspection: `git merge-base --is-ancestor v0.5.4 9d9d8a1` confirms
  `v0.5.4` is an ancestor of, and therefore predates, the fix by 14
  commits. Stating the prerequisite as a version number rather than the
  specific commit would misrepresent the current released state.

## Consequences

- The "Promotions-plugin milestone" in `docs/ARCHITECTURE.md` is closed.
  No further UCB documentation or code work is required for this
  integration.
- **Deployment-readiness note, not a UCB blocker:** any site wanting the
  hidden-component exclusion in production must run
  `mp-commerce-promotions` from a build that includes commit `9d9d8a1` or
  later — currently only available by installing from that repository's
  `main` branch, since no tagged release contains it yet. This is a fact
  to track in that repository (and in any site-specific deployment
  checklist), not something this ADR or UCB can resolve.
- This closure changes no UCB source file, test, schema, or runtime
  behaviour. It is a documentation reconciliation confirming that a
  cross-repository, data-contract-only dependency this architecture always
  described has been satisfied by the other repository's own accepted
  design.
- UCB's own acceptance-coverage requirement for this contract
  (`docs/ARCHITECTURE.md`, "Acceptance coverage" → "Cross-cutting exclusion
  contract") is satisfied by `mp-commerce-promotions`' own live evidence
  (PR #6) rather than by a UCB-side spike against a mocked engine — a
  stronger form of the same evidence, from the real consuming codebase.

## Related

`docs/adr/0007-cross-cutting-cart-order-line-exclusion-contract.md`
(partially superseded, promotions row only); `docs/ARCHITECTURE.md`,
"Promotions-plugin milestone" and "Cross-plugin contract and rollout gate"
sections; `mp-commerce-promotions` repository:
`docs/adr/0001-ucb-component-cart-line-exclusion.md`,
`docs/UNIVERSAL_COMMERCE_BUNDLES_COMPONENT_EXCLUSION.md`, PR #6 (merged
`9d9d8a18ccedd793fb77d7b8da803d01dd3be8d5`).
