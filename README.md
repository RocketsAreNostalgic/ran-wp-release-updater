# RAN WordPress Release Updater

`ran/wp-release-updater` is a Composer library that lets a WordPress plugin or theme receive verified updates from versioned release assets through WordPress Core's native update lifecycle.

GitHub Releases is currently the only built-in provider. The package validates repository/release identity, release ZIP metadata and package identity before handing an update to WordPress, then verifies the installed package before accepting completion. WordPress remains the installer.

The package is currently in beta. Use a tagged beta release and test the complete update journey in the plugin or theme you actually distribute.

## Documentation

- [Integrating the release updater](docs/integration.md) — lifecycle, registration timing, configuration, release packaging, credentials, runtime behaviour, diagnostics, multisite and gotchas.
- [Release-source API](docs/release-sources.md) — advanced request-local `releases()` operations for verified release metadata/bytes without native installation.
- [Architecture and package relationships](docs/architecture.md) — trust boundaries, WordPress ownership, runtime selection, relationship to the branch updater, `ran/updater-support`, and Booster.
- [Testing and verification](docs/testing.md) — repository test layers, installed-WordPress setup, maintained matrix, and explicit limits of the test environment.
- [Contributing](CONTRIBUTING.md) and [Security](SECURITY.md) — development workflow and vulnerability reporting.

## Requirements

- PHP 8.2 or newer
- WordPress 6.5 or newer
- PHP Hash, JSON, and Zip extensions
- Composer 2 for installation/development
- WordPress's `direct` filesystem method for supported discovery/installation

## Install

If the package is not already available through a Composer registry configured for your project, add its GitHub repository and require a tagged beta:

```sh
composer config repositories.ran-wp-release-updater vcs \
  https://github.com/RocketsAreNostalgic/ran-wp-release-updater.git
composer require ran/wp-release-updater:^0.1@beta
```

The package deliberately has no production Composer autoload mapping. Your plugin/theme owns its Composer repository declaration, lock file and production bundle. The ZIP you distribute must include this dependency under `vendor`; production WordPress sites should not need Composer to update your package.

## Quick start: plugin

Your installed plugin metadata must identify the same repository and version family the updater will manage:

```php
<?php
/**
 * Plugin Name: Example Plugin
 * Plugin URI: https://github.com/acme/example-plugin
 * Version: 1.2.3
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Update URI: https://github.com/acme/example-plugin
 */
```

Require the updater from the plugin's main file, before `plugins_loaded`, then create and register the target immediately:

```php
<?php

$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';

$releaseUpdater = $registrar->plugin(
    provider: 'github',
    pluginFile: __FILE__,
    repository: 'acme/example-plugin',
    repositoryId: '123456789',
    channel: 'stable',
    updatePolicy: 'manual'
);

$releaseUpdater->register();
```

The repository ID is GitHub's numeric repository `id`, represented as a string—not `owner/repository`:

```sh
gh api repos/acme/example-plugin --jq '.id | tostring'
```

`register()` is passive and idempotent. It admits the declaration into the selected runtime; it does not contact GitHub, resolve credentials, read a ZIP or perform installation. Provider work begins when WordPress runs its normal update lifecycle.

Themes use the same model via `$registrar->theme(...)`. See [Integrating the release updater](docs/integration.md) for theme registration, inactive-theme management and registration timing.

## Configuration at a glance

`plugin()` and `theme()` expose the same core policy:

| Setting | Accepted value |
| --- | --- |
| Provider | Built-in provider code; currently `github` |
| Installed file | Absolute plugin main-file path or theme `style.css` path |
| Repository | GitHub `owner/repository` |
| Repository ID | Positive numeric GitHub repository ID as a string |
| Channel | `stable` or `prerelease`; defaults to `stable` |
| Update policy | `manual`, `automatic`, `forced-off`, or `disabled`; defaults to `manual` |
| Credentials | Optional request-local callable returning a token string or `null` |
| Maximum artifact bytes (`maximumArtifactBytes`) | Positive compressed-ZIP limit; defaults to 52,428,800 bytes |

`manual` publishes a verified native offer. `automatic` additionally permits automatic installation only when the release satisfies the package's stronger immutable/provenance requirements. `forced-off` and `disabled` suppress the native offer.

Custom provider registration is not supported. Unknown provider codes leave a target inactive rather than loading arbitrary implementation code.

## Release asset contract

At a minimum, a compatible GitHub Release must:

- be non-draft and use the supported three-part SemVer subset in either `MAJOR.MINOR.PATCH` or `vMAJOR.MINOR.PATCH` form, optionally with the same prerelease suffix subset such as `1.2.3-beta.1` or `v1.2.3-beta.1`; build metadata (`+...`) is not supported;
- contain exactly one fully uploaded `.zip` asset with GitHub-provided `sha256:` metadata;
- contain one top-level package directory matching the installed plugin/theme identity;
- carry matching package name/version/`Update URI` headers inside the ZIP.

GitHub's generated **Source code** archives are not accepted in place of an uploaded release ZIP.

Use the canonical repository URL for `Update URI`:

```text
https://github.com/owner/repository
```

Do not include credentials, query strings, fragments, release paths or a trailing slash.

The updater freshly rechecks the selected release before installation and verifies the staged/installed package around WordPress Core. Full archive/layout rules and publishing gotchas are in [Integrating the release updater](docs/integration.md).

## Runtime status and failures

Keep the target handle if your product needs request-local inspection:

```php
$releaseUpdater->status();
$releaseUpdater->diagnostics();
$releaseUpdater->refresh();
```

`status()` returns a target envelope with `state`, `declaration_accepted`, `hooks_registered`, `code`, and `native`. When the target is active, installed/candidate/offered release fields live under `status()['native']`, not at the top level. `diagnostics()` returns an envelope with `state` and a nested `diagnostics` list of machine-readable entries. Native admission failures use `WP_Error` codes prefixed with `ran_wp_release_updater_` and a deliberately generic fallback English message. Map those codes to your own localized product messages rather than parsing the fallback string.

Several plugins/themes may bundle different versions of this library in the same WordPress request. That is supported: each bootstrap contributes its physical copy as a candidate, the package elects the highest version compatible with the current PHP/WordPress environment, and only that runtime owns the request. Equal package versions with different runtime bytes, protocol conflicts, and late attempts to replace the selected runtime fail closed. See [Integrating the release updater](docs/integration.md#multiple-bundled-copies-and-versions) for the full selection model.

See [Integrating the release updater](docs/integration.md#status-diagnostics-and-native-failures) for the failure model and important codes.

## Credentials

Pass an optional request-local callback when a private repository or authenticated GitHub quota is needed:

```php
$releaseUpdater = $registrar->plugin(
    provider: 'github',
    pluginFile: __FILE__,
    repository: 'acme/example-plugin',
    repositoryId: '123456789',
    credentials: static fn (): ?string => getenv('EXAMPLE_PLUGIN_GITHUB_TOKEN') ?: null
);

$releaseUpdater->register();
```

Registration does not invoke the callback. Tokens should remain outside plugin source/release ZIPs. The updater does not persist them in target state, diagnostics or URLs. A `null` callback result selects anonymous access; credential/access failures do not silently retry anonymously.

## Read releases without registering an update target

For prospective release metadata/bytes, create a request-local source:

```php
$source = $registrar->releases(
    provider: 'github',
    packageType: 'plugin',
    repository: 'acme/example-plugin',
    repositoryId: '123456789'
);

add_action('init', static function () use ($source): void {
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!WP_Filesystem() || !(($GLOBALS['wp_filesystem'] ?? null) instanceof \WP_Filesystem_Direct)) {
        return;
    }

    $listing = $source->list();
    if ($listing['ok']) {
        // Inspect/select release metadata.
    }
});
```

A release source never installs an archive. Its operations require WordPress's filesystem API to resolve to the `direct` implementation; the example initializes that state and returns without provider work on hosts that require another transport. See [Release-source API](docs/release-sources.md) for `list()`, `inspect()`, `acquire()`, result codes, retry rules and artifact custody.

## Important limits

The current public support boundary includes several deliberate constraints:

- only the WordPress `direct` filesystem method is supported for update discovery/installation;
- GitHub Releases is the only built-in provider;
- provider implementations are sealed package code, not runtime extensions;
- the library does not own product scheduling, inventory/admin UI, multisite network policy or an application cache;
- the updater cannot sandbox arbitrary PHP/processes with equivalent filesystem/database authority.

The repository test suite also has explicit boundaries; for example, its installed-WordPress proof does not depend on live GitHub or a Booster checkout. See [Testing and verification](docs/testing.md) before treating the library's CI as a substitute for product-level end-to-end testing.

## Development

For repository work:

```sh
composer install
composer check
```

`ran/updater-support` is a development/build dependency whose reviewed archive-safety primitives are generated into the sealed distributed runtime. See [Architecture and package relationships](docs/architecture.md) for why this differs from the branch updater's dependency model.

Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing changes. Ordinary defects belong in the issue tracker; security reports follow [SECURITY.md](SECURITY.md).

## License and provenance

The project is licensed under `GPL-2.0-or-later`. See [LICENSE](LICENSE).
