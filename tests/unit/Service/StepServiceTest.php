<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\StepService;
use ConfigKit\Service\TemplateService;
use PHPUnit\Framework\TestCase;

final class StepServiceTest extends TestCase {

	private StubStepRepository $stepRepo;
	private StubTemplateRepository $tmplRepo;
	private StepService $service;
	private int $template_id;
	private int $other_template_id;

	protected function setUp(): void {
		$this->stepRepo = new StubStepRepository();
		$this->tmplRepo = new StubTemplateRepository();
		$this->service  = new StepService( $this->stepRepo, $this->tmplRepo );

		// Seed two templates via TemplateService so canonical record shape is used.
		$tmplSvc = new TemplateService( $this->tmplRepo );
		$created = $tmplSvc->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise motorisert',
			'status'       => 'draft',
		] );
		$this->template_id = $created['id'];

		$other = $tmplSvc->create( [
			'template_key' => 'markise_manuell',
			'name'         => 'Markise manuell',
			'status'       => 'draft',
		] );
		$this->other_template_id = $other['id'];
	}

	private function valid_step( array $overrides = [] ): array {
		return array_replace(
			[
				'step_key'    => 'maal',
				'label'       => 'Mål',
				'description' => 'Width and height',
				'is_required' => true,
				'sort_order'  => 1,
			],
			$overrides
		);
	}

	public function test_create_with_valid_input_succeeds(): void {
		$result = $this->service->create( $this->template_id, $this->valid_step() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'maal', $result['record']['step_key'] );
		$this->assertSame( 'Mål', $result['record']['label'] );
		$this->assertSame( 'markise_motorisert', $result['record']['template_key'] );
	}

	public function test_create_in_unknown_template_returns_template_not_found(): void {
		$result = $this->service->create( 9999, $this->valid_step() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'template_not_found', $result['errors'][0]['code'] );
	}

	public function test_create_missing_step_key_returns_required(): void {
		$result = $this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_two_char_step_key_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => 'ab' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_reserved_step_key_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => 'admin' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'reserved', $codes );
	}

	public function test_create_step_key_with_dot_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => 'foo.bar' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_chars', $codes );
	}

	public function test_create_duplicate_step_key_in_same_template_is_rejected(): void {
		$this->service->create( $this->template_id, $this->valid_step() );
		$result = $this->service->create( $this->template_id, $this->valid_step() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_same_step_key_in_different_templates_is_allowed(): void {
		$first  = $this->service->create( $this->template_id, $this->valid_step() );
		$second = $this->service->create( $this->other_template_id, $this->valid_step() );
		$this->assertTrue( $first['ok'] );
		$this->assertTrue( $second['ok'] );
	}

	public function test_create_missing_label_returns_required(): void {
		$result = $this->service->create( $this->template_id, $this->valid_step( [ 'label' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_short_label_returns_too_short(): void {
		$result = $this->service->create( $this->template_id, $this->valid_step( [ 'label' => 'A' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_201_char_label_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_step( [ 'label' => str_repeat( 'a', 201 ) ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_long', $codes );
	}

	public function test_create_without_sort_order_assigns_max_plus_one(): void {
		$first  = $this->service->create( $this->template_id, $this->valid_step( [ 'sort_order' => 5 ] ) );
		$input2 = $this->valid_step( [ 'step_key' => 'duk', 'label' => 'Duk' ] );
		unset( $input2['sort_order'] );
		$second = $this->service->create( $this->template_id, $input2 );

		$this->assertTrue( $second['ok'] );
		$this->assertSame( 6, $second['record']['sort_order'] );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->template_id, $this->valid_step() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_step( [ 'label' => 'Mål (renamed)' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Mål (renamed)', $result['record']['label'] );
	}

	public function test_update_with_stale_version_hash_returns_conflict(): void {
		$created = $this->service->create( $this->template_id, $this->valid_step() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_step( [ 'label' => 'X-X' ] ),
			'stale-hash'
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'conflict', $result['errors'][0]['code'] );
	}

	public function test_update_step_in_wrong_template_returns_not_found(): void {
		$created = $this->service->create( $this->template_id, $this->valid_step() );
		$result  = $this->service->update(
			$this->other_template_id,
			$created['id'],
			$this->valid_step( [ 'label' => 'Renamed' ] ),
			$created['record']['version_hash']
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_update_step_key_is_immutable(): void {
		$created = $this->service->create( $this->template_id, $this->valid_step() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_step( [ 'step_key' => 'something_else', 'label' => 'Renamed' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'maal', $result['record']['step_key'] );
	}

	public function test_delete_removes_step(): void {
		$created = $this->service->create( $this->template_id, $this->valid_step() );
		$result  = $this->service->delete( $this->template_id, $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertNull( $this->stepRepo->find_by_id( $created['id'] ) );
	}

	public function test_delete_step_in_wrong_template_returns_not_found(): void {
		$created = $this->service->create( $this->template_id, $this->valid_step() );
		$result  = $this->service->delete( $this->other_template_id, $created['id'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_list_for_unknown_template_returns_null(): void {
		$result = $this->service->list_for_template( 9999 );
		$this->assertNull( $result );
	}

	public function test_list_returns_steps_sorted_by_sort_order(): void {
		$this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => 'maal', 'label' => 'Mål', 'sort_order' => 2 ] ) );
		$this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => 'duk', 'label' => 'Duk', 'sort_order' => 1 ] ) );
		$this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => 'sum', 'label' => 'Sum', 'sort_order' => 3 ] ) );

		$listing = $this->service->list_for_template( $this->template_id );
		$keys    = array_column( $listing['items'], 'step_key' );
		$this->assertSame( [ 'duk', 'maal', 'sum' ], $keys );
	}

	public function test_reorder_updates_sort_orders(): void {
		$a = $this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => 'maal', 'sort_order' => 1 ] ) );
		$b = $this->service->create( $this->template_id, $this->valid_step( [ 'step_key' => 'duk', 'label' => 'Duk', 'sort_order' => 2 ] ) );

		$result = $this->service->reorder( $this->template_id, [
			[ 'step_id' => $a['id'], 'sort_order' => 10 ],
			[ 'step_id' => $b['id'], 'sort_order' => 5 ],
		] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['summary']['updated'] );
		$this->assertSame( 10, $this->stepRepo->find_by_id( $a['id'] )['sort_order'] );
		$this->assertSame( 5, $this->stepRepo->find_by_id( $b['id'] )['sort_order'] );
	}

	public function test_reorder_skips_steps_in_other_templates(): void {
		$a = $this->service->create( $this->template_id, $this->valid_step() );
		$b = $this->service->create( $this->other_template_id, $this->valid_step() );

		$result = $this->service->reorder( $this->template_id, [
			[ 'step_id' => $a['id'], 'sort_order' => 99 ],
			[ 'step_id' => $b['id'], 'sort_order' => 1 ],
		] );

		$this->assertSame( 1, $result['summary']['updated'] );
		$this->assertSame( 1, $result['summary']['skipped'] );
		$this->assertSame( 99, $this->stepRepo->find_by_id( $a['id'] )['sort_order'] );
		// b's sort_order should NOT change because it's in the other template.
		$this->assertSame( 1, $this->stepRepo->find_by_id( $b['id'] )['sort_order'] );
	}

	public function test_reorder_unknown_template_returns_template_not_found(): void {
		$result = $this->service->reorder( 9999, [] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'template_not_found', $result['errors'][0]['code'] );
	}
}
