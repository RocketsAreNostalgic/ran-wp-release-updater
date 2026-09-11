# Testing and verification

This page is for maintainers and contributors. It explains the repository's verification layers, the local prerequisites for the installed-WordPress suite, and—most importantly—what those tests do **not** prove.

Consumer integration guidance lives in [Integrating the release updater](integration.md).

## Default quality gate

Run:

```sh
composer install
composer check
```

`composer check` validates the package metadata and generated updater-support copy, audits locked Composer dependencies, lints PHP syntax/style, runs PHPStan, executes PHPUnit, and proves the installed no-dev Composer consumer path.

It is the baseline repository gate, not the complete environment matrix.

The CI quality job additionally runs JavaScript/release-publisher checks and the isolated MySQL CAS lifecycle proof. CI also has separate PHP 8.5, Windows portability, and installed-WordPress jobs.

## Maintained CI environment

The maintained matrix currently exercises:

- PHP 8.2 as the package floor;
- PHP 8.5 compatibility;
- Windows portability for syntax/runtime-path assumptions;
- installed WordPress integration on WordPress 6.5 and 7.1;
- an isolated MySQL proof for the persistent binding/fence CAS lifecycle.

Those rows are evidence for the tested boundaries, not a claim that every PHP/WordPress/host combination between them has been exhaustively exercised.

## Installed WordPress integration suite

Run:

```sh
composer test:wordpress-integration
```

The suite verifies two maintained guarantees in a disposable WordPress installation:

1. the updater operates from its installed distribution rather than only from the repository checkout;
2. the same target observed from the main site and a subsite shares one network-scoped operation fence.

The suite is intentionally separate from `composer check` because it needs a real WordPress source tree and local MySQL process.

### Prerequisites

The suite requires:

- PHP 8.2 with `zip` and `mysqli`;
- WP-CLI 2.12.0;
- an executable `mysqld`;
- a pristine WordPress source tree for the version being tested;
- durable writable directories owned by the operator for the integration run and local socket.

CI downloads WordPress 6.5 and 7.1 using `wp core download --skip-content`. Use those versions when reproducing the maintained matrix locally.

Set the following paths before running the suite:

```sh
export RAN_UPDATER_WP_CLI=/path/to/wp
export RAN_UPDATER_MYSQLD_BIN=/path/to/mysqld
export RAN_UPDATER_WP_ROOT=/path/to/pristine-wordpress
export RAN_UPDATER_INTEGRATION_ROOT=/path/to/durable/integration-root
export RAN_UPDATER_SOCKET_ROOT=/path/to/short/durable/socket-root
composer test:wordpress-integration
```

`RAN_UPDATER_INTEGRATION_ROOT` and `RAN_UPDATER_SOCKET_ROOT` must already be writable, non-symlink directories. Keep them outside disposable OS temp locations. The suite creates marker-owned children and removes only state it can prove it owns.

If the normal repository workspace makes MySQL's Unix socket path too long, use a separately authorized short directory for `RAN_UPDATER_SOCKET_ROOT`.

Missing prerequisites and failed scenarios are failures, not skipped passes.

## Other focused suites

The Composer scripts expose narrower proofs for specific boundaries:

```sh
composer test:wordpress-bulk
composer test:release-publisher
```

Use the focused suite when changing its boundary, but do not substitute it for `composer check` or the relevant CI matrix.

Provider behavior is covered by the maintained unit/contract suite inside `composer check`.

The real-MySQL CAS proof is run by CI's quality job rather than being presented as a standalone consumer feature. It protects the persistence/fencing implementation against stale or competing writers.

## What the repository tests prove

Within their tested fixtures/environments, the suite provides evidence for:

- package metadata and installed no-dev consumer behaviour;
- provider-neutral and GitHub provider contracts;
- archive/path/custody validation rules;
- native plugin/theme callback behaviour;
- sealed physical-runtime selection and compatibility checks;
- persistent binding/fence behaviour, including real MySQL CAS semantics;
- WordPress 6.5/7.1 installed-distribution behaviour;
- the bounded multisite target/fence guarantee;
- Windows-sensitive runtime/path behaviour.

## What the repository tests do not prove

The project deliberately avoids overstating its matrix. In particular:

- **No live GitHub dependency in the installed WordPress suite.** Provider behaviour is proven with controlled fixtures/contracts; the installed suite does not make a release-updater test depend on GitHub availability or quota.
- **No Booster checkout in the package integration suite.** Booster is a downstream consumer, not a test prerequisite for the reusable library.
- **No FTP/SSH WordPress filesystem support.** The product supports the `direct` filesystem method; alternative transports are outside both the runtime support contract and the maintained test guarantee.
- **No claim about every WordPress version.** CI tests the maintained floor/current rows, not every intermediate or future WordPress build.
- **No claim about every PHP version.** PHP 8.2 and the maintained compatibility row are explicit; other environments need their own evidence.
- **No full multisite administration proof.** The suite proves shared target/fence behaviour across main/subsite contexts, not network activation, inventory, UI, or host policy.
- **No hostile same-user sandbox.** Tests cannot make the updater a security boundary against arbitrary PHP/processes with equivalent filesystem/database authority.
- **No product-specific scheduling/UI proof.** WordPress and the embedding application own those behaviours.

These limits should remain visible in public documentation because they define where an integrator needs additional product-specific testing.
