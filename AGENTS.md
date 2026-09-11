# Agent guidance

This repository is the fresh Git lineage for the provider-neutral
`ran/wp-release-updater` Composer package. Keep every committed file suitable
for eventual public disclosure.

- Preserve the package coordinate, `RAN\WPReleaseUpdater\V1` namespace, and
  independent `v0.1.0-beta.*` prerelease line.
- Keep the built-in provider catalog sealed inside the selected runtime. Add
  no public adapter loader, compatibility facade, second broker, `replace`, or
  `provide` declaration.
- Keep credentials request-local and out of source, fixtures, diagnostics,
  caches, logs, URLs, and committed artifacts.
- Run `composer check` and the focused lifecycle or provider tests owned by a
  change before committing it.
- Treat pushes, pull requests, merges, visibility changes, tags, releases, and
  publication as separate operations requiring their applicable authority.
- Preserve the fresh-history boundary: import reviewed source bytes only, not
  Git objects, refs, tags, remotes, or internal planning evidence from another
  repository.

## Blacksmith AI prohibition

Blacksmith is approved only as GitHub Actions runner infrastructure where a
repository workflow explicitly selects a Blacksmith runner.

- Never invoke, delegate work to, tag, enable, or otherwise use Blacksmith
  [code]smith, `@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning,
  Blacksmith Testbox agents, or any other Blacksmith AI/agent feature.
- Do not trigger "Enable autofix", ask [code]smith to investigate or repair CI,
  or call Blacksmith agent/MCP/CLI/API features that perform AI inference.
- If CI fails, inspect GitHub Actions logs directly and diagnose or fix the
  failure without delegating it to Blacksmith AI.
- This is a cost-control requirement. Do not override it for convenience, CI
  failures, review comments, or suggestions presented by GitHub or Blacksmith
  UI.
