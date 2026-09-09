# Releases

This package follows the RAN release-updater's exact-commit publishing process.
Its independent prerelease line starts at `v0.1.0-beta.1`. Composer derives
versions from Git tags; the manifest's `0.0.0` value means unreleased.

## Repository setup

The repository uses `main`, protected against deletion and force pushes, with
pull requests and the `quality` CI check required. Release PRs must use a normal
merge commit: squash and rebase merges cannot satisfy the publisher's parent and
tree checks. Every PR receives independent review of its exact base and head;
merging remains an explicit owner decision.

Enable GitHub Actions PR creation and immutable releases before releasing.
Set the repository variable
`RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID` to the exact
numeric repository ID only after verifying immutable releases are enabled.
The variable acknowledges that setting; it does not enable it.

## Prepare and publish

1. Merge reviewed Conventional Commits through the normal PR process. CI runs
   Composer validation, PHP contract tests and lint, and publisher tests. A
   successful same-repository main push starts Release Please.
2. Release Please opens a version PR. Approve its Actions workflow run if GitHub
   requires approval for the bot-created PR; `CI` also supports manual dispatch
   against the exact PR branch. Review the version and complete changelog diff.
   The first release must advance `0.0.0` to `0.1.0-beta.1`; later releases remain
   on the `0.1.0-beta.*` line. Changing that line requires a reviewed configuration
   and publisher policy change.
3. Run independent review against the exact PR base and head, resolve findings,
   and present the checks and normal-merge method to the owner. Merge only after
   explicit authorization. Only the manifest version and prepended changelog
   section may change in the release PR.
4. Successful CI for that exact main merge permits publication. The publisher
   verifies the PR's two parents and head tree, rechecks main and remote release
   state, and creates one immutable prerelease targeting the exact merge SHA.
   It publishes no uploaded assets. GitHub's source archives are the Composer
   distribution. Conflicting or partial remote state fails closed.
5. The publisher verifies the tag, release metadata, notes and empty asset list
   before changing the PR's lifecycle label. A retry after label interruption
   reconciles the existing release instead of publishing a second one.

Do not create manual release tags, move existing tags, bypass failed checks, or
edit generated version/changelog content outside a reviewed release correction.
Release Please prepares PRs; this repository's separate publisher owns releases.
Packagist registration is a separate publication step and is not implied by a
GitHub release.

## Before the first release

A consuming root project can declare the GitHub VCS repository and require
`ran/updater-support` using `dev-main`. Commit the root project's lockfile, verify its source reference
against the reviewed full commit SHA, and explicitly allow that development
dependency. Composer
repository declarations are root-only and are not inherited from dependencies.
Replace the development pin with an owner-approved beta tag after publication.
There is no claimed `0.1.0` release and no local path repository requirement.

The initial bootstrap boundary is the fresh repository's seed commit. The
following `feat` commit supplies the first release's source and changelog scope.
See the [Release Please manifest reference](https://github.com/googleapis/release-please/blob/main/docs/manifest-releaser.md)
and [GitHub immutable releases documentation](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/establish-provenance-and-integrity/prevent-release-changes).
