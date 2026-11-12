# Release-source API

The `releases()` API is for applications that need verified release metadata or controlled release bytes without registering a plugin/theme with WordPress's native updater.

It is an advanced public API. A release source is request-local, performs no installation, and gives the caller explicit ownership of scheduling and any application-level cache.

## Create a source

Create the source during normal early plugin/theme bootstrap so it can bind to the selected runtime, then call its operations later in the request:

```php
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';

$source = $registrar->releases(
    provider: 'github',
    package_type: 'plugin',
    repository: 'acme/example-plugin',
    repository_id: '123456789'
);

add_action('init', static function () use ($source): void {
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!WP_Filesystem() || !(($GLOBALS['wp_filesystem'] ?? null) instanceof \WP_Filesystem_Direct)) {
        return;
    }

    $listing = $source->list();
    if (!$listing['ok']) {
        return;
    }

    // Choose from $listing['value'].
});
```

The source adds no native update hooks, does not schedule retries, and never installs the acquired archive.

The optional `releases()` arguments are the same release-read policies used by native targets, without an update policy (plus `rejection_reasons`, described below): `channel` accepts `stable` or `prerelease` and defaults to `stable`; `credentials` is an optional request-local callable returning a token string or `null` and defaults to anonymous access; `maximum_artifact_bytes` is a positive compressed-ZIP byte ceiling and defaults to 52,428,800 bytes.

Release-source operations require WordPress's `direct` filesystem implementation. On a normal request where `FS_METHOD` is not explicitly configured, initialize the WordPress Filesystem API before the operation as shown above and continue only when `$GLOBALS['wp_filesystem']` is a `WP_Filesystem_Direct`. If the host resolves to FTP/SSH or initialization fails, do not force `FS_METHOD` from plugin code; the source returns `filesystem_unsupported` and does not prompt for filesystem credentials or perform provider work. A site that explicitly configures `FS_METHOD` as `direct` already satisfies the source's direct-method gate.

## Result envelope

Every operation returns exactly these top-level fields:

```text
ok
code
value
retry_after
cleanup_status
```

A failure has `value: null`. Only rate-limited results carry a non-null retry delay. Cleanup status describes custody of any archive allocated by that operation rather than the application's own files.

### Rejection reasons (opt-in)

By default a rejected release is just `package_incompatible`. A source created with `rejection_reasons: true` (the last optional `releases()` argument) adds a sixth envelope key, `rejection`, to every result of that source. Sources created without it return exactly the five fields above, so a consumer that has not opted in never sees the key and a consumer that has can fall back to `package_incompatible` when `rejection` is `null`.

`rejection` is `null` except on a failed `inspect()` or `acquire()` with code `package_incompatible` for a known cause, where it is `['reason' => string, 'detail' => string|null]`. `detail` is `null` or one short printable-ASCII string (at most 120 characters) such as `found 1.2.0, expected 1.2.3`. It is publisher-controlled text taken from a release tag or a downloaded package header: treat it as untrusted and escape it on output. The updater screens the complete text before bounding it and drops the whole detail (it never redacts in place) when any of these shapes appears: an at sign or userinfo; a query string; a credential-style `name=` or `name:` assignment; a provider-token prefix of any length (`ghp_`-family, `github_pat_`, `glpat-`, `xox*-`, `AKIA`/`ASIA`, `eyJ`, `npm_`, `sk_live_`/`sk_test_`, or `Basic`/`Bearer` followed by text); the words password, passwd, pwd, secret, token, authorization or API key beside any other word; a hash-algorithm prefix; a 32-or-more character hex run, or mixed-case digit-bearing base64-like run, once every non-alphanumeric character (including whitespace and non-ASCII) is removed; a `file:` or other `://` URL; an absolute, home, `..`-relative or drive path anywhere in the text; two or more slashes; a single-slash path ending in a letter file extension; a backslash; or markup. Only a lone canonical `https` host/path value is exempt from the slash and URL rules. The screening is by shape, so it is a safety net and not a guarantee that no sensitive text can pass, and it may drop benign text that resembles one of these shapes; consumers must still escape the detail on output. A mismatched `Update URI` is echoed only as its canonical host/path form on the expected host, otherwise `null`. The option takes exactly `true`; any other value is `invalid_configuration`. Treat an unknown `reason` as the generic code: new reasons may be added, existing names are stable.

| Reason | Detail |
| --- | --- |
| `release_draft` | none |
| `tag_not_version` | the release tag |
| `tag_mismatch` | `found <tag>, expected <tag>` |
| `prerelease_not_allowed` | the release tag (Stable channel) |
| `no_package_zip` | none |
| `multiple_package_zips` | `<n> ZIP assets` |
| `asset_not_uploaded` | `state <state>` |
| `asset_metadata_invalid` | none |
| `asset_too_large` | `<bytes> bytes, limit <bytes>` |
| `digest_missing` | none (no GitHub `sha256:` digest) |
| `download_size_mismatch` | `downloaded <n> bytes, expected <n>` |
| `download_digest_mismatch` | none |
| `archive_unreadable`, `archive_entry_limit`, `archive_size_limit`, `archive_path_unsafe`, `archive_path_duplicate` | none |
| `archive_root_not_single` | none (more than one top-level folder, or a loose top-level file; the folder name is not compared with an expected slug at inspection) |
| `no_package_header` | none (no plugin main file or `style.css` header) |
| `header_plugin_ambiguous` | none (more than one plugin main file) |
| `header_name_missing`, `header_version_missing`, `header_update_uri_missing` | none |
| `header_invalid`, `header_duplicate` | the header key, for example `Version` |
| `header_version_mismatch` | `found <header>, expected <tag version>` |
| `header_update_uri_mismatch` | the header's `Update URI` |
| `header_requires_php_unmet`, `header_requires_wp_unmet` | the header value; an absent `Requires PHP` or `Requires at least` header is accepted, a present empty, invalid or unmet one is not |

An older bundled copy does not know the option: asking an older selected runtime for it fails with `invalid_configuration`, so create the source with the option and, on that code, create it again without it.

## Operations

| Operation | Successful code | What it does |
| --- | --- | --- |
| `list($conditional = array())` | `releases_listed` or `releases_not_modified` | Lists bounded candidate metadata, optionally using caller-supplied conditional metadata |
| `inspect($release_id, $expected_tag)` | `release_inspected` | Freshly verifies the selected provider release and returns an opaque fingerprint plus release facts |
| `acquire($release_id, $expected_tag, $expected_fingerprint)` | `release_acquired` | Re-inspects the release, requires the same fingerprint, and returns a controlled temporary artifact |

The public value path is deliberate. `list()` returns candidate entries carrying the provider `release_identity` and `tag` that identify the release to inspect. Pass those values to `inspect()`. A successful inspection returns the opaque `fingerprint` for that exact inspected release; pass the same release identity/tag together with that fingerprint to `acquire()`.

`inspect()` is not a metadata-only call: it downloads and validates the full ZIP, then discards those inspection bytes synchronously before returning. A later `acquire()` performs a fresh download and validation rather than reusing the inspection ZIP. Callers should therefore budget one full ZIP transfer for inspection and another for acquisition.

The fingerprint is opaque. Store and replay it unchanged; do not parse it or build your own equivalent token.

## Failure codes

| Code | Meaning / caller action |
| --- | --- |
| `invalid_configuration` | The source declaration or `list()` conditional metadata is invalid; fix the relevant input rather than retrying |
| `invalid_release` | The release identifier/tag is invalid, or `acquire()` received a malformed/unsupported fingerprint |
| `runtime_not_ready` | The selected runtime has not reached the operation point yet |
| `runtime_unavailable` | The request can no longer use the selected runtime; do not retry through the same source |
| `provider_unavailable` | The requested provider code is not available in the sealed provider catalogue; choose a provider shipped by this package |
| `filesystem_unsupported` | WordPress is not using the supported `direct` filesystem path |
| `credential_unavailable` | The configured credentials callback did not produce usable material |
| `repository_access_unavailable` | Provider authentication/authorization or repository access failed |
| `release_unavailable` | The concrete release/commit/asset is absent; discovery may choose another candidate where appropriate |
| `package_incompatible` | The candidate/release ZIP does not satisfy the package contract; discovery may choose another candidate |
| `rate_limited` | Retry is caller-owned; `retry_after` is an integer from 1 to 86,400 seconds |
| `operation_failed` | Network, malformed provider response, identity mismatch, provider/server failure, or another non-recoverable operation failure |
| `release_changed` | `acquire()` found that the release no longer matches the fingerprint retained from inspection |
| `cleanup_failed` | The primary operation succeeded or had no stronger error, but owned temporary bytes could not be discharged safely |

Provider-specific HTTP details are intentionally mapped into these neutral codes. For GitHub, ordinary authorization failures remain distinct from rate limits; a rate-limit response is not silently treated as a missing release.

## Conditional listing and caller-owned cache

A caller may retain the conditional metadata returned by `list()` in application-owned storage and pass it to a later listing. A successful conditional `304` appears as `releases_not_modified` and creates no new candidate work.
A caller may also retain an inspection fingerprint alongside the exact release facts it selected. Scope retained data to the provider, repository identity, package type and other facts that make that selection meaningful. Do not reuse it for another repository/target merely because a tag or version is the same.

The library itself keeps source credentials request-local. It does not provide an application persistence layer or retry scheduler.

## Artifact custody

A successful `acquire()` returns a controlled artifact. The artifact exposes only scoped `inspect()` and `discard()` operations; its path is not an application-owned file.

Copy bytes inside `inspect()` if the application needs a durable copy, verify the copied size/digest against the returned inspection facts, and then call `discard()` on the updater-owned artifact.

Before running this custody step, supply the ready `$source`, the selected
`$release_id`/`$tag` and retained `$fingerprint` from a successful inspection.
Set `$application_storage_directory` from your application's configuration to
an existing, writable, private directory owned by the application. The example
constructs its destination there; it does not create or select a storage directory.

```php
$application_owned_path = $application_storage_directory . '/prepared-release.zip';

$acquisition = $source->acquire(
    release_id: $release_id,
    expected_tag: $tag,
    expected_fingerprint: $fingerprint
);

if (!$acquisition['ok']) {
    return;
}

$artifact = $acquisition['value']['artifact'];
$facts = $acquisition['value']['inspection'];
$created_application_copy = false;

try {
    $artifact->inspect(static function (string $path) use ($application_owned_path, $facts, &$created_application_copy): void {
        $input = fopen($path, 'rb');
        if (false === $input) {
            throw new \RuntimeException('Unable to open verified artifact.');
        }

        $output = fopen($application_owned_path, 'xb');
        if (false === $output) {
            fclose($input);
            throw new \RuntimeException('Unable to create application copy.');
        }
        $created_application_copy = true;

        try {
            stream_copy_to_stream($input, $output, $facts['artifact_size']);
        } finally {
            fclose($input);
            fclose($output);
        }

        if (hash_file('sha256', $application_owned_path) !== $facts['artifact_sha256']) {
            throw new \RuntimeException('Application copy did not preserve the verified digest.');
        }
    });

    if (!$artifact->discard()) {
        throw new \RuntimeException('Updater-owned artifact cleanup failed.');
    }
} catch (\Throwable $failure) {
    try {
        $artifact->discard();
    } catch (\Throwable) {
        // Preserve the primary failure.
    }
    if ($created_application_copy) {
        @unlink($application_owned_path);
    }
    throw $failure;
}
```

Opening the destination with `xb` deliberately refuses to overwrite an existing application file. Failure cleanup removes the destination only when this invocation actually created it.

The reader is synchronous. Do not retain, rename, modify, or persist the updater-owned artifact path after the callback.

Artifact guard exceptions use `RuntimeException` codes:

- `1001`: changed or unavailable artifact;
- `1002`: selected runtime unavailable;
- `1003`: artifact already busy/in use.

## Cleanup semantics

`cleanup_status` is one of the library's bounded custody outcomes:

- `not_applicable`: the operation did not allocate owned archive bytes;
- `complete`: allocated updater-owned bytes were discharged;
- `retained`: successful acquisition intentionally returned custody through the controlled artifact object;
- `failed`: updater-owned bytes could not be proven cleaned.

If cleanup reports `failed`, treat that as an operational fault rather than ignoring it. The library does not claim a clean terminal outcome when it cannot discharge bytes it owns.

## When to use this API

Use `releases()` when your application needs prospective release inspection, release selection, metadata display, or a verified archive for a purpose other than the native WordPress update target.

Use `plugin()` or `theme()` plus `register()` when the goal is to participate in WordPress Core's normal plugin/theme update lifecycle. See [Integrating the release updater](integration.md).

## Two-part stable tags

A stable tag such as `v1.3` is listed and offered as version `1.3.0` (`new_version` `1.3.0`) while its package header may still read `Version: 1.3`; the two compare equal. When `v1.3` and `v1.3.0` both exist the complete tag is listed first.
