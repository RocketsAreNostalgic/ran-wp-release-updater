<?php

declare(strict_types=1);

/**
 * Small, repository-owned WordPress hook fixture for isolated runtime probes.
 *
 * It deliberately covers only the hook behaviour asserted by the runtime
 * tests: priority ordering, callbacks added during a hook run, current
 * priority, and action/filter bookkeeping.
 */
final class WP_Hook
{
	/** @var array<int,list<array{function:callable,accepted_args:int}>> */
	public array $callbacks = array();
	private ?int $priority = null;

	public function add_filter( string $hook, callable $callback, int $priority, int $acceptedArgs ): void
	{
		unset( $hook );
		$this->callbacks[ $priority ][] = array(
			'function' => $callback,
			'accepted_args' => $acceptedArgs,
		);
	}

	public function current_priority(): ?int
	{
		return $this->priority;
	}

	/** @param list<mixed> $arguments */
	public function do_action( array $arguments ): void
	{
		$this->run( $arguments, false );
	}

	/** @param list<mixed> $arguments */
	public function apply_filters( mixed $value, array $arguments ): mixed
	{
		return $this->run( array_merge( array( $value ), $arguments ), true );
	}

	/** @param list<mixed> $arguments */
	private function run( array $arguments, bool $filter ): mixed
	{
		$last = null;
		try {
			while ( true ) {
				$priorities = array_keys( $this->callbacks );
				sort( $priorities, SORT_NUMERIC );
				$next = null;
				foreach ( $priorities as $priority ) {
					if ( null === $last || $priority > $last ) {
						$next = $priority;
						break;
					}
				}
				if ( null === $next ) {
					break;
				}
				$this->priority = $next;
				foreach ( $this->callbacks[ $next ] as $callback ) {
					$result = $callback['function']( ...array_slice( $arguments, 0, $callback['accepted_args'] ) );
					if ( $filter ) {
						$arguments[0] = $result;
					}
				}
				$last = $next;
			}
		} finally {
			$this->priority = null;
		}

		return $arguments[0] ?? null;
	}
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool
{
	$GLOBALS['wp_filter'][ $hook ] ??= new WP_Hook();
	$GLOBALS['wp_filter'][ $hook ]->add_filter( $hook, $callback, $priority, $acceptedArgs );
	return true;
}

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool
{
	return add_filter( $hook, $callback, $priority, $acceptedArgs );
}

function do_action( string $hook, mixed ...$arguments ): void
{
	$GLOBALS['wp_actions'][ $hook ] = ( $GLOBALS['wp_actions'][ $hook ] ?? 0 ) + 1;
	$GLOBALS['wp_current_filter'][] = $hook;
	try {
		if ( isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
			$GLOBALS['wp_filter'][ $hook ]->do_action( $arguments );
		}
	} finally {
		array_pop( $GLOBALS['wp_current_filter'] );
	}
}

function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed
{
	$GLOBALS['wp_current_filter'][] = $hook;
	try {
		return isset( $GLOBALS['wp_filter'][ $hook ] )
			? $GLOBALS['wp_filter'][ $hook ]->apply_filters( $value, $arguments )
			: $value;
	} finally {
		array_pop( $GLOBALS['wp_current_filter'] );
	}
}

function doing_action( ?string $hook = null ): bool
{
	return null === $hook
		? array() !== ( $GLOBALS['wp_current_filter'] ?? array() )
		: in_array( $hook, $GLOBALS['wp_current_filter'] ?? array(), true );
}

function did_action( string $hook ): int
{
	return $GLOBALS['wp_actions'][ $hook ] ?? 0;
}
