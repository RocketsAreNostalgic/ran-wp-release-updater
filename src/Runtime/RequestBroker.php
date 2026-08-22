<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

use RuntimeException;
use Throwable;

/**
 * Request-local intake for physical runtime copies.
 *
 * It deliberately retains data, not callbacks: the sole hook registration
 * callable exists only for the one explicit activation attempt.
 */
final class RequestBroker
{
	private const COPY_KEYS = array( 'package_revision', 'package_version', 'php_floor', 'runtime_file', 'runtime_protocol', 'wordpress_floor' );
	private const SHA256 = '/\A[a-f0-9]{64}\z/D';

	/** @var list<array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,source_root:string,wordpress_floor:string}> */
	private array $candidates = array();
	/** @var array<string, true> */
	private array $candidateRoots = array();
	/** @var list<array{code:string}> */
	private array $diagnostics = array();
	private bool $activationAttempted = false;

	public function protocolVersion(): int
	{
		return 1;
	}

	/** Register a physical runtime-copy.json without loading its runtime. */
	public function registerCandidate( string $copyFile ): bool
	{
		if ( $this->activationAttempted ) {
			$this->diagnostics[] = array( 'code' => 'late_candidate_rejected' );
			return false;
		}
		if ( count( $this->candidates ) >= 8 ) {
			$this->diagnostics[] = array( 'code' => 'candidate_limit_rejected' );
			return false;
		}

		try {
			$candidate = $this->candidate( $copyFile );
		} catch ( Throwable ) {
			$this->diagnostics[] = array( 'code' => 'candidate_invalid' );
			return false;
		}
		if ( isset( $this->candidateRoots[ $candidate['source_root'] ] ) ) {
			$this->diagnostics[] = array( 'code' => 'duplicate_candidate_rejected' );
			return false;
		}

		$this->candidateRoots[ $candidate['source_root'] ] = true;
		$this->candidates[] = $candidate;
		return true;
	}

	/**
	 * Select one physical copy and load only its runtime entrypoint. Provider
	 * activation is deliberately deferred until a real adapter owns that seam.
	 *
	 * @param array<string, mixed> $environment
	 * @return array{loaded:bool,diagnostics:list<array{code:string}>}
	 */
	public function activate( array $environment ): array
	{
		if ( $this->activationAttempted ) {
			$this->diagnostics[] = array( 'code' => 'activation_already_attempted' );
			return $this->result( false );
		}
		$this->activationAttempted = true;

		if ( $this->legacyConflict() ) {
			$this->diagnostics[] = array( 'code' => 'legacy_conflict_inactive' );
			return $this->result( false );
		}

		try {
			$selected = $this->select( $environment );
		} catch ( Throwable ) {
			$this->diagnostics[] = array( 'code' => 'runtime_selection_inactive' );
			return $this->result( false );
		}

		try {
			require_once $selected['runtime_file'];
		} catch ( Throwable ) {
			$this->diagnostics[] = array( 'code' => 'runtime_load_failed' );
			return $this->result( false );
		}

		return $this->result( true );
	}

	/** @return array{activation_attempted:bool,candidate_count:int,diagnostics:list<array{code:string}>} */
	public function diagnostics(): array
	{
		return array(
			'activation_attempted' => $this->activationAttempted,
			'candidate_count' => count( $this->candidates ),
			'diagnostics' => $this->diagnostics,
		);
	}

	private function legacyConflict(): bool
	{
		return isset( $GLOBALS['ran_wp_github_release_updater_v1_broker'] )
			|| function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' );
	}

	/** @return array{loaded:bool,diagnostics:list<array{code:string}>} */
	private function result( bool $loaded ): array
	{
		return array( 'loaded' => $loaded, 'diagnostics' => $this->diagnostics );
	}

	/** @return array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,source_root:string,wordpress_floor:string} */
	private function candidate( string $copyFile ): array
	{
		$file = 'runtime-copy.json' === basename( $copyFile ) ? realpath( $copyFile ) : false;
		$root = false === $file ? false : realpath( dirname( $file ) );
		if ( false === $file || false === $root || $file !== $root . DIRECTORY_SEPARATOR . 'runtime-copy.json' ) {
			throw new RuntimeException( 'Invalid runtime copy.' );
		}
		try {
			$facts = json_decode( $this->read( $file ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new RuntimeException( 'Invalid runtime copy.', 0, $exception );
		}
		if ( ! is_array( $facts ) || array_is_list( $facts ) || ! $this->exactKeys( $facts, self::COPY_KEYS ) || ! is_string( $facts['package_revision'] ) || 1 !== preg_match( self::SHA256, $facts['package_revision'] ) || ! is_string( $facts['package_version'] ) || ! is_string( $facts['php_floor'] ) || 'runtime.php' !== $facts['runtime_file'] || 1 !== $facts['runtime_protocol'] || ! is_string( $facts['wordpress_floor'] ) ) {
			throw new RuntimeException( 'Invalid runtime copy.' );
		}
		$this->version( $facts['package_version'] );
		$this->version( $facts['php_floor'] );
		$this->version( $facts['wordpress_floor'] );
		$runtime = realpath( $root . DIRECTORY_SEPARATOR . 'runtime.php' );
		if ( false === $runtime || $runtime !== $root . DIRECTORY_SEPARATOR . 'runtime.php' ) {
			throw new RuntimeException( 'Invalid runtime entrypoint.' );
		}
		return array( 'package_revision' => $facts['package_revision'], 'package_version' => $facts['package_version'], 'php_floor' => $facts['php_floor'], 'runtime_file' => $runtime, 'source_root' => $root, 'wordpress_floor' => $facts['wordpress_floor'] );
	}

	/** @param array<string, mixed> $environment
	 * @return array{package_revision:string,package_version:string,php_floor:string,runtime_file:string,source_root:string,wordpress_floor:string}
	 */
	private function select( array $environment ): array
	{
		if ( array() === $this->candidates || ! $this->exactKeys( $environment, array( 'php_version', 'runtime_protocol', 'wordpress_version' ) ) || 1 !== $environment['runtime_protocol'] || ! is_string( $environment['php_version'] ) || ! is_string( $environment['wordpress_version'] ) ) {
			throw new RuntimeException( 'Invalid runtime environment.' );
		}
		$this->version( $environment['php_version'] );
		$this->version( $environment['wordpress_version'] );
		$compatible = array_values( array_filter( $this->candidates, fn ( array $candidate ): bool => $this->compare( $candidate['php_floor'], $environment['php_version'] ) <= 0 && $this->compare( $candidate['wordpress_floor'], $environment['wordpress_version'] ) <= 0 ) );
		if ( array() === $compatible ) {
			throw new RuntimeException( 'No compatible runtime.' );
		}
		usort( $compatible, fn ( array $left, array $right ): int => $this->compare( $right['package_version'], $left['package_version'] ) ?: strcmp( $left['source_root'], $right['source_root'] ) );
		$highest = array_filter( $compatible, fn ( array $candidate ): bool => 0 === $this->compare( $candidate['package_version'], $compatible[0]['package_version'] ) );
		if ( 1 !== count( array_unique( array_column( $highest, 'package_revision' ) ) ) ) {
			throw new RuntimeException( 'Equal runtime versions disagree.' );
		}
		return $compatible[0];
	}

	/** @return array{core:list<string>,prerelease:list<string>} */
	private function version( string $value ): array
	{
		if ( ! preg_match( '/\Av?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/D', $value, $match ) ) {
			throw new RuntimeException( 'Invalid runtime version.' );
		}
		$prerelease = isset( $match[4] ) ? explode( '.', $match[4] ) : array();
		foreach ( $prerelease as $identifier ) {
			if ( ctype_digit( $identifier ) && ! preg_match( '/\A(?:0|[1-9]\d*)\z/D', $identifier ) ) throw new RuntimeException( 'Invalid runtime version.' );
		}
		return array( 'core' => array( $match[1], $match[2], $match[3] ), 'prerelease' => $prerelease );
	}

	private function compare( string $left, string $right ): int
	{
		$left = $this->version( $left ); $right = $this->version( $right );
		foreach ( array( 0, 1, 2 ) as $index ) { $comparison = strlen( $left['core'][ $index ] ) <=> strlen( $right['core'][ $index ] ) ?: strcmp( $left['core'][ $index ], $right['core'][ $index ] ); if ( 0 !== $comparison ) return $comparison; }
		if ( array() === $left['prerelease'] || array() === $right['prerelease'] ) return array() === $left['prerelease'] ? ( array() === $right['prerelease'] ? 0 : 1 ) : -1;
		for ( $index = 0; $index < max( count( $left['prerelease'] ), count( $right['prerelease'] ) ); ++$index ) { if ( ! isset( $left['prerelease'][ $index ] ) ) return -1; if ( ! isset( $right['prerelease'][ $index ] ) ) return 1; $a = $left['prerelease'][ $index ]; $b = $right['prerelease'][ $index ]; if ( $a === $b ) continue; if ( ctype_digit( $a ) && ctype_digit( $b ) ) return strlen( $a ) <=> strlen( $b ) ?: strcmp( $a, $b ); if ( ctype_digit( $a ) !== ctype_digit( $b ) ) return ctype_digit( $a ) ? -1 : 1; return strcmp( $a, $b ); }
		return 0;
	}

	private function exactKeys( array $value, array $keys ): bool { $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $keys, SORT_STRING ); return $actual === $keys; }
	private function read( string $file ): string { $content = file_get_contents( $file ); if ( false === $content ) throw new RuntimeException( 'Unreadable runtime copy.' ); return $content; }
}
