<?php

// WordPress eval-file runs each phase in its own boot and shares the transient through the database.
( static function (): void {
	$phase   = getenv( 'RAN_UPDATER_METADATA_CACHE_PHASE' );
	$archive = getenv( 'RAN_UPDATER_METADATA_CACHE_ZIP' );
	$output  = getenv( 'RAN_UPDATER_METADATA_CACHE_OUTPUT' );
	if ( ! in_array( $phase, array( 'write', 'warm', 'historical', 'incompatible', 'install' ), true ) || ! is_string( $archive ) || ! is_string( $output ) || ! isset( $GLOBALS['ran_updater_plugin_handle'] ) ) {
		throw new RuntimeException( 'Metadata cache proof requires the installed distribution fixture.' );
	}

	$identity = 'ran-neutral-plugin/ran-neutral-plugin.php';
	$uri      = 'https://github.com/fixture/neutral-plugin';
	$attempt  = in_array( $phase, array( 'incompatible', 'install' ), true );
	$writer   = 'write' === $phase || $attempt;
	$warm     = 'warm' === $phase;
	if ( 'write' === $phase ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::EXCL )
			|| ! $zip->addFromString(
				$identity,
				"<?php
/*
Plugin Name: Integration consumer
Version: 2.0.0
Update URI: $uri
Requires at least: 6.5
Requires PHP: 8.2
*/
"
			)
			|| ! $zip->close() ) {
			throw new RuntimeException( 'Could not create the metadata cache release fixture.' );
		}
	}
	if ( $attempt ) {
		$original = getenv( 'RAN_UPDATER_METADATA_CACHE_ORIGINAL' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copy the installed distribution fixture to an owned candidate archive.
		if ( ! is_string( $original ) || ! copy( $original, $archive ) ) {
			throw new RuntimeException( 'Could not copy the installed distribution fixture.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			throw new RuntimeException( 'Could not open the candidate archive.' );
		}
		$entry = $zip->getFromName( $identity );
		$entry = is_string( $entry ) ? str_replace( 'Version: 1.0.0', 'Version: 2.0.0', $entry ) : '';
		if ( 'incompatible' === $phase ) {
			$entry = str_replace( 'Requires PHP: 8.2', 'Requires PHP: 99.0', $entry );
		}
		if ( '' === $entry || ! $zip->addFromString( $identity, $entry ) || ! $zip->close() ) {
			throw new RuntimeException( 'Could not finish the candidate archive.' );
		}
	}
	if ( $writer ) {
		delete_site_transient( 'update_plugins' );
	}
	$release        = array(
		'id'           => 7,
		'draft'        => false,
		'prerelease'   => false,
		'immutable'    => true,
		'html_url'     => $uri . '/releases/tag/v2.0.0',
		'published_at' => '2026-10-25T00:00:00Z',
		'tag_name'     => 'v2.0.0',
		'assets'       => array(
			array(
				'id'     => 8,
				'name'   => 'ran-neutral-plugin.zip',
				'size'   => filesize( $archive ),
				'state'  => 'uploaded',
				'digest' => 'sha256:' . hash_file( 'sha256', $archive ),
			),
		),
	);
	$api            = 'https://api.github.com/repos/fixture/neutral-plugin';
	$provider_calls = 0;
	$zip_calls      = 0;
	add_filter(
		'pre_http_request',
		static function ( mixed $response, array $args, string $url ) use ( $api, $release, $archive, &$provider_calls, &$zip_calls ): mixed {
			if ( 'https://api.wordpress.org/plugins/update-check/1.1/' === $url ) {
				$body = wp_json_encode(
					array(
						'plugins'      => array(),
						'translations' => array(),
						'no_update'    => array(),
					)
				);
			} elseif ( str_starts_with( $url, 'http://example.test/' ) ) {
				// Core's automatic update sandbox probes the activated fixture through loopback.
				$query = wp_parse_url( $url, PHP_URL_QUERY );
				parse_str( is_string( $query ) ? $query : '', $parts );
				$key   = $parts['wp_scrape_key'] ?? null;
				$nonce = $parts['wp_scrape_nonce'] ?? null;
				$body  = is_string( $key ) && preg_match( '/\A[a-f0-9]{32}\z/', $key ) && is_string( $nonce ) && ctype_digit( $nonce )
					? "###### wp_scraping_result_start:$key ######
[]
###### wp_scraping_result_end:$key ######"
					: null;
			} elseif ( str_starts_with( $url, $api ) ) {
				++$provider_calls;
				$body = match ( $url ) {
					$api => wp_json_encode( array( 'id' => 123456789 ) ),
					$api . '/releases?per_page=20&page=1' => wp_json_encode( array( $release ) ),
					$api . '/releases/7' => wp_json_encode( $release ),
					$api . '/commits/v2.0.0' => str_repeat( 'a', 40 ),
					default => null,
				};
				if ( $api . '/releases/assets/8' === $url && is_string( $args['filename'] ?? null ) ) {
					++$zip_calls;
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Stream the exact local fixture bytes into the updater's private download destination.
					if ( copy( $archive, $args['filename'] ) ) {
						$body = '';
					}
				}
			} else {
				return $response;
			}
			return is_string( $body ) ? array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array( 'code' => 200 ),
			) : $response;
		},
		PHP_INT_MAX,
		3
	);

	$proof = array();
	if ( ! $writer ) {
		$raw                                     = get_site_option( '_site_transient_update_plugins' );
		$advisory                                = $raw->ran_wp_release_updater_metadata_offers[ $identity ] ?? null;
		$old                                     = is_array( $advisory ) ? ( $advisory['offer'] ?? null ) : null;
		$package                                 = is_object( $old ) ? ( $old->package ?? null ) : null;
		$proof['prior_metadata_offer_persisted'] = is_string( $package ) && str_starts_with( $package, 'ran-wp-release-updater:metadata:v1:' );
		$proof['prior_check_is_fresh']           = '1.0.0' === ( $advisory['checked'] ?? null ) && time() - ( $raw->last_checked ?? 0 ) < HOUR_IN_SECONDS;
		$proof['prior_native_slots_empty']       = ! isset( $raw->response[ $identity ] ) && ! isset( $raw->checked[ $identity ] );
		$view                                    = get_site_transient( 'update_plugins' );
		if ( $warm ) {
			$proof['projected'] = is_object( $view ) && ( $view->response[ $identity ]->package ?? null ) === $package && '1.0.0' === ( $view->checked[ $identity ] ?? null );
			set_site_transient( 'update_plugins', $view );
		} else {
			$proof['hidden'] = is_object( $view ) && ! isset( $view->response[ $identity ] ) && ! isset( $view->checked[ $identity ] );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise Core's exact automatic update decision hook.
			$proof['automatic_refused'] = is_object( $old ) && false === apply_filters( 'auto_update_plugin', true, $old );
			$reply                      = is_string( $package ) ? apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise Core's exact native download admission hook.
				'upgrader_pre_download',
				false,
				$package,
				null,
				array(
					'type'   => 'plugin',
					'action' => 'update',
					'plugin' => $identity,
				)
			) : null;
			$proof['download_refused'] = $reply instanceof WP_Error && 'ran_wp_release_updater_unverified_pre_download' === $reply->get_error_code();
		}
		$proof['no_provider_calls_until_new_check'] = 0 === $provider_calls;
	}
	if ( 'historical' === $phase ) {
		$broker                              = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
		$historical                          = getenv( 'RAN_UPDATER_METADATA_CACHE_HISTORICAL' );
		$proof['historical_runtime_elected'] = is_object( $broker ) && is_string( $historical )
			&& realpath( $historical ) === ( new ReflectionProperty( $broker, 'selected_root' ) )->getValue( $broker );
	}
	wp_update_plugins();

	$stored                            = get_site_option( '_site_transient_update_plugins' );
	$metadata                          = $writer || $warm;
	$saved                             = $stored->ran_wp_release_updater_metadata_offers[ $identity ] ?? null;
	$offer                             = $metadata ? ( $saved['offer'] ?? null ) : ( $stored->response[ $identity ] ?? null );
	$proof['zip_requests']             = $zip_calls;
	$proof['offer_persisted']          = is_object( $offer ) && '2.0.0' === ( $offer->new_version ?? null )
		&& str_starts_with( $offer->package ?? '', $metadata ? 'ran-wp-release-updater:metadata:v1:' : 'ran-wp-release-updater:v1:' )
		&& ( $metadata ? '' : '6.5' ) === ( $offer->requires ?? null )
		&& ( $metadata ? '' : '8.2' ) === ( $offer->requires_php ?? null );
	$proof['stored_in_expected_slots'] = $metadata
		? ! isset( $stored->response[ $identity ] ) && ! isset( $stored->checked[ $identity ] ) && '1.0.0' === ( $saved['checked'] ?? null )
		: null === $saved;
	$proof['pass']                     = ! in_array( false, $proof, true ) && ( $metadata ? 0 : 1 ) === $zip_calls
		&& ( $warm ? 0 === $provider_calls : 0 < $provider_calls );
	if ( $attempt ) {
		$site = getenv( 'RAN_UPDATER_METADATA_CACHE_SITE' );
		$root = is_string( $site ) ? realpath( $site ) : false;
		$path = WP_PLUGIN_DIR . '/' . $identity;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify the parent harness's exact ownership marker before running Core's installer.
		if ( ! is_string( $root ) || $site !== $root || ABSPATH !== $root . '/' || WP_CONTENT_DIR !== $root . '/wp-content' || 'http://example.test' !== site_url() || realpath( $path ) !== $root . '/wp-content/plugins/' . $identity || 'ran-wp-release-updater-integration-v1' !== file_get_contents( dirname( $output ) . '/.integration-owner' ) ) {
			throw new RuntimeException( 'Automatic metadata proof requires an owned disposable site.' );
		}
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$manifest                  = static function ( string $directory ): array {
			$files = array();
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file->isLink() || ! $file->isFile() ) {
					throw new RuntimeException( 'Expected regular installed package files.' );
				}
				$files[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() );
			}
			ksort( $files );
			return $files;
		};
		$installed                 = $manifest( dirname( $path ) );
		$active                    = get_option( 'active_plugins' );
		$status                    = $GLOBALS['ran_updater_plugin_handle']->status();
		$candidate                 = $status['native'] ?? array();
		$proof['candidate_status'] = '7' === ( $candidate['offered_release_identity'] ?? null )
			&& 'v2.0.0' === ( $candidate['candidate_tag'] ?? null )
			&& '2.0.0' === ( $candidate['offered_version'] ?? null )
			&& array_key_exists( 'candidate_header_version', $candidate ) && null === $candidate['candidate_header_version']
			&& array_key_exists( 'candidate_validation_code', $candidate ) && null === $candidate['candidate_validation_code'];
		if ( 'install' === $phase ) {
			$registrar                    = require dirname( $path ) . '/vendor/ran/wp-release-updater/bootstrap.php';
			$source                       = $registrar->releases( 'github', 'plugin', 'fixture/neutral-plugin', '123456789' );
			$inspection                   = $source->inspect( '7', 'v2.0.0' );
			$proof['explicit_inspection'] = 'release_inspected' === ( $inspection['code'] ?? null ) && 1 === $zip_calls;
		}
		$before_install = $zip_calls;
		$preinstall     = 0;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Observe Core's unmodified installation boundary.
		add_filter(
			'upgrader_pre_install',
			static function ( mixed $result, array $extra ) use ( $identity, &$preinstall ): mixed {
				if ( ( $extra['plugin'] ?? null ) === $identity ) {
					++$preinstall;
				}
				return $result;
			},
			1,
			2
		);
		$activation_writes = 0;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Observe writes to the actual WordPress activation option.
		add_action(
			'update_option_active_plugins',
			static function () use ( &$activation_writes ): void {
				++$activation_writes;
			}
		);
		$results = array();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Observe Core's real automatic update results for the fixture target.
		add_action(
			'automatic_updates_complete',
			static function ( array $updates ) use ( &$results ): void {
				$results = $updates['plugin'] ?? array();
			}
		);
		add_filter( 'pre_wp_mail', '__return_true' );
		$vcs_checkout = 'unchecked';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Record Core's actual VCS admission without overriding it.
		add_filter(
			'automatic_updates_is_vcs_checkout',
			static function ( bool $checkout, string $context ) use ( &$vcs_checkout ): bool {
				if ( WP_PLUGIN_DIR === $context ) {
					$vcs_checkout = $checkout ? 'detected' : 'clear';
				}
				return $checkout;
			},
			PHP_INT_MAX,
			2
		);
		$automatic_decision = null;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Observe the automatic decision without modifying it.
		add_filter(
			'auto_update_plugin',
			static function ( ?bool $decision, object $item ) use ( $identity, &$automatic_decision ): ?bool {
				if ( ( $item->plugin ?? null ) === $identity ) {
					$automatic_decision = $decision;
				}
				return $decision;
			},
			PHP_INT_MAX,
			2
		);
		$download_error = null;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Observe the native reply before older Core discards the download error.
		add_filter(
			'upgrader_pre_download',
			static function ( mixed $reply, string $package, mixed $upgrader, array $extra ) use ( $identity, &$download_error ): mixed {
				unset( $package, $upgrader );
				if ( ( $extra['plugin'] ?? null ) === $identity && is_wp_error( $reply ) ) {
					$download_error = $reply->get_error_code();
				}
				return $reply;
			},
			PHP_INT_MAX,
			4
		);
		add_filter( 'wp_doing_cron', '__return_true' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Dispatch the same Core action as the scheduled automatic updater.
		do_action( 'wp_maybe_auto_update' );
		$proof['core_vcs_checkout']       = $vcs_checkout;
		$proof['core_automatic_decision'] = $automatic_decision;
		$result                           = $results[0]->result ?? null;
		$proof['install_zip_requests']    = $zip_calls - $before_install;
		$proof['activation_preserved']    = is_plugin_active( $identity ) && get_option( 'active_plugins' ) === $active;
		if ( 'incompatible' === $phase ) {
			$proof['failure']                  = $download_error;
			$proof['before_install_boundary']  = 0 === $preinstall && 0 === $activation_writes;
			$proof['original_bytes_preserved'] = $installed === $manifest( dirname( $path ) );
			$proof['pass']                     = $proof['pass'] && 'ran_wp_release_updater_archive_php_requirement_incompatible' === $proof['failure'];
		} else {
			$zip                                = new ZipArchive();
			$entry                              = true === $zip->open( $archive ) ? $zip->getFromName( $identity ) : false;
			$proof['candidate_bytes_installed'] = is_string( $entry ) && hash( 'sha256', $entry ) === hash_file( 'sha256', $path );
			$status                             = $GLOBALS['ran_updater_plugin_handle']->status();
			$proof['verified_install']          = 1 === $preinstall && ! is_wp_error( $result ) && null !== $result
				&& 'verified_success' === ( $status['native']['last_native_outcome']['outcome'] ?? null );
		}
		$proof['pass'] = $proof['pass'] && ! in_array( false, $proof, true )
			&& 'clear' === $vcs_checkout && true === $automatic_decision && 1 === $proof['install_zip_requests'];
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Save only the bounded redacted assertions for the parent installed test process.
	file_put_contents(
		$output,
		wp_json_encode( $proof ) . '
'
	);
} )();
