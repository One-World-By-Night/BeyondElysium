<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * No test file for this controller existed before this one (2026-09-09) - a real,
 * significant gap: `POST /schema-blocks` (creating any brand-new custom block) has never
 * worked, for the entire life of this project. `validate_definition()` checked
 * `is_object( $definition )`, but a real JSON REST request body always decodes to a plain
 * PHP array (confirmed live, `WP_REST_Request::get_param()`), never a stdClass - so the
 * check could never pass for any genuine request, only for a value constructed directly
 * in PHP. Found building the admin UI's structured definition editor, which finally
 * exercised this path against a real POST body for the first time.
 */
class SchemaBlocksControllerTest extends WP_UnitTestCase {

	public int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_creating_a_trait_list_block_with_a_real_json_body_succeeds(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-trait-block',
			'name'         => 'Thread Test Trait Block',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Iron Will', 'cost' => '5' ] ] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'thread-test-trait-block', $data->slug );
		$this->assertSame( 'Iron Will', $data->definition->items[0]->name );
	}

	public function test_creating_a_tiered_power_block_with_a_real_json_body_succeeds(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-power-block',
			'name'         => 'Thread Test Power Block',
			'section_type' => 'tiered_power',
			'definition'   => [ 'powers' => [ [ 'name' => 'Celerity', 'levels' => [] ] ] ],
		] ) );

		$this->assertSame( 201, $this->dispatch( $request )->get_status() );
	}

	public function test_a_definition_missing_the_required_key_is_rejected(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-bad-block',
			'name'         => 'Thread Test Bad Block',
			'section_type' => 'trait_list',
			'definition'   => [ 'not_items' => [] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_updating_a_blocks_definition_with_a_real_json_body_succeeds(): void {
		$create = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$create->set_header( 'Content-Type', 'application/json' );
		$create->set_body( wp_json_encode( [
			'slug'         => 'thread-test-update-block',
			'name'         => 'Thread Test Update Block',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [] ],
		] ) );
		$this->dispatch( $create );

		$update = new WP_REST_Request( 'PUT', '/be/v1/schema-blocks/thread-test-update-block' );
		$update->set_header( 'Content-Type', 'application/json' );
		$update->set_body( wp_json_encode( [ 'definition' => [ 'items' => [ [ 'name' => 'Danger Sense' ] ] ] ] ) );
		$response = $this->dispatch( $update );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Danger Sense', $response->get_data()->definition->items[0]->name );
	}

	// -------------------------------------------------------------------------
	// description sanitization (Rich_Text_Sanitizer) - a global-admin-editable,
	// never-imported/exported free-text field for a house rule or page
	// reference; formatting/lists/tables survive, images and scripts don't.
	// -------------------------------------------------------------------------

	public function test_creating_a_trait_list_block_sanitizes_item_descriptions(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-description-create',
			'name'         => 'Thread Test Description Create',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [
				'name'        => 'Occult',
				'description' => [
					'reference'   => '<p>Book, p.42</p>',
					'description' => '<p>House rule</p><img src="x.png"><script>alert(1)</script>',
				],
			] ] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$description = $response->get_data()->definition->items[0]->description;
		$this->assertSame( '<p>Book, p.42</p>', $description->reference );
		$this->assertSame( '<p>House rule</p>', $description->description );
		$this->assertFalse( property_exists( $description, 'source' ) );
	}

	public function test_updating_a_trait_list_items_description_is_sanitized(): void {
		$create = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$create->set_header( 'Content-Type', 'application/json' );
		$create->set_body( wp_json_encode( [
			'slug'         => 'thread-test-description-update',
			'name'         => 'Thread Test Description Update',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Occult' ] ] ],
		] ) );
		$this->dispatch( $create );

		$update = new WP_REST_Request( 'PUT', '/be/v1/schema-blocks/thread-test-description-update' );
		$update->set_header( 'Content-Type', 'application/json' );
		$update->set_body( wp_json_encode( [
			'definition' => [ 'items' => [ [
				'name'        => 'Occult',
				'description' => [ 'source' => '<table><tr><td>Fine</td></tr></table><iframe src="x"></iframe>' ],
			] ] ],
		] ) );
		$response = $this->dispatch( $update );

		$this->assertSame( 200, $response->get_status() );
		$description = $response->get_data()->definition->items[0]->description;
		$this->assertSame( '<table><tr><td>Fine</td></tr></table>', $description->source );
	}

	public function test_a_tiered_power_familys_and_levels_description_are_both_sanitized(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-description-tiered',
			'name'         => 'Thread Test Description Tiered',
			'section_type' => 'tiered_power',
			'definition'   => [ 'powers' => [ [
				'name'        => 'Celerity',
				'description' => [ 'description' => '<p>Family note</p><script>bad()</script>' ],
				'levels'      => [
					[ 'level' => 1, 'power_name' => 'Alacrity', 'description' => [ 'reference' => '<ul><li>Level note</li></ul><img src="x.png">' ] ],
				],
			] ] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$power = $response->get_data()->definition->powers[0];
		$this->assertSame( '<p>Family note</p>', $power->description->description );
		$this->assertSame( '<ul><li>Level note</li></ul>', $power->levels[0]->description->reference );
	}

	// -------------------------------------------------------------------------
	// per-value/per-level approval schedules round-trip through the REST layer
	// unchanged - Change_Engine.php is what interprets them, this controller
	// only persists whatever shape is submitted.
	// -------------------------------------------------------------------------

	public function test_a_trait_list_items_approval_by_value_round_trips(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-approval-by-value',
			'name'         => 'Thread Test Approval By Value',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [
				'name'              => 'Occult',
				'approval_by_value' => [
					[ 'from' => 1, 'to' => 3, 'approval' => 'auto' ],
					[ 'from' => 4, 'to' => 5, 'approval' => 'coordinator', 'reason' => 'Occult 4+ needs review.' ],
				],
			] ] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$ranges = $response->get_data()->definition->items[0]->approval_by_value;
		$this->assertCount( 2, $ranges );
		$this->assertSame( 'auto', $ranges[0]->approval );
		$this->assertSame( 'coordinator', $ranges[1]->approval );
		$this->assertSame( 'Occult 4+ needs review.', $ranges[1]->reason );
	}

	public function test_a_tiered_power_levels_approval_round_trips(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-approval-tiered-level',
			'name'         => 'Thread Test Approval Tiered Level',
			'section_type' => 'tiered_power',
			'definition'   => [ 'powers' => [ [
				'name'   => 'Celerity',
				'levels' => [ [ 'level' => 4, 'power_name' => 'Fleetness', 'approval' => 'coordinator' ] ],
			] ] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'coordinator', $response->get_data()->definition->powers[0]->levels[0]->approval );
	}

	public function test_a_resource_pools_approval_by_value_round_trips(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-approval-resource',
			'name'         => 'Thread Test Approval Resource',
			'section_type' => 'resource_pool',
			'definition'   => [ 'pools' => [ [
				'name'              => 'Willpower',
				'value_type'        => 'integer',
				'default_start'     => 1,
				'approval_by_value' => [ [ 'from' => 8, 'to' => 10, 'approval' => 'coordinator' ] ],
			] ] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'coordinator', $response->get_data()->definition->pools[0]->approval_by_value[0]->approval );
	}

	public function test_an_identity_fields_approval_by_option_round_trips(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'         => 'thread-test-approval-identity',
			'name'         => 'Thread Test Approval Identity',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [
				'name'               => 'Generation',
				'field_type'         => 'select',
				'required'           => false,
				'options'            => [ 'Neonate', 'Antediluvian' ],
				'approval_by_option' => [ 'Antediluvian' => [ 'approval' => 'coordinator', 'reason' => 'Needs review.' ] ],
			] ] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$schedule = (array) $response->get_data()->definition->fields[0]->approval_by_option;
		$this->assertSame( 'coordinator', $schedule['Antediluvian']->approval );
		$this->assertSame( 'Needs review.', $schedule['Antediluvian']->reason );
	}
}
