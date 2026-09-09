from pathlib import Path

broker_path = Path('src/Runtime/RequestBroker.php')
broker = broker_path.read_text()


def method_span(text: str, name: str) -> tuple[int, int, str]:
    marker = f'\tprivate function {name}('
    start = text.index(marker)
    brace = text.index('{', start)
    depth = 0
    quote = None
    escaped = False
    line_comment = False
    block_comment = False
    i = brace
    while i < len(text):
        ch = text[i]
        nxt = text[i + 1] if i + 1 < len(text) else ''
        if line_comment:
            if ch == '\n':
                line_comment = False
            i += 1
            continue
        if block_comment:
            if ch == '*' and nxt == '/':
                block_comment = False
                i += 2
                continue
            i += 1
            continue
        if quote is not None:
            if escaped:
                escaped = False
            elif ch == '\\':
                escaped = True
            elif ch == quote:
                quote = None
            i += 1
            continue
        if ch in ("'", '"'):
            quote = ch
            i += 1
            continue
        if ch == '/' and nxt == '/':
            line_comment = True
            i += 2
            continue
        if ch == '/' and nxt == '*':
            block_comment = True
            i += 2
            continue
        if ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth == 0:
                return start, i + 1, text[start:i + 1]
        i += 1
    raise RuntimeError(f'Unterminated method {name}')


public_methods = [
    'ownedBy',
    'exactPublicMethods',
    'validStatus',
    'validDiagnostics',
    'declarationCode',
    'releaseDeclarationCode',
    'exactKeys',
]
private_methods = ['validNativeStatus', 'validDiagnosticCode', 'opaque']
all_methods = public_methods + private_methods

blocks = {}
for name in all_methods:
    _, _, block = method_span(broker, name)
    visibility = 'public' if name in public_methods else 'private'
    block = block.replace(f'\tprivate function {name}(', f'\t{visibility} static function {name}(', 1)
    block = block.replace('$this->', 'self::')
    blocks[name] = block

const_start = broker.index('\tprivate const TERMINAL_CODES')
const_end = broker.index('\n\n\t/** @var list<array', const_start)
const_block = broker[const_start:const_end]

helper = '''<?php

declare(strict_types=1);

namespace RAN\\WPReleaseUpdater\\V1\\Runtime;

/**
 * Internal protocol/wire validator for RequestBroker handoff boundaries.
 *
 * @internal RequestBroker remains the sole owner of request-local lifecycle state.
 */
final class RequestProtocolValidator {

\tprivate const MAX_DIAGNOSTICS = 16;
'''
helper += const_block
helper += '\n\n'

annotations = {
    'exactPublicMethods': '\t/** @param list<string> $expected */\n',
    'declarationCode': '\t/** @param array<string,mixed> $value */\n',
    'releaseDeclarationCode': '\t/** @param array<string,mixed> $value */\n',
    'exactKeys': '\t/**\n\t * @param array<string,mixed> $value\n\t * @param list<string> $keys\n\t */\n',
}
for name in all_methods:
    helper += annotations.get(name, '') + blocks[name] + '\n\n'
helper += '''\tpublic static function isTerminalCode( string $code ): bool {
\t\treturn in_array( $code, self::TERMINAL_CODES, true );
\t}
}
'''
Path('src/Runtime/RequestProtocolValidator.php').write_text(helper)

# Remove protocol code-list constants from the stateful broker; keep its own bounded
# diagnostic buffer constant because that state remains broker-owned.
broker = broker[:const_start] + broker[const_end:]

if "require_once __DIR__ . '/RequestProtocolValidator.php';" not in broker:
    broker = broker.replace(
        "require_once __DIR__ . '/RuntimeCopySelector.php';\n",
        "require_once __DIR__ . '/RuntimeCopySelector.php';\nrequire_once __DIR__ . '/RequestProtocolValidator.php';\n",
        1,
    )

# Delegate validation calls from the broker to the internal helper. Preserve
# declarationCode() as a thin private delegate because characterization tests
# intentionally reflect that historical private seam.
replacements = {
    '$this->ownedBy(': 'RequestProtocolValidator::ownedBy(',
    '$this->exactPublicMethods(': 'RequestProtocolValidator::exactPublicMethods(',
    '$this->validStatus(': 'RequestProtocolValidator::validStatus(',
    '$this->validDiagnostics(': 'RequestProtocolValidator::validDiagnostics(',
    '$this->releaseDeclarationCode(': 'RequestProtocolValidator::releaseDeclarationCode(',
    '$this->exactKeys(': 'RequestProtocolValidator::exactKeys(',
}
for old, new in replacements.items():
    broker = broker.replace(old, new)
broker = broker.replace(
    "in_array( $result['code'], self::TERMINAL_CODES, true )",
    "RequestProtocolValidator::isTerminalCode( $result['code'] )",
)

# Remove moved method bodies, except declarationCode which remains a compatibility
# delegate for the focused characterization test.
for name in ['ownedBy', 'exactPublicMethods', 'validStatus', 'validNativeStatus', 'validDiagnostics', 'validDiagnosticCode', 'releaseDeclarationCode', 'opaque', 'exactKeys']:
    start, end, _ = method_span(broker, name)
    broker = broker[:start] + broker[end:]

start, end, _ = method_span(broker, 'declarationCode')
broker = broker[:start] + "\tprivate function declarationCode( array $value ): ?string {\n\t\treturn RequestProtocolValidator::declarationCode( $value );\n\t}" + broker[end:]

# Sanity checks: no moved code-list constants remain in the broker and all stateful
# public protocol methods remain present.
for token in ('TERMINAL_CODES', 'CANDIDATE_VALIDATION_CODES', 'FAILURE_CODES', 'RELATIONSHIPS'):
    if token in broker:
        raise RuntimeError(f'Broker still contains moved protocol constant {token}')
for method in ('protocolVersion', 'registerCandidate', 'activate', 'registerTarget', 'releaseSource', 'targetStatus', 'targetDiagnostics', 'refreshTarget', 'diagnostics'):
    if f'function {method}(' not in broker:
        raise RuntimeError(f'Broker lost required method {method}')

broker_path.write_text(broker)

# Synthetic physical-runtime roots must model the new broker sibling.
activation = Path('tests/Runtime/RequestBrokerActivationDrainTest.php')
text = activation.read_text()
needle = "\t\tcopy( dirname( __DIR__, 2 ) . '/src/Runtime/RuntimeCopySelector.php', $root . '/src/Runtime/RuntimeCopySelector.php' );\n"
insert = needle + "\t\tcopy( dirname( __DIR__, 2 ) . '/src/Runtime/RequestProtocolValidator.php', $root . '/src/Runtime/RequestProtocolValidator.php' );\n"
if needle not in text:
    raise RuntimeError('Activation-drain selector copy fixture not found')
text = text.replace(needle, insert, 1)
identity_old = "'src/Runtime/RequestBroker.php', 'src/Runtime/RuntimeCopySelector.php', 'src/Runtime/SelectedRuntimeState.php'"
identity_new = "'src/Runtime/RequestBroker.php', 'src/Runtime/RequestProtocolValidator.php', 'src/Runtime/RuntimeCopySelector.php', 'src/Runtime/SelectedRuntimeState.php'"
if identity_old not in text:
    raise RuntimeError('Activation-drain identity fixture not found')
activation.write_text(text.replace(identity_old, identity_new, 1))

broker_test = Path('tests/Runtime/RequestBrokerTest.php')
text = broker_test.read_text()
copy_old = "'bootstrap.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/RuntimeCopySelector.php', 'src/Runtime/SelectedRuntimeState.php'"
copy_new = "'bootstrap.php', 'src/Runtime/RequestBroker.php', 'src/Runtime/RequestProtocolValidator.php', 'src/Runtime/RuntimeCopySelector.php', 'src/Runtime/SelectedRuntimeState.php'"
if copy_old not in text:
    raise RuntimeError('RequestBroker synthetic copy fixture not found')
broker_test.write_text(text.replace(copy_old, copy_new, 1))

phpstan = Path('phpstan.neon')
text = phpstan.read_text()
needle = '\t\t- src/Runtime/RuntimeCopySelector.php\n'
if needle not in text:
    raise RuntimeError('RuntimeCopySelector PHPStan ratchet entry not found')
if '\t\t- src/Runtime/RequestProtocolValidator.php\n' not in text:
    text = text.replace(needle, '\t\t- src/Runtime/RequestProtocolValidator.php\n' + needle, 1)
phpstan.write_text(text)
