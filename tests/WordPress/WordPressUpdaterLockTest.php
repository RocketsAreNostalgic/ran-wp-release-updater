<?php

declare(strict_types=1);

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Keep global WordPress cache stub and namespaced test code in the same isolated fixture.
namespace {
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress exposes object-cache invalidation through this global function name.
		function wp_cache_delete( string $key, string $group = '' ): bool {
			$GLOBALS['ran_wp_release_updater_test_cache_deletes'][] = array( $key, $group );
			return true;
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
	}
}
