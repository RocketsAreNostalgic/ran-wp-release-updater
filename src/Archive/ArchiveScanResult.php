<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

/** Immutable result of one bounded ZIP inventory scan. */
final readonly class ArchiveScanResult {

	/** @param list<array{name:string,path:string,directory:bool,size:int,compressed_size:int}> $entries */
	private function __construct(
		private ?string $failure_code,
		private ?string $root,
		private array $entries,
		private int $expanded_bytes
	) {}

	public static function blocked( string $failure_code ): self {
		return new self( $failure_code, null, array(), 0 );
	}

	/** @param list<array{name:string,path:string,directory:bool,size:int,compressed_size:int}> $entries */
	public static function ready( string $root, array $entries, int $expanded_bytes ): self {
		return new self( null, $root, $entries, $expanded_bytes );
	}

	public function is_valid(): bool {
		return null === $this->failure_code;
	}

	public function failure_code(): ?string {
		return $this->failure_code;
	}

	public function root(): ?string {
		return $this->root;
	}

	/** @return list<array{name:string,path:string,directory:bool,size:int,compressed_size:int}> */
	public function entries(): array {
		return $this->entries;
	}

	public function expanded_bytes(): int {
		return $this->expanded_bytes;
	}
}
