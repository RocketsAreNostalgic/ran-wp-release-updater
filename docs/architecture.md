# Architecture and package relationships

This document explains the durable architectural boundaries that matter when embedding, maintaining, or extending `ran/wp-release-updater`. It deliberately omits the chronology of how the current design was reached.

## Conceptual lifecycle

Both RAN updater packages can be described with the same broad security-oriented flow:

```text
Declaration
→ Provider
→ Artifact acquisition
→ Validation
→ Mutation admission
→ WordPress Core
→ Postcondition verification
```

For the release updater, that flow is distributed across WordPress callbacks rather than executed as one synchronous command.

A plugin/theme declaration records configuration without performing provider work. A built-in provider discovers and acquires a release. The updater validates release/package identity, rechecks authority immediately before mutation, gives the controlled operation to WordPress Core, and verifies the resulting installation before accepting completion.

## Responsibility boundaries

The important boundaries are behavioural rather than inheritance/class-taxonomy rules:

- **Declaration**: immutable configured/admitted facts for a target.
- **Provider**: source-specific remote access and source authority.
- **Adapter**: translation from provider-specific behaviour into the provider-neutral release contract.
- **Artifact**: acquired bytes held under controlled custody.
- **Coordinator**: state/authority that must survive separate callbacks or processes.
- **State / Store**: bounded lifecycle data and its durable persistence.
- **Archive**: ZIP path/layout/identity/custody checks.
- **WordPress**: WordPress-specific hooks and Core mutation integration.

The package does not introduce a generic updater base class, public provider registry, or artificial common runner hierarchy merely to resemble another updater domain.

## Native WordPress lifecycle ownership

`NativePackageUpdater` owns the package's native plugin/theme hooks and callback lifecycle. It does not own provider authority and it does not replace WordPress Core as installer.

`BindingFenceCoordinator` owns the persistent binding/fence boundary used to prevent stale or competing writers from claiming the same target operation. It coordinates durable ownership state; it is not the complete release-update orchestrator.

`RequestBroker` is the single request-local broker for declarations and physical-runtime selection. It does not replace the durable fence coordinator.

These names describe different lifetimes:

- request-local runtime selection;
- persistent authority across callbacks/processes;
- WordPress's native callback lifecycle.

Keeping those lifetimes separate is more important than giving the release updater the same object graph as another package.

## Provider authority

The release updater ships a sealed catalogue of built-in providers. Consumers choose a provider code through `plugin()`, `theme()`, or the request-local `releases()` source; consumers do not register arbitrary adapter classes or provider loaders at runtime.

GitHub Releases is currently the only built-in provider. Provider-specific code owns GitHub repository identity, credentials, HTTP classification, release/asset identity, redirect rules and acquisition behaviour. The rest of the lifecycle consumes the provider-neutral `ReleaseAdapter` contract.

This is an intentional trust boundary. Adding another provider is a package change with its own identity, credential, transport, rate-limit and artifact rules; it is not a consumer extension hook.

## WordPress Core owns installation

The release updater verifies and admits the operation around Core. WordPress remains responsible for filesystem mutation, extraction, installation, rollback/temporary backup behaviour and the native update UI/scheduling.

The package therefore does not maintain a parallel installer. Its security model depends on:

1. validating the exact provider/release/archive before Core mutation;
2. fencing the target against stale/competing operations;
3. checking the staged/installed package after Core runs;
4. refusing to claim a verified terminal outcome when those postconditions cannot be established.

## Physical runtime selection

WordPress can load several independently distributed plugins/themes that each bundle their own physical copy—and potentially a different version—of this library. Production Composer does not PSR-4-autoload the release-updater implementation; each host package enters through its bundled `bootstrap.php`.

The first valid bootstrap establishes the request-local broker. Other physical copies loaded before the activation boundary register themselves as candidates without loading their runtime implementation. At `after_setup_theme` priority `PHP_INT_MAX`, the broker filters the candidates for the current PHP and WordPress versions, selects the highest compatible package version, and loads only that copy's runtime entrypoint. Declarations collected through every participating bootstrap are then routed through the selected runtime, so the request has one runtime authority and one hook-owning implementation.

Different package versions are therefore expected to coexist. A newer copy can be ignored when its environment floor is not satisfied and an older compatible copy can be selected instead. The integrity boundary is narrower: physical copies that claim the same package version must agree on the runtime revision, protocol-incompatible copies fail closed, and a copy arriving after activation cannot replace the already selected authority.

This mechanism lets downstream plugins/themes update the dependency independently while preventing separate bundled versions from competing for WordPress lifecycle authority. Historical runtime protocol transitions remain release history rather than standing architecture documentation.

## `ran/updater-support`

The release updater uses `ran/updater-support` differently from the branch updater.

During development/build, shared archive-safety primitives come from the Composer dependency. The release package then generates/reviews a namespace-scoped copy inside its sealed runtime. The distributed runtime therefore does not depend on another production Composer package being loaded in the WordPress request.

The branch updater can use `ran/updater-support` as an ordinary production dependency. This difference reflects their loading/trust models and is not dependency drift that should be normalized away.

Shared support is appropriate only for stable, non-trivial correctness/security rules whose semantics genuinely match across packages. Small conveniences or domain policy remain local.

## Relationship to the branch updater

`ran/wp-release-updater` and `ran/wp-branch-updater` share security vocabulary and some low-level archive rules, but they solve different product problems.

| Release updater | Branch updater |
| --- | --- |
| Joins WordPress's future native update lifecycle | Performs an explicitly requested branch deployment |
| Consumer ends with `register()` | Consumer ends with `deploy()` |
| Lifecycle is distributed over native callbacks | One admitted attempt has a synchronous ordered runner |
| Providers are package-owned/sealed | Providers are supplied by the host |
| Persistent fence state spans callbacks/processes | Deployment journal/runner state follows the explicit attempt |
| Generated updater-support copy is part of the sealed runtime | updater-support can be a normal production dependency |

The common conceptual flow is useful for reasoning and review, but neither package should acquire abstractions solely to look structurally identical to the other.

## Relationship to Booster

Booster is a downstream consumer of `ran/wp-release-updater`; the release-updater library itself does **not** depend on Booster. Booster supplies product-specific configuration, administration, release-selection/workflow behaviour and any additional host policy around the library.

That relationship is useful as a real integration example, but Booster is not part of this package's runtime contract:

- a third-party plugin/theme can embed and use the updater directly;
- the release updater's own tests do not require a Booster checkout or a live Booster site;
- package behaviour should remain valid without Booster-specific classes, state or UI;
- Booster-specific release policy belongs in Booster rather than being generalized into this library unless it is genuinely part of the reusable updater contract.

This separation is intentional: the public release updater is a reusable Composer library, while Booster is one product that consumes it.

## Security boundary and non-goals

The updater authenticates/validates the provider and package evidence it owns, controls temporary artifact custody, fences its target operation and verifies Core's result. It does not sandbox arbitrary PHP already executing as the same operating-system user, and it cannot stop unrelated code with equivalent filesystem/database authority from changing the installation outside the updater lifecycle.

The package also does not own:

- application scheduling outside WordPress's normal update lifecycle;
- product-specific inventory/administration policy;
- arbitrary third-party provider plugins;
- FTP/SSH filesystem transports;
- a general persistent cache for host applications;
- network activation policy in multisite.

See [Integrating the release updater](integration.md) for the consumer-facing lifecycle and [Testing and verification](testing.md) for what the repository's test matrix does and does not prove.
