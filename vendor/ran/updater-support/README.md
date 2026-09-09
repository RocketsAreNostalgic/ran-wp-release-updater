# RAN Updater Support

`ran/updater-support` provides shared contracts and utilities for RAN updater packages. Its first component is a pure PHP 8.2 archive-safety contract. This component performs no ZIP, filesystem, network, or custody work.

`ArchiveSafety::normalizePath()` accepts printable ASCII relative paths with one optional trailing slash. It rejects traversal, empty components, Windows-reserved names, unsafe punctuation, trailing spaces or dots, and paths or components over the documented byte limits.

`entryTypeFailure()` accepts only ZIP origin OS values DOS (`0`) and UNIX (`3`). Unknown origins are rejected because the package does not guess external-attribute semantics. UNIX accepts unspecified, regular, and directory modes; DOS accepts unspecified file flags or a matching directory bit, and rejects volume labels. `collisionFailure()` detects case-insensitive duplicate paths and files used as ancestors while allowing explicit or implicit directories.

## Contract and usage

The raw archive name is limited to 4,096 bytes, including any trailing directory
slash. Each component is limited to 255 bytes. There is no independent depth
limit. These are archive admission budgets; the installer must still check its
actual destination and filesystem capabilities.

```php
use RAN\UpdaterSupport\V1\ArchiveSafety;

$entry = ArchiveSafety::normalizePath('example/assets/icon.svg');
if ($entry === null) {
    throw new InvalidArgumentException('Unsafe archive path.');
}

$failure = ArchiveSafety::entryTypeFailure(
    originOs: 3,
    attributes: 0100644 << 16,
    directory: false,
);

$collision = ArchiveSafety::collisionFailure([
    ['path' => 'example', 'directory' => true],
    $entry,
]);
```

Only pass successfully normalized entry facts to `collisionFailure()`. It returns
`path_duplicate`, `file_parent_collision`, or `null`. Metadata validation returns
`entry_metadata_invalid`, `entry_type_unsupported`, or `null`; unavailable
metadata differs from valid zero attributes. Consumers retain their own outward
error codes. Case folding is deterministic because admitted names are ASCII.

ZIP origin identifies the archive writer's attribute encoding, not the operating
system currently running PHP. DOS upper bits are not interpreted as Unix mode.
Missing metadata, unknown origins and contradictory file/directory metadata fail
closed. Extending supported origin encodings requires evidence and fixtures.
See [ZIP APPNOTE sections 4.4.2 and 4.4.15](https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT)
and [Windows filename rules](https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file).

## Package boundary

The package has no WordPress or updater dependency. Additional utilities belong
here when actual consumers need the same behavior and a shared implementation
reduces maintained duplication.

The branch updater loads the Composer dependency directly. The release updater
bundles a generated namespace-scoped copy within its verified runtime and checks
that copy against the installed dependency. The canonical fixture corpus in
`tests/fixtures/archive-safety.php` is included for consumer compatibility checks.

Both whole-archive validators retain package identity, root selection, resource
limits and their source-specific checks. Archive safety does not extract archives
or own temporary files, permissions, cleanup, credentials or deployment state.

## Development and releases

Run `composer install`, then `composer check` with PHP 8.2 and Node 24.11.0.
Composer consumes the package through its Git version; `composer.json` deliberately
has no version field. The initial `0.0.0` release manifest is an unreleased
bootstrap, not an available version.

See [RELEASING.md](RELEASING.md) for the independent beta release process and
installation before the first release.
