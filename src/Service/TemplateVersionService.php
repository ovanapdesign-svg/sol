<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\FieldOptionRepository;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\RuleRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Repository\TemplateVersionRepository;

/**
 * Snapshot + publish for templates. Versions are immutable once written;
 * a fresh publish always creates v(N+1).
 *
 * The snapshot is a self-contained denormalised dump of every entity
 * the runtime needs: template metadata + steps + fields + manual
 * options + rules. Stored as JSON-encoded LONGTEXT per
 * TARGET_ARCHITECTURE.md §15 (no native JSON columns).
 */
final class TemplateVersionService {

	public const ENGINE_VERSION = '1.0.0';

	public function __construct(
		private TemplateVersionRepository $versions,
		private TemplateRepository $templates,
		private StepRepository $steps,
		private FieldRepository $fields,
		private FieldOptionRepository $options,
		private RuleRepository $rules,
		private TemplateValidator $validator,
	) {}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}|null
	 */
	public function list_for_template( int $template_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		return $this->versions->list_for_template( (string) $template['template_key'] );
	}

	public function get_version( int $template_id, int $version_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		$version = $this->versions->find_by_id( $version_id );
		if ( $version === null || $version['template_key'] !== $template['template_key'] ) {
			return null;
		}
		return $version;
	}

	/**
	 * Build a snapshot of the current draft state.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build_snapshot( int $template_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		$template_key = (string) $template['template_key'];

		$steps_listing = $this->steps->list_in_template( $template_key );
		$steps         = $steps_listing['items'];

		$fields_all = [];
		$options_all = [];
		foreach ( $steps as $step ) {
			$fields_in_step = $this->fields->list_in_step( $template_key, (string) $step['step_key'] )['items'];
			foreach ( $fields_in_step as $field ) {
				$fields_all[] = $field;
				$opts         = $this->options->list_for_field( $template_key, (string) $field['field_key'] )['items'];
				foreach ( $opts as $opt ) {
					$options_all[] = $opt;
				}
			}
		}

		$rules = $this->rules->list_in_template( $template_key )['items'];

		$user_id = function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
		return [
			'template'          => $template,
			'steps'             => $steps,
			'fields'            => $fields_all,
			'field_options'     => $options_all,
			'rules'             => $rules,
			'snapshot_metadata' => [
				'snapshot_at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'published_by_user_id' => $user_id ?: null,
				'engine_version'      => self::ENGINE_VERSION,
			],
		];
	}

	/**
	 * @return array{
	 *   ok: bool,
	 *   record?: array<string,mixed>,
	 *   validation?: array<string,mixed>,
	 *   errors?: list<array<string,string>>
	 * }
	 */
	public function publish( int $template_id ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Template not found.' ] ] ];
		}

		$validation = $this->validator->validate( $template_id );
		if ( $validation === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Template not found.' ] ] ];
		}
		if ( ! $validation['valid'] ) {
			return [
				'ok'         => false,
				'validation' => $validation,
				'errors'     => [ [ 'code' => 'validation_failed', 'message' => 'Pre-publish validation failed. Fix errors and try again.' ] ],
			];
		}

		$snapshot = $this->build_snapshot( $template_id );
		if ( $snapshot === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Template not found.' ] ] ];
		}

		$template_key   = (string) $template['template_key'];
		$next_version   = $this->versions->max_version_number( $template_key ) + 1;
		$snapshot_json  = (string) wp_json_encode( $snapshot );
		$user_id        = function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
		$published_at   = function_exists( 'current_time' ) ? (string) \current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

		$id = $this->versions->create( [
			'template_key'   => $template_key,
			'version_number' => $next_version,
			'status'         => 'published',
			'snapshot_json'  => $snapshot_json,
			'published_at'   => $published_at,
			'published_by'   => $user_id ?: null,
		] );

		// Update templates.published_version_id pointer + status via the
		// dedicated mark_published path (bypasses the user-form
		// optimistic-locking flow — publish is server-validated).
		$this->templates->mark_published( $template_id, $id );

		$record = $this->versions->find_by_id( $id );
		return [
			'ok'         => true,
			'record'     => $record ?? [],
			'validation' => $validation,
		];
	}
}
