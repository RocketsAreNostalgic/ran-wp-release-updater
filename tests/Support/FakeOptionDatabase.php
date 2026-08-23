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
	private array $writeFailures = array();
	/** @var array<string, array{after:int,callback:callable}> */
	private array $readHooks = array();
	/** @var array<string, callable> */
	private array $writeHooks = array();
	/** @var list<string> */
	private array $readOptionNames = array();
	/** @var array{after:int,callback:callable}|null */
	private ?array $timeHook = null;
	private int $sequence = 0;
	public function __construct( private int $time ) {}
	public function setTime( int $time ): void { $this->time = $time; }
	/** Seed an unrelated legacy option without giving the coordinator a migration seam. */
	public function seedOption( string $name, string $value, string $autoload = 'no' ): void { $this->rows[$name] = array( 'option_value' => $value, 'autoload' => $autoload ); }
	public function failNextWrite( string $name ): void { $this->writeFailures[$name] = ( $this->writeFailures[$name] ?? 0 ) + 1; }
	public function mutateOnRead( string $name, int $after, callable $callback ): void { $this->readHooks[$name] = array( 'after' => $after, 'callback' => $callback ); }
	public function mutateOnNextWrite( string $name, callable $callback ): void { $this->writeHooks[$name] = $callback; }
	public function mutateOnTimeRead( int $after, callable $callback ): void { $this->timeHook = array( 'after' => $after, 'callback' => $callback ); }
	public function forceOptionValue( string $name, string $value ): void { if ( isset( $this->rows[$name] ) ) $this->rows[$name]['option_value'] = $value; }
	/** @return list<string> */
	public function preparedSql(): array { return array_values( array_column( $this->prepared, 'sql' ) ); }
	/** @return list<string> */
	public function readOptionNames(): array { return $this->readOptionNames; }
	/** @return array<string, array{option_value:string,autoload:string}> */
	public function rows(): array { ksort( $this->rows, SORT_STRING ); return $this->rows; }
	public function prepare( string $sql, mixed ...$args ): string { $token = 'prepared-' . ++$this->sequence; $this->prepared[$token] = array( 'sql' => $sql, 'args' => $args ); return $token; }
	public function get_var( string $query ): string|int|null { if ( 'SELECT UNIX_TIMESTAMP()' === $query ) { if ( null !== $this->timeHook ) { if ( 0 === $this->timeHook['after'] ) { $hook = $this->timeHook['callback']; $this->timeHook = null; $hook( $this ); } else --$this->timeHook['after']; } return $this->time; } $prepared = $this->prepared[$query] ?? null; if ( null === $prepared || ! str_starts_with( $prepared['sql'], 'SELECT option_value' ) ) return null; $name = $prepared['args'][0]; $this->readOptionNames[] = $name; if ( isset( $this->readHooks[$name] ) ) { if ( 0 === $this->readHooks[$name]['after'] ) { $hook = $this->readHooks[$name]['callback']; unset( $this->readHooks[$name] ); $hook( $this ); } else --$this->readHooks[$name]['after']; } return $this->rows[$name]['option_value'] ?? null; }
	public function query( string $query ): int { $prepared = $this->prepared[$query] ?? null; if ( null === $prepared ) return 0; if ( str_starts_with( $prepared['sql'], 'INSERT INTO' ) ) { [ $name, $value ] = $prepared['args']; if ( isset( $this->writeHooks[$name] ) ) { $hook = $this->writeHooks[$name]; unset( $this->writeHooks[$name] ); $hook( $this ); } if ( $this->consumeWriteFailure( $name ) || isset( $this->rows[$name] ) ) return 0; $this->rows[$name] = array( 'option_value' => $value, 'autoload' => 'no' ); return 1; } if ( str_starts_with( $prepared['sql'], 'UPDATE' ) ) { [ $next, $name, $expected, $leaseDeadline ] = $prepared['args']; if ( isset( $this->writeHooks[$name] ) ) { $hook = $this->writeHooks[$name]; unset( $this->writeHooks[$name] ); $hook( $this ); } $expired = str_contains( $prepared['sql'], 'UNIX_TIMESTAMP() > %d' ); $leaseAllows = $expired ? $this->time > $leaseDeadline : $this->time <= $leaseDeadline; if ( $this->consumeWriteFailure( $name ) || ! $leaseAllows || ! isset( $this->rows[$name] ) || ! hash_equals( $expected, $this->rows[$name]['option_value'] ) || hash_equals( $next, $this->rows[$name]['option_value'] ) ) return 0; $this->rows[$name]['option_value'] = $next; return 1; } return 0; }
	private function consumeWriteFailure( string $name ): bool { if ( ! isset( $this->writeFailures[$name] ) ) return false; if ( 1 === $this->writeFailures[$name] ) unset( $this->writeFailures[$name] ); else --$this->writeFailures[$name]; return true; }
}
