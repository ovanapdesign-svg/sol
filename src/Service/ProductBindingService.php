<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\ProductBindingRepository;

/**
 * Validates and persists product binding state. Cross-reference checks
 * (does the template exist? does the lookup table have cells?) live in
 * ProductDiagnosticsService — the binding service intentionally accepts
 * dangling references so an owner can save partial state and fix it
 * later. The status badge in section 1.2 reflects diagnostic results.
 */
final class ProductBindingService {

	public const FRONTEND_MODES = [ 'stepper', 'accordion', 'single-page' ];
	public const SALE_MODES     = [ 'off', 'force_regular', 'discount_percent' ];
	public const VAT_DISPLAYS   = [ 'use_global', 'incl_vat', 'excl_vat', 'off' ];

	public function __construct( private ProductBindingRepository $repo ) {}

	public function get( int $product_id ): ?array {
		return $this->repo->find( $product_id );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list_overview( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		return $this->repo->list_overview( $filters, $page, $per_page );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $product_id, array $input, string $expected_version_hash ): array {
		$existing = $this->repo->find( $product_id );
		if ( $existing === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Product not found.' ] ] ];
		}
		// Empty existing version_hash means the product has no binding yet
		// — first save is allowed without a hash check.
		$existing_hash = (string) $existing['version_hash'];
		if ( $existing_hash !== '' && $existing_hash !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Binding was edited elsewhere. Reload and try again.' ] ] ];
		}

		$errors = $this->validate( $input );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}

		$sanitized = $this->sanitize( $input );
		$this->repo->save( $product_id, $sanitized );
		return [ 'ok' => true, 'record' => $this->repo->find( $product_id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input ): array {
		$errors = [];

		// frontend_mode allow-list.
		if ( array_key_exists( 'frontend_mode', $input ) && $input['frontend_mode'] !== '' && $input['frontend_mode'] !== null ) {
			if ( ! in_array( (string) $input['frontend_mode'], self::FRONTEND_MODES, true ) ) {
				$errors[] = [
					'field'   => 'frontend_mode',
					'code'    => 'invalid_value',
					'message' => 'frontend_mode must be one of: ' . implode( ', ', self::FRONTEND_MODES ) . '.',
				];
			}
		}

		// template_version_id: int ≥ 0.
		if ( array_key_exists( 'template_version_id', $input ) && $input['template_version_id'] !== '' && $input['template_version_id'] !== null ) {
			if ( ! is_numeric( $input['template_version_id'] ) || (int) $input['template_version_id'] < 0 ) {
				$errors[] = [
					'field'   => 'template_version_id',
					'code'    => 'invalid_type',
					'message' => 'template_version_id must be a non-negative integer (0 = latest).',
				];
			}
		}

		// defaults: must be assoc array of field_key → scalar/array.
		if ( array_key_exists( 'defaults', $input ) ) {
			if ( ! is_array( $input['defaults'] ) ) {
				$errors[] = [
					'field'   => 'defaults',
					'code'    => 'invalid_type',
					'message' => 'defaults must be an object keyed by field_key.',
				];
			}
		}

		// allowed_sources: per-field { allowed_libraries[], excluded_items[], allowed_price_groups[], allowed_options[] }.
		if ( array_key_exists( 'allowed_sources', $input ) ) {
			if ( ! is_array( $input['allowed_sources'] ) ) {
				$errors[] = [
					'field'   => 'allowed_sources',
					'code'    => 'invalid_type',
					'message' => 'allowed_sources must be an object keyed by field_key.',
				];
			} else {
				foreach ( $input['allowed_sources'] as $field_key => $cfg ) {
					if ( ! is_array( $cfg ) ) {
						$errors[] = [
							'field'   => 'allowed_sources',
							'code'    => 'invalid_type',
							'message' => sprintf( 'allowed_sources[%s] must be an object.', is_string( $field_key ) ? $field_key : '?' ),
						];
						continue;
					}
					foreach ( [ 'allowed_libraries', 'excluded_items', 'allowed_price_groups', 'allowed_options' ] as $list_key ) {
						if ( array_key_exists( $list_key, $cfg ) && ! is_array( $cfg[ $list_key ] ) ) {
							$errors[] = [
								'field'   => 'allowed_sources',
								'code'    => 'invalid_type',
								'message' => sprintf( 'allowed_sources[%s].%s must be an array.', $field_key, $list_key ),
							];
						}
					}
				}
			}
		}

		// pricing_overrides: 6 known keys, each numeric where applicable.
		if ( array_key_exists( 'pricing_overrides', $input ) ) {
			if ( ! is_array( $input['pricing_overrides'] ) ) {
				$errors[] = [
					'field'   => 'pricing_overrides',
					'code'    => 'invalid_type',
					'message' => 'pricing_overrides must be an object.',
				];
			} else {
				$po = $input['pricing_overrides'];
				foreach ( [ 'base_price_fallback', 'minimum_price', 'product_surcharge', 'discount_percent' ] as $num_key ) {
					if ( array_key_exists( $num_key, $po ) && $po[ $num_key ] !== '' && $po[ $num_key ] !== null ) {
						if ( ! is_numeric( $po[ $num_key ] ) ) {
							$errors[] = [
								'field'   => 'pricing_overrides',
								'code'    => 'invalid_type',
								'message' => sprintf( 'pricing_overrides.%s must be numeric.', $num_key ),
							];
						} elseif ( (float) $po[ $num_key ] < 0 ) {
							$errors[] = [
								'field'   => 'pricing_overrides',
								'code'    => 'invalid_value',
								'message' => sprintf( 'pricing_overrides.%s must be ≥ 0.', $num_key ),
							];
						}
					}
				}
				if ( array_key_exists( 'sale_mode', $po ) && $po['sale_mode'] !== '' && $po['sale_mode'] !== null ) {
					if ( ! in_array( (string) $po['sale_mode'], self::SALE_MODES, true ) ) {
						$errors[] = [
							'field'   => 'pricing_overrides',
							'code'    => 'invalid_value',
							'message' => 'pricing_overrides.sale_mode must be one of: ' . implode( ', ', self::SALE_MODES ) . '.',
						];
					}
				}
				if ( array_key_exists( 'vat_display', $po ) && $po['vat_display'] !== '' && $po['vat_display'] !== null ) {
					if ( ! in_array( (string) $po['vat_display'], self::VAT_DISPLAYS, true ) ) {
						$errors[] = [
							'field'   => 'pricing_overrides',
							'code'    => 'invalid_value',
							'message' => 'pricing_overrides.vat_display must be one of: ' . implode( ', ', self::VAT_DISPLAYS ) . '.',
						];
					}
				}
				if ( array_key_exists( 'allowed_price_groups', $po ) && ! is_array( $po['allowed_price_groups'] ) ) {
					$errors[] = [
						'field'   => 'pricing_overrides',
						'code'    => 'invalid_type',
						'message' => 'pricing_overrides.allowed_price_groups must be an array.',
					];
				}
			}
		}

		// field_overrides: per-field { hide?, require?, lock?, preselect? }.
		if ( array_key_exists( 'field_overrides', $input ) ) {
			if ( ! is_array( $input['field_overrides'] ) ) {
				$errors[] = [
					'field'   => 'field_overrides',
					'code'    => 'invalid_type',
					'message' => 'field_overrides must be an object keyed by field_key.',
				];
			} else {
				foreach ( $input['field_overrides'] as $fk => $cfg ) {
					if ( ! is_array( $cfg ) ) {
						$errors[] = [
							'field'   => 'field_overrides',
							'code'    => 'invalid_type',
							'message' => sprintf( 'field_overrides[%s] must be an object.', is_string( $fk ) ? $fk : '?' ),
						];
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		return [
			'enabled'              => ! empty( $input['enabled'] ),
			'template_key'         => isset( $input['template_key'] ) && $input['template_key'] !== '' ? (string) $input['template_key'] : null,
			'template_version_id'  => isset( $input['template_version_id'] ) && $input['template_version_id'] !== '' ? (int) $input['template_version_id'] : 0,
			'lookup_table_key'     => isset( $input['lookup_table_key'] ) && $input['lookup_table_key'] !== '' ? (string) $input['lookup_table_key'] : null,
			'family_key'           => isset( $input['family_key'] ) && $input['family_key'] !== '' ? (string) $input['family_key'] : null,
			'frontend_mode'        => isset( $input['frontend_mode'] ) && in_array( (string) $input['frontend_mode'], self::FRONTEND_MODES, true )
				? (string) $input['frontend_mode']
				: 'stepper',
			'defaults'             => is_array( $input['defaults'] ?? null ) ? $input['defaults'] : [],
			'allowed_sources'      => is_array( $input['allowed_sources'] ?? null ) ? $input['allowed_sources'] : [],
			'pricing_overrides'    => is_array( $input['pricing_overrides'] ?? null ) ? $input['pricing_overrides'] : [],
			'field_overrides'      => is_array( $input['field_overrides'] ?? null ) ? $input['field_overrides'] : [],
		];
	}
}
