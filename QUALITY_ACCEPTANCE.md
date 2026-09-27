# PHP quality acceptance

This is the repository's residual acceptance record for
[RocketsAreNostalgic/ran-wp-release-updater#60](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/issues/60).
The implementation is proposed until its PRs are reviewed and merged. This
record does not authorize a merge, package version bump, publication or a Core
dependency update. Release PR #70 remains held by the owner.

## Maintained scope

| Surface | Required evidence |
| --- | --- |
| `bootstrap.php`, `runtime.php`, and 34 PHP files under `src/` | All 36 shipped PHP paths are direct PHPStan level-8 roots. `scanDirectories` is additional discovery, not coverage. |
| Maintained production PHP except the generated helper | PHPCS/PHPCBF use the same shared ruleset and scope. Owned methods and variables are enforced across paths, including new files. |
| `scripts/` | Syntax and shared standards, with method/variable naming enforced. The helper synchronizer's remaining local name is normalized. |
| Generated `src/Dependency/ArchiveSafety.php` | PHPCS excluded; namespace-only byte parity against the locked `ran/updater-support` source and direct level-8 analysis remain required. Never hand-edit this file. |
| `tests/` | Existing purpose-built fixture/harness naming boundary remains. Production callers, named arguments, callbacks and Reflection seams follow the renamed API. Other configured standards, syntax and executable proofs still run. This record does not certify every fixture-local identifier as snake_case. |

`composer check` retains strict validation, generated-copy parity, live advisory
audit, syntax, shared standards/compatibility, level-8 analysis, unit tests and
the no-dev consumer proof. Native PHP 8.2/8.5, installed WordPress 6.5/7.1,
MySQL CAS, Windows portability and JavaScript checks still feed terminal
`quality`. No workflow or stronger gate is removed.

## Retained exceptions

The exact rule/path list lives in `.phpcs.xml`; these are local product
requirements, not shared-standard exemptions.

| Boundary | Reason and retained protection |
| --- | --- |
| Internal exception text | It is closed failure data, not HTML output. EscapeOutput's exception diagnostic is disabled; public failure projection and message-sanitization tests remain. |
| Native JSON | Protocol hashes and canonical bytes require native `json_encode` flags and `JSON_THROW_ON_ERROR`, including before WordPress helpers exist. JSON/hash regression tests remain. |
| Native URL parsing | Provider-neutral canonicalization/bootstrap cannot require WordPress URL helpers. Canonical URI and redirect rejection tests remain. |
| Native filesystem and local warning suppression | Archive/custody, installed identity, atomic persistence and cleanup require native identity/permission/rename checks. Scope is limited to the listed production files/directories plus tooling/fixtures; archive, symlink, cleanup, Windows and no-dev proofs remain. |
| Trusted local metadata reads | Bootstrap and RuntimeCopySelector read verified local metadata. Only their native file-read diagnostic is excepted; runtime content identity/provenance and selection/refusal tests remain. |
| Prepared SQL identifier/CAS boundary | BindingFenceCoordinator validates the table identifier and prepares data values. Only two SQL parser false-positive rules are excepted for that file. MySQL CAS and setup-failure proofs remain required. |
| Bounded base64 operation tokens | NativePackageUpdater uses URL-safe base64 for opaque operation tokens, not executable code. Only its encode/decode rules and test fixtures are excepted. |
| Native `ZipArchive::$numFiles` | Two line-local property-name ignores cover three reads of the external extension property. Owned members remain enforced. |
| Test harness/fixture syntax | Synthetic namespaces, globals, hooks, subprocesses, SQL doubles and deliberately invalid input preserve their test purpose. The existing explicit condition/reserved-parameter and other fixture scopes remain; they do not exempt production. |

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

Core at `0c1ace618331a23e068cec6e54a896c634bc8f76` still locks updater
`0.1.0-beta.7` and declares `extra.ran-updater-runtime-protocol: 4`. Its
`tests/WordPress/ReleaseUpdaterBootstrapTest.php` and
`tests/WordPress/github-release-updater-bootstrap-smoke.php` assert protocol 4
through `protocolVersion()`. Those checks and the metadata must change together
with an installable released dependency adoption. Do not relax them to accept
both protocols or record protocol 5 beside the old lock. Core #177's separate
release-management work is not modified by this cohort.

The bounded tracked-source audit also covered Provider
`7cd2c0624e2a41ccd8c2b1e879ac743eddb8f800`, Bitbucket
`efcf2a36da14ae3775980f5e8616693fff645b21` and Migrator
`829d823afd87923a20f8170671d2f456465424d9`. No actual consumer of the renamed
archive/provider internals was found there. This is not an inventory of
untracked third-party consumers or a certification of a new Core tuple.

Before #60 closes, record exact reviewed heads, applicable native CI, merged
main qualification and the organisation matrix/adoption reconciliation. Retarget
and requalify dependent PRs after squash merges. Portable PHP 8.4 lacks
`GLOB_BRACE`; its two architecture errors are not a green full aggregate.
Native CI provides separate qualification. Publication and Core adoption remain
separate owner decisions after implementation acceptance; use Release Please,
never manual tags.
