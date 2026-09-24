<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Services\Query_Engine;
use BeyondElysium\Services\Trait_Mapper;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * C7: the four real consumers §3.4 names, each on a declared install, one scenario per
 * the design doc's own words:
 *
 * - Trait_Mapper::classify_list() - "the GEX importer lands 'Abilities' in vampire-abilities"
 * - Query_Engine, case 'json' - "the abilities query field matches"
 * - Changes_Controller::create_item() - "a stale met-abilities submission lands in vampire-abilities"
 * - Change_Engine::apply_to_sheet() - "a pending met-merits change approved after the flip
 *   lands in vampire-merits" (the race: submitted legacy, approved declared)
 */
class CatalogCutoverLiveSlugConsumersThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'cutover-live-slug-consumers';

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		do_action( 'rest_api_init' );
		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Cutover Live Slug Consumers' ] );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A real cutover is more than the option: `apply()` (R5) sets `OPTION` and then
	 * reseeds the declared stacks in the same step (§3.6). Setting only the option leaves
	 * `Creature_Stack::resolve()` still returning the stored, pre-cutover row - realistic
	 * for `Trait_Mapper`/`Change_Engine::apply_to_sheet()` (neither reads a stack's stored
	 * sections), wrong for anything that validates against them.
	 */
	private function declare_cutover(): void {
		update_option( Catalog_Cutover::OPTION, 'declared' );
		Catalog_Cutover::reset_cache();
		Seeder::seed_creature_stacks();
	}

	public function test_trait_mapper_lands_the_gex_import_in_the_live_block(): void {
		$this->declare_cutover();

		$this->assertSame(
			'vampire-abilities',
			Trait_Mapper::classify_list( 'vampire', 'Abilities' )['block_slug']
		);
	}

	public function test_query_engine_abilities_field_matches_data_under_the_live_block(): void {
		$this->declare_cutover();

		Character::create( [
			'name' => 'Post-Cutover Scholar', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'status' => 'active',
			'sheet_data' => [ 'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ] ],
		] );

		$result = Query_Engine::execute(
			$this->game_slug,
			[ [ 'field' => 'abilities', 'operator' => 'contains', 'find' => 'Occult', 'value' => 'Occult' ] ],
			'AND'
		);

		$this->assertSame( [ 'Post-Cutover Scholar' ], array_map( static fn( $row ) => $row->name, $result['results'] ) );
	}

	public function test_changes_controller_create_item_remaps_a_stale_submission(): void {
		$this->declare_cutover();

		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character_id = Character::create( [
			'name' => 'Stale Client Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $character_id, 10, 10 );

		wp_set_current_user( $player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$character_id}/changes" );
		$request->set_param( 'change_type', 'add_trait' );
		$request->set_param( 'category', 'met-abilities' );
		// A browser tab loaded before the cutover still submits the slug it started with.
		$request->set_param( 'change_data', [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 1 ] ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$change = Change::find( (int) $response->get_data()->id );
		$this->assertSame( 'vampire-abilities', $change->change_data['block_slug'] );
	}

	public function test_change_engine_apply_to_sheet_remaps_a_pending_change_approved_after_the_flip(): void {
		// Submitted while legacy - met-merits is a real section on the (not yet cut over) stack.
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$st     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$character_id = Character::create( [
			'name' => 'Race Condition Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $character_id, 10, 10 );

		wp_set_current_user( $player );
		$submit = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$character_id}/changes" );
		$submit->set_param( 'change_type', 'add_trait' );
		$submit->set_param( 'category', 'met-merits' );
		$submit->set_param( 'change_data', [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ] );
		$change_id = (int) $this->dispatch( $submit )->get_data()->id;
		$this->assertSame( 'met-merits', Change::find( $change_id )->change_data['block_slug'], 'precondition: stored as submitted, while legacy' );

		// The install is cut over before anyone reviews it.
		$this->declare_cutover();

		wp_set_current_user( $st );
		$approve = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$approve->set_param( 'status', 'approved' );
		$this->assertSame( 200, $this->dispatch( $approve )->get_status() );

		$character = Character::find( $character_id );
		$this->assertArrayHasKey( 'vampire-merits', $character->sheet_data );
		$this->assertSame( 'Iron Will', $character->sheet_data['vampire-merits'][0]['name'] );
		$this->assertArrayNotHasKey( 'met-merits', $character->sheet_data );
	}
}
