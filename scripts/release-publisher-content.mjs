export const BETA = /^0\.1\.0-beta\.(0|[1-9][0-9]*)$/;
export const UNRELEASED = "0.0.0";

export class PublisherRefusal extends Error {
  constructor(code, message) {
    super(`${code}: ${message}`);
    this.name = "PublisherRefusal";
    this.code = code;
  }
}

export function refuse(code, message) {
  throw new PublisherRefusal(code, message);
}

export function manifestVersion(raw, source, permitUnreleased = false) {
  let manifest;
  try { manifest = JSON.parse(raw); } catch { refuse("release_manifest_invalid", `${source} manifest is not JSON`); }
  const version = manifest?.["."];
  if (Object.keys(manifest ?? {}).length !== 1 || typeof version !== "string" || (!BETA.test(version) && !(permitUnreleased && version === UNRELEASED))) {
    refuse("release_manifest_invalid", `${source} manifest must contain one canonical release version`);
  }
  return version;
}

export function runtimeCopy(raw) {
  let copy;
  try { copy = JSON.parse(raw); } catch { refuse("runtime_copy_invalid", "runtime-copy.json is not JSON"); }
  if (!copy || typeof copy !== "object" || Array.isArray(copy) || !/^[a-f0-9]{64}$/.test(copy.package_revision ?? "") || typeof copy.package_version !== "string") {
    refuse("runtime_copy_invalid", "runtime-copy.json has no canonical package identity");
  }
  return copy;
}

function firstNotes(changelog, version) {
  const escaped = version.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"); const date = "\\([0-9]{4}-[0-9]{2}-[0-9]{2}\\)"; const heading = new RegExp(`^## (?:${escaped} ${date}|\\[${escaped}\\]\\(https://github\\.com/RocketsAreNostalgic/ran-wp-release-updater/compare/v[^)\\s]+\\.\\.\\.v${escaped}\\) ${date})\\n`, "m");
  const start = changelog.search(heading);
  if (start < 0 || changelog.match(/^## (?:\[|[0-9])/m)?.index !== start) refuse("release_notes_missing", "candidate must prepend its changelog section");
  const tail = changelog.slice(start);
  const next = tail.slice(1).search(/^## (?:\[|[0-9])/m);
  const notes = (next < 0 ? tail : tail.slice(0, next + 1)).trim();
  if (notes.length < 20 || notes.length > 125000) refuse("release_notes_invalid", "candidate notes are empty or unbounded");
  return notes;
}

export function candidateIdentity(contents, candidateSha) {
  if (!/^[a-f0-9]{40}$/.test(candidateSha)) refuse("candidate_invalid", "candidate SHA is invalid");
  const version = manifestVersion(contents.manifest, "candidate");
  const copy = runtimeCopy(contents.runtimeCopy);
  let composer;
  try { composer = JSON.parse(contents.composer); } catch { refuse("composer_identity_invalid", "composer.json is not JSON"); }
  if (composer?.name !== "ran/wp-release-updater" || composer?.type !== "library" || Object.hasOwn(composer, "version")) refuse("composer_identity_invalid", "Composer identity is not the unversioned target library");
  if (copy.package_version !== version) refuse("version_source_drift", "manifest and runtime-copy package_version disagree");
  return { candidateSha, packageName: composer.name, tag: `v${version}`, version, notes: firstNotes(contents.changelog, version), packageRevision: copy.package_revision };
}

export function verifyReleaseDelta(parent, candidate) {
  const before = manifestVersion(parent.manifest, "parent", true);
  const after = manifestVersion(candidate.manifest, "candidate");
  if (before !== UNRELEASED && !BETA.test(before)) refuse("release_content_drift", "parent release state is invalid");
	if (before !== UNRELEASED) {
		const beforeParts = before.match(BETA).slice(1).map(Number);
		const afterParts = after.match(BETA).slice(1).map(Number);
		const changed = afterParts.findIndex((part, index) => part !== beforeParts[index]);
		if (changed < 0 || afterParts[changed] < beforeParts[changed]) refuse("release_version_not_advanced", "release version must advance");
	}
  const parentCopy = runtimeCopy(parent.runtimeCopy);
  const candidateCopy = runtimeCopy(candidate.runtimeCopy);
  if (parentCopy.package_version !== before || candidateCopy.package_version !== after || parentCopy.package_revision !== candidateCopy.package_revision) refuse("release_content_drift", "only runtime-copy package_version may change");
  if (candidate.manifest !== parent.manifest.replace(JSON.stringify(before), JSON.stringify(after)) || candidate.runtimeCopy !== parent.runtimeCopy.replace(JSON.stringify(before), JSON.stringify(after))) refuse("release_content_drift", "version files may change only their version token");
  const prefix = "# Changelog\n\n";
  const escapedBefore = before.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const escapedAfter = after.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const date = "\\([0-9]{4}-[0-9]{2}-[0-9]{2}\\)";
  const parentHeading = before === UNRELEASED
    ? /^## \[Unreleased\]\n/m
    : new RegExp(`^## (?:${escapedBefore} ${date}|\\[${escapedBefore}\\]\\(https://github\\.com/RocketsAreNostalgic/ran-wp-release-updater/compare/v[^)\\s]+\\.\\.\\.v${escapedBefore}\\) ${date})\\n`, "m");
  const candidateHeading = before === UNRELEASED
    ? new RegExp(`^## ${escapedAfter} ${date}\\n`, "m")
    : new RegExp(`^## \\[${escapedAfter}\\]\\(https://github\\.com/RocketsAreNostalgic/ran-wp-release-updater/compare/v${escapedBefore}\\.\\.\\.v${escapedAfter}\\) ${date}\\n`, "m");
  const parentMatch = parentHeading.exec(parent.changelog);
  const candidateMatch = candidateHeading.exec(candidate.changelog);
  if (!parent.changelog.startsWith(prefix) || !candidate.changelog.startsWith(prefix) || parentMatch?.index !== prefix.length || candidateMatch?.index !== prefix.length) refuse("release_content_drift", "CHANGELOG heading or prefix is not an exact Release Please insertion");
  const parentHistory = parent.changelog.slice(prefix.length);
  if (!candidate.changelog.endsWith(parentHistory)) refuse("release_content_drift", "CHANGELOG prior history must remain byte-identical");
  const inserted = candidate.changelog.slice(prefix.length, candidate.changelog.length - parentHistory.length);
  if (!inserted.endsWith("\n\n") || !candidateHeading.test(inserted)) refuse("release_content_drift", "CHANGELOG insertion is not a complete Release Please section");
  return { parentVersion: before, candidateVersion: after };
}
