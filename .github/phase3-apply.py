from pathlib import Path
import re

# Migrate deprecated PHPUnit doc-comment data providers to attributes.
single = re.compile(
    r"(?P<indent>^[\t ]*)/\*\* @dataProvider (?P<provider>[A-Za-z_][A-Za-z0-9_]*) \*/\n(?P=indent)public function",
    re.MULTILINE,
)
multiline = re.compile(
    r"(?P<indent>^[\t ]*)/\*\*\n(?P=indent) \* @dataProvider (?P<provider>[A-Za-z_][A-Za-z0-9_]*)\n(?P=indent) \*/\n(?P=indent)public function",
    re.MULTILINE,
)

replacements = 0
for path in Path('tests').rglob('*Test.php'):
    text = path.read_text()

    def replace(match: re.Match[str]) -> str:
        nonlocal_replacement = (
            f"{match.group('indent')}#[\\PHPUnit\\Framework\\Attributes\\DataProvider( '{match.group('provider')}' )]\n"
            f"{match.group('indent')}public function"
        )
        return nonlocal_replacement

    text, count_one = single.subn(replace, text)
    text, count_two = multiline.subn(replace, text)
    if count_one or count_two:
        replacements += count_one + count_two
        path.write_text(text)

if replacements != 16:
    raise SystemExit(f'Expected 16 PHPUnit data-provider migrations, got {replacements}')
if any('@dataProvider' in path.read_text() for path in Path('tests').rglob('*.php')):
    raise SystemExit('Deprecated @dataProvider metadata remains')

# Make new PHPUnit and runtime deprecations fail the normal unit-test gate.
composer = Path('composer.json')
text = composer.read_text()
old = '"test:unit": "phpunit --configuration phpunit.xml"'
new = '"test:unit": "phpunit --configuration phpunit.xml --fail-on-deprecation --fail-on-phpunit-deprecation"'
if old not in text:
    raise SystemExit('composer test:unit anchor not found')
composer.write_text(text.replace(old, new, 1))

# Add current-stable PHP compatibility coverage while preserving PHP 8.2 as the floor job.
ci = Path('.github/workflows/ci.yml')
text = ci.read_text()
anchor = '\n  windows-portability:\n'
job = '''
  php-85-compatibility:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@de0fac2e4500dabe0009e67214ff5f5447ce83dd # v6.0.2
      - uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2
        with:
          php-version: '8.5'
          coverage: none
          extensions: zip, mysqli
          tools: composer:v2
      - run: composer install --no-interaction --prefer-dist
      - run: composer check

'''
if anchor not in text or 'php-85-compatibility:' in text:
    raise SystemExit('CI PHP 8.5 insertion anchor invalid')
ci.write_text(text.replace(anchor, '\n' + job + '  windows-portability:\n', 1))

# Admit additional cohesive collaborators to the PHPStan level-8 ratchet.
phpstan = Path('phpstan.neon')
text = phpstan.read_text()
old_paths = '''\t\t# Security-sensitive custody, state, and runtime-copy selection collaborators are required to remain level-8 clean.
\t\t- src/WordPress/OwnedArchiveStore.php
\t\t- src/WordPress/StagedPackageManifest.php
\t\t- src/WordPress/PendingInstallState.php
\t\t- src/WordPress/BindingState.php
\t\t- src/Runtime/RuntimeCopySelector.php
\t\t- src/Provider/GitHub/GitHubApiClient.php
\t\t- src/Provider/GitHub/GitHubArtifactCustodyFailure.php
\t\t- src/Provider/GitHub/GitHubArtifactStore.php
'''
new_paths = '''\t\t# Security-sensitive custody, state, runtime selection, and provider collaborators are required to remain level-8 clean.
\t\t- src/WordPress/OwnedArchiveStore.php
\t\t- src/WordPress/StagedPackageManifest.php
\t\t- src/WordPress/PendingInstallState.php
\t\t- src/WordPress/BindingState.php
\t\t- src/Runtime/ReleaseFailure.php
\t\t- src/Runtime/RuntimeCopySelector.php
\t\t- src/Runtime/SelectedRuntimeState.php
\t\t- src/Provider/GitHub/GitHubApiClient.php
\t\t- src/Provider/GitHub/GitHubArtifactCustodyFailure.php
\t\t- src/Provider/GitHub/GitHubArtifactStore.php
\t\t- src/Provider/GitHub/GitHubCredentialResolver.php
\t\t- src/Provider/GitHub/GitHubReleaseAdapter.php
\t\t- src/Provider/GitHub/GitHubReleaseReadUnavailable.php
\t\t- src/Provider/GitHub/ProspectiveReleaseArtifact.php
\t\t- src/Provider/GitHub/ProspectiveReleaseInspection.php
'''
if old_paths not in text:
    raise SystemExit('PHPStan ratchet block not found')
phpstan.write_text(text.replace(old_paths, new_paths, 1))

# Expand compact PHPDoc so PHPStan sees both iterable value types.
adapter = Path('src/Provider/GitHub/GitHubReleaseAdapter.php')
text = adapter.read_text()
old = '\t/** @param array<string, mixed> $configuration @param array<string, mixed> $archivePolicy */'
new = '''\t/**
\t * @param array<string, mixed> $configuration
\t * @param array<string, mixed> $archivePolicy
\t */'''
if old not in text:
    raise SystemExit('GitHubReleaseAdapter PHPDoc anchor not found')
adapter.write_text(text.replace(old, new, 1))

inspection = Path('src/Provider/GitHub/ProspectiveReleaseInspection.php')
text = inspection.read_text()
old = '\t/** @param array<string, mixed> $facts @return array<string, mixed> */'
new = '''\t/**
\t * @param array<string, mixed> $facts
\t * @return array<string, mixed>
\t */'''
if old not in text:
    raise SystemExit('ProspectiveReleaseInspection orderedFacts PHPDoc anchor not found')
text = text.replace(old, new, 1)
old = '\t/** @param array<string, mixed> $value @param list<string> $keys @return array<string, mixed> */'
new = '''\t/**
\t * @param array<string, mixed> $value
\t * @param list<string> $keys
\t * @return array<string, mixed>
\t */'''
if old not in text:
    raise SystemExit('ProspectiveReleaseInspection ordered PHPDoc anchor not found')
inspection.write_text(text.replace(old, new, 1))

state = Path('src/Runtime/SelectedRuntimeState.php')
text = state.read_text()
old = '\tpublic function activate(): array {'
new = '''\t/** @return array{loaded:bool,state:string,code:string,diagnostics:list<array{code:string}>} */
\tpublic function activate(): array {'''
if old not in text:
    raise SystemExit('SelectedRuntimeState activate anchor not found')
text = text.replace(old, new, 1)
old = "\t\t\t\t: strtolower( $matches[5] ) . '.' . $matches[6];"
new = "\t\t\t\t: strtolower( $matches[5] ?? '' ) . '.' . ( $matches[6] ?? '' );"
if old not in text:
    raise SystemExit('SelectedRuntimeState prerelease match anchor not found')
state.write_text(text.replace(old, new, 1))
