# beta.15 public runtime fixture

Source: RocketsAreNostalgic/ran-wp-release-updater, commit
`2283850d81a3a5b0dfa7fee07beddaba8bf02883` (1.0.0-beta.15).

`runtime.json.gz` SHA-256: `c11e968c69ffbd936a742c5150707e7483f9eb1c9adf5ee8883260b3c50b9346`.
It is a gzip-compressed JSON map of paths to base64-encoded original file bytes,
with paths sorted lexically. It contains only `bootstrap.php`, `runtime.php`,
`runtime-copy.json`, and the PHP files under `src/` at that commit. The test
also checks the original runtime's package revision against an independent
constant before writing any decoded files.

The upstream project is licensed under GPL-2.0-or-later; see the repository's
[LICENSE](../../../LICENSE).
