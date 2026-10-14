<?php

/** @return array<string,string> */
function ran_wp_release_updater_test_create_fixtures( string $site, string $type ): array {
	$targets  = array(
		'managed-a' => array( 'Managed A', 'https://mixed-bulk.invalid/managed-a/repository' ),
		'ordinary'  => array( 'Ordinary', 'https://mixed-bulk.invalid/ordinary/repository' ),
		'managed-b' => array( 'Managed B', 'https://mixed-bulk.invalid/managed-b/repository' ),
	);
	$archives = array();
	foreach ( $targets as $slug => $target ) {
		[ $name, $uri ] = $target;
		if ( 'plugin' === $type ) {
			ran_wp_release_updater_test_fixture_plugin( $site, $slug, $name, $uri, '1.0.0' );
		} else {
			ran_wp_release_updater_test_fixture_theme( $site, $slug, $name, $uri, '1.0.0' );
		}
		$archives[ $slug ] = ran_wp_release_updater_test_fixture_archive( $site, $slug, $name, $uri, $type );
	}
	return $archives;
}
function ran_wp_release_updater_test_fixture_plugin( string $site, string $slug, string $name, string $uri, string $version ): void {
	$dir = $site . '/wp-content/plugins/' . $slug;
	if ( ! is_dir( $dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
		mkdir( $dir, 0700, true );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents(
		$dir . '/' . $slug . '.php',
		"<?php\n/*\nPlugin Name: {$name}\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.5\n*/\n// {$slug}-v{$version}\n"
	);
}
function ran_wp_release_updater_test_fixture_theme( string $site, string $slug, string $name, string $uri, string $version ): void {
	$dir = $site . '/wp-content/themes/' . $slug;
	if ( ! is_dir( $dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
		mkdir( $dir, 0700, true );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents(
		$dir . '/style.css',
		"/*\nTheme Name: {$name}\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.5\n*/\n"
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents(
		$dir . '/index.php',
		"<?php // {$slug}-v{$version}\n"
	);
}
function ran_wp_release_updater_test_fixture_archive( string $site, string $slug, string $name, string $uri, string $type ): string {
	$path = $site . '/wp-content/uploads/' . $type . '-' . $slug . '-2.0.0.zip';
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Archive create failed.' );
	}
	if ( 'plugin' === $type ) {
		$zip->addFromString(
			$slug . '/' . $slug . '.php',
			"<?php\n/*\nPlugin Name: {$name}\nVersion: 2.0.0\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.5\n*/\n// {$slug}-v2\n"
		);
	} else {
		$zip->addFromString(
			$slug . '/style.css',
			"/*\nTheme Name: {$name}\nVersion: 2.0.0\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.5\n*/\n"
		);
		$zip->addFromString(
			$slug . '/index.php',
			"<?php // {$slug}-v2\n"
		);
	}
	$zip->close();
	return $path;
}

return static fn ( string $site, string $type ): array => ran_wp_release_updater_test_create_fixtures( $site, $type );
