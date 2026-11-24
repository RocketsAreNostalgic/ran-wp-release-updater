<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

use InvalidArgumentException;

/**
 * A repository-relative path was rejected.
 *
 * The message is fixed and never contains the path. reason() is one of the
 * closed REASONS identifiers chosen by this package; it carries no input text,
 * so it is safe to log, display or map without escaping.
 */
final class InvalidRepositoryPathException extends InvalidArgumentException {

	public const MESSAGE = 'The repository-relative path is invalid.';

	public const REASONS = array(
		'path_too_long',
		'path_control_character',
		'path_empty',
		'path_absolute',
		'path_backslash',
		'path_drive_letter',
		'path_empty_component',
		'path_dot_segment',
		'component_too_long',
		'path_encoded_traversal',
		'path_encoded_drive_letter',
		'path_encoded_control_character',
		'path_decode_limit',
	);

	/** @param string $reason One of REASONS. */
	public function __construct( private readonly string $reason ) {
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			throw new \InvalidArgumentException( 'The repository-relative path failure reason is not recognised.' );
		}
		parent::__construct( self::MESSAGE );
	}

	/** @return string One of REASONS. */
	public function reason(): string {
		return $this->reason;
	}
}
