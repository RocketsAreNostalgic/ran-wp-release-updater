<?php

declare(strict_types=1);

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Keep global WordPress cache stub and namespaced test code in the same isolated fixture.
namespace {
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress exposes object-cache invalidation through this global function name.
		function wp_cache_delete( string $key, string $group = '' ): bool {
			$GLOBALS['ran_wp_release_updater_test_cache_deletes'][] = array( $key, $group );
			$GLOBALS['ran_wp_release_updater_test_cache_events'][]  = array(
				'delete',
				$GLOBALS['ran_wp_release_updater_test_cache_blog_id'] ?? ( $GLOBALS['ran_wp_release_updater_test_blog_id'] ?? 1 ),
				$key,
				$group,
			);
			return true;
		}
	}
	if ( ! function_exists( 'is_multisite' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress exposes multisite detection through this global function name.
		function is_multisite(): bool {
			return true === ( $GLOBALS['ran_wp_release_updater_test_multisite'] ?? false );
		}
	}
	if ( ! function_exists( 'get_current_blog_id' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress exposes the current site ID through this global function name.
		function get_current_blog_id(): int {
			return is_int( $GLOBALS['ran_wp_release_updater_test_blog_id'] ?? null ) ? $GLOBALS['ran_wp_release_updater_test_blog_id'] : 1;
		}
	}
	if ( ! function_exists( 'get_main_site_id' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress exposes the main site ID through this global function name.
		function get_main_site_id(): int {
			return is_int( $GLOBALS['ran_wp_release_updater_test_main_site_id'] ?? null ) ? $GLOBALS['ran_wp_release_updater_test_main_site_id'] : 1;
		}
	}
	if ( ! function_exists( 'wp_cache_switch_to_blog' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress exposes object-cache blog switching through this global function name.
		function wp_cache_switch_to_blog( int $blog_id ): void {
			$GLOBALS['ran_wp_release_updater_test_cache_blog_id']  = $blog_id;
			$GLOBALS['ran_wp_release_updater_test_cache_events'][] = array( 'switch', $blog_id );
		}
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- Colocate the global WordPress cache stub with its namespaced unit tests.
namespace RAN\WPReleaseUpdater\V1\Tests\WordPress {

	require_once dirname( __DIR__ ) . '/Support/FakeOptionDatabase.php';

	use PHPUnit\Framework\TestCase;
	use RAN\WPReleaseUpdater\V1\Tests\Support\FakeOptionDatabase;
	use RAN\WPReleaseUpdater\V1\WordPress\WordPressUpdaterLock;

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Keep the conditional WordPress cache stub and its test class in the same self-contained fixture.
	final class WordPressUpdaterLockTest extends TestCase {

		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function setUp(): void {
			$GLOBALS['ran_wp_release_updater_test_cache_deletes'] = array();
			$GLOBALS['ran_wp_release_updater_test_cache_events']  = array();
			$GLOBALS['ran_wp_release_updater_test_multisite']     = false;
			$GLOBALS['ran_wp_release_updater_test_blog_id']       = 1;
			$GLOBALS['ran_wp_release_updater_test_main_site_id']  = 1;
			$GLOBALS['ran_wp_release_updater_test_cache_blog_id'] = 1;
		}

		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function tearDown(): void {
			$GLOBALS['ran_wp_release_updater_test_multisite']     = false;
			$GLOBALS['ran_wp_release_updater_test_blog_id']       = 1;
			$GLOBALS['ran_wp_release_updater_test_main_site_id']  = 1;
			$GLOBALS['ran_wp_release_updater_test_cache_blog_id'] = 1;
		}

		public function test_acquire_writes_fractional_token_with_off_autoload_and_clears_option_cache(): void {
			$database = new FakeOptionDatabase();
			$now      = 1_700_000_000;
			$lock     = new WordPressUpdaterLock( $database, static fn (): int => $now );

			$token = $lock->acquire();
			self::assertIsString( $token );
			self::assertMatchesRegularExpression( '/\A1700000000\.[0-9]{27}\z/D', $token );
			self::assertSame( $now, WordPressUpdaterLock::parse_timestamp( $token ) );

			$rows = $database->rows();
			self::assertArrayHasKey( WordPressUpdaterLock::OPTION_NAME, $rows );
			self::assertSame( $token, $rows[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
			self::assertSame( 'off', $rows[ WordPressUpdaterLock::OPTION_NAME ]['autoload'] );
			self::assertSame(
				array(
					array( 'auto_updater.lock', 'options' ),
					array( 'notoptions', 'options' ),
				),
				$GLOBALS['ran_wp_release_updater_test_cache_deletes']
			);
			self::assertTrue( $lock->verify( $token ) );
			self::assertSame( $token, $lock->current_token() );

			$GLOBALS['ran_wp_release_updater_test_cache_deletes'] = array();
			self::assertTrue( $lock->release( $token ) );
			self::assertSame( array(), $database->rows() );
			self::assertSame(
				array(
					array( 'auto_updater.lock', 'options' ),
					array( 'notoptions', 'options' ),
				),
				$GLOBALS['ran_wp_release_updater_test_cache_deletes']
			);
			self::assertFalse( $lock->verify( $token ) );
			self::assertNull( $lock->current_token() );
		}

		public function test_active_core_integer_or_fractional_lock_blocks_competing_acquire(): void {
			$now = 1_700_000_000;
			foreach ( array(
				'core integer timestamp'         => (string) $now,
				'exact 3600s boundary not stale' => (string) ( $now - WordPressUpdaterLock::STALE_SECONDS ),
				'fractional ran token'           => $now . '.000000001000000002000000003',
			) as $label => $seeded ) {
				$database = new FakeOptionDatabase();
				$database->seed_option( WordPressUpdaterLock::OPTION_NAME, $seeded, 'off' );
				$lock = new WordPressUpdaterLock( $database, static fn (): int => $now );

				self::assertNull( $lock->acquire(), $label );
				self::assertSame( $seeded, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'], $label );
				self::assertSame( $seeded, $lock->current_token(), $label );
				self::assertTrue( $lock->verify( $seeded ), $label );
			}
		}

		public function test_stale_lock_is_reclaimed_atomically_while_corrupt_lock_fails_closed(): void {
			$now      = 1_700_010_000;
			$database = new FakeOptionDatabase();
			$stale    = (string) ( $now - WordPressUpdaterLock::STALE_SECONDS - 1 );
			$database->seed_option( WordPressUpdaterLock::OPTION_NAME, $stale, 'off' );

			$lock = new WordPressUpdaterLock( $database, static fn (): int => $now );
			self::assertNull( $lock->current_token() );
			self::assertFalse( $lock->verify( $stale ) );

			$reclaimed = $lock->acquire();
			self::assertIsString( $reclaimed );
			self::assertNotSame( $stale, $reclaimed );
			self::assertSame( $reclaimed, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );

			foreach ( array( '', 'corrupt', '-1', '001700000000', '1700000000.123', '{"lock":1}', "1700000000\n" ) as $corrupt ) {
				$corrupt_db = new FakeOptionDatabase();
				$corrupt_db->seed_option( WordPressUpdaterLock::OPTION_NAME, $corrupt, 'off' );
				$corrupt_lock = new WordPressUpdaterLock( $corrupt_db, static fn (): int => $now );

				self::assertNull( $corrupt_lock->acquire(), 'Corrupt row must fail closed: ' . $corrupt );
				self::assertNull( $corrupt_lock->current_token(), 'Corrupt row must not validate: ' . $corrupt );
				self::assertSame( $corrupt, $corrupt_db->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
			}
		}

		public function test_release_deletes_only_the_exact_owner_token(): void {
			$now      = 1_700_000_000;
			$database = new FakeOptionDatabase();
			$lock     = new WordPressUpdaterLock( $database, static fn (): int => $now );
			$first    = $lock->acquire();
			self::assertIsString( $first );

			$other = $now . '.999999999999999999999999999';
			self::assertFalse( $lock->release( $other ) );
			self::assertSame( $first, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );

			$database->force_option_value( WordPressUpdaterLock::OPTION_NAME, $other );
			self::assertFalse( $lock->verify( $first ) );
			self::assertFalse( $lock->release( $first ) );
			self::assertSame( $other, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
			self::assertTrue( $lock->release( $other ) );
			self::assertSame( array(), $database->rows() );
		}

		public function test_reclaim_retries_when_competing_owner_releases_mid_race(): void {
			$now      = 1_700_000_000;
			$database = new FakeOptionDatabase();
			$database->seed_option( WordPressUpdaterLock::OPTION_NAME, (string) $now, 'off' );
			$database->mutate_on_read(
				WordPressUpdaterLock::OPTION_NAME,
				0,
				static function ( FakeOptionDatabase $db ): void {
					$db->delete_option( WordPressUpdaterLock::OPTION_NAME );
				}
			);

			$lock  = new WordPressUpdaterLock( $database, static fn (): int => $now );
			$token = $lock->acquire();
			self::assertIsString( $token );
			self::assertSame( $token, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
		}

		public function test_database_errors_and_invalid_wpdb_fail_closed(): void {
			$now      = 1_700_000_000;
			$database = new FakeOptionDatabase();
			$database->fail_next_with_error( 'Simulated MySQL failure.' );
			$lock = new WordPressUpdaterLock( $database, static fn (): int => $now );

			self::assertNull( $lock->acquire() );
			self::assertSame( array(), $database->rows() );

			$token = $lock->acquire();
			self::assertIsString( $token );
			$database->fail_next_with_error( 'Read failure.' );
			self::assertFalse( $lock->verify( $token ) );

			$database->fail_next_with_error( 'Delete failure.' );
			self::assertFalse( $lock->release( $token ) );
			self::assertArrayHasKey( WordPressUpdaterLock::OPTION_NAME, $database->rows() );

			$invalid_table          = new FakeOptionDatabase();
			$invalid_table->options = 'wp_options; DROP TABLE wp_options';
			$bad_lock               = new WordPressUpdaterLock( $invalid_table, static fn (): int => $now );
			self::assertNull( $bad_lock->acquire() );
			self::assertNull( $bad_lock->current_token() );
			self::assertFalse( $bad_lock->verify( $token ) );
			self::assertFalse( $bad_lock->release( $token ) );

			$missing_methods = new WordPressUpdaterLock( new \stdClass(), static fn (): int => $now );
			self::assertNull( $missing_methods->acquire() );
		}

		public function test_multi_run_shares_lock_only_after_settlement_and_never_with_single_run(): void {
			$now      = 1_700_000_000;
			$database = new FakeOptionDatabase();
			$first    = new WordPressUpdaterLock( $database, static fn (): int => $now );
			$second   = new WordPressUpdaterLock( $database, static fn (): int => $now );

			$token_a = $first->acquire_multi_run();
			self::assertIsString( $token_a );
			// Unsettled multi-run lock blocks overlapping multi-run and single-run acquires.
			self::assertNull( $second->acquire_multi_run() );
			self::assertNull( $second->acquire() );

			$first->mark_multi_run_settled( $token_a );
			// Single-run acquire still fails closed even after settlement.
			self::assertNull( $second->acquire() );
			// Sequential multi-run item reuses the exact verified token.
			$token_b = $second->acquire_multi_run();
			self::assertSame( $token_a, $token_b );

			// First owner releasing keeps the DB lock alive for the second owner.
			self::assertTrue( $first->release_multi_run( $token_a ) );
			self::assertTrue( $second->verify( $token_b ) );
			// Final owner releasing deletes the DB lock row.
			self::assertTrue( $second->release_multi_run( $token_b ) );
			self::assertSame( array(), $database->rows() );
		}

		public function test_failed_multi_run_item_restores_settlement_so_subsequent_bulk_items_proceed(): void {
			$now      = 1_700_000_000;
			$database = new FakeOptionDatabase();
			$item_1   = new WordPressUpdaterLock( $database, static fn (): int => $now );
			$item_2   = new WordPressUpdaterLock( $database, static fn (): int => $now );
			$item_3   = new WordPressUpdaterLock( $database, static fn (): int => $now );

			// Item 1 acquires the bulk lock and settles after install_package completes.
			$token_1 = $item_1->acquire_multi_run();
			self::assertIsString( $token_1 );
			$item_1->mark_multi_run_settled( $token_1 );

			// Item 2 acquires the settled bulk lock, then fails and releases before settling.
			$token_2 = $item_2->acquire_multi_run();
			self::assertSame( $token_1, $token_2 );
			self::assertTrue( $item_2->release_multi_run( $token_2 ) );
			self::assertTrue( $item_1->verify( $token_1 ) );

			// Item 3 must still be able to acquire the shared bulk lock and proceed.
			$token_3 = $item_3->acquire_multi_run();
			self::assertSame( $token_1, $token_3 );
			$item_3->mark_multi_run_settled( $token_3 );

			// Shutdown releases from item 1 and item 3 remove the DB lock cleanly.
			self::assertTrue( $item_1->release_multi_run( $token_1 ) );
			self::assertTrue( $item_3->verify( $token_3 ) );
			self::assertTrue( $item_3->release_multi_run( $token_3 ) );
			self::assertSame( array(), $database->rows() );
		}

		public function test_base_prefix_takes_precedence_over_subsite_options_and_invalid_base_prefix_fails_closed(): void {
			$now          = 1_700_000_000;
			$multisite_db = new class() {
				public string $base_prefix = 'wp_';
				public string $options     = 'wp_2_options';
				public string $last_error  = '';
				/** @var list<string> */
				public array $queried_tables = array();
				/** @var array<string, array<string, string>> */
				public array $tables = array();
				/** @var array<string, array{sql:string,args:list<mixed>}> */
				private array $prepared = array();
				private int $sequence   = 0;

				public function prepare( string $sql, mixed ...$args ): string {
					$key                    = 'q-' . ( ++$this->sequence );
					$this->prepared[ $key ] = array(
						'sql'  => $sql,
						'args' => array_values( $args ),
					);
					return $key;
				}

				public function get_row( string $query ): ?object {
					$entry = $this->prepared[ $query ] ?? null;
					if ( null === $entry ) {
						return null;
					}
					$table                  = (string) $entry['args'][0];
					$name                   = (string) $entry['args'][1];
					$this->queried_tables[] = $table;
					if ( ! isset( $this->tables[ $table ][ $name ] ) ) {
						return null;
					}
					return (object) array( 'option_value' => $this->tables[ $table ][ $name ] );
				}

				public function query( string $query ): int|false {
					$entry = $this->prepared[ $query ] ?? null;
					if ( null === $entry ) {
						return false;
					}
					$table                  = (string) $entry['args'][0];
					$name                   = (string) $entry['args'][1];
					$this->queried_tables[] = $table;
					if ( str_starts_with( $entry['sql'], 'INSERT IGNORE INTO %i' ) ) {
						if ( isset( $this->tables[ $table ][ $name ] ) ) {
							return 0;
						}
						$this->tables[ $table ][ $name ] = (string) $entry['args'][2];
						return 1;
					}
					if ( str_starts_with( $entry['sql'], 'DELETE FROM %i' ) ) {
						$expected = (string) $entry['args'][2];
						if ( ! isset( $this->tables[ $table ][ $name ] ) || ! hash_equals( $expected, $this->tables[ $table ][ $name ] ) ) {
							return 0;
						}
						unset( $this->tables[ $table ][ $name ] );
						return 1;
					}
					return 0;
				}
			};

			$lock  = new WordPressUpdaterLock( $multisite_db, static fn (): int => $now );
			$token = $lock->acquire();
			self::assertIsString( $token );
			self::assertArrayHasKey( 'wp_options', $multisite_db->tables );
			self::assertArrayNotHasKey( 'wp_2_options', $multisite_db->tables );
			self::assertSame( $token, $multisite_db->tables['wp_options'][ WordPressUpdaterLock::OPTION_NAME ] );
			self::assertTrue( $lock->verify( $token ) );
			self::assertTrue( $lock->release( $token ) );
			self::assertSame( array(), $multisite_db->tables['wp_options'] );
			self::assertSame( array( 'wp_options' ), array_values( array_unique( $multisite_db->queried_tables ) ) );

			// Empty base_prefix is valid and resolves to 'options'.
			$multisite_db->base_prefix    = '';
			$multisite_db->queried_tables = array();
			$empty_prefix_token           = $lock->acquire();
			self::assertIsString( $empty_prefix_token );
			self::assertSame( array( 'options' ), array_values( array_unique( $multisite_db->queried_tables ) ) );
			self::assertTrue( $lock->release( $empty_prefix_token ) );

			// Invalid base_prefix fails closed and never falls back to $wpdb->options.
			$multisite_db->base_prefix    = 'wp_; DROP TABLE wp_options';
			$multisite_db->queried_tables = array();
			self::assertNull( $lock->acquire() );
			self::assertNull( $lock->current_token() );
			self::assertFalse( $lock->verify( $token ) );
			self::assertFalse( $lock->release( $token ) );
			self::assertSame( array(), $multisite_db->queried_tables );
		}

		public function test_multisite_subsite_cache_invalidation_switches_to_main_site_and_restores_current_blog(): void {
			$now      = 1_700_000_000;
			$database = new FakeOptionDatabase();
			$lock     = new WordPressUpdaterLock( $database, static fn (): int => $now );

			$GLOBALS['ran_wp_release_updater_test_multisite']     = true;
			$GLOBALS['ran_wp_release_updater_test_blog_id']       = 2;
			$GLOBALS['ran_wp_release_updater_test_main_site_id']  = 1;
			$GLOBALS['ran_wp_release_updater_test_cache_blog_id'] = 2;
			$GLOBALS['ran_wp_release_updater_test_cache_events']  = array();

			$token = $lock->acquire();
			self::assertIsString( $token );
			self::assertSame( 2, $GLOBALS['ran_wp_release_updater_test_cache_blog_id'] );
			self::assertSame(
				array(
					array( 'switch', 1 ),
					array( 'delete', 1, 'auto_updater.lock', 'options' ),
					array( 'delete', 1, 'notoptions', 'options' ),
					array( 'switch', 2 ),
				),
				$GLOBALS['ran_wp_release_updater_test_cache_events']
			);

			$GLOBALS['ran_wp_release_updater_test_cache_events'] = array();
			self::assertTrue( $lock->release( $token ) );
			self::assertSame( 2, $GLOBALS['ran_wp_release_updater_test_cache_blog_id'] );
			self::assertSame(
				array(
					array( 'switch', 1 ),
					array( 'delete', 1, 'auto_updater.lock', 'options' ),
					array( 'delete', 1, 'notoptions', 'options' ),
					array( 'switch', 2 ),
				),
				$GLOBALS['ran_wp_release_updater_test_cache_events']
			);

			// Main-site operations on multisite invalidate directly without switching blogs.
			$GLOBALS['ran_wp_release_updater_test_blog_id']       = 1;
			$GLOBALS['ran_wp_release_updater_test_cache_blog_id'] = 1;
			$GLOBALS['ran_wp_release_updater_test_cache_events']  = array();
			$main_token = $lock->acquire();
			self::assertIsString( $main_token );
			self::assertSame(
				array(
					array( 'delete', 1, 'auto_updater.lock', 'options' ),
					array( 'delete', 1, 'notoptions', 'options' ),
				),
				$GLOBALS['ran_wp_release_updater_test_cache_events']
			);
			self::assertTrue( $lock->release( $main_token ) );
		}
	}
}
