# PHP quality acceptance

This is the repository's residual acceptance record for
[RocketsAreNostalgic/ran-wp-release-updater#60](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/issues/60).
That implementation and historical release PR #70 have since merged. This
record does not authorize another merge, package version bump, publication or a
Core dependency update. The native-operation refinement below was delivered separately in PR #95.

## Maintained scope

| Surface | Required evidence |
| --- | --- |
| `bootstrap.php`, `runtime.php`, and 34 PHP files under `src/` | All 36 shipped PHP paths are direct PHPStan level-8 roots. `scanDirectories` is additional discovery, not coverage. |
| Maintained production PHP except the generated helper | PHPCS/PHPCBF use the same shared ruleset and scope. Owned methods and variables are enforced across paths, including new files. |
| `scripts/` | Syntax and shared standards, with method/variable naming enforced. The helper synchronizer's remaining local name is normalized. |
| Generated `src/Dependency/ArchiveSafety.php` | PHPCS excluded; namespace-only byte parity against the locked `ran/updater-support` source and direct level-8 analysis remain required. Never hand-edit this file. |
| All `tests/` PHP, including root files and future/nested roots | Owned methods, variables, Yoda conditions, unused parameters and reserved parameter names have no file/root exclusions. Thirty-one PHPUnit lifecycle overrides, twelve unused foreign-stub parameter reports, the existing skin override parameter and native ZipArchive property uses retain exact local exceptions. No test-path exclusions remain. Global-variable prefixing and other fixture-specific exceptions are occurrence-local as listed below. |

`composer check` retains strict validation, generated-copy parity, live advisory
audit, syntax, shared standards/compatibility, level-8 analysis, unit tests and
the no-dev consumer proof. Native PHP 8.2/8.5, installed WordPress 6.5/7.1,
MySQL CAS, Windows portability and JavaScript checks still feed terminal
`quality`. No workflow or stronger gate is removed.

## Standalone script prefix refinement (historical checkpoint)

The later occurrence refinement below supersedes the two file-wide variable allowances described in this checkpoint.

The whole `PrefixAllGlobals` exemption for `scripts/` is removed. The locked
checker exposed 23 global-variable reports in the two standalone entrypoints
(12 in `lint-php.php`, 11 in `sync-updater-support.php`). Only
`NonPrefixedVariableFound` uses an exact-code file-wide annotation in those two files:
they execute as isolated CLI processes and do not register WordPress globals.
Functions, classes, constants, namespaces and hooks remain checked there.
Future scripts and same-named nested paths do not inherit the variable exemption.
The scripts-wide WordPress global-override exclusion is also removed; the lint
loop now uses the owned `$source_path` name instead of `$path`. Actual-checker
controls keep unrelated WordPress-global overrides visible in current/future
scripts. Production executable bytes, generated copy, dependencies and analysis
settings are unchanged; script behavior is preserved.

## Retained exceptions

Retained allowances live beside their exact source occurrences. `.phpcs.xml`
contains no diagnostic severity below five and no rule-specific file exemptions;
only the separately parity-checked generated file remains excluded as a whole. These are
local product requirements, not shared-standard exemptions.

| Boundary | Reason and retained protection |
| --- | --- |
| Internal exception text | It is closed failure data, not HTML output. EscapeOutput's exception diagnostic is locally excepted only at existing failure occurrences; public failure projection and message-sanitization tests remain. |
| Native JSON | Protocol hashes and canonical bytes require native `json_encode` flags and `JSON_THROW_ON_ERROR`, including before WordPress helpers exist. JSON/hash regression tests remain. |
| Native URL parsing | Provider-neutral canonicalization/bootstrap cannot require WordPress URL helpers. Canonical URI and redirect rejection tests remain. |
| Native filesystem and local warning suppression | Archive/custody, installed identity, atomic persistence and cleanup require native identity/permission/rename checks. The seven affected production files and two tools use occurrence-local diagnostic annotations; test-fixture native and process operations likewise use exact occurrence-local annotations after the refinement below. Checked failure, observed deletion/retry and best-effort cleanup retain their distinct behavior. Archive, symlink, cleanup, Windows and no-dev proofs remain. |
| Trusted local metadata reads | Bootstrap and RuntimeCopySelector read verified local metadata. Only their native file-read diagnostic is excepted; runtime content identity/provenance and selection/refusal tests remain. |
| Prepared SQL identifier/CAS boundary | BindingFenceCoordinator validates the table identifier and prepares data values. Only the actual SQL parser false-positive occurrences are excepted; neighboring queries remain checked. MySQL CAS and setup-failure proofs remain required. |
| Bounded base64 operation tokens | NativePackageUpdater uses URL-safe base64 for opaque operation tokens, not executable code. Only its encode/decode rules and test fixtures are excepted. |
| Native `ZipArchive::$numFiles` | Two line-local property-name ignores cover three reads of the external extension property. Owned members remain enforced. |
| Test harness/fixture syntax | Synthetic namespaces, globals, hooks, subprocesses, SQL doubles and deliberately invalid input preserve their test purpose. Owned free functions/classes/constants follow the package prefix and owned test namespaces use `RAN\WPReleaseUpdater\V1\Tests` without namespace exceptions. Existing shared-state/global-variable prefix allowances are occurrence-local; foreign signatures, synthetic syntax and native/process exceptions are diagnostic-local. No test-path exclusions remain. These retained boundaries do not exempt production. |

Seven declaration-local PHPStan exceptions remain: RequestBroker's
Reflection-invoked private validation seam; ReleaseSource's direct-filesystem
constant inspection; and five InstalledPackageResolver Reflection-assigned
property type seams. Bodies remain directly analyzed at level 8. No baseline,
new analysis exclusion or lower level is introduced.

## Runtime and public-name transition

The WordPress/SelectedRuntimeState and runtime/bootstrap cohorts must land as
one coordinated transition. The broker interface generation advances from 4
to 5 because owned methods and selected-state interfaces change. The package
version is unchanged by these implementation commits; Release Please retains
version ownership.

Registrar named arguments now use `plugin_file`, `stylesheet_file`,
`repository_id`, `update_policy`, `maximum_artifact_bytes` and `package_type`.
Release-source operations use `release_id`, `expected_tag` and
`expected_fingerprint`. Current public guides and executable examples follow
these names. Broker methods use snake_case, including `protocol_version()`.
No coexistence aliases are introduced. Persisted binding/descriptor keys,
provider data schemas, fingerprints and native hook identifiers are unchanged;
`runtime_protocol`/diagnostic protocol values intentionally advance to 5.
Private PHP object layout is not a supported saved wire format.

A committed purpose-built mixed-interface test covers both load orders and
asserts conflict/inactivity without replacing or mutating the established
broker. A separate direct proof against actual main
`58851628c93f554e89ae75bde9ee37e7cf57a5a9` confirms the same result in both orders.
This is fail-closed coexistence, not a claim that both generations can update
targets together in one request.

## Connected adoption and closure

The historical adoption snapshot recorded Core at `0c1ace618331a23e068cec6e54a896c634bc8f76` locking updater
`0.1.0-beta.7` and declaring `extra.ran-updater-runtime-protocol: 4`. Its
`tests/WordPress/ReleaseUpdaterBootstrapTest.php` and
`tests/WordPress/github-release-updater-bootstrap-smoke.php` asserted protocol 4
through `protocolVersion()`. Those checks and the metadata had to change together
with an installable released dependency adoption, without relaxing them to accept
both protocols or recording protocol 5 beside the old lock. Core #177's separate
release-management work was outside that cohort. This is historical evidence,
not a statement of the current Core dependency tuple.

The bounded tracked-source audit also covered Provider
`7cd2c0624e2a41ccd8c2b1e879ac743eddb8f800`, Bitbucket
`efcf2a36da14ae3775980f5e8616693fff645b21` and Migrator
`829d823afd87923a20f8170671d2f456465424d9`. No actual consumer of the renamed
archive/provider internals was found there. This is not an inventory of
untracked third-party consumers or a certification of a new Core tuple.

Issue #60 is closed; its source acceptance is historical evidence, not blanket
acceptance of future exceptions. Record exact reviewed heads, applicable native
CI and merged-main qualification for this refinement. Retarget
and requalify dependent PRs after squash merges. Portable PHP 8.4 lacks
`GLOB_BRACE`; its two architecture errors are not a green full aggregate.
Native CI provides separate qualification. Publication and Core adoption remain
separate owner decisions after implementation acceptance; use Release Please,
never manual tags.

## First ordinary-test naming cohort

This cohort landed in PR #96 at `a670e7681eac7119fcfecd07cc35c5abea852343`,
independently of this native-operation refinement. It covers four
Archive tests, five Contract tests and one Dependency test. The locked checker
exposed 115 naming diagnostics: 71 owned method declarations, 38 variable or
interpolation occurrences, and six PHPUnit lifecycle overrides. Owned names and
their calls/DataProvider references are normalized; the six required foreign
overrides use exact declaration-local exceptions. Yoda, unused-parameter and
reserved-parameter rules were already active and clear in this cohort.

The existing Dependency test hosts a small actual-checker regression: clean
sources pass; inherited owned camelCase methods and camelCase variables fail at
current and future paths in each selected root. An outside-cohort Runtime path
retains its previous boundary. Test discovery and every existing dataset identity
are preserved after the explicit owned-name mapping; only this regression adds
a test. No production, dependency, runtime identity, API or fixture wire format
changes are part of this proposal. Other test roots and native/fixture exceptions
remain separate work; this is not acceptance of the full test surface.

## Native-operation exception refinement

Removing the production/tool category exclusions exposes 95 diagnostics across
seven production files and two scripts. Exact occurrence annotations preserve
native identity, locks, bounded streams, permissions, atomic replacement and
existing cleanup semantics without changing executable PHP tokens. Local warning
suppression remains where return values or later observations drive failure, and
where cleanup was already deliberately best-effort; annotations distinguish them.

Two architecture regressions run the actual locked checker on each affected path
and future archive/tool paths: an unrelated native read and an unrelated silenced
expression must independently report their exact diagnostics. These probes do not
execute the supplied paths. The generated helper, dependency lock, public API,
level-8 analysis and separate fixture exceptions are unchanged. Production comment
bytes change the canonical runtime identity, so `runtime-copy.json.package_revision`
is regenerated using the existing sorted-path/content-hash algorithm. Package
version and runtime protocol remain unchanged.

## Provider test naming cohort

The next bounded cohort adds all five `tests/Provider` files and future files in
that root to owned method/variable naming. The initial inventory at main
`a670e7681eac7119fcfecd07cc35c5abea852343` exposed 143
diagnostics: 93 owned method declarations, 46 variable occurrences and four
required PHPUnit lifecycle overrides. Four files need normalization; the named
constructor contract test already complies. The four lifecycle declarations keep
exact local exceptions; WordPress/native stubs and foreign APIs retain their
required names. Existing method calls and DataProvider references follow owned
renames, while dataset identities, named production arguments, Reflection targets,
wire strings and mutable callback references remain unchanged.

The existing Dependency checker regression adds current and future Provider
paths, preserving the outside-cohort Runtime control. No new tests or framework
are introduced. Production, scripts, dependencies, runtime identity and workflow
inputs are outside this cohort; other test roots remain separate work. The final
proposal is integrated onto main `cbed2d1a856d3ed8e98d6494a54cbf72495921e6`
after PR #95, retaining its native-operation protections and acceptance record.


## Runtime test naming cohort

Provider naming landed in PR #98 at
`52acceaf5c3d3f195756ce5f31f30408b89a09dd`. From that exact main, the
13 Runtime test files expose 207 naming diagnostics: 136 owned method
declarations, 58 variable occurrences and 13 required PHPUnit lifecycle
overrides. Owned methods, callers, variables and nine DataProvider reference
strings are normalized together. Lifecycle overrides retain exact declaration-local
exceptions. Embedded PHP payloads, intentional `protocolVersion` controls,
Reflection targets, production named arguments, foreign APIs, `$GLOBALS` and
by-reference observations are preserved.

The existing Dependency checker regression adds current and future Runtime
paths and moves its outside-cohort control to WordPress. It now exercises
11 paths with clean, inherited-method and variable controls (132 assertions).
Restoring the old scope fails the Runtime control. All 477 test/dataset
identities remain after explicit owned-name mapping; Runtime retains exactly
130 tests / 1,155 assertions. The canonical Composer aggregate passes with
477 tests / 25,100 assertions, including level 8, live advisory audit and the
installed no-dev consumer. No new test framework or test is added.

This proposal changes tests, checker scope and guidance only. Production,
scripts, runtime identity, dependencies, public APIs, JavaScript and workflows
remain unchanged. Other test roots and retained-exception acceptance remain
separate work under organisation #65/#128; no release is authorized.


## WordPress and shared-support test cohort

Runtime naming landed in PR #99 at
`fd2a7daa19f9a17f6b429d0863ea2726e46d6238`. This proposal adds all four
WordPress tests and six Support PHP files, including future and nested files,
to owned method/variable enforcement. Nine files need normalization; the
failing-runtime fixture already complies. The locked checker exposes 600 naming
reports: 99 owned-method checker reports (93 owned declarations and six required
PHPUnit lifecycle overrides), ten overlapping WPCS method reports, and 491
variable/property declaration or use reports. This is checker exposure, not a
count of distinct defects. The six lifecycle methods retain exact local exceptions.

Seven DataProvider reference strings follow their owned declarations. The shared
fake database's two callers in AcquisitionReceiptTest are updated in the same
change. Other fixture APIs, named production arguments, Reflection targets,
embedded payloads, wire keys, GLOBALS, by-reference captures, callback order,
rollback observations and deletion/identity fences are preserved. Hook-fixture
accepted_args spelling is normalized consistently with its positional callers.

One additional Yoda finding in MysqliOptionDatabase is corrected by swapping the
pure strlen comparison operands; its file exclusion is removed. The existing
Dependency regression covers 15 naming paths (current/future selected roots and
an outside Integration path), plus current/future Support Yoda controls. It passes
188 assertions. Restoring either the old naming scope or the old MySQL Yoda
exclusion makes the corresponding control fail.

All 477 test/dataset identities remain under the explicit owned-name map.
WordPress before and after remains 86 tests / 904 assertions. The canonical
Composer aggregate passes 477 tests / 25,156 assertions, level 8, live advisory
audit and the installed no-dev consumer. Token comparison permits only owned
renames, seven provider strings and the single pure comparison swap.
Production, scripts, public APIs, runtime identity, dependencies, JavaScript and
workflows are unchanged. Other test roots and exception acceptance remain under
organisation #65/#128. This is not release authorization.


## Integration harness naming cohort

WordPress/Support naming landed in PR #100 at
`9ca4d7b91ae5815955d37a1bfd19a20889da89d3`. This proposal adds all eleven
Integration PHP files and future/nested files to owned method and variable
checks. Ten files need normalization; the no-dev consumer already complies.
The locked checker exposes 772 naming reports: one owned method declaration
and 771 variable/property/interpolation reports. Two property uses are the native
ZipArchive::numFiles API and retain exact occurrence-local exceptions. The other
39 condition/signature reports (23 Yoda, 13 unused parameters, three reserved
parameter names) retain their existing, separately reviewed scope; this cohort
adds no exclusions for them and does not claim their acceptance.

Owned identifiers are normalized while free-function API names, environment
keys, embedded fixture payloads, foreign signatures, Reflection targets, globals,
reference captures, control flow and cleanup fences remain intact. One compact()
evidence assembly becomes an explicit array so all sixteen existing output keys,
order and values remain unchanged. A differential evaluation verifies that
mapping. Two source-string expectations in the existing security contract test
follow the variable renames without weakening either positive or negative checks.

The existing checker regression now exercises nineteen naming paths, including
current/future nested Integration paths and an outside Performance control;
its two previous Support Yoda controls remain. It passes 236 assertions.
Restoring the previous naming scope fails the Integration control. All 477
PHPUnit test/dataset identities are unchanged. Canonical Composer passes
477 tests / 25,204 assertions, level 8, live advisory audit and installed no-dev.
All twelve plugin/theme public-consumer scenarios and the MySQL setup-failure
cleanup proof pass locally. The full Integration token comparison permits only
owned renames and the explicit compact-key mapping (plus formatting/comments).

Native CI qualifies the maintained installed WordPress and MySQL suites. The
legacy Local-only phase-2.4 and mixed-bulk suites require external Local/MySQL/
WordPress prerequisites unavailable in the coordinator environment; they were
not executed locally for this proposal. Token equivalence and their existing
security-contract tests provide bounded evidence, not a claim of a new full
legacy runtime qualification. No harness guard or prerequisite is relaxed.
Production, scripts, dependencies, public APIs, runtime identity, JavaScript and
workflows are unchanged. Remaining test roots and retained exceptions continue
under organisation #65/#128. No release is authorized.


## Complete test naming and condition/parameter scope

Integration naming landed in PR #101 at
`8ade8e1c62025179b505b900532a8bc41b15b618`. This proposal finishes the remaining
Performance, Architecture, Documentation and root-file naming migration and
removes every test file/root exclusion for the six owned-method/WPCS-method,
variable, Yoda, unused-parameter and reserved-parameter rules. New roots are
covered automatically; the checker and fixer retain the same ruleset.

The remaining baseline has 221 reports: 38 method and 136 variable naming
reports, 28 Yoda reports, fourteen unused parameters and five reserved names.
Thirty-six owned methods and all variables are renamed; two additional PHPUnit
lifecycle methods retain exact exceptions. Twenty-six safe equality operand
swaps preserve strict comparison and short-circuit order. Two already-Yoda
command-line flag comparisons gain parentheses to stop the checker scanning
into preceding function arguments. Four owned reserved parameters are renamed;
the existing skin override parameter keeps its named-call compatibility.
Two unused owned helper arguments and their sole pure call expressions are
removed. Six foreign stub declarations retain local exact unused-parameter
exceptions covering twelve reports. No fabricated variable use, broad new
exclusion, gate relaxation, new API, dependency or persistent state is added.

The existing checker regression now covers 27 current/future/root/nested naming
paths plus condition/parameter controls (372 assertions). Restoring each old
rule-family scope fails its corresponding control. All 477 test/dataset identities
remain under the explicit owned-method mapping; the complete PHPUnit suite
passes 477 tests / 25,340 assertions and the no-dev consumer passes. The native-discovery matrix
passes all 96 rows plus two plugin callback controls and five theme controls;
all budgets, counters, revocation checks and output keys are preserved.
Twelve public-consumer combinations pass when supplied with their required
fence source inputs. MySQL setup-failure cleanup and the existing source-security
contracts remain checked. Production, scripts, runtime identity, dependencies,
JavaScript and workflows are byte-identical to the base.

Local Composer advisory requests suffered intermittent service/proxy timeouts;
a standalone live audit retry passed. Qualification records distinguish that
result, the individually executed remaining gates, and exact-head native CI
rather than claiming a failed aggregate invocation succeeded. Legacy Local-only
phase-2.4/mixed-bulk end-to-end suites remain unexecuted locally because their
external prerequisites are unavailable. Reviewed transformations and existing
security contracts are bounded evidence; prerequisites and deletion guards remain.

This completes these six test rule families, not acceptance of every retained
fixture/global/native-primitive exception or the whole ecosystem. Remaining
exceptions, Admin Shell inventory, UI/manual/operational acceptance and releases
remain separate under organisation #65/#128. No release is authorized.

## Native test-fixture and subprocess exception refinement

This tranche follows #102 at `35476cefdf45cd2c32b6698629b74181180126d9`
(tree `72bd32d9e2a75294b9c05fe59be77e7630b76195`). Removing the six
test-path exclusions exposes 501 warnings in 41 files: 426 native filesystem
operations, 32 warning-suppression reports, 18 `exec`, 14 `proc_open`, one
`shell_exec` and ten `putenv` calls. The 501 reports are not 501 defects.
They are narrowed to 461 exact-code line-local annotations, with repeated
rationales grouped by the actual invariant below. No whole-test-file exception
remains for these six families. Existing global JSON/URL policy and separate
production metadata-read, SQL, base64 and fixture-syntax boundaries remain.

| Boundary | Retained reason and evidence |
| --- | --- |
| Real filesystem fixtures | Setup writes exact package, manifest, header, malformed-input and replacement bytes. Native directories, modes, streams and renames preserve inode/custody/permission boundaries that a WordPress filesystem abstraction would change. Archive, provider, runtime, installed identity and Windows tests exercise them. |
| Native teardown and deliberate deletion | Direct removal preserves existing ownership, link and marker checks. Core-consumption simulations and injected deletion/permission seams stay distinct from ordinary teardown. No new fallback, deletion scope or success assumption is introduced. |
| Warning suppression | Thirty-two reports remain local. Checked copy failure becomes a harness exception; consumer cleanup is observed by absence assertions; unavailable isolated MySQL teardown and ordinary fixture teardown remain best-effort. The original exception survives failure cleanup. No `@` is added or removed. |
| Child processes and pipes | Separate PHP processes isolate bootstrap/global state; isolated mysqld workers prove CAS concurrency. Existing argv arrays or escaped shell arguments, cwd/env, pipe handling and exit/output assertions are preserved. The sole shell lookup is the fixed `command -v mysqld` fallback followed by executable validation. |
| Environment changes | The setup-failure proof restores both prior values in `finally`. The standalone phase-2.4 process configures its own temporary directories and proof marker for descendants. These are test-process environment changes, not persisted product settings. |
| Local inspection and result files | Architecture/docs/workflow assertions read exact repository bytes without WordPress initialization. Installed/measurement harnesses write local proof results for their parent or later inspection. |

The existing architecture checker tests now enumerate all maintained test PHP
and representative future/root/nested paths. Each existing source must remain
clean for these families; appended native reads, silencing, all three process
functions and `putenv` must report their exact codes. Probe text is supplied to
PHPCS stdin and never executed. This exposed the production `/bootstrap.php`
read exclusion also matching test bootstrap files; its pattern now explicitly
rejects every `/tests/` path, with current and nested bootstrap controls.
Production's metadata-read exception remains; no production bytes change.

All 40 changed fixture PHP files outside the expanded architecture guard retain
identical executable tokens and string bytes. Only annotations and required
alignment whitespace change there. No production, scripts, dependency,
runtime identity, API, package version or workflow changes are included.
PHPStan remains level 8. The other 965 reports from the wider test-exclusion
probe remain a separate review inventory, not automatically accepted debt.

Local canonical `composer check` passed, including live advisory audit, syntax,
standards, level-8 analysis, 477 tests / 26,316 assertions and the no-dev consumer
proof. All 477 test/dataset identities are unchanged. The expanded checker tests
pass 2 / 1,108; six old-scope negatives and two bootstrap-collision negatives
prove the scope change, while the production metadata-read exception remains.
The second fixer pass reports no changes. Native discovery measurement passed
96 rows plus two plugin and five theme controls; setup-failure cleanup passed.
Exact-head independent review and native CI remain separate PR qualification.

Legacy Local-only phase-2.4/mixed-bulk end-to-end suites require their original
external prerequisites and are not claimed as locally executed. Native CI
continues to own the installed WordPress, MySQL and Windows qualification.
This refinement does not authorize a merge, release or connected adoption.


## Remaining test-profile closeout

Re-scoped on main `02dbc1166b159701326c71d308663c183bb32a2c`, removing the
remaining test-path exclusions exposed 965 reports (903 errors, 62 warnings)
across 56 files. This proposal removes those exclusions; shared global policy
and production/script exceptions remain. It does not claim zero exceptions or
completion of ecosystem-wide retained-exception acceptance.

Owned declarations now comply: 110 global helpers (including 50 camelCase
helpers), three classes and one constant use the package prefix. Calls follow
the declarations; foreign WordPress signatures, member APIs, wire keys and
fixture payloads retain their identity. The existing Composer `Tests` development
namespace has declaration-local namespace exceptions. It is not added to the
repository-wide accepted prefixes; production and new test declarations using
Tests-prefixed names still fail without an explicit applicable exception. Thirty-two short ternaries become
full ternaries with single evaluation and the same falsy fallback; directory
cleanup retains its original existence fence. One increment receives explicit
parentheses and adjacent generated-PHP literals are combined without changing
output bytes.

At this historical checkpoint, the 515 global-variable prefix reports had a file-wide disposition,
not 515 corrections. Twelve standalone CLI/eval/measurement files retain local
scenario globals; two provider tests retain their shared transport-state globals.
Only `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound`
was disabled in these named files (superseded by the occurrence refinement below):

- `tests/Integration/no-dev-consumer.php`
- `tests/Integration/phase-2.4-wordpress-core-proof-harness.php`
- `tests/Integration/phase-2.4-wordpress-core-proof.php`
- `tests/Integration/real-mysql-cas-proof.php`
- `tests/Integration/real-mysql-cas-setup-failure-proof.php`
- `tests/Integration/release-source-consumer-proof.php`
- `tests/Integration/wordpress-integration.php`
- `tests/Integration/wordpress-integration/distribution.php`
- `tests/Integration/wordpress-integration/multisite.php`
- `tests/Integration/wordpress-native-mixed-bulk-proof-harness.php`
- `tests/Integration/wordpress-native-mixed-bulk-proof.php`
- `tests/Performance/native-discovery-measure.php`
- `tests/Provider/GitHubReleaseAdapterTest.php`
- `tests/Provider/GitHubResponseRoutingTest.php`

That EOF re-enable did not protect new variables inserted inside those files:
new globals there also received the allowance. The later refinement removes it. Variable snake_case,
function/class/constant prefixing and all other naming checks remain active.
The checker guard exercises that distinction using an actual annotated harness,
plus fresh current/future paths where new unprefixed globals must fail.

Other retained reports use exact-code local annotations for foreign stubs,
synthetic namespace/class composition, native WordPress hooks, controlled PHP
literal generation, isolated MySQL calls, denial/serialization probes, opaque
operation tokens, CLI output, deliberate exception observations and liveness
polling. Repeated conditional WP_Error stubs declare their duplicate-class
exception locally so parallel checker ordering cannot change acceptance.

The existing real-checker guard covers these rules at current and future test
paths, including malformed naming and unsafe syntax controls. No production
source, public API, dependency, persistent state, runtime-copy identity, checker
level or workflow changes are included. PHPStan stays at level 8. Installed
WordPress/MySQL proofs require native CI; the legacy Local-only phase-2.4 and
mixed-bulk end-to-end environments remain a separately stated limitation.

Local validation on PHP 8.3.6: `composer --no-interaction check` exits zero;
syntax checks 95 PHP files, PHPCS is clean, PHPStan level 8 is clean, PHPUnit
passes 478 tests / 26,506 assertions and the no-dev consumer proof passes.
Composer audit reports no advisories but falls back to cached Packagist data
after a proxy timeout; fresh advisory verification remains native CI evidence.
The focused profile guard passes 1 test / 190 assertions, with no test discovery
loss (477 existing plus one new guard). Six representative old-scope negative
controls prove the former exclusions hide the newly enforced diagnostics.
PHPCBF repeatability is clean. Native discovery produces 96 matrix rows and its
existing controls; isolated MySQL setup-failure cleanup passes. Literal-token
comparison preserves all changed fixtures except the equivalent generated-config
concatenation. Independent actual base/head review and native CI qualify the
published candidate separately; local evidence alone is not merge admission.


Review correction: the initial candidate added `Tests` to the shared prefix
property, which also admitted Tests-prefixed production declarations. That
allowance is removed. The 43 existing test namespace declarations instead carry
exact namespace-only annotations, and separate namespace/global-declaration
negative probes cover production src, a root entrypoint and future tests.
No executable fixture tokens change in this correction beyond the checker guard.


## Maintained script analysis (proposed)

Both maintained `scripts/` PHP files now enter a separate level-8 invocation of
`composer analyze`; directory selection includes future split and nested scripts.
The independent selection guard checks the whole scripts tree without inheriting
production's root-relative exclusions, and verifies the required level and actual
body diagnostics. The production level-8 symbol world remains unchanged.

This advances direct analysis from 36 to 38 of 96 maintained PHP files. The 58
test files remain unqualified pending isolated-harness profiles; the combined
level-5 probe's 92 diagnostics include incompatible same-name functions from
separate executable harnesses. That is not a reviewed exemption or a justification
for blanket ignores. See docs/testing.md for the reproduced scope and next step.
PHP 8.5 analysis also exposed the CLI argument array as potentially absent.
The sync script uses an empty-array fallback at its two argument reads; normal
CLI invocations preserve their behavior. No production runtime PHP, dependency,
fixture identity or existing exemption is changed.


## Inline sniff-property overrides

The locked checker accepts both `phpcs:set` (including case variants) and legacy
`@codingStandardsChangeSetting` directives that silently replace required prefix
properties. Actual-checker controls prove an unprefixed function is reported
before mutation, hidden after changing the prefix, and an unrelated function
immediately before the directive remains reported. No maintained
source currently needs these directives. The existing coverage suite now scans
all maintained PHP comments, including tests and scripts, and rejects both
spellings case-insensitively. Literal fixture strings and existing diagnostic-local
annotations are preserved. No new checker runner or production changes are needed.


## Maintained test analysis (proposed)

The dependent candidate on #106 extends direct analysis to all 97 maintained PHP
files: production36/tools3 at level8, tests58 at level5. A 43-line CLI adapter
uses the existing PHPStan finder and runs each discovered test file separately;
there is no filename registry, baseline or test-file exemption. Independent
selection, new nested file, exclusion, actual body diagnostic and symbol-isolation
controls extend the existing architecture test. Production PHP is unchanged.

Truthful fixes include policy/probe PHPDoc, the renamed mixed-bulk adapter type,
an assertion helper contract, captured mutable boolean types and explicit shared
process registries. Seven matches now explicitly throw their existing
UnhandledMatchError for unsupported cases; two unused private helpers are removed.
Redundant install-mode alternatives are simplified only inside the already
established install branch. The mixed-bulk HTTP veto accepts zero hook arguments,
matching its zero-parameter callback without changing the veto result.

The test-only conservative function-value setting preserves assertions following
callback-mediated updater mutations; it is not applied to production or tools.
No diagnostic category is globally ignored. Twenty-five occurrence annotations
retain specific, reviewed-in-this-candidate contracts:

- AcquisitionReceiptTest deliberately supplies five invalid argument types and
  one extra argument and must observe TypeError.
- PackageIdentityValidatorTest and TemporaryArtifactCustodyTest exercise private
  clone denial and verify original-object usability after Error.
- ProductionAnalysisCoverageTest uses locked internal FileExcluder/FileHelper
  APIs to match the analyzer CLI's configured-stub filtering.
- The integration entrypoints retain unsupported-PHP preflight, exceptional-path
  cleanup initialization, ownership rechecks and native/WordPress evidence guards.
  The HTTP stub retains the foreign array-or-WP_Error return contract.

Actual source annotations are reused in regression controls proving their intended
occurrence is allowed while the next occurrence of the same diagnostic fails;
existing runtime contract tests continue to prove the negative scenarios. This
is a proposed disposition requiring independent review, not acceptance inferred
from green CI or these explanations.

A separate confirmed test-harness defect is corrected: ZipArchive::statIndex does
not provide external_attributes, so the distribution manifest's former zero
fallback never rejected non-regular types. The helper now reads native attributes
with getExternalAttributesIndex and fails if they cannot be obtained. A regression
runs the exact repository helper against a regular-file ZIP (accepted) and UNIX
symlink ZIP (rejected). This changes test validation, not production/runtime code.


## Occurrence-only WPCS refinement after all-maintained analysis

Based on PR #107 head `4ccf560da39fdff4aa7590a1f3b9f8cfc015a9db`, this
candidate removes the remaining three global severity-zero diagnostics and all
rule-specific XML file allowances. It also replaces all sixteen global-variable
spans: the fourteen test files inventoried above plus the two standalone scripts.
Existing shared variables and embedded fixture bytes are not renamed or rewritten.
Future assignments in those same files now require their own reviewed disposition.

The locked-checker exposure probe produced 691 non-format diagnostics, not 691
proven defects: 549 global-variable prefixes, 58 internal exception messages,
64 native JSON calls, nine native URL parses, two local metadata reads, four SQL
construction reports, three opaque-token base64 operations and two CLI parser
process/output occurrences. Each retained allowance names the exact diagnostic
and concrete contract at its occurrence. Diagnostic counts do not establish
acceptance; independent candidate review must disposition these groups:

- Existing CLI/shared-state identities remain intact at their exact assignments.
  No allowance applies to future variables in those files.
- Native JSON and URL parsing preserve canonical bytes and bootstrap behavior
  independently of WordPress helper availability or fallback semantics.
- Exception messages remain internal failure data; public sanitized failure
  projections remain protected by the existing runtime tests.
- The options-table identifier is validated and data values are prepared before
  each retained SQL operation. The allowance does not apply to other queries.
- Metadata reads remain local and provenance-checked. URL-safe base64 remains an
  opaque operation-token boundary. Parser subprocesses retain blocking statuses,
  and diagnostic output remains a CLI-only channel.

The existing analysis/standards suites now reject blanket, broad-selector and
case-variant annotations, old settings/ignore directives, file-scoped diagnostic
exceptions, severity below five, success-on-error configuration and checker
arguments that narrow enforcement. They preserve broad root selection and the
dependency exclusions. The generated-helper exclusion now uses an anchored,
relative exact path; real directory probes keep nested same-path and suffix
neighbors checked. Locked PHPCS normalizes separator matching on Windows. Rule
include-pattern narrowing and conditional phpcs-only/phpcbf-only attributes are
also rejected; real conditional method-rule mutations lose the diagnostic and
are caught by the guard.
Real-checker controls reuse actual annotations and require new neighboring
variables, exception throws, JSON/URL calls, SQL, base64 and file reads to fail.
The pre-existing naming controls now also reject new globals inside the former
harness/script exception spans. Owned inherited-method enforcement is exercised
without a command-line sniff override, including actual rule-removal mutation.

All executable PHP token sequences and inline HTML outside the two changed guard
test classes are unchanged. Production annotation bytes necessarily change the
content-addressed `runtime-copy.json.package_revision`; its deterministic refresh
preserves package version, runtime protocol and every runtime capability. The
generated dependency bytes and locked tooling remain unchanged. This candidate
requires exact-head independent review and native CI; it does not authorize merge,
release, consumer adoption or ecosystem-wide acceptance.


## Required-rule guard correction

The structural ruleset guard now checks the complete reviewed local rule
inventory and rejects removal of each canonical declaration. The existing real
checker proof already required the owned-method diagnostic from the canonical
profile and demonstrated its disappearance when `RANOwnedMethods` was removed;
that protection remains. This correction closes the narrower helper/mutation
contract gap, not a demonstrated bypass of the whole canonical suite.

The change affects only the existing guard and stale namespace guidance. It does
not alter checker policy, maintained-file scope, runtime bytes or dependencies.

## Effective analysis and executable templates (#109)

The maintained-file guard inspects complete non-PHP file contents for PHP opening
or short-echo tags, including HTML-prefixed templates and unknown suffixes. Only
Markdown retains a bounded entrypoint check: README and other guidance contain
inert PHP examples. Leading PHP entrypoints in Markdown are still detected. This is a
discovery boundary for documentation, not a directory or declaration exemption.

The effective `ignoreErrors` list must be empty in production and tooling as well
as isolated test analysis. Regression controls exercise direct and imported
suppression of a real `return.type` diagnostic and require the independent
coverage guard to reject both. Existing exact inline contract exceptions remain
unchanged. No runtime bytes, dependencies or analysis levels change.
