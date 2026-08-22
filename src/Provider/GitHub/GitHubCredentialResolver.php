<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use RuntimeException;

/** Request-local GitHub credential source. It deliberately retains no resolved value. */
final class GitHubCredentialResolver
{
	/** @var null|callable():mixed */
	private $source;

	/** @param null|callable():mixed $source */
	public function __construct( mixed $source = null )
	{
		if ( null !== $source && ! is_callable( $source ) ) {
			throw new \InvalidArgumentException( 'The GitHub credential source is invalid.' );
		}
		$this->source = $source;
	}

	/** Resolve once, immediately before a top-level request chain. */
	public function resolve(): ?string
	{
		if ( null === $this->source ) {
			return null;
		}
		try {
			$credential = ( $this->source )();
		} catch ( \Throwable $exception ) {
			throw new RuntimeException( 'The GitHub credential is unavailable.', 0, $exception );
		}
		if ( ! is_string( $credential ) || 1 > strlen( $credential ) || 512 < strlen( $credential ) || 1 !== preg_match( '/\A[\x21-\x7e]+\z/D', $credential ) ) {
			throw new RuntimeException( 'The GitHub credential is invalid.' );
		}
		return $credential;
	}
}
