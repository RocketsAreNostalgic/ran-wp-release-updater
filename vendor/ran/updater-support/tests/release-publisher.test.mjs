import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import { PublisherRefusal, candidateIdentity, classifyParentReleaseMetadata, decidePublication, hydrateExactReleasePullTree, runPublisher, verifyReleaseDelta, verifyPublishedState } from "../scripts/release-publisher.mjs";

const SHA = "a".repeat(40);
const REPOSITORY = "RocketsAreNostalgic/ran-updater-support";
const ID = 42;
const VERSION = "0.1.0-beta.1";

function contents(version = VERSION) {
  return {
    manifest: JSON.stringify({ ".": version }),
    composer: JSON.stringify({ name: "ran/updater-support", type: "library" }),
    changelog: `# Changelog\n\n## ${version} (2026-09-01)\n\n### Features\n\n* first release\n\n# prior\n`,
  };
}
function refusal(code, callback) { assert.throws(callback, (error) => error instanceof PublisherRefusal && error.code === code); }
function pull() { return { state: "closed", merged_at: "2026-09-01T00:00:00Z", draft: false, merge_commit_sha: SHA, base: { ref: "main", sha: "c".repeat(40), repo: { id: ID, full_name: REPOSITORY } }, head: { ref: "release-please--branches--main--components--ran/updater-support", sha: "d".repeat(40), repo: { id: ID, full_name: REPOSITORY } }, head_tree_sha: "e".repeat(40), user: { login: "github-actions[bot]" }, title: `chore(main): release ${VERSION}`, number: 7, labels: [{ name: "autorelease: pending" }] }; }

test("candidate binds manifest, Composer identity and release notes", () => {
  const identity = candidateIdentity(contents(), SHA);
  assert.equal(identity.version, VERSION);
  assert.equal(identity.tag, `v${VERSION}`);
});

test("candidate accepts dated linked headings only on the independent beta line", () => {
  const next = "0.1.0-beta.2";
  const linked = {
    ...contents(next),
    changelog: `# Changelog\n\n## [${next}](https://github.com/RocketsAreNostalgic/ran-updater-support/compare/v${VERSION}...v${next}) (2026-09-02)\n\n### Bug Fixes\n\n* second release\n`,
  };
  assert.equal(candidateIdentity(linked, SHA).version, next);
  for (const version of ["0.2.0-beta.1", "1.0.0-beta.1"]) {
    refusal("release_manifest_invalid", () => candidateIdentity(contents(version), SHA));
  }
});

test("release delta permits only manifest version and a changelog prepend", () => {
  const parent = { ...contents("0.0.0"), changelog: "# Changelog\n\n## [Unreleased]\n\nAll notable changes.\n" };
  const candidate = { ...contents(), changelog: `# Changelog\n\n## ${VERSION} (2026-09-01)\n\n### Features\n\n* first release\n\n## [Unreleased]\n\nAll notable changes.\n` };
  assert.deepEqual(verifyReleaseDelta(parent, candidate), { parentVersion: "0.0.0", candidateVersion: VERSION });
  const next = "0.1.0-beta.2";
  const later = { ...contents(next), changelog: `# Changelog\n\n## [${next}](https://github.com/RocketsAreNostalgic/ran-updater-support/compare/v${VERSION}...v${next}) (2026-09-02)\n\n### Bug Fixes\n\n* second release\n\n${candidate.changelog.slice("# Changelog\n\n".length)}` };
  assert.deepEqual(verifyReleaseDelta(candidate, later), { parentVersion: VERSION, candidateVersion: next });
  refusal("release_content_drift", () => verifyReleaseDelta(parent, { ...candidate, manifest: JSON.stringify({ ".": VERSION }, null, 2) }));
});

test("only exact green CI normal merge and changed paths can publish", () => {
  const identity = candidateIdentity(contents(), SHA); const releasePull = pull();
  const input = { event: { event: "push", conclusion: "success", head_branch: "main", head_sha: SHA, head_repository: { full_name: REPOSITORY, id: ID } }, candidateSha: SHA, mainSha: SHA, identity, pulls: [releasePull], repository: REPOSITORY, repositoryId: ID, immutableAcknowledgement: String(ID), tagRef: null, release: null, commit: { sha: SHA, parents: [{ sha: "c".repeat(40) }, { sha: "d".repeat(40) }], tree: { sha: "e".repeat(40) }, parentVersion: "0.0.0", changedPaths: [".release-please-manifest.json", "CHANGELOG.md"] } };
  assert.deepEqual(decidePublication(input), { action: "create_release", pullNumber: 7 });
  refusal("release_paths_invalid", () => decidePublication({ ...input, commit: { ...input.commit, changedPaths: [...input.commit.changedPaths, "src/ArchiveValidator.php"] } }));
  refusal("main_moved", () => decidePublication({ ...input, mainSha: undefined }));
  for (const parentVersion of ["0.2.0-beta.1", "1.0.0-beta.1"]) refusal("release_parent_version_invalid", () => decidePublication({ ...input, commit: { ...input.commit, parentVersion } }));
});

test("exact merged Release Please pull hydrates its head tree", async () => {
  const releasePull = { ...pull(), head_tree_sha: undefined }; const calls = [];
  const pulls = await hydrateExactReleasePullTree(REPOSITORY, SHA, [releasePull], async (path, options) => { calls.push({ path, options }); return { sha: releasePull.head.sha, tree: { sha: "e".repeat(40) } }; });
  assert.equal(pulls[0].head_tree_sha, "e".repeat(40));
  assert.deepEqual(calls, [{ path: `/repos/${REPOSITORY}/git/commits/${releasePull.head.sha}`, options: undefined }]);
});

test("malformed hydrated head tree fails closed without writes", async () => {
  const releasePull = { ...pull(), head_tree_sha: undefined }; const calls = [];
  await assert.rejects(hydrateExactReleasePullTree(REPOSITORY, SHA, [releasePull], async (path, options) => { calls.push({ path, options }); return { sha: releasePull.head.sha, tree: { sha: "not-a-sha" } }; }), (error) => error.code === "release_pr_head_tree_invalid");
  assert.deepEqual(calls, [{ path: `/repos/${REPOSITORY}/git/commits/${releasePull.head.sha}`, options: undefined }]);
});

test("ordinary unreleased main state has no publication side effect", () => {
  const identity = { candidateSha: SHA, version: "0.0.0" };
  assert.deepEqual(decidePublication({ event: { event: "push", conclusion: "success", head_branch: "main", head_sha: SHA, head_repository: { full_name: REPOSITORY, id: ID } }, candidateSha: SHA, mainSha: SHA, identity, pulls: [], repository: REPOSITORY, repositoryId: ID, commit: { parentVersion: "0.0.0" } }), { action: "none", reason: "ordinary_main" });
});

test("missing or partial parent metadata has no legacy exceptions", () => {
  assert.equal(classifyParentReleaseMetadata({ manifest: null, changelog: null }), "absent");
  const blob = { mode: "100644", type: "blob", sha: SHA };
  assert.equal(classifyParentReleaseMetadata({ manifest: blob, changelog: blob }), "complete");
  for (const entries of [{ manifest: blob, changelog: null }, { manifest: null, changelog: blob }]) {
    refusal("release_content_drift", () => classifyParentReleaseMetadata(entries));
  }
});

test("immutable release readback rejects mutable or asset-bearing releases", () => {
  const identity = candidateIdentity(contents(), SHA);
  const tag = { object: { type: "commit", sha: SHA } };
  const release = { id: 1, tag_name: identity.tag, target_commitish: SHA, name: identity.tag, body: identity.notes, draft: false, prerelease: true, immutable: true, assets: [] };
  assert.equal(verifyPublishedState(tag, release, identity), true);
  refusal("release_state_conflict", () => verifyPublishedState(tag, { ...release, immutable: false }, identity));
  refusal("release_asset_conflict", () => verifyPublishedState(tag, { ...release, assets: [{ id: 1 }] }, identity));
  refusal("release_state_conflict", () => verifyPublishedState({ object: { type: "tag", sha: SHA } }, release, identity));
});

function git(root, args) { return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim(); }
function publisherFixture(rootOnly = false) {
  const root = mkdtempSync(join(tmpdir(), "updater-support-publisher-"));
  git(root, ["init", "--initial-branch=main"]);
  git(root, ["config", "user.email", "test@example.test"]);
  git(root, ["config", "user.name", "Test"]);
  const write = (version, changelog) => {
    writeFileSync(join(root, ".release-please-manifest.json"), JSON.stringify({ ".": version }));
    writeFileSync(join(root, "composer.json"), JSON.stringify({ name: "ran/updater-support", type: "library" }));
    writeFileSync(join(root, "CHANGELOG.md"), changelog);
  };
  write("0.0.0", "# Changelog\n\n## [Unreleased]\n\nBootstrap\n");
  git(root, ["add", "."]); git(root, ["commit", "-m", "chore: bootstrap"]);
  const base = git(root, ["rev-parse", "HEAD"]);
  let head = base;
  if (!rootOnly) {
    git(root, ["checkout", "-b", "release-please--branches--main--components--ran/updater-support"]);
    write(VERSION, `# Changelog\n\n## ${VERSION} (2026-09-01)\n\n### Features\n\n* release\n\n## [Unreleased]\n\nBootstrap\n`);
    git(root, ["add", "."]); git(root, ["commit", "-m", "chore(main): release"]);
    head = git(root, ["rev-parse", "HEAD"]);
    git(root, ["checkout", "main"]); git(root, ["merge", "--no-ff", "--no-edit", head]);
  }
  const candidate = git(root, ["rev-parse", "HEAD"]);
  const tree = git(root, ["show", "-s", "--format=%T", candidate]);
  const fixture = { root, base, head, candidate, tree, eventPath: join(root, "event.json") };
  writeEvent(fixture);
  return fixture;
}
function writeEvent(fixture, changes = {}) {
  writeFileSync(fixture.eventPath, JSON.stringify({ repository: { id: ID }, workflow_run: { event: "push", conclusion: "success", head_branch: "main", head_sha: fixture.candidate, head_repository: { id: ID, full_name: REPOSITORY }, ...changes } }));
}
function transport(fixture, options = {}) { const calls = []; const state = { tag: null, release: null, labels: ["autorelease: pending"] }; const response = (data, status = 200) => new Response(data === null ? null : JSON.stringify(data), { status, headers: { link: "" } }); const fetch = async (url, init = {}) => { const value = new URL(url); const method = init.method ?? "GET"; calls.push({ path: value.pathname + value.search, method }); const pr = { ...pull(), merge_commit_sha: fixture.candidate, base: { ref: "main", sha: fixture.base, repo: { id: ID, full_name: REPOSITORY } }, head: { ref: "release-please--branches--main--components--ran/updater-support", sha: fixture.head, repo: { id: ID, full_name: REPOSITORY } }, labels: state.labels.map((name) => ({ name })) }; if (value.pathname.endsWith(`/commits/${fixture.candidate}/pulls`)) return response(options.ordinary ? [] : [pr]); if (value.pathname.endsWith(`/git/commits/${fixture.head}`)) return response({ sha: fixture.head, tree: { sha: options.badTree ? "bad" : fixture.tree } }); if (value.pathname.endsWith("/git/ref/heads/main")) return response({ object: { sha: fixture.candidate } }); if (value.pathname.includes("/git/ref/tags/")) return state.tag ? response(state.tag) : response(null, 404); if (value.pathname.includes("/releases/tags/")) return state.release ? response(state.release) : response(null, 404); if (value.pathname.endsWith("/releases") && method === "POST") { state.tag = { object: { type: "commit", sha: fixture.candidate } }; const body = JSON.parse(init.body); state.release = { ...body, id: 99, immutable: true, assets: [] }; return response(state.release, 201); } if (value.pathname.endsWith("/labels") && method === "POST") { if (options.failLabel) { options.failLabel = false; return response({ message: "lost acknowledgement" }, 500); } state.labels = ["autorelease: tagged"]; return response(null, 204); } if (value.pathname.includes("/labels/autorelease%3A%20pending") && method === "DELETE") { state.labels = ["autorelease: tagged"]; return response(null, 204); } if (value.pathname.endsWith("/pulls/7")) return response(pr); throw new Error(`unexpected ${method} ${value.pathname}`); }; return { calls, fetch, state }; }
function publisherEnvironment(fixture, fetch) { const names = ["GITHUB_REPOSITORY", "GITHUB_EVENT_PATH", "GITHUB_TOKEN", "RAN_RELEASE_PUBLISHER_MUTATE", "RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID"]; assert.ok(!names.includes("fetch")); const before = Object.fromEntries(names.map((name) => [name, process.env[name]])); const previousFetch = globalThis.fetch; process.env.GITHUB_REPOSITORY = REPOSITORY; process.env.GITHUB_EVENT_PATH = fixture.eventPath; process.env.GITHUB_TOKEN = "test"; process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1"; process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = String(ID); globalThis.fetch = fetch; return () => { for (const name of names) { if (before[name] === undefined) delete process.env[name]; else process.env[name] = before[name]; } globalThis.fetch = previousFetch; rmSync(fixture.root, { recursive: true, force: true }); }; }

test("runPublisher uses a temporary Git merge and exact mocked publication sequence", async (context) => { const fixture = publisherFixture(); const mocked = transport(fixture); context.after(publisherEnvironment(fixture, mocked.fetch)); const result = await runPublisher(fixture.root); assert.equal(result.action, "create_release"); assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1); const release = mocked.calls.findIndex((call) => call.method === "POST" && call.path.endsWith("/releases")); const label = mocked.calls.findIndex((call) => call.method !== "GET" && call.path.includes("/labels")); assert.ok(mocked.calls.findIndex((call, index) => index > release && call.path.includes("/releases/tags/")) < label); assert.ok(mocked.calls.some((call) => call.path.endsWith("/pulls/7"))); assert.equal((await runPublisher(fixture.root)).action, "already_published"); assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1); });

test("runPublisher ordinary, malformed, and disabled mutation paths never write", async () => { for (const options of [{ ordinary: true }, { badTree: true }, {}]) { const fixture = publisherFixture(); if (options.ordinary) { writeFileSync(join(fixture.root, "ordinary.txt"), "ordinary\n"); git(fixture.root, ["add", "ordinary.txt"]); git(fixture.root, ["commit", "-m", "fix: ordinary"]); fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]); fixture.tree = git(fixture.root, ["show", "-s", "--format=%T", "HEAD"]); writeFileSync(fixture.eventPath, JSON.stringify({ repository: { id: ID }, workflow_run: { event: "push", conclusion: "success", head_branch: "main", head_sha: fixture.candidate, head_repository: { id: ID, full_name: REPOSITORY } } })); } const mocked = transport(fixture, options); const restore = publisherEnvironment(fixture, mocked.fetch); if (!options.ordinary && !options.badTree) delete process.env.RAN_RELEASE_PUBLISHER_MUTATE; try { if (options.ordinary) assert.equal((await runPublisher(fixture.root)).action, "none"); else await assert.rejects(runPublisher(fixture.root)); assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0); if (options.ordinary) assert.equal(mocked.calls.filter((call) => call.path.includes("/git/commits/")).length, 0); } finally { restore(); } } });

test("missing or mismatched immutable acknowledgement refuses before any write", async () => { for (const acknowledgement of [undefined, String(ID + 1)]) { const fixture = publisherFixture(); const mocked = transport(fixture); const restore = publisherEnvironment(fixture, mocked.fetch); if (acknowledgement === undefined) delete process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID; else process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = acknowledgement; try { await assert.rejects(runPublisher(fixture.root), (error) => error.code === "immutable_releases_disabled"); assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0); } finally { restore(); } } });

test("post-create pending-label interruption reconciles without a second release", async (context) => { const fixture = publisherFixture(); const mocked = transport(fixture, { failLabel: true }); context.after(publisherEnvironment(fixture, mocked.fetch)); await assert.rejects(runPublisher(fixture.root)); assert.equal(mocked.state.labels[0], "autorelease: pending"); const result = await runPublisher(fixture.root); assert.equal(result.action, "reconcile_labels"); assert.equal(mocked.state.labels[0], "autorelease: tagged"); assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1); });

test("complete unreleased root commit is ordinary and makes no writes", async (context) => {
  const fixture = publisherFixture(true);
  const mocked = transport(fixture, { ordinary: true });
  context.after(publisherEnvironment(fixture, mocked.fetch));
  assert.deepEqual(await runPublisher(fixture.root), { action: "none", reason: "ordinary_main" });
  assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
});

test("initial release must be exactly beta.1 and later releases must advance", () => {
  const parent = { ...contents("0.0.0"), changelog: "# Changelog\n\n## [Unreleased]\n\nBootstrap\n" };
  for (const version of ["0.1.0-beta.0", "0.1.0-beta.2"]) {
    refusal("release_version_not_advanced", () => verifyReleaseDelta(parent, contents(version)));
  }
  refusal("release_version_not_advanced", () => verifyReleaseDelta(contents(), contents()));
  refusal("release_version_not_advanced", () => verifyReleaseDelta(contents("0.1.0-beta.2"), contents()));
});

test("wrong CI identity fails without writes", async () => {
  for (const changes of [{ event: "workflow_dispatch" }, { conclusion: "failure" }, { head_branch: "other" }, { head_repository: { id: ID + 1, full_name: REPOSITORY } }, { head_repository: { id: ID, full_name: "other/repo" } }]) {
    const fixture = publisherFixture(); writeEvent(fixture, changes);
    const mocked = transport(fixture); const restore = publisherEnvironment(fixture, mocked.fetch);
    try {
      await assert.rejects(runPublisher(fixture.root), (error) => error.code === "quality_identity_invalid");
      assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
    } finally { restore(); }
  }
});

test("root commit with released metadata cannot publish", async (context) => {
  const fixture = publisherFixture(true);
  writeFileSync(join(fixture.root, ".release-please-manifest.json"), contents().manifest);
  writeFileSync(join(fixture.root, "CHANGELOG.md"), contents().changelog);
  git(fixture.root, ["add", ".release-please-manifest.json", "CHANGELOG.md"]);
  git(fixture.root, ["commit", "--amend", "--no-edit"]);
  fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]); writeEvent(fixture);
  const mocked = transport(fixture, { ordinary: true });
  context.after(publisherEnvironment(fixture, mocked.fetch));
  await assert.rejects(runPublisher(fixture.root), (error) => error.code === "release_content_drift");
  assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
});

function publicationInput() {
  return {
    event: { event: "push", conclusion: "success", head_branch: "main", head_sha: SHA, head_repository: { full_name: REPOSITORY, id: ID } },
    candidateSha: SHA, mainSha: SHA, identity: candidateIdentity(contents(), SHA),
    pulls: [pull()], repository: REPOSITORY, repositoryId: ID,
    tagRef: null, release: null, immutableReleasesEnabled: true,
    commit: { sha: SHA, parents: [{ sha: "c".repeat(40) }, { sha: "d".repeat(40) }], tree: { sha: "e".repeat(40) }, parentVersion: "0.0.0", changedPaths: [".release-please-manifest.json", "CHANGELOG.md"] },
  };
}

test("squash, rebase, extra parents, reversed parents and merge tree drift refuse publication", () => {
  const input = publicationInput();
  for (const commit of [
    { ...input.commit, parents: [input.commit.parents[0]] },
    { ...input.commit, parents: [] },
    { ...input.commit, parents: [...input.commit.parents, { sha: "f".repeat(40) }] },
    { ...input.commit, parents: input.commit.parents.toReversed() },
    { ...input.commit, tree: { sha: "f".repeat(40) } },
  ]) refusal("release_pr_not_normal_merge", () => decidePublication({ ...input, commit }));
});

test("fork, wrong bot, title or branch and ambiguous release associations refuse", () => {
  const input = publicationInput(); const pr = input.pulls[0];
  for (const candidate of [
    { ...pr, head: { ...pr.head, repo: { ...pr.head.repo, id: ID + 1 } } },
    { ...pr, base: { ...pr.base, repo: { ...pr.base.repo, full_name: "other/repo" } } },
    { ...pr, user: { login: "someone" } },
    { ...pr, title: "chore: release" },
    { ...pr, head: { ...pr.head, ref: "another-branch" } },
  ]) refusal("release_pr_invalid", () => decidePublication({ ...input, pulls: [candidate] }));
  refusal("release_pr_ambiguous", () => decidePublication({ ...input, pulls: [pr, pr] }));
  refusal("release_pr_ambiguous", () => decidePublication({ ...input, pulls: [pr, { ...pr, merge_commit_sha: "f".repeat(40) }] }));
});

test("partial publication and incompatible lifecycle labels fail closed", () => {
  const input = publicationInput();
  refusal("partial_publication_state", () => decidePublication({ ...input, tagRef: { object: { type: "commit", sha: SHA } } }));
  refusal("release_without_tag", () => decidePublication({ ...input, release: { id: 1 } }));
  for (const labels of [[], [{ name: "autorelease: tagged" }], [{ name: "autorelease: tagged" }, { name: "autorelease: pending" }]]) {
    refusal("release_pr_label_conflict", () => decidePublication({ ...input, pulls: [{ ...pull(), labels }] }));
  }
  for (const version of ["0.1.0-beta.0", "0.1.0-beta.2", "1.0.0"]) {
    const identity = { ...input.identity, version };
    refusal("release_version_invalid", () => decidePublication({ ...input, identity, pulls: [{ ...pull(), title: `chore(main): release ${version}` }] }));
  }
});

test("partial parent metadata and executable candidate metadata refuse before network writes", async () => {
  for (const change of ["partial", "executable"]) {
    const fixture = publisherFixture(true);
    if (change === "partial") git(fixture.root, ["rm", "CHANGELOG.md"]);
    else git(fixture.root, ["update-index", "--chmod=+x", ".release-please-manifest.json"]);
    git(fixture.root, ["commit", "--amend", "--no-edit"]);
    if (change === "partial") {
      writeFileSync(join(fixture.root, "CHANGELOG.md"), "# Changelog\n\n## [Unreleased]\n\nBootstrap\n");
      git(fixture.root, ["add", "CHANGELOG.md"]); git(fixture.root, ["commit", "-m", "chore: setup"]);
    }
    fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]); writeEvent(fixture);
    const mocked = transport(fixture, { ordinary: true }); const restore = publisherEnvironment(fixture, mocked.fetch);
    try {
      await assert.rejects(runPublisher(fixture.root), (error) => error.code === "release_content_drift");
      assert.equal(mocked.calls.length, 0);
    } finally { restore(); }
  }
});
