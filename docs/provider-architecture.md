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

The optional credential callback has the same meaning for plugin and theme
targets. It may provide private-repository access or an authenticated quota for
public reads. A `null` result selects anonymous access. A supplied token that
is malformed or unavailable returns `credential_unavailable`. A well-formed
token denied by GitHub returns `repository_access_unavailable`. Neither result
silently retries that request anonymously.

## Provider responses

Each provider classifies its own HTTP responses by throwing an internal neutral
`ReleaseFailure`; a public release source converts that failure into its result
envelope. Candidate discovery may continue after a clean
`release_unavailable` or `package_incompatible` failure. It stops for every
other failure by default.

For GitHub, `429`, or `403` with a valid `Retry-After` or zero remaining quota,
is rate limited. A reset time applies only when remaining quota is zero. The
largest applicable positive delay is used; missing usable hints fall back to
900 seconds, and a delay above 86,400 seconds is `operation_failed`. A
headerless `403` is an access failure. The delay is a hint for caller-owned
scheduling; the native lifecycle keeps its existing stage codes and does not
create a persistent cooldown. GitHub documents the upstream headers and
rate-limit responses in its
[REST API rate-limit guidance](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api).

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

Future providers define their own response and rate-limit rules before they
join the sealed catalog. No additional provider implementation is included.

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

## Release sources

`releases()` creates a request-local source without an installed-file
declaration, native hooks, or installation authority. Its `list()`,
`inspect()`, and `acquire()` operations return `ok`, `code`, `value`,
`retry_after`, and `cleanup_status`. Inspection supplies an opaque `v2:`
fingerprint, and acquisition requires it for a fresh proof. Credentials remain
request-local. Consumers copy verified bytes only inside artifact `inspect()`,
then call `discard()`; callback, runtime, or cleanup failure requires removal
of the consumer's provisional copy. Guard failures use RuntimeException codes
1001, 1002, and 1003.
