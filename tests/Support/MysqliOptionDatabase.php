<?php

declare(strict_types=1);

namespace Tests\Support;

/** Minimal wpdb-compatible adapter for the isolated real-MySQL CAS proof. */
final class MysqliOptionDatabase {

	public string $options;

	public function __construct( private \mysqli $mysqli, string $table ) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $table ) ) throw new \InvalidArgumentException( 'Invalid options table.' );
		$this->options = $table;
	}

	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$position = strcspn( $sql, '%', 0 );
			if ( $position === strlen( $sql ) || ! isset( $sql[ $position + 1 ] ) || ! in_array( $sql[ $position + 1 ], array( 'd', 's' ), true ) ) throw new \InvalidArgumentException( 'Unsupported query placeholder.' );
			$replacement = 'd' === $sql[ $position + 1 ] ? (string) (int) $arg : "'" . $this->mysqli->real_escape_string( (string) $arg ) . "'";
			$sql = substr( $sql, 0, $position ) . $replacement . substr( $sql, $position + 2 );
		}
		return $sql;
	}

	public function get_var( string $query ): string|int|null {
		$result = $this->mysqli->query( $query );
		if ( false === $result ) throw new \RuntimeException( $this->mysqli->error );
		$row = $result->fetch_row();
		$result->free();
		return null === $row ? null : $row[0];
	}

	public function query( string $query ): int {
		try {
			$result = $this->mysqli->query( $query );
			if ( false === $result ) throw new \RuntimeException( $this->mysqli->error );
			return $this->mysqli->affected_rows;
		} catch ( \mysqli_sql_exception $exception ) {
			if ( 1062 === $exception->getCode() ) return 0;
			throw $exception;
		}
	}
}
