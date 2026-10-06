<?php

declare(strict_types=1);

/**
 * Small, repository-owned WordPress hook fixture for isolated runtime probes.
 *
 * It deliberately covers only the hook behaviour asserted by the runtime
 * tests: priority ordering, callbacks added during a hook run, current
 * priority, and action/filter bookkeeping.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must occupy the WordPress global class identity used by production calls.
final class WP_Hook {

	/** @var array<int,list<array{function:callable,accepted_args:int}>> */
	public array $callbacks = array();
	private ?int $priority  = null;

	public function add_filter( string $hook, callable $callback, int $priority, int $accepted_args ): void {
		unset( $hook );
		$this->callbacks[ $priority ][] = array(
			'function'      => $callback,
			'accepted_args' => $accepted_args,
		);
	}

	public function current_priority(): ?int {
		return $this->priority;
	}

	/** @param list<mixed> $arguments */
	public function do_action( array $arguments ): void {
		$this->run( $arguments, false );
	}

	/** @param list<mixed> $arguments */
	public function apply_filters( mixed $value, array $arguments ): mixed {
		return $this->run( array_merge( array( $value ), $arguments ), true );
	}

	/** @param list<mixed> $arguments */
	private function run( array $arguments, bool $filter ): mixed {
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

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The self-contained fixture combines foreign functions/classes with the test harness that exercises them. WordPress calls this global stub by its exact foreign function name.
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
	$GLOBALS['wp_filter'][ $hook ] ??= new WP_Hook();
	$GLOBALS['wp_filter'][ $hook ]->add_filter( $hook, $callback, $priority, $accepted_args );
	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function do_action( string $hook, mixed ...$arguments ): void {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
	$GLOBALS['wp_actions'][ $hook ] = ( $GLOBALS['wp_actions'][ $hook ] ?? 0 ) + 1;
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
	$GLOBALS['wp_current_filter'][] = $hook;
	try {
		if ( isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
			$GLOBALS['wp_filter'][ $hook ]->do_action( $arguments );
		}
	} finally {
		array_pop( $GLOBALS['wp_current_filter'] );
	}
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
	$GLOBALS['wp_current_filter'][] = $hook;
	try {
		return isset( $GLOBALS['wp_filter'][ $hook ] )
			? $GLOBALS['wp_filter'][ $hook ]->apply_filters( $value, $arguments )
			: $value;
	} finally {
		array_pop( $GLOBALS['wp_current_filter'] );
	}
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function doing_action( ?string $hook = null ): bool {
	return null === $hook
		? array() !== ( $GLOBALS['wp_current_filter'] ?? array() )
		: in_array( $hook, $GLOBALS['wp_current_filter'] ?? array(), true );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
function did_action( string $hook ): int {
	return $GLOBALS['wp_actions'][ $hook ] ?? 0;
}
