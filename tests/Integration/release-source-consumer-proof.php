<?php
declare(strict_types=1);

/* Executable public-consumer proof. run-prototype supplies a durable PHP temp directory. */
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
$type = $argv[1] ?? 'plugin';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$scenario = $argv[2] ?? 'happy';
if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! in_array( $scenario, array( 'happy', 'liveness', 'discard', 'fence-list', 'fence-inspect', 'fence-acquire' ), true ) ) {
	throw new RuntimeException( 'Pass a supported package type and consumer scenario.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$fixture_repository = 'acme/consumer';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$fixture_repository_id = '99';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$fixture_release_id = 7;
if ( 'fence-list' === $scenario ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$fixture_repository = 'acme/example-plugin';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$fixture_repository_id = '123456789';
}
if ( 'fence-inspect' === $scenario ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$fixture_repository = 'acme/example-theme';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$fixture_repository_id = '987654321';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$fixture_release_id = 42;
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$root = sys_get_temp_dir() . '/release-source-consumer-' . $type . '-' . bin2hex( random_bytes( 8 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Could not create fixture root.' ); }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$GLOBALS['rs_root'] = $root;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$GLOBALS['rs_hooks'] = array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$GLOBALS['rs_requests'] = array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$GLOBALS['rs_responses'] = array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$GLOBALS['rs_paths'] = array();
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
$GLOBALS['wp_version'] = '6.8.0';
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
$GLOBALS['wpdb'] = new stdClass();
register_shutdown_function(
	static function () use ( $root ): void {
		$fixture_entries = glob( $root . '/*' );
		foreach ( $fixture_entries ? $fixture_entries : array() as $path ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@unlink( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		} @rmdir( $root );
	}
);
define( 'WP_PLUGIN_DIR', $root . '/plugins' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Classes.DuplicateClassName.Found -- This stub must occupy the WordPress global class identity used by production calls. The same foreign stub name is reused behind conditional or isolated-process load boundaries.
final class WP_Error {}
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The self-contained fixture combines foreign functions/classes with the test harness that exercises them. WordPress calls this global stub by its exact foreign function name.
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function wp_http_validate_url( string $url ): string {
	return $url; }
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Keep the conditional WordPress stub and its test class in the same self-contained fixture. This stub must occupy the WordPress global class identity used by production calls.
final class WP_Filesystem_Direct {}
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the WordPress WP_Filesystem entry point used by the consumer proof. WordPress calls this global stub by its exact foreign function name.
function WP_Filesystem(): bool {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
	$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();
	return true;
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress requires this positional stub signature; this consumer proof intentionally ignores these inputs. WordPress calls this global stub by its exact foreign function name.
function add_action( string $hook, callable $callback, int $priority = 10, int $arguments = 1 ): void {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$GLOBALS['rs_hooks'][] = compact( 'hook', 'callback', 'priority' ); }
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress requires this positional stub signature; this consumer proof intentionally ignores these inputs. WordPress calls this global stub by its exact foreign function name.
function add_filter( string $hook, callable $callback, int $priority = 10, int $arguments = 1 ): void {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$GLOBALS['rs_hooks'][] = compact( 'hook', 'callback', 'priority' ); }
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress requires this positional stub signature; this consumer proof intentionally ignores these inputs. WordPress calls this global stub by its exact foreign function name.
function doing_action( string $hook ): bool {
	return false; }
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress requires this positional stub signature; this consumer proof intentionally ignores these inputs. WordPress calls this global stub by its exact foreign function name.
function did_action( string $hook ): int {
	return 0; }
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress requires this positional stub signature; this consumer proof intentionally ignores these inputs. WordPress calls this global stub by its exact foreign function name.
function wp_tempnam( string $name ): string|false {
	$path = tempnam( $GLOBALS['rs_root'], 'rs-' );
	if ( is_string( $path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
		chmod( $path, 0600 );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$GLOBALS['rs_paths'][] = $path;
	} return $path; }
/**
 * @param array<string,mixed> $args
 * @return array<string,mixed>|WP_Error
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name. @phpstan-ignore return.unusedType (The stub preserves the foreign WordPress HTTP return contract even when this scenario returns only an array.)
function wp_safe_remote_get( string $url, array $args ): array|WP_Error {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$GLOBALS['rs_requests'][] = array(
		'url'    => $url,
		'stream' => (bool) ( $args['stream'] ?? false ),
	);
	$response                 = array_shift( $GLOBALS['rs_responses'] );
	if ( ! is_array( $response ) ) {
		throw new RuntimeException( 'Unexpected mock request.' );
	} if ( isset( $args['filename'], $response['file'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
		file_put_contents( $args['filename'], $response['file'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
		chmod( $args['filename'], 0600 );
	} return $response; }
/**
 * @param array<string,mixed> $response
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function wp_remote_retrieve_response_code( array $response ): int|string {
	return $response['response']['code']; }
/**
 * @param array<string,mixed> $response
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function wp_remote_retrieve_header( array $response, string $name ): mixed {
	return $response['headers'][ strtolower( $name ) ] ?? null; }
/**
 * @param array<string,mixed> $response
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function wp_remote_retrieve_body( array $response ): string {
	return $response['body'] ?? ''; }
/**
 * @return array{response:array{code:int},headers:array<string,string>,body:string,file:?string}
 */
function ran_wp_release_updater_test_rs_response( mixed $body, ?string $file = null ): array {
	return array(
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		'headers'  => array(),
		'response' => array( 'code' => 200 ),
		'file'     => $file,
	); }
/** @phpstan-assert true $condition */
function ran_wp_release_updater_test_rs_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( $message ); } }
function ran_wp_release_updater_test_rs_activate(): void {
	foreach ( $GLOBALS['rs_hooks'] as $hook ) {
		if ( 'after_setup_theme' === $hook['hook'] ) {
			( $hook['callback'] )(); }
	} }
/** @return array{requests:int,zips:int} */
function ran_wp_release_updater_test_rs_delta( int $start ): array {
	$requests = array_slice( $GLOBALS['rs_requests'], $start );
	return array(
		'requests' => count( $requests ),
		'zips'     => count( array_filter( $requests, static fn( array $request ): bool => $request['stream'] ) ),
	); }
/**
 * @param array<string,mixed> $delta
 */
function ran_wp_release_updater_test_rs_assert_delta( string $operation, array $delta, int $zips ): void {
	ran_wp_release_updater_test_rs_assert( 0 < $delta['requests'], $operation . ' made no HTTP requests.' );
	ran_wp_release_updater_test_rs_assert( $zips === $delta['zips'], $operation . ' ZIP request count changed.' ); }
/**
 * @param array<string,mixed> $facts
 */
function ran_wp_release_updater_test_rs_copy_verified( string $source, string $destination, array $facts ): void {
	$size  = $facts['artifact_size'] ?? null;
	$limit = $facts['maximum_artifact_bytes'] ?? null;
	ran_wp_release_updater_test_rs_assert( is_int( $size ) && $size > 0 && is_int( $limit ) && $size <= $limit, 'Artifact facts are not bounded.' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The fixture needs the native stream mode and handle, including exclusive creation or archive truncation semantics.
	$input = fopen( $source, 'rb' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The fixture needs the native stream mode and handle, including exclusive creation or archive truncation semantics.
	$output = fopen( $destination, 'xb' );
	if ( false === $input || false === $output ) {
		throw new RuntimeException( 'Could not open prepared-copy stream.' ); }
	try {
		$remaining = $size;
		while ( 0 < $remaining ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read the native stream directly to preserve bounded reads and injected failure behavior.
			$chunk = fread( $input, min( 8192, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException( 'Prepared-copy read was incomplete.' ); }
			for ( $offset = 0, $length = strlen( $chunk ); $offset < $length; ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write fixture bytes to the native stream while preserving its existing partial-write or subprocess protocol.
				$written = fwrite( $output, substr( $chunk, $offset ) );
				if ( false === $written || 0 === $written ) {
					throw new RuntimeException( 'Prepared-copy write was incomplete.' );
				} $offset += $written; }
			$remaining -= $length;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read the native stream directly to preserve bounded reads and injected failure behavior.
		if ( false === feof( $input ) && '' !== fread( $input, 1 ) ) {
			throw new RuntimeException( 'Artifact exceeded its inspected size.' ); }
	} finally {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $input );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $output ); }
	ran_wp_release_updater_test_rs_assert( filesize( $destination ) === $size, 'Prepared copy size changed.' );
	ran_wp_release_updater_test_rs_assert( hash_equals( $facts['artifact_sha256'], (string) hash_file( 'sha256', $destination ) ), 'Prepared copy digest changed.' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$zip_path = $root . '/fixture.zip';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$zip = new ZipArchive();
ran_wp_release_updater_test_rs_assert( true === $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'ZIP creation failed.' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$entry = 'plugin' === $type ? 'consumer/consumer.php' : 'consumer/style.css';
$zip->addFromString( $entry, 'plugin' === $type ? "<?php\n/*\nPlugin Name: Consumer\nVersion: 1.2.3\nUpdate URI: https://github.com/{$fixture_repository}\nRequires at least: 6.5\nRequires PHP: 8.2\n*/" : "/*\nTheme Name: Consumer\nVersion: 1.2.3\nUpdate URI: https://github.com/{$fixture_repository}\nRequires at least: 6.5\nRequires PHP: 8.2\n*/" );
$zip->close();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
chmod( $zip_path, 0600 );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$bytes = file_get_contents( $zip_path );
ran_wp_release_updater_test_rs_assert( is_string( $bytes ), 'ZIP read failed.' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$release = array(
	'id'         => $fixture_release_id,
	'draft'      => false,
	'prerelease' => false,
	'immutable'  => true,
	'tag_name'   => 'v1.2.3',
	'html_url'   => 'https://github.com/' . $fixture_repository . '/releases/tag/v1.2.3',
	'assets'     => array(
		array(
			'id'     => 8,
			'name'   => 'consumer.zip',
			'state'  => 'uploaded',
			'size'   => strlen( $bytes ),
			'digest' => 'sha256:' . hash( 'sha256', $bytes ),
		),
	),
);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$queue_proof = static function () use ( $release, $bytes, $fixture_repository_id ): void {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$GLOBALS['rs_responses'] = array( ran_wp_release_updater_test_rs_response( array( 'id' => (int) $fixture_repository_id ) ), ran_wp_release_updater_test_rs_response( $release ), ran_wp_release_updater_test_rs_response( array( 'sha' => str_repeat( 'a', 40 ) ) ), ran_wp_release_updater_test_rs_response( array( 'id' => (int) $fixture_repository_id ) ), ran_wp_release_updater_test_rs_response( null, $bytes ), ran_wp_release_updater_test_rs_response( array( 'id' => (int) $fixture_repository_id ) ) );
};
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$credentials = 0;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$registrar = require dirname( __DIR__, 2 ) . '/bootstrap.php';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$source = $registrar->releases(
	'github',
	$type,
	'acme/consumer',
	'99',
	'stable',
	static function () use ( &$credentials ): string {
		++$credentials;
		return 'consumer-token';
	}
);
ran_wp_release_updater_test_rs_assert(
	array(
		array(
			'hook'     => 'after_setup_theme',
			'callback' => $GLOBALS['rs_hooks'][0]['callback'],
			'priority' => PHP_INT_MAX,
		),
	) === $GLOBALS['rs_hooks'],
	'Release source registered native hooks.'
);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$broker_facts = $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics();
ran_wp_release_updater_test_rs_assert( 0 === $broker_facts['submission_count'] && 0 === $broker_facts['logical_target_count'], 'Release source bound a native target.' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$before = $source->list();
ran_wp_release_updater_test_rs_assert( 'runtime_not_ready' === $before['code'], 'Source did not fail before readiness.' );
ran_wp_release_updater_test_rs_activate();
ran_wp_release_updater_test_rs_assert( 1 === count( $GLOBALS['rs_hooks'] ) && 'after_setup_theme' === $GLOBALS['rs_hooks'][0]['hook'], 'Release source added hooks during activation.' );
if ( 'fence-list' !== $scenario ) {
	ran_wp_release_updater_test_rs_assert( WP_Filesystem() && $GLOBALS['wp_filesystem'] instanceof WP_Filesystem_Direct, 'Fixture could not initialize direct filesystem state.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$fence = $argv[3] ?? null;
if ( str_starts_with( $scenario, 'fence-' ) ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Carry exact repository documentation-fence bytes through the escaped CLI argument; this is a controlled test payload.
	ran_wp_release_updater_test_rs_assert( is_string( $fence ) && '' !== $fence && false !== base64_decode( $fence, true ), 'Fence source is required.' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Carry exact repository documentation-fence bytes through the escaped CLI argument; this is a controlled test payload. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$fence = base64_decode( $fence, true );
	if ( 'fence-acquire' === $scenario ) {
		$queue_proof();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$inspection = $source->inspect( '7', 'v1.2.3' );
		ran_wp_release_updater_test_rs_assert( $inspection['ok'], 'Fence prerequisite inspection failed.' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$release_id = '7';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
		$tag = 'v1.2.3';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$fingerprint = $inspection['value']['fingerprint'];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$application_storage_directory = $root;
		$queue_proof();
	} elseif ( 'fence-list' === $scenario ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$GLOBALS['rs_responses'] = array( ran_wp_release_updater_test_rs_response( array( $release ) ) ); } else {
		$queue_proof(); }
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$fence_request_start = count( $GLOBALS['rs_requests'] );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Execute the repository documentation fence supplied by the parent test in this isolated CLI fixture, never a production request.
		eval( $fence );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		foreach ( $GLOBALS['rs_hooks'] as $hook ) {
			if ( 'init' === $hook['hook'] ) {
				( $hook['callback'] )();
			}
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$fence_delta = ran_wp_release_updater_test_rs_delta( $fence_request_start );
		if ( 'fence-list' === $scenario ) {
			ran_wp_release_updater_test_rs_assert(
				array(
					'requests' => 1,
					'zips'     => 0,
				) === $fence_delta,
				'README listing fence did not complete its public operation.'
			);
		} elseif ( 'fence-inspect' === $scenario ) {
			ran_wp_release_updater_test_rs_assert(
				array(
					'requests' => 6,
					'zips'     => 1,
				) === $fence_delta,
				'README inspection fence did not complete its public operation.'
			);
		} else {
			ran_wp_release_updater_test_rs_assert( isset( $acquisition ) && is_array( $acquisition ) && true === ( $acquisition['ok'] ?? false ), 'Acquisition fence returned a failure.' );
			ran_wp_release_updater_test_rs_assert( isset( $application_owned_path ) && $root . '/prepared-release.zip' === $application_owned_path, 'Acquisition fence did not construct its application-owned destination.' );
			ran_wp_release_updater_test_rs_assert( is_file( $application_owned_path ), 'Acquisition fence did not create its prepared copy.' );
			ran_wp_release_updater_test_rs_assert( hash_equals( $acquisition['value']['inspection']['artifact_sha256'], (string) hash_file( 'sha256', $application_owned_path ) ), 'Acquisition fence prepared-copy digest changed.' );
			ran_wp_release_updater_test_rs_assert(
				array(
					'requests' => 6,
					'zips'     => 1,
				) === $fence_delta,
				'Acquisition fence did not complete its public operation.'
			);
			ran_wp_release_updater_test_rs_assert( ! file_exists( $GLOBALS['rs_paths'][ count( $GLOBALS['rs_paths'] ) - 1 ] ), 'Acquisition fence retained its owned artifact.' );
		}
		ran_wp_release_updater_test_rs_assert( array() === $GLOBALS['rs_responses'], 'Fence mock queue was not drained.' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The following absence assertion observes cleanup; an already absent fixture must not emit a warning. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		@unlink( $root . '/fence-prepared.zip' );
		ran_wp_release_updater_test_rs_assert( ! file_exists( $root . '/fence-prepared.zip' ), 'Fixture owner could not remove its scenario file.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		echo json_encode(
			array(
				'type'     => $type,
				'scenario' => $scenario,
				'before'   => $before['code'],
			),
			JSON_THROW_ON_ERROR
		) . PHP_EOL;
	exit( 0 );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$list_start = count( $GLOBALS['rs_requests'] );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$GLOBALS['rs_responses'] = array( ran_wp_release_updater_test_rs_response( array( $release ) ) );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$list = $source->list();
ran_wp_release_updater_test_rs_assert( true === $list['ok'], 'Public list failed.' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$list_delta = ran_wp_release_updater_test_rs_delta( $list_start );
ran_wp_release_updater_test_rs_assert_delta( 'list', $list_delta, 0 );
$queue_proof();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$inspect_start = count( $GLOBALS['rs_requests'] );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$inspection = $source->inspect( '7', 'v1.2.3' );
ran_wp_release_updater_test_rs_assert( true === $inspection['ok'] && 'complete' === $inspection['cleanup_status'], 'Public inspect failed.' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$inspect_delta = ran_wp_release_updater_test_rs_delta( $inspect_start );
ran_wp_release_updater_test_rs_assert_delta( 'inspect', $inspect_delta, 1 );
$queue_proof();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$acquire_start = count( $GLOBALS['rs_requests'] );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$acquisition = $source->acquire( '7', 'v1.2.3', $inspection['value']['fingerprint'] );
ran_wp_release_updater_test_rs_assert( true === $acquisition['ok'] && 'retained' === $acquisition['cleanup_status'], 'Public acquire failed.' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$acquire_delta = ran_wp_release_updater_test_rs_delta( $acquire_start );
ran_wp_release_updater_test_rs_assert_delta( 'acquire', $acquire_delta, 1 );
ran_wp_release_updater_test_rs_assert( array() === $GLOBALS['rs_responses'], 'Mock queue was not drained.' );
ran_wp_release_updater_test_rs_assert( 2 === count( $GLOBALS['rs_paths'] ), 'Inspection and acquisition must allocate exactly one artifact each.' );
ran_wp_release_updater_test_rs_assert( ! file_exists( $GLOBALS['rs_paths'][0] ), 'Inspection artifact was not removed.' );
ran_wp_release_updater_test_rs_assert( is_file( $GLOBALS['rs_paths'][1] ), 'Acquisition artifact was not retained.' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$artifact = $acquisition['value']['artifact'];
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$caller = new DomainException( 'consumer callback' );
try {
	$artifact->inspect(
		static function () use ( $caller ): never {
			throw $caller;
		}
	);
	throw new RuntimeException( 'Callback escaped.' );
} catch ( DomainException $actual ) {
	ran_wp_release_updater_test_rs_assert( $caller === $actual, 'Callback identity changed.' ); }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$provisional = $root . '/prepared.zip';
try {
	if ( 'happy' === $scenario ) {
		$artifact->inspect(
			static function ( string $path ) use ( $provisional, $acquisition ): void {
				ran_wp_release_updater_test_rs_copy_verified( $path, $provisional, $acquisition['value']['inspection'] );
			}
		);
		ran_wp_release_updater_test_rs_assert( $artifact->discard(), 'Artifact cleanup failed.' );
		ran_wp_release_updater_test_rs_assert( is_file( $provisional ), 'Prepared copy missing.' ); } elseif ( 'liveness' === $scenario ) {
		try {
			$artifact->inspect(
				static function ( string $path ) use ( $provisional, $acquisition ): void {
					ran_wp_release_updater_test_rs_copy_verified( $path, $provisional, $acquisition['value']['inspection'] );
					$GLOBALS['ran_wp_release_updater_v1_broker'] = new stdClass();
				}
			);
			throw new RuntimeException( 'Expected liveness loss.' );
		} catch ( RuntimeException $failure ) {
			ran_wp_release_updater_test_rs_assert( 1002 === $failure->getCode(), 'Liveness loss did not use code 1002.' );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The following absence assertion observes cleanup; an already absent fixture must not emit a warning. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@unlink( $provisional );
		} ran_wp_release_updater_test_rs_assert( ! file_exists( $provisional ), 'Liveness failure retained provisional copy.' );
		ran_wp_release_updater_test_rs_assert( $artifact->discard(), 'Cleanup after liveness loss failed.' ); } else {
			try {
				$artifact->inspect(
					static function ( string $path ) use ( $provisional, $acquisition ): void {
						ran_wp_release_updater_test_rs_copy_verified( $path, $provisional, $acquisition['value']['inspection'] );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
							file_put_contents( $path, 'changed' );
					}
				);
				throw new RuntimeException( 'Expected changed artifact.' );
			} catch ( RuntimeException $failure ) {
				ran_wp_release_updater_test_rs_assert( 1001 === $failure->getCode(), 'Changed artifact did not use code 1001.' );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The following absence assertion observes cleanup; an already absent fixture must not emit a warning. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				@unlink( $provisional );
			} ran_wp_release_updater_test_rs_assert( ! file_exists( $provisional ), 'Changed-artifact failure retained provisional copy.' );
			ran_wp_release_updater_test_rs_assert( ! $artifact->discard(), 'Changed artifact cleanup must fail safely.' ); }
} catch ( Throwable $failure ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort provisional-file cleanup preserves the original exception. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
	@unlink( $provisional );
	throw $failure; }
if ( 'happy' !== $scenario ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The following absence assertion observes cleanup; an already absent fixture must not emit a warning. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
	@unlink( $provisional ); }
ran_wp_release_updater_test_rs_assert( 'happy' === $scenario ? is_file( $provisional ) : ! file_exists( $provisional ), 'Consumer provisional-copy retention changed.' );
ran_wp_release_updater_test_rs_assert( 3 === $credentials, 'Resolver was not called once per operation.' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
echo json_encode(
	array(
		'type'                  => $type,
		'scenario'              => $scenario,
		'before'                => $before['code'],
		'credential_operations' => $credentials,
		'http'                  => array(
			'list'    => $list_delta,
			'inspect' => $inspect_delta,
			'acquire' => $acquire_delta,
		),
	),
	JSON_THROW_ON_ERROR
) . PHP_EOL;
