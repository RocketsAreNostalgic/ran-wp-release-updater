# Integrating the release updater

This guide is for plugin and theme authors embedding `ran/wp-release-updater` in a distributed WordPress package. It covers the native lifecycle, registration timing, release packaging, runtime behaviour, and the failure surfaces that matter to a host application.

For the shortest working example, start with the repository [README](../README.md).

## Native lifecycle

The package joins WordPress Core's existing update lifecycle. It does not run a second installer and `register()` does not perform an update.

A normal target moves through these stages over one or more WordPress callbacks:

```text
register target
→ WordPress update check
→ discover and validate an eligible release
→ publish a native update offer
→ reacquire and revalidate the release before installation
→ admit the verified archive to WordPress Core
→ WordPress extracts/installs/rolls back as needed
→ verify the installed package before accepting completion
```

Registration is deliberately passive: it does not resolve credentials, contact the provider, read an archive, or enter installation. Provider work starts when WordPress asks for update information.

WordPress Core remains responsible for update scheduling, the Plugins and Updates screens, filesystem access, temporary backups, extraction, installation, rollback, activation state, and Core cleanup. The library validates and fences the operation around those Core-owned steps.

## Registration timing

Require `bootstrap.php` from the plugin's main file before `plugins_loaded`, create the target, and call `register()` immediately.

```php
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

An active theme can do the same from `functions.php`:

```php
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';

$releaseUpdater = $registrar->theme(
    provider: 'github',
    stylesheetFile: __DIR__ . '/style.css',
    repository: 'acme/example-theme',
    repositoryId: '987654321'
);

$releaseUpdater->register();
```

An inactive theme cannot execute its own PHP. Register it from an active plugin or another manager that runs early enough to participate in runtime selection.

Do not defer registration to `plugins_loaded`. The package waits until `after_setup_theme` at priority `PHP_INT_MAX` to select and activate the compatible physical runtime from all copies loaded during normal early plugin/theme bootstrap.

## Multiple bundled copies and versions

Different plugins and themes may intentionally bundle different versions of this library in the same WordPress request. That version skew is supported; consumers do not need a shared Composer lock file or a synchronized package version.

Each bundled copy directly loads its own `bootstrap.php`. Before runtime selection, the bootstrap registers that physical copy as a candidate with the request-local broker without loading the candidate's runtime implementation. At `after_setup_theme` priority `PHP_INT_MAX`, the broker filters the collected candidates against the current PHP and WordPress environment and selects the highest compatible package version. Only the selected copy's runtime entrypoint is loaded. Declarations already queued through any bundled copy, and later declarations in the same request, are routed through that one selected runtime.

A newer candidate whose PHP or WordPress floor is not satisfied does not make the request unusable when an older compatible candidate is available; the highest compatible candidate wins. The fail-closed cases are narrower:

- two physical copies claiming the same package version but carrying different runtime revisions;
- a bootstrap/runtime protocol conflict that cannot safely share the request broker;
- a candidate arriving after selection and attempting to replace the already selected authority.

This election mechanism is deliberate: independently distributed plugins/themes can update this dependency on their own schedules while the WordPress request still has one runtime authority and does not install competing hook owners.

## Configuration

`plugin()` and `theme()` share the same policy surface:

| Setting | Meaning |
| --- | --- |
| `provider` | Built-in provider code; currently `github` |
| installed file | Plugin main file or theme `style.css` |
| `repository` | GitHub `owner/repository` |
| `repositoryId` | Positive numeric GitHub repository ID represented as a string |
| `channel` | `stable` or `prerelease`; defaults to `stable` |
| `updatePolicy` | `manual`, `automatic`, `forced-off`, or `disabled`; defaults to `manual` |
| `credentials` | Optional request-local callable returning a token string or `null` |
| `maximumArtifactBytes` | Positive compressed-ZIP byte ceiling; defaults to 52,428,800 bytes |

The repository ID is GitHub's numeric repository `id`, not the `owner/repository` string. For example:

```sh
gh api repos/acme/example-plugin --jq '.id | tostring'
```

Policy behaviour:

- `manual` publishes a verified native offer but does not admit automatic installation.
- `automatic` admits automatic installation only when the provider evidence proves the release immutable/provenanced and the normal verification profile also passes.
- `forced-off` and `disabled` suppress the native update offer.

Unknown providers are not dynamically loaded. The target remains inactive and reports `unsupported_provider`.

## Plugin and theme identity

The installed package and the release asset must describe the same WordPress package.

For a plugin, the main file should contain at least:

```text
Plugin Name: Example Plugin
Version: 1.2.3
Update URI: https://github.com/acme/example-plugin
```

`Requires PHP` and `Requires at least` may also be supplied and are enforced when present.

For a theme, `style.css` must carry matching `Theme Name`, `Version`, and `Update URI` values. A child theme's `Template` relationship must remain unchanged across the update; a standalone theme cannot become a child theme, or vice versa, as part of a release.

Use the canonical repository URL as `Update URI`:

```text
https://github.com/owner/repository
```

Do not include credentials, query strings, fragments, release paths, or a trailing slash. The updater rechecks the URI and package identity at later trust boundaries instead of assuming the installed metadata stayed unchanged.

## Publishing a compatible GitHub release

A usable release must satisfy all of the following:

- it is a non-draft GitHub Release;
- its tag uses the supported three-part SemVer subset: `vMAJOR.MINOR.PATCH`, optionally with a prerelease suffix such as `v1.2.3-beta.1`; build metadata (`+...`) is not supported;
- stable-channel releases are not marked prerelease and do not use a prerelease SemVer suffix;
- it contains exactly one fully uploaded `.zip` release asset;
- GitHub exposes a `sha256:` digest for that uploaded asset;
- the ZIP contains exactly one top-level package directory;
- the package directory and main plugin/theme file match the installed package identity;
- package headers inside the ZIP match the selected release version and canonical Update URI.

GitHub's generated **Source code** archives are not uploaded release assets and are not accepted in place of the ZIP.

A typical plugin ZIP is:

```text
example-plugin/
├── example-plugin.php
├── vendor/
└── ...
```

If the updater is bundled through Composer, the distributed ZIP must contain the dependency under `vendor`; production sites should not need Composer to perform an update.

The default compressed-artifact limit is 50 MiB (52,428,800 bytes) per target. Raising it increases accepted compressed-data and disk exposure; it does not relax the separate archive path, entry-count, expanded-size, compression-ratio, digest, identity, or custody checks.

## Credentials and private repositories

A plugin or theme may pass a request-local credentials callback:

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

The callback is not invoked during registration. Each top-level provider operation resolves it when needed.

Keep tokens outside source control and outside the release ZIP. The updater does not persist them in target state, diagnostics, URLs, or provider artifacts. `null` selects anonymous GitHub access. Invalid callback material reports `credential_unavailable`; a well-formed token denied by GitHub reports `repository_access_unavailable`. Neither case is silently retried anonymously.

## Direct filesystem requirement

Native discovery and installation are supported only when WordPress selects the `direct` filesystem method. Other filesystem methods leave the registered target passive before credential resolution, HTTP, release acquisition, archive reads, or mutation work.

This is a product limitation, not merely a test limitation. Hosts that require FTP/SSH filesystem transports are currently outside the supported update path.

## Status, diagnostics, and native failures

Keep the target handle for request-local inspection:

```php
$releaseUpdater->status();
$releaseUpdater->diagnostics();
$releaseUpdater->refresh();
```

`status()` returns an outer target envelope with `state`, `declaration_accepted`, `hooks_registered`, `code`, and `native`. For an active target, the installed/candidate/offered versions, candidate validation state, last check, release identity, relationship, and native failure code are under `status()['native']`; they are not top-level fields. `diagnostics()` returns an envelope with `state` plus a nested `diagnostics` list of machine-readable entries. `refresh()` clears request-local native state when the selected runtime is live.

The native installer returns `WP_Error` failures using the prefix:

```text
ran_wp_release_updater_<failure_code>
```

The English fallback message is intentionally generic. If your product shows a failure to users, map the code to text in your own text domain; do not parse the fallback message.

Important native failure codes include:

| Code | Meaning |
| --- | --- |
| `runtime_package_identity_invalid` | Installed package/version/Update URI no longer matches the admitted target |
| `binding_fence_lost` | Another valid operation owns or replaced the persistent target fence |
| `acquisition_failed` | The provider release/archive could not be freshly inspected or acquired |
| `remote_release_changed` | The release no longer matches the exact release previously offered |
| `acquisition_identity_invalid` | Acquired bytes failed the expected archive/provider identity checks |
| `archive_changed_before_extraction` | The controlled archive changed before Core extraction |
| `staged_package_identity_invalid` | Core's staged package does not match the admitted package/manifest |
| `runtime_liveness_lost` | The selected physical runtime is no longer authoritative for the operation |
| `unverified_pre_download` / `unverified_pre_install` | A required verification/admission precondition was not satisfied |
| `unverified_install_result` | Core did not produce an install result the updater can safely accept |
| `outcome_uncertain` | Core ran, but final postconditions were insufficient to claim a verified completion |

Discovery can also reject candidates without turning the whole update check into a WordPress error. `status()` exposes the candidate-validation state for that case. A missing/incompatible individual release can be skipped in favour of a later candidate; provider, authentication, malformed-data, or other authority failures stop discovery rather than being silently treated as an absent release.

## Multisite

The package has a bounded multisite guarantee: the same target observed from the main site and a subsite resolves to one network-scoped target and one operation fence. The target key does not include `blog_id`.

This does **not** claim ownership of network activation, target inventory, multisite administration, or product-specific network policy. Those remain host responsibilities.

## Advanced release reads

If you need verified release metadata or bytes without registering a native update target, use the request-local `releases()` API. It never installs a package. See [Release-source API](release-sources.md).

## Security boundary

The updater verifies provider/package identity before handing controlled bytes to WordPress and verifies the installed package after Core completes. It does not sandbox other PHP already running as the WordPress filesystem user. Code with equivalent operating-system access can still mutate plugin/theme files outside this lifecycle.

See [Architecture and package relationships](architecture.md) for the trust model and [SECURITY.md](../SECURITY.md) for vulnerability reporting.
