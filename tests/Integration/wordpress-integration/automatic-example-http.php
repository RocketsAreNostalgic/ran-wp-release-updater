<?php

// Loaded as a disposable MU-plugin so a fresh WP-CLI cron process owns this HTTP boundary.

$ran_wp_release_updater_test_automatic_http_archive = getenv( 'RAN_UPDATER_AUTOMATIC_EXAMPLE_ARCHIVE' );
$ran_wp_release_updater_test_automatic_http_version = getenv( 'RAN_UPDATER_AUTOMATIC_EXAMPLE_VERSION' );
$ran_wp_release_updater_test_automatic_http_log     = getenv( 'RAN_UPDATER_AUTOMATIC_EXAMPLE_HTTP_LOG' );
if ( ! is_string( $ran_wp_release_updater_test_automatic_http_archive ) || ! is_file( $ran_wp_release_updater_test_automatic_http_archive ) || ! is_string( $ran_wp_release_updater_test_automatic_http_version ) || ! preg_match( '/\A0\.[23]\.0\z/', $ran_wp_release_updater_test_automatic_http_version ) || ! is_string( $ran_wp_release_updater_test_automatic_http_log ) || '' === $ran_wp_release_updater_test_automatic_http_log ) {
	throw new RuntimeException( 'Automatic example HTTP fixture inputs are invalid.' );
}
$ran_wp_release_updater_test_automatic_http_calls    = array();
$ran_wp_release_updater_test_automatic_http_writes   = 0;
$ran_wp_release_updater_test_automatic_http_complete = array(
	'result_count'  => 0,
	'success_count' => 0,
);
$ran_wp_release_updater_test_automatic_http_hook     = false;
$ran_wp_release_updater_test_automatic_http_vcs      = null;
$ran_wp_release_updater_test_automatic_http_mail     = 0;
add_filter(
	'pre_wp_mail',
	static function () use ( &$ran_wp_release_updater_test_automatic_http_mail ): bool {
		++$ran_wp_release_updater_test_automatic_http_mail;
		return true;
	}
);
$ran_wp_release_updater_test_automatic_http_api = 'https://api.github.com/repos/RocketsAreNostalgic/ran-wp-release-updater-example';
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $ran_wp_release_updater_test_automatic_http_archive, $ran_wp_release_updater_test_automatic_http_version, $ran_wp_release_updater_test_automatic_http_api, &$ran_wp_release_updater_test_automatic_http_calls, &$ran_wp_release_updater_test_automatic_http_writes ) {
		if ( 'https://api.wordpress.org/plugins/update-check/1.1/' === $url ) {
			$ran_wp_release_updater_test_automatic_http_calls[] = $url;
			return ran_wp_release_updater_test_automatic_http_response(
				array(
					'plugins'      => array(),
					'translations' => array(),
					'no_update'    => array(),
				)
			);
		}
		$ran_wp_release_updater_test_automatic_http_loopback = wp_parse_url( $url );
		if ( is_array( $ran_wp_release_updater_test_automatic_http_loopback ) && 'example.test' === ( $ran_wp_release_updater_test_automatic_http_loopback['host'] ?? null ) ) {
			parse_str( $ran_wp_release_updater_test_automatic_http_loopback['query'] ?? '', $ran_wp_release_updater_test_automatic_http_query );
			$ran_wp_release_updater_test_automatic_http_scrape_key   = $ran_wp_release_updater_test_automatic_http_query['wp_scrape_key'] ?? null;
			$ran_wp_release_updater_test_automatic_http_scrape_nonce = $ran_wp_release_updater_test_automatic_http_query['wp_scrape_nonce'] ?? null;
			if ( is_string( $ran_wp_release_updater_test_automatic_http_scrape_key ) && preg_match( '/\A[a-f0-9]{32}\z/', $ran_wp_release_updater_test_automatic_http_scrape_key ) && is_string( $ran_wp_release_updater_test_automatic_http_scrape_nonce ) && ctype_digit( $ran_wp_release_updater_test_automatic_http_scrape_nonce ) ) {
				$ran_wp_release_updater_test_automatic_http_calls[] = $url;
				return ran_wp_release_updater_test_automatic_http_raw_response( "###### wp_scraping_result_start:$ran_wp_release_updater_test_automatic_http_scrape_key ######\n[]\n###### wp_scraping_result_end:$ran_wp_release_updater_test_automatic_http_scrape_key ######" );
			}
		}
		if ( ! is_string( $url ) || ! str_starts_with( $url, $ran_wp_release_updater_test_automatic_http_api ) ) {
			return new WP_Error( 'automatic_example_http_denied', 'Automatic example fixture denied a non-GitHub request.' );
		}
		$ran_wp_release_updater_test_automatic_http_calls[]    = $url;
		$ran_wp_release_updater_test_automatic_http_tag        = 'v' . $ran_wp_release_updater_test_automatic_http_version;
		$ran_wp_release_updater_test_automatic_http_phase      = (int) explode( '.', $ran_wp_release_updater_test_automatic_http_version )[1];
		$ran_wp_release_updater_test_automatic_http_release_id = 300 + $ran_wp_release_updater_test_automatic_http_phase;
		$ran_wp_release_updater_test_automatic_http_asset_id   = 400 + $ran_wp_release_updater_test_automatic_http_phase;
		$ran_wp_release_updater_test_automatic_http_commit     = str_repeat( (string) $ran_wp_release_updater_test_automatic_http_phase, 40 );
		$ran_wp_release_updater_test_automatic_http_release    = array(
			'id'           => $ran_wp_release_updater_test_automatic_http_release_id,
			'draft'        => false,
			'prerelease'   => false,
			'immutable'    => true,
			'html_url'     => 'https://github.com/RocketsAreNostalgic/ran-wp-release-updater-example/releases/tag/' . $ran_wp_release_updater_test_automatic_http_tag,
			'published_at' => '2026-10-25T00:00:00Z',
			'tag_name'     => $ran_wp_release_updater_test_automatic_http_tag,
			'assets'       => array(
				array(
					'id'     => $ran_wp_release_updater_test_automatic_http_asset_id,
					'name'   => basename( $ran_wp_release_updater_test_automatic_http_archive ),
					'size'   => filesize( $ran_wp_release_updater_test_automatic_http_archive ),
					'state'  => 'uploaded',
					'digest' => 'sha256:' . hash_file( 'sha256', $ran_wp_release_updater_test_automatic_http_archive ),
				),
			),
		);
		if ( $ran_wp_release_updater_test_automatic_http_api . '/releases?per_page=20&page=1' === $url ) {
			return ran_wp_release_updater_test_automatic_http_response( array( $ran_wp_release_updater_test_automatic_http_release ) );
		}
		if ( $ran_wp_release_updater_test_automatic_http_api === $url ) {
			return ran_wp_release_updater_test_automatic_http_response( array( 'id' => 1433442769 ) );
		}
		if ( $ran_wp_release_updater_test_automatic_http_api . '/releases/' . $ran_wp_release_updater_test_automatic_http_release_id === $url ) {
			return ran_wp_release_updater_test_automatic_http_response( $ran_wp_release_updater_test_automatic_http_release );
		}
		if ( $ran_wp_release_updater_test_automatic_http_api . '/commits/' . rawurlencode( $ran_wp_release_updater_test_automatic_http_tag ) === $url ) {
			return ran_wp_release_updater_test_automatic_http_response( array( 'sha' => $ran_wp_release_updater_test_automatic_http_commit ) );
		}
		if ( $ran_wp_release_updater_test_automatic_http_api . '/releases/assets/' . $ran_wp_release_updater_test_automatic_http_asset_id === $url && true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) && '' !== $args['filename'] ) {
			if ( ! copy( $ran_wp_release_updater_test_automatic_http_archive, $args['filename'] ) ) {
				return new WP_Error( 'automatic_example_copy_failed', 'Automatic example fixture could not stream the archive.' );
			}
			++$ran_wp_release_updater_test_automatic_http_writes;
			return ran_wp_release_updater_test_automatic_http_response( null, $args['filename'] );
		}
		return new WP_Error( 'automatic_example_http_denied', 'Automatic example fixture denied an unexpected GitHub request.' );
	},
	PHP_INT_MAX,
	3
);
add_action(
	'wp_maybe_auto_update',
	static function () use ( &$ran_wp_release_updater_test_automatic_http_hook ): void {
		$ran_wp_release_updater_test_automatic_http_hook = true;
	},
	1
);
add_action(
	'automatic_updates_complete',
	static function ( $results ) use ( &$ran_wp_release_updater_test_automatic_http_complete ): void {
		if ( ! is_array( $results ) || ! is_array( $results['plugin'] ?? null ) ) {
			return;
		}
		foreach ( $results['plugin'] as $ran_wp_release_updater_test_automatic_http_result ) {
			$ran_wp_release_updater_test_automatic_http_item = is_object( $ran_wp_release_updater_test_automatic_http_result ) ? ( $ran_wp_release_updater_test_automatic_http_result->item ?? null ) : null;
			if ( ! is_object( $ran_wp_release_updater_test_automatic_http_item ) || 'ran-wp-release-updater-example/ran-wp-release-updater-example.php' !== ( $ran_wp_release_updater_test_automatic_http_item->plugin ?? null ) ) {
				continue;
			}
			++$ran_wp_release_updater_test_automatic_http_complete['result_count'];
			if ( true === ( $ran_wp_release_updater_test_automatic_http_result->result ?? null ) ) {
				++$ran_wp_release_updater_test_automatic_http_complete['success_count'];
			}
		}
	},
);
add_filter(
	'automatic_updates_is_vcs_checkout',
	static function ( $checkout ) use ( &$ran_wp_release_updater_test_automatic_http_vcs ): bool {
		$ran_wp_release_updater_test_automatic_http_vcs = $checkout;
		return false;
	}
);
register_shutdown_function(
	static function () use ( $ran_wp_release_updater_test_automatic_http_log, &$ran_wp_release_updater_test_automatic_http_calls, &$ran_wp_release_updater_test_automatic_http_writes, &$ran_wp_release_updater_test_automatic_http_complete, &$ran_wp_release_updater_test_automatic_http_hook, &$ran_wp_release_updater_test_automatic_http_vcs ): void {
		$ran_wp_release_updater_test_automatic_http_target = null;
		foreach ( ( $GLOBALS['wp_filter']['update_plugins_github.com']->callbacks ?? array() ) as $ran_wp_release_updater_test_automatic_http_priority ) {
			foreach ( $ran_wp_release_updater_test_automatic_http_priority as $ran_wp_release_updater_test_automatic_http_callback ) {
				$ran_wp_release_updater_test_automatic_http_function = $ran_wp_release_updater_test_automatic_http_callback['function'] ?? null;
				if ( is_array( $ran_wp_release_updater_test_automatic_http_function ) && is_object( $ran_wp_release_updater_test_automatic_http_function[0] ) && is_callable( array( $ran_wp_release_updater_test_automatic_http_function[0], 'status' ) ) ) {
					$ran_wp_release_updater_test_automatic_http_target = $ran_wp_release_updater_test_automatic_http_function[0]->status();
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Persist exact disposable cron evidence for the parent process.
		file_put_contents(
			$ran_wp_release_updater_test_automatic_http_log,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON retains exact structured cron evidence for the parent process.
			json_encode(
				array(
					'calls'                 => $ran_wp_release_updater_test_automatic_http_calls,
					'writes'                => $ran_wp_release_updater_test_automatic_http_writes,
					'doing_cron'            => wp_doing_cron(),
					'hook'                  => $ran_wp_release_updater_test_automatic_http_hook,
					'complete_plugin'       => $ran_wp_release_updater_test_automatic_http_complete,
					'vcs_checkout_observed' => $ran_wp_release_updater_test_automatic_http_vcs,
					'target'                => $ran_wp_release_updater_test_automatic_http_target,
				),
				JSON_THROW_ON_ERROR
			) . "\n"
		);
	}
);
/** @return array<string,mixed> */
function ran_wp_release_updater_test_automatic_http_response( mixed $ran_wp_release_updater_test_automatic_http_body, ?string $ran_wp_release_updater_test_automatic_http_filename = null ): array {
	return array(
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Return exact controlled provider response bytes at the HTTP boundary.
		'body'     => null === $ran_wp_release_updater_test_automatic_http_body ? '' : json_encode( $ran_wp_release_updater_test_automatic_http_body, JSON_THROW_ON_ERROR ),
		'headers'  => array(),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'filename' => $ran_wp_release_updater_test_automatic_http_filename,
	);
}

/** @return array<string,mixed> */
function ran_wp_release_updater_test_automatic_http_raw_response( string $ran_wp_release_updater_test_automatic_http_body ): array {
	return array(
		'body'     => $ran_wp_release_updater_test_automatic_http_body,
		'headers'  => array(),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
	);
}
