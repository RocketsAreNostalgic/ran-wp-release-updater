<?php

declare(strict_types=1);

namespace Tests\Support;

/** Minimal wpdb double: its opaque prepared tokens prevent tests from parsing SQL. */
final class FakeOptionDatabase {

	public string $options = 'wp_options';
	/** @var array<string, array{option_value:string,autoload:string}> */
	private array $rows = array();
	/** @var array<string, array{sql:string,args:list<string>}> */
	private array $prepared = array();
	/** @var array<string, int> */
	private array $write_failures = array();
	/** @var array<string, array{after:int,callback:callable}> */
	private array $read_hooks = array();
	/** @var array<string, callable> */
	private array $write_hooks = array();
	/** @var list<string> */
	private array $read_option_names = array();
	/** @var array{after:int,callback:callable}|null */
	private ?array $time_hook = null;
	private int $sequence     = 0;
	public function __construct( private int $time ) {}

	public function set_time( int $time ): void {
		$this->time = $time;
	}

	/** Seed an unrelated legacy option without giving the coordinator a migration seam. */
	public function seed_option( string $name, string $value, string $autoload = 'no' ): void {
		$this->rows[ $name ] = array(
			'option_value' => $value,
			'autoload'     => $autoload,
		);
	}

	public function fail_next_write( string $name ): void {
		$this->write_failures[ $name ] = ( $this->write_failures[ $name ] ?? 0 ) + 1;
	}

	public function mutate_on_read( string $name, int $after, callable $callback ): void {
		$this->read_hooks[ $name ] = array(
			'after'    => $after,
			'callback' => $callback,
		);
	}

	public function mutate_on_next_write( string $name, callable $callback ): void {
		$this->write_hooks[ $name ] = $callback;
	}

	public function mutate_on_time_read( int $after, callable $callback ): void {
		$this->time_hook = array(
			'after'    => $after,
			'callback' => $callback,
		);
	}

	public function force_option_value( string $name, string $value ): void {
		if ( isset( $this->rows[ $name ] ) ) {
			$this->rows[ $name ]['option_value'] = $value;
		}
	}

	/** @return list<string> */
	public function prepared_sql(): array {
		return array_values( array_column( $this->prepared, 'sql' ) );
	}

	/** @return list<string> */
	public function read_option_names(): array {
		return $this->read_option_names;
	}

	/** @return array<string, array{option_value:string,autoload:string}> */
	public function rows(): array {
		ksort( $this->rows, SORT_STRING );
		return $this->rows;
	}

	public function prepare( string $sql, mixed ...$args ): string {
		$token                    = 'prepared-' . ( ++$this->sequence );
		$this->prepared[ $token ] = array(
			'sql'  => $sql,
			'args' => $args,
		);
		return $token;
	}

	public function get_var( string $query ): string|int|null {
		if ( 'SELECT UNIX_TIMESTAMP()' === $query ) {
			if ( null !== $this->time_hook ) {
				if ( 0 === $this->time_hook['after'] ) {
					$hook            = $this->time_hook['callback'];
					$this->time_hook = null;
					$hook( $this );
				} else {
					--$this->time_hook['after'];
				}
			}
			return $this->time;
		}

		$prepared = $this->prepared[ $query ] ?? null;
		if ( null === $prepared || ! str_starts_with( $prepared['sql'], 'SELECT option_value' ) ) {
			return null;
		}

		$name                      = $prepared['args'][0];
		$this->read_option_names[] = $name;
		if ( isset( $this->read_hooks[ $name ] ) ) {
			if ( 0 === $this->read_hooks[ $name ]['after'] ) {
				$hook = $this->read_hooks[ $name ]['callback'];
				unset( $this->read_hooks[ $name ] );
				$hook( $this );
			} else {
				--$this->read_hooks[ $name ]['after'];
			}
		}
		return $this->rows[ $name ]['option_value'] ?? null;
	}

	public function query( string $query ): int {
		$prepared = $this->prepared[ $query ] ?? null;
		if ( null === $prepared ) {
			return 0;
		}

		if ( str_starts_with( $prepared['sql'], 'INSERT INTO' ) ) {
			[ $name, $value ] = $prepared['args'];
			if ( isset( $this->write_hooks[ $name ] ) ) {
				$hook = $this->write_hooks[ $name ];
				unset( $this->write_hooks[ $name ] );
				$hook( $this );
			}
			if ( $this->consume_write_failure( $name ) || isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			$this->rows[ $name ] = array(
				'option_value' => $value,
				'autoload'     => 'no',
			);
			return 1;
		}

		if ( str_starts_with( $prepared['sql'], 'UPDATE' ) ) {
			[ $next, $name, $expected, $lease_deadline ] = $prepared['args'];
			if ( isset( $this->write_hooks[ $name ] ) ) {
				$hook = $this->write_hooks[ $name ];
				unset( $this->write_hooks[ $name ] );
				$hook( $this );
			}
			$expired      = str_contains( $prepared['sql'], 'UNIX_TIMESTAMP() > %d' );
			$lease_allows = $expired ? $this->time > $lease_deadline : $this->time <= $lease_deadline;
			if ( $this->consume_write_failure( $name ) || ! $lease_allows || ! isset( $this->rows[ $name ] ) || ! hash_equals( $expected, $this->rows[ $name ]['option_value'] ) || hash_equals( $next, $this->rows[ $name ]['option_value'] ) ) {
				return 0;
			}
			$this->rows[ $name ]['option_value'] = $next;
			return 1;
		}

		return 0;
	}

	private function consume_write_failure( string $name ): bool {
		if ( ! isset( $this->write_failures[ $name ] ) ) {
			return false;
		}
		if ( 1 === $this->write_failures[ $name ] ) {
			unset( $this->write_failures[ $name ] );
		} else {
			--$this->write_failures[ $name ];
		}
		return true;
	}
}
