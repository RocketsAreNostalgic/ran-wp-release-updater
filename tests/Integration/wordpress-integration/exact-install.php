<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter;
use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;

/** Exercise the selected runtime, its GitHub HTTP transport and stock WordPress upgraders. */
function ran_wp_release_updater_test_install_exact( string $type, string $mode, string $direction, string $identity, string $archive, string $main_file, string $installed, string $source_root ): void {
	$no_target   = apply_filters( 'ran_wp_release_updater_install_exact_release_v1', null, $type, $identity, '301', 'v2.0.0', '2.0.0' );
	$transient   = 'plugin' === $type ? 'update_plugins' : 'update_themes';
	$header_file = 'plugin' === $type ? $main_file : dirname( $main_file ) . '/style.css';
	$slug        = basename( dirname( $main_file ) );
	$backup      = WP_CONTENT_DIR . '/upgrade-temp-backup/' . ( 'plugin' === $type ? 'plugins' : 'themes' ) . '/' . $slug;
	$read_bytes  = static function ( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the exact local package bytes in the disposable installation.
		$bytes = file_get_contents( $path );
		if ( ! is_string( $bytes ) ) {
			throw new RuntimeException( 'The proof could not read the installed package.' );
		}
		return $bytes;
	};
	$before      = $read_bytes( $main_file );
	$header      = PackageIdentityValidator::parse_header( $read_bytes( $header_file ), $type );
	$zip         = new ZipArchive();
	if ( true !== $zip->open( $archive ) ) {
		throw new RuntimeException( 'The proof could not open the release archive.' );
	}
	$release = $zip->getFromName( $slug . '/' . basename( $main_file ) );
	$zip->close();

	$early_refusal = in_array( $mode, array( 'exact-early-refusal', 'exact-early-lease-fail' ), true );
	$stale_file    = $backup . '/' . basename( $main_file );
	$stale_bytes   = $before . "\n// stale backup fixture\n";
	if ( $early_refusal ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed a stale backup before WordPress initializes its filesystem.
		if ( ! wp_mkdir_p( $backup ) || false === file_put_contents( $stale_file, $stale_bytes ) ) {
			throw new RuntimeException( 'Could not seed the early refusal backup.' );
		}
	}

	$observed = (object) array(
		'downloads'       => 0,
		'inspections'     => 0,
		'deletions'       => 0,
		'upgrader'        => null,
		'package'         => null,
		'completions'     => array(),
		'foreign_restore' => 0,
		'stdout_bytes'    => 0,
	);
	ran_wp_release_updater_test_exact_http( $archive, $mode, $observed );
	$standalone_lock_fault = in_array( $mode, array( 'exact-lease-fail', 'exact-early-lease-fail' ), true );
	$composition           = GitHubReleaseAdapter::compose_from_declaration(
		array(
			'installed_file'           => $header_file,
			'target_type'              => $type,
			'repository_locator'       => 'exact-fixture/offer-target',
			'repository_identity'      => '101',
			'channel'                  => 'stable',
			'update_policy'            => 'manual',
			'maximum_artifact_bytes'   => 52_428_800,
			'credential_resolver'      => null,
			'descriptor_admission'     => null,
			'operation_guard'          => $standalone_lock_fault ? null : static fn (): bool => true,
			'native_mutation_begin'    => $standalone_lock_fault ? null : static fn (): string => 'exact-install-proof',
			'native_mutation_complete' => $standalone_lock_fault ? null : static function ( string $owner, string $target_type, string $installed_identity, string $outcome ) use ( $observed, $mode ): void {
				$observed->completions[] = array( $owner, $target_type, $installed_identity, $outcome );
				if ( 'exact-host-fail' === $mode ) {
					throw new RuntimeException( 'Injected host completion failure.' );
				}
			},
		),
		array(
			'archive_root' => $slug,
			'header_file'  => basename( $header_file ),
		),
		$header['headers'] ?? array(),
		$identity,
		1,
		null
	);
	$updater               = $composition['native'];
	if ( ! $updater instanceof NativePackageUpdater ) {
		throw new RuntimeException( 'The exact-install fixture could not register its target.' );
	}

	ran_wp_release_updater_test_exact_wordpress_faults( $mode, $type, $identity, $backup, $observed );
	$alternate_destination  = 'exact-alternate-destination' === $mode ? ran_wp_release_updater_test_exact_alternate_destination( dirname( $main_file ), $observed ) : null;
	$translation            = 'exact-pending-translation' === $mode ? ran_wp_release_updater_test_exact_translation( $type, $observed ) : null;
	$credentials_refused    = 'exact-credentials-refused' === $mode;
	$theme_cache_registered = 'theme' === $type && 'exact-theme-cache' === $mode;
	if ( $theme_cache_registered ) {
		add_action( 'upgrader_process_complete', 'wp_clean_themes_cache', 9, 0 );
	}
	if ( 'exact-cache-file' === $mode ) {
		add_filter(
			'upgrader_post_install',
			static function ( mixed $response, array $extra ) use ( $type, $identity, $main_file ): mixed {
				if ( ( $extra[ $type ] ?? null ) === $identity ) {
					$cache_dir = dirname( $main_file ) . '/cache';
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulate a post-update hook writing a cache file inside the disposable package directory.
					if ( ! wp_mkdir_p( $cache_dir ) || false === file_put_contents( $cache_dir . '/generated.php', "<?php return true;\n" ) ) {
						throw new RuntimeException( 'Could not write the post-update cache file.' );
					}
				}
				return $response;
			},
			PHP_INT_MAX,
			2
		);
	}
	set_site_transient(
		$transient,
		(object) array(
			'last_checked' => time(),
			'checked'      => array( $identity => $installed ),
			'response'     => array(),
		)
	);
	$expected_version = 'exact-listed-change' === $mode ? '2.0.1' : '2.0.0';
	ob_start(
		static function ( string $bytes ) use ( $observed ): string {
			$observed->stdout_bytes += strlen( $bytes );
			return '';
		}
	);
	$caller_level = ob_get_level();
	if ( $credentials_refused ) {
		add_filter( 'request_filesystem_credentials', '__return_false' );
	}
	try {
		$report = apply_filters( 'ran_wp_release_updater_install_exact_release_v1', null, $type, $identity, '301', 'v2.0.0', $expected_version, 'exact-refuse' !== $mode && 'older' === $direction );
	} finally {
		if ( $credentials_refused ) {
			remove_filter( 'request_filesystem_credentials', '__return_false' );
		}
	}
	$buffer_preserved = ob_get_level() === $caller_level;
	$output           = $buffer_preserved ? ob_get_clean() : false;
	if ( ! is_array( $report ) ) {
		throw new RuntimeException( 'The exact-install target did not return a report.' );
	}

	$before_shutdown   = $read_bytes( $main_file );
	$native_record     = $updater->status()['last_native_outcome'];
	$owns_backup_hook  = $observed->upgrader instanceof WP_Upgrader && 100 === has_action( 'shutdown', array( $observed->upgrader, 'delete_temp_backup' ) );
	$owns_restore_hook = $observed->upgrader instanceof WP_Upgrader && 10 === has_action( 'shutdown', array( $observed->upgrader, 'restore_temp_backup' ) );
	$owns_finalizer    = PHP_INT_MAX === has_action( 'shutdown', array( $updater, 'finalize_pending_install' ) );
	$offer_absent      = null === ( get_site_transient( $transient )->response[ $identity ] ?? null );
	if ( is_string( $observed->package ) ) {
		// A copy held by another hook must still be stripped after the operation returns.
		set_site_transient( $transient, (object) array( 'response' => array( $identity => (object) array( 'package' => $observed->package ) ) ) );
	}
	$stored_offer       = get_site_transient( $transient )->response[ $identity ] ?? null;
	$backup_present     = is_dir( $backup );
	$completions_before = $observed->completions;
	$deletions_before   = $observed->deletions;
	$private_before     = glob( sys_get_temp_dir() . '/ran-wp-release-updater-*' );
	$expected_code      = match ( $mode ) {
		'exact-success', 'exact-theme-cache', 'exact-pending-translation', 'exact-cache-file' => 'update_completed',
		'exact-refuse'                => 'release_older',
		'exact-listed-change'         => 'candidate_descriptor_mismatch',
		'exact-changed'               => 'remote_release_changed',
		'exact-failure'               => 'backup_restore_pending',
		'exact-alternate-destination', 'exact-credentials-refused', 'exact-early-refusal' => 'unverified_install_result',
		'exact-interrupted'           => 'unexpected_failure',
		'exact-early-lease-fail', 'exact-lease-fail' => 'lease_release_failed',
		'exact-archive-fail'          => 'archive_cleanup_failed',
		'exact-host-fail'             => 'host_completion_failed',
		default                       => throw new RuntimeException( 'Invalid exact-install scenario.' ),
	};
	$pending                  = in_array( $mode, array( 'exact-failure', 'exact-interrupted', 'exact-alternate-destination' ), true );
	$refused                  = in_array( $mode, array( 'exact-refuse', 'exact-listed-change' ), true );
	$changed                  = 'exact-changed' === $mode;
	$finalized                = ! $pending && ! $refused && ! $changed && ! $early_refusal && ! $credentials_refused;
	$finalization             = $pending ? null : array(
		'commit'          => $finalized ? 'completed' : 'not_attempted',
		'host_completion' => 'exact-host-fail' === $mode ? 'failed' : ( $finalized && ! $standalone_lock_fault ? 'completed' : 'not_applicable' ),
		'lease'           => $standalone_lock_fault ? 'failed' : 'not_held',
		'archive_cleanup' => 'exact-archive-fail' === $mode ? 'failed' : 'clean',
	);
	$success                  = in_array( $mode, array( 'exact-success', 'exact-theme-cache', 'exact-pending-translation', 'exact-cache-file' ), true );
	$host_completed           = $finalized && ! $standalone_lock_fault ? array(
		array( 'exact-install-proof', $type, $identity, 'exact-archive-fail' === $mode ? 'uncertain' : 'verified_success' ),
	) : array();
	$core_restoring           = 'exact-failure' === $mode || $early_refusal;
	$restoring                = 'exact-failure' === $mode;
	$backup_moved             = $finalized || $pending;
	$keeps_old_bytes          = $refused || $changed || $early_refusal || $credentials_refused;
	$expected_installed_bytes = $keeps_old_bytes ? $before : $release;
	$expected_status          = $success ? 'installed' : ( $pending ? 'pending' : ( $refused ? 'refused' : 'failed' ) );
	$checks                   = array(
		'unsupported_default'   => null === $no_target,
		'one_zip_body'          => ( $refused ? 0 : 1 ) === $observed->downloads,
		'fresh_metadata'        => ( $refused || $credentials_refused ? 1 : 2 ) === $observed->inspections,
		'installed_runtime'     => ( new ReflectionClass( $updater ) )->getFileName() === $source_root . '/src/WordPress/NativePackageUpdater.php',
		'safe_report'           => array(
			'status'               => $expected_status,
			'code'                 => $expected_code,
			'version'              => '2.0.0',
			'relationship'         => $direction,
			'finalization_pending' => $pending,
			'finalization'         => $finalization,
		) === $report,
		'offer_removed'         => $offer_absent && null === $stored_offer,
		'quiet_response'        => $buffer_preserved && '' === $output && 0 === $observed->stdout_bytes,
		'bytes_before'          => $before_shutdown === $expected_installed_bytes && ( $keeps_old_bytes || $before !== $before_shutdown ),
		'commit_before'         => $completions_before === $host_completed && ( $success ? 'verified_success' === ( $native_record['outcome'] ?? null ) : 'verified_success' !== ( $native_record['outcome'] ?? null ) ),
		'shutdown_ownership'    => $pending === $owns_finalizer && $core_restoring === $owns_restore_hook && ( $backup_moved || $early_refusal ) === $owns_backup_hook && 0 === $deletions_before,
		'backup_present_before' => ( $backup_moved || $early_refusal ) === $backup_present,
		'stale_backup_before'   => ! $early_refusal || ( is_file( $stale_file ) && $read_bytes( $stale_file ) === $stale_bytes ),
		'private_custody'       => ( $pending || 'exact-archive-fail' === $mode ) ? is_array( $private_before ) && 1 === count( $private_before ) : array() === $private_before,
	);
	if ( $observed->upgrader instanceof WP_Upgrader ) {
		$instance                            = $observed->upgrader;
		$checks['native_upgrader_class']     = get_class( $instance ) === ( 'plugin' === $type ? Plugin_Upgrader::class : Theme_Upgrader::class );
		$checks['temporary_filters_removed'] =
			false === has_filter( 'upgrader_clear_destination', array( $instance, 'plugin' === $type ? 'delete_old_plugin' : 'delete_old_theme' ) )
			&& false === has_filter( 'upgrader_source_selection', array( $instance, 'check_package' ) )
			&& false === has_filter( 'upgrader_pre_install', array( $instance, 'current_before' ) )
			&& false === has_filter( 'upgrader_post_install', array( $instance, 'current_after' ) );
	}
	$checks['theme_cache_hook_preserved'] = ( $theme_cache_registered ? 9 : false ) === has_action( 'upgrader_process_complete', 'wp_clean_themes_cache' );
	if ( null !== $translation ) {
		$checks['translation_left_pending'] = array( false ) === $translation->decisions
			&& in_array( $translation->offer->package, array_column( wp_get_translation_updates(), 'package' ), true )
			&& remove_filter( 'async_update_translation', $translation->observe, PHP_INT_MAX )
			&& false === has_filter( 'async_update_translation' );
	}
	if ( $credentials_refused ) {
		$checks['subsequent_theme_update'] = ran_wp_release_updater_test_exact_follow_up_theme( dirname( $archive ), $read_bytes );
	}
	if ( $finalized ) {
		$updater->finalize_pending_install();
		$checks['duplicate_finalize_noop'] = $native_record === $updater->status()['last_native_outcome'] && $completions_before === $observed->completions;
	}
	add_action(
		'shutdown',
		static function () use ( $checks, $type, $identity, $backup, $restoring, $expected_installed_bytes, $read_bytes, $main_file, $before, $observed, $host_completed, $pending, $backup_moved, $updater, $report, $early_refusal, $stale_file, $stale_bytes, $alternate_destination ): void {
			$after       = $read_bytes( $main_file );
			$is_active   = 'plugin' === $type ? is_plugin_active( $identity ) : get_stylesheet() === $identity;
			$completions = $pending ? array( array( 'exact-install-proof', $type, $identity, 'uncertain' ) ) : $host_completed;
			$checks     += array(
				'active_preserved'   => $is_active,
				'rollback_preserved' => ( $restoring ? $before : $expected_installed_bytes ) === $after,
				'shutdown_cleanup'   => is_dir( $backup ) === $early_refusal && array() === glob( sys_get_temp_dir() . '/ran-wp-release-updater-*' ),
				'stale_backup_after' => ! $early_refusal || ( is_file( $stale_file ) && $read_bytes( $stale_file ) === $stale_bytes ),
				'single_completion'  => $completions === $observed->completions,
				'backup_deletions'   => ( $backup_moved && ! $restoring ? 1 : 0 ) === $observed->deletions,
				'other_upgrader'     => 1 === $observed->foreign_restore,
			);
			if ( null !== $alternate_destination ) {
				$checks['alternate_tree_matches'] = is_dir( $alternate_destination ) && $read_bytes( $alternate_destination . '/' . basename( $main_file ) ) === $read_bytes( $main_file );
			}
			$evidence = array(
				'pass'         => ! in_array( false, $checks, true ),
				'checks'       => $checks,
				'report'       => $report,
				'outcome'      => $updater->status()['last_native_outcome'],
				'downloads'    => $observed->downloads,
				'stdout_bytes' => $observed->stdout_bytes,
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact evidence bytes for the disposable integration fixture.
			file_put_contents( (string) getenv( 'RAN_WP_RELEASE_UPDATER_OFFER_OUTPUT' ), json_encode( $evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) );
		},
		PHP_INT_MAX
	);
}

/** Report a byte-identical package under the same slug at an alternate path. */
function ran_wp_release_updater_test_exact_alternate_destination( string $destination, stdClass $observed ): string {
	$alternate = WP_CONTENT_DIR . '/alternate-install/' . basename( $destination );
	add_filter(
		'upgrader_install_package_result',
		static function ( mixed $result ) use ( $alternate, $destination, $observed ): mixed {
			$upgrader = $observed->upgrader;
			if ( ! $upgrader instanceof WP_Upgrader || ! is_array( $upgrader->result ) || $result !== $upgrader->result || untrailingslashit( $upgrader->result['destination'] ) !== $destination ) {
				return $result;
			}
			if ( ! wp_mkdir_p( $alternate ) || true !== copy_dir( $destination, $alternate ) ) {
				throw new RuntimeException( 'Could not seed the byte-identical alternate destination.' );
			}
			$upgrader->result['destination'] = trailingslashit( $alternate );
			return $upgrader->result;
		},
		9
	);
	return $alternate;
}

/** Present a pending offer to Core's real translation callback after it refreshes its package caches. */
function ran_wp_release_updater_test_exact_translation( string $type, stdClass $observed ): stdClass {
	$state          = (object) array(
		'offer'     => (object) array(
			'type'       => $type,
			'slug'       => 'offer-target',
			'language'   => 'fr_FR',
			'version'    => '2.0.0',
			'autoupdate' => true,
			'package'    => 'https://downloads.wordpress.org/translation/' . $type . '/offer-target/2.0.0/fr_FR.zip',
		),
		'decisions' => array(),
	);
	$state->observe = static function ( bool $update, stdClass $offer ) use ( $state ): bool {
		if ( $offer->package === $state->offer->package ) {
			$state->decisions[] = $update;
		}
		return $update;
	};
	add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
	add_action( 'upgrader_process_complete', array( Language_Pack_Upgrader::class, 'async_upgrade' ), 20, 1 );
	add_action(
		'upgrader_process_complete',
		static function ( WP_Upgrader $upgrader ) use ( $observed, $state ): void {
			if ( $upgrader !== $observed->upgrader ) {
				return;
			}
			set_site_transient( 'update_core', (object) array( 'translations' => array( $state->offer ) ) );
			add_filter( 'async_update_translation', $state->observe, PHP_INT_MAX, 2 );
		},
		19,
		1
	);
	return $state;
}

/** @param (Closure(string):string) $read_bytes */
function ran_wp_release_updater_test_exact_follow_up_theme( string $archive_directory, Closure $read_bytes ): bool {
	$slug      = 'follow-up-theme';
	$directory = get_theme_root() . '/' . $slug;
	$archive   = $archive_directory . '/follow-up-theme.zip';
	$before    = "/*\nTheme Name: Follow Up Theme\nVersion: 1.0.0\n*/\n";
	$after     = str_replace( '1.0.0', '2.0.0', $before );
	$zip       = new ZipArchive();
	if ( ! wp_mkdir_p( $directory ) || true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Could not prepare the follow-up theme.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed the exact pre-upgrade bytes inside the disposable WordPress site.
	file_put_contents( $directory . '/style.css', $before );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed the required classic theme entrypoint in the disposable WordPress site.
	file_put_contents( $directory . '/index.php', '<?php' );
	$zip->addFromString( $slug . '/style.css', $after );
	$zip->addFromString( $slug . '/index.php', '<?php' );
	$zip->close();
	set_site_transient( 'update_themes', (object) array( 'response' => array( $slug => array( 'package' => $archive ) ) ) );
	$skin = new class() extends Theme_Upgrader_Skin {
		public function feedback( $feedback, ...$args ): void {
			unset( $feedback, $args );
		}
	};
	ob_start();
	try {
		$result = ( new Theme_Upgrader( $skin ) )->upgrade( $slug );
	} finally {
		ob_end_clean();
	}
	return true === $result && $after === $read_bytes( $directory . '/style.css' );
}

/** Stream the actual fixture ZIP only at GitHub's asset-body boundary. */
function ran_wp_release_updater_test_exact_http( string $archive, string $mode, stdClass $observed ): void {
	$api     = 'https://api.github.com/repos/exact-fixture/offer-target';
	$release = array(
		'id'         => 301,
		'tag_name'   => 'v2.0.0',
		'draft'      => false,
		'prerelease' => false,
		'immutable'  => true,
		'html_url'   => 'https://github.com/exact-fixture/offer-target/releases/tag/v2.0.0',
		'assets'     => array(
			array(
				'id'     => 401,
				'name'   => basename( $archive ),
				'size'   => filesize( $archive ),
				'state'  => 'uploaded',
				'digest' => 'sha256:' . hash_file( 'sha256', $archive ),
			),
		),
	);
	add_filter(
		'pre_http_request',
		static function ( mixed $pre, array $arguments, string $url ) use ( $api, $archive, $mode, $release, $observed ): array|WP_Error {
			unset( $pre );
			$filename = null;
			if ( $api === $url ) {
				$body = wp_json_encode( array( 'id' => 101 ) );
			} elseif ( $api . '/releases/301' === $url ) {
				++$observed->inspections;
				$body = wp_json_encode( $release );
			} elseif ( $api . '/commits/v2.0.0' === $url ) {
				$body = str_repeat( 'exact-changed' === $mode && 2 === $observed->inspections ? 'b' : 'a', 40 );
			} elseif ( $api . '/releases/assets/401' === $url && true === ( $arguments['stream'] ?? false ) && is_string( $arguments['filename'] ?? null ) && copy( $archive, $arguments['filename'] ) ) {
				++$observed->downloads;
				$filename = $arguments['filename'];
				$body     = '';
			} else {
				return new WP_Error( 'exact_fixture_denied', 'Unexpected HTTP request in the exact install fixture.' );
			}
			return array(
				'body'     => $body,
				'headers'  => array(),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'filename' => $filename,
			);
		},
		PHP_INT_MAX,
		3
	);
}

/** Observe the exact upgrader and inject failures only at its native boundaries. */
function ran_wp_release_updater_test_exact_wordpress_faults( string $mode, string $type, string $identity, string $backup, stdClass $observed ): void {
	add_filter(
		'upgrader_pre_download',
		static function ( mixed $reply, string $package, WP_Upgrader $upgrader, array $extra ) use ( $mode, $type, $identity, $observed ): mixed {
			if ( ( $extra[ $type ] ?? null ) !== $identity ) {
				return $reply;
			}
			$observed->upgrader = $upgrader;
			$observed->package  = $package;
			if ( 'exact-archive-fail' === $mode && is_string( $reply ) ) {
				$sentinel = dirname( $reply ) . '/keep-until-shutdown';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Make the owned archive directory non-empty to inject real native cleanup failure.
				file_put_contents( $sentinel, 'fixture' );
				add_action(
					'shutdown',
					static function () use ( $sentinel ): void {
						wp_delete_file( $sentinel );
					},
					20
				);
			}
			return $reply;
		},
		PHP_INT_MAX,
		4
	);

	if ( 'exact-failure' === $mode ) {
		add_filter(
			'upgrader_post_install',
			static function ( mixed $response, array $extra ) use ( $type, $identity ): mixed {
				if ( ( $extra[ $type ] ?? null ) !== $identity ) {
					return $response;
				}
				return new WP_Error( 'exact_fixture_post_copy_failure', 'Injected after the replacement was copied.' );
			},
			PHP_INT_MAX,
			2
		);
	}
	if ( in_array( $mode, array( 'exact-early-refusal', 'exact-early-lease-fail' ), true ) ) {
		add_filter(
			'upgrader_pre_install',
			static function ( mixed $response, array $extra ) use ( $mode, $type, $identity ): mixed {
				if ( ( $extra[ $type ] ?? null ) !== $identity ) {
					return $response;
				}
				if ( 'exact-early-lease-fail' === $mode ) {
					add_filter(
						'query',
						static fn ( string $sql ): string => str_starts_with( $sql, 'DELETE ' ) && str_contains( $sql, 'auto_updater.lock' ) ? $sql . ' AND 1 = 0' : $sql
					);
				}
				return new WP_Error( 'exact_fixture_pre_install_refusal', 'Refused before Core moves the backup.' );
			},
			0,
			2
		);
	}

	// A foreign upgrader's restore must not block this invocation or be disarmed by it.
	$other = new class( new WP_Upgrader_Skin(), $observed ) extends WP_Upgrader {
		public function __construct( WP_Upgrader_Skin $skin, private stdClass $observation ) {
			parent::__construct( $skin );
		}

		public function restore_temp_backup( $temp_restores = array() ): bool {
			unset( $temp_restores );
			++$this->observation->foreign_restore;
			return true;
		}
	};
	add_action(
		'shutdown',
		static function () use ( $other ): void {
			$other->restore_temp_backup();
		},
		10,
		0
	);

	add_action(
		'upgrader_process_complete',
		static function ( WP_Upgrader $upgrader ) use ( $mode, $backup, $observed ): void {
			if ( $upgrader !== $observed->upgrader ) {
				return;
			}
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Observe native backup deletion at Core's direct-filesystem shutdown boundary.
			$GLOBALS['wp_filesystem'] = new class( $backup, $observed ) extends WP_Filesystem_Direct {
				public function __construct( private string $backup_path, private stdClass $observation ) {
					parent::__construct( null );
				}

				public function delete( $file, $recursive = false, $type = false ) {
					if ( rtrim( $file, '/\\' ) === $this->backup_path ) {
						++$this->observation->deletions;
					}
					return parent::delete( $file, $recursive, $type );
				}
			};
			if ( 'exact-interrupted' === $mode ) {
				throw new RuntimeException( 'Injected interruption before the native upgrader returns.' );
			}
			if ( 'exact-lease-fail' === $mode ) {
				add_filter(
					'query',
					static fn ( string $sql ): string => str_starts_with( $sql, 'DELETE ' ) && str_contains( $sql, 'auto_updater.lock' ) ? $sql . ' AND 1 = 0' : $sql
				);
			}
		},
		PHP_INT_MAX
	);
}
