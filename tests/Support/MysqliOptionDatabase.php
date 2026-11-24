<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Support;

/** Minimal wpdb-compatible adapter for the isolated real-MySQL lock proof. */
final class MysqliOptionDatabase {

	public string $options;
	public string $last_error = '';
	/** @var list<array{level:string,code:int,message:string}> */
	private array $warnings = array();

	public function __construct( private \mysqli $mysqli, string $table ) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $table ) ) {
			throw new \InvalidArgumentException( 'Invalid options table.' );
		}
		$this->options = $table;
	}

	/** @return list<array{level:string,code:int,message:string}> */
	public function warnings(): array {
		return $this->warnings;
	}

	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$position = strcspn( $sql, '%', 0 );
			if ( strlen( $sql ) === $position || ! isset( $sql[ $position + 1 ] ) || ! in_array( $sql[ $position + 1 ], array( 'd', 'i', 's' ), true ) ) {
				throw new \InvalidArgumentException( 'Unsupported query placeholder.' );
			}
			$specifier = $sql[ $position + 1 ];
			if ( 'i' === $specifier ) {
				$identifier = (string) $arg;
				if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $identifier ) ) {
					throw new \InvalidArgumentException( 'Unsupported SQL identifier.' );
				}
				$replacement = '`' . $identifier . '`';
			} elseif ( 'd' === $specifier ) {
				$replacement = (string) (int) $arg;
			} else {
				$replacement = "'" . $this->mysqli->real_escape_string( (string) $arg ) . "'";
			}
			$sql = substr( $sql, 0, $position ) . $replacement . substr( $sql, $position + 2 );
		}
		return $sql;
	}

	public function get_row( string $query ): ?object {
		$this->last_error = '';
		$result           = $this->mysqli->query( $query );
		if ( false === $result ) {
			$this->last_error = $this->mysqli->error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
			throw new \RuntimeException( $this->mysqli->error );
		}
		if ( ! $result instanceof \mysqli_result ) {
			throw new \RuntimeException( 'Expected a MySQL result set.' );
		}
		$row = $result->fetch_object();
		$result->free();
		$this->record_warnings();
		if ( false === $row ) {
			throw new \RuntimeException( 'Could not fetch the MySQL row.' );
		}
		return $row;
	}

	public function query( string $query ): int {
		$this->last_error = '';
		$result           = $this->mysqli->query( $query );
		if ( false === $result ) {
			$this->last_error = $this->mysqli->error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
			throw new \RuntimeException( $this->mysqli->error );
		}
		$affected = $this->mysqli->affected_rows;
		$this->record_warnings();
		if ( ! is_int( $affected ) || $affected < 0 ) {
			throw new \RuntimeException( 'MySQL affected-row count is unavailable or exceeds integer range.' );
		}
		return $affected;
	}

	private function record_warnings(): void {
		if ( 0 === $this->mysqli->warning_count ) {
			return;
		}
		$result = $this->mysqli->query( 'SHOW WARNINGS' );
		if ( ! $result instanceof \mysqli_result ) {
			return;
		}
		while ( true ) {
			$row = $result->fetch_assoc();
			if ( ! is_array( $row ) ) {
				break;
			}
			$this->warnings[] = array(
				'level'   => (string) ( $row['Level'] ?? '' ),
				'code'    => (int) ( $row['Code'] ?? 0 ),
				'message' => (string) ( $row['Message'] ?? '' ),
			);
		}
		$result->free();
	}
}
