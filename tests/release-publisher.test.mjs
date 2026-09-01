import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import { PublisherRefusal, candidateIdentity, classifyParentReleaseMetadata, decidePublication, hydrateExactReleasePullTree, runPublisher, verifyReleaseDelta, verifyPublishedState } from "../scripts/release-publisher.mjs";

const SHA = "a".repeat(40);
const REPOSITORY = "RocketsAreNostalgic/ran-wp-release-updater";
const ID = 42;
const VERSION = "0.1.0-beta.1";
const REVISION = "b".repeat(64);

function contents(version = VERSION) {
  return {
    manifest: JSON.stringify({ ".": version }),
    runtimeCopy: JSON.stringify({ package_revision: REVISION, package_version: version, php_floor: "8.2.0", runtime_file: "runtime.php", runtime_protocol: 1, wordpress_floor: "6.5.0" }),
    composer: JSON.stringify({ name: "ran/wp-release-updater", type: "library" }),
    changelog: `# Changelog\n\n## ${version} (2026-09-01)\n\n### Features\n\n* first release\n\n# prior\n`,
  };
}
function refusal(code, callback) { assert.throws(callback, (error) => error instanceof PublisherRefusal && error.code === code); }
function pull() { return { state: "closed", merged_at: "2026-09-01T00:00:00Z", draft: false, merge_commit_sha: SHA, base: { ref: "main", sha: "c".repeat(40), repo: { id: ID, full_name: REPOSITORY } }, head: { ref: "release-please--branches--main--components--ran/wp-release-updater", sha: "d".repeat(40), repo: { id: ID, full_name: REPOSITORY } }, head_tree_sha: "e".repeat(40), user: { login: "github-actions[bot]" }, title: `chore(main): release ${VERSION}`, number: 7, labels: [{ name: "autorelease: pending" }] }; }

test("candidate binds manifest, runtime-copy and release notes", () => {
  const identity = candidateIdentity(contents(), SHA);
  assert.equal(identity.version, VERSION);
  assert.equal(identity.tag, `v${VERSION}`);
  refusal("version_source_drift", () => candidateIdentity({ ...contents(), runtimeCopy: JSON.stringify({ package_revision: REVISION, package_version: "0.1.0-beta.2" }) }, SHA));
});

test("candidate accepts dated linked headings only on the independent beta line", () => {
  const next = "0.1.0-beta.2";
  const linked = {
    ...contents(next),
    changelog: `# Changelog\n\n## [${next}](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/compare/v${VERSION}...v${next}) (2026-09-02)\n\n### Bug Fixes\n\n* second release\n`,
  };
  assert.equal(candidateIdentity(linked, SHA).version, next);
  for (const version of ["0.2.0-beta.1", "1.0.0-beta.1"]) {
    refusal("release_manifest_invalid", () => candidateIdentity(contents(version), SHA));
  }
});

test("release delta permits only manifest/runtime-copy version and a changelog prepend", () => {
  const parent = { ...contents("0.0.0"), changelog: "# Changelog\n\n## [Unreleased]\n\nAll notable changes.\n" };
  const candidate = { ...contents(), changelog: `# Changelog\n\n## ${VERSION} (2026-09-01)\n\n### Features\n\n* first release\n\n## [Unreleased]\n\nAll notable changes.\n` };
  assert.deepEqual(verifyReleaseDelta(parent, candidate), { parentVersion: "0.0.0", candidateVersion: VERSION });
  const next = "0.1.0-beta.2";
  const later = { ...contents(next), changelog: `# Changelog\n\n## [${next}](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/compare/v${VERSION}...v${next}) (2026-09-02)\n\n### Bug Fixes\n\n* second release\n\n${candidate.changelog.slice("# Changelog\n\n".length)}` };
  assert.deepEqual(verifyReleaseDelta(candidate, later), { parentVersion: VERSION, candidateVersion: next });
  refusal("release_content_drift", () => verifyReleaseDelta(parent, { ...candidate, runtimeCopy: candidate.runtimeCopy.replace(REVISION, "c".repeat(64)) }));
  refusal("release_content_drift", () => verifyReleaseDelta(parent, { ...candidate, manifest: JSON.stringify({ ".": VERSION }, null, 2) }));
});

test("only exact green CI normal merge and changed paths can publish", () => {
  const identity = candidateIdentity(contents(), SHA); const releasePull = pull();
  const input = { event: { event: "push", conclusion: "success", head_branch: "main", head_sha: SHA, head_repository: { full_name: REPOSITORY, id: ID } }, candidateSha: SHA, mainSha: SHA, identity, pulls: [releasePull], repository: REPOSITORY, repositoryId: ID, immutableAcknowledgement: String(ID), tagRef: null, release: null, commit: { sha: SHA, parents: [{ sha: "c".repeat(40) }, { sha: "d".repeat(40) }], tree: { sha: "e".repeat(40) }, parentVersion: "0.0.0", changedPaths: [".release-please-manifest.json", "CHANGELOG.md", "runtime-copy.json"] } };
  assert.deepEqual(decidePublication(input), { action: "create_release", pullNumber: 7 });
  refusal("release_paths_invalid", () => decidePublication({ ...input, commit: { ...input.commit, changedPaths: [...input.commit.changedPaths, "src/Runtime/RequestBroker.php"] } }));
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

test("only the exact historical runtime-copy partial parent is bootstrap metadata", () => {
  const legacy = { manifest: null, runtimeCopy: { mode: "100644", type: "blob", sha: "ec1170ddb999e78bb2f30814e3760c468763e399" }, changelog: null };
  assert.equal(classifyParentReleaseMetadata("ec9e1058939b3d699fb49eb700dded1a2caddb19", legacy), "legacy_bootstrap");
  const otherBlob = { mode: "100644", type: "blob", sha: "c".repeat(40) };
  for (const [sha, entries] of [["a".repeat(40), legacy], ["ec9e1058939b3d699fb49eb700dded1a2caddb19", { ...legacy, runtimeCopy: { ...legacy.runtimeCopy, sha: "b".repeat(40) } }], ["ec9e1058939b3d699fb49eb700dded1a2caddb19", { ...legacy, runtimeCopy: { ...legacy.runtimeCopy, mode: "100755" } }], ["ec9e1058939b3d699fb49eb700dded1a2caddb19", { ...legacy, manifest: otherBlob }], ["ec9e1058939b3d699fb49eb700dded1a2caddb19", { ...legacy, changelog: otherBlob }], ["ec9e1058939b3d699fb49eb700dded1a2caddb19", { manifest: otherBlob, runtimeCopy: null, changelog: null }], ["ec9e1058939b3d699fb49eb700dded1a2caddb19", { manifest: null, runtimeCopy: null, changelog: otherBlob }]]) refusal("release_content_drift", () => classifyParentReleaseMetadata(sha, entries));
});

test("immutable release readback rejects mutable or asset-bearing releases", () => {
  const identity = candidateIdentity(contents(), SHA);
  const tag = { object: { type: "commit", sha: SHA } };
  const release = { id: 1, tag_name: identity.tag, target_commitish: SHA, name: identity.tag, body: identity.notes, draft: false, prerelease: true, immutable: true, assets: [] };
  assert.equal(verifyPublishedState(tag, release, identity), true);
  refusal("release_state_conflict", () => verifyPublishedState(tag, { ...release, immutable: false }, identity));
});

function git(root, args) { return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim(); }
function revision(files) { return createHash("sha256").update(Object.entries(files).sort(([left], [right]) => left.localeCompare(right)).map(([file, value]) => `${file}\0${createHash("sha256").update(value).digest("hex")}\n`).join("")).digest("hex"); }
function publisherFixture() {
  const root = mkdtempSync(join(tmpdir(), "wp-release-publisher-")); git(root, ["init", "--initial-branch=main"]); git(root, ["config", "user.email", "test@example.test"]); git(root, ["config", "user.name", "Test"]);
  const source = { "bootstrap.php": "<?php\n", "runtime.php": "<?php\n", "src/Runtime/Test.php": "<?php\n" }; const write = (version, changelog) => { for (const [file, value] of Object.entries(source)) { const path = join(root, file); execFileSync("mkdir", ["-p", join(path, "..")]); writeFileSync(path, value); } writeFileSync(join(root, ".release-please-manifest.json"), JSON.stringify({ ".": version })); writeFileSync(join(root, "runtime-copy.json"), JSON.stringify({ package_revision: revision(source), package_version: version })); writeFileSync(join(root, "composer.json"), JSON.stringify({ name: "ran/wp-release-updater", type: "library" })); writeFileSync(join(root, "CHANGELOG.md"), changelog); };
  write("0.0.0", "# Changelog\n\n## [Unreleased]\n\nBootstrap\n"); git(root, ["add", "."]); git(root, ["commit", "-m", "chore: bootstrap"]); const base = git(root, ["rev-parse", "HEAD"]); git(root, ["checkout", "-b", "release-please--branches--main--components--ran/wp-release-updater"]); write(VERSION, `# Changelog\n\n## ${VERSION} (2026-09-01)\n\n### Features\n\n* release\n\n## [Unreleased]\n\nBootstrap\n`); git(root, ["add", "."]); git(root, ["commit", "-m", "chore(main): release"]); const head = git(root, ["rev-parse", "HEAD"]); git(root, ["checkout", "main"]); git(root, ["merge", "--no-ff", "--no-edit", head]); const candidate = git(root, ["rev-parse", "HEAD"]); const tree = git(root, ["show", "-s", "--format=%T", candidate]); const eventPath = join(root, "event.json"); writeFileSync(eventPath, JSON.stringify({ repository: { id: ID }, workflow_run: { event: "push", conclusion: "success", head_branch: "main", head_sha: candidate, head_repository: { id: ID, full_name: REPOSITORY } } })); return { root, base, head, candidate, tree, eventPath };
}
function transport(fixture, options = {}) { const calls = []; const state = { tag: null, release: null, labels: ["autorelease: pending"] }; const response = (data, status = 200) => new Response(data === null ? null : JSON.stringify(data), { status, headers: { link: "" } }); const fetch = async (url, init = {}) => { const value = new URL(url); const method = init.method ?? "GET"; calls.push({ path: value.pathname + value.search, method }); const pr = { ...pull(), merge_commit_sha: fixture.candidate, base: { ref: "main", sha: fixture.base, repo: { id: ID, full_name: REPOSITORY } }, head: { ref: "release-please--branches--main--components--ran/wp-release-updater", sha: fixture.head, repo: { id: ID, full_name: REPOSITORY } }, labels: state.labels.map((name) => ({ name })) }; if (value.pathname.endsWith(`/commits/${fixture.candidate}/pulls`)) return response(options.ordinary ? [] : [pr]); if (value.pathname.endsWith(`/git/commits/${fixture.head}`)) return response({ sha: fixture.head, tree: { sha: options.badTree ? "bad" : fixture.tree } }); if (value.pathname.endsWith("/git/ref/heads/main")) return response({ object: { sha: fixture.candidate } }); if (value.pathname.includes("/git/ref/tags/")) return state.tag ? response(state.tag) : response(null, 404); if (value.pathname.includes("/releases/tags/")) return state.release ? response(state.release) : response(null, 404); if (value.pathname.endsWith("/releases") && method === "POST") { state.tag = { object: { type: "commit", sha: fixture.candidate } }; const body = JSON.parse(init.body); state.release = { ...body, id: 99, immutable: true, assets: [] }; return response(state.release, 201); } if (value.pathname.endsWith("/labels") && method === "POST") { if (options.failLabel) { options.failLabel = false; return response({ message: "lost acknowledgement" }, 500); } state.labels = ["autorelease: tagged"]; return response(null, 204); } if (value.pathname.includes("/labels/autorelease%3A%20pending") && method === "DELETE") { state.labels = ["autorelease: tagged"]; return response(null, 204); } if (value.pathname.endsWith("/pulls/7")) return response(pr); throw new Error(`unexpected ${method} ${value.pathname}`); }; return { calls, fetch, state }; }
function publisherEnvironment(fixture, fetch) { const names = ["GITHUB_REPOSITORY", "GITHUB_EVENT_PATH", "GITHUB_TOKEN", "RAN_RELEASE_PUBLISHER_MUTATE", "RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID"]; assert.ok(!names.includes("fetch")); const before = Object.fromEntries(names.map((name) => [name, process.env[name]])); const previousFetch = globalThis.fetch; process.env.GITHUB_REPOSITORY = REPOSITORY; process.env.GITHUB_EVENT_PATH = fixture.eventPath; process.env.GITHUB_TOKEN = "test"; process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1"; process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = String(ID); globalThis.fetch = fetch; return () => { for (const name of names) { if (before[name] === undefined) delete process.env[name]; else process.env[name] = before[name]; } globalThis.fetch = previousFetch; rmSync(fixture.root, { recursive: true, force: true }); }; }

test("runPublisher uses a temporary Git merge and exact mocked publication sequence", async (context) => { const fixture = publisherFixture(); const mocked = transport(fixture); context.after(publisherEnvironment(fixture, mocked.fetch)); const result = await runPublisher(fixture.root); assert.equal(result.action, "create_release"); assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1); const release = mocked.calls.findIndex((call) => call.method === "POST" && call.path.endsWith("/releases")); const label = mocked.calls.findIndex((call) => call.method !== "GET" && call.path.includes("/labels")); assert.ok(mocked.calls.findIndex((call, index) => index > release && call.path.includes("/releases/tags/")) < label); assert.ok(mocked.calls.some((call) => call.path.endsWith("/pulls/7"))); assert.equal((await runPublisher(fixture.root)).action, "already_published"); assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1); });

test("runPublisher ordinary, malformed, and disabled mutation paths never write", async () => { for (const options of [{ ordinary: true }, { badTree: true }, {}]) { const fixture = publisherFixture(); if (options.ordinary) { writeFileSync(join(fixture.root, "ordinary.txt"), "ordinary\n"); git(fixture.root, ["add", "ordinary.txt"]); git(fixture.root, ["commit", "-m", "fix: ordinary"]); fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]); fixture.tree = git(fixture.root, ["show", "-s", "--format=%T", "HEAD"]); writeFileSync(fixture.eventPath, JSON.stringify({ repository: { id: ID }, workflow_run: { event: "push", conclusion: "success", head_branch: "main", head_sha: fixture.candidate, head_repository: { id: ID, full_name: REPOSITORY } } })); } const mocked = transport(fixture, options); const restore = publisherEnvironment(fixture, mocked.fetch); if (!options.ordinary && !options.badTree) delete process.env.RAN_RELEASE_PUBLISHER_MUTATE; try { if (options.ordinary) assert.equal((await runPublisher(fixture.root)).action, "none"); else await assert.rejects(runPublisher(fixture.root)); assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0); if (options.ordinary) assert.equal(mocked.calls.filter((call) => call.path.includes("/git/commits/")).length, 0); } finally { restore(); } } });

test("missing or mismatched immutable acknowledgement refuses before any write", async () => { for (const acknowledgement of [undefined, String(ID + 1)]) { const fixture = publisherFixture(); const mocked = transport(fixture); const restore = publisherEnvironment(fixture, mocked.fetch); if (acknowledgement === undefined) delete process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID; else process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = acknowledgement; try { await assert.rejects(runPublisher(fixture.root), (error) => error.code === "immutable_releases_disabled"); assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0); } finally { restore(); } } });

test("post-create pending-label interruption reconciles without a second release", async (context) => { const fixture = publisherFixture(); const mocked = transport(fixture, { failLabel: true }); context.after(publisherEnvironment(fixture, mocked.fetch)); await assert.rejects(runPublisher(fixture.root)); assert.equal(mocked.state.labels[0], "autorelease: pending"); const result = await runPublisher(fixture.root); assert.equal(result.action, "reconcile_labels"); assert.equal(mocked.state.labels[0], "autorelease: tagged"); assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1); });

test("first release setup merge has no parent metadata and remains ordinary", async (context) => { const fixture = publisherFixture(); git(fixture.root, ["checkout", fixture.base]); git(fixture.root, ["rm", ".release-please-manifest.json", "runtime-copy.json", "CHANGELOG.md"]); git(fixture.root, ["commit", "-m", "chore: pre-release setup parent"]); const bare = git(fixture.root, ["rev-parse", "HEAD"]); git(fixture.root, ["checkout", "-B", "release-please--branches--main--components--ran/wp-release-updater", bare]); writeFileSync(join(fixture.root, ".release-please-manifest.json"), JSON.stringify({ ".": "0.0.0" })); writeFileSync(join(fixture.root, "runtime-copy.json"), JSON.stringify({ package_revision: revision({ "bootstrap.php": "<?php\n", "runtime.php": "<?php\n", "src/Runtime/Test.php": "<?php\n" }), package_version: "0.0.0" })); writeFileSync(join(fixture.root, "CHANGELOG.md"), "# Changelog\n\n## [Unreleased]\n\nBootstrap\n"); git(fixture.root, ["add", ".release-please-manifest.json", "runtime-copy.json", "CHANGELOG.md"]); git(fixture.root, ["commit", "-m", "chore: release setup"]); fixture.head = git(fixture.root, ["rev-parse", "HEAD"]); git(fixture.root, ["checkout", "main"]); git(fixture.root, ["reset", "--hard", bare]); git(fixture.root, ["merge", "--no-ff", "--no-commit", fixture.head]); git(fixture.root, ["commit", "-m", "Merge release setup"]); fixture.base = bare; fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]); fixture.tree = git(fixture.root, ["show", "-s", "--format=%T", "HEAD"]); writeFileSync(fixture.eventPath, JSON.stringify({ repository: { id: ID }, workflow_run: { event: "push", conclusion: "success", head_branch: "main", head_sha: fixture.candidate, head_repository: { id: ID, full_name: REPOSITORY } } })); const mocked = transport(fixture, { ordinary: true }); context.after(publisherEnvironment(fixture, mocked.fetch)); assert.deepEqual(await runPublisher(fixture.root), { action: "none", reason: "ordinary_main" }); assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0); assert.equal(mocked.calls.filter((call) => call.path.includes("/git/commits/")).length, 0); });

test("arbitrary runtime-copy-only partial parent rejects without writes", async (context) => { const fixture = publisherFixture(); git(fixture.root, ["checkout", fixture.base]); git(fixture.root, ["rm", ".release-please-manifest.json", "CHANGELOG.md"]); git(fixture.root, ["commit", "-m", "chore: partial parent"]); const bare = git(fixture.root, ["rev-parse", "HEAD"]); git(fixture.root, ["checkout", "-B", "release-please--branches--main--components--ran/wp-release-updater", bare]); writeFileSync(join(fixture.root, ".release-please-manifest.json"), JSON.stringify({ ".": "0.0.0" })); writeFileSync(join(fixture.root, "CHANGELOG.md"), "# Changelog\n\n## [Unreleased]\n\nBootstrap\n"); git(fixture.root, ["add", ".release-please-manifest.json", "CHANGELOG.md"]); git(fixture.root, ["commit", "-m", "chore: release setup"]); fixture.head = git(fixture.root, ["rev-parse", "HEAD"]); git(fixture.root, ["checkout", "main"]); git(fixture.root, ["reset", "--hard", bare]); git(fixture.root, ["merge", "--no-ff", "--no-commit", fixture.head]); git(fixture.root, ["commit", "-m", "Merge release setup"]); fixture.base = bare; fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]); fixture.tree = git(fixture.root, ["show", "-s", "--format=%T", "HEAD"]); writeFileSync(fixture.eventPath, JSON.stringify({ repository: { id: ID }, workflow_run: { event: "push", conclusion: "success", head_branch: "main", head_sha: fixture.candidate, head_repository: { id: ID, full_name: REPOSITORY } } })); const mocked = transport(fixture, { ordinary: true }); context.after(publisherEnvironment(fixture, mocked.fetch)); await assert.rejects(runPublisher(fixture.root), (error) => error.code === "release_content_drift"); assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0); });

test("released metadata cannot be downgraded to unreleased or use executable blobs", () => { const identity = { candidateSha: SHA, version: "0.0.0" }; refusal("unrecognized_release_commit", () => decidePublication({ event: { event: "push", conclusion: "success", head_branch: "main", head_sha: SHA, head_repository: { full_name: REPOSITORY, id: ID } }, candidateSha: SHA, mainSha: SHA, identity, pulls: [], repository: REPOSITORY, repositoryId: ID, commit: { parentVersion: VERSION } })); });
