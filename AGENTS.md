# Agent guidance

This repository is the fresh Git lineage for the provider-neutral
`ran/wp-release-updater` Composer package. Keep every committed file suitable
for eventual public disclosure.

## RAN quality profile

This repository uses the RAN `php-library` quality profile. PHP coding and
compatibility ancestry comes from `ran/coding-standards` through
`RANWordPressLibrary`; the tracked Composer lock binds the published v1.0.0 release
under the `^1.0` development constraint.
`RANOwnedMethods` and variable naming cover all maintained PHP, including every
current and future test root. Yoda conditions, unused parameters and reserved
parameter names likewise have no test file/root exclusions. Required foreign
signatures use exact declaration-local exceptions recorded in acceptance.
Test native filesystem, warning-suppression, subprocess and environment
operations also use exact occurrence-local exceptions. Preserve their real
identity/permission/stream semantics, process isolation and observed failure
or best-effort teardown behavior; unrelated new calls remain checked.
The test profile has no test-path exclusions. Owned free functions, classes and
constants use the package prefix; the existing `Tests` dev namespace uses declaration-local namespace exceptions.
Foreign signatures and fixture syntax use exact local diagnostic annotations.
The former fourteen harness/shared-state and two standalone-script global-variable
spans are removed. Existing process/shared-state assignments retain exact
occurrence-local prefix exceptions so their identities stay intact; unrelated
new globals, functions, classes, constants and hooks remain checked. Native
JSON, URL parsing, internal exception text, local metadata reads, SQL and opaque
operation tokens also require exact diagnostic annotations, never severity-zero
or whole-file rules. The existing coverage guards reject broad/case-variant
suppressions, local rule exclusions, weakened severity and checker arguments,
and exercise real diagnostics immediately outside retained annotations.
The generated `src/Dependency/ArchiveSafety.php` stays PHPCS-excluded and is
namespace-only parity checked against the locked upstream package. All 36
shipped PHP files, including that generated copy, remain directly analysed at
PHPStan level 8.
Analysis defaults to the repository root, including new root/nested PHP and the
generated helper. Root-relative tests/scripts, dependencies and disposable state
are explicit exclusions; a production subdirectory with the same name is not.
ProductionAnalysisCoverageTest compares effective analyzer selection against
independent maintained-file discovery and protects new, split and excluded files.
The separate `phpstan-tools.neon` level-8 invocation in `composer analyze`
automatically includes all `scripts/` PHP without adding script symbols to
production analysis. The same coverage guard checks that profile independently.
Inline sniff-property overrides (`phpcs:set` and legacy
`@codingStandardsChangeSetting`) are forbidden in maintained PHP comments.
The existing coverage suite scans all maintained PHP for these directives,
case-insensitively; fixture strings remain data.
Every maintained test PHP file now enters a separate level-5 invocation via
`scripts/analyze-tests.php`. Its recursive effective selection defaults to all
`tests/`; new roots and split files need no profile-list update. Keep
`phpstan-tests.neon` free of broad analysis paths: the runner supplies one file
per invocation to prevent unrelated executable fixture symbols leaking in.
Production and tooling retain separate level-8 analysis. No maintained PHP
file is exempt from direct analysis.


Purpose-built test harnesses and fixture interfaces follow owned naming;
actual production calls, named arguments, Reflection seams and callback strings
must follow the production API. This does not exempt a production class because
it inherits or implements an interface. New owned methods must be snake_case;
PHP magic signatures remain recognized. An externally required non-snake name
needs a declaration-local justification, never a whole-class exemption.
Across all tests, owned tests/helpers and provider methods use
snake_case; update DataProvider references with their declarations. PHPUnit
`setUp`/`tearDown` overrides keep exact declaration-local exceptions. Preserve
foreign APIs, Reflection targets, wire keys and embedded fixture bytes.
See `QUALITY_ACCEPTANCE.md` for exceptions, evidence and pending release/adoption
gates. Do not infer release or dependency-adoption authority from naming acceptance.

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
