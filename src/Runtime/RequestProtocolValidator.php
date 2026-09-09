<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

/**
 * Internal protocol/wire validator for RequestBroker handoff boundaries.
 *
 * @internal RequestBroker remains the sole owner of request-local lifecycle state.
 */
final class RequestProtocolValidator {

	private const MAX_DIAGNOSTICS            = 16;
	private const TERMINAL_CODES             = array(
		'declaration_invalid',
		'provider_code_invalid',
		'repository_locator_invalid',
		'repository_identity_invalid',
		'release_channel_invalid',
		'update_policy_invalid',
		'credential_resolver_invalid',
		'maximum_artifact_bytes_invalid',
		'installed_file_invalid',
		'installed_file_missing',
		'installed_file_unreadable',
		'installed_file_not_regular',
		'installed_file_symlink',
		'installed_file_outside_root',
		'installed_file_root_ambiguous',
		'installed_file_changed',
		'plugin_root_level_unsupported',
		'theme_header_file_invalid',
		'theme_nested_identity_unsupported',
		'installed_header_missing',
		'installed_header_ambiguous',
		'installed_header_invalid',
		'installed_update_uri_mismatch',
		'installed_requirement_incompatible',
		'unsupported_provider',
		'target_declaration_conflict',
		'target_composition_failed',
		'activation_boundary_missed',
		'runtime_environment_invalid',
		'runtime_selection_inactive',
		'runtime_load_failed',
		'runtime_handoff_invalid',
		'protocol_conflict_inactive',
	);
	private const CANDIDATE_VALIDATION_CODES = array(
		'archive_entry_limit',
		'archive_entry_unreadable',
		'archive_file_identity_mismatch',
		'archive_header_duplicate',
		'archive_header_missing',
		'archive_header_unreadable',
		'archive_identity_verified',
		'archive_metadata_identity_mismatch',
		'archive_path_duplicate',
		'archive_path_unsafe',
		'archive_php_requirement_incompatible',
		'archive_root_mismatch',
		'archive_size_limit',
		'archive_target_policy_invalid',
		'archive_unreadable',
		'archive_update_uri_mismatch',
		'archive_version_mismatch',
		'archive_wordpress_requirement_incompatible',
		'archive_zip_extension_unavailable',
		'candidate_descriptor_mismatch',
		'candidate_inspection_failed',
		'candidate_invalid',
		'candidate_list_invalid',
		'candidate_not_newer',
		'candidate_validation_failed',
		'release_list_failed',
	);
	private const FAILURE_CODES              = array(
		'acquisition_failed',
		'acquisition_identity_invalid',
		'archive_changed_before_extraction',
		'binding_fence_lost',
		'outcome_uncertain',
		'remote_release_changed',
		'runtime_liveness_lost',
		'runtime_package_identity_invalid',
		'staged_package_identity_invalid',
		'unverified_install_result',
		'unverified_pre_download',
		'unverified_pre_download_result',
		'unverified_pre_install',
	);
	private const RELATIONSHIPS              = array( 'invalid', 'newer', 'older', 'same' );

	public static function ownedBy( object $value, string $root ): bool {
		try {
			$file = ( new \ReflectionClass( $value ) )->getFileName();
		} catch ( \ReflectionException ) {
			return false;
		}
		return is_string( $file ) && str_starts_with( $file, $root . DIRECTORY_SEPARATOR );
	}

	/** @param list<string> $expected */
	public static function exactPublicMethods( object $value, array $expected ): bool {
		$methods = array();
		foreach ( ( new \ReflectionClass( $value ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( ! $method->isConstructor() ) {
				$methods[] = $method->getName();
			}
		}
		sort( $methods, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $expected === $methods;
	}

	public static function validStatus( mixed $status ): bool {
		if (
			! is_array( $status )
			|| ! self::exactKeys( $status, array( 'state', 'declaration_accepted', 'hooks_registered', 'code', 'native' ) )
			|| ! is_string( $status['code'] )
			|| ! is_bool( $status['declaration_accepted'] )
			|| ! is_bool( $status['hooks_registered'] )
			|| ! in_array( $status['state'], array( 'new', 'queued', 'composing', 'deferred', 'active', 'inactive' ), true )
		) {
			return false;
		}
		if ( ! self::validNativeStatus( $status['native'] ) ) {
			return false;
		}

		return match ( $status['state'] ) {
			'new' => false === $status['declaration_accepted']
				&& false === $status['hooks_registered']
				&& 'target_unregistered' === $status['code']
				&& null === $status['native'],
			'queued' => true === $status['declaration_accepted']
				&& false === $status['hooks_registered']
				&& 'target_queued' === $status['code']
				&& null === $status['native'],
			'composing' => true === $status['declaration_accepted']
				&& false === $status['hooks_registered']
				&& 'target_composing' === $status['code']
				&& null === $status['native'],
			'deferred' => true === $status['declaration_accepted']
				&& false === $status['hooks_registered']
				&& 'declaration_deferred_operation_started' === $status['code']
				&& null === $status['native'],
			'active' => true === $status['declaration_accepted']
				&& true === $status['hooks_registered']
				&& 'target_active' === $status['code']
				&& is_array( $status['native'] ),
			'inactive' => in_array( $status['code'], self::TERMINAL_CODES, true ) && ( ! $status['hooks_registered'] || is_array( $status['native'] ) ),
		};
	}

	public static function validNativeStatus( mixed $native ): bool {
		$keys = array(
			'candidate_header_version',
			'candidate_tag',
			'candidate_validation_code',
			'candidate_version',
			'failure_code',
			'installed_version',
			'last_check',
			'offered_release_identity',
			'offered_version',
			'relationship',
		);
		if ( null === $native ) {
			return true;
		}
		if ( ! is_array( $native ) || ! self::exactKeys( $native, $keys ) ) {
			return false;
		}
		foreach ( array( 'candidate_header_version', 'candidate_tag', 'candidate_version', 'installed_version', 'offered_version' ) as $key ) {
			if ( null !== $native[ $key ] && ( ! is_string( $native[ $key ] ) || '' === $native[ $key ] ) ) {
				return false;
			}
		}
		if ( null !== $native['offered_release_identity'] && ! self::opaque( $native['offered_release_identity'], 191 ) ) {
			return false;
		}
		return ( null === $native['candidate_validation_code'] || in_array( $native['candidate_validation_code'], self::CANDIDATE_VALIDATION_CODES, true ) )
			&& ( null === $native['failure_code'] || in_array( $native['failure_code'], self::FAILURE_CODES, true ) )
			&& ( null === $native['last_check'] || ( is_int( $native['last_check'] ) && 0 < $native['last_check'] ) )
			&& ( null === $native['relationship'] || in_array( $native['relationship'], self::RELATIONSHIPS, true ) )
			&& ( null === $native['offered_release_identity'] ) === ( null === $native['offered_version'] );
	}

	public static function validDiagnostics( mixed $diagnostics, string $state ): bool {
		if (
			! is_array( $diagnostics )
			|| ! self::exactKeys( $diagnostics, array( 'state', 'diagnostics' ) )
			|| $state !== $diagnostics['state']
			|| ! is_array( $diagnostics['diagnostics'] )
			|| self::MAX_DIAGNOSTICS < count( $diagnostics['diagnostics'] )
		) {
			return false;
		}
		foreach ( $diagnostics['diagnostics'] as $diagnostic ) {
			if (
				! is_array( $diagnostic )
				|| ! self::exactKeys( $diagnostic, array( 'code' ) )
				|| ! is_string( $diagnostic['code'] )
				|| ! self::validDiagnosticCode( $diagnostic['code'] )
			) {
				return false;
			}
		}
		return true;
	}

	public static function validDiagnosticCode( string $code ): bool {
		$runtimeCodes = array(
			'activation_in_progress',
			'late_candidate_rejected',
			'runtime_active',
			'declaration_deferred_operation_started',
			'target_composing',
			'target_queued',
			'target_unregistered',
			'update_completed',
		);
		return in_array(
			$code,
			array_merge( self::TERMINAL_CODES, self::CANDIDATE_VALIDATION_CODES, self::FAILURE_CODES, $runtimeCodes ),
			true
		);
	}

	/** @param array<string,mixed> $value */
	public static function declarationCode( array $value ): ?string {
		$keys = array(
			'target_type',
			'installed_file',
			'provider_code',
			'repository_locator',
			'repository_identity',
			'channel',
			'update_policy',
			'credential_resolver',
			'maximum_artifact_bytes',
		);
		if (
			! self::exactKeys( $value, $keys )
			|| ! in_array( $value['target_type'], array( 'plugin', 'theme' ), true )
		) {
			return 'declaration_invalid';
		}
		$file       = $value['installed_file'];
		$normalized = is_string( $file ) ? str_replace( '\\', '/', $file ) : '';
		$isAbsolute = str_starts_with( $normalized, '/' ) || 1 === preg_match( '/\A[A-Za-z]:\//D', $normalized );
		$isUnc      = str_starts_with( $normalized, '//' );
		$uncParts   = $isUnc ? explode( '/', substr( $normalized, 2 ) ) : array();
		if (
			! is_string( $file )
			|| '' === $file
			|| 4096 < strlen( $file )
			|| ! $isAbsolute
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $file )
			|| ( ! $isUnc && str_contains( $normalized, '//' ) )
			|| ( $isUnc && ( 2 > count( $uncParts ) || in_array( '', $uncParts, true ) ) )
			|| 1 === preg_match( '#/(?:\.?\.?)(?:/|$)#', $isUnc ? substr( $normalized, 1 ) : $normalized )
		) {
			return 'installed_file_invalid';
		}
		if (
			! is_string( $value['provider_code'] )
			|| 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,31}\z/D', $value['provider_code'] )
		) {
			return 'provider_code_invalid';
		}
		if ( ! self::opaque( $value['repository_locator'], 255 ) ) {
			return 'repository_locator_invalid';
		}
		if ( ! self::opaque( $value['repository_identity'], 191 ) ) {
			return 'repository_identity_invalid';
		}
		if ( ! in_array( $value['channel'], array( 'stable', 'prerelease' ), true ) ) {
			return 'release_channel_invalid';
		}
		if ( ! in_array( $value['update_policy'], array( 'disabled', 'forced-off', 'manual', 'automatic' ), true ) ) {
			return 'update_policy_invalid';
		}
		if ( ! is_int( $value['maximum_artifact_bytes'] ) || 0 >= $value['maximum_artifact_bytes'] ) {
			return 'maximum_artifact_bytes_invalid';
		}
		return null === $value['credential_resolver'] || is_callable( $value['credential_resolver'] ) ? null : 'credential_resolver_invalid';
	}

	/** @param array<string,mixed> $value */
	public static function releaseDeclarationCode( array $value ): ?string {
		$keys = array( 'provider_code', 'target_type', 'repository_locator', 'repository_identity', 'channel', 'credential_resolver', 'maximum_artifact_bytes' );
		if ( ! self::exactKeys( $value, $keys ) || ! in_array( $value['target_type'], array( 'plugin', 'theme' ), true ) ) {
			return 'invalid_configuration';
		}
		if ( ! is_string( $value['provider_code'] ) || 1 !== preg_match( '/\\A[a-z][a-z0-9_-]{0,31}\\z/D', $value['provider_code'] ) ) {
			return 'invalid_configuration';
		}
		if ( ! self::opaque( $value['repository_locator'], 255 ) || ! self::opaque( $value['repository_identity'], 191 ) ) {
			return 'invalid_configuration';
		}
		if ( ! in_array( $value['channel'], array( 'stable', 'prerelease' ), true ) || ! is_int( $value['maximum_artifact_bytes'] ) || 0 >= $value['maximum_artifact_bytes'] ) {
			return 'invalid_configuration';
		}
		return null === $value['credential_resolver'] || is_callable( $value['credential_resolver'] ) ? null : 'invalid_configuration';
	}

	public static function opaque( mixed $value, int $limit ): bool {
		return is_string( $value )
			&& '' !== $value
			&& strlen( $value ) <= $limit
			&& 1 === preg_match( '//u', $value )
			&& 1 === preg_match( '/\A[^\p{C}\p{Z}\s]+\z/u', $value );
	}

	/**
	 * @param array<string,mixed> $value
	 * @param list<string> $keys
	 */
	public static function exactKeys( array $value, array $keys ): bool {
		return array_keys( $value ) === $keys;
	}

	public static function isTerminalCode( string $code ): bool {
		return in_array( $code, self::TERMINAL_CODES, true );
	}
}
