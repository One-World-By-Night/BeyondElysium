<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Cost_Engine;
use BeyondElysium\Services\Point_Audit;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle's creature type and sheet templates are layers over the book's: a chronicle hides a section or adds one
 * of its own, a hidden section takes no new purchase and keeps every read, and a book change reaches the layer past
 * what the chronicle changed.
 */
class CreatureStackLayerThreadTest extends WP_UnitTestCase {

	private string $slug  = 'thread-layers';
	private string $other = 'thread-layers-other';
	private int $game_id;
	private int $hst;
	private int $player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Layers by Night' ] );
		Game::create( [ 'slug' => $this->other, 'name' => 'Other by Night' ] );

		Schema_Block::create( [
			'slug' => 'tl-merits', 'name' => 'Merits', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [ [ 'name' => 'Iron Will', 'cost' => '3' ], [ 'name' => 'Eidetic Memory', 'cost' => '2' ] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'tl-magic', 'name' => 'Magic', 'section_type' => 'tiered_power', 'is_system' => 0,
			'definition' => [
				'default_held' => [ [ 'name' => 'Path of Flame', 'level' => 1 ] ],
				'powers'       => [
					[ 'name' => 'Path of Flame', 'levels' => self::ladder( 'Flame' ) ],
					[ 'name' => 'Path of Frost', 'levels' => self::ladder( 'Frost' ) ],
				],
			],
		] );
		Schema_Block::create( [
			'slug' => 'tl-identity', 'name' => 'Identity', 'section_type' => 'identity_field', 'is_system' => 0,
			'definition' => [
				'fields'           => [ [ 'name' => 'Clan', 'type' => 'select', 'options' => [ 'Ember', 'Rime' ] ] ],
				'clan_disciplines' => [ 'Ember' => [ 'Path of Flame' ], 'Rime' => [ 'Path of Frost' ] ],
			],
		] );
		Schema_Block::create( [
			'slug' => 'tl-pools', 'name' => 'Resources', 'section_type' => 'resource_pool', 'is_system' => 0,
			'definition' => [ 'pools' => [ [ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 2, 'max' => 10, 'cost_per_dot' => 3 ] ] ],
		] );
		Creature_Stack::create( [
			'slug' => 'tl-stack', 'name' => 'Layered Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [
				'sections' => [
					[ 'block_slug' => 'tl-merits', 'label' => 'Merits', 'display_order' => 10 ],
					[ 'block_slug' => 'tl-magic', 'label' => 'Magic', 'display_order' => 20, 'in_type' => [ [ 'kind' => 'names', 'values' => [ 'map' => 'tl-identity.clan_disciplines', 'by' => [ 'Clan' ] ] ] ] ],
					[ 'block_slug' => 'tl-pools', 'label' => 'Resources', 'display_order' => 30 ],
				],
			],
		] );
		foreach ( [ 'sheet_full', 'npc_full' ] as $type ) {
			Template::create( [
				'stack_slug' => 'tl-stack', 'name' => 'Layered Sheet', 'template_type' => $type, 'is_system' => 1, 'created_by' => 0,
				'layout'     => [ 'version' => 1, 'columns' => 6, 'sections' => [
					self::template_section( 'tl-merits', 1 ),
					self::template_section( 'tl-magic', 2 ),
					self::template_section( 'tl-pools', 3 ),
				] ],
			] );
		}

		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->character = (int) Character::create( [
			'name' => 'Layered Tester', 'stack_slug' => 'tl-stack', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
			'sheet_data' => [
				'tl-identity' => [ 'Clan' => 'Ember' ],
				'tl-merits' => [ [ 'name' => 'Iron Will', 'count' => 1 ] ],
				'tl-magic'  => [ [ 'name' => 'Path of Flame', 'level' => 2 ] ],
				'tl-pools'  => [ 'Willpower' => [ 'permanent' => 4, 'temporary' => 4 ] ],
			],
		] );
		Character::update_xp( $this->character, 60, 60 );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function ladder( string $element ): array {
		$levels = [];
		foreach ( [ 1, 2, 3 ] as $level ) {
			$levels[] = [ 'level' => $level, 'tier' => 'basic', 'power_name' => "{$element} {$level}", 'cost' => '3' ];
		}
		return $levels;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function template_section( string $block_slug, int $order ): array {
		return [ 'block_slug' => $block_slug, 'column' => 1, 'order' => $order, 'title' => null, 'display' => null, 'collapsed' => false, 'width' => 'full' ];
	}

	/**
	 * @return array<string,object>
	 */
	private static function sections_by_block( ?object $stack ): array {
		$sections = [];
		foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
			$sections[ $section->block_slug ] = $section;
		}
		return $sections;
	}

	private function send( int $user, string $method, string $route, array $body = [] ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function submit( int $user, string $type, array $data ): \WP_REST_Response {
		return $this->send( $user, 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes", [
			'change_type' => $type, 'category' => 'test', 'change_data' => $data,
		] );
	}

	private function layer_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_creature_stacks WHERE slug = 'tl-stack' AND game_slug <> ''" );
	}

	/**
	 * @return string[]
	 */
	private function template_blocks( string $type, int $game_id ): array {
		return array_column( Template::resolve( 'tl-stack', $type, $game_id )->layout['sections'], 'block_slug' );
	}

	public function test_a_chronicles_layer_is_what_it_resolves_and_no_other_chronicle_sees_it(): void {
		$listed = count( Creature_Stack::all() );

		$this->assertTrue( Creature_Stack::hide_section( 'tl-stack', $this->slug, 'tl-magic' ) );

		$own = Creature_Stack::resolve( 'tl-stack', $this->slug );
		$this->assertTrue( self::sections_by_block( $own['stack'] )['tl-magic']->hidden, 'hidden in its own chronicle' );
		$this->assertArrayHasKey( 'tl-magic', $own['blocks'], 'every read still has the block' );
		$this->assertSame( [ 'tl-magic' ], Creature_Stack::closed_blocks( $own['stack'] ) );

		$this->assertObjectNotHasProperty( 'hidden', self::sections_by_block( Creature_Stack::resolve( 'tl-stack', $this->other )['stack'] )['tl-magic'] );
		$this->assertObjectNotHasProperty( 'hidden', self::sections_by_block( Creature_Stack::find_by_slug( 'tl-stack' ) )['tl-magic'] );
		$this->assertSame( $listed, count( Creature_Stack::all() ), 'a layer is not a creature type of its own' );
		$for_game = array_column( Creature_Stack::all_for_game( $this->slug ), null, 'slug' );
		$this->assertSame( $this->slug, $for_game['tl-stack']->game_slug );
	}

	public function test_a_book_change_reaches_the_layer_and_the_chronicles_change_stays(): void {
		Creature_Stack::hide_section( 'tl-stack', $this->slug, 'tl-magic' );

		$book = json_decode( (string) wp_json_encode( Creature_Stack::find_by_slug( 'tl-stack' )->stack_definition ), true );
		$book['sections'][0]['label'] = 'Merits and Boons';
		$book['sections'][]           = [ 'block_slug' => 'tl-oaths-book', 'label' => 'Oaths', 'display_order' => 40 ];
		Schema_Block::create( [ 'slug' => 'tl-oaths-book', 'name' => 'Oaths', 'section_type' => 'trait_list', 'is_system' => 0, 'definition' => [ 'items' => [] ] ] );
		Creature_Stack::update( 'tl-stack', [ 'stack_definition' => $book ] );
		$this->assertSame( 1, Creature_Stack::refresh_layers( 'tl-stack' ) );

		$sections = self::sections_by_block( Creature_Stack::find_for_game( 'tl-stack', $this->slug ) );
		$this->assertTrue( $sections['tl-magic']->hidden, "the chronicle's change" );
		$this->assertSame( 'Merits and Boons', $sections['tl-merits']->label, 'the book correction' );
		$this->assertArrayHasKey( 'tl-oaths-book', $sections, 'the section the book added' );
	}

	public function test_a_hidden_section_takes_no_new_purchase_and_keeps_every_read(): void {
		Creature_Stack::hide_section( 'tl-stack', $this->slug, 'tl-magic' );

		$add = $this->submit( $this->hst, 'add_trait', [ 'block_slug' => 'tl-magic', 'trait' => [ 'name' => 'Path of Frost', 'level' => 1 ] ] );
		$this->assertSame( 400, $add->get_status() );
		$this->assertSame( 'section_hidden', $add->as_error()->get_error_code() );
		$this->assertStringContainsString( 'Magic is hidden in this chronicle', $add->as_error()->get_error_message() );

		$raise = $this->submit( $this->hst, 'modify_trait', [ 'block_slug' => 'tl-magic', 'trait' => [ 'name' => 'Path of Flame', 'level' => 3 ] ] );
		$this->assertSame( 'section_hidden', $raise->as_error()->get_error_code(), 'a higher level is a purchase too' );

		$preview = $this->send( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/preview-changes", [
			'changes' => [ [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'tl-magic', 'trait' => [ 'name' => 'Path of Frost', 'level' => 1 ] ] ] ],
		] );
		$this->assertSame( 'section_hidden', $preview->get_data()['results'][0]['error']['code'] ?? null, 'the editor hears why before it submits' );

		$other = $this->submit( $this->hst, 'add_trait', [ 'block_slug' => 'tl-merits', 'trait' => [ 'name' => 'Eidetic Memory', 'count' => 1 ] ] );
		$this->assertSame( 201, $other->get_status(), 'a shown section still sells' );

		$this->assertSame( [ [ 'name' => 'Path of Flame', 'level' => 2 ] ], Character::find( $this->character )->sheet_data['tl-magic'] );
		$read = $this->send( $this->player, 'GET', "/be/v1/{$this->slug}/characters/{$this->character}" );
		$this->assertSame( 'Path of Flame', $read->get_data()->sheet_data['tl-magic'][0]['name'] ?? null );
		$audited = array_column( Point_Audit::for_character( $this->character )['lines'] ?? [], 'block_slug' );
		$this->assertContains( 'tl-magic', $audited, 'the audit still prices what is held there' );

		$lower = $this->submit( $this->hst, 'modify_trait', [ 'block_slug' => 'tl-magic', 'trait' => [ 'name' => 'Path of Flame', 'level' => 1 ] ] );
		$this->assertSame( 201, $lower->get_status(), 'lowering buys nothing' );
	}

	public function test_a_pending_purchase_in_a_section_hidden_since_is_still_approved(): void {
		$pending = $this->submit( $this->player, 'add_trait', [ 'block_slug' => 'tl-magic', 'trait' => [ 'name' => 'Path of Frost', 'level' => 1 ] ] );
		$this->assertSame( 201, $pending->get_status() );
		$change_id = (int) $pending->get_data()->id;
		$this->assertSame( 'pending', Change::find( $change_id )->status );

		Creature_Stack::hide_section( 'tl-stack', $this->slug, 'tl-magic' );

		$approved = $this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [ 'status' => 'approved' ] );
		$this->assertSame( 200, $approved->get_status(), wp_json_encode( $approved->get_data() ) );
		$this->assertContains( 'Path of Frost', array_column( Character::find( $this->character )->sheet_data['tl-magic'], 'name' ) );
	}

	public function test_a_section_shown_again_sells_and_leaves_no_layer(): void {
		Creature_Stack::hide_section( 'tl-stack', $this->slug, 'tl-magic' );
		$this->assertSame( 1, $this->layer_count() );

		$this->assertTrue( Creature_Stack::show_section( 'tl-stack', $this->slug, 'tl-magic' ) );

		$this->assertSame( 0, $this->layer_count(), 'nothing left changed' );
		$this->assertSame( 201, $this->submit( $this->hst, 'add_trait', [ 'block_slug' => 'tl-magic', 'trait' => [ 'name' => 'Path of Frost', 'level' => 1 ] ] )->get_status() );
	}

	public function test_a_new_character_takes_nothing_in_a_hidden_section(): void {
		Creature_Stack::hide_section( 'tl-stack', $this->slug, 'tl-magic' );

		$created = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/characters", [
			'name'       => 'New Layered',
			'stack_slug' => 'tl-stack',
			'sheet_data' => [ 'tl-magic' => [ [ 'name' => 'Path of Frost', 'level' => 1 ] ] ],
		] );
		$this->assertSame( 400, $created->get_status() );
		$this->assertSame( 'section_hidden', $created->as_error()->get_error_code() );

		$plain = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Plain Layered', 'stack_slug' => 'tl-stack' ] );
		$this->assertSame( 201, $plain->get_status(), wp_json_encode( $plain->get_data() ) );
		$this->assertArrayNotHasKey( 'tl-magic', Character::find( (int) $plain->get_data()->id )->sheet_data, 'no starting entry in a hidden section' );
		Game_Member::set_role( (int) Game::find_by_slug( $this->other )->id, $this->hst, 'hst' );
		$elsewhere = $this->send( $this->hst, 'POST', "/be/v1/{$this->other}/characters", [ 'name' => 'Plain Elsewhere', 'stack_slug' => 'tl-stack' ] );
		$this->assertSame( [ [ 'name' => 'Path of Flame', 'level' => 1 ] ], Character::find( (int) $elsewhere->get_data()->id )->sheet_data['tl-magic'] ?? null );

		$form = $this->send( $this->hst, 'GET', '/be/v1/creature-stacks/tl-stack', [ 'resolve' => 1, 'game_slug' => $this->slug, 'for_creation' => 1 ] );
		$data = json_decode( (string) wp_json_encode( $form->get_data() ), true );
		$this->assertSame( [ 'tl-merits', 'tl-pools' ], array_column( $data['stack']['stack_definition']['sections'], 'block_slug' ) );
		$this->assertArrayNotHasKey( 'tl-magic', $data['blocks'] );
	}

	public function test_a_chronicles_own_block_joins_its_creature_type_and_sheets(): void {
		Schema_Block::create( [
			'slug' => 'tl-oaths', 'game_slug' => $this->slug, 'name' => 'Oaths', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [ [ 'name' => 'Oath of Fealty', 'cost' => '2' ] ] ],
		] );

		$this->assertFalse( Creature_Stack::add_section( 'tl-stack', $this->other, 'tl-oaths' ), 'another chronicle cannot read the block' );
		$this->assertTrue( Creature_Stack::add_section( 'tl-stack', $this->slug, 'tl-oaths', 'Oaths' ) );
		$this->assertFalse( Creature_Stack::add_section( 'tl-stack', $this->slug, 'tl-oaths' ), 'once' );

		$own = Creature_Stack::resolve( 'tl-stack', $this->slug );
		$this->assertArrayHasKey( 'tl-oaths', self::sections_by_block( $own['stack'] ) );
		$this->assertArrayHasKey( 'tl-oaths', $own['blocks'] );
		$this->assertContains( 'tl-oaths', $this->template_blocks( 'sheet_full', $this->game_id ) );
		$this->assertContains( 'tl-oaths', $this->template_blocks( 'npc_full', $this->game_id ) );
		Template::refresh_layers( 'tl-stack' );
		$this->assertContains( 'tl-oaths', $this->template_blocks( 'sheet_full', $this->game_id ), 'a site template rebuild keeps it' );
		$other_game = (int) Game::find_by_slug( $this->other )->id;
		$this->assertNotContains( 'tl-oaths', $this->template_blocks( 'sheet_full', $other_game ) );
		$this->assertArrayNotHasKey( 'tl-oaths', self::sections_by_block( Creature_Stack::resolve( 'tl-stack', $this->other )['stack'] ) );

		$bought = $this->submit( $this->hst, 'add_trait', [ 'block_slug' => 'tl-oaths', 'trait' => [ 'name' => 'Oath of Fealty', 'count' => 1 ] ] );
		$this->assertSame( 201, $bought->get_status(), wp_json_encode( $bought->get_data() ) );
		$this->assertSame( 200, $this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/changes/{$bought->get_data()->id}", [ 'status' => 'approved' ] )->get_status() );
		$this->assertContains( 'tl-oaths', array_column( Point_Audit::for_character( $this->character )['lines'] ?? [], 'block_slug' ) );

		$this->assertFalse( Creature_Stack::remove_section( 'tl-stack', $this->slug, 'tl-magic' ), "the book's sections are hidden, not removed" );
		$this->assertTrue( Creature_Stack::remove_section( 'tl-stack', $this->slug, 'tl-oaths' ) );
		$this->assertArrayNotHasKey( 'tl-oaths', self::sections_by_block( Creature_Stack::find_for_game( 'tl-stack', $this->slug ) ) );
		$this->assertNotContains( 'tl-oaths', $this->template_blocks( 'sheet_full', $this->game_id ) );
	}

	public function test_a_site_template_change_reaches_a_chronicles_template_past_its_own_changes(): void {
		$site = Template::resolve( 'tl-stack', 'sheet_full', null );
		$mine = $site->layout;
		array_splice( $mine['sections'], 2, 1 );
		$mine['sections'][0]['column'] = 2;
		$own_id = Template::create( [ 'game_id' => $this->game_id, 'stack_slug' => 'tl-stack', 'name' => 'Our Sheet', 'template_type' => 'sheet_full', 'layout' => $mine ] );
		$this->assertGreaterThan( 0, $own_id );

		$layout               = $site->layout;
		$layout['sections'][] = self::template_section( 'tl-oaths-site', 4 );
		Schema_Block::create( [ 'slug' => 'tl-oaths-site', 'name' => 'Oaths', 'section_type' => 'trait_list', 'is_system' => 0, 'definition' => [ 'items' => [] ] ] );
		$this->assertTrue( Template::update( (int) $site->id, [ 'layout' => $layout ] ) );
		Template::refresh_layers( 'tl-stack', 'sheet_full' );

		$sections = array_column( Template::find( $own_id )->layout['sections'], null, 'block_slug' );
		$this->assertArrayHasKey( 'tl-oaths-site', $sections, 'the section the site template gained' );
		$this->assertArrayNotHasKey( 'tl-pools', $sections, 'the section the chronicle left out stays out' );
		$this->assertSame( 2, $sections['tl-merits']['column'], "the chronicle's own placement" );
	}

	public function test_the_upgrade_records_a_chronicle_template_kept_before_changes_were_recorded(): void {
		global $wpdb;
		$site = Template::resolve( 'tl-stack', 'sheet_full', null );
		$mine = $site->layout;
		array_splice( $mine['sections'], 2, 1 );
		$wpdb->insert( $wpdb->prefix . 'be_templates', [
			'game_id' => $this->game_id, 'stack_slug' => 'tl-stack', 'name' => 'Old Sheet', 'template_type' => 'sheet_full',
			'layout' => wp_json_encode( $mine ), 'is_system' => 0, 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$own_id = (int) $wpdb->insert_id;

		Schema::record_template_changes();

		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT fork_changes FROM {$wpdb->prefix}be_templates WHERE id = %d", $own_id ) ) );
		$layout               = $site->layout;
		$layout['sections'][] = self::template_section( 'tl-oaths-upgrade', 4 );
		Schema_Block::create( [ 'slug' => 'tl-oaths-upgrade', 'name' => 'Oaths', 'section_type' => 'trait_list', 'is_system' => 0, 'definition' => [ 'items' => [] ] ] );
		Template::update( (int) $site->id, [ 'layout' => $layout ] );
		Template::refresh_layers();

		$blocks = array_column( Template::find( $own_id )->layout['sections'], 'block_slug' );
		$this->assertSame( [ 'tl-merits', 'tl-magic', 'tl-oaths-upgrade' ], $blocks );
	}

	public function test_renaming_or_deleting_a_chronicle_takes_its_creature_type_layer_along(): void {
		Creature_Stack::hide_section( 'tl-stack', $this->slug, 'tl-magic' );
		$this->assertSame( 1, Game::content_counts( Game::find_by_slug( $this->slug ) )['creature_stacks'] );

		$renamed = Game::rename( $this->game_id, 'thread-layers-renamed' );
		$this->assertTrue( $renamed['changed'] );
		$this->assertSame( 1, $renamed['creature_stacks'] );
		$this->assertTrue( self::sections_by_block( Creature_Stack::find_for_game( 'tl-stack', 'thread-layers-renamed' ) )['tl-magic']->hidden );

		$this->assertTrue( Game::delete_with_content( 'thread-layers-renamed' ) );
		$this->assertSame( 0, $this->layer_count() );
	}

	public function test_pricing_reads_the_chronicles_creature_type(): void {
		$character = Character::find( $this->character );
		$this->assertFalse( Cost_Engine::is_in_type( $character, 'tl-magic', 'Path of Frost' ), 'out of type in the book' );

		$definition = json_decode( (string) wp_json_encode( Creature_Stack::find_by_slug( 'tl-stack' )->stack_definition ), true );
		unset( $definition['sections'][1]['in_type'] );
		$this->assertTrue( Creature_Stack::update_for_game( 'tl-stack', $this->slug, [ 'stack_definition' => $definition ] ) );

		$this->assertTrue( Cost_Engine::is_in_type( Character::find( $this->character ), 'tl-magic', 'Path of Frost' ), "in type under the chronicle's own rule" );
	}
}
