# Testing and verification

This page is for maintainers and contributors. It explains the repository's verification layers, the local prerequisites for the installed-WordPress suite, and—most importantly—what those tests do **not** prove.

Consumer integration guidance lives in [Integrating the release updater](integration.md).

## Default quality gate

Run:

```sh
composer install
composer check
```

`composer check` validates the package metadata and generated updater-support copy, audits locked Composer dependencies, lints PHP syntax/style, runs PHPStan, executes PHPUnit, and proves the installed no-dev Composer consumer path.

PHPStan's level-8 roots include `src/WordPress/InstalledPackageResolver.php`.
Its five private race/read/lock/rewind seams are assigned through Reflection by
`InstalledPackageResolverTest`; only `property.unusedType` is ignored at those
specific declarations. Their callable types and all method bodies remain checked.
`scanDirectories: src` supplies symbol discovery; it does not itself analyse
method bodies. Production analysis now defaults to the repository root rather
than enumerating individual source files. Root-relative tests/scripts, installed
dependencies and disposable cache/workspace/coverage directories are excluded;
they are separate development or generated state, not omitted production. The
generated ArchiveSafety helper remains directly analyzed. Exclusions apply to
analysis and scanning so fixture-defined constants do not change production
type inference.

`ProductionAnalysisCoverageTest` uses PHPStan's effective finder and exclusions
and compares them with independent recursive maintained-PHP discovery. Isolated
controls exercise new root files, new/nested source directories, relocation and
splitting, a production `src/tests` directory, an intentionally incomplete path
list and an explicitly excluded generated production file. A bounded header check
also detects extensionless PHP/shebang entrypoints: a new unselected entrypoint
fails coverage until its analysis boundary is deliberately accounted for. Existing production
coverage remains 36/36; this prevents future omissions rather than correcting a
current uncovered production file.

`composer analyze` also runs `phpstan-tools.neon` at level 8 over the entire
`scripts/` directory in a separate invocation. Its two maintained PHP scripts
are now directly analyzed, without adding their declarations to the production
symbol world. New and split scripts enter automatically. The existing coverage
test independently discovers all script PHP, including nested `scripts/tests`,
compares effective selection, asserts level 8, detects an excluded nested script,
and proves a new script's incorrect return fails actual analysis.

Direct analysis covers all 97 maintained PHP files: 36 production files and
three scripts at level 8, plus 58 test files at level 5. The small
`scripts/analyze-tests.php` runner discovers the whole tests directory through
PHPStan's file finder, then passes each file separately to the locked analyzer.
`--list` exposes exactly that selection to the independent coverage guard.
No file registry, baseline, test exclusions or ignored-error configuration is
used. Nonstandard PHP entrypoints still fail the independent discovery comparison
until explicitly included, just as for production.

The test profile deliberately has no configured `paths`: otherwise declarations
from unrelated executable fixtures can enter symbol discovery despite passing
one CLI path. Existing same-name connection helpers and WordPress hook fixtures
require separate worlds. Regression controls demonstrate clean isolated worlds,
the failing combined-world counterexample, an automatically selected future
nested test, an excluded-file omission and a real return-type violation.

The test-only profile does not remember possibly impure function values.
Updater status changes through callbacks/helper calls and shared global state;
remembering an earlier `status()` observation incorrectly rejects later runtime
assertions. Production/tool profiles retain their stronger existing settings.
Shared fixture registries are read through their actual `$GLOBALS` identity,
and captured mutable booleans have truthful type documentation.

Exact identifier-local annotations preserve intentional malformed-call and
private-clone rejection tests, locked internal analyzer API use, and defensive
runtime/preflight/cleanup checks. They do not exempt files or whole lines from
analysis; unmatched identifiers fail. Controls reuse actual annotations and
prove the immediately following occurrence remains diagnosed. See
QUALITY_ACCEPTANCE.md for dispositions requiring candidate review.

All 36 maintained production PHP files (`bootstrap.php`, `runtime.php` and
production PHP under `src/`) now have direct level-8 roots. Issue #60 records the historical
source acceptance; this coverage count does not certify later exception changes.

`src/Runtime/ReleaseSource.php` is also a level-8 root. Its direct `FS_METHOD`
inspection deliberately avoids filesystem negotiation; the line-specific
`phpstanWP.wpConstant.fetch` exception preserves the contract exercised by
`PublicReleaseSourceTest`. `TemporaryArtifact::discard()` is marked impure because
it mutates custody/filesystem state and cleanup retries can return different
results. Neither change weakens analysis for the rest of the file.

`src/Provider/GitHub/GitHubReleaseService.php` is a direct level-8 root.
Its listing helpers share precise PHPStan candidate/result shapes. The sort
callback explicitly rejects an invalid version comparison; adapter tests cover
malformed-tag filtering, descending SemVer order and the existing identity tie-break.

`src/WordPress/NativePackageUpdater.php` is also directly analysed at level 8.
Missing binding state or release descriptors take the existing failure/cleanup
paths before typed receipt or archive operations. Native lifecycle tests cover
missing-descriptor rejection, archive cleanup and lease release.

`src/WordPress/BindingFenceCoordinator.php` is a direct level-8 root.
Its verification result distinguishes success (state and database time present)
from a lost fence. Local callable guards retain duck-typed database objects,
including magic-method proxies, without requiring a new interface. SQL, lease
and exact-value compare-and-swap behaviour remain covered by existing tests
and the native MySQL proof.

`src/Runtime/RequestBroker.php` is a direct level-8 root. Local callable
checks supplement source ownership and exact-method validation; they do not
admit magic or inherited extra methods. Eight unused private validator
forwarders were removed. The `validNativeStatus` Reflection seam remains,
with only its `method.unused` diagnostic ignored at the declaration because
`ConciseRegistrarTest` calls it directly; its body remains analysed.

`runtime.php` is a direct level-8 root. Its handoff arrays have explicit element
types, and native handle calls check callability while retaining the object-based
fixture seam. Theme directory values are passed as a list without changing their
order or mutating WordPress globals; a regression covers sparse and string keys.
Selected-root validation, the sealed provider catalog and liveness checks remain
in place. The runtime-copy revision is regenerated from the shipped PHP payload.

`bootstrap.php` is also a direct level-8 root. Broker reflection accepts only
already-loaded classes; native provenance checks still determine ownership.
Dynamic broker calls retain the existing object-based fixture contracts and
failure handling. The artifact ownership predicate communicates its checked
type to PHPStan so the existing impure cleanup retry remains analysed without
suppression. Bootstrap/provenance, public release-source schema and registrar
lifecycle tests cover these boundaries.

The generated `src/Dependency/ArchiveSafety.php` is explicitly analysed at level 8.
Its source remains owned by the pinned `ran/updater-support` dependency;
`scripts/sync-updater-support.php --check` requires namespace-only byte parity.
Future fixes belong upstream and must arrive through the reviewed dependency and
copy-sync process. Direct analysis does not remove its generated-code PHPCS
exclusion or permit local edits to the copied helper.

Focused commands retain the same underlying tools and boundaries:

| Command | Scope |
| --- | --- |
| `composer lint:syntax` | Parse root bootstrap/runtime files and PHP in `src`, `scripts`, and `tests`. |
| `composer standards` | PHPCS using the repository `.phpcs.xml`. |
| `composer standards:fix` | PHPCBF using the same ruleset and paths; modifies files. |
| `composer analyze` | Existing level-8 PHPStan analysis on the configured paths. |
| `composer test` | Unit tests, then the installed no-dev consumer proof. |

`test:unit` and `test:no-dev-consumer` remain available individually.
The architecture suite also invokes the actual locked PHPCS checker to prove
occurrence-local native-operation exceptions cannot hide an unrelated native read
or silenced expression in the affected production/tool paths or future archive/tool
files. Existing custody, installed-identity, deletion and rollback tests retain
the behavioral evidence; this standards refinement does not change those behaviors.
The obsolete `lint:php`, `format:php`, and `check:php-style` names have been
replaced by `standards` and `standards:fix`. Composer audit remains blocking
inside `check`; it uses live advisory data and requires network access.

It is the baseline repository gate, not the complete environment matrix.

The CI quality job additionally requires JavaScript quality/workflow-contract checks and the isolated MySQL CAS lifecycle proof. CI also has separate PHP 8.5, Windows portability, and installed-WordPress jobs.

## Maintained CI environment

The maintained matrix currently exercises:

- PHP 8.2 as the package floor;
- PHP 8.5 compatibility;
- Windows portability for syntax/runtime-path assumptions;
- installed WordPress integration on WordPress 6.5 and 7.1;
- an isolated MySQL proof for the persistent binding/fence CAS lifecycle.

Those rows are evidence for the tested boundaries, not a claim that every PHP/WordPress/host combination between them has been exhaustively exercised.

## Installed WordPress integration suite

Run:

```sh
composer test:wordpress-integration
```

The suite verifies two maintained guarantees in a disposable WordPress installation:

1. the updater operates from its installed distribution rather than only from the repository checkout;
2. the same target observed from the main site and a subsite shares one network-scoped operation fence.

The suite is intentionally separate from `composer check` because it needs a real WordPress source tree and local MySQL process.

### Prerequisites

The suite requires:

- PHP 8.2 with `zip` and `mysqli`;
- WP-CLI 2.12.0;
- an executable `mysqld`;
- a pristine WordPress source tree for the version being tested;
- durable writable directories owned by the operator for the integration run and local socket.

CI downloads WordPress 6.5 and 7.1 using `wp core download --skip-content`. Use those versions when reproducing the maintained matrix locally.

Set the following paths before running the suite:

```sh
export RAN_UPDATER_WP_CLI=/path/to/wp
export RAN_UPDATER_MYSQLD_BIN=/path/to/mysqld
export RAN_UPDATER_WP_ROOT=/path/to/pristine-wordpress
export RAN_UPDATER_INTEGRATION_ROOT=/path/to/durable/integration-root
export RAN_UPDATER_SOCKET_ROOT=/path/to/short/durable/socket-root
composer test:wordpress-integration
```

`RAN_UPDATER_INTEGRATION_ROOT` and `RAN_UPDATER_SOCKET_ROOT` must already be writable, non-symlink directories. Keep them outside disposable OS temp locations. The suite creates marker-owned children and removes only state it can prove it owns.

If the normal repository workspace makes MySQL's Unix socket path too long, use a separately authorized short directory for `RAN_UPDATER_SOCKET_ROOT`.

Missing prerequisites and failed scenarios are failures, not skipped passes.

## Other focused suites

The Composer scripts expose narrower proofs for specific boundaries:

```sh
composer test:wordpress-bulk
```

For the source-release workflow contract, run `pnpm test`; run `pnpm check` for the full JavaScript audit, formatting, lint, and test aggregate after `pnpm install --frozen-lockfile` with the repository's pinned Node/pnpm toolchain.

Release Please owns this Composer package's version, changelog, release PR, tag, and GitHub Release lifecycle through the pinned shared Profile A caller. The JavaScript contract verifies that caller and normal input-free, read-only candidate CI. The PHP suite retains `runtime-copy.json` version/manifest consistency, the Release Please `extra-files` version adapter, canonical runtime content identity, provenance/fail-closed behaviour, archive exports, and the full Quality fan-in. These are product and caller contracts, not a repository-local publisher or version engine.

Use the focused suite when changing its boundary, but do not substitute it for `composer check` or the relevant CI matrix.

Provider behavior is covered by the maintained unit/contract suite inside `composer check`.

The real-MySQL CAS proof is run by CI's quality job rather than being presented as a standalone consumer feature. It protects the persistence/fencing implementation against stale or competing writers.

## What the repository tests prove

Within their tested fixtures/environments, the suite provides evidence for:

- package metadata and installed no-dev consumer behaviour;
- provider-neutral and GitHub provider contracts;
- archive/path/custody validation rules;
- native plugin/theme callback behaviour;
- sealed physical-runtime selection and compatibility checks;
- persistent binding/fence behaviour, including real MySQL CAS semantics;
- WordPress 6.5/7.1 installed-distribution behaviour;
- the bounded multisite target/fence guarantee;
- Windows-sensitive runtime/path behaviour.

## What the repository tests do not prove

The project deliberately avoids overstating its matrix. In particular:

- **No live GitHub dependency in the installed WordPress suite.** Provider behaviour is proven with controlled fixtures/contracts; the installed suite does not make a release-updater test depend on GitHub availability or quota.
- **No Booster checkout in the package integration suite.** Booster is a downstream consumer, not a test prerequisite for the reusable library.
- **No FTP/SSH WordPress filesystem support.** The product supports the `direct` filesystem method; alternative transports are outside both the runtime support contract and the maintained test guarantee.
- **No claim about every WordPress version.** CI tests the maintained floor/current rows, not every intermediate or future WordPress build.
- **No claim about every PHP version.** PHP 8.2 and the maintained compatibility row are explicit; other environments need their own evidence.
- **No full multisite administration proof.** The suite proves shared target/fence behaviour across main/subsite contexts, not network activation, inventory, UI, or host policy.
- **No hostile same-user sandbox.** Tests cannot make the updater a security boundary against arbitrary PHP/processes with equivalent filesystem/database authority.
- **No product-specific scheduling/UI proof.** WordPress and the embedding application own those behaviours.

These limits should remain visible in public documentation because they define where an integrator needs additional product-specific testing.
