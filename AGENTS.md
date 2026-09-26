# Agent guidance

This repository is the fresh Git lineage for the provider-neutral
`ran/wp-release-updater` Composer package. Keep every committed file suitable
for eventual public disclosure.

## RAN quality profile

This repository uses the RAN `php-library` quality profile. PHP coding and
compatibility ancestry comes from `ran/coding-standards` through
`RANWordPressLibrary`; the tracked Composer lock binds the published v1.0.0 release
under the `^1.0` development constraint. The additional `RANOwnedMethods`
check is enabled only for `CanonicalUpdateUri.php`, `ReleaseVersion.php`,
`AcquisitionReceipt.php`, `BindingRecord.php`, `IdentityDescriptor.php` and
`ReleaseAdapter.php` under `src/Contract/`, plus
`src/Provider/GitHub/GitHubReleaseAdapter.php`.
Variable naming enforcement is enabled only for `ReleaseVersion.php`,
`AcquisitionReceipt.php`, `BindingRecord.php`, `IdentityDescriptor.php` and
`ReleaseAdapter.php` under `src/Contract/`, plus
`src/Provider/GitHub/GitHubReleaseAdapter.php`. Expand these completed scopes
only with reviewed declaration/caller and enforcement proof.

Completed result/proof cohorts also enforce owned methods and variable naming in
`ArchiveScanResult.php` and `ValidatedPackage.php` under `src/Archive/`, plus
`ProspectiveReleaseInspection.php` and `ProspectiveReleaseArtifact.php` under
`src/Provider/GitHub/`.

Completed result/proof cohorts also enforce owned methods and variable naming in
`src/Archive/ArchiveScanner.php`,
`src/Archive/PackageIdentityValidator.php`,
`src/Archive/TemporaryArtifact.php`.

Completed result/proof cohorts also enforce owned methods and variable naming in
`src/Provider/GitHub/GitHubApiClient.php`,
`src/Provider/GitHub/GitHubArtifactCustodyFailure.php`,
`src/Provider/GitHub/GitHubArtifactStore.php`,
`src/Provider/GitHub/GitHubCredentialResolver.php`,
`src/Provider/GitHub/GitHubReleaseReadUnavailable.php`,
`src/Provider/GitHub/GitHubReleaseService.php`.

Completed result/proof cohorts also enforce owned methods and variable naming in
`src/Runtime/ReleaseFailure.php`.

Completed result/proof cohorts also enforce owned methods and variable naming in
`src/WordPress/BindingFenceCoordinator.php`,
`src/WordPress/BindingState.php`,
`src/WordPress/InstalledPackageResolver.php`,
`src/WordPress/NativePackageUpdater.php`,
`src/WordPress/OwnedArchiveStore.php`,
`src/WordPress/PendingInstallState.php`,
`src/WordPress/StagedPackageManifest.php`,
`src/Runtime/SelectedRuntimeState.php`.

Keep release-updater identity and product constraints local: the
`RAN\WPReleaseUpdater\V1` namespace, WordPress 6.5 floor, PHP `^8.2` range,
source paths, provider/runtime rules, security-sensitive native-primitive
exceptions, fixtures, and focused integration proofs remain repository-owned.
Do not copy shared ancestry back into local PHPCS configuration and do not
promote release-updater exceptions into the shared standard merely to reduce
this file.

`composer check` remains the authoritative non-mutating PHP aggregate and must
retain strict validation, updater-support parity, Composer audit, syntax lint,
shared PHPCS/PHPCompatibility checks, PHPStan, unit tests, and the no-dev
consumer proof. The audit uses live advisory data and requires network access.
Use `lint:syntax` for parsing, `standards` for PHPCS, `standards:fix` for
PHPCBF with the same ruleset and scope, `analyze` for the existing PHPStan
boundaries, and `test` for unit tests followed by the no-dev consumer proof.

- Preserve the package coordinate, `RAN\WPReleaseUpdater\V1` namespace, and
  canonical `MAJOR.MINOR.PATCH-beta.N` prerelease line, with SemVer-core advancement permitted when reviewed release-driving metadata requires it.
- Keep the built-in provider catalog sealed inside the selected runtime. Add
  no public adapter loader, compatibility facade, second broker, `replace`, or
  `provide` declaration.
- Keep credentials request-local and out of source, fixtures, diagnostics,
  caches, logs, URLs, and committed artifacts.
- Run `composer check` and the focused lifecycle or provider tests owned by a
  change before committing it.
- Treat pushes, pull requests, merges, visibility changes, tags, releases, and
  publication as separate operations requiring their applicable authority.
- Preserve the fresh-history boundary: import reviewed source bytes only, not
  Git objects, refs, tags, remotes, or internal planning evidence from another
  repository.

## Blacksmith AI prohibition

Blacksmith is approved only as GitHub Actions runner infrastructure where a
repository workflow explicitly selects a Blacksmith runner.

- Never invoke, delegate work to, tag, enable, or otherwise use Blacksmith
  [code]smith, `@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning,
  Blacksmith Testbox agents, or any other Blacksmith AI/agent feature.
- Do not trigger "Enable autofix", ask [code]smith to investigate or repair CI,
  or call Blacksmith agent/MCP/CLI/API features that perform AI inference.
- If CI fails, inspect GitHub Actions logs directly and diagnose or fix the
  failure without delegating it to Blacksmith AI.
- This is a cost-control requirement. Do not override it for convenience, CI
  failures, review comments, or suggestions presented by GitHub or Blacksmith
  UI.


## Prerelease version policy

Release Please owns version semantics, including the beta prerelease line and
SemVer-core advancement configured in `release-please-config.json`. It owns the
changelog, release PR, tag, and GitHub Release lifecycle. The pinned shared
Profile A workflow supplies exact successful-main CI admission and bounded
exact-candidate Quality dispatch; do not duplicate those controls locally.

Ordinary Quality owns the runtime-copy product contract:
`runtime-copy.json.package_version` must match the Release Please manifest and
remain managed through its `extra-files` adapter, while `package_revision` must
match the exact content identity of `bootstrap.php`, `runtime.php`, and
production PHP under `src/`. Preserve the existing runtime-copy provenance and
fail-closed tests. Do not recreate a generic version engine, release classifier,
local publisher, lifecycle-label reconciliation, or historical recovery system.
