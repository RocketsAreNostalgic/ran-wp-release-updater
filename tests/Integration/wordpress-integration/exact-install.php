<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter;
use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;
use RAN\WPReleaseUpdater\V1\WordPress\StagedPackageManifest;

/** Exercise the selected runtime, its GitHub HTTP transport and stock WordPress upgraders. */
function ran_wp_release_updater_test_install_exact( string $type, string $mode, string $direction, string $identity, string $archive, string $main_file, string $installed, string $source_root ): void {
	$no_target           = apply_filters( 'ran_wp_release_updater_install_exact_release_v1', null, $type, $identity, '301', 'v2.0.0', '2.0.0' );
	$transient           = 'plugin' === $type ? 'update_plugins' : 'update_themes';
	$header_file         = 'plugin' === $type ? $main_file : dirname( $main_file ) . '/style.css';
	$slug                = basename( dirname( $main_file ) );
	$backup              = WP_CONTENT_DIR . '/upgrade-temp-backup/' . ( 'plugin' === $type ? 'plugins' : 'themes' ) . '/' . $slug;
	$maintenance         = ABSPATH . '.maintenance';
	$foreign_maintenance = 'exact-preexisting-maintenance' === $mode;
	if ( $foreign_maintenance ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed a foreign maintenance marker inside the disposable installation.
		file_put_contents( $maintenance, '<?php $upgrading = 1; // fixture-owned marker' );
	}
	$read_bytes = static function ( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the exact local package bytes in the disposable installation.
		$bytes = file_get_contents( $path );
		if ( ! is_string( $bytes ) ) {
			throw new RuntimeException( 'The proof could not read the installed package.' );
		}
		return $bytes;
	};
	$before     = $read_bytes( $main_file );
	$header     = PackageIdentityValidator::parse_header( $read_bytes( $header_file ), $type );
	$zip        = new ZipArchive();
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
	$composition = GitHubReleaseAdapter::compose_from_declaration(
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
			'operation_guard'          => static fn (): bool => true,
			'native_mutation_begin'    => static fn (): string => 'exact-install-proof',
			'native_mutation_complete' => static function ( string $owner, string $target_type, string $installed_identity, string $outcome ) use ( $observed, $mode ): void {
				$observed->completions[] = array( $owner, $target_type, $installed_identity, $outcome );
				if ( 'exact-lease-fail' === $mode ) {
					add_filter(
						'query',
						static fn ( string $sql ): string => str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'ran_wp_release_updater_target_v1_' ) ? $sql . ' AND 1 = 0' : $sql
					);
				}
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
	$updater     = $composition['native'];
	if ( ! $updater instanceof NativePackageUpdater ) {
		throw new RuntimeException( 'The exact-install fixture could not register its target.' );
	}

	$backup_fault = ran_wp_release_updater_test_exact_backup_fault( $mode, $backup, $main_file );
	ran_wp_release_updater_test_exact_wordpress_faults( $mode, $type, $identity, $backup, $observed, $backup_fault );
	$alternate_destination  = 'exact-alternate-destination' === $mode ? ran_wp_release_updater_test_exact_alternate_destination( dirname( $main_file ), $observed ) : null;
	$copy_fallback          = str_starts_with( $mode, 'exact-copy-' )
		? ran_wp_release_updater_test_exact_copy_fallback( $mode, $backup, $main_file, $observed )
		: null;
	$recovery_fault         = str_starts_with( $mode, 'exact-recovery-' )
		? ran_wp_release_updater_test_exact_recovery_fault( substr( $mode, strlen( 'exact-recovery-' ) ), $backup, $main_file, $archive, $maintenance, $observed )
		: null;
	$translation            = 'exact-pending-translation' === $mode ? ran_wp_release_updater_test_exact_translation( $type, $observed ) : null;
	$credentials_refused    = 'exact-credentials-refused' === $mode;
	$theme_cache_registered = 'theme' === $type && $foreign_maintenance;
	if ( $theme_cache_registered ) {
		add_action( 'upgrader_process_complete', 'wp_clean_themes_cache', 9, 0 );
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

	if ( null !== $copy_fallback ) {
		ran_wp_release_updater_test_exact_copy_evidence( $copy_fallback, $observed, $report, $buffer_preserved && '' === $output, $read_bytes( $main_file ) === $release );
		return;
	}

	if ( null !== $recovery_fault ) {
		ran_wp_release_updater_test_exact_recovery_evidence(
			$recovery_fault,
			$observed,
			$before,
			$read_bytes,
			$report,
			$buffer_preserved && '' === $output
		);
		return;
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
		'exact-success'       => 'update_completed',
		'exact-refuse'        => 'release_older',
		'exact-listed-change' => 'candidate_descriptor_mismatch',
		'exact-changed'       => 'remote_release_changed',
		'exact-failure', 'exact-rollback-error', 'exact-rollback-throw', 'exact-rollback-host-file', 'exact-rollback-backup-mapping', 'exact-rollback-destination-mapping', 'exact-rollback-parent' => 'backup_restore_pending',
		'exact-delete-fail', 'exact-delete-mapping' => 'backup_cleanup_failed',
		'exact-alternate-destination' => 'unverified_install_result',
		'exact-interrupted' => 'unexpected_failure',
		'exact-post-install-throw' => 'unexpected_failure',
		'exact-preexisting-maintenance' => 'update_completed',
		'exact-pending-translation' => 'update_completed',
		'exact-credentials-refused' => 'unverified_install_result',
		'exact-early-refusal' => 'unverified_install_result',
		'exact-early-lease-fail' => 'lease_release_failed',
		'exact-commit-fail' => 'install_commit_failed',
		'exact-archive-fail'  => 'archive_cleanup_failed',
		'exact-lease-fail'    => 'lease_release_failed',
		'exact-host-fail'     => 'host_completion_failed',
		default              => throw new RuntimeException( 'Invalid exact-install scenario.' ),
	};
	$core_pending             = null !== $backup_fault || in_array( $mode, array( 'exact-failure', 'exact-delete-fail', 'exact-interrupted', 'exact-alternate-destination' ), true );
	$preserved_backup         = $early_refusal || null !== $backup_fault || in_array( $mode, array( 'exact-interrupted', 'exact-alternate-destination' ), true );
	$orphaned_backup          = 'exact-post-install-throw' === $mode;
	$pending                  = $core_pending || $orphaned_backup;
	$refused                  = in_array( $mode, array( 'exact-refuse', 'exact-listed-change' ), true );
	$changed                  = 'exact-changed' === $mode;
	$finalized                = ! $pending && ! $refused && ! $changed && ! $early_refusal && ! $credentials_refused;
	$commit_failed            = 'exact-commit-fail' === $mode;
	$finalization             = $pending ? null : array(
		'commit'          => $finalized ? ( $commit_failed ? 'failed' : 'completed' ) : 'not_attempted',
		'host_completion' => 'exact-host-fail' === $mode ? 'failed' : ( $finalized ? 'completed' : 'not_applicable' ),
		'lease'           => in_array( $mode, array( 'exact-lease-fail', 'exact-early-lease-fail' ), true ) ? 'failed' : 'released',
		'archive_cleanup' => 'exact-archive-fail' === $mode ? 'failed' : 'clean',
	);
	$success                  = in_array( $mode, array( 'exact-success', 'exact-preexisting-maintenance', 'exact-pending-translation' ), true );
	$host_completed           = $finalized ? array(
		array( 'exact-install-proof', $type, $identity, $commit_failed || 'exact-archive-fail' === $mode ? 'uncertain' : 'verified_success' ),
	) : array();
	$core_restoring           = 'exact-failure' === $mode || $early_refusal || str_starts_with( $mode, 'exact-rollback-' );
	$restoring                = 'exact-failure' === $mode || $orphaned_backup;
	$deleted_early            = $finalized || 'exact-delete-fail' === $mode;
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
		'shutdown_ownership'    => $pending === $owns_finalizer && $core_restoring === $owns_restore_hook && ( $core_pending || $early_refusal ) === $owns_backup_hook && ( $deleted_early ? 1 : 0 ) === $deletions_before,
		'backup_present_before' => ( $pending || $early_refusal ) === $backup_present,
		'stale_backup_before'   => ! $early_refusal || ( is_file( $stale_file ) && $read_bytes( $stale_file ) === $stale_bytes ),
		'maintenance_before'    => ( $foreign_maintenance || $orphaned_backup ) === is_file( $maintenance ),
		'private_custody'       => ( $pending || 'exact-archive-fail' === $mode ) ? is_array( $private_before ) && 1 === count( $private_before ) : array() === $private_before,
	);
	if ( $observed->upgrader instanceof WP_Upgrader ) {
		$instance                            = $observed->upgrader;
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
		static function () use ( $checks, $type, $mode, $identity, $backup, $maintenance, $foreign_maintenance, $restoring, $expected_installed_bytes, $read_bytes, $main_file, $before, $observed, $host_completed, $pending, $updater, $report, $early_refusal, $stale_file, $stale_bytes, $backup_fault, $preserved_backup, $alternate_destination ): void {
			$after       = $read_bytes( $backup_fault->installed_file ?? $main_file );
			$is_active   = 'plugin' === $type ? is_plugin_active( $identity ) : get_stylesheet() === $identity;
			$completions = $pending ? array( array( 'exact-install-proof', $type, $identity, 'uncertain' ) ) : $host_completed;
			$checks     += array(
				'active_preserved'   => $is_active,
				'rollback_preserved' => ( $restoring ? $before : $expected_installed_bytes ) === $after,
				'shutdown_cleanup'   => is_dir( $backup ) === $preserved_backup && array() === glob( sys_get_temp_dir() . '/ran-wp-release-updater-*' ),
				'stale_backup_after' => ! $early_refusal || ( is_file( $stale_file ) && $read_bytes( $stale_file ) === $stale_bytes ),
				'single_completion'  => $completions === $observed->completions,
				'backup_deletions'   => ( 'exact-delete-fail' === $mode ? 2 : count( $host_completed ) ) === $observed->deletions,
				'maintenance_after'  => $foreign_maintenance ? '<?php $upgrading = 1; // fixture-owned marker' === $read_bytes( $maintenance ) : ! is_file( $maintenance ),
				'other_upgrader'     => 1 === $observed->foreign_restore,
			);
			if ( $preserved_backup && ! $early_refusal ) {
				$backup_file                      = $backup . '/' . basename( $main_file );
				$checks['owned_backup_preserved'] = is_file( $backup_file ) && $read_bytes( $backup_file ) === $before;
			}
			if ( null !== $backup_fault ) {
				$foreign_file = $backup_fault->foreign_file;
				$checks      += array(
					'foreign_location_preserved' => null === $foreign_file || ( is_file( $foreign_file ) && $read_bytes( $foreign_file ) === $backup_fault->foreign_bytes ),
					'rollback_attempts'          => ( in_array( $mode, array( 'exact-rollback-error', 'exact-rollback-throw' ), true ) ? 1 : 0 ) === $backup_fault->attempts,
				);
			}
			if ( null !== $alternate_destination ) {
				$manifest                         = new StagedPackageManifest();
				$checks['alternate_tree_matches'] = is_dir( $alternate_destination ) && $manifest->snapshot( $alternate_destination ) === $manifest->snapshot( dirname( $main_file ) );
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

/** Drive selected moves through Core's own copy fallback and record the original trees. */
function ran_wp_release_updater_test_exact_copy_fallback( string $mode, string $backup, string $main_file, stdClass $observed ): stdClass {
	$destination = dirname( $main_file );
	$slug        = basename( $destination );
	$incoming    = str_starts_with( $mode, 'exact-copy-incoming-' );
	$zero_inode  = str_starts_with( $mode, 'exact-copy-zero-inode-' );
	$restores    = in_array( $mode, array( 'exact-copy-nested', 'exact-copy-zero-inode-distinct' ), true );
	$preexisting = $zero_inode || ( ! $restores && ! $incoming );
	$entry       = match ( true ) {
		'exact-copy-directory-collision' === $mode => $slug . '/readme.txt',
		'exact-copy-file-collision' === $mode      => $slug,
		'exact-copy-nested' === $mode              => 'nested/' . $slug . '/readme.txt',
		$incoming || $zero_inode || 'exact-copy-zero-slug' === $mode => 'original-only.txt',
		default => throw new RuntimeException( 'Unexpected copy fallback fixture.' ),
	};
	$marker = $destination . '/' . $entry;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed an installed entry before WordPress initializes its filesystem.
	if ( ! wp_mkdir_p( dirname( $marker ) ) || false === file_put_contents( $marker, 'Installed payload for copy fallback' ) ) {
		throw new RuntimeException( 'Could not seed the installed copy fallback entry.' );
	}
	if ( $zero_inode ) {
		$GLOBALS['ran_wp_release_updater_test_zero_inode_backup'] = $backup;
		require_once __DIR__ . '/exact-install-stat.php';
	}
	$manifest = new StagedPackageManifest();
	$original = $manifest->snapshot( $destination );
	if ( null === $original ) {
		throw new RuntimeException( 'Could not read the original copy fallback tree.' );
	}
	if ( $preexisting ) {
		if ( ! wp_mkdir_p( $backup ) ) {
			throw new RuntimeException( 'Could not create the prior copy fallback backup.' );
		}
		if ( 'exact-copy-zero-inode-identical' === $mode ) {
			foreach ( array_keys( $original['files'] ) as $file ) {
				if ( ! copy( $destination . '/' . $file, $backup . '/' . $file ) ) {
					throw new RuntimeException( 'Could not seed a byte-identical prior backup.' );
				}
			}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed a pre-existing backup before WordPress initializes its filesystem.
		} elseif ( false === file_put_contents( $backup . '/prior.txt', 'Pre-existing backup' ) ) {
			throw new RuntimeException( 'Could not seed the prior copy fallback backup.' );
		}
	}
	$prior = $manifest->snapshot( $backup );
	if ( $preexisting && null === $prior ) {
		throw new RuntimeException( 'Could not read the prior copy fallback backup.' );
	}
	$state = (object) array(
		'restores'         => $restores,
		'incoming'         => $incoming,
		'interrupted'      => $incoming && str_ends_with( $mode, '-throw' ),
		'zero_inode'       => $zero_inode,
		'prior_identity'   => $zero_inode ? StagedPackageManifest::entry_identity( $backup ) : null,
		'slug'             => $slug,
		'destination'      => $destination,
		'backup'           => $backup,
		'original'         => $original,
		'prior'            => $prior,
		'backup_attempts'  => 0,
		'install_attempts' => 0,
		'restore_attempts' => 0,
		'wordpress_result' => null,
	);
	add_filter(
		'upgrader_pre_download',
		static function ( mixed $reply, string $package, WP_Upgrader $upgrader ) use ( $state, $observed ): mixed {
			unset( $package );
			if ( is_string( $reply ) && $upgrader === $observed->upgrader ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Force the disposable site's Core upgrader through its native copy fallback.
				$GLOBALS['wp_filesystem'] = new class( $state ) extends WP_Filesystem_Direct {
					public function __construct( private stdClass $state ) {
						parent::__construct( null );
					}

					public function move( $source, $destination, $overwrite = false ) {
						if ( untrailingslashit( $source ) === $this->state->destination && untrailingslashit( $destination ) === $this->state->backup ) {
							++$this->state->backup_attempts;
							return false;
						}
						if ( untrailingslashit( $source ) === $this->state->backup && untrailingslashit( $destination ) === $this->state->destination ) {
							++$this->state->restore_attempts;
							return false;
						}
						if ( $this->state->incoming && untrailingslashit( $destination ) === $this->state->destination ) {
							++$this->state->install_attempts;
							return false;
						}
						return parent::move( $source, $destination, $overwrite );
					}
				};
			}
			return $reply;
		},
		PHP_INT_MAX,
		3
	);
	add_filter(
		'upgrader_install_package_result',
		static function ( mixed $result ) use ( $state ): mixed {
			$state->wordpress_result = $result instanceof WP_Error ? $result->get_error_code() : is_array( $result );
			return $result;
		},
		9,
		1
	);
	return $state;
}

/** @param array<array-key,mixed> $report */
function ran_wp_release_updater_test_exact_copy_evidence( stdClass $state, stdClass $observed, array $report, bool $quiet, bool $release_bytes ): void {
	$manifest    = new StagedPackageManifest();
	$installed   = $manifest->snapshot( $state->destination );
	$upgrader    = $observed->upgrader;
	$moved       = $state->restores || $state->incoming;
	$backup_tree = $moved ? $state->original : $state->prior;
	$core_result = match ( true ) {
		$state->incoming => true,
		$state->restores => 'exact_fixture_post_copy_failure',
		default          => 'ran_wp_release_updater_backup_source_unverified',
	};
	$expected_code = match ( true ) {
		$state->interrupted => 'unexpected_failure',
		$state->incoming    => 'unverified_install_result',
		default            => 'backup_restore_pending',
	};
	$checks = array(
		'quiet_response'            => $quiet && 0 === $observed->stdout_bytes,
		'one_zip_body'              => 1 === $observed->downloads,
		'core_result'               => $core_result === $state->wordpress_result,
		'backup_copy_attempts'      => ( $moved ? 1 : 0 ) === $state->backup_attempts,
		'install_copy_attempts'     => ( $state->incoming ? 1 : 0 ) === $state->install_attempts,
		'backup_before_shutdown'    => $manifest->snapshot( $state->backup ) === $backup_tree,
		'installed_before_shutdown' => $moved ? $release_bytes && null !== $installed && $installed !== $state->original : $installed === $state->original,
		'incoming_entry_omitted'    => ! $state->incoming || ( ! isset( $installed['files'][ $state->slug ] ) && ! isset( $installed['directories'][ $state->slug ] ) ),
		'finalization_pending'      => 'pending' === $report['status'] && true === $report['finalization_pending']
			&& $expected_code === $report['code'],
		'core_callbacks_scheduled'  => $upgrader instanceof WP_Upgrader
			&& ( $state->incoming ? false : 10 ) === has_action( 'shutdown', array( $upgrader, 'restore_temp_backup' ) )
			&& 100 === has_action( 'shutdown', array( $upgrader, 'delete_temp_backup' ) ),
	);
	if ( $state->zero_inode ) {
		$checks['zero_inode_metadata_collision'] = 0 === ( $state->prior_identity['ino'] ?? null )
			&& StagedPackageManifest::entry_identity( $state->backup ) === $state->prior_identity;
	}
	add_action(
		'shutdown',
		static function () use ( $checks, $state, $manifest, $observed, $report, $installed, $backup_tree ): void {
			$checks  += array(
				'restore_copy_attempts'    => ( $state->restores ? 1 : 0 ) === $state->restore_attempts,
				'installed_after_shutdown' => $manifest->snapshot( $state->destination ) === ( $state->incoming ? $installed : $state->original ),
				'backup_after_shutdown'    => $state->restores ? ! file_exists( $state->backup ) : $manifest->snapshot( $state->backup ) === $backup_tree,
				'private_custody_cleaned'  => array() === glob( sys_get_temp_dir() . '/ran-wp-release-updater-*' ),
				'completion'               => array( 'uncertain' ) === array_column( $observed->completions, 3 ),
			);
			$evidence = array(
				'pass'      => ! in_array( false, $checks, true ),
				'checks'    => $checks,
				'report'    => $report,
				'downloads' => $observed->downloads,
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact observations inside the disposable copy fallback run.
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

/** Set up post-capture failures around Core's real restore and deletion callbacks. */
function ran_wp_release_updater_test_exact_backup_fault( string $mode, string $backup, string $main_file ): ?stdClass {
	if ( ! str_starts_with( $mode, 'exact-rollback-' ) && 'exact-delete-mapping' !== $mode ) {
		return null;
	}
	$destination    = dirname( $main_file );
	$parent         = dirname( $destination );
	$foreign_root   = WP_CONTENT_DIR . '/exact-callback-fixture';
	$backup_mapping = match ( $mode ) {
		// Core concatenates the suffix even when a changed mapping omits the slash.
		'exact-delete-mapping' => untrailingslashit( WP_CONTENT_DIR ),
		// On Unix a literal backslash can resolve elsewhere despite matching after normalization.
		'exact-rollback-backup-mapping' => '/' === DIRECTORY_SEPARATOR
			? dirname( WP_CONTENT_DIR ) . '\\' . basename( WP_CONTENT_DIR ) . '/'
			: trailingslashit( $foreign_root ),
		default => trailingslashit( $foreign_root ),
	};
	$foreign_file = match ( $mode ) {
		'exact-rollback-host-file' => $destination . '/host-written.txt',
		'exact-rollback-backup-mapping', 'exact-delete-mapping' => $backup_mapping . 'upgrade-temp-backup/' . basename( dirname( $backup ) ) . '/' . basename( $backup ) . '/' . basename( $main_file ),
		'exact-rollback-destination-mapping', 'exact-rollback-parent' => $foreign_root . '/' . basename( $destination ) . '/' . basename( $main_file ),
		default => null,
	};
	$fault = (object) array(
		'active'         => 'exact-delete-mapping' === $mode,
		'attempts'       => 0,
		'destination'    => $destination,
		'parent'         => $parent,
		'foreign_root'   => $foreign_root,
		'backup_mapping' => $backup_mapping,
		'installed_file' => $main_file,
		'foreign_file'   => $foreign_file,
		'foreign_bytes'  => "<?php\n// Unrelated callback fixture.\n",
	);
	if ( null !== $foreign_file && 'exact-rollback-host-file' !== $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed unrelated local bytes before initializing WordPress filesystem access.
		if ( ! wp_mkdir_p( dirname( $foreign_file ) ) || false === file_put_contents( $foreign_file, $fault->foreign_bytes ) ) {
			throw new RuntimeException( 'Could not prepare the unrelated callback location.' );
		}
	}
	add_action(
		'shutdown',
		static function () use ( $fault, $mode, $parent, $main_file, $foreign_root, $foreign_file ): void {
			$fault->active = true;
			if ( 'exact-rollback-host-file' === $mode ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulate a host writing unrelated bytes after Core schedules rollback.
				if ( null === $foreign_file || false === file_put_contents( $foreign_file, $fault->foreign_bytes ) ) {
					throw new RuntimeException( 'Could not write the host file before rollback.' );
				}
			}
			if ( 'exact-rollback-parent' === $mode ) {
				$original   = $parent . '-callback-original';
				$filesystem = new WP_Filesystem_Direct( null );
				if ( ! $filesystem->move( $parent, $original ) || ! symlink( $foreign_root, $parent ) ) {
					throw new RuntimeException( 'Could not replace the callback destination parent.' );
				}
				$fault->installed_file = $original . substr( $main_file, strlen( $parent ) );
			}
		},
		9
	);
	if ( 'exact-rollback-backup-mapping' === $mode ) {
		add_action(
			'shutdown',
			static function () use ( $fault ): void {
				$fault->active = false;
			},
			11
		);
	}
	return $fault;
}

/** Interrupt Core after a native backup rename or a successful maintenance marker write. */
function ran_wp_release_updater_test_exact_recovery_fault( string $kind, string $backup, string $main_file, string $archive, string $maintenance, stdClass $observed ): stdClass {
	$directory = dirname( $main_file );
	$files     = array();
	if ( in_array( $kind, array( 'partial', 'complete', 'foreign-directory', 'parent-matching' ), true ) ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			throw new RuntimeException( 'Could not open the incoming recovery fixture.' );
		}
		$names = 'complete' === $kind ? array( 'index.php', 'style.css' ) : array( basename( $main_file ) );
		foreach ( $names as $name ) {
			$bytes = $zip->getFromName( 'offer-target/' . $name );
			if ( ! is_string( $bytes ) ) {
				throw new RuntimeException( 'The incoming recovery fixture is incomplete.' );
			}
			$files[ $name ] = $bytes;
		}
		$zip->close();
	}
	if ( 'foreign-directory' === $kind ) {
		$files['foreign.txt'] = 'Unrelated directory contents';
	}
	if ( 'foreign-bytes' === $kind ) {
		$files[ basename( $main_file ) ] = 'Unrelated bytes at the incoming path';
	}
	$subdirectory = match ( $kind ) {
		'unverified-directory' => 'unverified-empty',
		'verified-directory'   => 'verified/nested',
		default                => null,
	};
	$fault = (object) array(
		'kind'           => $kind,
		'backup'         => $backup,
		'marker'         => $maintenance,
		'directory'      => $directory,
		'main_file'      => $main_file,
		'files'          => $files,
		'subdirectory'   => $subdirectory,
		'staging_seeded' => false,
		'backup_error'   => null,
		'stale_backup'   => 'Unrelated prior backup',
		'interrupted'    => false,
	);
	if ( 'unicode-source' === $kind ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed an existing package file before initializing WordPress filesystem access.
		if ( false === file_put_contents( $directory . '/résumé.txt', 'Existing UTF-8 filename' ) ) {
			throw new RuntimeException( 'Could not seed the UTF-8 source filename.' );
		}
	}
	if ( 'unverified-source' === $kind ) {
		if ( ! symlink( $main_file, $directory . '/old-link.txt' ) ) {
			throw new RuntimeException( 'Could not seed the unverified source link.' );
		}
		$stale_header = $backup . '/' . basename( $main_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed unrelated backup bytes before initializing WordPress filesystem access.
		if ( ! wp_mkdir_p( $backup ) || false === file_put_contents( $stale_header, $fault->stale_backup ) ) {
			throw new RuntimeException( 'Could not seed the unrelated backup.' );
		}
		add_filter(
			'upgrader_install_package_result',
			static function ( mixed $result ) use ( $fault ): mixed {
				$fault->backup_error = $result instanceof WP_Error ? $result->get_error_code() : null;
				return $result;
			},
			9,
			1
		);
	}
	if ( 'unverified-directory' === $kind ) {
		add_filter(
			'upgrader_source_selection',
			static function ( mixed $source, string $remote_source, WP_Upgrader $upgrader ) use ( $fault, $observed ): mixed {
				unset( $remote_source );
				if ( is_string( $source ) && $upgrader === $observed->upgrader ) {
					$fault->staging_seeded = wp_mkdir_p( trailingslashit( $source ) . 'unverified-empty' );
					if ( ! $fault->staging_seeded ) {
						throw new RuntimeException( 'Could not seed the unauthenticated staging directory.' );
					}
				}
				return $source;
			},
			19,
			3
		);
	}
	$replace = static function ( WP_Filesystem_Direct $filesystem ) use ( $fault, $directory, $backup, $kind, $files, $subdirectory ): void {
		if ( 'foreign-file' === $kind ) {
			if ( ! $filesystem->put_contents( $directory, 'Unrelated replacement file' ) ) {
				throw new RuntimeException( 'Could not seed the replacement file.' );
			}
		} elseif ( ! in_array( $kind, array( 'absent', 'unicode-source', 'parent-absent', 'parent-replaced' ), true ) ) {
			if ( ! $filesystem->mkdir( $directory ) ) {
				throw new RuntimeException( 'Could not seed the replacement directory.' );
			}
			foreach ( $files as $name => $bytes ) {
				if ( ! $filesystem->put_contents( $directory . '/' . $name, $bytes ) ) {
					throw new RuntimeException( 'Could not seed the replacement directory contents.' );
				}
			}
			if ( 'symlink' === $kind && ! symlink( $backup . '/index.php', $directory . '/index.php' ) ) {
				throw new RuntimeException( 'Could not seed the replacement link.' );
			}
			if ( null !== $subdirectory && ! wp_mkdir_p( $directory . '/' . $subdirectory ) ) {
				throw new RuntimeException( 'Could not seed the replacement subdirectory.' );
			}
		}
		$fault->interrupted = true;
		throw new RuntimeException( 'Interrupted Core cache invalidation after the backup rename.' );
	};
	add_filter(
		'upgrader_pre_download',
		static function ( mixed $reply, string $package, WP_Upgrader $upgrader ) use ( $fault, $observed, $backup, $directory, $kind, $maintenance, $replace ): mixed {
			unset( $package );
			if ( is_string( $reply ) && $upgrader === $observed->upgrader ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Inject only at the disposable site's successful direct filesystem mutation boundaries.
				$GLOBALS['wp_filesystem'] = new class( $fault, $backup, $directory, $kind, $maintenance, $replace ) extends WP_Filesystem_Direct {
					/** @param (Closure(WP_Filesystem_Direct):void) $replace */
					public function __construct( private stdClass $fault, private string $backup, private string $destination, private string $scenario, private string $marker, private Closure $replace ) {
						parent::__construct( null );
					}

					public function dirlist( $path, $include_hidden = true, $recursive = false ) {
						if ( 'maintenance' !== $this->scenario && ! $this->fault->interrupted && $recursive
							&& rtrim( $path, '/\\' ) === $this->backup && ! file_exists( $this->destination )
						) {
							( $this->replace )( $this );
						}
						return parent::dirlist( $path, $include_hidden, $recursive );
					}

					public function put_contents( $file, $contents, $mode = false ) {
						$written = parent::put_contents( $file, $contents, $mode );
						if ( $written && 'maintenance' === $this->scenario && $file === $this->marker ) {
							$this->fault->interrupted = true;
							throw new RuntimeException( 'Interrupted after Core wrote the maintenance marker.' );
						}
						return $written;
					}
				};
			}
			return $reply;
		},
		PHP_INT_MAX,
		3
	);
	return $fault;
}

/** @param (Closure(string):string) $read_bytes */
function ran_wp_release_updater_test_exact_interrupted_destination( stdClass $fault, Closure $read_bytes ): bool {
	switch ( $fault->kind ) {
		case 'absent':
		case 'unicode-source':
		case 'parent-absent':
		case 'parent-replaced':
			return ! file_exists( $fault->directory ) && ! is_link( $fault->directory );
		case 'unverified-directory':
		case 'verified-directory':
			$child = $fault->directory . '/' . $fault->subdirectory;
			return is_dir( $child ) && array( '.', '..' ) === scandir( $child );
		case 'foreign-file':
			return is_file( $fault->directory ) && 'Unrelated replacement file' === $read_bytes( $fault->directory );
		case 'symlink':
			return is_link( $fault->directory . '/index.php' ) && readlink( $fault->directory . '/index.php' ) === $fault->backup . '/index.php';
		default:
			$expected = array_merge( array( '.', '..' ), array_keys( $fault->files ) );
			sort( $expected, SORT_STRING );
			if ( ! is_dir( $fault->directory ) || scandir( $fault->directory ) !== $expected ) {
				return false;
			}
			foreach ( $fault->files as $name => $bytes ) {
				if ( $read_bytes( $fault->directory . '/' . $name ) !== $bytes ) {
					return false;
				}
			}
			return true;
	}
}

/** Swap the original parent after the upgrader has captured its backup. */
function ran_wp_release_updater_test_exact_swap_parent( stdClass $fault ): void {
	$parent     = dirname( $fault->directory );
	$old        = $parent . '-original-fixture';
	$filesystem = new WP_Filesystem_Direct( null );
	if ( ! $filesystem->move( $parent, $old ) ) {
		throw new RuntimeException( 'Could not move the original destination parent.' );
	}
	if ( 'parent-replaced' === $fault->kind ) {
		if ( ! $filesystem->mkdir( $parent ) ) {
			throw new RuntimeException( 'Could not replace the destination parent with another directory.' );
		}
		return;
	}
	$redirected = $parent . '-redirected-fixture';
	$slug       = '/' . basename( $fault->directory );
	if ( ! $filesystem->mkdir( $redirected )
		|| ( $filesystem->exists( $old . $slug ) && ! $filesystem->move( $old . $slug, $redirected . $slug ) )
		|| ! symlink( $redirected, $parent )
	) {
		throw new RuntimeException( 'Could not replace the destination parent with a symlink.' );
	}
}

/**
 * @param (Closure(string):string) $read_bytes
 * @param array<array-key,mixed> $report
 */
function ran_wp_release_updater_test_exact_recovery_evidence( stdClass $fault, stdClass $observed, string $before, Closure $read_bytes, array $report, bool $quiet ): void {
	$maintenance_only  = 'maintenance' === $fault->kind;
	$unverified_source = 'unverified-source' === $fault->kind;
	$unicode_source    = 'unicode-source' === $fault->kind;
	$parent_swap       = str_starts_with( $fault->kind, 'parent-' );
	$untouched         = $maintenance_only || $unverified_source;
	$backup_header     = $fault->backup . '/' . basename( $fault->main_file );
	$upgrader          = $observed->upgrader;
	$checks            = array(
		'interrupted'                 => $unverified_source !== $fault->interrupted,
		'one_zip_body'                => 1 === $observed->downloads,
		'quiet_response'              => $quiet && 0 === $observed->stdout_bytes,
		'pre_move_error'              => ! $unverified_source || 'ran_wp_release_updater_backup_source_unverified' === $fault->backup_error,
		'pending_recovery'            => 'pending' === $report['status']
			&& ( $unverified_source ? 'backup_restore_pending' : 'unexpected_failure' ) === $report['code']
			&& true === $report['finalization_pending'],
		'core_recovery_callbacks'     => $upgrader instanceof WP_Upgrader
			&& ( $unverified_source ? 10 : false ) === has_action( 'shutdown', array( $upgrader, 'restore_temp_backup' ) )
			&& ( $unverified_source ? 100 : false ) === has_action( 'shutdown', array( $upgrader, 'delete_temp_backup' ) ),
		'marker_written_before_throw' => $unverified_source || is_file( $fault->marker ),
		'backup_before_shutdown'      => $maintenance_only ? ! file_exists( $fault->backup )
			: is_file( $backup_header ) && $read_bytes( $backup_header ) === ( $unverified_source ? $fault->stale_backup : $before ),
		'destination_before_shutdown' => $untouched ? $read_bytes( $fault->main_file ) === $before : ran_wp_release_updater_test_exact_interrupted_destination( $fault, $read_bytes ),
		'staging_directory_seeded'    => 'unverified-directory' !== $fault->kind || $fault->staging_seeded,
	);
	if ( $parent_swap ) {
		ran_wp_release_updater_test_exact_swap_parent( $fault );
	}
	add_action(
		'shutdown',
		static function () use ( $checks, $fault, $observed, $backup_header, $before, $read_bytes, $report, $maintenance_only, $unverified_source, $unicode_source, $parent_swap ): void {
			$parent   = dirname( $fault->directory );
			$preserve = $parent_swap || in_array( $fault->kind, array( 'foreign-directory', 'foreign-bytes', 'foreign-file', 'symlink', 'unverified-directory' ), true );
			$checks  += array(
				'destination_after_shutdown'   => $preserve
					? ran_wp_release_updater_test_exact_interrupted_destination( $fault, $read_bytes )
					: is_file( $fault->main_file ) && $read_bytes( $fault->main_file ) === $before,
				'backup_after_shutdown'        => $preserve || $unverified_source
					? is_file( $backup_header ) && $read_bytes( $backup_header ) === ( $unverified_source ? $fault->stale_backup : $before )
					: ! file_exists( $fault->backup ),
				'marker_cleaned'               => ! file_exists( $fault->marker ),
				'private_custody_cleaned'      => array() === glob( sys_get_temp_dir() . '/ran-wp-release-updater-*' ),
				'completion'                   => ( $maintenance_only ? array() : array( 'uncertain' ) ) === array_column( $observed->completions, 3 ),
				'utf8_source_restored'         => ! $unicode_source || 'Existing UTF-8 filename' === $read_bytes( $fault->directory . '/résumé.txt' ),
				'unverified_source_intact'     => ! $unverified_source || is_link( $fault->directory . '/old-link.txt' ),
				'original_parent_preserved'    => ! $parent_swap || is_dir( $parent . '-original-fixture' ),
				'replacement_parent_preserved' => ! $parent_swap || ( 'parent-replaced' === $fault->kind
					? is_dir( $parent ) && ! is_link( $parent )
					: is_link( $parent ) && readlink( $parent ) === $parent . '-redirected-fixture' ),
			);
			$evidence = array(
				'pass'      => ! in_array( false, $checks, true ),
				'checks'    => $checks,
				'report'    => $report,
				'downloads' => $observed->downloads,
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact recovery observations inside the disposable integration run.
			file_put_contents( (string) getenv( 'RAN_WP_RELEASE_UPDATER_OFFER_OUTPUT' ), json_encode( $evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) );
		},
		PHP_INT_MAX
	);
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
function ran_wp_release_updater_test_exact_wordpress_faults( string $mode, string $type, string $identity, string $backup, stdClass $observed, ?stdClass $backup_fault ): void {
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

	if ( in_array( $mode, array( 'exact-failure', 'exact-post-install-throw', 'exact-copy-nested', 'exact-copy-zero-inode-distinct' ), true ) || str_starts_with( $mode, 'exact-rollback-' ) ) {
		add_filter(
			'upgrader_post_install',
			static function ( mixed $response, array $extra ) use ( $mode, $type, $identity ): mixed {
				if ( ( $extra[ $type ] ?? null ) !== $identity ) {
					return $response;
				}
				if ( 'exact-post-install-throw' === $mode ) {
					throw new RuntimeException( 'Interrupted after copy and before Core schedules recovery.' );
				}
				return new WP_Error( 'exact_fixture_post_copy_failure', 'Injected after the replacement was copied.' );
			},
			'exact-post-install-throw' === $mode ? 9 : PHP_INT_MAX,
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
						static fn ( string $sql ): string => str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'ran_wp_release_updater_target_v1_' ) ? $sql . ' AND 1 = 0' : $sql
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
		static function ( WP_Upgrader $upgrader ) use ( $mode, $backup, $observed, $backup_fault ): void {
			if ( $upgrader !== $observed->upgrader ) {
				return;
			}
			if ( str_starts_with( $mode, 'exact-copy-incoming-' ) && str_ends_with( $mode, '-throw' ) ) {
				throw new RuntimeException( 'Interrupted after Core scheduled deletion of the original backup.' );
			}
			if ( str_starts_with( $mode, 'exact-copy-' ) ) {
				return;
			}
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Inject failures and mapping changes at Core's direct-filesystem callback boundaries.
			$GLOBALS['wp_filesystem'] = new class( $mode, $backup, $observed, $backup_fault ) extends WP_Filesystem_Direct {
				public function __construct( private string $scenario, private string $backup_path, private stdClass $observation, private ?stdClass $fault ) {
					parent::__construct( null );
				}

				public function wp_content_dir() {
					if ( null !== $this->fault && $this->fault->active && in_array( $this->scenario, array( 'exact-rollback-backup-mapping', 'exact-delete-mapping' ), true ) ) {
						return $this->fault->backup_mapping;
					}
					return parent::wp_content_dir();
				}

				public function find_folder( $folder ) {
					if ( 'exact-rollback-destination-mapping' === $this->scenario && null !== $this->fault && $this->fault->active
						&& rtrim( wp_normalize_path( $folder ), '/' ) === $this->fault->parent
					) {
						return trailingslashit( $this->fault->foreign_root );
					}
					return parent::find_folder( $folder );
				}

				public function delete( $file, $recursive = false, $type = false ) {
					if ( null !== $this->fault && $this->fault->active && rtrim( $file, '/\\' ) === $this->fault->destination
						&& in_array( $this->scenario, array( 'exact-rollback-error', 'exact-rollback-throw', 'exact-rollback-host-file' ), true )
					) {
						++$this->fault->attempts;
						if ( 'exact-rollback-throw' === $this->scenario ) {
							throw new RuntimeException( 'Injected exception while Core was clearing the rollback destination.' );
						}
						if ( 'exact-rollback-error' === $this->scenario ) {
							return false;
						}
					}
					if ( rtrim( $file, '/\\' ) === $this->backup_path ) {
						++$this->observation->deletions;
						if ( 'exact-delete-fail' === $this->scenario && 1 === $this->observation->deletions ) {
							return false;
						}
					}
					return parent::delete( $file, $recursive, $type );
				}
			};
			if ( 'exact-interrupted' === $mode ) {
				throw new RuntimeException( 'Injected interruption before the native upgrader returns.' );
			}
			if ( 'exact-commit-fail' === $mode ) {
				$failed = false;
				add_filter(
					'query',
					static function ( string $sql ) use ( &$failed ): string {
						if ( ! $failed && str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'ran_wp_release_updater_target_v1_' ) ) {
							$failed = true;
							return $sql . ' AND 1 = 0';
						}
						return $sql;
					}
				);
			}
		},
		PHP_INT_MAX
	);
}
