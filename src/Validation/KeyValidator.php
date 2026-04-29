<?php
declare(strict_types=1);

namespace ConfigKit\Validation;

/**
 * Shared validation for all `*_key` fields across ConfigKit entities.
 *
 * Rules (locked in across Modules, Libraries, Library Items, Lookup
 * Tables, and any future entity carrying a snake_case identity column):
 *
 * - Minimum 3 characters
 * - Maximum 64 characters
 * - Must start with a lowercase ASCII letter
 * - Lowercase ASCII letters, digits, and underscores only
 * - Must not be a reserved keyword
 */
final class KeyValidator {

	public const MIN_LENGTH = 3;
	public const MAX_LENGTH = 64;

	private const RESERVED = [
		'admin',
		'config',
		'configkit',
		'wp',
		'rest',
		'api',
		'system',
		'default',
		'all',
		'none',
		'null',
		'undefined',
	];

	/**
	 * Validate a `*_key` value.
	 *
	 * Returns a (possibly empty) list of `{field, code, message}` records
	 * suitable to merge into a service's error array. The empty key is
	 * always reported with code `required` and short-circuits other
	 * checks; non-empty keys may surface multiple codes simultaneously
	 * (e.g. too_short + invalid_chars).
	 *
	 * @return list<array{field:string, code:string, message:string}>
	 */
	public static function validate( string $field, string $key ): array {
		if ( $key === '' ) {
			return [ [
				'field'   => $field,
				'code'    => 'required',
				'message' => sprintf( '%s is required.', $field ),
			] ];
		}

		$errors = [];
		$length = strlen( $key );

		if ( $length < self::MIN_LENGTH ) {
			$errors[] = [
				'field'   => $field,
				'code'    => 'too_short',
				'message' => sprintf( '%s must be at least %d characters.', $field, self::MIN_LENGTH ),
			];
		}
		if ( $length > self::MAX_LENGTH ) {
			$errors[] = [
				'field'   => $field,
				'code'    => 'too_long',
				'message' => sprintf( '%s must be at most %d characters.', $field, self::MAX_LENGTH ),
			];
		}

		// Pattern check splits into two messages so the user knows whether
		// the bad character is at the start (digit / underscore / etc.) or
		// elsewhere.
		if ( ! preg_match( '/^[a-z]/', $key ) ) {
			$errors[] = [
				'field'   => $field,
				'code'    => 'invalid_start',
				'message' => sprintf( '%s must start with a lowercase letter.', $field ),
			];
		}

		if ( ! preg_match( '/^[a-z0-9_]+$/', $key ) ) {
			$errors[] = [
				'field'   => $field,
				'code'    => 'invalid_chars',
				'message' => sprintf( '%s may only contain lowercase letters, digits, and underscores.', $field ),
			];
		}

		if ( in_array( $key, self::RESERVED, true ) ) {
			$errors[] = [
				'field'   => $field,
				'code'    => 'reserved',
				'message' => sprintf( '"%s" is a reserved keyword.', $key ),
			];
		}

		return $errors;
	}
}
