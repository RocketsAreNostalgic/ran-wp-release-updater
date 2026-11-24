<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Support;

/** Minimal wpdb double: its opaque prepared tokens prevent tests from parsing SQL. */
final class FakeOptionDatabase {

	public string $options    = 'wp_options';
	public string $last_error = '';
	/** @var array<string, array{option_value:string,autoload:string}> */
	private array $rows = array();
	/** @var array<string, array{sql:string,args:array<int|string,mixed>}> */
	private array $prepared = array();
	/** @var array<string, int> */
	private array $write_failures = array();
	private ?string $next_error   = null;
	/** @var array<string, array{after:int,callback:callable}> */
	private array $read_hooks = array();
	/** @var array<string, callable> */
	private array $write_hooks = array();
	/** @var list<string> */
	private array $read_option_names = array();
	private int $sequence            = 0;

	public function __construct( private int $time = 0 ) {}

	public function set_time( int $time ): void {
		$this->time = $time;
	}

	public function time(): int {
		return $this->time;
	}

	/** Seed an option row directly in the fake wp_options store. */
	public function seed_option( string $name, string $value, string $autoload = 'off' ): void {
		$this->rows[ $name ] = array(
			'option_value' => $value,
			'autoload'     => $autoload,
		);
	}

	public function delete_option( string $name ): void {
		unset( $this->rows[ $name ] );
	}

	public function fail_next_write( string $name ): void {
		$this->write_failures[ $name ] = ( $this->write_failures[ $name ] ?? 0 ) + 1;
	}

	public function fail_next_with_error( string $error ): void {
		$this->next_error = $error;
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

	public function get_row( string $query ): ?object {
		if ( null !== $this->next_error ) {
			$this->last_error = $this->next_error;
			$this->next_error = null;
			return null;
		}
		$prepared = $this->prepared[ $query ] ?? null;
		if ( null === $prepared || ! str_starts_with( $prepared['sql'], 'SELECT option_value FROM %i WHERE option_name = %s' ) ) {
			return null;
		}
		[ $table, $name ] = $prepared['args'];
		if ( $table !== $this->options || ! is_string( $name ) ) {
			return null;
		}
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
		if ( ! isset( $this->rows[ $name ] ) ) {
			return null;
		}
		return (object) array( 'option_value' => $this->rows[ $name ]['option_value'] );
	}

	public function query( string $query ): int|false {
		if ( null !== $this->next_error ) {
			$this->last_error = $this->next_error;
			$this->next_error = null;
			return false;
		}
		$prepared = $this->prepared[ $query ] ?? null;
		if ( null === $prepared ) {
			return 0;
		}

		if ( str_starts_with( $prepared['sql'], 'INSERT IGNORE INTO %i' ) ) {
			[ $table, $name, $value, $autoload ] = $prepared['args'];
			if ( $table !== $this->options || ! is_string( $name ) || ! is_string( $value ) || ! is_string( $autoload ) ) {
				return false;
			}
			if ( isset( $this->write_hooks[ $name ] ) ) {
				$hook = $this->write_hooks[ $name ];
				unset( $this->write_hooks[ $name ] );
				$hook( $this );
			}
			if ( $this->consume_write_failure( $name ) ) {
				return false;
			}
			if ( isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			$this->rows[ $name ] = array(
				'option_value' => $value,
				'autoload'     => $autoload,
			);
			return 1;
		}

		if ( str_starts_with( $prepared['sql'], 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = %s' ) ) {
			[ $table, $name, $expected ] = $prepared['args'];
			if ( $table !== $this->options || ! is_string( $name ) || ! is_string( $expected ) ) {
				return false;
			}
			if ( isset( $this->write_hooks[ $name ] ) ) {
				$hook = $this->write_hooks[ $name ];
				unset( $this->write_hooks[ $name ] );
				$hook( $this );
			}
			if ( $this->consume_write_failure( $name ) ) {
				return false;
			}
			if ( ! isset( $this->rows[ $name ] ) || ! hash_equals( $expected, $this->rows[ $name ]['option_value'] ) ) {
				return 0;
			}
			unset( $this->rows[ $name ] );
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
