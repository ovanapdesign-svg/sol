<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

/**
 * Read/write access to `wp_configkit_log` (DATA_MODEL.md §3.13).
 *
 * Phase 3 only uses this for diagnostic acknowledgements
 * (`event_type = 'diagnostic_acknowledged'`). Other event types will
 * land alongside the runtime engines in Phase 5+.
 */
class LogRepository {

	public const EVENT_DIAGNOSTIC_ACK = 'diagnostic_acknowledged';

	public function __construct( private \wpdb $wpdb ) {}

	private function table(): string {
		return $this->wpdb->prefix . 'configkit_log';
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function record(
		string $level,
		string $event_type,
		string $message,
		array $context = [],
		?int $product_id = null,
		?string $template_key = null,
		?int $order_id = null
	): int {
		$ok = $this->wpdb->insert(
			$this->table(),
			[
				'created_at'   => $this->now(),
				'level'        => $level,
				'event_type'   => $event_type,
				'user_id'      => function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0,
				'product_id'   => $product_id,
				'order_id'     => $order_id,
				'template_key' => $template_key,
				'context_json' => count( $context ) === 0 ? null : (string) wp_json_encode( $context ),
				'message'      => $message,
			]
		);
		if ( $ok === false ) {
			throw new \RuntimeException( 'Failed to write log entry: ' . (string) $this->wpdb->last_error );
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Return all diagnostic acknowledgement rows. Decoded context shape:
	 * { issue_id: string, object_type: string, object_id: int|string|null }.
	 *
	 * @return list<array{id:int, created_at:string, user_id:int, issue_id:string, object_type:string, object_id:int|string|null}>
	 */
	public function list_diagnostic_acknowledgements(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, created_at, user_id, context_json FROM `{$this->table()}` WHERE event_type = %s ORDER BY id ASC",
				self::EVENT_DIAGNOSTIC_ACK
			),
			ARRAY_A
		) ?: [];

		$out = [];
		foreach ( $rows as $row ) {
			$ctx = $this->decode_context( (string) ( $row['context_json'] ?? '' ) );
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

	/**
	 * Build a fast O(1) lookup set keyed by "issue_id|object_type|object_id".
	 *
	 * @return array<string,array{ack_at:string, ack_by_user_id:int}>
	 */
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

	/**
	 * @return array<string,mixed>
	 */
	private function decode_context( string $json ): array {
		if ( $json === '' ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private function now(): string {
		// wp_configkit_log.created_at is DATETIME(6); microsecond precision.
		$micro = microtime( true );
		$secs  = (int) $micro;
		$frac  = (int) round( ( $micro - $secs ) * 1000000 );
		return gmdate( 'Y-m-d H:i:s', $secs ) . '.' . str_pad( (string) $frac, 6, '0', STR_PAD_LEFT );
	}
}
