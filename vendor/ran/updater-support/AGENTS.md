# Agent guidance

This is the independent `ran/updater-support` Composer library. Keep committed
files suitable for public distribution. Shared utilities need concrete consumers
with equivalent behavior; keep updater orchestration in its owning package.

- Run `composer check` before committing and retain consumer contract fixtures.
- Preserve the `RAN\UpdaterSupport\V1` public namespace and independent beta line.
- Before changing release automation or preparing a release, read `RELEASING.md`.
- Every pull request needs independent review against its exact base/head SHAs.
- Merging requires explicit owner authorization of the exact PR and merge method.
- Keep credentials, local logs, vendor files and internal planning out of commits.
