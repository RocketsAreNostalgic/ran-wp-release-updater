<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

/**
 * Coordinates release-update mutations on WordPress Core's auto_updater.lock contract.
 *
 * Uses fractional tokens compatible with WordPress Core's integer timestamps,
 * non-autoloaded option rows ('off'), INSERT IGNORE contention handling, and
 * CAST(option_value AS BINARY) exact-token deletion without deprecated BINARY
 * operator syntax.
 */
final class WordPressUpdaterLock {

	public const OPTION_NAME       = 'auto_updater.lock';
	public const STALE_SECONDS     = 3600;
	private const ACQUIRE_ATTEMPTS = 3;

	private ?\Closure $clock;

	/**
	 * Process-local refcounted lock state for standalone multi-item bulk upgrades
	 * sharing a single wpdb handle sequentially within one request.
	 *
	 * @var \WeakMap<object, array{token:string,owners:int,settled:bool}>|null
	 */
	private static ?\WeakMap $multi_run_locks = null;

	public function __construct(
		private object $wpdb,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? null : \Closure::fromCallable( $clock );
	}

	/**
	 * Parses the integer timestamp prefix from a WordPress Core integer lock
	 * or a 27-digit fractional RAN lock token.
	 */
	public static function parse_timestamp( string $token ): ?int {
		if ( 1 !== preg_match( '/\A([0-9]+)(?:\.[0-9]{27})?\z/D', $token, $matches ) ) {
			return null;
		}
		$seconds = $matches[1];
		if ( strlen( $seconds ) > 19 || ( strlen( $seconds ) > 1 && str_starts_with( $seconds, '0' ) ) ) {
			return null;
		}
		$parsed = filter_var( $seconds, FILTER_VALIDATE_INT );
		return is_int( $parsed ) && $parsed >= 0 ? $parsed : null;
	}

	/**
	 * Atomically acquires auto_updater.lock, reclaiming only strictly expired
	 * valid lock rows and failing closed on active, corrupt, or error states.
	 */
	public function acquire(): ?string {
		$table = $this->options_table();
		if ( null === $table ) {
			return null;
		}
		$now = $this->now();
		if ( null === $now ) {
			return null;
		}
		$token = $this->generate_token( $now );
		if ( null === $token ) {
			return null;
		}

		for ( $attempt = 0; $attempt < self::ACQUIRE_ATTEMPTS; ++$attempt ) {
			$inserted = $this->insert_lock( $table, $token );
			if ( true === $inserted ) {
				return $token;
			}
			if ( null === $inserted ) {
				return null;
			}

			$row = $this->read_lock_row( $table );
			if ( ! $row['ok'] ) {
				return null;
			}
			if ( ! $row['exists'] ) {
				continue;
			}
			$existing = $row['value'];
			if ( ! is_string( $existing ) ) {
				return null;
			}
			$timestamp = self::parse_timestamp( $existing );
			if ( null === $timestamp || ! self::is_expired( $timestamp, $now ) ) {
				return null;
			}

			$deleted = $this->delete_lock_exact( $table, $existing );
			if ( null === $deleted ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Acquires auto_updater.lock for a standalone multi-item bulk upgrade,
	 * reusing the process-local multi-run lock only after the previous item
	 * settled and the database row still matches its exact token.
	 */
	public function acquire_multi_run(): ?string {
		$locks = self::multi_run_map();
		if ( isset( $locks[ $this->wpdb ] ) ) {
			$entry = $locks[ $this->wpdb ];
			if ( ! $entry['settled'] ) {
				return null;
			}
			if ( ! $this->verify( $entry['token'] ) ) {
				unset( $locks[ $this->wpdb ] );
				return null;
			}
			$locks[ $this->wpdb ] = array(
				'token'   => $entry['token'],
				'owners'  => $entry['owners'] + 1,
				'settled' => false,
			);
			return $entry['token'];
		}

		$token = $this->acquire();
		if ( null === $token ) {
			return null;
		}
		$locks[ $this->wpdb ] = array(
			'token'   => $token,
			'owners'  => 1,
			'settled' => false,
		);
		return $token;
	}

	/**
	 * Marks the current standalone multi-item bulk entry settled so the next
	 * sequential item in the same bulk upgrade may reuse the verified lock.
	 */
	public function mark_multi_run_settled( string $token ): void {
		$locks = self::multi_run_map();
		if ( ! isset( $locks[ $this->wpdb ] ) ) {
			return;
		}
		$entry = $locks[ $this->wpdb ];
		if ( hash_equals( $entry['token'], $token ) ) {
			$locks[ $this->wpdb ] = array(
				'token'   => $entry['token'],
				'owners'  => $entry['owners'],
				'settled' => true,
			);
		}
	}

	/**
	 * Reads the current active, non-expired auto_updater.lock token (for example,
	 * WordPress Core's lock during wp_maybe_auto_update). Returns null if absent,
	 * expired, corrupt, or unreadable.
	 */
	public function current_token(): ?string {
		$table = $this->options_table();
		if ( null === $table ) {
			return null;
		}
		$now = $this->now();
		if ( null === $now ) {
			return null;
		}
		$row = $this->read_lock_row( $table );
		if ( ! $row['ok'] || ! $row['exists'] || ! is_string( $row['value'] ) ) {
			return null;
		}
		$timestamp = self::parse_timestamp( $row['value'] );
		if ( null === $timestamp || self::is_expired( $timestamp, $now ) ) {
			return null;
		}
		return $row['value'];
	}

	/**
	 * Verifies that auto_updater.lock is still present, unexpired, and byte-equal
	 * to the expected owner token.
	 */
	public function verify( string $token ): bool {
		$timestamp = self::parse_timestamp( $token );
		if ( null === $timestamp ) {
			return false;
		}
		$table = $this->options_table();
		if ( null === $table ) {
			return false;
		}
		$now = $this->now();
		if ( null === $now || self::is_expired( $timestamp, $now ) ) {
			return false;
		}
		$row = $this->read_lock_row( $table );
		return $row['ok'] && $row['exists'] && is_string( $row['value'] ) && hash_equals( $token, $row['value'] );
	}

	/**
	 * Releases auto_updater.lock only if the stored row byte-matches $token.
	 */
	public function release( string $token ): bool {
		if ( null === self::parse_timestamp( $token ) ) {
			return false;
		}
		$table = $this->options_table();
		if ( null === $table ) {
			return false;
		}
		return true === $this->delete_lock_exact( $table, $token );
	}

	/**
	 * Decrements the standalone multi-run refcount and deletes the database lock
	 * row once the final owner finishes.
	 */
	public function release_multi_run( string $token ): bool {
		$locks = self::multi_run_map();
		if ( isset( $locks[ $this->wpdb ] ) && hash_equals( $locks[ $this->wpdb ]['token'], $token ) ) {
			$entry = $locks[ $this->wpdb ];
			if ( $entry['owners'] > 1 ) {
				$locks[ $this->wpdb ] = array(
					'token'   => $entry['token'],
					'owners'  => $entry['owners'] - 1,
					'settled' => true,
				);
				return true;
			}
			unset( $locks[ $this->wpdb ] );
		}
		return $this->release( $token );
	}

	/** @return \WeakMap<object, array{token:string,owners:int,settled:bool}> */
	private static function multi_run_map(): \WeakMap {
		if ( null === self::$multi_run_locks ) {
			self::$multi_run_locks = new \WeakMap();
		}
		return self::$multi_run_locks;
	}

	private static function is_expired( int $timestamp, int $now ): bool {
		return ( $now - $timestamp ) > self::STALE_SECONDS;
	}

	private function now(): ?int {
		try {
			$now = null === $this->clock ? time() : ( $this->clock )();
		} catch ( \Throwable ) {
			return null;
		}
		return is_int( $now ) && $now >= 0 ? $now : null;
	}

	private function generate_token( int $now ): ?string {
		try {
			return sprintf(
				'%d.%09d%09d%09d',
				$now,
				random_int( 0, 999999999 ),
				random_int( 0, 999999999 ),
				random_int( 0, 999999999 )
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	private function options_table(): ?string {
		if (
			! method_exists( $this->wpdb, 'prepare' )
			|| ! method_exists( $this->wpdb, 'query' )
			|| ! method_exists( $this->wpdb, 'get_row' )
		) {
			return null;
		}
		if ( property_exists( $this->wpdb, 'base_prefix' ) ) {
			if ( ! is_string( $this->wpdb->base_prefix ) || 1 !== preg_match( '/\A[A-Za-z0-9_]*\z/D', $this->wpdb->base_prefix ) ) {
				return null;
			}
			return $this->wpdb->base_prefix . 'options';
		}
		if ( ! property_exists( $this->wpdb, 'options' ) || ! is_string( $this->wpdb->options ) || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $this->wpdb->options ) ) {
			return null;
		}
		return $this->wpdb->options;
	}

	private function insert_lock( string $table, string $token ): ?bool {
		$sql = $this->prepare(
			'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
			$table,
			self::OPTION_NAME,
			$token,
			'off'
		);
		if ( null === $sql ) {
			return null;
		}
		$affected = $this->query( $sql );
		if ( null === $affected ) {
			return null;
		}
		if ( 1 === $affected ) {
			$this->clear_option_cache();
			return true;
		}
		return 0 === $affected ? false : null;
	}

	/** @return array{ok:bool,exists:bool,value:?string} */
	private function read_lock_row( string $table ): array {
		$sql  = $this->prepare(
			'SELECT option_value FROM %i WHERE option_name = %s',
			$table,
			self::OPTION_NAME
		);
		$wpdb = $this->wpdb;
		if ( null === $sql || ! method_exists( $wpdb, 'get_row' ) ) {
			return array(
				'ok'     => false,
				'exists' => false,
				'value'  => null,
			);
		}
		try {
			$this->reset_last_error();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above with validated options-table %i and %s placeholders.
			$row = $wpdb->get_row( $sql );
		} catch ( \Throwable ) {
			return array(
				'ok'     => false,
				'exists' => false,
				'value'  => null,
			);
		}
		if ( $this->has_db_error() ) {
			return array(
				'ok'     => false,
				'exists' => false,
				'value'  => null,
			);
		}
		if ( null === $row ) {
			return array(
				'ok'     => true,
				'exists' => false,
				'value'  => null,
			);
		}
		if ( is_object( $row ) && property_exists( $row, 'option_value' ) && is_string( $row->option_value ) ) {
			return array(
				'ok'     => true,
				'exists' => true,
				'value'  => $row->option_value,
			);
		}
		if ( is_array( $row ) && array_key_exists( 'option_value', $row ) && is_string( $row['option_value'] ) ) {
			return array(
				'ok'     => true,
				'exists' => true,
				'value'  => $row['option_value'],
			);
		}
		return array(
			'ok'     => false,
			'exists' => true,
			'value'  => null,
		);
	}

	private function delete_lock_exact( string $table, string $token ): ?bool {
		$sql = $this->prepare(
			'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = %s',
			$table,
			self::OPTION_NAME,
			$token
		);
		if ( null === $sql ) {
			return null;
		}
		$affected = $this->query( $sql );
		if ( null === $affected ) {
			return null;
		}
		if ( 1 === $affected ) {
			$this->clear_option_cache();
			return true;
		}
		if ( 0 === $affected ) {
			$this->clear_option_cache();
			return false;
		}
		return null;
	}

	private function prepare( string $query, mixed ...$args ): ?string {
		if ( ! method_exists( $this->wpdb, 'prepare' ) ) {
			return null;
		}
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass fixed parameterized SQL templates with %i and %s placeholders.
			$prepared = $this->wpdb->prepare( $query, ...$args );
		} catch ( \Throwable ) {
			return null;
		}
		return is_string( $prepared ) && '' !== $prepared ? $prepared : null;
	}

	private function query( string $sql ): ?int {
		$wpdb = $this->wpdb;
		if ( ! method_exists( $wpdb, 'query' ) ) {
			return null;
		}
		try {
			$this->reset_last_error();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above with validated options-table %i and %s placeholders.
			$result = $wpdb->query( $sql );
		} catch ( \Throwable ) {
			return null;
		}
		if ( false === $result || $this->has_db_error() || ! is_int( $result ) ) {
			return null;
		}
		return $result;
	}

	private function reset_last_error(): void {
		if ( property_exists( $this->wpdb, 'last_error' ) ) {
			$this->wpdb->last_error = '';
		}
	}

	private function has_db_error(): bool {
		return property_exists( $this->wpdb, 'last_error' )
			&& is_string( $this->wpdb->last_error )
			&& '' !== trim( $this->wpdb->last_error );
	}

	private function clear_option_cache(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		if (
			function_exists( 'is_multisite' )
			&& is_multisite()
			&& function_exists( 'get_current_blog_id' )
			&& function_exists( 'get_main_site_id' )
		) {
			$current_blog = get_current_blog_id();
			$main_site    = get_main_site_id();
			if ( $main_site > 0 && $current_blog !== $main_site ) {
				if ( function_exists( 'wp_cache_switch_to_blog' ) ) {
					wp_cache_switch_to_blog( $main_site );
					try {
						wp_cache_delete( self::OPTION_NAME, 'options' );
						wp_cache_delete( 'notoptions', 'options' );
					} finally {
						wp_cache_switch_to_blog( $current_blog );
					}
					return;
				}
				if ( function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
					switch_to_blog( $main_site );
					try {
						wp_cache_delete( self::OPTION_NAME, 'options' );
						wp_cache_delete( 'notoptions', 'options' );
					} finally {
						restore_current_blog();
					}
					return;
				}
			}
		}
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
