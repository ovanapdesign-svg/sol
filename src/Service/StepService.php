<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Validation\KeyValidator;

final class StepService {

	private const LABEL_MIN = 2;
	private const LABEL_MAX = 200;

	public function __construct(
		private StepRepository $steps,
		private TemplateRepository $templates,
	) {}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}|null
	 */
	public function list_for_template( int $template_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		return $this->steps->list_in_template( (string) $template['template_key'] );
	}

	public function get( int $template_id, int $step_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		$step = $this->steps->find_by_id( $step_id );
		if ( $step === null || $step['template_key'] !== $template['template_key'] ) {
			return null;
		}
		return $step;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, id?:int, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function create( int $template_id, array $input ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'template_not_found', 'message' => 'Parent template not found.' ] ] ];
		}
		$errors = $this->validate( $input, null, $template );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input );
		$sanitized['template_key'] = (string) $template['template_key'];

		// Default sort_order to (max + 1) when caller did not supply one.
		if ( ! array_key_exists( 'sort_order', $input ) || $input['sort_order'] === '' || $input['sort_order'] === null ) {
			$sanitized['sort_order'] = $this->steps->max_sort_order( (string) $template['template_key'] ) + 1;
		}

		$id = $this->steps->create( $sanitized );
		return [ 'ok' => true, 'id' => $id, 'record' => $this->steps->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $template_id, int $step_id, array $input, string $expected_version_hash ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'template_not_found', 'message' => 'Parent template not found.' ] ] ];
		}
		$existing = $this->steps->find_by_id( $step_id );
		if ( $existing === null || $existing['template_key'] !== $template['template_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Step not found.' ] ] ];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Step was edited elsewhere. Reload and try again.' ] ] ];
		}
		$errors = $this->validate( $input, $existing, $template );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input );
		// step_key is immutable once created.
		$sanitized['step_key']     = (string) $existing['step_key'];
		$sanitized['template_key'] = (string) $template['template_key'];
		// Preserve sort_order if not explicitly supplied (form may omit it).
		if ( ! array_key_exists( 'sort_order', $input ) || $input['sort_order'] === '' || $input['sort_order'] === null ) {
			$sanitized['sort_order'] = (int) $existing['sort_order'];
		}
		$this->steps->update( $step_id, $sanitized );
		return [ 'ok' => true, 'record' => $this->steps->find_by_id( $step_id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function delete( int $template_id, int $step_id ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'template_not_found', 'message' => 'Parent template not found.' ] ] ];
		}
		$existing = $this->steps->find_by_id( $step_id );
		if ( $existing === null || $existing['template_key'] !== $template['template_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Step not found.' ] ] ];
		}
		$this->steps->delete( $step_id );
		return [ 'ok' => true ];
	}

	/**
	 * Apply a bulk reorder. Each item is { step_id, sort_order }. Steps
	 * not in the list keep their current sort_order. Items not belonging
	 * to this template are silently skipped (treated as out-of-scope).
	 *
	 * @param list<array<string,mixed>> $items
	 * @return array{ok:bool, summary:array<string,int>, errors:list<array<string,mixed>>}
	 */
	public function reorder( int $template_id, array $items ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [
				'ok'      => false,
				'summary' => [ 'updated' => 0, 'skipped' => 0 ],
				'errors'  => [ [ 'code' => 'template_not_found', 'message' => 'Parent template not found.' ] ],
			];
		}
		$updated = 0;
		$skipped = 0;
		$errors  = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['step_id'] ) || ! isset( $item['sort_order'] ) ) {
				$skipped++;
				$errors[] = [ 'row' => $index, 'code' => 'invalid_row', 'message' => 'Each item must have step_id and sort_order.' ];
				continue;
			}
			$step = $this->steps->find_by_id( (int) $item['step_id'] );
			if ( $step === null || $step['template_key'] !== $template['template_key'] ) {
				$skipped++;
				continue;
			}
			$this->steps->set_sort_order( (int) $item['step_id'], (int) $item['sort_order'] );
			$updated++;
		}
		return [
			'ok'      => count( $errors ) === 0,
			'summary' => [ 'updated' => $updated, 'skipped' => $skipped ],
			'errors'  => $errors,
		];
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing
	 * @param array<string,mixed>      $template
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input, ?array $existing, array $template ): array {
		$errors = [];

		// step_key is immutable on update; only validate format/uniqueness on create.
		if ( $existing === null ) {
			$step_key   = isset( $input['step_key'] ) ? (string) $input['step_key'] : '';
			$key_errors = KeyValidator::validate( 'step_key', $step_key );
			if ( count( $key_errors ) > 0 ) {
				$errors = array_merge( $errors, $key_errors );
			} elseif ( $this->steps->key_exists_in_template( (string) $template['template_key'], $step_key ) ) {
				$errors[] = [
					'field'   => 'step_key',
					'code'    => 'duplicate',
					'message' => 'A step with this key already exists in this template.',
				];
			}
		}

		$label        = isset( $input['label'] ) ? trim( (string) $input['label'] ) : '';
		$label_length = strlen( $label );
		if ( $label === '' ) {
			$errors[] = [ 'field' => 'label', 'code' => 'required', 'message' => 'label is required.' ];
		} elseif ( $label_length < self::LABEL_MIN ) {
			$errors[] = [
				'field'   => 'label',
				'code'    => 'too_short',
				'message' => sprintf( 'label must be at least %d characters.', self::LABEL_MIN ),
			];
		} elseif ( $label_length > self::LABEL_MAX ) {
			$errors[] = [
				'field'   => 'label',
				'code'    => 'too_long',
				'message' => sprintf( 'label must be at most %d characters.', self::LABEL_MAX ),
			];
		}

		if ( array_key_exists( 'sort_order', $input ) && $input['sort_order'] !== '' && $input['sort_order'] !== null ) {
			if ( ! is_numeric( $input['sort_order'] ) ) {
				$errors[] = [ 'field' => 'sort_order', 'code' => 'invalid_type', 'message' => 'sort_order must be numeric.' ];
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
			'step_key'                => (string) ( $input['step_key'] ?? '' ),
			'label'                   => trim( (string) ( $input['label'] ?? '' ) ),
			'description'             => isset( $input['description'] ) && $input['description'] !== '' ? (string) $input['description'] : null,
			'sort_order'              => isset( $input['sort_order'] ) && $input['sort_order'] !== '' ? (int) $input['sort_order'] : 0,
			'is_required'             => ! empty( $input['is_required'] ),
			'is_collapsed_by_default' => ! empty( $input['is_collapsed_by_default'] ),
		];
	}
}
