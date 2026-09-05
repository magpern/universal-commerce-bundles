# ADR-0006: Cross-plugin rollout / readiness gate, and inactive-plugin safety

## Status

**Accepted** — merged as part of the documentation-freeze pull request
(PR #1, `docs/architecture-freeze`). Narrowed by the Architecture A → B decision. The purchasability
guard, capability handshake, and deactivation-lock policy are retained
unchanged. The custom problem-status ownership term and the
background-stock-operation-deferral responsibility, both required under
Architecture A, are removed — proven unnecessary for stock-lifecycle
correctness under Architecture B (ADR-0002).

## Context

A kit is an ordinary WooCommerce `simple` product (ADR-0001). If this
plugin stops running — deactivated intentionally, its files removed, or its
bootstrap failing part-way — its purchasability and availability filters
simply disappear, and **the kit remains purchasable as a normal product**,
with no component-availability check, no reservation, and no component
reduction at all. A companion fulfillment plugin's fail-closed intake
protects orders *already placed*; it does nothing about *new* orders being
placed unsafely while this plugin is down. No plugin can enforce behaviour
while its own code is not running, so protection has to live somewhere that
survives the plugin's absence: **persisted product state**, plus an
**independently loaded host guard**.

A persisted "is this plugin healthy" option, by itself, cannot prove
*current* health: the plugin can initialise once, write the option, then
have its files later removed or corrupted, and a subsequent request would
find the stale "healthy" option and wrongly allow kit purchases.

## Decision

1. **Deactivation writes persistent safety state before completing.** The
   deactivation hook marks every kit non-purchasable through data that
   survives without the plugin — an out-of-stock status plus a `_ucb_locked`
   marker recording that the lock was set by deactivation. Persisted state
   is the only mechanism that still works when no plugin code runs at all.
2. **Reactivation does not auto-unlock.** Locked kits stay locked until an
   explicit admin action, because the reason for deactivation is unknown to
   the plugin and stock may have moved in the interval. Composition is
   revalidated before the unlock is offered.
3. **The host guard is a must-use (mu) plugin, not an ordinary plugin.** An
   ordinary "storefront"-style plugin is not sufficient — it can itself be
   deactivated or fail, so it is not independent of the failure it exists
   to catch. The guard must:
   - read the "is a kit" product-meta flag directly, which persists
     regardless of this plugin's own state;
   - have **no dependency on this plugin's classes, autoloader, or
     constants** — it must function correctly when none of it is loaded;
   - check for a **healthy, request-local capability signal** (below), not
     merely a persisted option;
   - block purchase of kit products whenever that signal is absent;
   - remain active while every ordinary plugin is deactivated;
   - be covered by deployment and acceptance tests.
4. **Two separate signals: a persisted contract record, and a request-local
   readiness handshake.**
   - **Persisted contract record** — expected plugin version, supported
     snapshot versions, and configuration. Survives across requests, is
     **informational only**, and is never by itself sufficient to permit a
     purchase.
   - **Request-local readiness handshake** — starts `false` on **every**
     request. This plugin emits a stable hook, `ucb_runtime_ready`, only as
     its **final successful bootstrap step**. The MU-plugin listens for it
     and sets readiness `true` only after validating the payload. The
     purchasability guard requires this request-local signal — it must
     never infer current health from the persisted record alone.

   Bootstrap-failure protection is thereby the *guard's* responsibility,
   not this plugin's own: a plugin that dies before registering hooks
   cannot protect anything by itself, so a design where this plugin is
   expected to notice its own failure is circular. This plugin's only
   obligation is to emit `ucb_runtime_ready` if and only if it has fully
   initialised.
5. **The custom "stock problem" order status is removed as a required
   MU-plugin responsibility.** Under Architecture A it existed to give a
   custom stock-transaction subsystem a safe deferral state, and its
   registration and background-stock-op-deferral wiring were part of the
   MU-plugin's mandatory contract. Under Architecture B, this is **no
   longer load-bearing for correctness** — core's own reduction/restoration
   lifecycle already works correctly even with this plugin entirely
   inactive (proven live, ADR-0002). A general-purpose "needs attention"
   status may still be useful for unrelated partial-failure visibility
   (`docs/ARCHITECTURE.md`, "Partial-failure handling"), but its
   registration is no longer a contract term, and it is no longer
   specifically named by necessity.

### Capability contract (closed)

| # | Term |
|---|---|
| 1 | The plugin emits `ucb_runtime_ready` **exactly once**, as the final successful bootstrap step |
| 2 | Payload carries `plugin_version`, `contract_version`, `snapshot_versions` |
| 3 | The MU-plugin starts **every** request with readiness `false` |
| 4 | It listens for the action and sets readiness `true` **only after validating the payload** |
| 5 | At a **late purchasability-filter priority**, a product marked as a kit requires readiness `true` |
| 6 | The persisted option is **separately named and versioned, non-authoritative**, and can never set runtime readiness |
| 7 | The guard references **no plugin class, constant or autoloader** |

Term 1 makes a partially initialised plugin indistinguishable from an
absent one — the desired failure mode. Term 5 places the check late so any
other plugin's earlier veto still wins. Terms 6 and 7 keep the guard
functional when the plugin is not merely inactive but entirely gone from
disk.

Division of responsibility: terms 1, 2, and the publishing half of term 4
belong to this (generic) plugin; term 3 and the enforcing half of term 4
are host configuration, and are therefore **not** part of the generic
plugin's own codebase — consistent with keeping the plugin store-agnostic —
and are documented as a deployment requirement for any picked-to-order
site.

### Host MU-plugin deployment guidance

- Deploy as an individually mounted or otherwise deployed, **read-only,
  single file** — never a whole-directory mount, which could shadow other,
  unrelated must-use plugins already present on a host.
- Present in **every** runtime context that can execute WooCommerce
  operations, not only the primary web process — including any CLI/cron
  runner used for scheduled WooCommerce work.
- Tracked in version control as a first-class file. A safety guard whose
  entire job is to survive plugin failure must not itself be an
  unversioned file living only in a runtime data directory.

**The justification for deploying into every runtime context changed
between architectures, but the conclusion did not.** Under Architecture A,
this was load-bearing: background/cron-driven stock reduction needed the
guard present in every WooCommerce-capable runtime context specifically to
defer stock operations while the plugin was unavailable. Under Architecture
B, core's own stock lifecycle handles that case correctly with no guard
involvement at all — so that specific justification no longer applies. The
guidance is retained anyway, defensively: the guard's remaining
responsibility, the *purchasability* check, should still be present
wherever WooCommerce operations can run (a future CLI-driven order-creation
script, an admin CLI action, or any other path that calls
`is_purchasable()` outside a normal web request).

## Consequences

- A kit's safety when this plugin is absent does not depend on any code in
  this plugin running — by design, since the whole point of the guard is
  to work when this plugin cannot.
- The MU-plugin becomes a small, permanent, cross-repository dependency
  that any host deploying this plugin for a picked-to-order storefront must
  provision and keep in version control — documented here as a deployment
  requirement, not shipped as part of this repository's own code (which
  stays store-agnostic).
- Architecture B measurably shrinks this ADR's scope relative to
  Architecture A: what remains is exactly the purchasability guard and the
  deactivation-lock policy, with no stock-lifecycle responsibility at all.

## Rejected alternatives

- **Relying on an ordinary (non-mu) plugin as the host guard.** Rejected —
  it is not independent of the class of failure (plugin deactivation/
  removal) it exists to catch.
- **Inferring health from the persisted contract record alone**, without a
  request-local handshake. Rejected — proven to go stale in exactly the
  case that matters: files removed after the option was written, leaving a
  wrongly-permissive stale "healthy" state with no mechanism to notice.
- **Keeping the custom "stock problem" status as a required MU-plugin
  contract term under Architecture B.** Rejected — proven unnecessary for
  correctness once core's own lifecycle was shown to work with the plugin
  fully inactive; retaining it as a mandatory contract term would have kept
  Architecture A's coupling without Architecture A's justification for it.
