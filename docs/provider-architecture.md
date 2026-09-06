# Provider architecture

The package has one public integration path for plugins and themes and one
shared WordPress update lifecycle. Provider-specific code sits behind that
boundary.

## Public registration

Consumers require `bootstrap.php` and call `plugin()` or `theme()`. Each call
declares the installed file, provider code, repository locator, stable
repository identity, release channel, update policy, and optional request-local
credential resolver.

The declaration contains data, not an adapter object, class name, factory, file
path, or arbitrary callback. Consumers cannot add providers to the selected
runtime. An unknown provider code leaves the target inactive with an
`unsupported_provider` diagnostic.

## Provider dispatch

The selected runtime owns a sealed catalog of built-in providers. After it has
resolved the installed plugin or theme, it passes the declaration and installed
package facts to the selected provider's composition function.

That function is provider-owned. It validates the provider's repository
locator and stable repository identity, constructs the provider credential
resolver and release adapter, and binds the provider facts to the installed
package. It then supplies the resulting adapter and exact package policy to the
shared WordPress lifecycle.

GitHub Releases is the only catalog entry today. Its composition is implemented
by `GitHubReleaseAdapter::composeFromDeclaration()`.
`GitHubReleaseAdapter::registerFromConfiguration()` is likewise
GitHub-specific: it constructs a `GitHubReleaseAdapter` and accepts a
`GitHubCredentialResolver`. Both methods are internal composition details, not
supported consumer APIs.

## Provider-neutral lifecycle seam

`ReleaseAdapter` defines the provider-neutral discovery, inspection, and
acquisition contract. `NativePluginUpdater::fromConfiguration()` accepts that
interface together with a provider-neutral `BindingRecord`, exact native
configuration, and archive policy. `NativePluginUpdater` then owns the WordPress
hooks and update lifecycle. Archive identity, package validation, operation
fencing, installation checks, and diagnostics remain shared.

This is the lower-level seam shared by every built-in provider. It is public in
PHP visibility so package-owned composition code can call it, but it is not a
supported consumer API. Its inputs are validated trust objects and policies,
not ordinary plugin or theme settings.

A future built-in provider therefore needs its own provider adapter and
composition function, plus a package-owned catalog entry. It does not need a
new consumer registration API or another WordPress lifecycle.

## Extension boundary

The catalog is deliberately closed. There is no public adapter loader or
third-party provider registration hook. Supporting another provider is a
package change that must define and test its repository identity, release and
artifact identity, credential behavior, redirect policy, immutable-release
evidence, and reacquisition guarantees.

Artifact policy is target-local. `plugin()` and `theme()` default
`maximumArtifactBytes` to 52,428,800 bytes (50 MiB), and a host may pass any
positive PHP integer to select another limit for that target. A larger value
increases the accepted compressed-artifact and disk exposure. The value bounds
accepted GitHub asset metadata and retained compressed ZIP bytes in the
temporary file, not underlying network transfer; the request timeout still
applies. It does not relax the independent expanded-archive byte, entry-count,
compression-ratio, digest, or custody checks.
