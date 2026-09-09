from pathlib import Path
import hashlib
import json

service = Path('src/Provider/GitHub/GitHubReleaseService.php')
text = service.read_text()

# Transport constants now belong to GitHubApiClient.
for fragment in (
    "\tprivate const API_HOST                 = 'api.github.com';\n",
    "\tprivate const API_ORIGIN               = 'https://' . self::API_HOST;\n",
    "\tprivate const HTTP_TIMEOUT             = 10;\n",
):
    if fragment not in text:
        raise SystemExit(f'missing transport constant: {fragment!r}')
    text = text.replace(fragment, '', 1)
asset_hosts = "\tprivate const RELEASE_ASSET_HOSTS      = array(\n\t\t'github-releases.githubusercontent.com',\n\t\t'objects.githubusercontent.com',\n\t\t'release-assets.githubusercontent.com',\n\t);\n"
if asset_hosts not in text:
    raise SystemExit('missing release asset hosts block')
text = text.replace(asset_hosts, '', 1)

# Add the two internal collaborators.
property_anchor = "\tprivate GitHubCredentialResolver $credentials;\n"
if property_anchor not in text:
    raise SystemExit('missing credential property anchor')
text = text.replace(
    property_anchor,
    property_anchor + "\tprivate GitHubApiClient $client;\n\tprivate GitHubArtifactStore $artifactStore;\n",
    1,
)
ctor_anchor = "\t\t$this->binding       = self::ordered( $configuration, self::CONFIGURATION_KEYS );\n\t\t$this->credentials   = $credentials ?? new GitHubCredentialResolver();\n\t\t$this->livenessGuard = $livenessGuard;\n"
if ctor_anchor not in text:
    raise SystemExit('missing constructor assignment anchor')
text = text.replace(
    ctor_anchor,
    ctor_anchor
    + "\t\t$this->client        = new GitHubApiClient( fn (): void => $this->assertLive() );\n"
    + "\t\t$this->artifactStore = new GitHubArtifactStore();\n",
    1,
)

# Migrate call sites before removing the old implementations.
replacements = {
    '$this->request(': '$this->client->request(',
    'self::responseCode(': 'GitHubApiClient::responseCode(',
    'self::responseHeader(': 'GitHubApiClient::responseHeader(',
    'self::responseBody(': 'GitHubApiClient::responseBody(',
    '$this->api(': '$this->client->api(',
    '$this->temporaryFile(': '$this->artifactStore->allocate(',
    'self::fileIdentity(': '$this->artifactStore->identity(',
    'self::removeOwnedFile(': '$this->artifactStore->remove(',
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing service call site: {old}')
    text = text.replace(old, new)

# Remove request/send; response parsing and provider policy remain in the service.
start = text.index("\t/** @return array<string, mixed> */\n\tprivate function request(")
end = text.index("\t/** @return array<string, mixed> */\n\tprivate function jsonSuccess(", start)
text = text[:start] + text[end:]

# repositoryApiUrl now uses the client directly; remove the trivial api() wrapper.
api_method = "\n\tprivate function api( string $path ): string {\n\t\treturn self::API_ORIGIN . $path;\n\t}\n"
if api_method in text:
    text = text.replace(api_method, '\n', 1)
else:
    # The call-site replacement can alter the body before removal.
    altered = "\n\tprivate function api( string $path ): string {\n\t\treturn self::API_ORIGIN . $path;\n\t}\n"
    if altered in text:
        text = text.replace(altered, '\n', 1)

# Remove response accessor implementations now delegated to GitHubApiClient.
start = text.index("\t/** @param array<string, mixed> $response */\n\tprivate static function responseCode(")
end = text.index("\t/** @return list<array<string, mixed>> */\n\tprivate static function decodeList(", start)
text = text[:start] + text[end:]

# Remove temporary-file custody methods now delegated to GitHubArtifactStore.
start = text.index("\t/** @param-out ?bool $allocationClean @return array{0:string,1:array<string,int>} */\n\tprivate function temporaryFile(")
end = text.index("\tprivate static function validatedRedirectUrl(", start)
text = text[:start] + text[end:]

# Remove redirect/signed-URL validation now delegated to GitHubApiClient.
start = text.index("\tprivate static function validatedRedirectUrl(")
end = text.index("\tprivate static function validLocator(", start)
text = text[:start] + text[end:]

# exactUtcDate only served signed-URL validation.
start = text.index("\tprivate static function exactUtcDate(")
end = text.index("\tprivate static function nonNegativeHeader(", start)
text = text[:start] + text[end:]

service.write_text(text)

# Register the internal runtime symbols.
runtime = Path('runtime.php')
text = runtime.read_text()
anchor = "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\Provider\\\\GitHub\\\\GitHubReleaseReadUnavailable' => 'src/Provider/GitHub/GitHubReleaseReadUnavailable.php',\n"
if anchor not in text:
    raise SystemExit('missing runtime GitHub anchor')
addition = (
    anchor
    + "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\Provider\\\\GitHub\\\\GitHubApiClient' => 'src/Provider/GitHub/GitHubApiClient.php',\n"
    + "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\Provider\\\\GitHub\\\\GitHubArtifactStore' => 'src/Provider/GitHub/GitHubArtifactStore.php',\n"
)
text = text.replace(anchor, addition, 1)
runtime.write_text(text)

# Native filesystem operations in these custody/transport helpers have the same security rationale as the service they replace.
phpcs = Path('.phpcs.xml')
text = phpcs.read_text()
for rule in ('WordPress.WP.AlternativeFunctions', 'WordPress.PHP.NoSilencedErrors'):
    rule_start = text.index(f'<rule ref="{rule}">')
    anchor = '\t\t<exclude-pattern>/src/Provider/GitHub/GitHubReleaseService.php</exclude-pattern>'
    pos = text.index(anchor, rule_start)
    addition = (
        '\t\t<exclude-pattern>/src/Provider/GitHub/GitHubApiClient.php</exclude-pattern>\n'
        '\t\t<exclude-pattern>/src/Provider/GitHub/GitHubArtifactStore.php</exclude-pattern>\n'
        + anchor
    )
    text = text[:pos] + text[pos:].replace(anchor, addition, 1)
phpcs.write_text(text)

# Ratchet the new security-sensitive collaborators at PHPStan level 8.
phpstan = Path('phpstan.neon')
text = phpstan.read_text()
anchor = "\t\t- src/WordPress/PendingInstallState.php\n"
if anchor not in text:
    raise SystemExit('missing PHPStan ratchet anchor')
text = text.replace(
    anchor,
    anchor
    + "\t\t- src/Provider/GitHub/GitHubApiClient.php\n"
    + "\t\t- src/Provider/GitHub/GitHubArtifactStore.php\n",
    1,
)
phpstan.write_text(text)

# Refresh sealed runtime content identity; PHPCBF may alter bytes again in the workflow, so it is recomputed there too.
files = sorted(['bootstrap.php', 'runtime.php'] + [str(path) for path in Path('src').rglob('*.php')])
payload = b''.join(
    path.encode() + b'\0' + hashlib.sha256(Path(path).read_bytes()).hexdigest().encode() + b'\n'
    for path in files
)
manifest = Path('runtime-copy.json')
data = json.loads(manifest.read_text())
data['package_revision'] = hashlib.sha256(payload).hexdigest()
manifest.write_text(json.dumps(data, indent=2) + '\n')
