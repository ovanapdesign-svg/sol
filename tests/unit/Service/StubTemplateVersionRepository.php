<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\TemplateVersionRepository;

final class StubTemplateVersionRepository extends TemplateVersionRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function find_by_id( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function find_by_template_and_number( string $template_key, int $version_number ): ?array {
		foreach ( $this->records as $rec ) {
			if ( $rec['template_key'] === $template_key && (int) $rec['version_number'] === $version_number ) {
				return $rec;
			}
		}
		return null;
	}

	public function list_for_template( string $template_key ): array {
		$items = array_values( array_filter(
			$this->records,
			static fn( array $r ): bool => $r['template_key'] === $template_key
		) );
		usort(
			$items,
			static fn( array $a, array $b ): int => ( (int) $b['version_number'] ) <=> ( (int) $a['version_number'] )
		);
		return [ 'items' => $items, 'total' => count( $items ) ];
	}

	public function max_version_number( string $template_key ): int {
		$max = 0;
		foreach ( $this->records as $rec ) {
			if ( $rec['template_key'] === $template_key && (int) $rec['version_number'] > $max ) {
				$max = (int) $rec['version_number'];
			}
		}
		return $max;
	}

	public function create( array $data ): int {
		$id  = $this->next_id++;
		$now = '2026-04-30 12:00:00';
		$snapshot_json = (string) ( $data['snapshot_json'] ?? '{}' );
		$snapshot      = json_decode( $snapshot_json, true );
		$this->records[ $id ] = [
			'id'             => $id,
			'template_key'   => (string) ( $data['template_key'] ?? '' ),
			'version_number' => (int) ( $data['version_number'] ?? 1 ),
			'status'         => (string) ( $data['status'] ?? 'published' ),
			'snapshot'       => is_array( $snapshot ) ? $snapshot : [],
			'published_at'   => (string) ( $data['published_at'] ?? $now ),
			'published_by'   => isset( $data['published_by'] ) ? (int) $data['published_by'] : null,
			'notes'          => $data['notes'] ?? null,
			'created_at'     => $now,
		];
		return $id;
	}
}
