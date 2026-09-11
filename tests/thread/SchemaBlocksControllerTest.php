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
}
