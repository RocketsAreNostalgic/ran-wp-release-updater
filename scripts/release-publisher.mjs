#!/usr/bin/env node
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { PublisherRefusal, candidateIdentity, manifestVersion, refuse, runtimeCopy, verifyReleaseDelta } from "./release-publisher-content.mjs";

export { PublisherRefusal, candidateIdentity, verifyReleaseDelta };
const FULL_SHA = /^[a-f0-9]{40}$/;
const REPOSITORY = "RocketsAreNostalgic/ran-wp-release-updater";
const RELEASE_BRANCH = "release-please--branches--main--components--ran/wp-release-updater";
const RELEASE_PATHS = [".release-please-manifest.json", "CHANGELOG.md", "runtime-copy.json"];
const PENDING_LABEL = "autorelease: pending"; const TAGGED_LABEL = "autorelease: tagged"; const BOT_LOGIN = "github-actions[bot]"; const API_VERSION = "2022-11-28"; const IMMUTABLE_RELEASES_API_VERSION = "2026-03-10";

function git(root, args) { return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim(); }
function blob(root, sha, file) { const entry = git(root, ["ls-tree", sha, "--", file]); if (!/^100644 blob [a-f0-9]{40}\t/.test(entry)) refuse("release_content_drift", `${file} must be an ordinary non-executable Git blob`); return execFileSync("git", ["show", `${sha}:${file}`], { cwd: root, encoding: "utf8" }); }
function contents(root, sha) { return { manifest: blob(root, sha, ".release-please-manifest.json"), runtimeCopy: blob(root, sha, "runtime-copy.json"), composer: blob(root, sha, "composer.json"), changelog: blob(root, sha, "CHANGELOG.md") }; }
function parentContents(root, sha) { const files = [".release-please-manifest.json", "runtime-copy.json", "CHANGELOG.md"]; const present = files.filter((file) => git(root, ["ls-tree", sha, "--", file])); if (!present.length) return null; if (present.length !== files.length) refuse("release_content_drift", "parent release metadata is incomplete"); return contents(root, sha); }

export function revisionAt(root, sha) {
  const files = git(root, ["ls-tree", "-r", "--name-only", sha]).split("\n").filter((file) => file === "bootstrap.php" || file === "runtime.php" || /^src\/.+\.php$/.test(file)).sort();
  if (files.length < 3) refuse("runtime_copy_invalid", "runtime identity source family is incomplete");
  const payload = files.map((file) => `${file}\0${createHash("sha256").update(blob(root, sha, file)).digest("hex")}\n`).join("");
  return createHash("sha256").update(payload).digest("hex");
}

export function validateCandidate(root, sha) {
  const identity = candidateIdentity(contents(root, sha), sha);
  if (identity.packageRevision !== revisionAt(root, sha)) refuse("runtime_revision_drift", "runtime-copy package_revision does not hash bootstrap.php, runtime.php and src PHP bytes");
  return identity;
}

function labels(pull) { return Array.isArray(pull?.labels) ? pull.labels.map((label) => typeof label === "string" ? label : label?.name) : []; }
function shaped(pull) { return pull?.head?.ref === RELEASE_BRANCH || labels(pull).includes(PENDING_LABEL) || labels(pull).includes(TAGGED_LABEL); }
function exactPulls(pulls, candidateSha) { const releasePulls = pulls.filter(shaped); return { exact: releasePulls.filter((pull) => pull?.state === "closed" && typeof pull?.merged_at === "string" && pull?.merge_commit_sha === candidateSha), stale: releasePulls.filter((pull) => pull?.state === "closed" && typeof pull?.merged_at === "string" && pull?.merge_commit_sha !== candidateSha) }; }
export async function hydrateExactReleasePullTree(repository, candidateSha, pulls, request = api) { const { exact, stale } = exactPulls(pulls, candidateSha); if (exact.length !== 1 || stale.length) return pulls; const pull = exact[0]; if (!FULL_SHA.test(pull?.head?.sha ?? "")) refuse("release_pr_invalid", "Release Please head identity is invalid"); const response = await request(`/repos/${repository}/git/commits/${pull.head.sha}`); const headCommit = response?.data ?? response; if (headCommit?.sha !== pull.head.sha || !FULL_SHA.test(headCommit?.tree?.sha ?? "")) refuse("release_pr_head_tree_invalid", "Release Please head tree readback is invalid"); return pulls.map((value) => value === pull ? { ...value, head_tree_sha: headCommit.tree.sha } : value); }
export function decidePublication(input) {
  const { event, candidateSha, mainSha, identity, pulls, commit, repository, repositoryId } = input;
  if (identity?.candidateSha !== candidateSha) refuse("candidate_identity_drift", "checked-out candidate identity differs from CI");
  if (event?.event !== "push" || event?.conclusion !== "success" || event?.head_branch !== "main" || event?.head_sha !== candidateSha || !Number.isInteger(repositoryId) || event?.head_repository?.id !== repositoryId || event?.head_repository?.full_name !== repository) refuse("quality_identity_invalid", "publisher requires the exact successful same-repository main CI candidate");
  if (mainSha !== candidateSha) refuse("main_moved", "main no longer points at the successful candidate");
  const { exact, stale } = exactPulls(pulls, candidateSha);
  if (!exact.length && !stale.length) { if (commit?.parentVersion !== identity.version) refuse("unrecognized_release_commit", "manifest changed without an exact Release Please merge"); return { action: "none", reason: "ordinary_main" }; }
  if (exact.length !== 1 || stale.length) refuse("release_pr_ambiguous", "candidate has no single exact Release Please merge");
  const pull = exact[0];
  if (pull?.state !== "closed" || typeof pull?.merged_at !== "string" || pull?.draft !== false || pull?.head?.ref !== RELEASE_BRANCH || pull?.head?.repo?.id !== repositoryId || pull?.head?.repo?.full_name !== repository || pull?.base?.ref !== "main" || !FULL_SHA.test(pull?.base?.sha ?? "") || pull?.base?.repo?.id !== repositoryId || pull?.base?.repo?.full_name !== repository || pull?.user?.login !== BOT_LOGIN || pull?.title !== `chore(main): release ${identity.version}` || !Number.isInteger(pull?.number) || pull.number < 1 || !FULL_SHA.test(pull?.head?.sha ?? "") || pull.head.sha === candidateSha) refuse("release_pr_invalid", "release PR identity is invalid");
  if (commit?.sha !== candidateSha || commit.parents?.length !== 2 || commit.parents[0]?.sha !== pull.base.sha || commit.parents[1]?.sha !== pull.head.sha || commit.tree?.sha !== pull.head_tree_sha) refuse("release_pr_not_normal_merge", "candidate must be the normal two-parent merge of the exact Release Please head");
  if (typeof commit.parentVersion !== "string" || (commit.parentVersion !== "0.0.0" && !/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-beta\.(0|[1-9][0-9]*)$/.test(commit.parentVersion))) refuse("release_parent_version_invalid", "release parent version is invalid");
  if (!Array.isArray(commit.changedPaths) || JSON.stringify(commit.changedPaths) !== JSON.stringify(RELEASE_PATHS)) refuse("release_paths_invalid", "release changed paths are not exact");
  if (commit.parentVersion === identity.version) refuse("release_version_unchanged", "release did not advance the manifest");
  const pending = labels(pull).includes(PENDING_LABEL); const tagged = labels(pull).includes(TAGGED_LABEL);
  if (input.release !== null && input.tagRef === null) refuse("release_without_tag", "release exists without its tag");
  if (input.release !== null) { verifyPublishedState(input.tagRef, input.release, identity); if (!pending && !tagged) refuse("release_pr_label_conflict", "published candidate has no lifecycle label"); return { action: tagged ? "already_published" : "reconcile_labels", pullNumber: pull.number }; }
  if (!pending || tagged) refuse("release_pr_label_conflict", "unpublished candidate must have only pending label");
  if (input.tagRef !== null) refuse("partial_publication_state", "tag exists without release");
  if (input.immutableReleasesEnabled === false) refuse("immutable_releases_disabled", "immutable-release acknowledgement is missing or mismatched");
  return { action: "create_release", pullNumber: pull.number };
}
export function verifyPublishedState(tagRef, release, identity) {
  if (tagRef?.object?.type !== "commit" || tagRef.object.sha !== identity.candidateSha || release?.tag_name !== identity.tag || release?.target_commitish !== identity.candidateSha || release?.name !== identity.tag || release?.body !== identity.notes || release?.draft !== false || release?.prerelease !== true || release?.immutable !== true || !Number.isInteger(release?.id) || release.id < 1) refuse("release_state_conflict", "tag or immutable release readback is not exact");
  if (!Array.isArray(release.assets) || release.assets.length) refuse("release_asset_conflict", "release must have no assets");
  return true;
}
async function api(path, options = {}) { if (!process.env.GITHUB_TOKEN) refuse("token_missing", "GITHUB_TOKEN is required"); const response = await fetch(`https://api.github.com${path}`, { method: options.method ?? "GET", headers: { Accept: "application/vnd.github+json", Authorization: `Bearer ${process.env.GITHUB_TOKEN}`, "User-Agent": "ran-wp-release-updater-exact-publisher", "X-GitHub-Api-Version": options.apiVersion ?? API_VERSION }, body: options.body === undefined ? undefined : JSON.stringify(options.body), redirect: "error" }); if (options.allow404 && response.status === 404) return { data: null, headers: response.headers }; if (!response.ok) refuse("github_api_failed", `${options.method ?? "GET"} ${path} returned ${response.status}`); return { data: response.status === 204 ? null : await response.json(), headers: response.headers }; }
async function associatedPulls(repository, sha) { const pulls = []; for (let page = 1; page <= 10; page += 1) { const response = await api(`/repos/${repository}/commits/${sha}/pulls?per_page=100&page=${page}`); if (!Array.isArray(response.data)) refuse("pull_readback_invalid", "commit pull request response is not a list"); pulls.push(...response.data); if (!/<[^>]+>;\s*rel="next"/.test(response.headers.get("link") ?? "")) return pulls; } refuse("pull_readback_unbounded", "commit pull request response exceeded ten pages"); }
async function remoteState(repository, tag) { const encoded = encodeURIComponent(tag); const [tagRef, release] = await Promise.all([api(`/repos/${repository}/git/ref/tags/${encoded}`, { allow404: true }), api(`/repos/${repository}/releases/tags/${encoded}`, { allow404: true, apiVersion: IMMUTABLE_RELEASES_API_VERSION })]); return { tagRef: tagRef.data, release: release.data }; }
async function reconcileLabels(repository, number, value) { if (!value.includes(TAGGED_LABEL)) await api(`/repos/${repository}/issues/${number}/labels`, { method: "POST", body: { labels: [TAGGED_LABEL] } }); if (value.includes(PENDING_LABEL)) await api(`/repos/${repository}/issues/${number}/labels/${encodeURIComponent(PENDING_LABEL)}`, { method: "DELETE", allow404: true }); }
export async function runPublisher(root = process.cwd()) {
  const eventPath = process.env.GITHUB_EVENT_PATH; const repository = process.env.GITHUB_REPOSITORY;
  if (repository !== REPOSITORY || !eventPath || !process.env.GITHUB_TOKEN) refuse("environment_invalid", "publisher environment is incomplete");
  const payload = JSON.parse(readFileSync(eventPath, "utf8")); const event = payload.workflow_run; const sha = event?.head_sha; if (!FULL_SHA.test(sha ?? "") || git(root, ["rev-parse", "HEAD"]) !== sha) refuse("checkout_drift", "checkout is not the CI candidate");
  const candidateContents = contents(root, sha); const parent = git(root, ["rev-parse", `${sha}^`]); const parentState = parentContents(root, parent);
  const unreleased = manifestVersion(candidateContents.manifest, "candidate", true) === "0.0.0" && runtimeCopy(candidateContents.runtimeCopy).package_version === "0.0.0";
  const identity = unreleased ? { candidateSha: sha, version: "0.0.0", tag: "v0.0.0", notes: "unreleased bootstrap", packageRevision: runtimeCopy(candidateContents.runtimeCopy).package_revision } : validateCandidate(root, sha);
  if (unreleased && identity.packageRevision !== revisionAt(root, sha)) refuse("runtime_revision_drift", "runtime-copy package_revision does not hash bootstrap.php, runtime.php and src PHP bytes");
  const parentVersion = parentState ? manifestVersion(parentState.manifest, "parent", true) : "0.0.0";
  if (unreleased && parentState && parentVersion !== "0.0.0") refuse("release_version_regression", "released beta metadata may not return to 0.0.0");
  const unchangedVersion = parentState?.manifest === candidateContents.manifest;
  const delta = !parentState || unreleased ? { parentVersion } : unchangedVersion ? { parentVersion: identity.version } : verifyReleaseDelta(parentState, candidateContents);
  const [main, pulls, state] = await Promise.all([api(`/repos/${repository}/git/ref/heads/main`), associatedPulls(repository, sha), remoteState(repository, identity.tag)]);
  const hydratedPulls = await hydrateExactReleasePullTree(repository, sha, pulls);
  const changedPaths = git(root, ["diff", "--name-only", `${sha}^`, sha]).split("\n").filter(Boolean).sort(); const parents = git(root, ["show", "-s", "--format=%P", sha]).split(" ").filter(Boolean).map((value) => ({ sha: value }));
  let input = { event, candidateSha: sha, mainSha: main.data?.object?.sha, identity, pulls: hydratedPulls, commit: { sha, parents, changedPaths, parentVersion: delta.parentVersion, tree: { sha: git(root, ["show", "-s", "--format=%T", sha]) } }, repository, repositoryId: payload.repository?.id, tagRef: state.tagRef, release: state.release };
  let result = decidePublication(input);
  if (result.action === "none") return result;
  if (process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") refuse("mutation_disabled", "publisher mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1");
  const [freshMain, freshPulls, freshState] = await Promise.all([api(`/repos/${repository}/git/ref/heads/main`), associatedPulls(repository, sha), remoteState(repository, identity.tag)]);
  input = { ...input, mainSha: freshMain.data?.object?.sha, pulls: await hydrateExactReleasePullTree(repository, sha, freshPulls), tagRef: freshState.tagRef, release: freshState.release, immutableReleasesEnabled: freshState.release === null ? process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID === String(payload.repository?.id) : undefined };
  result = decidePublication(input);
  if (result.action === "create_release") await api(`/repos/${repository}/releases`, { method: "POST", apiVersion: IMMUTABLE_RELEASES_API_VERSION, body: { tag_name: identity.tag, target_commitish: sha, name: identity.tag, body: identity.notes, draft: false, prerelease: true, generate_release_notes: false } });
  const checked = await remoteState(repository, identity.tag); verifyPublishedState(checked.tagRef, checked.release, identity);
  const original = input.pulls.find((pull) => pull.number === result.pullNumber); await reconcileLabels(repository, result.pullNumber, labels(original));
  const finalPull = (await api(`/repos/${repository}/pulls/${result.pullNumber}`)).data;
  const finalWithTree = (await hydrateExactReleasePullTree(repository, sha, [finalPull]))[0];
  decidePublication({ ...input, pulls: [finalWithTree], tagRef: checked.tagRef, release: checked.release });
  if (!labels(finalPull).includes(TAGGED_LABEL) || labels(finalPull).includes(PENDING_LABEL)) refuse("release_label_readback_failed", "release PR labels did not reconcile to tagged");
  return { ...result, releaseId: checked.release.id };
}
if (process.argv[1] === fileURLToPath(import.meta.url)) runPublisher().catch((error) => { console.error(error); process.exitCode = 1; });
