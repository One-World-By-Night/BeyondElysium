<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Template;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-069 (Pass H intake `t3-config-controllers-bootstrap`). `GET /templates/{id}`
 * handed any logged-in user any chronicle's own template by counting ids - no membership, and
 * none of the Storyteller-only sections taken out that the sheet's own `resolve` takes out. The
 * template lists were open to every member too. A sheet reaches its template through `resolve`
 * alone; the lists and the id route are the template editor's, and the id route answers for a
 * shared template only.
 */
class TemplateReadScopeThreadTest extends WP_UnitTestCase {

	private int $player;
	private int $storyteller_a;
	private int $storyteller_b;
	private int $fork_a;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_a              = (int) Game::create( [ 'slug' => 'thread-template-a', 'name' => 'Template A' ] );
		$game_b              = (int) Game::create( [ 'slug' => 'thread-template-b', 'name' => 'Template B' ] );
		$this->player        = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->storyteller_a = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->storyteller_b = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_a, $this->player, 'player' );
		Game_Member::set_role( $game_a, $this->storyteller_a, 'hst' );
		Game_Member::set_role( $game_b, $this->storyteller_b, 'hst' );

		$this->fork_a = Template::create( [
			'game_id' => $game_a, 'stack_slug' => 'vampire', 'name' => 'A NPC sheet', 'template_type' => 'npc_full',
			'layout'  => [ 'version' => 1, 'columns' => 3, 'sections' => [ [ 'block_slug' => 'npc-roleplaying-notes', 'column' => 1 ] ] ],
		] );
	}

	private function get_as( int $user, string $route, array $query = [] ) {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'GET', "/be/v1{$route}" );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_cannot_read_templates_directly(): void {
		$this->assertSame( 403, $this->get_as( $this->player, "/templates/{$this->fork_a}" )->get_status() );
		$this->assertSame( 403, $this->get_as( $this->player, '/templates' )->get_status() );
		$this->assertSame( 403, $this->get_as( $this->player, '/thread-template-a/templates' )->get_status() );
	}

	public function test_no_one_reads_a_chronicles_own_template_by_id(): void {
		$this->assertSame( 404, $this->get_as( $this->storyteller_b, "/templates/{$this->fork_a}" )->get_status() );
		$this->assertSame( 404, $this->get_as( $this->storyteller_a, "/templates/{$this->fork_a}" )->get_status(), 'a chronicle lists its own templates on its own route' );
	}

	public function test_the_template_editor_still_lists_shared_and_its_own_templates(): void {
		$this->assertSame( 200, $this->get_as( $this->storyteller_a, '/templates' )->get_status() );
		$own = $this->get_as( $this->storyteller_a, '/thread-template-a/templates' );
		$this->assertSame( 200, $own->get_status() );
		$this->assertContains( $this->fork_a, array_map( 'intval', array_column( (array) $own->get_data(), 'id' ) ) );
		$this->assertSame( 403, $this->get_as( $this->storyteller_b, '/thread-template-a/templates' )->get_status(), 'not another chronicle\'s' );
	}

	public function test_a_player_still_gets_their_sheet_template_through_resolve(): void {
		$response = $this->get_as( $this->player, '/thread-template-a/templates/resolve', [ 'stack_slug' => 'vampire', 'template_type' => 'npc_full' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( 'npc-roleplaying-notes', array_column( $response->get_data()['template']['layout']['sections'], 'block_slug' ) );
	}
}
