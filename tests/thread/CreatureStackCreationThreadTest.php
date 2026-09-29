<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Layout_Generator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle's own brand-new creature type, built from nothing rather than a layer over one the book declares: a
 * real slug, name and sections, reachable for character creation, sheet rendering and deletion like any other.
 */
class CreatureStackCreationThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-stack-creation';
	private int $game_id;
	private int $hst;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Invented Creatures' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
	}

	private function send( string $method, string $route, array $body = [], ?int $as = null ): \WP_REST_Response {
		wp_set_current_user( $as ?? $this->hst );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function create_payload( string $slug = 'thread-invented-type' ): array {
		return [
			'slug'      => $slug,
			'name'      => 'Invented Type',
			'game_line' => 'met',
			'sections'  => [
				[ 'block_slug' => 'met-physical-traits', 'label' => 'Physical Traits' ],
			],
		];
	}

	public function test_the_book_route_refuses_creation_even_for_an_administrator(): void {
		$admin    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$response = $this->send( 'POST', '/be/v1/creature-stacks', $this->create_payload(), $admin );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'book_read_only', $response->as_error()->get_error_code() );
		$this->assertNull( Creature_Stack::find_by_slug( 'thread-invented-type' ) );
	}

	public function test_a_player_cannot_create_a_creature_type(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload(), $this->player );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_hst_creates_a_brand_new_creature_type_for_their_chronicle(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload() );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$created = Creature_Stack::find_for_game( 'thread-invented-type', $this->slug );
		$this->assertSame( $this->slug, $created->game_slug );
		$this->assertSame( 'Invented Type', $created->name );
		$this->assertCount( 1, $created->stack_definition->sections );
		$this->assertSame( 'met-physical-traits', $created->stack_definition->sections[0]->block_slug );

		$this->assertNull( Creature_Stack::find_by_slug( 'thread-invented-type' ), 'no book row exists for an invented type' );
	}

	public function test_a_duplicate_slug_is_refused(): void {
		$this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload() );
		$again = $this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload() );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'duplicate_slug', $again->as_error()->get_error_code() );
	}

	public function test_an_unknown_block_in_a_section_is_refused(): void {
		$payload               = $this->create_payload();
		$payload['sections'][] = [ 'block_slug' => 'not-a-real-block' ];
		$response              = $this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $payload );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unknown_block', $response->as_error()->get_error_code() );
	}

	public function test_it_appears_in_the_chronicles_own_listing_and_can_be_enabled(): void {
		$this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload() );

		$listed = $this->send( 'GET', '/be/v1/creature-stacks', [ 'game_slug' => $this->slug, 'per_page' => 100 ] );
		$slugs  = array_column( $listed->get_data(), 'slug' );
		$this->assertContains( 'thread-invented-type', $slugs );

		$all = Creature_Stack::all_for_game( $this->slug );
		$this->assertContains( 'thread-invented-type', array_column( $all, 'slug' ) );
	}

	public function test_a_character_can_be_created_on_it_and_its_sheet_renders_with_no_authored_template(): void {
		$this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload() );

		$character_id = (int) Character::create( [
			'name'       => 'First of a Kind',
			'owner_slug' => $this->slug,
			'stack_slug' => 'thread-invented-type',
			'created_by' => $this->hst,
		] );
		$this->assertGreaterThan( 0, $character_id );

		$layout = Layout_Generator::generate_for_stack( 'thread-invented-type', $this->slug );
		$this->assertNotNull( $layout, 'a generated layout falls back to the chronicle-owned type, not just the book' );
		$this->assertSame( 'met-physical-traits', $layout['sections'][0]['block_slug'] );

		$response = $this->send( 'GET', "/be/v1/{$this->slug}/templates/resolve", [
			'stack_slug'    => 'thread-invented-type',
			'template_type' => 'sheet_full',
		] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'generated', $response->get_data()['resolved_from'] );
	}

	public function test_sections_can_be_added_and_removed_after_creation(): void {
		$this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload() );

		$added = $this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks/thread-invented-type/sections", [
			'block_slug' => 'met-social-traits', 'label' => 'Social Traits',
		] );
		$this->assertSame( 201, $added->get_status(), wp_json_encode( $added->get_data() ) );
		$this->assertCount( 2, Creature_Stack::find_for_game( 'thread-invented-type', $this->slug )->stack_definition->sections );

		$removed = $this->send( 'DELETE', "/be/v1/{$this->slug}/creature-stacks/thread-invented-type/sections/met-social-traits" );
		$this->assertSame( 200, $removed->get_status(), wp_json_encode( $removed->get_data() ) );
		$this->assertCount( 1, Creature_Stack::find_for_game( 'thread-invented-type', $this->slug )->stack_definition->sections );
	}

	public function test_deleting_is_refused_while_a_character_still_holds_it(): void {
		$this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload() );
		Character::create( [
			'name'       => 'Still Here',
			'owner_slug' => $this->slug,
			'stack_slug' => 'thread-invented-type',
			'created_by' => $this->hst,
		] );

		$response = $this->send( 'DELETE', "/be/v1/{$this->slug}/creature-stacks/thread-invented-type" );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'creature_stack_in_use', $response->as_error()->get_error_code() );
		$this->assertNotNull( Creature_Stack::find_for_game( 'thread-invented-type', $this->slug ) );
	}

	public function test_deleting_succeeds_once_nothing_holds_it(): void {
		$this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks", $this->create_payload() );

		$response = $this->send( 'DELETE', "/be/v1/{$this->slug}/creature-stacks/thread-invented-type" );
		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Creature_Stack::find_for_game( 'thread-invented-type', $this->slug ) );
	}
}
