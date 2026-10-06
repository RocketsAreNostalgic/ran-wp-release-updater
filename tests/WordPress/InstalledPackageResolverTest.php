<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\WordPress\InstalledPackageResolver;

final class InstalledPackageResolverTest extends TestCase {

	private string $root;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.1/installed-resolver-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $this->root . '/plugins', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $this->root . '/themes', 0700, true );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
		$GLOBALS['wp_version'] = '6.8.0';
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
	protected function tearDown(): void {
		$this->remove( $this->root ); }

	public function test_plugin_roots_mappings_and_passive_file_codes(): void {
		$plugin   = $this->file( 'plugins/renamed/main.php', $this->plugin_header() );
		$resolver = $this->resolver();
		self::assertSame( 'renamed/main.php', $resolver->resolve( $this->declaration( 'plugin', $plugin ) )['installed_package_identity'] ); // P01/P02.
		self::assertSame( 'plugin_root_level_unsupported', $resolver->resolve( $this->declaration( 'plugin', $this->file( 'plugins/root.php', $this->plugin_header() ) ) )['code'] ); // P03.
		self::assertSame( 'installed_file_missing', $resolver->resolve( $this->declaration( 'plugin', $this->root . '/plugins/missing.php' ) )['code'] );
		self::assertSame( 'installed_file_not_regular', $resolver->resolve( $this->declaration( 'plugin', $this->root . '/plugins/renamed' ) )['code'] ); // P04.
		self::assertSame( 'installed_file_not_regular', $resolver->resolve( $this->declaration( 'plugin', '/dev/null' ) )['code'] ); // P04 device.
		symlink( $plugin, $this->root . '/plugins/link.php' );
		self::assertSame( 'installed_file_symlink', $resolver->resolve( $this->declaration( 'plugin', $this->root . '/plugins/link.php' ) )['code'] ); // P05.
		self::assertSame( 'installed_file_outside_root', $resolver->resolve( $this->declaration( 'plugin', $this->file( 'outside/x.php', $this->plugin_header() ) ) )['code'] ); // P07.
	}

	public function test_registered_ancestor_symlink_equivalent_maps_and_ambiguous_maps(): void {
		$actual = $this->root . '/actual/slug';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $actual, 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $actual . '/main.php', $this->plugin_header() );
		$logical = $this->root . '/plugins/slug';
		symlink( $actual, $logical );
		$mapped = new InstalledPackageResolver( plugin_directory: $this->root . '/plugins', plugin_paths: array( $logical => $actual ), theme_directories: array() );
		self::assertSame( 'slug/main.php', $mapped->resolve( $this->declaration( 'plugin', $logical . '/main.php' ) )['installed_package_identity'] );
		self::assertSame( 'slug/main.php', $mapped->resolve( $this->declaration( 'plugin', $actual . '/main.php' ) )['installed_package_identity'] ); // P06.
		$ambiguous = new InstalledPackageResolver(
			$this->root . '/plugins',
			array(
				$logical                       => $actual,
				$this->root . '/plugins/other' => $actual,
			),
			array()
		);
		self::assertSame( 'installed_file_root_ambiguous', $ambiguous->resolve( $this->declaration( 'plugin', $actual . '/main.php' ) )['code'] ); // P08.
	}

	public function test_explicit_plugin_mapping_beats_generic_plugin_directory_for_logical_and_real_files(): void {
		$actual = $this->root . '/plugins/shared/foo';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $actual, 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $actual . '/main.php', $this->plugin_header() );
		$logical = $this->root . '/plugins/foo';
		symlink( $actual, $logical );
		$mapped = new InstalledPackageResolver( $this->root . '/plugins', array( $logical => $actual ), array() );
		self::assertSame( 'foo/main.php', $mapped->resolve( $this->declaration( 'plugin', $logical . '/main.php' ) )['installed_package_identity'] );
		self::assertSame( 'foo/main.php', $mapped->resolve( $this->declaration( 'plugin', $actual . '/main.php' ) )['installed_package_identity'] );

		$other = $this->root . '/plugins/other';
		symlink( $actual, $other );
		$ambiguous = new InstalledPackageResolver(
			$this->root . '/plugins',
			array(
				$logical => $actual,
				$other   => $actual,
			),
			array()
		);
		self::assertSame( 'installed_file_root_ambiguous', $ambiguous->resolve( $this->declaration( 'plugin', $actual . '/main.php' ) )['code'] );
	}

	public function test_symlinked_registered_roots_and_unregistered_internal_links(): void {
		$actual_plugins = $this->root . '/actual-plugins';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $actual_plugins . '/slug', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $actual_plugins . '/slug/main.php', $this->plugin_header() );
		$logical_plugins = $this->root . '/logical-plugins';
		symlink( $actual_plugins, $logical_plugins );
		$plugins = new InstalledPackageResolver( $logical_plugins, array(), array() );
		self::assertSame( 'slug/main.php', $plugins->resolve( $this->declaration( 'plugin', $logical_plugins . '/slug/main.php' ) )['installed_package_identity'] );
		self::assertSame( 'slug/main.php', $plugins->resolve( $this->declaration( 'plugin', $actual_plugins . '/slug/main.php' ) )['installed_package_identity'] );
		$actual_themes = $this->root . '/actual-themes';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $actual_themes . '/slug', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $actual_themes . '/slug/style.css', $this->theme_header() );
		$logical_themes = $this->root . '/logical-themes';
		symlink( $actual_themes, $logical_themes );
		$themes = new InstalledPackageResolver( '', array(), array( $logical_themes ) );
		self::assertSame( 'slug', $themes->resolve( $this->declaration( 'theme', $logical_themes . '/slug/style.css' ) )['installed_package_identity'] );
		self::assertSame( 'slug', $themes->resolve( $this->declaration( 'theme', $actual_themes . '/slug/style.css' ) )['installed_package_identity'] );
		$external = $this->root . '/external';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $external . '/plugin', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $external . '/plugin/main.php', $this->plugin_header() );
		symlink( $external . '/plugin', $this->root . '/plugins/inside' );
		self::assertSame( 'installed_file_outside_root', $this->resolver()->resolve( $this->declaration( 'plugin', $this->root . '/plugins/inside/main.php' ) )['code'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $external . '/theme', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $external . '/theme/style.css', $this->theme_header() );
		symlink( $external . '/theme', $this->root . '/themes/inside' );
		self::assertSame( 'installed_file_outside_root', $this->resolver()->resolve( $this->declaration( 'theme', $this->root . '/themes/inside/style.css' ) )['code'] );
	}

	public function test_parser_uses_bounded_normalized_header_values_only(): void {
		$header = "<?php /* Plugin Name: caf\xc3\xa9 */ trailing\rVersion: 1.0.0\rUpdate URI: https://github.com/acme/example\r";
		$result = PackageIdentityValidator::parse_header( $header . "\x00", 'plugin' );
		self::assertSame( 'installed_header_verified', $result['code'] );
		self::assertSame( 'café', $result['headers']['Name'] );
		self::assertSame( 'installed_header_ambiguous', PackageIdentityValidator::parse_header( $this->plugin_header() . "<?php /* Version: 1.0.0 */\n", 'plugin' )['code'] );
		self::assertSame( 'installed_header_missing', PackageIdentityValidator::parse_header( "<?php /* Plugin Name: Example */\n", 'plugin' )['code'] );
		self::assertSame( 'installed_header_invalid', PackageIdentityValidator::parse_header( "<?php /* Plugin Name: bad\x00 */\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n", 'plugin' )['code'] );
		self::assertSame( 'installed_header_missing', PackageIdentityValidator::parse_header( str_repeat( 'x', 8192 ) . "\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example", 'plugin' )['code'] );
	}

	public function test_path_traversal_and_later_header_colon_are_rejected_or_parsed_exactly(): void {
		$resolver = $this->resolver();
		foreach ( array( 'acme/.', 'acme/..', 'acme\\', 'acme\\.\\main.php', 'acme\\..\\main.php' ) as $suffix ) {
			self::assertSame( 'installed_file_invalid', $resolver->resolve( $this->declaration( 'plugin', $this->root . '/plugins/' . $suffix ) )['code'], $suffix );
		}
		$header = "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nSomething else: value\nUpdate URI: https://github.com/acme/example\n*/\n";
		self::assertSame( 'installed_header_verified', PackageIdentityValidator::parse_header( $header, 'plugin' )['code'] );
		$split_colon = "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI\n: https://github.com/acme/example\n*/\n";
		self::assertSame( 'installed_header_missing', PackageIdentityValidator::parse_header( $split_colon, 'plugin' )['code'] );
	}

	public function test_path_lexicon_accepts_posix_drive_qualified_and_unc_paths(): void {
		$method   = new \ReflectionMethod( InstalledPackageResolver::class, 'valid_path' );
		$resolver = $this->resolver();
		foreach ( array( '/srv/wordpress/wp-content/plugins/example/main.php', 'C:/WordPress/wp-content/plugins/example/main.php', 'D:\\WordPress\\wp-content\\themes\\example\\style.css', '//server/share/plugins/example/main.php', '\\\\server\\share\\plugins\\example\\main.php' ) as $path ) {
			self::assertTrue( $method->invoke( $resolver, $path ), $path );
		}
		foreach ( array( 'C:WordPress/wp-content/plugins/example/main.php', '/srv/wordpress/../outside/main.php', 'C:/WordPress/../outside/main.php', '/srv/wordpress//plugins/example/main.php', '/srv/wordpress/main.php/', 'C:/WordPress/main.php/', '//server', '//server/', '///server/share/plugins/example/main.php', '//server//share/plugins/example/main.php', '//server/share//plugins/example/main.php', '//server/share/plugins/example/main.php/', '//server/./plugins/example/main.php', '//server/share/../example/main.php', "//server/share/plugins/\x00main.php", "C:/WordPress/wp-content/plugins/example/\x00main.php", "C:/WordPress/wp-content/plugins/example/\x1fmain.php" ) as $path ) {
			self::assertFalse( $method->invoke( $resolver, $path ), $path );
		}
	}

	public function test_theme_identity_child_parent_nested_and_symlink_boundaries(): void {
		$child    = $this->file( 'themes/child/style.css', $this->theme_header( 'Template: parent' ) );
		$parent   = $this->file( 'themes/parent/style.css', $this->theme_header() );
		$resolver = $this->resolver();
		self::assertSame( 'child', $resolver->resolve( $this->declaration( 'theme', $child ) )['installed_package_identity'] ); // T01/T03.
		self::assertSame( 'parent', $resolver->resolve( $this->declaration( 'theme', $parent ) )['installed_package_identity'] ); // T04.
		self::assertSame( 'theme_nested_identity_unsupported', $resolver->resolve( $this->declaration( 'theme', $this->file( 'themes/group/theme/style.css', $this->theme_header() ) ) )['code'] ); // T05.
		self::assertSame( 'theme_header_file_invalid', $resolver->resolve( $this->declaration( 'theme', $this->file( 'themes/bad/theme.css', $this->theme_header() ) ) )['code'] ); // T06.
		$custom = $this->root . '/custom-themes';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
		mkdir( $custom . '/custom', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
		file_put_contents( $custom . '/custom/style.css', $this->theme_header() );
		self::assertSame( 'custom', ( new InstalledPackageResolver( '', array(), array( $custom ) ) )->resolve( $this->declaration( 'theme', $custom . '/custom/style.css' ) )['installed_package_identity'] ); // T02.
	}

	public function test_header_normalization_duplicates_requirements_and_read_mutation(): void {
		$lf       = $this->file( 'plugins/lf/main.php', $this->plugin_header( "\n" ) );
		$crlf     = $this->file( 'plugins/crlf/main.php', str_replace( "\n", "\r\n", $this->plugin_header( "\n" ) ) );
		$cr       = $this->file( 'plugins/cr/main.php', str_replace( "\n", "\r", $this->plugin_header( "\n" ) ) );
		$resolver = $this->resolver();
		self::assertSame( $resolver->resolve( $this->declaration( 'plugin', $lf ) )['headers'], $resolver->resolve( $this->declaration( 'plugin', $crlf ) )['headers'] ); // H01.
		self::assertSame( $resolver->resolve( $this->declaration( 'plugin', $lf ) )['headers'], $resolver->resolve( $this->declaration( 'plugin', $cr ) )['headers'] ); // H01 lone CR.
		self::assertSame( 'installed_header_ambiguous', $resolver->resolve( $this->declaration( 'plugin', $this->file( 'plugins/duplicate/main.php', $this->plugin_header() . "Plugin Name: Again\n" ) ) )['code'] ); // H02.
		self::assertSame( 'installed_header_missing', $resolver->resolve( $this->declaration( 'plugin', $this->file( 'plugins/missing/main.php', "<?php\n/* Version: 1.0.0 */" ) ) )['code'] ); // H03.
		self::assertSame( 'installed_header_invalid', $resolver->resolve( $this->declaration( 'plugin', $this->file( 'plugins/invalid-version/main.php', "<?php\n/*\nPlugin Name: Example\nVersion: broken\nUpdate URI: https://github.com/acme/example\n*/\n" ) ) )['code'] );
		self::assertSame( 'installed_header_verified', PackageIdentityValidator::parse_header( $this->plugin_header() . "\x00", 'plugin' )['code'] );
		self::assertSame( 'installed_header_invalid', PackageIdentityValidator::parse_header( $this->theme_header( 'Template: ../parent' ), 'theme' )['code'] );
		self::assertSame( 'installed_requirement_incompatible', $resolver->resolve( $this->declaration( 'plugin', $this->file( 'plugins/requirements/main.php', $this->plugin_header( "\nRequires PHP: 99.0\n" ) ) ) )['code'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
		$GLOBALS['wp_version'] = '6.9-beta1-60740';
		self::assertSame( 'installed_requirement_incompatible', $resolver->resolve( $this->declaration( 'plugin', $this->file( 'plugins/requires-newer-wordpress/main.php', $this->plugin_header( "\nRequires at least: 6.10\n" ) ) ) )['code'] );
		$changed  = $this->file( 'plugins/changed/main.php', $this->plugin_header() );
		$property = new \ReflectionProperty( InstalledPackageResolver::class, 'after_first_read' );
		$property->setValue(
			$resolver,
			static function ( string $file ): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
				file_put_contents( $file, "<?php\n/* Plugin Name: Changed\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n" );
			}
		);
		self::assertSame( 'installed_file_changed', $resolver->resolve( $this->declaration( 'plugin', $changed ) )['code'] ); // R01.
		$initial_drift = $this->file( 'plugins/initial-drift/main.php', $this->plugin_header() );
		$before        = new \ReflectionProperty( InstalledPackageResolver::class, 'before_first_stat' );
		$before->setValue(
			$resolver,
			static function ( string $file ): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Move the real fixture entry to exercise replacement identity; an abstract filesystem would change the scenario.
				rename( $file, $file . '.old' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
				file_put_contents( $file, "<?php\n/* Plugin Name: Replacement\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n" );
			}
		);
		self::assertSame( 'installed_file_changed', $resolver->resolve( $this->declaration( 'plugin', $initial_drift ) )['code'] );
	}

	public function test_installed_headers_permit_absent_requirements_and_reject_post_closing_duplicates(): void {
		$resolver = $this->resolver();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$relative = 'plugin' === $type ? 'plugins/optional/main.php' : 'themes/optional/style.css';
			$header   = 'plugin' === $type ? $this->plugin_header() : $this->theme_header();
			$file     = $this->file( $relative, $header );
			self::assertSame( 'installed_identity_verified', $resolver->resolve( $this->declaration( $type, $file ) )['code'] );

			$file = $this->file( str_replace( 'optional', 'duplicate', $relative ), $header . "Version: 1.0.0\n" );
			self::assertSame( 'installed_header_ambiguous', $resolver->resolve( $this->declaration( $type, $file ) )['code'] );
		}
	}

	public function test_deterministic_capture_failures_are_unreadable(): void {
		$file = $this->file( 'plugins/capture/main.php', $this->plugin_header() );
		foreach ( array(
			'lock'   => static fn (): bool => false,
			'rewind' => static fn (): bool => false,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read the native stream directly to preserve bounded reads and injected failure behavior.
			'read'   => static fn ( mixed $stream, int $read ): string|false => 2 === $read ? false : fread( $stream, 8192 ),
		) as $property => $seam ) {
			$resolver = $this->resolver();
			( new \ReflectionProperty( InstalledPackageResolver::class, $property ) )->setValue( $resolver, $seam );
			self::assertSame( 'installed_file_unreadable', $resolver->resolve( $this->declaration( 'plugin', $file ) )['code'] );
		}
	}

	private function resolver(): InstalledPackageResolver {
		return new InstalledPackageResolver( $this->root . '/plugins', array(), array( $this->root . '/themes' ) ); }
	/** @return array<string,mixed> */ private function declaration( string $type, string $file ): array {
		return array(
			'target_type'    => $type,
			'installed_file' => $file,
		); }
	private function plugin_header( string $suffix = "\n" ): string {
		return "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/" . $suffix; }
	private function theme_header( string $extra = '' ): string {
		return "/*\nTheme Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n" . $extra . "\n*/\n"; }
	private function file( string $relative, string $contents ): string {
		$path = $this->root . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
			mkdir( dirname( $path ), 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
		} file_put_contents( $path, $contents );
		return $path; }
	private function remove( string $path ): void {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				unlink( $path );
			} return;
		}
		$fixture_entries = scandir( $path );
		foreach ( $fixture_entries ? $fixture_entries : array() as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$this->remove( $path . '/' . $name );
			}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		} rmdir( $path ); }
}
