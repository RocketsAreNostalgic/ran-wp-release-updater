# Contributing

The package is in a fresh, pre-release development line. Keep every commit safe
for public review and use Conventional Commits (`feat:`, `fix:`, `docs:`,
`test:`, or `chore:`).

Before proposing a change, validate the Composer package metadata:

```sh
composer check
```

Run the applicable PHPUnit suites for the changed boundary. Maintainers may
also run environment-specific integration and parity gates when their fixtures
are available. Report any unavailable or skipped gate instead of treating
`composer check` as a complete test run.

Changes to provider protocols, archive custody, WordPress lifecycle hooks,
release automation, package identity, or compatibility boundaries need focused
tests and an independent review. Do not commit credentials, signed URLs, raw
provider responses, release ZIPs, temporary files, WordPress runtime state,
`vendor`, or dependency caches.

Use ordinary issues for support and non-sensitive defects. Follow
[SECURITY.md](SECURITY.md) for vulnerabilities; do not submit security details
in an issue or pull request.
