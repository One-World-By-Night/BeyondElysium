<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The creature stacks routes: the book's creature types are read-only to everyone, and the list reads as the chronicle
 * it names has them.
 */
class CreatureStacksControllerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function dispatch( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_books_creature_types_are_read_only_even_to_an_administrator(): void {
		$before = wp_json_encode( Creature_Stack::find_by_slug( 'vampire' )->stack_definition );

		$created = $this->dispatch( 'POST', '/be/v1/creature-stacks', [
			'slug'             => 'thread-test-stack',
			'name'             => 'Thread Test Stack',
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'vampire-abilities', 'label' => 'Abilities', 'display_order' => 1 ] ] ],
		] );
		$updated = $this->dispatch( 'PUT', '/be/v1/creature-stacks/vampire', [ 'name' => 'Renamed For Everyone', 'stack_definition' => [ 'sections' => [] ] ] );
		$deleted = $this->dispatch( 'DELETE', '/be/v1/creature-stacks/vampire' );

		foreach ( [ $created, $updated, $deleted ] as $response ) {
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'book_read_only', $response->as_error()->get_error_code() );
		}
		$this->assertNull( Creature_Stack::find_by_slug( 'thread-test-stack' ) );
		$this->assertSame( 'Vampire', Creature_Stack::find_by_slug( 'vampire' )->name );
		$this->assertSame( $before, wp_json_encode( Creature_Stack::find_by_slug( 'vampire' )->stack_definition ) );
	}

	public function test_the_list_names_a_chronicles_own_layer_for_that_chronicle_alone(): void {
		Game::create( [ 'slug' => 'thread-stack-list', 'name' => 'Stack List' ] );
		Creature_Stack::hide_section( 'vampire', 'thread-stack-list', 'vampire-blood-magic' );

		$own  = array_column( $this->dispatch( 'GET', '/be/v1/creature-stacks', [ 'game_slug' => 'thread-stack-list', 'per_page' => 100 ] )->get_data(), null, 'slug' );
		$book = array_column( $this->dispatch( 'GET', '/be/v1/creature-stacks', [ 'per_page' => 100 ] )->get_data(), null, 'slug' );

		$this->assertSame( 'thread-stack-list', $own['vampire']->game_slug );
		$this->assertSame( '', $book['vampire']->game_slug );
		$this->assertSame( count( $book ), count( array_filter( $book, static fn( $stack ) => $stack->game_slug === '' ) ) );
	}
}
