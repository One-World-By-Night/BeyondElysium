<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Cost_Engine;
use BeyondElysium\Services\In_Type;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Catalog screen's routes: which sections take entries, and every kind of entry an HST adds, changes, files and
 * takes away on the chronicle's own copy of a block, with the book and another chronicle left alone.
 */
class CatalogScreenThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-catalog-screen';
	private string $other = 'thread-catalog-screen-other';
	private int $game_id;
	private int $hst;
	private int $ast;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Catalog Screen by Night' ] );
		Game::create( [ 'slug' => $this->other, 'name' => 'Another Chronicle' ] );

		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->ast = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		Game_Member::set_role( $this->game_id, $this->ast, 'ast' );
		wp_set_current_user( $this->hst );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function call( string $method, string $route, array $params = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}/catalog{$route}" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function entry( string $block, string $name, string $stack = 'vampire' ): array {
		$listing = $this->call( 'GET', "/{$block}", [ 'stack' => $stack ] )->get_data();
		foreach ( $listing['entries'] as $entry ) {
			if ( 0 === strcasecmp( $entry['name'], $name ) ) {
				return $entry;
			}
		}
		$this->fail( "{$name} is not in {$block}" );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function copy( string $block, ?string $game = null ): array {
		$copy = Schema_Block::copy_for_game( $block, $game ?? $this->slug );
		$this->assertNotNull( $copy, "the chronicle has its own copy of {$block}" );
		return $copy;
	}

	private function holds( array $definition, string $key, string $name ): ?array {
		foreach ( $definition[ $key ] ?? [] as $each ) {
			if ( 0 === strcasecmp( (string) $each['name'], $name ) ) {
				return $each;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private function add( string $block, array $fields ): array {
		$response = $this->call( 'POST', "/{$block}/entries", $fields );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['entry'];
	}

	/**
	 * @param array<string,mixed> $changes
	 */
	private function change( string $block, array $entry, array $changes, string $stack = 'vampire' ) {
		return $this->call( 'PUT', "/{$block}/entries", [
			'name'    => $entry['name'],
			'etag'    => $entry['etag'],
			'changes' => $changes,
			'stack'   => $stack,
		] );
	}

	private function price_for( string $block, string $name, string $stack = 'vampire' ): ?int {
		$character = Character::find( Character::create( [
			'name' => 'Quote Subject ' . wp_generate_password( 6, false ), 'stack_slug' => $stack, 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
		] ) );
		$quote = Cost_Engine::quote_for_change( $character, [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $block, 'trait' => [ 'name' => $name, 'count' => 1 ] ],
		], false );
		return $quote['priced'] ? (int) $quote['xp'] : null;
	}

	public function test_the_sections_that_take_entries_are_listed_by_creature_type(): void {
		$groups = $this->call( 'GET', '' )->get_data()['creature_types'];
		$by_stack = array_column( $groups, 'sections', 'stack' );

		$this->assertArrayHasKey( 'vampire', $by_stack );
		$vampire = array_column( $by_stack['vampire'], null, 'block_slug' );
		foreach ( [ 'vampire-disciplines', 'vampire-rituals', 'vampire-combo-disciplines', 'vampire-merits' ] as $slug ) {
			$this->assertArrayHasKey( $slug, $vampire );
		}
		$this->assertSame( 'family', $vampire['vampire-disciplines']['kind'] );
		$this->assertSame( 'item', $vampire['vampire-rituals']['kind'] );
		$this->assertSame( 'rank', $vampire['vampire-disciplines']['priced_by'] );
		$this->assertCount( 5, $vampire['vampire-disciplines']['ladder'] );

		$every = [];
		foreach ( $by_stack as $sections ) {
			$every = array_merge( $every, array_column( $sections, 'block_slug' ) );
		}
		foreach ( [ 'vampire-health', 'vampire-bonds', 'werewolf-health' ] as $closed ) {
			$this->assertNotContains( $closed, $every, "{$closed} takes no entries of its own" );
		}
		$this->assertNotContains( 'various', array_keys( $by_stack ) );
	}

	public function test_an_item_joins_only_the_chronicles_copy_and_is_priced_for_a_character(): void {
		$before = count( Schema_Block::find_by_slug( 'vampire-merits' )->definition->items );

		$added = $this->add( 'vampire-merits', [ 'name' => 'Hollow Blood', 'cost' => '4' ] );
		$this->assertSame( 'added', $added['origin'] );
		$this->assertSame( '4', $added['cost'] );

		$this->assertNotNull( $this->holds( $this->copy( 'vampire-merits' )['definition'], 'items', 'Hollow Blood' ) );
		$this->assertNotEmpty( $this->copy( 'vampire-merits' )['changes'], 'the chronicle records the addition as its own change' );
		$this->assertSame( $before, count( Schema_Block::find_by_slug( 'vampire-merits' )->definition->items ), 'the book block is untouched' );
		$this->assertNull( Schema_Block::copy_for_game( 'vampire-merits', $this->other ), 'another chronicle has no copy' );
		$this->assertSame( 4, $this->price_for( 'vampire-merits', 'Hollow Blood' ) );
	}

	public function test_a_price_outside_the_catalogs_grammar_is_refused(): void {
		foreach ( [ 'four', '', '501', '3-1', '-2' ] as $bad ) {
			$response = $this->call( 'POST', '/vampire-merits/entries', [ 'name' => 'Bad Price', 'cost' => $bad ] );
			$this->assertSame( 400, $response->get_status(), "'{$bad}'" );
			$this->assertSame( 'invalid_price', $response->as_error()->get_error_code() );
		}
		foreach ( [ '3', '1-3', '1 or 3' ] as $good ) {
			$this->assertSame( 201, $this->call( 'POST', '/vampire-merits/entries', [ 'name' => "Priced {$good}", 'cost' => $good ] )->get_status() );
		}
	}

	public function test_a_lists_ranks_come_lowest_first(): void {
		$sections = array_column( $this->call( 'GET', '' )->get_data()['creature_types'], 'sections', 'stack' );
		$rituals  = array_column( $sections['vampire'], null, 'block_slug' )['vampire-rituals'];
		$this->assertTrue( $rituals['name_form'] );
		$this->assertSame( [ 'basic', 'intermediate', 'advanced', 'elder', 'master' ], $rituals['name_ranks'] );
	}

	public function test_a_ritual_is_stored_by_the_lists_name_form(): void {
		$added = $this->add( 'vampire-rituals', [ 'name' => 'Ward of Ash', 'group' => 'Thaumaturgy', 'tier' => 'basic', 'cost' => '2' ] );

		$this->assertSame( 'Thaumaturgy: Ward of Ash (basic)', $added['name'] );
		$this->assertSame( 'Thaumaturgy', $added['group'] );
		$this->assertSame( 'basic', $added['tier'] );
		$this->assertSame( 'Ward of Ash', $added['base_name'] );

		$refused = $this->call( 'POST', '/vampire-rituals/entries', [ 'name' => 'Odd', 'group' => 'Thaumaturgy', 'tier' => 'grand', 'cost' => '2' ] );
		$this->assertSame( 400, $refused->get_status() );
	}

	public function test_a_name_already_there_is_refused_whatever_its_case(): void {
		$this->add( 'vampire-merits', [ 'name' => 'Hollow Blood', 'cost' => '4' ] );

		$again = $this->call( 'POST', '/vampire-merits/entries', [ 'name' => 'HOLLOW BLOOD', 'cost' => '2' ] );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'entry_exists', $again->as_error()->get_error_code() );
	}

	public function test_a_book_item_changes_only_its_price_and_description_and_goes_back(): void {
		$book = $this->entry( 'vampire-merits', 'Ability Aptitude' );
		$this->assertSame( 'book', $book['origin'] );
		$this->assertSame( '1', $book['cost'] );

		$renamed = $this->change( 'vampire-merits', $book, [ 'name' => 'Skill Aptitude' ] );
		$this->assertSame( 400, $renamed->get_status() );
		$this->assertSame( 'book_entry_field', $renamed->as_error()->get_error_code() );

		$changed = $this->change( 'vampire-merits', $book, [ 'cost' => '3', 'description' => [ 'description' => '<p>Practised.</p>' ] ] );
		$this->assertSame( 200, $changed->get_status(), wp_json_encode( $changed->get_data() ) );
		$entry = $changed->get_data()['entry'];
		$this->assertSame( 'changed', $entry['origin'] );
		$this->assertSame( '3', $entry['cost'] );
		$this->assertSame( 3, $this->price_for( 'vampire-merits', 'Ability Aptitude' ), 'a player is quoted the chronicle\'s price' );
		$this->assertSame( '1', Schema_Block::find_by_slug( 'vampire-merits' )->definition->items[0]->cost ?? '1', 'the book keeps its price' );

		$back = $this->call( 'POST', '/vampire-merits/entries/revert', [ 'name' => 'Ability Aptitude' ] );
		$this->assertSame( 200, $back->get_status() );
		$this->assertSame( 'book', $back->get_data()['entry']['origin'] );
		$this->assertSame( '1', $back->get_data()['entry']['cost'] );
		$this->assertSame( 1, $this->price_for( 'vampire-merits', 'Ability Aptitude' ) );
	}

	public function test_a_new_price_leaves_a_held_row_where_it_was(): void {
		$character_id = Character::create( [
			'name' => 'Holds a Merit', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => [ 'vampire-merits' => [ [ 'name' => 'Ability Aptitude', 'count' => 1 ] ] ],
		] );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_characters', [ 'xp_unspent' => 9 ], [ 'id' => $character_id ] );

		$this->change( 'vampire-merits', $this->entry( 'vampire-merits', 'Ability Aptitude' ), [ 'cost' => '5' ] );

		$character = Character::find( $character_id );
		$this->assertEquals( 9, $character->xp_unspent, 'no XP moves' );
		$this->assertSame( [ [ 'name' => 'Ability Aptitude', 'count' => 1 ] ], $character->sheet_data['vampire-merits'], 'the held row is as it was' );
	}

	public function test_a_book_change_under_a_chronicles_change_is_flagged_and_settled_through_the_correction_routes(): void {
		$entry = $this->entry( 'vampire-merits', 'Ability Aptitude' );
		$this->change( 'vampire-merits', $entry, [ 'cost' => '7' ] );

		$book = Schema_Block::find_by_slug( 'vampire-merits' );
		$definition = json_decode( (string) wp_json_encode( $book->definition ), true );
		$definition['items'][ array_search( 'Ability Aptitude', array_column( $definition['items'], 'name' ), true ) ]['cost'] = '2';
		Schema_Block::update( 'vampire-merits', [ 'definition' => $definition ] );
		Schema_Block::refresh_forks( 'vampire-merits' );

		$flagged = $this->entry( 'vampire-merits', 'Ability Aptitude' );
		$this->assertTrue( $flagged['flagged'], 'the book changed under the chronicle\'s own change' );
		$this->assertSame( '7', $flagged['cost'] );
		$this->assertNotEmpty( $flagged['corrections'] );

		$path = $flagged['corrections'][0]['path'];
		$take = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/catalog-corrections/take" );
		$take->set_param( 'kind', 'block' );
		$take->set_param( 'target', 'vampire-merits' );
		$take->set_param( 'path', $path );
		$this->assertSame( 200, rest_get_server()->dispatch( $take )->get_status() );

		$settled = $this->entry( 'vampire-merits', 'Ability Aptitude' );
		$this->assertFalse( $settled['flagged'] );
		$this->assertSame( '2', $settled['cost'], 'the book\'s new price now stands' );
	}

	public function test_a_family_on_a_ladder_takes_every_rung_levels_named_and_an_elder_power(): void {
		$added = $this->add( 'vampire-disciplines', [ 'name' => 'Hollow Vein' ] );
		$this->assertSame( 'added', $added['origin'] );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_column( $added['levels'], 'level' ) );
		$this->assertSame( [ '', '', '', '', '' ], array_column( $added['levels'], 'power_name' ) );
		$this->assertSame( [ '3', '3', '6', '6', '9' ], array_column( $added['levels'], 'cost' ) );

		$named = $this->change( 'vampire-disciplines', $added, [
			'levels' => [ [ 'level' => 1, 'power_name' => 'First Cut' ], [ 'level' => 5, 'power_name' => 'Open Vein', 'description' => [ 'description' => '<p>Bleed.</p>' ] ] ],
			'picks'  => [ [ 'rank' => 'elder', 'power_name' => 'Hollow Throne', 'cost' => '', 'description' => [ 'description' => '<p>Elder.</p>' ] ] ],
		] );
		$this->assertSame( 200, $named->get_status(), wp_json_encode( $named->get_data() ) );
		$entry = $named->get_data()['entry'];
		$this->assertSame( 'First Cut', $entry['levels'][0]['power_name'] );
		$this->assertSame( 'Open Vein', $entry['levels'][4]['power_name'] );
		$this->assertCount( 1, $entry['picks'] );
		$this->assertSame( '12', $entry['picks'][0]['cost'], 'an elder power takes its rank\'s cost' );
		$this->assertSame( 'added', $entry['picks'][0]['origin'] );

		$family = $this->holds( $this->copy( 'vampire-disciplines' )['definition'], 'powers', 'Hollow Vein' );
		$this->assertSame( 'First Cut', $family['levels'][0]['power_name'] );
		$this->assertNull( $this->holds( json_decode( (string) wp_json_encode( Schema_Block::find_by_slug( 'vampire-disciplines' )->definition ), true ), 'powers', 'Hollow Vein' ) );
		$this->assertNotEmpty( $this->copy( 'vampire-disciplines' )['changes'] );
	}

	public function test_a_power_name_repeated_inside_a_family_is_refused(): void {
		$added = $this->add( 'vampire-disciplines', [ 'name' => 'Hollow Vein' ] );
		$clash = $this->change( 'vampire-disciplines', $added, [
			'levels' => [ [ 'level' => 1, 'power_name' => 'Same Name' ] ],
			'picks'  => [ [ 'rank' => 'elder', 'power_name' => 'same name' ] ],
		] );
		$this->assertSame( 409, $clash->get_status() );
	}

	public function test_a_book_family_takes_a_description_and_new_elder_powers_but_keeps_its_names(): void {
		$book = $this->entry( 'vampire-disciplines', 'Animalism' );
		$this->assertSame( 'book', $book['origin'] );

		$renamed = $this->change( 'vampire-disciplines', $book, [ 'levels' => [ [ 'level' => 1, 'power_name' => 'Something Else' ] ] ] );
		$this->assertSame( 'book_entry_field', $renamed->as_error()->get_error_code() );

		$kept = array_map( static fn( $pick ) => [ 'rank' => $pick['rank'], 'power_name' => $pick['power_name'], 'cost' => $pick['cost'] ], $book['picks'] );
		$added = $this->change( 'vampire-disciplines', $book, [
			'description' => [ 'description' => '<p>Beasts.</p>' ],
			'picks'       => array_merge( $kept, [ [ 'rank' => 'elder', 'power_name' => 'Herd Mind', 'cost' => '' ] ] ),
		] );
		$this->assertSame( 200, $added->get_status(), wp_json_encode( $added->get_data() ) );
		$names = array_column( $added->get_data()['entry']['picks'], 'power_name' );
		$this->assertContains( 'Herd Mind', $names );
		$this->assertContains( 'Quell the Herd', $names, 'the book\'s own powers stay' );

		$without = array_slice( $kept, 1 );
		$dropped = $this->change( 'vampire-disciplines', $added->get_data()['entry'], [ 'picks' => $without ] );
		$this->assertSame( 'book_entry_field', $dropped->as_error()->get_error_code(), 'a book power cannot be taken away here' );
	}

	public function test_a_gift_joins_an_existing_family_and_a_new_one(): void {
		$homid = $this->entry( 'werewolf-gifts', 'Homid', 'werewolf' );
		$kept  = array_map( static fn( $pick ) => [ 'rank' => $pick['rank'], 'power_name' => $pick['power_name'], 'cost' => $pick['cost'] ], $homid['picks'] );

		$joined = $this->change( 'werewolf-gifts', $homid, [ 'picks' => array_merge( $kept, [ [ 'rank' => 'basic', 'power_name' => 'Hollow Gift', 'cost' => '' ] ] ) ], 'werewolf' );
		$this->assertSame( 200, $joined->get_status(), wp_json_encode( $joined->get_data() ) );
		$this->assertContains( 'Hollow Gift', array_column( $joined->get_data()['entry']['picks'], 'power_name' ) );

		$fresh = $this->call( 'POST', '/werewolf-gifts/entries', [
			'name'  => 'Hollow Pack',
			'picks' => [ [ 'rank' => 'intermediate', 'power_name' => 'Pack Echo', 'cost' => '' ] ],
			'stack' => 'werewolf',
		] );
		$this->assertSame( 201, $fresh->get_status(), wp_json_encode( $fresh->get_data() ) );
		$this->assertSame( '6', $fresh->get_data()['entry']['picks'][0]['cost'] );
		$this->assertNull( $this->holds( json_decode( (string) wp_json_encode( Schema_Block::find_by_slug( 'werewolf-gifts' )->definition ), true ), 'powers', 'Hollow Pack' ) );
	}

	public function test_a_blood_magic_path_takes_its_traditions(): void {
		$traditions = array_slice( (array) Schema_Block::find_by_slug( 'vampire-blood-magic' )->definition->traditions, 0, 2 );
		$this->assertCount( 2, $traditions );

		$added = $this->add( 'vampire-blood-magic', [
			'name'       => 'Hollow Rite',
			'traditions' => [ $traditions[0] => 'Rasayana', $traditions[1] => null ],
		] );
		$this->assertSame( 'Rasayana', ( (array) $added['traditions'] )[ $traditions[0] ] );
		$this->assertNull( ( (array) $added['traditions'] )[ $traditions[1] ] );

		$bad = $this->change( 'vampire-blood-magic', $added, [ 'traditions' => [ 'Not a Tradition' => 'x' ] ] );
		$this->assertSame( 400, $bad->get_status() );
	}

	public function test_in_clan_files_a_family_under_a_clan_and_the_sections_own_test_reads_it(): void {
		$added = $this->add( 'vampire-disciplines', [ 'name' => 'Hollow Vein' ] );

		$sections = array_column( $this->call( 'GET', '' )->get_data()['creature_types'], 'sections', 'stack' );
		$disciplines = array_column( $sections['vampire'], null, 'block_slug' )['vampire-disciplines'];
		$this->assertSame( 'vampire-identity.clan_disciplines', $disciplines['in_type']['maps'][0]['map'] );
		$this->assertContains( 'Tremere', $disciplines['in_type']['maps'][0]['keys'] );
		$this->assertSame( [ 'Caitiff', 'Pander' ], $disciplines['in_type']['chosen'][0]['is'], 'those clans choose their own' );

		$filed = $this->call( 'PUT', '/vampire-disciplines/in-type', [
			'name'    => 'Hollow Vein',
			'etag'    => $added['etag'],
			'stack'   => 'vampire',
			'in_clan' => [ 'vampire-identity.clan_disciplines' => [ 'Tremere' ] ],
		] );
		$this->assertSame( 200, $filed->get_status(), wp_json_encode( $filed->get_data() ) );
		$this->assertSame( [ 'Tremere' ], $filed->get_data()['entry']['in_clan']->{'vampire-identity.clan_disciplines'} );

		$tremere = (object) [ 'stack_slug' => 'vampire', 'owner_slug' => $this->slug, 'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Tremere' ] ] ];
		$ventrue = (object) [ 'stack_slug' => 'vampire', 'owner_slug' => $this->slug, 'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Ventrue' ] ] ];
		$this->assertTrue( In_Type::check( $tremere, 'vampire-disciplines' )( 'Hollow Vein' ) );
		$this->assertFalse( In_Type::check( $ventrue, 'vampire-disciplines' )( 'Hollow Vein' ) );

		$identity = $this->copy( 'vampire-identity' )['definition'];
		$this->assertContains( 'Hollow Vein', $identity['clan_disciplines']['Tremere'] );
		$this->assertNotContains( 'Hollow Vein', Schema_Block::find_by_slug( 'vampire-identity' )->definition->clan_disciplines->Tremere, 'the book keeps its list' );

		$unfiled = $this->call( 'PUT', '/vampire-disciplines/in-type', [
			'name' => 'Hollow Vein', 'etag' => $filed->get_data()['entry']['etag'], 'stack' => 'vampire',
			'in_clan' => [ 'vampire-identity.clan_disciplines' => [] ],
		] );
		$this->assertSame( 200, $unfiled->get_status() );
		$this->assertFalse( In_Type::check( $tremere, 'vampire-disciplines' )( 'Hollow Vein' ) );
	}

	public function test_a_book_family_cannot_be_filed_in_another_clan_here(): void {
		$animalism = $this->entry( 'vampire-disciplines', 'Animalism' );
		$response  = $this->call( 'PUT', '/vampire-disciplines/in-type', [
			'name' => 'Animalism', 'etag' => $animalism['etag'], 'stack' => 'vampire',
			'in_clan' => [ 'vampire-identity.clan_disciplines' => [ 'Tremere' ] ],
		] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'book_entry_field', $response->as_error()->get_error_code() );
	}

	public function test_filed_under_makes_a_gift_in_type_for_that_tribe(): void {
		$fresh = $this->call( 'POST', '/werewolf-gifts/entries', [
			'name'  => 'Hollow Pack',
			'picks' => [ [ 'rank' => 'basic', 'power_name' => 'Pack Echo', 'cost' => '' ] ],
		] )->get_data()['entry'];

		$sections = array_column( $this->call( 'GET', '' )->get_data()['creature_types'], 'sections', 'stack' );
		$gifts    = array_column( $sections['werewolf'], null, 'block_slug' )['werewolf-gifts'];
		$axes     = array_column( $gifts['in_type']['facets'], 'options', 'facet' );
		$this->assertContains( 'Silver Fangs', $axes['tribe'] );

		$filed = $this->call( 'PUT', '/werewolf-gifts/in-type', [
			'name' => 'Hollow Pack', 'etag' => $fresh['etag'], 'stack' => 'werewolf',
			'category_values' => [ 'tribe' => 'Silver Fangs' ],
		] );
		$this->assertSame( 200, $filed->get_status(), wp_json_encode( $filed->get_data() ) );

		$fang  = (object) [ 'stack_slug' => 'werewolf', 'owner_slug' => $this->slug, 'sheet_data' => [ 'werewolf-identity' => [ 'Breed' => 'Homid', 'Auspice' => 'Ragabash', 'Tribe' => 'Silver Fangs' ] ] ];
		$other = (object) [ 'stack_slug' => 'werewolf', 'owner_slug' => $this->slug, 'sheet_data' => [ 'werewolf-identity' => [ 'Breed' => 'Homid', 'Auspice' => 'Ragabash', 'Tribe' => 'Black Furies' ] ] ];
		$this->assertTrue( In_Type::check( $fang, 'werewolf-gifts' )( 'Hollow Pack' ) );
		$this->assertFalse( In_Type::check( $other, 'werewolf-gifts' )( 'Hollow Pack' ) );

		$bad = $this->call( 'PUT', '/werewolf-gifts/in-type', [
			'name' => 'Hollow Pack', 'etag' => $filed->get_data()['entry']['etag'], 'stack' => 'werewolf',
			'category_values' => [ 'tribe' => 'Not a Tribe' ],
		] );
		$this->assertSame( 400, $bad->get_status() );
	}

	public function test_an_entry_characters_hold_or_a_waiting_change_names_cannot_be_renamed_or_removed(): void {
		$family = $this->add( 'vampire-disciplines', [ 'name' => 'Hollow Vein' ] );
		$item   = $this->add( 'vampire-merits', [ 'name' => 'Hollow Blood', 'cost' => '4' ] );

		for ( $i = 0; $i < 4; $i++ ) {
			Character::create( [
				'name' => "Holder {$i}", 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
				'sheet_data' => [ 'vampire-merits' => [ [ 'name' => 'Hollow Blood', 'count' => 1 ] ], 'vampire-disciplines' => [ [ 'name' => 'Hollow Vein', 'level' => 1 ] ] ],
			] );
		}
		$waiting = Character::create( [ 'name' => 'Waiting', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		Change::create( [
			'character_id' => $waiting, 'change_type' => 'add_trait', 'status' => 'pending', 'submitted_by' => $this->hst,
			'change_data'  => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Hollow Blood', 'count' => 1 ] ],
		] );

		$rename = $this->change( 'vampire-merits', $this->entry( 'vampire-merits', 'Hollow Blood' ), [ 'name' => 'Hollow Heart' ] );
		$this->assertSame( 409, $rename->get_status() );
		$this->assertSame( 'entry_in_use', $rename->as_error()->get_error_code() );
		$this->assertSame( '4 characters hold this, and 1 change waiting names it.', $rename->as_error()->get_error_message() );

		$remove = $this->call( 'DELETE', '/vampire-merits/entries', [ 'name' => 'Hollow Blood', 'etag' => $item['etag'] ] );
		$this->assertSame( 'entry_in_use', $remove->as_error()->get_error_code() );

		$famed = $this->call( 'PUT', '/vampire-disciplines/entries', [ 'name' => 'Hollow Vein', 'etag' => $family['etag'], 'changes' => [ 'name' => 'Hollow Heart' ] ] );
		$this->assertSame( 'entry_in_use', $famed->as_error()->get_error_code() );
		$this->assertSame( '4 characters hold this.', $famed->as_error()->get_error_message() );

		$levelled = $this->change( 'vampire-disciplines', $family, [ 'levels' => [ [ 'level' => 2, 'power_name' => 'Named Later' ] ] ] );
		$this->assertSame( 200, $levelled->get_status(), 'naming a level is always allowed, since a held level is held by number' );
	}

	public function test_an_unheld_own_entry_can_be_renamed_and_removed_and_leaves_the_in_clan_lists_clean(): void {
		$family = $this->add( 'vampire-disciplines', [ 'name' => 'Hollow Vein' ] );
		$this->call( 'PUT', '/vampire-disciplines/in-type', [
			'name' => 'Hollow Vein', 'etag' => $family['etag'], 'stack' => 'vampire',
			'in_clan' => [ 'vampire-identity.clan_disciplines' => [ 'Tremere' ] ],
		] );

		$renamed = $this->change( 'vampire-disciplines', $this->entry( 'vampire-disciplines', 'Hollow Vein' ), [ 'name' => 'Hollow Heart' ] );
		$this->assertSame( 200, $renamed->get_status(), wp_json_encode( $renamed->get_data() ) );
		$this->assertContains( 'Hollow Heart', $this->copy( 'vampire-identity' )['definition']['clan_disciplines']['Tremere'] );
		$this->assertNotContains( 'Hollow Vein', $this->copy( 'vampire-identity' )['definition']['clan_disciplines']['Tremere'] );

		$entry   = $this->entry( 'vampire-disciplines', 'Hollow Heart' );
		$removed = $this->call( 'DELETE', '/vampire-disciplines/entries', [ 'name' => 'Hollow Heart', 'etag' => $entry['etag'] ] );
		$this->assertSame( 200, $removed->get_status() );
		$this->assertNull( $this->holds( $this->copy( 'vampire-disciplines' )['definition'], 'powers', 'Hollow Heart' ) );
		$this->assertNotContains( 'Hollow Heart', $this->copy( 'vampire-identity' )['definition']['clan_disciplines']['Tremere'] );
	}

	public function test_a_book_entry_cannot_be_removed_here(): void {
		$book    = $this->entry( 'vampire-merits', 'Ability Aptitude' );
		$removed = $this->call( 'DELETE', '/vampire-merits/entries', [ 'name' => 'Ability Aptitude', 'etag' => $book['etag'] ] );
		$this->assertSame( 400, $removed->get_status() );
		$this->assertSame( 'book_entry_field', $removed->as_error()->get_error_code() );
	}

	public function test_a_save_over_a_newer_version_is_refused(): void {
		$item  = $this->add( 'vampire-merits', [ 'name' => 'Hollow Blood', 'cost' => '4' ] );
		$first = $this->change( 'vampire-merits', $item, [ 'cost' => '5' ] );
		$this->assertSame( 200, $first->get_status() );

		$stale = $this->change( 'vampire-merits', $item, [ 'cost' => '6' ] );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'entry_changed', $stale->as_error()->get_error_code() );
		$this->assertSame( 'Someone changed this since you opened it.', $stale->as_error()->get_error_message() );
	}

	public function test_two_people_changing_different_entries_of_one_block_keep_both_changes(): void {
		$one = $this->entry( 'vampire-merits', 'Ability Aptitude' );
		$two = $this->entry( 'vampire-merits', 'Abomination' );

		$this->assertSame( 200, $this->change( 'vampire-merits', $one, [ 'cost' => '3' ] )->get_status() );
		$this->assertSame( 200, $this->change( 'vampire-merits', $two, [ 'cost' => '4' ] )->get_status() );

		$this->assertSame( '3', $this->entry( 'vampire-merits', 'Ability Aptitude' )['cost'] );
		$this->assertSame( '4', $this->entry( 'vampire-merits', 'Abomination' )['cost'] );
	}

	public function test_a_save_on_a_portuguese_site_keeps_the_name_as_typed_and_no_translations_in_the_copy(): void {
		switch_to_locale( 'pt_BR' );
		$added = $this->add( 'vampire-merits', [ 'name' => 'Hollow Blood', 'cost' => '4' ] );
		restore_previous_locale();

		$this->assertSame( 'Hollow Blood', $added['name'] );
		$this->assertStringNotContainsString( '_pt"', (string) wp_json_encode( $this->copy( 'vampire-merits' )['definition'] ) );
		$this->assertNotNull( $this->holds( $this->copy( 'vampire-merits' )['definition'], 'items', 'Hollow Blood' ) );
	}

	public function test_a_chronicles_own_section_is_listed_and_its_entries_are_its_own(): void {
		Schema_Block::create( [
			'slug' => 'thread-own-boons', 'game_slug' => $this->slug, 'name' => 'Own Boons', 'section_type' => 'trait_list',
			'definition' => [ 'allow_custom' => true, 'items' => [ [ 'name' => 'Old Favour', 'cost' => '2' ] ] ],
		] );
		\BeyondElysium\Models\Creature_Stack::add_section( 'vampire', $this->slug, 'thread-own-boons', 'Own Boons' );

		$sections = array_column( $this->call( 'GET', '' )->get_data()['creature_types'], 'sections', 'stack' );
		$own      = array_column( $sections['vampire'], null, 'block_slug' );
		$this->assertArrayHasKey( 'thread-own-boons', $own );

		$entry = $this->entry( 'thread-own-boons', 'Old Favour' );
		$this->assertSame( 'added', $entry['origin'], 'a chronicle\'s own section has no book beneath it' );
		$this->assertSame( 200, $this->change( 'thread-own-boons', $entry, [ 'name' => 'Older Favour' ] )->get_status() );
	}

	public function test_an_ast_without_a_grant_is_refused_on_every_route(): void {
		$item = $this->add( 'vampire-merits', [ 'name' => 'Hollow Blood', 'cost' => '4' ] );
		wp_set_current_user( $this->ast );

		foreach ( [
			[ 'GET', '' ],
			[ 'GET', '/vampire-merits' ],
			[ 'POST', '/vampire-merits/entries' ],
			[ 'PUT', '/vampire-merits/entries' ],
			[ 'DELETE', '/vampire-merits/entries' ],
			[ 'POST', '/vampire-merits/entries/revert' ],
			[ 'PUT', '/vampire-disciplines/in-type' ],
		] as [ $method, $route ] ) {
			$this->assertSame( 403, $this->call( $method, $route, [ 'name' => 'Hollow Blood', 'etag' => $item['etag'] ] )->get_status(), "{$method} {$route}" );
		}
	}

	public function test_an_ast_with_the_catalog_granted_can_use_the_routes(): void {
		\BeyondElysium\Services\Staff_Grants::set( Game::find( $this->game_id ), $this->ast, [ 'be_manage_schemas' ], $this->hst );
		wp_set_current_user( $this->ast );

		$this->assertSame( 200, $this->call( 'GET', '' )->get_status() );
		$this->assertSame( 201, $this->call( 'POST', '/vampire-merits/entries', [ 'name' => 'Granted Merit', 'cost' => '2' ] )->get_status() );
	}

	public function test_the_item_catalogs_own_route_is_not_taken_for_a_chronicles_catalog(): void {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/items/catalog' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'books', $response->get_data() );
		$this->assertArrayNotHasKey( 'creature_types', $response->get_data() );
	}

	public function test_a_block_that_is_not_a_catalog_section_is_not_found(): void {
		$this->assertSame( 404, $this->call( 'GET', '/vampire-health' )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/vampire-bonds/entries', [ 'name' => 'X', 'cost' => '1' ] )->get_status() );
	}
}
