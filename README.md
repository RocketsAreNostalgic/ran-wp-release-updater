# RAN WordPress Release Updater

`ran/wp-release-updater` lets a WordPress plugin or theme receive updates from
versioned release assets without running a second update system alongside
WordPress. The package discovers releases, verifies the selected ZIP, and
publishes an update through WordPress Core's native update lifecycle.

GitHub Releases is currently the only supported provider. Provider selection is
part of the shared plugin and theme registration API, so another built-in
provider can use the same integration path. Custom provider registration is not
supported.

## What it does

For each registered plugin or theme, the updater:

1. asks GitHub for eligible releases in the configured repository;
2. binds the candidate to the repository ID, release, tag commit, and uploaded
   ZIP asset;
3. downloads the ZIP to private temporary storage and verifies its size and
   GitHub-reported SHA-256 digest;
4. reads the WordPress package headers inside the ZIP before offering the
   update;
5. gives the verified offer to WordPress Core; and
6. reacquires and checks the release before installation, then compares the
   staged and installed file manifests before accepting completion.

If a repository, release, asset, digest, archive path, package header, or
runtime requirement does not match, the updater does not offer that release.

WordPress still owns update scheduling, the Plugins and Updates screens,
filesystem access, temporary backups, extraction, installation, restoration,
activation state, and cleanup. This package is a Composer library, not an
activatable WordPress plugin.

## Requirements

- PHP 8.2 or newer
- WordPress 6.5 or newer
- PHP Hash, JSON, and Zip extensions
- Composer 2 for installation and development
- the WordPress `direct` filesystem method for discovery and installation

The package is in beta. Use a tagged beta release and test the complete update
on a non-production site before distributing it with your plugin.

## Install the library

If the package is not available through a Composer registry already configured
for your project, add its GitHub repository first:

```sh
composer config repositories.ran-wp-release-updater vcs \
 https://github.com/RocketsAreNostalgic/ran-wp-release-updater.git
composer require ran/wp-release-updater:^0.1@beta
```

The package deliberately has no production Composer autoload mapping. Your
plugin owns its repository declaration, lock file, and production bundle. The
ZIP you distribute must include this dependency under `vendor`; production
WordPress sites should not need Composer to install it.

## Prepare your plugin

The plugin's main PHP file needs headers that identify the same repository and
runtime requirements used by the updater:

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

Use the canonical repository URL for `Update URI`:
`https://github.com/owner/repository`, without credentials, a query string,
fragment, release path, or trailing slash.

## Register your plugin

Require `bootstrap.php` from the plugin's main file, create a target, and
register it. The updater reads the installed file and derives its WordPress
identity, package root, main filename, headers, Update URI, and runtime facts.
Your plugin does not construct internal binding or archive-policy objects.

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

`maximumArtifactBytes` is an optional trailing integer argument, in bytes. It
defaults to `52,428,800`; set it per target only when that package genuinely
needs a different compressed ZIP limit.

The repository ID is GitHub's numeric repository `id`, represented as a
string. It is not the `owner/repository` name. Read it with:

```sh
gh api repos/acme/example-plugin --jq '.id | tostring'
```

Keep the target handle for the rest of the request if you need its public
inspection methods:

```php
$releaseUpdater->status();      // Registration and native lifecycle state.
$releaseUpdater->diagnostics(); // Bounded, secret-free result codes.
$releaseUpdater->refresh();     // Clears request-local native state when active.
```

`register()` is idempotent. It returns whether the declaration was accepted;
`status()` and `diagnostics()` explain inactive, deferred, conflict, or active
results. Registration itself does not contact GitHub, resolve a credential,
read an archive, or enter installation. Provider work begins during a normal
WordPress update check.

When no credentials callback is configured, a successful discovery result can
be reused within the same request while the installed version and target
ownership remain unchanged. `refresh()` clears that result so the next check
discovers releases again. A credentials callback remains fresh even when it
returns `null`. Installation always rechecks the release and acquires a fresh
archive.

### Registration timing and multiple copies

Require `bootstrap.php` from the plugin's main file, before `plugins_loaded`.
Create and register the target immediately; do not defer it to
`plugins_loaded`. Active-theme code gets the same opportunity from
`functions.php`. At `after_setup_theme` priority `PHP_INT_MAX`, the package
chooses the highest compatible physical copy, loads it, and activates the
queued declarations. Later declarations route to the selected copy without
reopening selection.

If no copy is compatible, or equal package versions contain different runtime
bytes, the updater remains inactive and reports the conflict through
`diagnostics()`.

### Private repositories

Pass a callable as the `credentials` argument (the penultimate positional
argument when also setting `maximumArtifactBytes`). Registration does not
invoke it; each top-level GitHub service operation resolves it when it begins:

```php
$releaseUpdater = $registrar->plugin(
 provider: 'github',
 pluginFile: __FILE__,
 repository: 'acme/example-plugin',
 repositoryId: '123456789',
 channel: 'stable',
 updatePolicy: 'manual',
 credentials: static fn (): ?string => getenv( 'EXAMPLE_PLUGIN_GITHUB_TOKEN' ) ?: null
);

$releaseUpdater->register();
```

Keep the token outside the plugin source and release ZIP. It remains
request-local and is not stored in target state, diagnostics, logs, URLs, or
committed artifacts. The GitHub client sends credentials to `api.github.com`,
disables automatic redirects, validates release-asset redirects, and removes
authorization when a request leaves the API host.

## Configuration reference

`plugin()` and `theme()` use the same closed argument list:

| Setting | Accepted value |
| --- | --- |
| Provider | Built-in provider code; currently only `github` |
| Installed file | Absolute plugin main-file path or theme `style.css` path |
| Repository | GitHub `owner/repository` |
| Repository ID | Positive numeric GitHub repository ID as a string |
| Channel | `stable` or `prerelease`; defaults to `stable` |
| Update policy | `manual`, `automatic`, `forced-off`, or `disabled`; defaults to `manual` |
| Credentials | Optional request-local callable returning a token string or `null` |
| Maximum artifact bytes | Positive compressed-ZIP byte limit; defaults to `52,428,800` per target |

The policy values have these effects:

- `manual` offers a verified update but does not admit an automatic update.
- `automatic` admits automatic installation only when GitHub marks the Release
  immutable and the candidate also passes the manual verification profile.
- `forced-off` and `disabled` suppress the native update offer.

The updater derives and then rechecks the canonical Update URI, installed
identity, archive root, header file, package name, and runtime versions at their
trust boundaries. If your distribution installs as
`example-plugin/example-plugin.php`, the release archive must retain that root
and main filename.

Unknown provider codes leave the target inactive with an `unsupported_provider`
diagnostic. Providers are shipped as part of the selected runtime; consumers
cannot register adapters at runtime.

### Direct filesystem boundary

Discovery and installation are supported only when WordPress selects the
`direct` filesystem method. Other methods leave the target passive before
credential resolution, HTTP, release acquisition, archive reads, or any update
or installation work. The registered WordPress hooks remain inert.

## Provider architecture

The public provider-neutral seam is the `plugin()` and `theme()` registration
API. Behind it, each built-in provider owns its validation, credentials,
discovery, and acquisition code. A shared lower-level seam accepts the resulting
provider adapter and runs the WordPress lifecycle.

That lower-level seam is provider-neutral but package-internal; it is not a
supported consumer API. GitHub is its only built-in provider today. See
[Provider architecture](docs/provider-architecture.md) for the maintainer-facing
composition boundary and extension rules.

## Publish a compatible GitHub Release

Each release consumed by the updater must satisfy all of the following.

### Tag and release

- Publish a non-draft GitHub Release.
- Use a full SemVer tag such as `v1.2.3` or `v1.2.3-beta.1`.
- For the `stable` channel, do not mark the Release as a prerelease and do not
  use a prerelease version suffix.
- Use the `prerelease` channel if the target should consider prerelease tags.

The package version in the plugin header must match the normalized tag version.
The leading `v` belongs only to the tag.

### Uploaded ZIP

Upload exactly one `.zip` asset to the GitHub Release, for example:

```text
example-plugin-1.2.3.zip
```

The ZIP must be fully uploaded and have the `sha256:` digest supplied by
GitHub's release-asset metadata. Each target defaults to a 50 MiB
(52,428,800-byte) maximum. Pass a positive `maximumArtifactBytes` as the
trailing argument to `plugin()` or `theme()` to lower or raise that target's
limit. It bounds accepted GitHub asset metadata and the retained compressed ZIP
size in the temporary file, not underlying network transfer; the request timeout
still applies. The independent expanded archive byte, entry-count,
compression-ratio, digest, and custody checks still apply. GitHub's generated
**Source code** archives do not count as uploaded assets.

### Archive layout and headers

The ZIP must contain one top-level directory and no files beside it:

```text
example-plugin/
├── example-plugin.php
├── vendor/
└── ...
```

The top-level directory and main filename must match `archive_root` and
`header_file`. The main file inside the ZIP must contain:

```text
Plugin Name: Example Plugin
Version: 1.2.3
Update URI: https://github.com/acme/example-plugin
Requires PHP: 8.2
Requires at least: 6.5
```

The plugin name must match the registered `metadata_name`. `Version` must match
the release tag. The two runtime requirements must be valid and satisfied by
the site receiving the update.

Archives fail validation if they contain unsafe, duplicate, ambiguous, special,
or multi-root paths; more than 10,000 entries; more than 127,826,407 expanded
bytes; or an entry whose expansion exceeds the permitted compression ratio.

## Themes

An active theme can register itself from `functions.php` before the shared
activation boundary:

```php
<?php

$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';

$releaseUpdater = $registrar->theme(
 provider: 'github',
 stylesheetFile: __DIR__ . '/style.css',
 repository: 'acme/example-theme',
 repositoryId: '987654321'
);

$releaseUpdater->register();
```

An inactive theme cannot execute its own PHP. Register it from an active plugin
or another manager that runs before `after_setup_theme`:

```php
<?php

$stylesheet = 'managed-theme';
$styleFile = get_theme_root( $stylesheet ) . '/' . $stylesheet . '/style.css';

$managedThemeUpdater = $registrar->theme(
 provider: 'github',
 stylesheetFile: $styleFile,
 repository: 'acme/managed-theme',
 repositoryId: '987654321',
 channel: 'stable',
 updatePolicy: 'manual'
);

$managedThemeUpdater->register();
```

The release ZIP must contain `<stylesheet>/style.css` with matching `Theme
Name`, `Version`, `Update URI`, `Requires PHP`, and `Requires at least` headers.
For a child theme, its `Template` header must also exactly match the installed
child theme. A standalone theme must remain standalone: adding or removing a
`Template` header in a release is rejected.

## WordPress Multisite

The standalone package has a bounded network-safety proof: the same target
observed from the main site and a subsite resolves to one network-scoped target
and one operation fence. The target key does not include `blog_id` or the
current site.

The proof does not cover a host's network activation, target inventory,
administration, or product-specific behavior.

## Security boundary

The updater verifies provider and package identity before it gives an archive
to WordPress, and verifies the installed manifest after Core completes the
operation. It does not sandbox other PHP code already running as the WordPress
filesystem user. Code with the same operating-system access can still change
plugin files outside the updater lifecycle.

Read [SECURITY.md](SECURITY.md) before reporting a vulnerability. Do not put
tokens, signed URLs, raw HTTP responses, release ZIPs, temporary paths, or site
data in a public issue.

## Internationalization

This library is embedded by a host plugin or theme. It does not load a text
domain or own a translation catalog.

Diagnostic codes and exception text are developer-facing. If your plugin shows
an updater failure to a user, map the `ran_wp_release_updater_*` code from its
`WP_Error` to a message localized with your plugin's text domain. Do not parse
the fallback English message.

## Development

Install development dependencies and run the package checks with:

```sh
composer install
composer check
```

Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change. Ordinary
defects belong in the [issue tracker](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/issues); security reports follow [SECURITY.md](SECURITY.md).

## License and provenance

The project is licensed under `GPL-2.0-or-later`. See [LICENSE](LICENSE) for the
license and [NOTICE.md](NOTICE.md) for the frozen source boundary and
attribution.
