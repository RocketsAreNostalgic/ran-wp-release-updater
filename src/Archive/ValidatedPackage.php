<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

/**
 * A closed, non-extracting result of package archive inspection.
 *
 * The snapshot is diagnostic projection data, not an authority to install;
 * only PackageIdentityValidator issues a ready result in normal package flow.
 */
final readonly class ValidatedPackage {

	/** @param array<string, scalar> $snapshot */
	private function __construct( private string $code, private array $snapshot ) {}

	/**
	 * @internal
	 * @param array{archive_root:string,header_file:string,manifest_entry_count:int,manifest_expanded_bytes:int,manifest_hash:string,metadata_name:string,package_type:string,descriptor_fingerprint:string,sha256:string,size:int,update_uri:string} $snapshot
	 */
	public static function ready( array $snapshot ): self {
		return new self( 'archive_identity_verified', $snapshot ); }
	public static function blocked( string $code ): self {
		return new self( $code, array() ); }
	public function isValid(): bool {
		return 'archive_identity_verified' === $this->code; }
	public function code(): string {
		return $this->code; }
	/** @return array<string, scalar> */
	public function toArray(): array {
		return $this->snapshot; }
}
