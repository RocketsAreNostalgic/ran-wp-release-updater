<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- CLI fixture state is process-local or shared with its controlled callbacks; preserve observed globals and external fixture keys, not plugin runtime globals.

declare(strict_types = 1);
/*
 * Reproducible local fixture: controlled GitHub-shaped responses only. Run:
 * mkdir -p .workspaces/php-tmp
 * TMPDIR="$PWD/.workspaces/php-tmp" php -d sys_temp_dir="$PWD/.workspaces/php-tmp" \
 *     tests/Performance/native-discovery-measure.php
 * PHP CLI needs ext-zip and uses the caller's INI configuration.
 */
// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Keep global WordPress stubs and namespaced test code in the same isolated fixture. WordPress stubs must be declared in the global namespace used by production calls.
namespace {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Classes.DuplicateClassName.Found -- This stub must occupy the WordPress global class identity used by production calls. The same foreign stub name is reused behind conditional or isolated-process load boundaries.
	final class WP_Error {
		public function __construct( public string $code, public string $message ) {
		}
	}
	// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The self-contained fixture combines foreign functions/classes with the test harness that exercises them. WordPress calls this global stub by its exact foreign function name.
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
	function get_filesystem_method(): string {
		return 'direct';
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
	function get_current_network_id(): int {
		return 1;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
	function wp_safe_remote_get( string $url, array $args ): array {
		$response = ran_wp_release_updater_test_native_measure_response( $url );
		$body     = $response ['body'];
		++$GLOBALS ['native_measure'] ['http_calls'];
		$GLOBALS ['native_measure'] ['body_bytes'] += strlen( $body );
		if ( isset( $args ['filename'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for native discovery measurement fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $args ['filename'], $body );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
			chmod( $args ['filename'], 0600 );
			$GLOBALS ['native_measure'] ['streamed_bytes'] += strlen( $body );
		}
		return array(
			'body'     => isset( $args ['filename'] ) ? '' : $body,
			'headers'  => array(),
			'response' => array( 'code' => 200 ),
		);
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
	function wp_remote_retrieve_response_code( array $r ): int {
		return $r ['response'] ['code'];
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
	function wp_remote_retrieve_header( array $r, string $name ): mixed {
		return $r ['headers'] [ strtolower( $name ) ] ?? null;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
	function wp_remote_retrieve_body( array $r ): string {
		return $r ['body'];
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
	function wp_http_validate_url( string $url ): string {
		return $url;
	}
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress requires the filename parameter; this fixture allocates its own temporary filename. WordPress calls this global stub by its exact foreign function name.
	function wp_tempnam( string $name ): string|false {
		$path = tempnam( $GLOBALS ['native_measure'] ['temp'], 'asset-' );
		if ( is_string( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
			chmod( $path, 0600 );
			$GLOBALS ['native_measure'] ['temporary'] [] = $path;
		}
		return $path;
	}
	function ran_wp_release_updater_test_native_measure_response( string $url ): array {
		$u          = & $GLOBALS ['native_measure'];
		$parts      = parse_url( $url );
		$repository = (string) ( $u ['repository'] ?? 'repository' );
		$prefix     = '/repos/owner/' . $repository;
		$path       = $parts ['path'] ?? null;
		if ( ! is_array( $parts ) || 'https' !== ( $parts ['scheme'] ?? null ) || 'api.github.com' !== ( $parts ['host'] ?? null ) || ! is_string( $path ) || ( $path !== $prefix && ! str_starts_with( $path, $prefix . '/' ) ) ) {
			throw new \RuntimeException( 'Unexpected fixture request: ' . $url );
		}
		if ( $prefix . '/releases' === $path && isset( $parts ['query'] ) ) {
			return array( 'body' => json_encode( ran_wp_release_updater_test_native_measure_releases( $u ['scenario'] ) ) );
		}
		if ( 1 === preg_match( '~^' . preg_quote( $prefix, '~' ) . '/releases/assets/8$~D', $path ) ) {
			++$u ['acquisitions'];
			return array( 'body' => $u ['zip'] );
		}
		if ( 1 === preg_match( '~^' . preg_quote( $prefix, '~' ) . '/releases/([0-9]+)$~D', $path, $m ) ) {
			return array( 'body' => json_encode( ran_wp_release_updater_test_native_measure_release( (int) $m [1], $u ['changed'] && $u ['after_offer'], 'incompatible' === $u ['scenario'] ) ) );
		}
		if ( 1 === preg_match( '~^' . preg_quote( $prefix, '~' ) . '/commits/[A-Za-z0-9._/-]+$~D', $path ) ) {
			return array( 'body' => json_encode( array( 'sha' => $u ['changed'] && $u ['after_offer'] ? str_repeat( 'b', 40 ) : str_repeat( 'a', 40 ) ) ) );
		}
		if ( $prefix !== $path ) {
			throw new \RuntimeException( 'Unexpected fixture endpoint: ' . $url );
		}
		$number = preg_match( '/-(\d+)$/', $repository, $match ) ? (int) $match [1] : 0;
		return array( 'body' => json_encode( array( 'id' => 99 + $number ) ) );
	}
	function ran_wp_release_updater_test_native_measure_releases( string $scenario ): array {
		$n   = 'incompatible' === $scenario ? 8 : 1;
		$all = array();
		for ( $i = 0; $i < $n; ++$i ) {
			$all [] = ran_wp_release_updater_test_native_measure_release( $i + 1, false, 'incompatible' === $scenario );
		}
		return $all;
	}
	function ran_wp_release_updater_test_native_measure_release( int $id, bool $changed = false, bool $incompatible = false ): array {
		$v          = $incompatible ? '2.0.' . $id : ( 'no-newer' === $GLOBALS ['native_measure'] ['scenario'] ? '1.0.0' : '2.0.0' );
		$repository = $GLOBALS ['native_measure'] ['repository'] ?? 'repository';
		return array(
			'id'               => $id,
			'draft'            => false,
			'prerelease'       => false,
			'immutable'        => true,
			'published_at'     => '2026-08-22T10:00:00Z',
			'tag_name'         => 'v' . $v,
			'html_url'         => 'https://github.com/owner/' . $repository . '/releases/tag/v' . $v,
			'target_commitish' => $changed ? str_repeat( 'b', 40 ) : str_repeat( 'a', 40 ),
			'assets'           => array(
				array(
					'id'     => 8,
					'name'   => $repository . '.zip',
					'size'   => strlen( $GLOBALS ['native_measure'] ['zip'] ),
					'state'  => 'uploaded',
					'digest' => 'sha256:' . hash( 'sha256', $GLOBALS ['native_measure'] ['zip'] ),
				),
			),
		);
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- This fixture colocates foreign global stubs and namespaced test or injected provider seams. Keep global WordPress stubs and namespaced test code in the same isolated fixture.
namespace Tests\Performance {
	require_once dirname( __DIR__ ) . '/Support/WordPressHookFixture.php';
	require_once dirname( __DIR__ ) . '/Support/FakeOptionDatabase.php';
	use Tests\Support\FakeOptionDatabase;
	const NATIVE_MEASURE_COUNTS = array( 1, 5, 10, 20 );
	function native_measure_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}
	/** @return array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,runtime_protocol:int,wordpress_floor:string} */
	function native_measure_runtime_manifest( string $root ): array {
		$file = $root . '/runtime-copy.json';
		native_measure_assert( is_file( $file ) && ! is_link( $file ), 'Runtime manifest is not a regular file: ' . $file );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for native discovery measurement fixtures without requiring WordPress filesystem initialization.
			$manifest = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Runtime manifest is unreadable: ' . $file, 0, $error );
		}
		$keys = array( 'package_revision', 'package_version', 'php_floor', 'runtime_file', 'runtime_protocol', 'wordpress_floor' );
		native_measure_assert( is_array( $manifest ) && ! array_is_list( $manifest ) && array_keys( $manifest ) === $keys, 'Runtime manifest has an unexpected shape: ' . $file );
		native_measure_assert( is_string( $manifest ['package_revision'] ) && is_string( $manifest ['package_version'] ) && is_string( $manifest ['php_floor'] ) && 'runtime.php' === $manifest ['runtime_file'] && is_int( $manifest ['runtime_protocol'] ) && 0 < $manifest ['runtime_protocol'] && is_string( $manifest ['wordpress_floor'] ), 'Runtime manifest contains invalid values: ' . $file );
		return $manifest;
	}
	function native_measure_current_runtime_protocol( string $trusted_root, array $roots ): int {
		native_measure_assert( '' !== $trusted_root && is_dir( $trusted_root ), 'Trusted current runtime root is unavailable.' );
		$trusted = native_measure_runtime_manifest( $trusted_root );
		foreach ( $roots as $root ) {
			native_measure_assert( native_measure_runtime_manifest( $root ) === $trusted, 'Runtime specimen manifest differs from the trusted current manifest: ' . $root );
		}
		return $trusted ['runtime_protocol'];
	}
	function native_measure_zip( string $type, string $root = 'repository', string $uri = 'https://github.com/owner/repository', string $name = 'Fixture' ): string {
		$path = tempnam( $GLOBALS ['native_measure'] ['temp'], 'zip-' );
		$zip  = new \ZipArchive();
		native_measure_assert( true === $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ), 'ZIP creation failed.' );
		$header = 'plugin' === $type
			? "<?php\n/*\nPlugin Name: " . $name . "\nVersion: 2.0.0\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/"
			: "/*\nTheme Name: " . $name . "\nVersion: 2.0.0\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/";
		$zip->addFromString( $root . '/' . ( 'plugin' === $type ? $root . '.php' : 'style.css' ), $header );
		$zip->addFromString( $root . '/readme.txt', 'fixture' );
		$zip->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for native discovery measurement fixtures without requiring WordPress filesystem initialization.
		$bytes = file_get_contents( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		unlink( $path );
		return is_string( $bytes ) ? $bytes : throw new \RuntimeException( 'ZIP read failed.' );
	}
	/** One PHP request: N copied bootstraps, M queued registrar targets, then one activation. */
	function native_measure_delta( array $before ): array {
		$now = $GLOBALS ['native_measure'];
		return array(
			'http_calls'               => $now ['http_calls'] - $before ['http_calls'],
			'body_bytes'               => $now ['body_bytes'] - $before ['body_bytes'],
			'streamed_bytes'           => $now ['streamed_bytes'] - $before ['streamed_bytes'],
			'archive_acquisitions'     => $now ['acquisitions'] - $before ['acquisitions'],
			'validation_archive_opens' => $now ['validation_opens'] - $before ['validation_opens'],
		);
	}
	function native_measure_counters(): array {
		return array(
			'http_calls'       => $GLOBALS ['native_measure'] ['http_calls'],
			'body_bytes'       => $GLOBALS ['native_measure'] ['body_bytes'],
			'streamed_bytes'   => $GLOBALS ['native_measure'] ['streamed_bytes'],
			'acquisitions'     => $GLOBALS ['native_measure'] ['acquisitions'],
			'validation_opens' => $GLOBALS ['native_measure'] ['validation_opens'],
		);
	}
	/** One PHP request: N copied bootstraps, M queued registrar targets, then one activation. */
	function native_measure_shared_request( array $roots, int $targets, string $scenario, bool $callback_control = false, string $target_type = 'plugin', bool $callback_revoked = false ): array {
		native_measure_assert( array() !== $roots && count( $roots ) === count( array_unique( $roots ) ), 'Physical runtime roots are not distinct.' );
		$runtime_protocol           = native_measure_current_runtime_protocol( (string) getenv( 'RAN_NATIVE_MEASURE_TRUSTED_ROOT' ), $roots );
		$temp                       = getenv( 'RAN_NATIVE_MEASURE_TEMP' );
		$temp                       = $temp ? $temp : sys_get_temp_dir();
		$GLOBALS ['native_measure'] = array(
			'temp'             => $temp,
			'scenario'         => $scenario,
			'changed'          => false,
			'after_offer'      => false,
			'http_calls'       => 0,
			'body_bytes'       => 0,
			'streamed_bytes'   => 0,
			'acquisitions'     => 0,
			'validation_opens' => 0,
			'temporary'        => array(),
		);
		$installed_root             = $temp . '/shared-installed-' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for native discovery measurement fixtures with the specified permissions.
		mkdir( $installed_root, 0700, true );
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', $installed_root );
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
		$GLOBALS ['wp_version'] = '6.8.0';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
		$GLOBALS ['wpdb'] = new FakeOptionDatabase( 100 );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
		$GLOBALS ['wp_theme_directories'] = array( $installed_root );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
		$GLOBALS ['wp_filter'] = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
		$GLOBALS ['wp_actions'] = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
		$GLOBALS ['wp_current_filter'] = array();
		$api                           = null;
		$target_fixtures               = array();
		for ( $index = 0; $index < $targets; ++$index ) {
			$slug = 'repository-' . $index;
			$uri  = 'https://github.com/owner/' . $slug;
			$name = 'Fixture ' . $index;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for native discovery measurement fixtures with the specified permissions.
			mkdir( $installed_root . '/' . $slug, 0700, true );
			$file   = $installed_root . '/' . $slug . '/' . ( 'plugin' === $target_type ? $slug . '.php' : 'style.css' );
			$header = 'plugin' === $target_type
				? "<?php\n/*\nPlugin Name: " . $name . "\nVersion: 1.0.0\nPlugin URI: " . $uri . "\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/"
				: "/*\nTheme Name: " . $name . "\nVersion: 1.0.0\nTheme URI: " . $uri . "\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for native discovery measurement fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $file, $header );
			$target_fixtures [] = array(
				'repository' => $slug,
				'uri'        => $uri,
				'zip'        => native_measure_zip( $target_type, $slug, $uri, $name ),
			);
		}
		memory_reset_peak_usage();
		$before = array(
			'time_ns' => hrtime( true ),
			'memory'  => memory_get_usage( true ),
		);
		foreach ( $roots as $root ) {
			$loaded = require $root . '/bootstrap.php';
			if ( ! is_object( $api ) ) {
				$api = $loaded;
			}
		}
		$after_bootstrap  = array(
			'time_ns' => hrtime( true ),
			'memory'  => memory_get_usage( true ),
		);
		$credential_calls = 0;
		foreach ( $target_fixtures as $index => $fixture ) {
			$file     = $installed_root . '/' . $fixture ['repository'] . '/' . ( 'plugin' === $target_type ? $fixture ['repository'] . '.php' : 'style.css' );
			$resolver = $callback_control ? static function () use ( &$credential_calls, $callback_revoked ): ?string {
				++$credential_calls;
				if ( $callback_revoked && $credential_calls > 3 ) {
					throw new \RuntimeException( 'Credential revoked.' );
				}
				return null;
			}
			: null;
			$handle   = 'plugin' === $target_type ? $api->plugin( 'github', $file, 'owner/' . $fixture ['repository'], (string) ( 99 + $index ), 'stable', 'manual', $resolver ) : $api->theme( 'github', $file, 'owner/' . $fixture ['repository'], (string) ( 99 + $index ), 'stable', 'manual', $resolver );
			native_measure_assert( $handle->register(), 'Queued registrar target was rejected.' );
		}
		$after_declaration = array(
			'time_ns' => hrtime( true ),
			'memory'  => memory_get_usage( true ),
		);
		$broker            = $GLOBALS ['ran_wp_release_updater_v1_broker'];
		$activation        = $broker->activate(
			array(
				'php_version'       => '8.2.0',
				'runtime_protocol'  => $runtime_protocol,
				'wordpress_version' => '6.8.0',
			)
		);
		native_measure_assert( 'active' === $activation ['state'], 'Shared-request activation failed.' );
		$after_activation = array(
			'time_ns' => hrtime( true ),
			'memory'  => memory_get_usage( true ),
		);
		$submissions      = new \ReflectionProperty( $broker, 'submissions' );
		$all              = $submissions->getValue( $broker );
		$opens            = 0;
		$natives          = array();
		foreach ( array_values( $all ) as $index => $submission ) {
			$native    = ( new \ReflectionProperty( $submission ['handle'], 'native' ) )->getValue( $submission ['handle'] );
			$validator = ( new \ReflectionProperty( $native, 'validator' ) )->getValue( $native );
			( new \ReflectionProperty( $validator, 'after_open' ) )->setValue(
				$validator,
				static function ( string $path ) use ( &$opens ): void {
					unset( $path );
					++$opens;
					++$GLOBALS ['native_measure'] ['validation_opens'];
				}
			);
			$natives [] = array(
				'native'   => $native,
				'identity' => ( new \ReflectionProperty( $native, 'installed_identity' ) )->getValue( $native ),
				'fixture'  => $target_fixtures [ $index ],
			);
		}
		$steps  = array();
		$offers = array();
		if ( 'registration' !== $scenario ) {
			foreach ( $natives as $index => $item ) {
				$GLOBALS ['native_measure'] ['repository'] = $item ['fixture'] ['repository'];
				$GLOBALS ['native_measure'] ['zip']        = $item ['fixture'] ['zip'];
				$before_step                               = native_measure_counters();
				$offer                                     = $item ['native']->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $item ['fixture'] ['uri'],
					),
					$item ['identity'],
					array()
				);
				if ( 'incompatible' === $scenario || 'no-newer' === $scenario ) {
					native_measure_assert( false === $offer, ucfirst( $scenario ) . ' candidate was offered.' );
				} else {
					native_measure_assert( is_array( $offer ), 'Discovery did not offer an update for ' . $item ['identity'] . '.' );
				}
							$offers [ $index ]               = $offer;
							$steps [ 'discovery_' . $index ] = native_measure_delta( $before_step );
				if ( 'incompatible' === $scenario ) {
					native_measure_assert( 8 === $steps [ 'discovery_' . $index ] ['archive_acquisitions'], 'Incompatible search did not inspect eight candidates.' );
					native_measure_assert( 8 === $steps [ 'discovery_' . $index ] ['validation_archive_opens'], 'Incompatible search did not open eight candidate archives.' );
				}
				if ( 'no-newer' === $scenario ) {
					native_measure_assert( 1 === $steps [ 'discovery_' . $index ] ['http_calls'] && 0 === $steps [ 'discovery_' . $index ] ['archive_acquisitions'], 'No-newer discovery did not remain one page with no archive acquisition.' );
				}
			}
		}
		if ( 'repeated' === $scenario ) {
			foreach ( $natives as $index => $item ) {
				$GLOBALS ['native_measure'] ['repository'] = $item ['fixture'] ['repository'];
				$GLOBALS ['native_measure'] ['zip']        = $item ['fixture'] ['zip'];
				$before_step                               = native_measure_counters();
				$again                                     = $item ['native']->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $item ['fixture'] ['uri'],
					),
					$item ['identity'],
					array()
				);
				if ( $callback_revoked ) {
					native_measure_assert( false === $again, 'Revoked callback discovery did not fail closed.' );
				} else {
					native_measure_assert( is_array( $again ), 'Repeated discovery did not offer an update.' );
				}
							$steps [ 'repeated_discovery_' . $index ] = native_measure_delta( $before_step );
				if ( 'plugin' === $target_type && ! $callback_revoked ) {
					$before_step                       = native_measure_counters();
					$information                       = $item ['native']->filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', 'plugin' . "\0" . $item ['identity'] ), 0, 24 ) ) );
					$steps [ 'information_' . $index ] = native_measure_delta( $before_step );
					native_measure_assert( is_object( $information ) && '2.0.0' === ( $information->version ?? null ), 'Plugin information did not return the expected version.' );
				}
			}
		}
		if ( 'refresh' === $scenario ) {
			foreach ( $natives as $index => $item ) {
				native_measure_assert( true === $item ['native']->refresh(), 'Native refresh failed.' );
				$GLOBALS ['native_measure'] ['repository'] = $item ['fixture'] ['repository'];
				$GLOBALS ['native_measure'] ['zip']        = $item ['fixture'] ['zip'];
				$before_step                               = native_measure_counters();
				native_measure_assert(
					is_array(
						$item ['native']->filter_update(
							false,
							array(
								'Version'   => '1.0.0',
								'UpdateURI' => $item ['fixture'] ['uri'],
							),
							$item ['identity'],
							array()
						)
					),
					'Refresh discovery did not offer an update.'
				);
							$steps [ 'refresh_discovery_' . $index ] = native_measure_delta( $before_step );
							native_measure_assert( 1 === $steps [ 'refresh_discovery_' . $index ] ['archive_acquisitions'], 'Refresh discovery did not acquire a fresh archive.' );
			}
		}
		if ( 'install' === $scenario || 'changed' === $scenario ) {
			foreach ( $natives as $index => $item ) {
				$GLOBALS ['native_measure'] ['repository']  = $item ['fixture'] ['repository'];
				$GLOBALS ['native_measure'] ['zip']         = $item ['fixture'] ['zip'];
				$GLOBALS ['native_measure'] ['after_offer'] = true;
				if ( 'changed' === $scenario ) {
					$GLOBALS ['native_measure'] ['changed'] = true;
				}
				$before_step = native_measure_counters();
				$extra       = array(
					'action' => 'update',
					'type'   => $target_type,
					'plugin' === $target_type ? 'plugin' : 'theme' => $item ['identity'],
				);
				$reply       = $item ['native']->filter_pre_download( false, $offers [ $index ] ['package'], null, $extra );
				if ( 'install' === $scenario ) {
					native_measure_assert( is_string( $reply ) && is_file( $reply ), 'Fresh installation preparation failed.' );
				} else {
					native_measure_assert( $reply instanceof \WP_Error || false === $reply, 'Changed remote evidence was admitted.' );
					native_measure_assert( 'remote_release_changed' === $item ['native']->status() ['failure_code'], 'Changed remote evidence had the wrong rejection.' );
				}
				$step_name            = ( 'install' === $scenario ? 'install_' : 'changed_' ) . $index;
				$steps [ $step_name ] = native_measure_delta( $before_step );
				if ( 'install' === $scenario ) {
					native_measure_assert( 1 === $steps [ $step_name ] ['archive_acquisitions'], 'Installation preparation did not acquire exactly one fresh archive.' );
					native_measure_assert( 2 === $steps [ $step_name ] ['validation_archive_opens'], 'Installation preparation did not open source and owned archive.' );
				}
			}
		}
		$after_operations = array(
			'time_ns'     => hrtime( true ),
			'memory'      => memory_get_usage( true ),
			'memory_peak' => memory_get_peak_usage( true ),
		);
		$owned_paths      = array();
		foreach ( $natives as $item ) {
			$pending = ( new \ReflectionProperty( $item ['native'], 'pending_install' ) )->getValue( $item ['native'] );
			$path    = $pending instanceof \RAN\WPReleaseUpdater\V1\WordPress\PendingInstallState ? $pending->archive() : null;
			if ( is_string( $path ) && is_file( $path ) ) {
				$owned_paths [] = $path;
			}
		}
		$temporary_paths = array_filter( $GLOBALS ['native_measure'] ['temporary'], 'is_file' );
		$before_cleanup  = count( array_unique( array_merge( $owned_paths, $temporary_paths ) ) );
		foreach ( $natives as $item ) {
			native_measure_assert( true === $item ['native']->refresh(), 'Native cleanup refresh failed.' );
		}
		$after_cleanup = count( array_filter( array_unique( array_merge( $owned_paths, $temporary_paths ) ), 'is_file' ) );
		$broker_state  = $broker->diagnostics();
		native_measure_assert( count( $roots ) === $broker_state ['candidate_count'], 'Broker candidate count differs from physical copies: ' . json_encode( $broker_state ) );
		native_measure_assert( count( $all ) === $targets && $targets === $broker_state ['logical_target_count'], 'Shared-request active native count differs from declarations.' );
		native_measure_assert( $opens >= $GLOBALS ['native_measure'] ['acquisitions'], 'Validator opened fewer archives than acquisitions.' );
		native_measure_assert( 0 === $after_cleanup, 'Native refresh left owned or temporary archives behind.' );
		if ( 'registration' === $scenario ) {
			native_measure_assert( 0 === $GLOBALS ['native_measure'] ['http_calls'] && 0 === $credential_calls, 'Registration performed work outside declaration.' );
		}
		if ( 'install' === $scenario ) {
			native_measure_assert( $targets === $before_cleanup, 'Installation did not retain exactly one owned file per target.' );
		}
		if ( 'changed' === $scenario ) {
			foreach ( $steps as $name => $delta ) {
				if ( str_starts_with( $name, 'changed_' ) ) {
								native_measure_assert( 0 === $delta ['archive_acquisitions'], 'Changed evidence acquired an archive.' );
				}
			}
		}
		foreach ( $GLOBALS ['native_measure'] ['temporary'] as $path ) {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				unlink( $path );
			}
		}
		return array(
			'physical_copies'           => count( $roots ),
			'distinct_targets'          => $targets,
			'target_type'               => $target_type,
			'scenario'                  => $scenario,
			'credential_mode'           => $callback_control ? 'callback_returning_null' : 'literal_null',
			'bootstrap_ns'              => $after_bootstrap ['time_ns'] - $before ['time_ns'],
			'declaration_ns'            => $after_declaration ['time_ns'] - $after_bootstrap ['time_ns'],
			'activation_ns'             => $after_activation ['time_ns'] - $after_declaration ['time_ns'],
			'operations_ns'             => $after_operations ['time_ns'] - $after_activation ['time_ns'],
			'memory_initial'            => $before ['memory'],
			'memory_after_operations'   => $after_operations ['memory'],
			'memory_peak'               => $after_operations ['memory_peak'],
			'http_calls'                => $GLOBALS ['native_measure'] ['http_calls'],
			'body_bytes'                => $GLOBALS ['native_measure'] ['body_bytes'],
			'streamed_bytes'            => $GLOBALS ['native_measure'] ['streamed_bytes'],
			'archive_acquisitions'      => $GLOBALS ['native_measure'] ['acquisitions'],
			'validation_archive_opens'  => $opens,
			'files_before_cleanup'      => $before_cleanup,
			'files_after_cleanup'       => $after_cleanup,
			'credential_callback_calls' => $credential_calls,
			'operation_steps'           => $steps,
		);
	}
	function native_measure_shared_worker( array $args ): void {
		$roots = explode( '|', (string) getenv( 'RAN_NATIVE_MEASURE_ROOTS' ) );
		echo json_encode( native_measure_shared_request( $roots, (int) ( $args [2] ?? 1 ), $args [3] ?? 'cold', ( '--callback-control' === ( $args [4] ?? null ) ), $args [5] ?? 'plugin', ( '--callback-revoked' === ( $args [6] ?? null ) ) ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	}
	function native_measure_copy( string $from, string $to ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for native discovery measurement fixtures with the specified permissions.
		mkdir( $to, 0700, true );
		foreach ( array( 'bootstrap.php', 'runtime.php', 'runtime-copy.json' ) as $file ) {
			copy( $from . '/' . $file, $to . '/' . $file );
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from . '/src', \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $file ) {
			$relative    = substr( $file->getPathname(), strlen( $from . '/src' ) + 1 );
			$destination = $to . '/src/' . $relative;
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for native discovery measurement fixtures with the specified permissions.
				mkdir( $destination, 0700, true );
			} else {
				copy( $file->getPathname(), $destination );
			}
		}
	}
	function native_measure_child( array $roots, string $scratch, int $targets, string $scenario, bool $callback_control = false, string $target_type = 'plugin', bool $callback_revoked = false ): array {
		$command     = array( PHP_BINARY, '-d', 'sys_temp_dir=' . $scratch, __FILE__, '--shared-worker', (string) $targets, $scenario, $callback_control ? '--callback-control' : '--literal-null', $target_type, $callback_revoked ? '--callback-revoked' : '--callback-stable' );
		$environment = array(
			'RAN_NATIVE_MEASURE_ROOTS'        => implode( '|', $roots ),
			'RAN_NATIVE_MEASURE_TEMP'         => $scratch,
			'RAN_NATIVE_MEASURE_TRUSTED_ROOT' => dirname( __DIR__, 2 ),
		);
		foreach ( array( 'PATH', 'TMPDIR', 'PHPRC', 'PHP_INI_SCAN_DIR' ) as $name ) {
			$value = getenv( $name );
			if ( false !== $value ) {
				$environment[ $name ] = $value;
			}
		}
		$pipes = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the isolated proof command with explicit argv, pipe capture and exit-status observation.
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			$environment
		);
		native_measure_assert( is_resource( $process ), 'Could not start measurement subprocess.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes [0] );
		$stdout = stream_get_contents( $pipes [1] );
		$stderr = stream_get_contents( $pipes [2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes [1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes [2] );
		$exit = proc_close( $process );
		if ( 0 !== $exit ) {
			throw new \RuntimeException( 'Measurement subprocess failed (' . $exit . '): ' . trim( $stderr ) . "\nstdout: " . trim( $stdout ) );
		}
		try {
			$decoded = json_decode( $stdout, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Measurement subprocess returned invalid JSON: ' . trim( $stderr ) . "\nstdout: " . trim( $stdout ), 0, $error );
		}
		native_measure_assert( is_array( $decoded ), 'Measurement subprocess did not return a row.' );
		return $decoded;
	}
	function native_measure_main(): void {
		$root    = dirname( __DIR__, 2 );
		$scratch = $root . '/.workspaces/evidence/u2-native-measure-' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for native discovery measurement fixtures with the specified permissions.
		mkdir( $scratch, 0700, true );
		$scenarios = array( 'registration', 'cold', 'repeated', 'incompatible', 'no-newer', 'refresh', 'install', 'changed' );
		$rows      = array();
		foreach ( NATIVE_MEASURE_COUNTS as $count ) {
			foreach ( array( array( 1, $count ), array( $count, 1 ), array( $count, $count ) ) as $topology ) {
				foreach ( $scenarios as $scenario ) {
								[$copies, $targets] = $topology;
								$roots              = array();
					for ( $index = 0; $index < $copies; ++$index ) {
						$copy = $scratch . '/copy-' . count( $rows ) . '-' . $index;
						native_measure_copy( $root, $copy );
						$roots [] = $copy;
					}
					$row              = native_measure_child( $roots, $scratch, $targets, $scenario );
					$row ['topology'] = array(
						'physical_copies'  => $copies,
						'distinct_targets' => $targets,
					);
					$rows []          = $row;
				}
			}
		}
		native_measure_assert( 96 === count( $rows ), 'Shared-request matrix did not produce 96 rows.' );
		$files = 2;
		$bytes = filesize( $root . '/bootstrap.php' ) + filesize( $root . '/runtime.php' );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				++$files;
				$bytes += $file->getSize();
			}
		}
		$out          = array(
			'fixture'          => 'native-discovery-measure',
			'environment'      => array(
				'php'              => PHP_VERSION,
				'sapi'             => PHP_SAPI,
				'filesystem_cache' => 'warm local filesystem; one fresh PHP globals process per matrix row',
				'transport'        => 'controlled in-process wp_safe_remote_get; no network, credentials, or live site',
			),
			'source_inventory' => array(
				'runtime_php_files' => $files,
				'runtime_php_bytes' => $bytes,
				'inventory_only'    => true,
			),
			'measurements'     => $rows,
			'limitations'      => array( 'Timing is local contextual evidence, not host or network latency.', 'Fake transport and fake option database count deterministic runtime work only.' ),
		);
		$control_copy = $scratch . '/callback-control';
		native_measure_copy( $root, $control_copy );
		$out ['controls']                                      = array( 'callback_returning_null_repeated_plugin' => native_measure_child( array( $control_copy ), $scratch, 1, 'repeated', true ) );
		$out ['controls'] ['callback_revoked_repeated_plugin'] = native_measure_child( array( $control_copy ), $scratch, 1, 'repeated', true, 'plugin', true );
		$theme_controls                                        = array();
		foreach ( array( 'cold', 'repeated', 'refresh', 'install', 'changed' ) as $scenario ) {
			$theme_copy = $scratch . '/theme-control-' . $scenario;
			native_measure_copy( $root, $theme_copy );
			$theme_controls [ $scenario ] = native_measure_child( array( $theme_copy ), $scratch, 1, $scenario, false, 'theme' );
		}
		$out ['controls'] ['theme_1copy_1target'] = $theme_controls;
		native_measure_assert( $out ['controls'] ['callback_returning_null_repeated_plugin'] ['credential_callback_calls'] > 0, 'Callback-returning-null control did not invoke its resolver.' );
		native_measure_assert( 4 === $out ['controls'] ['callback_revoked_repeated_plugin'] ['credential_callback_calls'], 'Revoked callback control did not re-resolve before the second discovery.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write the measurement result as exact local JSON bytes after all scenarios have passed.
		file_put_contents( $root . '/.workspaces/evidence/native-discovery-measure.json', json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
		echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
	}
	if ( '--shared-worker' === ( $argv [1] ?? null ) ) {
		native_measure_shared_worker( $argv );
	} else {
		native_measure_main();
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
