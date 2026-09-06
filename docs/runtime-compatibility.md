# Runtime compatibility

The native status contract uses runtime Protocol 4. Its
`offered_release_identity` field identifies the exact verified release used for
`offered_version`, or is `null` when no native offer is current.

Released Protocol 3 copies validate an exact status shape and cannot accept
this additional field. Protocols 1, 2 and 3 are incompatible with this runtime.
Update bundled copies together across plugins and themes. An incompatible
copy cannot register native targets into the selected runtime. Loading another
protocol after activation rejects its declarations but does not remove callbacks
already registered by the first runtime.

When released beta.3 and this copy load before activation, the first runtime
fails activation with `runtime_handoff_invalid` in either order. The later copy
rejects declarations with `protocol_conflict_inactive`. These combinations do
not provide cross-protocol compatibility.

The Composer coordinate `ran/wp-release-updater`,
`RAN\WPReleaseUpdater\V1` namespace, and independent `v0.1.0-beta.*` release line
remain unchanged. The runtime protocol is independent of the package version.
