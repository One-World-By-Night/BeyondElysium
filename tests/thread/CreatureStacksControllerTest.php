<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * No test file for this controller existed before this one (2026-09-09) - same real,
 * significant gap as SchemaBlocksControllerTest: `POST /creature-stacks` (creating any
 * brand-new custom creature stack) has never worked, for the entire life of this project.
 * `validate_stack_definition()` checked `is_object( $definition )`, but a real JSON REST
 * request body always decodes to a plain PHP array - the check could never pass for any
 * genuine request. Found the same session, same root cause, building the admin UI.
 */
class CreatureStacksControllerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_creating_a_creature_stack_with_a_real_json_body_succeeds(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/creature-stacks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'             => 'thread-test-stack',
			'name'             => 'Thread Test Stack',
			'game_line'        => 'met',
			'stack_definition' => [
				'sections' => [
					[ 'block_slug' => 'met-abilities', 'label' => 'Abilities', 'display_order' => 1, 'required' => true ],
				],
			],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'thread-test-stack', $data->slug );
		$this->assertSame( 'met-abilities', $data->stack_definition->sections[0]->block_slug );
	}

	public function test_a_section_missing_block_slug_is_rejected(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/creature-stacks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'             => 'thread-test-bad-stack',
			'name'             => 'Thread Test Bad Stack',
			'stack_definition' => [ 'sections' => [ [ 'label' => 'No block slug', 'display_order' => 1 ] ] ],
		] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_stack_definition_missing_sections_is_rejected(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/creature-stacks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'slug'             => 'thread-test-no-sections',
			'name'             => 'Thread Test No Sections',
			'stack_definition' => [ 'not_sections' => [] ],
		] ) );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_updating_a_stacks_definition_with_a_real_json_body_succeeds(): void {
		$create = new WP_REST_Request( 'POST', '/be/v1/creature-stacks' );
		$create->set_header( 'Content-Type', 'application/json' );
		$create->set_body( wp_json_encode( [
			'slug'             => 'thread-test-update-stack',
			'name'             => 'Thread Test Update Stack',
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'met-abilities', 'label' => 'Abilities', 'display_order' => 1 ] ] ],
		] ) );
		$this->dispatch( $create );

		$update = new WP_REST_Request( 'PUT', '/be/v1/creature-stacks/thread-test-update-stack' );
		$update->set_header( 'Content-Type', 'application/json' );
		$update->set_body( wp_json_encode( [
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'met-merits', 'label' => 'Merits', 'display_order' => 1 ] ] ],
		] ) );
		$response = $this->dispatch( $update );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'met-merits', $response->get_data()->stack_definition->sections[0]->block_slug );
	}
}
