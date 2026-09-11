# Release-source API

The `releases()` API is for applications that need verified release metadata or controlled release bytes without registering a plugin/theme with WordPress's native updater.

It is an advanced public API. A release source is request-local, performs no installation, and gives the caller explicit ownership of scheduling and any application-level cache.

## Create a source

Create the source during normal early plugin/theme bootstrap so it can bind to the selected runtime, then call its operations later in the request:

```php
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';

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
    if (!$listing['ok']) {
        return;
    }

    // Choose from $listing['value'].
});
```

The source adds no native update hooks, does not schedule retries, and never installs the acquired archive.

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

## Operations

| Operation | Successful code | What it does |
| --- | --- | --- |
| `list($conditional = array())` | `releases_listed` or `releases_not_modified` | Lists bounded candidate metadata, optionally using caller-supplied conditional metadata |
| `inspect($releaseId, $expectedTag)` | `release_inspected` | Freshly verifies the selected provider release and returns an opaque fingerprint plus release facts |
| `acquire($releaseId, $expectedTag, $expectedFingerprint)` | `release_acquired` | Re-inspects the release, requires the same fingerprint, and returns a controlled temporary artifact |

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

```php
$acquisition = $source->acquire(
    releaseId: $releaseId,
    expectedTag: $tag,
    expectedFingerprint: $fingerprint
);

if (!$acquisition['ok']) {
    return;
}

$artifact = $acquisition['value']['artifact'];
$facts = $acquisition['value']['inspection'];
$createdApplicationCopy = false;

try {
    $artifact->inspect(static function (string $path) use ($applicationOwnedPath, $facts, &$createdApplicationCopy): void {
        $input = fopen($path, 'rb');
        if (false === $input) {
            throw new \RuntimeException('Unable to open verified artifact.');
        }

        $output = fopen($applicationOwnedPath, 'xb');
        if (false === $output) {
            fclose($input);
            throw new \RuntimeException('Unable to create application copy.');
        }
        $createdApplicationCopy = true;

        try {
            stream_copy_to_stream($input, $output, $facts['artifact_size']);
        } finally {
            fclose($input);
            fclose($output);
        }

        if (hash_file('sha256', $applicationOwnedPath) !== $facts['artifact_sha256']) {
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
    if ($createdApplicationCopy) {
        @unlink($applicationOwnedPath);
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
