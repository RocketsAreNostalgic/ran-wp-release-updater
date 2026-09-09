from pathlib import Path

broker = Path('src/Runtime/RequestBroker.php')
text = broker.read_text()

copy_keys = "\tprivate const COPY_KEYS                  = array(\n\t\t'package_revision',\n\t\t'package_version',\n\t\t'php_floor',\n\t\t'runtime_file',\n\t\t'runtime_protocol',\n\t\t'wordpress_floor',\n\t);\n"
if copy_keys not in text:
    raise SystemExit('RequestBroker COPY_KEYS block not found')
text = text.replace(copy_keys, '', 1)

sha_line = "\tprivate const SHA256                     = '/\\A[a-f0-9]{64}\\z/D';\n"
if sha_line not in text:
    raise SystemExit('RequestBroker SHA256 constant not found')
text = text.replace(sha_line, '', 1)

use_anchor = "use Throwable;\n"
require_line = "\nrequire_once __DIR__ . '/RuntimeCopySelector.php';\n"
if use_anchor not in text or require_line in text:
    raise SystemExit('RequestBroker selector load anchor invalid')
text = text.replace(use_anchor, use_anchor + require_line, 1)

property_anchor = "\tprivate ?string $terminalCode = null;\n"
if property_anchor not in text:
    raise SystemExit('RequestBroker property anchor not found')
text = text.replace(property_anchor, property_anchor + "\tprivate RuntimeCopySelector $runtimeCopySelector;\n", 1)

ctor = "\tpublic function __construct( private bool $activationBoundaryMissed = false, private ?SelectedRuntimeState $selectedRuntimeState = null ) {\n\t}\n"
replacement_ctor = "\tpublic function __construct( private bool $activationBoundaryMissed = false, private ?SelectedRuntimeState $selectedRuntimeState = null ) {\n\t\t$this->runtimeCopySelector = new RuntimeCopySelector();\n\t}\n"
if ctor not in text:
    raise SystemExit('RequestBroker constructor anchor not found')
text = text.replace(ctor, replacement_ctor, 1)

if '$this->candidate( $copyFile )' not in text:
    raise SystemExit('RequestBroker candidate call not found')
text = text.replace('$this->candidate( $copyFile )', '$this->runtimeCopySelector->candidate( $copyFile )', 1)
if '$this->select( $environment )' not in text:
    raise SystemExit('RequestBroker select call not found')
text = text.replace('$this->select( $environment )', '$this->runtimeCopySelector->select( $this->candidates, $environment )', 1)

environment_start_marker = "\t/** @param array<string,mixed> $environment */\n\tprivate function validEnvironment( array $environment ): bool {"
environment_end_marker = "\n\t/** @return array{loaded:bool,state:string,code:string,diagnostics:list<array{code:string}>} */\n\tprivate function result("
environment_start = text.find(environment_start_marker)
environment_end = text.find(environment_end_marker, environment_start)
if environment_start < 0 or environment_end < 0:
    raise SystemExit('RequestBroker environment validator anchors not found')
new_environment = (
    "\t/** @param array<string,mixed> $environment */\n"
    "\tprivate function validEnvironment( array $environment ): bool {\n"
    "\t\treturn $this->runtimeCopySelector->validEnvironment( $environment );\n"
    "\t}\n"
)
text = text[:environment_start] + new_environment + text[environment_end:]

start_marker = "\t/** @return array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,source_root:string,wordpress_floor:string} */\n\tprivate function candidate("
end_marker = "\tprivate function exactKeys( array $value, array $keys ): bool {"
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('RequestBroker selector method block anchors not found')
text = text[:start] + text[end:]

read_method = "\n\tprivate function read( string $file ): string {\n\t\t$content = file_get_contents( $file );\n\t\tif ( false === $content ) {\n\t\t\tthrow new RuntimeException( 'Unreadable runtime copy.' );\n\t\t}\n\t\treturn $content;\n\t}\n"
if read_method not in text:
    raise SystemExit('RequestBroker read method not found')
text = text.replace(read_method, '\n', 1)
broker.write_text(text)

# Minimal physical-copy fixtures must model the new sibling file and include it in explicit identities.
request_broker_test = Path('tests/Runtime/RequestBrokerTest.php')
text = request_broker_test.read_text()
old_files = "array( 'bootstrap.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/SelectedRuntimeState.php' )"
new_files = "array( 'bootstrap.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/RuntimeCopySelector.php', 'src/Runtime/SelectedRuntimeState.php' )"
if old_files not in text:
    raise SystemExit('RequestBrokerTest minimal copy list not found')
request_broker_test.write_text(text.replace(old_files, new_files, 1))

drain_test = Path('tests/Runtime/RequestBrokerActivationDrainTest.php')
text = drain_test.read_text()
old_copy = (
    "\t\tcopy( dirname( __DIR__, 2 ) . '/src/Runtime/RequestBroker.php', $root . '/src/Runtime/RequestBroker.php' );\n"
    "\t\tcopy( dirname( __DIR__, 2 ) . '/src/Runtime/SelectedRuntimeState.php', $root . '/src/Runtime/SelectedRuntimeState.php' );"
)
new_copy = (
    "\t\tcopy( dirname( __DIR__, 2 ) . '/src/Runtime/RequestBroker.php', $root . '/src/Runtime/RequestBroker.php' );\n"
    "\t\tcopy( dirname( __DIR__, 2 ) . '/src/Runtime/RuntimeCopySelector.php', $root . '/src/Runtime/RuntimeCopySelector.php' );\n"
    "\t\tcopy( dirname( __DIR__, 2 ) . '/src/Runtime/SelectedRuntimeState.php', $root . '/src/Runtime/SelectedRuntimeState.php' );"
)
if old_copy not in text:
    raise SystemExit('RequestBrokerActivationDrainTest copy block not found')
text = text.replace(old_copy, new_copy, 1)
old_identity = "array( 'bootstrap.php', 'runtime.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/SelectedRuntimeState.php' )"
new_identity = "array( 'bootstrap.php', 'runtime.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/RuntimeCopySelector.php', 'src/Runtime/SelectedRuntimeState.php' )"
if old_identity not in text:
    raise SystemExit('RequestBrokerActivationDrainTest identity list not found')
drain_test.write_text(text.replace(old_identity, new_identity, 1))

phpcs = Path('.phpcs.xml')
text = phpcs.read_text()
old = (
    "\t<!-- Bootstrap and broker read local trusted runtime metadata, never remote URLs. -->\n"
    "\t<rule ref=\"WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents\">\n"
    "\t\t<exclude-pattern>/bootstrap.php</exclude-pattern>\n"
    "\t\t<exclude-pattern>/src/Runtime/RequestBroker.php</exclude-pattern>\n"
    "\t</rule>"
)
new = (
    "\t<!-- Bootstrap and the runtime-copy selector read local trusted runtime metadata, never remote URLs. -->\n"
    "\t<rule ref=\"WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents\">\n"
    "\t\t<exclude-pattern>/bootstrap.php</exclude-pattern>\n"
    "\t\t<exclude-pattern>/src/Runtime/RuntimeCopySelector.php</exclude-pattern>\n"
    "\t</rule>"
)
if old not in text:
    raise SystemExit('PHPCS local runtime metadata exception not found')
phpcs.write_text(text.replace(old, new, 1))

phpstan = Path('phpstan.neon')
text = phpstan.read_text()
anchor = "\t\t- src/WordPress/BindingState.php\n"
selector = "\t\t- src/Runtime/RuntimeCopySelector.php\n"
if anchor not in text or selector in text:
    raise SystemExit('PHPStan selector ratchet anchor invalid')
text = text.replace(anchor, anchor + selector, 1)
text = text.replace(
    '# Security-sensitive custody, pending-state, and binding-state collaborators are required to remain level-8 clean.',
    '# Security-sensitive custody, state, and runtime-copy selection collaborators are required to remain level-8 clean.',
    1,
)
phpstan.write_text(text)
