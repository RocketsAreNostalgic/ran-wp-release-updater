<?php

declare(strict_types=1);

/**
 * Selected-root production kernel.
 *
 * Composer autoload order cannot decide ownership when multiple physical
 * copies exist. The broker selects the root first; this entrypoint then loads
 * every lifecycle class from that root or fails closed if a loser already
 * defined one.
 */
$ran_wp_release_updater_runtime_files = array(
	'RAN\\WPReleaseUpdater\\V1\\Contract\\CanonicalUpdateUri' => 'src/Contract/CanonicalUpdateUri.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\IdentityDescriptor' => 'src/Contract/IdentityDescriptor.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseVersion' => 'src/Contract/ReleaseVersion.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\BindingRecord' => 'src/Contract/BindingRecord.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\ValidatedPackage' => 'src/Archive/ValidatedPackage.php',
	'RAN\\WPReleaseUpdater\\V1\\Archive\\PackageIdentityValidator' => 'src/Archive/PackageIdentityValidator.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\ReleaseOperationCoordinator' => 'src/WordPress/ReleaseOperationCoordinator.php',
	'RAN\\WPReleaseUpdater\\V1\\Contract\\AcquisitionReceipt' => 'src/Contract/AcquisitionReceipt.php',
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePluginUpdater' => 'src/WordPress/NativePluginUpdater.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubCredentialResolver' => 'src/Provider/GitHub/GitHubCredentialResolver.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubTemporaryArtifact' => 'src/Provider/GitHub/GitHubTemporaryArtifact.php',
	'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseAdapter' => 'src/Provider/GitHub/GitHubReleaseAdapter.php',
);

foreach ( $ran_wp_release_updater_runtime_files as $ran_wp_release_updater_runtime_class => $ran_wp_release_updater_runtime_relative ) {
	$ran_wp_release_updater_runtime_file = __DIR__ . '/' . $ran_wp_release_updater_runtime_relative;
	if ( class_exists( $ran_wp_release_updater_runtime_class, false ) ) {
		$ran_wp_release_updater_runtime_loaded = ( new ReflectionClass( $ran_wp_release_updater_runtime_class ) )->getFileName();
		if ( ! is_string( $ran_wp_release_updater_runtime_loaded ) || ! hash_equals( $ran_wp_release_updater_runtime_file, $ran_wp_release_updater_runtime_loaded ) ) {
			throw new RuntimeException( 'A lifecycle class was loaded outside the selected runtime root.' );
		}
		continue;
	}
	require_once $ran_wp_release_updater_runtime_file;
}

unset(
	$ran_wp_release_updater_runtime_class,
	$ran_wp_release_updater_runtime_file,
	$ran_wp_release_updater_runtime_files,
	$ran_wp_release_updater_runtime_loaded,
	$ran_wp_release_updater_runtime_relative
);
