<?php

// WordPress eval-file runs each phase in its own boot and shares the transient through the database.
( static function (): void {
	$phase   = getenv( 'RAN_UPDATER_METADATA_CACHE_PHASE' );
	$archive = getenv( 'RAN_UPDATER_METADATA_CACHE_ZIP' );
	$output  = getenv( 'RAN_UPDATER_METADATA_CACHE_OUTPUT' );
	if ( ! in_array( $phase, array( 'write', 'warm', 'verify', 'rewrite', 'historical' ), true ) || ! is_string( $archive ) || ! is_string( $output ) || ! isset( $GLOBALS['ran_updater_plugin_handle'] ) ) {
		throw new RuntimeException( 'Metadata cache proof requires the installed distribution fixture.' );
	}

	$identity = 'ran-neutral-plugin/ran-neutral-plugin.php';
	$uri      = 'https://github.com/fixture/neutral-plugin';
	$writer   = in_array( $phase, array( 'write', 'rewrite' ), true );
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
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Save only the bounded redacted assertions for the parent installed test process.
	file_put_contents(
		$output,
		wp_json_encode( $proof ) . '
'
	);
} )();
