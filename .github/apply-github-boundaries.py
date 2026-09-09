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
    + "\t\t$this->client        = new GitHubApiClient( function (): void {\n\t\t\t$this->assertLive();\n\t\t} );\n"
    + "\t\t$this->artifactStore = new GitHubArtifactStore();\n",
    1,
)

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

# The new custody API reports allocation cleanup through a typed exception rather than a by-reference out parameter.
text = text.replace("\t\t$allocationClean = null;\n", "", 1)
old_allocate = "$this->artifactStore->allocate( $facts['artifact_filename'], $allocationClean )"
if old_allocate not in text:
    raise SystemExit('missing migrated artifact allocation call')
text = text.replace(old_allocate, "$this->artifactStore->allocate( $facts['artifact_filename'] )", 1)
old_catch = "\t\t\tif ( is_string( $path ) && is_array( $initialIdentity ) ) {\n\t\t\t\t$clean = $this->artifactStore->remove( $path, $initialIdentity );\n\t\t\t} elseif ( is_bool( $allocationClean ) ) {\n\t\t\t\t$clean = $allocationClean;\n\t\t\t} else {\n\t\t\t\tthrow $exception;\n\t\t\t}"
new_catch = "\t\t\tif ( is_string( $path ) && is_array( $initialIdentity ) ) {\n\t\t\t\t$clean = $this->artifactStore->remove( $path, $initialIdentity );\n\t\t\t} elseif ( $exception instanceof GitHubArtifactCustodyFailure ) {\n\t\t\t\t$clean = $exception->cleanupComplete;\n\t\t\t} else {\n\t\t\t\tthrow $exception;\n\t\t\t}"
if old_catch not in text:
    raise SystemExit('missing allocation cleanup catch block')
text = text.replace(old_catch, new_catch, 1)

start = text.index("\t/** @return array<string, mixed> */\n\tprivate function request(")
end = text.index("\t/** @return array<string, mixed> */\n\tprivate function jsonSuccess(", start)
text = text[:start] + text[end:]

api_method = "\n\tprivate function api( string $path ): string {\n\t\treturn self::API_ORIGIN . $path;\n\t}\n"
if api_method in text:
    text = text.replace(api_method, '\n', 1)

start = text.index("\t/** @param array<string, mixed> $response */\n\tprivate static function responseCode(")
end = text.index("\t/** @return list<array<string, mixed>> */\n\tprivate static function decodeList(", start)
text = text[:start] + text[end:]

start = text.index("\t/** @param-out ?bool $allocationClean @return array{0:string,1:array<string,int>} */\n\tprivate function temporaryFile(")
end = text.index("\tprivate static function validatedRedirectUrl(", start)
text = text[:start] + text[end:]

start = text.index("\tprivate static function validatedRedirectUrl(")
end = text.index("\tprivate static function validLocator(", start)
text = text[:start] + text[end:]

start = text.index("\tprivate static function exactUtcDate(")
end = text.index("\tprivate static function nonNegativeHeader(", start)
text = text[:start] + text[end:]
service.write_text(text)

runtime = Path('runtime.php')
text = runtime.read_text()
anchor = "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\Provider\\\\GitHub\\\\GitHubReleaseReadUnavailable' => 'src/Provider/GitHub/GitHubReleaseReadUnavailable.php',\n"
if anchor not in text:
    raise SystemExit('missing runtime GitHub anchor')
addition = (
    anchor
    + "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\Provider\\\\GitHub\\\\GitHubApiClient' => 'src/Provider/GitHub/GitHubApiClient.php',\n"
    + "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\Provider\\\\GitHub\\\\GitHubArtifactCustodyFailure' => 'src/Provider/GitHub/GitHubArtifactCustodyFailure.php',\n"
    + "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\Provider\\\\GitHub\\\\GitHubArtifactStore' => 'src/Provider/GitHub/GitHubArtifactStore.php',\n"
)
text = text.replace(anchor, addition, 1)
runtime.write_text(text)

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

phpstan = Path('phpstan.neon')
text = phpstan.read_text()
anchor = "\t\t- src/WordPress/PendingInstallState.php\n"
if anchor not in text:
    raise SystemExit('missing PHPStan ratchet anchor')
text = text.replace(
    anchor,
    anchor
    + "\t\t- src/Provider/GitHub/GitHubApiClient.php\n"
    + "\t\t- src/Provider/GitHub/GitHubArtifactCustodyFailure.php\n"
    + "\t\t- src/Provider/GitHub/GitHubArtifactStore.php\n",
    1,
)
phpstan.write_text(text)

files = sorted(['bootstrap.php', 'runtime.php'] + [str(path) for path in Path('src').rglob('*.php')])
payload = b''.join(
    path.encode() + b'\0' + hashlib.sha256(Path(path).read_bytes()).hexdigest().encode() + b'\n'
    for path in files
)
manifest = Path('runtime-copy.json')
data = json.loads(manifest.read_text())
data['package_revision'] = hashlib.sha256(payload).hexdigest()
manifest.write_text(json.dumps(data, indent=2) + '\n')
