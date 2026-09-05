# Release-source operations

A source is request-local. Create it during early plugin or theme loading and
call it after WordPress has activated the selected runtime. It does not add a
native target, schedule an update, or install a package.

Release-source operations require WordPress direct filesystem access already
selected for the request: `FS_METHOD` must be `direct`, or WordPress must have
an initialized `WP_Filesystem_Direct` instance when no override is defined.
They otherwise return `filesystem_unsupported`; the source never probes or
prompts for filesystem credentials.

| Operation | Success code | Cleanup status | Failure handling |
| --- | --- | --- | --- |
| `list($conditional)` | `releases_listed` or `releases_not_modified` | `not_applicable` | Preserve a nullable ETag/Last-Modified conditional only in application-scoped storage. `rate_limited` supplies an integer `retry_after`. |
| `inspect($releaseId, $tag)` | `release_inspected` | `complete` | Store the opaque `v2:` fingerprint unchanged with the selected release facts. |
| `acquire($releaseId, $tag, $fingerprint)` | `release_acquired` | `retained` | The fresh inspection must match the selected fingerprint. The returned artifact owns the downloaded ZIP until discard succeeds. |

| Failure code | Applies to | Cleanup and retry rule |
| --- | --- | --- |
| `invalid_configuration`, `invalid_release`, `runtime_not_ready`, `runtime_unavailable`, `credential_unavailable`, `repository_access_unavailable`, `release_unavailable`, `package_incompatible`, `operation_failed` | The applicable operation | No owned archive means `not_applicable`; a cleaned allocated archive reports `complete`. A terminal runtime loss is not retried by this source. |
| `rate_limited` | Any operation | `not_applicable` and an integer `retry_after` of 1–86,400 seconds. The caller schedules any retry. |
| `release_changed` | `acquire()` | The fresh proof did not match the stored fingerprint. Its owned archive is synchronously removed and reports `complete`, or `failed` when cleanup cannot be discharged. |
| `cleanup_failed` | Inspection or acquisition cleanup | `failed`. It means cleanup was the only failure; a primary bounded failure code is otherwise retained. |
| `releases_not_modified` | `list()` | A successful conditional `304` result with `not_applicable`; it creates no candidate work. |

Every result has exactly `ok`, `code`, `value`, `retry_after`, and
`cleanup_status`. A failure has `value: null`. A not-modified listing is a
successful result with no new candidate work; rate limits are the only results
with a non-null retry delay. Reinspect before an acquisition when application
state is stale or the opaque fingerprint is unsupported. The source and its
credentials are request-local. A caller may retain a conditional value or a
fingerprint only in caller-owned cache storage; scope it to the selected facts
and do not reuse it across requests with a different target, provider,
repository identity, channel, policy, or runtime fingerprint.

```php
$acquisition = $source->acquire(
 releaseId: $releaseId, expectedTag: $tag, expectedFingerprint: $fingerprint
);
if ( ! $acquisition['ok'] ) { return; }

$artifact = $acquisition['value']['artifact'];
$facts = $acquisition['value']['inspection'];
$prepared = $applicationOwnedPath;
try {
 $artifact->inspect( static function ( string $path ) use ( $prepared, $facts ): void {
 $input = fopen( $path, 'rb' ); $output = fopen( $prepared, 'xb' );
 if ( ! is_int( $facts['artifact_size'] ) || ! is_int( $facts['maximum_artifact_bytes'] ) || $facts['artifact_size'] < 1 || $facts['artifact_size'] > $facts['maximum_artifact_bytes'] || false === $input || false === $output ) {
  if ( false !== $input ) { fclose( $input ); }
  if ( false !== $output ) { fclose( $output ); }
  throw new RuntimeException();
 }
  try {
   $remaining = $facts['artifact_size'];
   while ( $remaining > 0 && ! feof( $input ) ) {
    $chunk = fread( $input, min( 8192, $remaining ) );
    if ( false === $chunk || '' === $chunk ) { throw new RuntimeException(); }
    for ( $offset = 0; $offset < strlen( $chunk ); ) { $written = fwrite( $output, substr( $chunk, $offset ) ); if ( false === $written || 0 === $written ) { throw new RuntimeException(); } $offset += $written; }
    $remaining -= strlen( $chunk );
   }
   if ( 0 !== $remaining ) { throw new RuntimeException(); }
  } finally { fclose( $input ); fclose( $output ); }
  if ( hash_file( 'sha256', $prepared ) !== $facts['artifact_sha256'] ) { throw new RuntimeException(); }
 } );
 if ( ! $artifact->discard() ) { throw new RuntimeException(); }
 } catch ( Throwable $failure ) {
 try { $artifact->discard(); } catch ( Throwable ) { /* preserve the callback failure */ }
 @unlink( $prepared );
 throw $failure;
}
```

The reader is synchronous: do not retain, rename, modify, or persist the
artifact path. Remove the application provisional copy after a reader failure,
runtime loss, or failed discard. Artifact guards use `RuntimeException` codes
1001 (changed or unavailable), 1002 (runtime unavailable), and 1003 (busy).
