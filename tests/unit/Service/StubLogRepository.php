<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\LogRepository;

final class StubLogRepository extends LogRepository {

	/** @var list<array<string,mixed>> */
	public array $records = [];

	private int $next_id = 1;

	public function __construct() {}

	public function record(
		string $level,
		string $event_type,
		string $message,
		array $context = [],
		?int $product_id = null,
		?string $template_key = null,
		?int $order_id = null
	): int {
		$id = $this->next_id++;
		$this->records[] = [
			'id'           => $id,
			'created_at'   => '2026-04-29 12:00:00.000000',
			'level'        => $level,
			'event_type'   => $event_type,
			'user_id'      => 1,
			'product_id'   => $product_id,
			'order_id'     => $order_id,
			'template_key' => $template_key,
			'context_json' => count( $context ) === 0 ? null : json_encode( $context ),
			'message'      => $message,
		];
		return $id;
	}

	public function list_diagnostic_acknowledgements(): array {
		$out = [];
		foreach ( $this->records as $row ) {
			if ( $row['event_type'] !== self::EVENT_DIAGNOSTIC_ACK ) continue;
			$ctx = $row['context_json'] ? json_decode( (string) $row['context_json'], true ) : [];
			$out[] = [
				'id'          => (int) $row['id'],
				'created_at'  => (string) $row['created_at'],
				'user_id'     => (int) $row['user_id'],
				'issue_id'    => (string) ( $ctx['issue_id'] ?? '' ),
				'object_type' => (string) ( $ctx['object_type'] ?? '' ),
				'object_id'   => $ctx['object_id'] ?? null,
			];
		}
		return $out;
	}

	public function build_acknowledgement_index(): array {
		$index = [];
		foreach ( $this->list_diagnostic_acknowledgements() as $ack ) {
			$key = $ack['issue_id'] . '|' . $ack['object_type'] . '|' . ( $ack['object_id'] === null ? '' : (string) $ack['object_id'] );
			$index[ $key ] = [
				'ack_at'         => $ack['created_at'],
				'ack_by_user_id' => $ack['user_id'],
			];
		}
		return $index;
	}
}
