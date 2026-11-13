<?php
declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

/**
 * Decides whether failure text may be shown or recorded, in explicit modes.
 *
 * One policy, implemented once, for Core and both updaters. The whole text is
 * screened first and only then bounded. A text that matches any rule is dropped
 * whole; nothing is ever redacted, rewritten or partly kept. The result is the
 * accepted printable-ASCII text or null, and there is no other outcome.
 *
 * The screen is by shape, deliberately broad, and a safety net rather than a
 * proof: false positives are accepted, false negatives are not. Sinks must still
 * prefer fixed or provider-public text, and consumers must escape the result on
 * output. See the README for the policy, the rule list and the dropped benign
 * shapes. `tests/fixtures/failure-text-corpus.php` is the cross-package contract.
 *
 * Every row of RULES and every `// rule:` line in `hostile()` is one named rule.
 * `tests/failure-text-mutation.php` removes each in turn and requires the corpus
 * to notice, so a rule added here needs a corpus entry that only it rejects.
 */
final class FailureText {

	/** The longest text, in bytes, that is screened. Longer text is dropped, not truncated. */
	public const MAX_INPUT_LENGTH = 4096;

	/** The longest identifier, in bytes, that `identifier()` accepts. */
	public const MAX_IDENTIFIER_LENGTH = 96;

	/**
	 * Shape rules, applied to the whole printable-ASCII text by every mode.
	 *
	 * @var array<string, string>
	 */
	private const RULES = array(
		'angle_bracket'             => '#[<>]#',
		'ampersand'                 => '#&#',
		'bracket_or_brace'          => '#[{}\[\]]#',
		'pipe'                      => '#\|#',
		'semicolon'                 => '#;(?!\s|\z)#',
		'backtick'                  => '#`#',
		'equals'                    => '#=#',
		'at_sign'                   => '#@#',
		'percent_escape'            => '#%[0-9a-f]{2}#i',
		'slash'                     => '#/#',
		'backslash'                 => '#\\\\#',
		'tilde'                     => '#~#',
		'colon_attached'            => '#:(?!\s|\z)#',
		'question_attached'         => '#\?(?!\s|\z)#',
		'url_scheme_word'           => '#(?<![a-z0-9])(?:https?|ftps?|sftp|ssh|wss?|ldaps?|smb|javascript|vbscript|mailto)\s*:#i',
		'wordpress_path_word'       => '#wp-(?:content|admin|includes)|\.ht(?:access|passwd)|public_html#i',
		'session_cookie_name'       => '#wordpress_(?:logged_in|sec|test_cookie)|wp-settings-|phpsessid#i',
		'private_key_material'      => '#private[ _.-]?key|-----begin|ssh-(?:rsa|dss|ed25519)#i',
		'wordpress_app_password'    => '#(?<![A-Za-z0-9])[A-Za-z0-9]{4}(?: +[A-Za-z0-9]{4}){5}(?![A-Za-z0-9])#',
		'credential_header_name'    => '#(?<![a-z0-9-])(?:proxy-authori[sz]ation|www-authenticate|authori[sz]ation|api-key|set-cookie|cookie|x-wp-nonce)\s*:#i',
		'credential_header_generic' => '#(?<![a-z0-9-])x-[a-z0-9-]*(?:key|token|secret|auth|password|signature)[a-z0-9-]*\s*:#i',
		'bearer_basic_token'        => '#(?<![a-z0-9])(?:bearer|basic)\s+(?=(?-i:[A-Za-z0-9+/=._~-]*[0-9A-Z+/=]))[A-Za-z0-9+/=._~-]{4,}#i',
		'prefix_github'             => '#gh[pousr][_-]|github_pat_#i',
		'prefix_gitlab'             => '#gl(?:pat|dt|rt|cbt|ptt|soat)-#i',
		'prefix_slack'              => '#xox[abprs]-|xapp-#i',
		'prefix_npm'                => '#npm_#i',
		'prefix_payment'            => '#(?:sk|pk|rk)_(?:live|test)_|sk-#i',
		'prefix_aws'                => '#AKIA|ASIA|AROA|AIDA|ABIA|ACCA|AGPA|ANPA|ANVA|APKA#',
		'prefix_jwt'                => '#eyJ#',
		'prefix_bitbucket'          => '#AT(?:BB|CTT3|ATT3)#',
		'prefix_google'             => '#AIza|ya29\.#',
		'prefix_other_vendor'       => '#shp(?:at|ca|pa|ss)_|dop_v1_#i',
		'digest_prefix'             => '#(?<![a-z0-9])(?:sha[ _-]?\d|md5|blake2)#i',
	);

	/**
	 * Prose rules, applied only by `strict()`: a credential word beside any other text,
	 * before or after it. Only a lone word on its own (`token`, `api keys`) passes.
	 * Curated English sentences that merely mention a token or password are not
	 * credentials, so `display()` and `identifier()` do not apply these. Credential
	 * words match inside a longer word (`usertoken`) and across an underscore
	 * (`client_secret`), but not before a letter (`tokenizer`). Both spellings of
	 * authorization match.
	 *
	 * @var array<string, string>
	 */
	private const PROSE_RULES = array(
		'credential_password'      => '#(?:passw[o0]rds?|passwds?|pwds?|pass(?:phrase|code)s?)(?![a-z])#i',
		'credential_secret'        => '#secrets?(?![a-z])#i',
		'credential_token'         => '#tokens?(?![a-z])#i',
		'credential_api_key'       => '#api[ _.-]?keys?(?![a-z])#i',
		'credential_authorization' => '#authori[sz]ations?(?![a-z])#i',
		'credential_signature'     => '#signatures?(?![a-z])#i',
		'credential_nonce'         => '#nonces?(?![a-z])#i',
		'credential_cookie'        => '#cookies?(?![a-z])#i',
		'credential_generic'       => '#credentials?(?![a-z])#i',
		'credential_other'         => '#(?:passwort|jwts?|pass|pw|auth)(?![a-z])|access[ _-]?keys?(?![a-z])#i',
	);

	/**
	 * Prose rules that need a value after the word, applied only by `strict()`.
	 *
	 * @var array<string, string>
	 */
	private const PROSE_VALUE_RULES = array(
		'bearer_or_basic'      => '#(?<![a-z0-9])(?:bearer|basic)\s+\S#i',
		'credential_phrase_is' => '#(?:pin|key)(?![a-z])\s+is(?![a-z])#i',
	);

	/**
	 * STRICT: screens untrusted free-text detail from a foreign package.
	 *
	 * Applies every shape rule and the prose credential-word rules, so a credential
	 * word with any other text before or after it drops the whole text; the word on
	 * its own may pass. Use for updater
	 * rejection and failure detail, with a small bound.
	 *
	 * Null means drop the whole text: it was empty, longer than MAX_INPUT_LENGTH,
	 * not printable ASCII, matched a rule, or $max_length was outside 1 to
	 * MAX_INPUT_LENGTH. The whole text is screened before it is bounded, so a
	 * secret past or across the bound drops the text. Surrounding spaces are
	 * trimmed; nothing else is changed.
	 *
	 * A validating sink that must reject over-long text instead of truncating it
	 * compares: `FailureText::strict( $text, $max ) === $text`.
	 *
	 * @param string $text       Untrusted failure text.
	 * @param int    $max_length Longest result to return, in bytes (1 to MAX_INPUT_LENGTH).
	 */
	public static function strict( ?string $text, int $max_length ): ?string {
		return self::screen( $text, $max_length, true );
	}

	/**
	 * DISPLAY: screens curated sentences that Core shows, by shape only.
	 *
	 * Identical to `strict()` except that the prose credential-word rules are not
	 * applied, so `Check the token permissions and organisation approval.` passes.
	 * Tokens, userinfo, URLs, query credentials, digests, hex and base64 runs,
	 * paths, markup and control, format and non-ASCII characters are still
	 * dropped. Full sentences with spaces and punctuation are allowed.
	 *
	 * @param string $text       Curated failure text.
	 * @param int    $max_length Longest result to return, in bytes (1 to MAX_INPUT_LENGTH).
	 */
	public static function display( ?string $text, int $max_length ): ?string {
		return self::screen( $text, $max_length, false );
	}

	/**
	 * IDENTIFIER: returns the text only when it is a closed snake_case identifier.
	 *
	 * Exactly `[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*`, at most MAX_IDENTIFIER_LENGTH bytes, and
	 * not hostile by any shape rule, so diagnostic ids and reason codes such as
	 * `ran_booster_storage_transaction_unavailable`, `gh.credential.valid` or `token_expired` pass, while `ghp_abc`, `npm_x`, `sha256_x`, a 16 or more
	 * digit hex word and a 25 or more character word do not. Never truncated.
	 *
	 * @param string $text Candidate identifier.
	 */
	public static function identifier( ?string $text ): ?string {
		if ( null === $text || strlen( $text ) > self::MAX_IDENTIFIER_LENGTH ) {
			return null;
		}
		try {
			return self::test( '#\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z#', $text ) && ! self::hostile( $text, false ) ? $text : null;
		} catch ( \RuntimeException ) {
			return null;
		}
	}

	/**
	 * Returns the text only when it is exactly a lowercase 40- or 64-digit hex commit id.
	 *
	 * This is the narrow opt-in for resolved commit ids, which `strict()` and `display()` always
	 * drops because they have the shape of a digest. Nothing else is allowed: no
	 * other length, case, surrounding text or whitespace.
	 *
	 * @param string $text Candidate commit id.
	 */
	public static function commit_id( ?string $text ): ?string {
		return null !== $text && 1 === preg_match( '#\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z#', $text ) ? $text : null;
	}

	/**
	 * Shared screening for `strict()` and `display()`.
	 *
	 * @param ?string $text      Untrusted text.
	 * @param int    $max_length Longest result to return, in bytes.
	 * @param bool   $prose      Whether the prose credential-word rules apply.
	 */
	private static function screen( ?string $text, int $max_length, bool $prose ): ?string {
		if ( null === $text || $max_length < 1 || $max_length > self::MAX_INPUT_LENGTH || strlen( $text ) > self::MAX_INPUT_LENGTH ) {
			return null;
		}
		try {
			// Non-ASCII, control, format and invisible characters are never accepted, and are never normalized away.
			if ( ! self::test( '#\A[\x20-\x7E]+\z#', $text ) ) { // rule: printable_ascii_only
				return null;
			}
			$text = trim( $text, ' ' );
			if ( '' === $text || self::hostile( $text, $prose ) ) {
				return null;
			}
			$bounded = rtrim( substr( $text, 0, $max_length ) );
			// A cut can leave a credential word at the end of what remains (`a passenger` cut to `a pass`): screen it again.
			if ( $bounded !== $text && self::hostile( $bounded, $prose ) ) {
				return null;
			}
		} catch ( \RuntimeException ) {
			// A regular-expression engine failure (backtrack or JIT limit) is never evidence of safety: fail closed.
			return null;
		}

		return $bounded;
	}

	/**
	 * True when the printable-ASCII text matches any rule.
	 *
	 * @param string $text  Text already confined to printable ASCII.
	 * @param bool   $prose Whether the prose credential-word rules apply.
	 */
	private static function hostile( string $text, bool $prose ): bool {
		return self::matches_table( $text, $prose )
			|| self::hex_run( $text ) // rule: hex_run
			|| self::hex_word( $text ) // rule: hex_word
			|| self::long_mixed_run( $text ) // rule: long_mixed_run
			|| self::long_word( $text ) // rule: long_word
			|| self::dense_chunks( $text ); // rule: dense_chunks
	}

	/**
	 * Applies the single-shape rules.
	 *
	 * @param string $text  Text already confined to printable ASCII.
	 * @param bool   $prose Whether the prose credential-word rules apply.
	 */
	private static function matches_table( string $text, bool $prose ): bool {
		foreach ( $prose ? array_merge( self::RULES, self::PROSE_VALUE_RULES ) : self::RULES as $pattern ) {
			if ( self::test( $pattern, $text ) ) {
				return true;
			}
		}
		if ( $prose ) {
			foreach ( self::PROSE_RULES as $pattern ) {
				// A credential word beside any other text is dropped; the word alone is not.
				$found = self::first( $pattern, $text );
				if ( null !== $found && $found !== $text ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Thirty-two or more hex digits once every separator, space and punctuation mark is removed.
	 *
	 * Catches digests and UUIDs however they are broken up.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function hex_run( string $text ): bool {
		return self::test( '#[0-9a-f]{32}#i', self::strip( '#[^0-9A-Za-z]+#', $text ) );
	}

	/**
	 * Sixteen or more hex digits in one word once punctuation is removed.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function hex_word( string $text ): bool {
		return self::test( '#[0-9a-f]{16}#i', self::strip( '#[^0-9A-Za-z\s]+#', $text ) );
	}

	/**
	 * A 32+ character word, with punctuation removed, that holds a digit or a capital letter.
	 *
	 * Base64, base64url and mixed tokens. A lowercase-only snake_case code is not matched.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function long_mixed_run( string $text ): bool {
		return self::test( '#(?<![0-9A-Za-z])(?=[0-9A-Za-z]*[0-9A-Z])[0-9A-Za-z]{32}#', self::strip( '#[^0-9A-Za-z\s]+#', $text ) );
	}

	/**
	 * A 25+ character run of letters and digits, which no ordinary word or identifier part reaches.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function long_word( string $text ): bool {
		return self::test( '#[0-9A-Za-z]{25}#', $text );
	}

	/**
	 * Consecutive space-separated chunks that each mix letters and digits, or mix lower then upper case: 20+ characters across two or more chunks, or 24+ in one; or a single 16 to 23 character word holding a digit, a capital and a lowercase letter.
	 *
	 * Words of 32 or more characters belong to long_mixed_run. Catches an encoded secret split by whitespace.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function dense_chunks( string $text ): bool {
		$run    = 0;
		$chunks = 0;
		$words  = preg_split( '#\s+#', self::strip( '#[^0-9A-Za-z\s]+#', $text ) );
		if ( false === $words ) {
			throw new \RuntimeException( 'The regular-expression engine failed.' );
		}
		foreach ( $words as $word ) {
			// A single 16 to 23 character word that mixes a digit, a capital and a lowercase letter looks random.
			if ( strlen( $word ) >= 16 && strlen( $word ) <= 23 && self::test( '#\d#', $word ) && self::test( '#[A-Z]#', $word ) && self::test( '#[a-z]#', $word ) ) {
				return true;
			}
			if ( strlen( $word ) < 32 && ( ( self::test( '#\d#', $word ) && self::test( '#[A-Za-z]#', $word ) ) || self::test( '#[a-z].*[A-Z]#', $word ) ) ) {
				$run += strlen( $word );
				++$chunks;
				if ( $run >= 24 || ( $chunks >= 2 && $run >= 20 ) ) {
					return true;
				}
			} else {
				$run    = 0;
				$chunks = 0;
			}
		}

		return false;
	}

	/**
	 * The single regular-expression test: an engine failure is thrown, never read as no match.
	 *
	 * @param string $pattern Pattern.
	 * @param string $subject Subject.
	 * @throws \RuntimeException When the engine fails (for example a backtrack or JIT stack limit).
	 */
	private static function test( string $pattern, string $subject ): bool {
		$result = preg_match( $pattern, $subject );
		if ( false === $result ) {
			throw new \RuntimeException( 'The regular-expression engine failed.' );
		}

		return 1 === $result;
	}

	/**
	 * Removes every match of $pattern; an engine failure is thrown, never read as an empty result.
	 *
	 * @param string $pattern Pattern.
	 * @param string $subject Subject.
	 * @throws \RuntimeException When the engine fails.
	 */
	private static function strip( string $pattern, string $subject ): string {
		$result = preg_replace( $pattern, '', $subject );
		if ( null === $result ) {
			throw new \RuntimeException( 'The regular-expression engine failed.' );
		}

		return $result;
	}

	/**
	 * The first match of $pattern, or null; an engine failure is thrown, never read as no match.
	 *
	 * @param string $pattern Pattern.
	 * @param string $subject Subject.
	 * @throws \RuntimeException When the engine fails.
	 */
	private static function first( string $pattern, string $subject ): ?string {
		$matched = preg_match( $pattern, $subject, $found );
		if ( false === $matched ) {
			throw new \RuntimeException( 'The regular-expression engine failed.' );
		}

		return 1 === $matched ? $found[0] : null;
	}
}
