# RAN WordPress Release Updater

`ran/wp-release-updater` is a provider-neutral Composer library for offering
verified WordPress plugin and theme releases through WordPress Core's native
update lifecycle.

This fresh package line begins at `v0.1.0-beta.1`; it does not continue the
version history of `ran/wp-github-release-updater`. Consult the GitHub Releases
page for supported published versions rather than treating a branch or commit
as a release.

The package namespace is `RAN\WPReleaseUpdater\V1`. The initial production
catalog will contain the built-in GitHub adapter only. Additional providers
are not supported unless a later release explicitly admits them.

The package deliberately conflicts with `ran/wp-github-release-updater` and
does not replace or provide that legacy coordinate. Consumers must make a hard
cut to one package or the other.

## Development status

The repository now contains the unreleased neutral kernel, its selected-runtime
broker, the built-in GitHub release-protocol adapter, and a concrete GitHub
composition entrypoint for the forthcoming Core migration. Constructing and
registering that entrypoint performs no remote or credential work. No installed
production consumer calls it yet, so there is still no supported installation
path. Do not depend on a branch or commit as though it were a release.

The kernel uses opaque provider identities, full canonical Update URI equality,
locally verified SHA-256 archive custody, and binding-aware target fences.
Add-ons cannot register another updater runtime or extend it at request time.

The native lifecycle currently fails closed unless WordPress selects its
`direct` filesystem method. Its custody checks trust code already running as
the WordPress filesystem user: peer PHP with that same operating-system access
can rewrite plugin files outside any updater hook and is not sandboxed by this
library. The updater still compares the extracted and installed file inventory
with the inspected archive before accepting completion.

## Internationalization

This Composer library is embedded by host plugins and themes. It does not own
or load a WordPress text domain or translation catalog.

Diagnostic and error codes remain stable and untranslated. Exception text is
developer-facing and is not localized presentation text.

Configured user-facing plugin information, such as a name or description,
belongs to the host and should already be localized through the host text
domain.

The generic English `WP_Error` message is fallback text only. Consumers that
present updater failures should map `ran_wp_release_updater_*` codes to
host-localized messages rather than parse or rely on that fallback.

Validate the Composer package metadata with:

```sh
composer check
```

Use the issue tracker for ordinary support and non-sensitive defects. Read
[CONTRIBUTING.md](CONTRIBUTING.md) before proposing changes and
[SECURITY.md](SECURITY.md) for confidential vulnerability reporting.

## License and provenance

The project is licensed under `GPL-2.0-or-later`. The full license is in
[LICENSE](LICENSE), and the fresh-lineage source attribution is recorded in
[NOTICE.md](NOTICE.md).
