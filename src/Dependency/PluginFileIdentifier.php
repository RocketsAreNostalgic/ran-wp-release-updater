<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

/** Check the canonical relative spelling of a WordPress plugin file. */
final class PluginFileIdentifier {

	/** Path depth and filename case are preserved for callers to compare exactly. */
	public static function is_valid( string $value ): bool {
		$segment = '[A-Za-z0-9][A-Za-z0-9._-]*';
		return 1 === preg_match( '/\A(?:' . $segment . '\/)*' . $segment . '\.[Pp][Hh][Pp]\z/', $value );
	}
}
