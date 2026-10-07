# Contributing

The package is in a pre-release development line. Keep every change safe for public review. Use a Conventional Commit pull-request title: for an ordinary squash merge, that title becomes the final subject consumed by Release Please, rather than the individual branch commit subjects. Use [this repository's release configuration](release-please-config.json) for classification; bot-owned proposals retain their generated version/changelog and the repository's approved merge requirements. A deliberately approved merge commit preserves individual commits, so their Conventional Commit subjects remain release inputs.

Before proposing a change, run the default Composer quality gate:

```sh
composer check
```

The default gate validates package metadata and the generated updater-support
copy, audits the locked dependencies, checks PHP syntax and PHPCS, runs the
configured PHPStan analysis, executes PHPUnit, and proves the no-dev consumer
installation path. Run the applicable additional environment-specific suites
for the changed boundary rather than treating `composer check` as the entire
verification matrix.

For focused commands and their scope, see the command table in
[Testing and verification](docs/testing.md#default-quality-gate).

The installed WordPress integration suite is separate from `composer check`.
It verifies installed-distribution operation and that main-site and subsite
discovery share one operation fence. It has no Booster checkout or live
site/provider dependency; unavailable prerequisites and failed scenarios are
failures, never silent skips. See [Testing and verification](docs/testing.md)
for the maintained matrix, local prerequisites, and the limits of those proofs.

Changes to provider protocols, archive custody, WordPress lifecycle hooks,
release automation, package identity, or compatibility boundaries need focused
tests and an independent review. Do not commit credentials, signed URLs, raw
provider responses, release ZIPs, temporary files, WordPress runtime state,
`vendor`, or dependency caches.

Use ordinary issues for support and non-sensitive defects. Follow
[SECURITY.md](SECURITY.md) for vulnerabilities; do not submit security details
in an issue or pull request.

Owned test namespaces use `RAN\WPReleaseUpdater\V1\Tests` with matching
Composer development autoload and fixture references. They are checked by the
ordinary prefix rule; an existing development namespace is not a foreign-contract
exemption. Intentional unprefixed checker fixture bytes remain negative controls.
New owned tests must use the compliant namespace without adding a namespace
suppression; update connected loaders and references when moving existing tests.
