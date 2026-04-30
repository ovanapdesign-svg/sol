<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.2c — validate + sanitise the per-module
 * `attribute_schema_json`. Lives next to ModuleService so the editor
 * UX can stop accepting half-built schemas without inventing its own
 * type system.
 *
 * Schema entries we accept on save (rich shape):
 *   {
 *     "fabric_code": {
 *       "label":      "Fabric code",
 *       "type":       "text" | "number" | "boolean" | "enum",
 *       "options":    [ "high", "medium", "low" ],   // when type=enum
 *       "required":   false,
 *       "sort_order": 10
 *     },
 *     ...
 *   }
 *
 * Legacy shape kept readable on the way IN — `{key: type-string}` is
 * normalised to the rich shape on the way OUT so older modules don't
 * break this round-trip.
 */
final class AttributeSchemaService {

	public const ALLOWED_TYPES = [ 'text', 'number', 'boolean', 'enum' ];

	private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

	/**
	 * Validate the proposed schema. Returns a list of structured
	 * error rows that ModuleService can merge into its overall
	 * validation result.
	 *
	 * @param mixed $raw
	 * @return list<array{field:string,code:string,message:string}>
	 */
	public function validate( mixed $raw ): array {
		if ( $raw === null || $raw === '' ) return [];
		if ( ! is_array( $raw ) ) {
			return [ [
				'field'   => 'attribute_schema',
				'code'    => 'invalid_type',
				'message' => 'attribute_schema must be an object keyed by attribute key.',
			] ];
		}

		$errors = [];
		$seen   = [];
		foreach ( $raw as $key => $entry ) {
			if ( ! is_string( $key ) || ! preg_match( self::KEY_PATTERN, $key ) ) {
				$errors[] = [
					'field'   => 'attribute_schema',
					'code'    => 'invalid_key',
					'message' => sprintf( 'Attribute key "%s" must be 1–63 chars, snake_case, starting with a letter.', is_string( $key ) ? $key : '?' ),
				];
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = [
					'field'   => 'attribute_schema',
					'code'    => 'duplicate_key',
					'message' => sprintf( 'Attribute key "%s" appears twice.', $key ),
				];
				continue;
			}
			$seen[ $key ] = true;

			$type = is_array( $entry ) ? (string) ( $entry['type'] ?? 'text' ) : (string) $entry;
			$canonical = $this->canonical_type( $type );
			if ( $canonical === null ) {
				$errors[] = [
					'field'   => 'attribute_schema',
					'code'    => 'invalid_type',
					'message' => sprintf( 'Attribute "%s" has type "%s" — must be text, number, boolean, or enum.', $key, $type ),
				];
				continue;
			}
			if ( $canonical === 'enum' ) {
				$opts = is_array( $entry ) ? ( $entry['options'] ?? [] ) : [];
				if ( ! is_array( $opts ) || count( $opts ) === 0 ) {
					$errors[] = [
						'field'   => 'attribute_schema',
						'code'    => 'enum_missing_options',
						'message' => sprintf( 'Attribute "%s" is an enum but has no options.', $key ),
					];
				}
			}
			if ( is_array( $entry ) && isset( $entry['label'] ) && ! is_string( $entry['label'] ) ) {
				$errors[] = [
					'field'   => 'attribute_schema',
					'code'    => 'invalid_label',
					'message' => sprintf( 'Attribute "%s" label must be a string.', $key ),
				];
			}
		}
		return $errors;
	}

	/**
	 * Normalise the schema into the rich shape the rest of the
	 * codebase consumes. Drops invalid entries so the post-sanitize
	 * value is always safe to store, and is sort-order stable.
	 *
	 * @param mixed $raw
	 * @return array<string,array{label:string,type:string,options?:list<string>,required:bool,sort_order:int}>
	 */
	public function sanitize( mixed $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		$auto_order = 10;
		foreach ( $raw as $key => $entry ) {
			if ( ! is_string( $key ) || ! preg_match( self::KEY_PATTERN, $key ) ) continue;

			if ( is_string( $entry ) ) {
				$type = $this->canonical_type( $entry );
				if ( $type === null ) continue;
				$out[ $key ] = [
					'label'      => $this->humanize_key( $key ),
					'type'       => $type === 'enum' ? 'text' : $type,
					'required'   => false,
					'sort_order' => $auto_order,
				];
				$auto_order += 10;
				continue;
			}
			if ( ! is_array( $entry ) ) continue;

			$type = $this->canonical_type( (string) ( $entry['type'] ?? 'text' ) );
			if ( $type === null ) continue;

			$row = [
				'label'      => isset( $entry['label'] ) && is_string( $entry['label'] ) && trim( $entry['label'] ) !== ''
					? trim( (string) $entry['label'] )
					: $this->humanize_key( $key ),
				'type'       => $type,
				'required'   => ! empty( $entry['required'] ),
				'sort_order' => isset( $entry['sort_order'] ) && is_numeric( $entry['sort_order'] )
					? (int) $entry['sort_order']
					: $auto_order,
			];
			if ( $type === 'enum' ) {
				$opts = $entry['options'] ?? [];
				if ( ! is_array( $opts ) || count( $opts ) === 0 ) continue; // skip — fails validate(), but defend on the way through anyway
				$row['options'] = array_values( array_filter( array_map(
					static fn ( $v ): string => trim( (string) $v ),
					array_filter( $opts, static fn ( $v ): bool => is_scalar( $v ) )
				), static fn ( string $v ): bool => $v !== '' ) );
				if ( count( $row['options'] ) === 0 ) continue;
			}
			$out[ $key ] = $row;
			$auto_order += 10;
		}
		return $out;
	}

	private function canonical_type( string $raw ): ?string {
		$lower = strtolower( trim( $raw ) );
		if ( in_array( $lower, [ 'string', 'str', 'varchar' ], true ) )      return 'text';
		if ( in_array( $lower, [ 'integer', 'int', 'float', 'decimal' ], true ) ) return 'number';
		if ( $lower === 'bool' ) return 'boolean';
		if ( in_array( $lower, self::ALLOWED_TYPES, true ) ) return $lower;
		return null;
	}

	private function humanize_key( string $key ): string {
		$out = preg_replace( '/[_\-]+/', ' ', $key );
		$out = trim( (string) $out );
		if ( $out === '' ) return $key;
		return strtoupper( substr( $out, 0, 1 ) ) . substr( $out, 1 );
	}
}
