<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

/** Immutable result of one bounded ZIP inventory scan. */
final readonly class ArchiveScanResult {

	/** @param list<array{name:string,path:string,directory:bool,size:int,compressed_size:int}> $entries */
	private function __construct(
		private ?string $failureCode,
		private ?string $root,
		private array $entries,
		private int $expandedBytes
	) {}

	public static function blocked( string $failureCode ): self {
		return new self( $failureCode, null, array(), 0 );
	}

	/** @param list<array{name:string,path:string,directory:bool,size:int,compressed_size:int}> $entries */
	public static function ready( string $root, array $entries, int $expandedBytes ): self {
		return new self( null, $root, $entries, $expandedBytes );
	}

	public function isValid(): bool {
		return null === $this->failureCode;
	}

	public function failureCode(): ?string {
		return $this->failureCode;
	}

	public function root(): ?string {
		return $this->root;
	}

	/** @return list<array{name:string,path:string,directory:bool,size:int,compressed_size:int}> */
	public function entries(): array {
		return $this->entries;
	}

	public function expandedBytes(): int {
		return $this->expandedBytes;
	}
}
