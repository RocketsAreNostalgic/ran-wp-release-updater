# Reviewed protocol-9 runtime fixture

Source: RocketsAreNostalgic/ran-wp-release-updater, merged commit
`e5371fdf66f5a3410cfe2d4a92c965eb5b7cf615` (unreleased protocol 9; package version remains beta.20).

`runtime.json.gz.base64` encodes the 39 original bootstrap, runtime, manifest
and production PHP files as a gzip-compressed, path-sorted JSON map.
Compressed SHA-256: `c4c12087bdaeba5c26ad537ce9952d26b5359c7ce08073dc289c4528b940650e`.
The test verifies the original content revision before extraction.

Licensed GPL-2.0-or-later; see [LICENSE](../../../LICENSE).
