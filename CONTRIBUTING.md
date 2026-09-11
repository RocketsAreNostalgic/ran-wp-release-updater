# Contributing

The package is in a fresh, pre-release development line. Keep every commit safe
for public review and use Conventional Commits (`feat:`, `fix:`, `docs:`,
`test:`, or `chore:`).

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
