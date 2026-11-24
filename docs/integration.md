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

During acquisition, the updater reuses the source archive's validated identity
after copying it into owned custody, with a fresh byte check after the binding
fence. This avoids a second full ZIP scan without replacing the later staged
and installed package header/identity checks. Confirmed completion also avoids
redundant lease release work; uncertain outcomes retain cleanup retries.

WordPress Core remains responsible for update scheduling, the Plugins and Updates screens, filesystem access, temporary backups, extraction, installation, rollback, activation state, and Core cleanup. The library validates and fences the operation around those Core-owned steps.

## Registration timing

Require `bootstrap.php` from the plugin's main file before `plugins_loaded`, create the target, and call `register()` immediately.

```php
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';

$release_updater = $registrar->plugin(
    provider: 'github',
    plugin_file: __FILE__,
    repository: 'acme/example-plugin',
    repository_id: '123456789',
    channel: 'stable',
    update_policy: 'manual'
);

$release_updater->register();
```

An active theme can do the same from `functions.php`:

```php
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';

$release_updater = $registrar->theme(
    provider: 'github',
    stylesheet_file: __DIR__ . '/style.css',
    repository: 'acme/example-theme',
    repository_id: '987654321'
);

$release_updater->register();
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
| `repository_id` | Positive numeric GitHub repository ID represented as a string |
| `channel` | `stable` or `prerelease`; defaults to `stable` |
| `update_policy` | `manual`, `automatic`, `forced-off`, or `disabled`; defaults to `manual` |
| `credentials` | Optional request-local callable returning a token string or `null` |
| `maximum_artifact_bytes` | Positive compressed-ZIP byte ceiling; defaults to 52,428,800 bytes |

The repository ID is GitHub's numeric repository `id`, not the `owner/repository` string. For example:

```sh
gh api repos/acme/example-plugin --jq '.id | tostring'
```

Policy behaviour:

- `manual` publishes a verified native offer but does not admit automatic installation.
- `automatic` admits automatic installation only when the provider evidence proves the release immutable/provenanced and the normal verification profile also passes.
- `forced-off` and `disabled` suppress the native update offer. They apply to the update check, plugin information, the pre-download step and the cached update entry. The pre-download step refuses any download for the package. None of those paths does discovery, claims a lease, calls the provider or builds a download token, and a cached entry for the package is dropped.

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
- its tag uses the supported three-part SemVer subset in either `MAJOR.MINOR.PATCH` or `vMAJOR.MINOR.PATCH` form (a stable two-part `MAJOR.MINOR` or `vMAJOR.MINOR` tag is read as `MAJOR.MINOR.0`; when two tags give the same version the complete one is listed first), optionally with the same prerelease suffix subset such as `1.2.3-beta.1` or `v1.2.3-beta.1`; build metadata (`+...`) is not supported;
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
$release_updater = $registrar->plugin(
    provider: 'github',
    plugin_file: __FILE__,
    repository: 'acme/example-plugin',
    repository_id: '123456789',
    credentials: static fn (): ?string => getenv('EXAMPLE_PLUGIN_GITHUB_TOKEN') ?: null
);

$release_updater->register();
```

The callback is not invoked during registration. Each top-level provider operation resolves it when needed.

Keep tokens outside source control and outside the release ZIP. The updater does not persist them in target state, diagnostics, URLs, or provider artifacts. `null` selects anonymous GitHub access. Invalid callback material reports `credential_unavailable`; a well-formed token denied by GitHub reports `repository_access_unavailable`. Neither case is silently retried anonymously.

## Direct filesystem requirement

Native discovery and installation are supported only when WordPress selects the `direct` filesystem method. Other filesystem methods leave the registered target passive before credential resolution, HTTP, release acquisition, archive reads, or mutation work.

This is a product limitation, not merely a test limitation. Hosts that require FTP/SSH filesystem transports are currently outside the supported update path.

## Status, diagnostics, and native failures

Keep the target handle for request-local inspection:

```php
$release_updater->status();
$release_updater->diagnostics();
$release_updater->refresh();
```

`status()` returns an outer target envelope with `state`, `declaration_accepted`, `hooks_registered`, `code`, and `native`. For an active target, the installed/candidate/offered versions, candidate validation state, last check, release identity, relationship, and native failure code are under `status()['native']`; they are not top-level fields.

`diagnostics()` returns an envelope with `state` plus a nested `diagnostics` list of machine-readable entries.

`refresh()` clears request-local native state when the selected runtime is live. It also deletes the WordPress `update_plugins` or `update_themes` site transient for the target's type, which removes WordPress's twelve-hour freshness shortcut. The next WordPress update check then calls this target's update filter again.

- `refresh()` runs no check itself. Call `wp_update_plugins()` or `wp_update_themes()` afterwards when you need the result in the same request.
- A refresh is not proof that the provider was contacted. The native filter still returns early for a `disabled` or `forced-off` policy, an unavailable filesystem, or when discovery is not admitted; use the read-only check below to learn the newest release without offering it.
- `status()['native']['last_check']` is stamped as soon as the filter runs, before those early exits, so it does not show what a check did. Read `status()['native']` (`failure_code`, `candidate_validation_code`, the `offered_*` fields) and `diagnostics()` instead.

### Checking without offering

`refresh()` followed by a WordPress update check is an offer path: a `disabled`
or `forced-off` target returns before discovery, so it cannot answer "is there a
newer release?". A host that wants that answer, for example behind a manual
Check releases control, calls the optional read-only check filter. The runtime
registers it for every active target and no handle method or protocol change is
involved, so an older elected runtime simply does not answer.

```text
$report = apply_filters( 'ran_wp_release_updater_check_releases_v1', null, 'plugin', 'package/package.php' );
```

Arguments are the unanswered default (`null`), the target type (`plugin` or
`theme`) and the installed identity. The host must authorize the caller itself,
as for any call that reaches the provider. Only the matching live target
answers. The filter returns `null` unchanged when nothing answered (no such
target, runtime not live, or an older runtime), and the host must treat that as
"unsupported", not as an up-to-date result.

An answering target returns exactly these keys:

| Key | Value |
| --- | --- |
| `status` | `succeeded`, `rejected`, `skipped` or `failed`, as for native observations |
| `reason` | Observation reason, below |
| `policy` | The target's `update_policy` |
| `relationship`, `installed_version`, `candidate_version`, `candidate_validation_code` | Nullable, validated and bounded exactly as in the completion observation |
| `offered` | Always `false` |

Reasons are those of the completion observation plus `newer_release_observed`
(`succeeded`: a newer, manually eligible release exists), and `runtime_busy`
(`failed`: a native install is pending or beginning, so nothing was checked).
A `no_newer_candidate`, `no_candidates`, `candidate_rejected` or
`candidate_not_eligible` result reports an up-to-date, rejected or ineligible
state in the same way a native check does.

Guarantees:

- The check never writes or deletes an `update_plugins` or `update_themes`
  transient entry and never builds an offer or download token. A reported
  release cannot become installable through this call.
- It leaves the last native check's state as that check left it: the
  `status()['native']` facts (including `offered_version` and
  `offered_release_identity`), the retained descriptor and the reusable
  discovery snapshot are restored when the call ends, including when an
  observer throws. The check itself keeps no descriptor or snapshot, and a
  native check that follows can still reuse its own earlier snapshot while the
  discovery lease it was taken under is still held (a replaced lease invalidates
  the snapshot, as it does for native checks). A runtime that is displaced during
  the check keeps no discovery state.
- It applies to `disabled`, `manual` and `automatic`. Native and background
  checks, and offers for `manual` and `automatic`, are unchanged: `disabled`
  still returns before discovery there with reason `policy_disabled`.
- `forced-off` is site configuration and stays fully blocked. No filesystem,
  lease or provider work occurs; the report and the completion observation have
  `status` `skipped` and reason `policy_forced_off`, distinct from the
  `policy_disabled` reason a native `disabled` check records.
- Every check discovers afresh and is observed. It never answers from a native
  check's reusable snapshot, and it emits `check_started_v1` and
  `check_completed_v1` once past the identity and `forced-off` gates (not for
  `runtime_busy` or when nothing answered). Consumers that treat a `succeeded`
  completion as "an offer exists" must read the reason: `newer_release_observed`
  is not an offer.
- Once a check proceeds past the identity and `forced-off` gates it marks the
  target type as operation-started, as a native check does, so run it after
  targets are declared. For every policy except `forced-off` it also claims the
  discovery lease (the 600-second binding claim) and holds it for the rest of
  the request, as a native check does.
- Every call does provider discovery, archive inspection and validation and
  keeps no reusable result. Do not wire it to unauthenticated or high-frequency
  paths.
- Remote text is never returned; only the finite codes and validated versions
  above.

### Offering one exact release

Native offers are newest-and-newer only. A host that must install one chosen
release through WordPress's own upgrader, for example the published release of
the same version as a branch-built copy, a newer release that is not the
newest, or an older release the operator has deliberately chosen, authorises it
for the current request with the optional offer filter.
As with the check filter, no handle method or protocol change is involved, and
an older elected runtime does not answer.

```text
$report = apply_filters( 'ran_wp_release_updater_offer_release_v1', null, 'plugin', 'package/package.php', $release_identity, $tag, $older_intended );
```

Arguments are the unanswered default (`null`), the target type, the installed
identity, the release identity and tag of the chosen release, and the optional
`$older_intended` (default `false`). The host must authorise the caller itself.

The same version as installed, or a newer one, is authorised whether or not
`$older_intended` is passed. An older release is authorised only when
`$older_intended` is exactly `true`; anything else is refused with
`release_older`. Pass `true` only after the operator has explicitly confirmed
installing a release that is not newer, and tell them first:

- database changes made by the newer version are not undone, and the older code
  runs against them;
- WordPress's next update check offers the newest release again as an
  ordinary update;
- many old releases will still be refused, for example for lacking the manual
  assurance facts or the expected headers.

Policy is applied as for any install: `disabled` and `forced-off` refuse, and
the updater adds no further rule for older releases. A host that must restrict
older installs to particular policies enforces that itself.

Only the matching live target answers; `null` back means unsupported. An
answering target returns exactly `status` (`authorized` or `refused`), `code`,
`version` and `relationship` (the last two are `null` when the call stops before
the release is inspected).

The target inspects the release afresh, binds it, requires the manual assurance
facts and validates it as discovery validates a candidate: host descriptor
admission, an exact release identity and tag match, one acquisition,
archive validation and discard, then admission again. Codes:

| Code | Meaning |
| --- | --- |
| `release_authorized` | Authorised for this request |
| `policy_disabled`, `policy_forced_off` | The target's policy admits no install |
| `runtime_busy` | A native install is pending or beginning |
| `filesystem_unavailable`, `discovery_not_admitted` | As for native checks |
| `release_unavailable` | Inspection or binding failed |
| `release_older` | The release is older than installed and `$older_intended` is not `true` |
| `release_invalid` | The installed or release version cannot be compared |
| `candidate_not_eligible` | The release lacks the manual assurance facts |
| `candidate_*` and archive validation codes | As reported for a discovery candidate |
| `offer_token_unavailable`, `runtime_liveness_lost` | No offer could be built, or the runtime was displaced |

Guarantees:

- The authorisation lives only in this request and is never stored. A new call
  replaces it, whatever its result. Every download attempt for the target
  consumes it, even one refused before any download, so a second attempt needs
  a new call, and `refresh()` clears it.
- While it is held, reads of the `update_plugins` or `update_themes` site
  transient carry the authorised offer for this target, so
  `Plugin_Upgrader::upgrade()`, `bulk_upgrade()` and `Theme_Upgrader::upgrade()`
  find it. The supplied offer is marked not automatic, and automatic update
  admission stays newer-only, so a same-version or older release is never
  installed automatically. The report's `relationship` is `same`, `newer` or
  `older`, so the host can tell which confirmation applied.
- When WordPress saves that transient, the target removes its entry if it
  carries any release authorised in this request, including one whose
  authorisation a later call replaced, until `refresh()`. The offer never
  reaches the stored row, including after a failed install and on the bulk
  route. While the authorisation is held, a read replaces this target's entry,
  so saving that read drops whatever entry the stored row had for the target,
  such as an ordinary offer for a newer release; the next update check restores
  it. Code that writes the option directly, bypassing `set_site_transient()`, is
  not covered.
- Download admission accepts a release that is not newer only when it is the
  authorised release.
  Fresh inspection, the `remote_release_changed` fingerprint check, reacquisition
  and verification are unchanged. Native checks, discovery and automatic
  updates stay newer-only.
- Authorising claims the discovery lease, as a check does, and leaves
  `status()['native']` as the last native check left it.

### Installing one exact release synchronously

For a flow that must know whether the installation finished before it returns,
call the optional exact-install filter on the registered target. Load the
standard WordPress admin filesystem and upgrader APIs first, as for a native
upgrade. The host authorizes the operator and applies its own mutation policy
before dispatching the filter. The updater runs the native upgrade itself.

```php
$report = apply_filters(
    'ran_wp_release_updater_install_exact_release_v1',
    null,
    'plugin',
    'package/package.php',
    $release_identity,
    $tag,
    $listed_version,
    $older_intended
);
```

For a theme, pass `theme` and its stylesheet slug. Pass the identity, tag and
version from the same selected listing entry. Only an explicit `true` permits
an older release, with the same operator confirmation as the offer filter.
An older elected runtime or an inactive target leaves the `null` default
unanswered. Treat that as unavailable before attempting a native upgrade.

The updater checks the live installed identity, descriptor, policy and manual
assurance, acquires the ZIP once, and validates it into private custody.
WordPress receives the verified copy only for the matching invocation. The
download hook re-inspects the descriptor, checks its fingerprint and the
binding fence, and rechecks the copy before beginning pending custody. The offer is
request-local. The report contains no archive path.

The plugin route uses native one-item `Plugin_Upgrader::bulk_upgrade()`. The theme
route uses native `Theme_Upgrader::upgrade()`. WordPress Core natively owns
temporary backup, rollback (`restore_temp_backup` at shutdown priority 10),
backup deletion (`delete_temp_backup` at shutdown priority 100, which also runs
after a failed restore), and `.maintenance` management. After a clean return
with matching completion evidence and no pending restoration at shutdown
priority 10, the updater verifies the installed destination, mutation lock, and
lightweight installed package headers/identity and finalizes synchronously
(cleaning up private archive custody, invoking host completion, releasing any
standalone lock, and disarming its own shutdown fallback). Pending restoration
and post-registration unverified install results retain WordPress Core's native
shutdown callbacks alongside the updater's shutdown cleanup fallback. Because
the updater relies on native `WP_Upgrader` rather than a custom recovery layer,
an unhandled exception thrown after `move_to_temp_backup_dir()` and before
`WP_Upgrader::run()` registers its shutdown callbacks leaves the target
unrecovered by Core; on the plugin `bulk_upgrade()` path (when the plugin is
active or on multisite), WordPress's 10-minute `.maintenance` timeout applies
(`Theme_Upgrader::upgrade()` does not enable maintenance mode), while the
updater's own shutdown fallback still cleans up private custody, releases its
lock, and reports an uncertain outcome.

If an exact install is refused before WordPress moves the live package to
temporary backup (for example in `upgrader_pre_install`, `upgrader_source_selection`,
or on raw `fs_no_content_dir`, `fs_temp_backup_mkdir`, or `fs_temp_backup_move`
errors observed via `upgrader_install_package_result` prior to error rewriting),
the updater disarms Core's `restore_temp_backup` (priority 10) and
`delete_temp_backup` (priority 100) shutdown callbacks for that upgrader
instance so a pre-existing stale directory under `upgrade-temp-backup/` cannot
overwrite the healthy live installation or be deleted by the refused invocation.

The operation buffers native progress without flushing the caller's response.
WordPress's asynchronous translation callback leaves pending language packs for
a later update while the exact install is running. When the native call returns
or throws, the updater removes its temporary upgrader filters and leaves any
post-move WordPress Core shutdown backup callbacks in place.

The report carries `status`, `code`, `version`, `relationship`,
`finalization_pending` and `finalization`. Only `installed` with
`update_completed` acknowledges a successful finalized install. Other statuses
are `refused` before WordPress starts, `failed` after it starts, and `pending`
when updater finalization remains due at shutdown. When cleanup runs in the
invocation, `finalization` describes that specific invocation.

| Field | Values |
| --- | --- |
| `commit` | `completed` when the updater verified the installed package and committed completion, `failed` when that could not be proved during synchronous commit, or `not_attempted` |
| `host_completion` | `completed` when the configured mutation completion callback returned normally, `failed` when it threw, or `not_applicable` |
| `lease` | `released`, `failed` or `not_held` |
| `archive_cleanup` | `clean` or `failed` |

A completed commit can accompany a later cleanup or lease failure. Use
`archive_cleanup_failed`, `host_completion_failed`, `lease_release_failed`
and `install_commit_failed` to distinguish those outcomes from success. A host
that persists its own source transition must also verify its own commit
acknowledgement for this invocation.

The native installer returns `WP_Error` failures using the prefix:

```text
ran_wp_release_updater_<failure_code>
```

The English fallback message is intentionally generic. If your product shows a failure to users, map the code to text in your own text domain; do not parse the fallback message.

Important native failure codes include:

| Code | Meaning |
| --- | --- |
| `runtime_package_identity_invalid` | Installed package/version/Update URI no longer matches the admitted target |
| `binding_fence_lost` | The updater could not establish, retain, or verify WordPress's `auto_updater.lock` for the mutation; this includes competing ownership, missing/corrupt Core automatic locks, and database failures that make lock authority unprovable |
| `acquisition_failed` | The provider release/archive could not be freshly inspected or acquired |
| `remote_release_changed` | The release no longer matches the exact release previously offered |
| `acquisition_identity_invalid` | Acquired bytes failed the expected archive/provider identity checks |
| `archive_changed_before_extraction` | The controlled archive changed before Core extraction |
| `staged_package_identity_invalid` | Core's staged package does not match the admitted package identity or headers |
| `runtime_liveness_lost` | The selected physical runtime is no longer authoritative for the operation |
| `unverified_pre_download` / `unverified_pre_install` | A required verification/admission precondition was not satisfied |
| `unverified_install_result` | Core did not produce an install result the updater can safely accept |
| `outcome_uncertain` | Core ran, but final postconditions were insufficient to claim a verified completion |

Discovery can also reject candidates without turning the whole update check into a WordPress error. `status()` exposes the candidate-validation state for that case. A missing/incompatible individual release can be skipped in favour of a later candidate; provider, authentication, malformed-data, or other authority failures stop discovery rather than being silently treated as an absent release.

## Multisite

The package has a bounded multisite guarantee: the same target observed from the main site and a subsite resolves to one network-scoped target and one operation fence. The target key does not include `blog_id`.

This does **not** claim ownership of network activation, target inventory, multisite administration, or product-specific network policy. Those remain host responsibilities.

The selected runtime captures the network ID at activation; changing the ambient
network later in the request does not retarget its existing handles. Fences for
different networks are independent, so they do not serialize mutations of the
shared plugin or theme files across an installation. A host that permits those
operations needs its own installation-wide mutation lock and recovery policy.

Credentials and guards are request-local callbacks. The captured network ID does
not automatically bind them to the originating network or site. For example,
a host can bind a read-only release source to the current network and blog:

```php
$origin_network = get_current_network_id();
$origin_blog = get_current_blog_id();
$at_origin = static fn (): bool => get_current_network_id() === $origin_network
    && get_current_blog_id() === $origin_blog;
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';
$source = $registrar->with_operation_guard($at_origin)->releases(
    provider: 'github',
    package_type: 'plugin',
    repository: 'acme/example-plugin',
    repository_id: '123456789',
    credentials: static fn (): ?string => $at_origin()
        ? (getenv('EXAMPLE_PLUGIN_GITHUB_TOKEN') ?: null)
        : null
);
```

This example refuses an operation when its guard observes a changed context;
the credential callback also checks the origin before reading the token. The
service rechecks the guard after credential resolution and before transport. A
native target additionally requires the host mutation callbacks described below.
Those callbacks must keep the original owner for completion and recovery rather
than deriving ownership from whichever site is current during cleanup. This
example does not implement multisite administration or cross-network locking.

## Advanced release reads

If you need verified release metadata or bytes without registering a native update target, use the request-local `releases()` API. It never installs a package. See [Release-source API](release-sources.md).

## Security boundary

The updater verifies provider/package identity before handing controlled bytes to WordPress and verifies the installed package after Core completes. It does not sandbox other PHP already running as the WordPress filesystem user. Code with equivalent operating-system access can still mutate plugin/theme files outside this lifecycle.

See [Architecture and package relationships](architecture.md) for the trust model and [SECURITY.md](../SECURITY.md) for vulnerability reporting.
### Optional native-check observations

The selected runtime emits `ran_wp_release_updater_check_started_v1` and
`ran_wp_release_updater_check_completed_v1` WordPress actions around an admitted
native update check, and around the read-only check described above. Start precedes remote discovery, after any reusable request
snapshot is ruled out; local validation/policy/admission failures also emit a
pair. Reusing an already verified request snapshot emits neither action. Each
action receives one array. `refresh()` and information reads
are not checks and emit neither action. These hooks do not change runtime selection, native eligibility, or the public
handle status shape.

The start array contains exactly `check_id`, `network_id`, `blog_id`,
`target_type`, `installed_identity`, `provider_code`, `repository_identity`,
`channel`, and `binding_hash`. The check ID is independent random 32-character
lowercase hexadecimal correlation data, never a lease or download token.
Installed identity is at most 255 bytes, provider code 32, repository identity
191. Consumers must resolve and authorize their own registered target; a binding
hash is correlation data, not authority.

Completion contains exactly `check_id`, `status`, `reason`, `relationship`,
`installed_version`, `candidate_version`, `offered_version`, and
`candidate_validation_code`. Status is `succeeded`, `rejected`, `skipped`, or
`failed`. Versions are nullable, validated and at most 64 bytes; unknown or
oversized facts are omitted as null, never truncated. Validation codes are a
finite safe subset of the native discovery codes. No credentials, URLs, remote
messages, exception contents, or archive tokens are included.

`newer_release_observed` (read-only check only, see above) means a newer eligible
release exists but no offer was constructed. `update_available` means an eligible
native offer was constructed. Only a
complete valid listing can establish `no_candidates` or `no_newer_candidate`;
unknown or rejected potentially newer candidates cannot establish either.
Policy suppression and failed discovery admission are skipped checks, not proof
of being up to date or of lock contention. A successfully offered later candidate
can establish availability despite an earlier rejected candidate.

Observers must tolerate missing start or completion, unsupported older elected
runtimes, and process termination. Listeners must not throw. Handle explicit
reporting or storage failure return values normally; do not wrap WordPress calls
in a catch-and-resume boundary. Unexpected listener exceptions propagate normally
and abort native execution, leaving a started observation incomplete. The updater
does not dispatch completion during exceptional unwind and cannot safely resume
a WordPress dispatcher that did not finish its cleanup. This also applies to
exceptions from nested WordPress hooks: do not catch those and resume dispatch. These are optional
observations, not a guaranteed delivery system or a new installation authority.


## Protecting version-control working copies

Native plugin and theme updates refuse a target containing `.git`, `.svn`, `.hg`
or `.bzr`, including marker files, directories, symlinks and dangling links.
Only the target folder is checked; ancestor folders and Composer metadata do not
count. A missing target folder is unaffected. The check runs before download,
before pre-install side effects and again at the source handoff before WordPress
moves or replaces the installed folder, for interactive, bulk and background updates.

The native error code is `ran_wp_release_updater_target_is_working_copy`.
The same closed reason, `target_is_working_copy`, appears in
`$handle->status()['native']['failure_code']` and `$handle->diagnostics()` in the
current request. It survives WordPress's install-result hook.

An intentional overwrite requires `allow_working_copy: true` on that target's
`plugin()` or `theme()` registration. The option defaults to `false`; it is not
a global filter or a persisted permission. Conflicting registrations for the
same target are rejected, including registrations that disagree on this option.

This closed refusal and declaration option advance the runtime generation.
Incompatible physical copies refuse each other in either load order; deploy the
qualified family together so an older broker cannot discard this refusal reason.

## Host-bound provider replacement

A host that can replace its GitHub implementation must bind the registrar before
creating any source or native target:

Call `$registrar->with_operation_guard($operation_allowed)` and then
`->with_native_mutation_callbacks($begin, $complete)` on the returned registrar.

Both methods clone the registrar. Reuse that bound instance and the same callable
identities; different callbacks are different native declarations. Unguarded
standalone registration retains its existing behavior. An operation guard can be
used alone for a plugin/theme; optional native begin and complete callbacks must
be supplied as a pair and require that guard. A prospective `releases()` source
needs only the operation guard. The guard returns exactly `true` for current authority; false,
non-boolean results and exceptions refuse. Hosts must bind it to the request's
exact selected implementation and generation, including anonymous operations.
This does not grant credentials or override source, capability or recovery rules.

For host-specific candidate policy, call `->with_descriptor_admission($admit)`
on the registrar before declaring a plugin or theme. The callback receives an
`IdentityDescriptor` and must return exactly `true` to admit it; any other result
or exception rejects it. It is called again at subsequent update boundaries,
including cached offers, acquisition, staging, and final verification. Without a
callback, descriptor admission imposes no additional host policy.

### Optional standalone-plugin admission

A standalone plugin may bind its own narrow host policy without depending on
Booster or another coordinating plugin. Bind the callback on the public
registrar before declaring the target, and return literal `true` only for a
candidate the plugin currently accepts:

```php
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';

$release_updater = $registrar
	->with_descriptor_admission(
		static function ( \RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor $descriptor ): bool {
			$facts = $descriptor->to_array();
			return true === version_compare( $facts['version'], '2.0.0', '<' );
		}
	)
	->plugin(
		provider: 'github',
		plugin_file: __FILE__,
		repository: 'acme/example-plugin',
		repository_id: '123456789',
		update_policy: 'manual'
	);

$release_updater->register();
```

The callback is deliberately repeated at discovery, cached-offer reuse,
acquisition, staging, and final verification. Treat every non-`true` result
and exception as a refusal; do not cache an earlier acceptance as authority for
a later native update. This is an optional host policy, not a Booster
dependency, and it cannot make an inactive plugin update or satisfy an
unreleased updater/runtime requirement.

The current runtime carries this callback in every target declaration, including
an explicit null when no callback is configured. Copies of this generation cannot
share a request broker with older protocol copies.

The current runtime generation carries these callbacks through the closed declaration and selected
runtime. Copies using incompatible runtime generations refuse each other in either load
order; there is no downgrade that drops a guard. Deploy the qualified family of
physical copies together.

`begin(string $target_type, string $installed_identity): string` runs after final
staged package validation in source selection, immediately before WordPress
may change the destination. It must atomically acquire the host's exact native
lock, revalidate selection/admission and durably record uncertainty, returning an
opaque owner tag of 1–191 non-whitespace, non-control bytes. An invalid result or
exception refuses mutation. If the callback wrote its durable marker and then
threw, the host retains that marker for protected recovery; the updater cannot
invent an owner or infer rollback.

`complete(string $owner_tag, string $target_type, string $installed_identity,
string $outcome): void` runs at most once after private finalization and archive
cleanup. `verified_success` requires WordPress Core's install result, active
mutation lock verification, lightweight installed package header/identity and
destination verification, and removed private archive/directory. Every
unverified result is `uncertain`. Completion uses the
captured owner even when provider availability disappears; refresh cannot discard
that pending lifecycle. A completion exception is reported as uncertain without a
success diagnostic or retry. The host clears its marker only on exact verified
completion or separately protected recovery. Process termination may prevent the
callback entirely, so elapsed time and shutdown events cannot clear the marker.
No additional persistent recovery journal is owned by this library.

### Retained native update outcome

The existing target status includes nullable `native.last_native_outcome`, kept
request-local on the active target without writing per-target `wp_options` rows.
An inactive handle exposes no cached outcome because its runtime authority cannot
be verified.
It contains `outcome`, `source`, `wordpress_code`, `release_identity`, `version`,
`last_observed_step`, and `recorded_at` (Unix time in seconds).
`outcome` is `verified_success`, `uncertain`, or an existing closed native failure
reason. `source` is `wordpress` for an attributable native error, otherwise
`release_updater` for this library's refusal or verification result.

`wordpress_code` is a foreign reference, stored unchanged without a prefix or
message catalogue. It must pass both `FailureText::identifier()` and
`FailureText::strict(original, 64)` unchanged; otherwise it is null. Messages,
error data, paths and URLs are never stored. A null code does not erase an
observed source or imply success. The source field supplies the WordPress label.

The nullable **last observed** step is `archive_admitted`, `extraction_admitted`,
`staging_verified`, `install_result_captured`, or `completion_observed`. It is
context about evidence seen by the library, never proof that a stage succeeded.
An error before archive admission can have a known refusal and a null step.

WordPress codes are retained where safely attributable through native result
hooks or automatic results. Unobserved unpack failures remain uncertain with no
invented cause. Filesystem connection can fail before this library enters the
attempt; no new outcome is guaranteed then. Uncertainty never proves rollback or
old bytes.

No per-target lease or outcome option rows are written to `wp_options`. Hosts
that require cross-request outcome history observe the request-local callbacks
and status directly.

Prospective inspection already exposes path-free `artifact_identity`,
`artifact_sha256`, `artifact_size` and `commit_identity`. They describe the freshly
verified candidate; inspection is not installation authority and exact install
must freshly reacquire and revalidate it.


### Skipped-release reports

A successful public release listing has a sixth field, `diagnostics`, alongside
`candidates`, `conditional`, `not_modified`, `rate_limit`, and `search_exhausted`.
It is null when no fresh observation is available (including 304), otherwise:

```text
{ scanned_count: 0..40, dropped_count: 0..40,
  drops: [{ release_identity: string|null, tag: string|null, reason: string }],
  truncated: boolean }
```

Counts cover records examined and existing listing-rule rejections in the current
scan. At most eight drops are sampled, in scan order; `truncated` means further
drops were counted. Duplicates and valid candidates beyond the return cap are not
drops. `search_exhausted` still describes the bounded release search, independently
of sample truncation. No extra requests, eligibility changes or stored history are
introduced. An unexpected oversized provider response yields no diagnostics if its
observed count cannot fit this bound; its existing candidate handling is unchanged.

Reasons are `release_metadata_invalid`, `release_immutable_invalid`,
`release_publication_invalid`, `release_page_invalid`, `release_draft`,
`tag_not_version`, and `prerelease_not_allowed`. Boolean false for immutable remains
accepted. A missing ZIP or digest remains an inspection failure, not a listing drop.
Only validated positive numeric GitHub release identities are included. A tag is
unchanged original context only when the whole value passes `FailureText::strict`
and fits 120 bytes, with shape `[A-Za-z0-9][A-Za-z0-9._+-]*`; otherwise null.
The foreign-code `identifier()` rule is unsuitable for numeric-leading tags,
upper case, `+`, and the 120-byte tag bound. The strict credential screen is retained;
nothing is truncated, rewritten or redacted. Consumers must escape display context.

`ran_wp_release_updater_check_completed_v2` receives the unchanged v1 completion
fields plus `search_exhausted` (bool|null), `listing_diagnostics` (the report above
or null), and `candidate_rejections` (at most eight samples of the same three-key
shape). It follows v1 and uses the same check ID; there is no second start event.
Known inspection/acquisition rejection reasons survive an older successful offer.
Unknown causes do not acquire invented reasons; messages, details, URLs and digests
are withheld. A new actual check clears previous request-local reports. V1's keys,
reasons and values remain unchanged. This slice adds no transport, offer or admission
diagnostics. Incompatible runtime generations fail closed in either load order;
consumer adoption and publication remain separate owner decisions.


### Package folder names

Installed plugin/theme folders, archive roots, theme-parent folder names and
prospective package roots use the shared `PackageDirectory::is_valid_name()` rule:
1–191 ASCII bytes, beginning with a letter or digit and then letters, digits,
periods, underscores or hyphens. Names retain their original case and compare
exactly; the rule does not trim, lowercase or infer a destination.

Every name previously admitted by the 100-byte folder rule remains admitted.
File-name limits are unchanged, as is the 255-byte installed plugin-identity bound
including the folder, slash and file name. A maximal folder and maximal file name
therefore cannot always be combined. Incompatible runtime generations fail closed;
an older runtime cannot admit the newly supported long folder facts.
