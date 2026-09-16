<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-109 (found triaging F-020). Six saves never looked at whether their write
 * landed: an approval rule's create, change, and clear, the Schema Blocks editor, the Creature
 * Stacks editor, a boon's repayment, and a chronicle's AI Assist settings. A write that failed -
 * a lost connection, a lock wait that timed out - was answered 200 or 201 as if it had saved,
 * with the old data or nothing. A chronicle's copy of a block that couldn't be made was
 * answered with the catalog block, and a rule "saved" onto it went nowhere.
 */
class FailedSaveResponsesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-failed-saves';
	private int $game_id;
	private string $broken = '';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Failed Saves' ] );
		Schema_Block::create( [
			'slug' => 'fs-merits', 'name' => 'FS Merits', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [ [ 'name' => 'True Faith', 'cost' => '7' ] ] ],
		] );
		Creature_Stack::create( [ 'slug' => 'fs-stack', 'name' => 'FS Stack', 'stack_definition' => [ 'sections' => [] ] ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		remove_filter( 'query', [ $this, 'break_writes' ] );
		parent::tear_down();
	}

	/** Fails every write of one kind to one table, as a lost connection or lock timeout would. */
	public function break_writes( string $query ): string {
		global $wpdb;
		[ $verb, $table ] = explode( ' ', $this->broken . ' ' );
		return $this->broken !== '' && preg_match( "/^\s*{$verb}\s+(INTO\s+)?`?{$wpdb->prefix}be_{$table}`?\s/i", $query )
			? 'UPDATE be_no_such_table SET broken = 1'
			: $query;
	}

	/**
	 * @param string $broken A verb and an unprefixed table, e.g. `UPDATE schema_blocks`.
	 */
	private function send( string $method, string $route, array $body = [], string $broken = '' ): \WP_REST_Response {
		global $wpdb;
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		$this->broken = $broken;
		add_filter( 'query', [ $this, 'break_writes' ] );
		$quiet = $wpdb->suppress_errors( true );
		try {
			return rest_get_server()->dispatch( $request );
		} finally {
			$wpdb->suppress_errors( $quiet );
			remove_filter( 'query', [ $this, 'break_writes' ] );
			$this->broken = '';
		}
	}

	private function rule_on_true_faith( string $broken = '' ): \WP_REST_Response {
		return $this->send( 'POST', "/be/v1/{$this->slug}/approval-rules", [
			'block_slug' => 'fs-merits', 'target_type' => 'item', 'target_name' => 'True Faith', 'approval' => 'st', 'reason' => 'Ask first',
		], $broken );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rules(): array {
		return $this->send( 'GET', "/be/v1/{$this->slug}/approval-rules" )->get_data();
	}

	public function test_an_approval_rule_that_did_not_save_is_not_reported_saved(): void {
		$response = $this->rule_on_true_faith( 'UPDATE schema_blocks' );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( [], $this->rules() );
		$this->assertSame( '', Schema_Block::find_for_game( 'fs-merits', $this->slug )->game_slug, 'and no copy of the block was left behind' );
	}

	public function test_a_rule_change_or_clear_that_did_not_save_is_not_reported_saved(): void {
		$id = $this->rule_on_true_faith()->get_data()['id'];

		$this->assertSame( 500, $this->send( 'PUT', "/be/v1/{$this->slug}/approval-rules/{$id}", [ 'reason' => 'Changed' ], 'UPDATE schema_blocks' )->get_status() );
		$this->assertSame( 500, $this->send( 'DELETE', "/be/v1/{$this->slug}/approval-rules/{$id}", [], 'UPDATE schema_blocks' )->get_status() );

		$rules = $this->rules();
		$this->assertCount( 1, $rules );
		$this->assertSame( 'Ask first', $rules[0]['reason'] );
	}

	public function test_a_copy_of_a_block_that_could_not_be_made_is_not_saved_as_the_catalog(): void {
		$this->assertSame( 500, $this->rule_on_true_faith( 'INSERT schema_blocks' )->get_status() );

		$definition = [ 'items' => [ [ 'name' => 'True Faith', 'cost' => '9' ] ] ];
		$response   = $this->send( 'PUT', "/be/v1/{$this->slug}/schema-blocks/fs-merits", [ 'definition' => $definition ], 'INSERT schema_blocks' );
		$this->assertSame( 500, $response->get_status() );

		$this->assertSame( [], $this->rules() );
		$this->assertSame( '7', Schema_Block::find_for_game( 'fs-merits', $this->slug )->definition->items[0]->cost );
	}

	public function test_a_schema_block_save_that_did_not_land_is_not_reported_saved(): void {
		$response = $this->send( 'PUT', '/be/v1/schema-blocks/fs-merits', [ 'name' => 'Renamed' ], 'UPDATE schema_blocks' );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'FS Merits', Schema_Block::find_by_slug( 'fs-merits' )->name );
	}

	public function test_a_creature_stack_save_that_did_not_land_is_not_reported_saved(): void {
		$response = $this->send( 'PUT', '/be/v1/creature-stacks/fs-stack', [ 'name' => 'Renamed' ], 'UPDATE creature_stacks' );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'FS Stack', Creature_Stack::find_by_slug( 'fs-stack' )->name );
	}

	public function test_a_boon_repayment_that_did_not_land_is_not_reported_repaid(): void {
		$boon = (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'boon', 'name' => 'Minor boon',
			'properties' => [ 'boon_level' => 'minor', 'status' => 'outstanding' ], 'created_by' => 1,
		] );

		$response = $this->send( 'PUT', "/be/v1/{$this->slug}/boons/{$boon}/repay", [], 'UPDATE world_objects' );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'outstanding', World_Object::find( $boon )->properties['status'] );
	}

	public function test_chronicle_ai_assist_settings_that_did_not_save_are_not_reported_saved(): void {
		$response = $this->send( 'PUT', "/be/v1/{$this->slug}/ai-assist/settings", [ 'enabled' => true ], 'UPDATE games' );

		$this->assertSame( 500, $response->get_status() );
		$this->assertEmpty( (array) Game::find_by_slug( $this->slug )->settings );
	}
}
