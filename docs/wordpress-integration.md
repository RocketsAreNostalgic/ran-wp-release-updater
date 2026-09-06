# Installed WordPress integration

`composer test:wordpress-integration` verifies two maintained guarantees in a
disposable WordPress installation: the updater operates from its installed
distribution, and main-site and subsite discovery share one operation fence.
This gate is separate from `composer check`.

It requires PHP 8.2 with `zip` and `mysqli`, WP-CLI 2.12.0, an executable
`mysqld`, and a pristine WordPress source tree. CI downloads WordPress 6.5 and
7.1 with `wp core download --skip-content`; use the same released versions
locally when reproducing its matrix.

Create owned, durable directories under the repository `.workspaces` root for
all mutable state. Do not use an OS temporary directory. The following example
uses WordPress 7.1; substitute 6.5 to reproduce the other CI row.

```sh
repo=$(pwd)
workspaces="$repo/.workspaces"
integration_root="$workspaces/wordpress-integration"
socket_root="$workspaces/sock"
php_tmp="$integration_root/php-tmp"
wp_cache="$integration_root/wp-cli-cache"
php_ini="$integration_root/php-ini"
wp_root="$workspaces/wordpress-7.1"
tools="$workspaces/tools"
mkdir -p "$integration_root" "$socket_root" "$php_tmp" "$wp_cache" "$php_ini" "$tools"

# Set these paths to installed, executable WP-CLI and mysqld binaries.
export RAN_UPDATER_WP_CLI="$tools/wp"
export RAN_UPDATER_MYSQLD_BIN="$tools/mysqld"
printf 'sys_temp_dir = "%s"\n' "$php_tmp" > "$php_ini/99-ran-updater-integration.ini"
export PHP_INI_SCAN_DIR="$php_ini:$(php --ini | sed -n 's/^Scan for additional .ini files in: //p')"
export WP_CLI_CACHE_DIR="$wp_cache"
export TMPDIR="$php_tmp" TMP="$php_tmp" TEMP="$php_tmp"
php -d "sys_temp_dir=$php_tmp" "$RAN_UPDATER_WP_CLI" core download --version=7.1 --skip-content --path="$wp_root"
export RAN_UPDATER_WP_ROOT="$wp_root"
export RAN_UPDATER_INTEGRATION_ROOT="$integration_root"
export RAN_UPDATER_SOCKET_ROOT="$socket_root"
composer test:wordpress-integration
```

`RAN_UPDATER_INTEGRATION_ROOT` and `RAN_UPDATER_SOCKET_ROOT` must already be
writable non-symlink directories. When a repository workspace would exceed the
platform socket-path limit, set the optional socket root to a separately
authorized, short durable directory; it must still be owned by the operator.
The suite creates a unique marker-owned child for each run, starts a local
socket-only MySQL server, and removes only those marker-owned children.

The suite has no Booster checkout or live site/provider dependency. Missing
inputs, unavailable tools, a failed scenario, or residual owned files make the
command fail; none are a skipped pass. It writes a redacted
`ran-wp-release-updater-result-<random>.json` in
`RAN_UPDATER_INTEGRATION_ROOT` and a stdout JSON summary. Preserve them as
candidate evidence: they bind the tested candidate to its
installed-distribution and multisite outcomes. Do not claim a guarantee passes
until this command has succeeded for that candidate.
