<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `Import_Controller::commit()`, with a hand-built `$parsed` structure injected directly.
 */
class ImportControllerCommitTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-import-game';
	private int $game_id;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Import Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A minimal vampire character record, shaped exactly like `GEX_Parser::parse_character_vampire()`'s real return
	 * array.
	 */
	private function synthetic_character( string $merit_trait_name, array $extra_trait_lists = [] ): array {
		return [
			'race'         => 'vampire',
			'name'         => 'Synthetic Test Character',
			'player'       => 'Jane Doe',
			'nature'       => 'Survivor',
			'demeanor'     => 'Bravo',
			'clan'         => 'Toreador',
			'sect'         => 'Camarilla',
			'generation'   => 10,
			'sire'         => 'Old One',
			'title'        => 'Whip',
			'path'         => 'Humanity',
			'path_traits'  => 7,
			'temp_path_traits' => 7,
			'blood'        => 12,
			'temp_blood'   => 12,
			'willpower'    => 6,
			'temp_willpower' => 6,
			'conscience'   => 3,
			'temp_conscience' => 3,
			'self_control' => 2,
			'temp_self_control' => 2,
			'courage'      => 4,
			'temp_courage' => 4,
			'is_npc'       => false,
			'narrator'     => '',
			'start_date'   => null,
			'biography'    => '',
			'notes'        => '',
			'status'       => 'Active',
			'experience'   => [ 'earned' => 12.0, 'unspent' => 5.0, 'history' => [] ],
			'trait_lists'  => array_merge( [
				'Merits' => [
					'name'   => 'Merits',
					'traits' => [
						[ 'name' => $merit_trait_name, 'total' => '3', 'note' => '' ],
					],
				],
			], $extra_trait_lists ),
		];
	}

	/** @return array<string,mixed> A minimal item record, shaped like `parse_item()`. */
	private function synthetic_item(): array {
		return [
			'name' => 'Synthetic Test Item', 'item_type' => 'Weapon', 'item_subtype' => 'Melee',
			'level' => 2, 'bonus' => 1, 'damage_type' => 'Aggravated', 'damage_amount' => 3,
			'concealability' => 'Trenchcoat',
			'temper_list'   => [ 'traits' => [] ],
			'ability_list'  => [ 'traits' => [] ],
			'negative_list' => [ 'traits' => [] ],
			'availability'  => [ 'traits' => [] ],
			'powers' => '', 'appearance' => 'A test item.', 'notes' => '',
		];
	}

	private function synthetic_parsed( array $characters, array $items = [] ): array {
		return [
			'version'    => 2.399,
			'players'    => [ [ 'name' => 'Jane Doe', 'email' => 'jane@example.test' ] ],
			'characters' => $characters,
			'items'      => $items,
			'locations'  => [],
			'rotes'      => [],
			'actions'    => [],
			'plots'      => [],
			'rumors'     => [],
			'queries'    => [],
		];
	}

	private function inject_job( array $parsed ): string {
		$job_id = wp_generate_uuid4();
		$reflection = new \ReflectionClass( \BeyondElysium\REST\Import_Controller::class );
		$build_preview = $reflection->getMethod( 'build_preview' );
		$build_preview->setAccessible( true );
		$preview = $build_preview->invoke( null, $parsed, $this->game_slug, $this->game_id );

		set_transient( 'be_import_job_' . $job_id, [
			'game_id'     => $this->game_id,
			'parsed'      => $parsed,
			'preview'     => $preview,
			'source_file' => 'synthetic-test.gex',
		], HOUR_IN_SECONDS );

		return $job_id;
	}

	private function commit_request( string $job_id ): WP_REST_Request {
		return new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/import/{$job_id}/commit" );
	}

	public function test_commit_creates_an_item_and_a_character_with_resolved_traits(): void {
		wp_set_current_user( $this->admin_id );

		$before = World_Object::count_for_game( $this->game_id );
		$job_id = $this->inject_job( $this->synthetic_parsed(
			[ $this->synthetic_character( 'Iron Will' ) ],
			[ $this->synthetic_item() ]
		) );

		$response = $this->dispatch( $this->commit_request( $job_id ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 'created', $data['items'][0]['action'] );
		$this->assertCount( 1, $data['characters'] );
		$this->assertSame( $before + 1, World_Object::count_for_game( $this->game_id ) );

		$character = Character::find( (int) $data['characters'][0]['id'] );
		$this->assertSame( 'Synthetic Test Character', $character->name );
		$this->assertSame( $this->game_slug, $character->owner_slug );
		$this->assertNotEmpty( $character->uuid, 'D24: every imported character gets a fresh UUIDv7.' );
		$this->assertSame( 12, (int) $character->xp_earned );
		$this->assertSame( 5, (int) $character->xp_unspent );

		$merits = $character->sheet_data['vampire-merits'] ?? [];
		$this->assertCount( 1, $merits );
		$this->assertSame( 'Iron Will', $merits[0]['name'] );
		$this->assertSame( 3, $merits[0]['count'] );

		// Identity/resource field mapping.
		$this->assertSame( 'Toreador', $character->sheet_data['vampire-identity']['Clan'] );
		$this->assertSame( 'Humanity', $character->sheet_data['vampire-identity']['Morality Path'] );
		$this->assertSame( 'Survivor', $character->sheet_data['met-archetypes']['Nature'] );
		// sheet_data round-trips through JSON (Character::create()/find()).
		$this->assertEquals(
			[ 'permanent' => 12, 'temporary' => 12 ],
			$character->sheet_data['vampire-resources']['Blood']
		);
		$this->assertEquals(
			[ 'permanent' => 7, 'temporary' => 7 ],
			$character->sheet_data['vampire-resources']['Morality'],
			'Morality is the numeric path_traits rating, not the Morality Path name.'
		);
		$this->assertEquals(
			[ 'permanent' => 3, 'temporary' => 3 ],
			$character->sheet_data['vampire-virtues']['Conscience']
		);

		$changes = \BeyondElysium\Models\Change::for_character( (int) $character->id );
		$import_notes = array_filter( $changes, static fn( $c ) => $c->change_type === 'import_note' );
		$this->assertCount( 1, $import_notes );
	}

	public function test_preserve_as_note_trait_lists_survive_into_the_import_note(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will', [
			'Miscellaneous' => [
				'name'   => 'Miscellaneous',
				'traits' => [
					[ 'name' => 'Sire', 'total' => '5', 'note' => '' ],
				],
			],
		] );
		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );

		$response = $this->dispatch( $this->commit_request( $job_id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$found_character = Character::find( (int) $response->get_data()['characters'][0]['id'] );
		$changes         = \BeyondElysium\Models\Change::for_character( (int) $found_character->id );
		$import_note     = current( array_filter( $changes, static fn( $c ) => $c->change_type === 'import_note' ) );
		$this->assertNotFalse( $import_note );

		$preserved = $import_note->change_data['raw_record']['trait_lists'] ?? null;
		$this->assertNotNull( $preserved, 'preserve_as_note lists must not be stripped from the raw_record.' );
		$preserved_names = array_column( $preserved, 'name' );
		$this->assertContains( 'Miscellaneous', $preserved_names );
		// Merits and Health Levels are both sheet_block-classified and already live in sheet_data.
		$this->assertNotContains( 'Merits', $preserved_names );
		$this->assertNotContains( 'Health Levels', $preserved_names );
	}

	/**
	 * The real fix: an imported character's "Health Levels" list lands in `{stack}-health` sheet_data exactly like any
	 * other trait_list import (Merits' own path), carrying the real per-character box counts.
	 */
	public function test_health_levels_imports_into_the_stacks_own_health_block(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will', [
			'Health Levels' => [
				'name'   => 'Health Levels',
				'traits' => [
					[ 'name' => 'Healthy', 'total' => '2', 'note' => '' ],
					[ 'name' => 'Bruised', 'total' => '3', 'note' => '' ],
					[ 'name' => 'Wounded', 'total' => '2', 'note' => '' ],
					[ 'name' => 'Incapacitated', 'total' => '1', 'note' => '' ],
					[ 'name' => 'Torpor', 'total' => '1', 'note' => '' ],
				],
			],
		] );
		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );

		$response = $this->dispatch( $this->commit_request( $job_id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$found_character = Character::find( (int) $response->get_data()['characters'][0]['id'] );
		$health          = $found_character->sheet_data['vampire-health'] ?? null;
		$this->assertNotNull( $health, 'Health Levels must land in vampire-health sheet_data.' );

		$by_name = [];
		foreach ( $health as $item ) {
			$by_name[ $item['name'] ] = $item['count'];
		}
		$this->assertSame(
			[ 'Healthy' => 2, 'Bruised' => 3, 'Wounded' => 2, 'Incapacitated' => 1, 'Torpor' => 1 ],
			$by_name
		);
	}

	/**
	 * Every open-catalog trait_list block (Merits, Backgrounds, and so on) seeds with `allow_custom: true`.
	 */
	public function test_commit_is_refused_while_a_tiered_power_trait_is_unresolved(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [ [ 'name' => 'Not A Real Discipline Family', 'total' => '3', 'note' => '' ] ],
		];

		$before = Character::count_for_game( $this->game_slug );
		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );

		$response = $this->dispatch( $this->commit_request( $job_id ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'unresolved_traits', $response->get_data()['code'] );
		$this->assertSame( $before, Character::count_for_game( $this->game_slug ), 'Nothing should be partially created.' );
	}

	/**
	 * Against real seeded `vampire-disciplines` data: a numbered rung (`Fortitude`/Total `3`, real level 3 =
	 * "Resilience") and an Elder-and-above pick via its real self-describing name (`Trait_Mapper`'s confirmed real
	 * format).
	 */
	public function test_commit_resolves_real_tiered_power_traits(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [
				[ 'name' => 'Fortitude', 'total' => '3', 'note' => '' ],
				[ 'name' => 'Fortitude: Personal Armor (elder)', 'total' => '1', 'note' => '' ],
				[ 'name' => 'Smothering Darkness', 'total' => '5', 'note' => '' ],
			],
		];

		$job_id   = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$response = $this->dispatch( $this->commit_request( $job_id ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );

		$found = Character::find( (int) $data['characters'][0]['id'] );
		$held  = $found->sheet_data['vampire-disciplines'] ?? [];
		$this->assertCount( 2, $held );

		$numbered = current( array_filter( $held, static fn( $h ) => isset( $h['level'] ) ) );
		$this->assertSame( 'Fortitude', $numbered['name'] );
		$this->assertSame( 3, $numbered['level'] );

		$elder = current( array_filter( $held, static fn( $h ) => isset( $h['power_name'] ) ) );
		$this->assertSame( 'Fortitude', $elder['name'] );
		$this->assertSame( 'Personal Armor', $elder['power_name'] );

		$combos = $found->sheet_data['vampire-combo-disciplines'] ?? [];
		$this->assertCount( 1, $combos );
		$this->assertSame( 'Smothering Darkness', $combos[0]['name'] );
		$this->assertSame( 5, $combos[0]['count'] );
	}

	public function test_recommitting_the_same_job_does_not_import_twice(): void {
		wp_set_current_user( $this->admin_id );

		$job_id = $this->inject_job( $this->synthetic_parsed(
			[ $this->synthetic_character( 'Iron Will' ) ]
		) );

		$first  = $this->dispatch( $this->commit_request( $job_id ) )->get_data();
		$second = $this->dispatch( $this->commit_request( $job_id ) )->get_data();

		$this->assertSame( $first, $second, 'V13: re-committing must return the same frozen result, not import again.' );
	}

	public function test_a_player_role_user_is_refused(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$job_id = $this->inject_job( $this->synthetic_parsed( [] ) );
		$response = $this->dispatch( $this->commit_request( $job_id ) );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------

	public function test_a_duplicate_character_is_reported_in_the_preview(): void {
		$existing_id = Character::create( [
			'name'       => 'Synthetic Test Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
		] );

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $this->synthetic_character( 'Iron Will' ) ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );

		$this->assertCount( 1, $job['preview']['duplicates'] );
		$this->assertSame( 'Synthetic Test Character', $job['preview']['duplicates'][0]['character'] );
		$this->assertSame( $existing_id, $job['preview']['duplicates'][0]['existing_id'] );
	}

	public function test_committing_with_an_unaddressed_duplicate_is_refused(): void {
		wp_set_current_user( $this->admin_id );
		Character::create( [
			'name'       => 'Synthetic Test Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
		] );

		$job_id   = $this->inject_job( $this->synthetic_parsed( [ $this->synthetic_character( 'Iron Will' ) ] ) );
		$response = $this->dispatch( $this->commit_request( $job_id ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'unresolved_duplicates', $response->get_data()['code'] );
	}

	public function test_committing_with_skip_leaves_the_existing_character_untouched(): void {
		wp_set_current_user( $this->admin_id );
		$existing_id = Character::create( [
			'name'       => 'Synthetic Test Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
			'notes'      => 'Original notes, never touched.',
		] );

		$before_count = Character::count_for_game( $this->game_slug );
		$job_id       = $this->inject_job( $this->synthetic_parsed( [ $this->synthetic_character( 'Iron Will' ) ] ) );

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [ 'duplicates' => [ 'Synthetic Test Character' => 'skip' ] ] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'skipped', $data['characters'][0]['action'] );
		$this->assertSame( $existing_id, $data['characters'][0]['id'] );
		$this->assertSame( $before_count, Character::count_for_game( $this->game_slug ), 'skip must not create a new row' );

		$existing = Character::find( $existing_id );
		$this->assertSame( 'Original notes, never touched.', $existing->notes );
	}

	public function test_committing_with_overwrite_updates_in_place_and_preserves_identity(): void {
		wp_set_current_user( $this->admin_id );
		$existing_id = Character::create( [
			'name'       => 'Synthetic Test Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
			'notes'      => 'Stale notes from before the re-import.',
		] );
		Character::update_xp( $existing_id, 3, 1 );
		$existing_uuid = Character::find( $existing_id )->uuid;

		$before_count = Character::count_for_game( $this->game_slug );
		$job_id       = $this->inject_job( $this->synthetic_parsed( [ $this->synthetic_character( 'Iron Will' ) ] ) );

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [ 'duplicates' => [ 'Synthetic Test Character' => 'overwrite' ] ] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'overwritten', $data['characters'][0]['action'] );
		$this->assertSame( $existing_id, $data['characters'][0]['id'] );
		$this->assertSame( $before_count, Character::count_for_game( $this->game_slug ), 'overwrite must not create a second row' );

		$updated = Character::find( $existing_id );
		$this->assertSame( $existing_uuid, $updated->uuid, 'uuid must survive an overwrite so existing be_connections rows stay valid' );
		$this->assertSame( 'Synthetic Test Character', $updated->name );
		$this->assertSame( '', $updated->notes, 'the freshly imported record replaces stale header fields' );
		$this->assertSame( 12, (int) $updated->xp_earned, 'the import carries an absolute total (12), replacing the stale 3 via a computed delta' );
		$this->assertSame( 5, (int) $updated->xp_unspent );

		$merits = $updated->sheet_data['vampire-merits'] ?? [];
		$this->assertSame( 'Iron Will', $merits[0]['name'] ?? null );
	}

	public function test_committing_with_import_as_new_creates_a_second_character(): void {
		wp_set_current_user( $this->admin_id );
		$existing_id = Character::create( [
			'name'       => 'Synthetic Test Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
		] );

		$before_count = Character::count_for_game( $this->game_slug );
		$job_id       = $this->inject_job( $this->synthetic_parsed( [ $this->synthetic_character( 'Iron Will' ) ] ) );

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [ 'duplicates' => [ 'Synthetic Test Character' => 'import_as_new' ] ] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'created', $data['characters'][0]['action'] );
		$this->assertNotSame( $existing_id, $data['characters'][0]['id'] );
		$this->assertSame( $before_count + 1, Character::count_for_game( $this->game_slug ) );
	}

	// -------------------------------------------------------------------------
	// Chunk 1 of the Import plan: applying an ST's chosen fuzzy-match suggestion.
	// -------------------------------------------------------------------------

	public function test_committing_with_an_applied_trait_resolution_uses_the_chosen_name(): void {
		wp_set_current_user( $this->admin_id );

		// "Iron Wil" is one letter short of the real seeded merit "Iron Will".
		$job_id = $this->inject_job( $this->synthetic_parsed( [ $this->synthetic_character( 'Iron Wil' ) ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$this->assertNotEmpty( $job['preview']['flagged_traits'], 'the typo must actually be flagged fuzzy for this test to mean anything' );
		$flag = $job['preview']['flagged_traits'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character'       => $flag['character'],
					'block'           => $flag['block'],
					'raw'             => $flag['raw'],
					'action'          => 'apply_suggestion',
					'suggestion_name' => 'Iron Will',
				],
			],
		] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );

		$character = Character::find( (int) $data['characters'][0]['id'] );
		$merits    = $character->sheet_data['vampire-merits'] ?? [];
		$this->assertCount( 1, $merits );
		$this->assertSame( 'Iron Will', $merits[0]['name'], 'the corrected suggestion name, not the raw typo, must be what gets stored' );
	}

	public function test_committing_with_keep_custom_resolves_a_genuinely_unresolved_tiered_power(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [ [ 'name' => 'Thaumaturgy: Not A Real Path', 'total' => '', 'note' => 'basic' ] ],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$this->assertNotEmpty( $job['preview']['unresolved'], 'this raw name must actually be unresolved for this test to mean anything' );
		$entry = $job['preview']['unresolved'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character' => $entry['character'],
					'block'     => $entry['block'],
					'raw'       => $entry['raw'],
					'action'    => 'keep_custom',
				],
			],
		] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );

		$found = Character::find( (int) $data['characters'][0]['id'] );
		$held  = $found->sheet_data['vampire-disciplines'] ?? [];
		$this->assertCount( 1, $held );
		$this->assertSame( 'Thaumaturgy', $held[0]['name'] );
		$this->assertSame( 'Not A Real Path', $held[0]['power_name'] );
		$this->assertTrue( $held[0]['custom'] );
		$this->assertArrayNotHasKey( 'level', $held[0], 'a custom pick has no seeded ladder position, same shape as an Elder+ pick (Decision 037)' );

		$change = \BeyondElysium\Models\Change::for_character( (int) $found->id )[0];
		$this->assertSame( 'import_note', $change->change_type );
		$custom_list = $change->change_data['fuzzy_or_custom'];
		$this->assertSame( 'Thaumaturgy: Not A Real Path', $custom_list[0]['name'] );
	}

	/**
	 * A real export can supply a `keep_custom` power with NO note at all and the held level sitting directly in the raw
	 * total.
	 */
	public function test_committing_with_keep_custom_preserves_a_real_numbered_level_when_no_note_is_present(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [ [ 'name' => 'Dur-An-Ki: Not A Real Power', 'total' => '5', 'note' => '' ] ],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$entry  = $job['preview']['unresolved'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character' => $entry['character'],
					'block'     => $entry['block'],
					'raw'       => $entry['raw'],
					'action'    => 'keep_custom',
				],
			],
		] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );

		$found = Character::find( (int) $data['characters'][0]['id'] );
		$held  = $found->sheet_data['vampire-disciplines'][0];
		$this->assertSame( 'Dur-An-Ki', $held['name'] );
		$this->assertSame( 'Not A Real Power', $held['power_name'] );
		$this->assertSame( 5, $held['level'], 'a clean 1-5 total with no note is a real held level, not an Elder+ pick' );
		$this->assertTrue( $held['custom'] );
	}

	public function test_committing_with_keep_custom_does_not_misread_a_combo_shaped_costs_a_level(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [
				[ 'name' => "Vicente's Holy Shield", 'total' => '3', 'note' => '', 'section' => 'Combination Disciplines' ],
			],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$entry  = $job['preview']['unresolved'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character' => $entry['character'],
					'block'     => $entry['block'],
					'raw'       => $entry['raw'],
					'action'    => 'keep_custom',
				],
			],
		] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );

		$found = Character::find( (int) $data['characters'][0]['id'] );
		$held  = $found->sheet_data['vampire-disciplines'][0];
		$this->assertSame( "Vicente's Holy Shield", $held['power_name'] );
		$this->assertArrayNotHasKey( 'level', $held, 'a combo-section cost must never be misread as a discipline level' );
	}

	public function test_committing_with_keep_custom_does_not_treat_a_cost_as_a_level_when_a_note_is_present(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [ [ 'name' => 'Thaumaturgy: Not A Real Path', 'total' => '3', 'note' => 'basic' ] ],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$entry  = $job['preview']['unresolved'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character' => $entry['character'],
					'block'     => $entry['block'],
					'raw'       => $entry['raw'],
					'action'    => 'keep_custom',
				],
			],
		] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );

		$found = Character::find( (int) $data['characters'][0]['id'] );
		$held  = $found->sheet_data['vampire-disciplines'][0];
		$this->assertArrayNotHasKey( 'level', $held, 'total=3 here is a cost (the note says so), not a level - must not become "level 3"' );
		$this->assertSame( 'basic', $held['tier'] );
	}

	/**
	 * `keep_custom` extended to `trait_list` blocks (Merits/Backgrounds/ Abilities/...).
	 */
	public function test_committing_with_keep_custom_preserves_a_fuzzy_matched_trait_list_entry_as_written(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Of Embrace Fortold' );

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$this->assertNotEmpty( $job['preview']['flagged_traits'], 'this raw name must actually be fuzzy for this test to mean anything' );
		$entry = $job['preview']['flagged_traits'][0];
		$this->assertSame( 'Of Embrace Fortold', $entry['raw'] );

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character' => $entry['character'],
					'block'     => $entry['block'],
					'raw'       => $entry['raw'],
					'action'    => 'keep_custom',
				],
			],
		] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );

		$found = Character::find( (int) $data['characters'][0]['id'] );
		$held  = $found->sheet_data['vampire-merits'][0];
		$this->assertSame(
			'Of Embrace Fortold',
			$held['name'],
			'kept exactly as typed - must NOT have been silently corrected to the fuzzy suggestion "Of Embrace Foretold"'
		);
		$this->assertTrue( $held['custom'] );

		$change = \BeyondElysium\Models\Change::for_character( (int) $found->id )[0];
		$this->assertSame( 'import_note', $change->change_type );
		$this->assertSame( 'Of Embrace Fortold', $change->change_data['fuzzy_or_custom'][0]['name'] );
	}

	public function test_add_to_catalog_creates_a_numbered_ladder_naming_only_the_held_level(): void {
		wp_set_current_user( $this->admin_id ); // administrator: holds be_manage_schemas too.

		$family = 'Test-Only-New-Tradition-' . wp_generate_password( 8, false );
		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [ [ 'name' => "{$family}: New Path", 'total' => '', 'note' => 'basic' ] ],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$entry  = $job['preview']['unresolved'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character'      => $entry['character'],
					'block'          => $entry['block'],
					'raw'            => $entry['raw'],
					'action'         => 'keep_custom',
					'add_to_catalog' => true,
				],
			],
		] );
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$global_names = array_column(
			\BeyondElysium\Models\Schema_Block::find_by_slug( 'vampire-disciplines' )->definition->powers,
			'name'
		);
		$this->assertNotContains( $family, $global_names, 'must never mutate the shared global catalog' );

		$block = \BeyondElysium\Models\Schema_Block::find_for_game( 'vampire-disciplines', $this->game_slug );
		$power = null;
		foreach ( $block->definition->powers as $p ) {
			if ( $p->name === $family ) {
				$power = $p;
				break;
			}
		}
		$this->assertNotNull( $power, 'the new family must exist in this chronicle\'s own fork of the catalog now' );
		$this->assertCount( 5, $power->levels, 'a fresh numbered family gets the real 1-5 ladder, not just the one held level' );

		$by_level = [];
		foreach ( $power->levels as $l ) {
			$by_level[ $l->level ] = $l;
		}
		$this->assertSame( 'basic', $by_level[1]->tier );
		$this->assertSame( '3', $by_level[1]->cost );
		$this->assertSame( 'New Path', $by_level[1]->power_name, 'the FIRST basic slot gets the real held name' );
		$this->assertSame( '', $by_level[2]->power_name, 'the second basic slot stays blank - never invented' );
		$this->assertSame( 'intermediate', $by_level[3]->tier );
		$this->assertSame( '6', $by_level[3]->cost );
		$this->assertSame( '', $by_level[3]->power_name );
		$this->assertSame( 'advanced', $by_level[5]->tier );
		$this->assertSame( '9', $by_level[5]->cost );
		$this->assertSame( '', $by_level[5]->power_name );
	}

	/**
	 * Elder-and-above has no numbered ladder position in real catalog data at all.
	 */
	public function test_add_to_catalog_adds_a_single_pick_for_an_elder_tier_family(): void {
		wp_set_current_user( $this->admin_id );

		$family = 'Test-Only-Elder-Tradition-' . wp_generate_password( 8, false );
		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [ [ 'name' => "{$family}: Elder Path", 'total' => '', 'note' => 'elder' ] ],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$entry  = $job['preview']['unresolved'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character' => $entry['character'], 'block' => $entry['block'], 'raw' => $entry['raw'],
					'action' => 'keep_custom', 'add_to_catalog' => true,
				],
			],
		] );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );

		$block = \BeyondElysium\Models\Schema_Block::find_for_game( 'vampire-disciplines', $this->game_slug );
		$power = current( array_filter( $block->definition->powers, static fn( $p ) => $p->name === $family ) );
		$this->assertNotFalse( $power );
		$this->assertCount( 1, $power->levels );
		$this->assertSame( 'elder', $power->levels[0]->tier );
		$this->assertSame( 'Elder Path', $power->levels[0]->power_name );
		$this->assertObjectNotHasProperty( 'level', $power->levels[0] );
	}

	/**
	 * Bonds import into the Vampire Bonds list, one row per bond with its rating, and are not kept as a note.
	 */
	public function test_bonds_import_into_the_bonds_list_with_their_ratings(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will', [
			'Bonds' => [
				'name'   => 'Bonds',
				'traits' => [
					[ 'name' => 'SOC: Test-Only Talos', 'total' => '9', 'note' => '' ],
					[ 'name' => 'Test-Only Regnant', 'total' => '2', 'note' => 'blood bond' ],
				],
			],
		] );
		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );

		$response = $this->dispatch( $this->commit_request( $job_id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$saved = Character::find( (int) $response->get_data()['characters'][0]['id'] );
		$this->assertEquals(
			[
				[ 'name' => 'SOC: Test-Only Talos', 'count' => 9, 'custom' => true ],
				[ 'name' => 'Test-Only Regnant', 'count' => 2, 'note' => 'blood bond', 'custom' => true ],
			],
			$saved->sheet_data['vampire-bonds'] ?? null
		);

		$changes     = \BeyondElysium\Models\Change::for_character( (int) $saved->id );
		$import_note = current( array_filter( $changes, static fn( $c ) => $c->change_type === 'import_note' ) );
		$kept        = array_column( (array) ( $import_note->change_data['raw_record']['trait_lists'] ?? [] ), 'name' );
		$this->assertNotContains( 'Bonds', $kept );
	}

	/**
	 * A trait labelled as a combo lands in the combo list: the catalog's entry when it holds one, otherwise a custom
	 * combo whose count is the file's value, its cost.
	 */
	public function test_a_combo_in_the_disciplines_list_lands_in_the_combo_list_with_its_cost(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [
				[ 'name' => 'Combo: Test-Only Unlisted Combo (&)', 'total' => '12', 'note' => '' ],
				[ 'name' => 'Combi. Discipline: Draw Fire', 'total' => '12', 'note' => '' ],
				[ 'name' => 'Combo Test-Only Run-In Combo', 'total' => '9', 'note' => '' ],
			],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$this->assertSame( [], $job['preview']['unresolved'], 'a labelled combo never waits on a Storyteller' );

		$response = $this->dispatch( $this->commit_request( $job_id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$saved = Character::find( (int) $response->get_data()['characters'][0]['id'] );
		$this->assertSame(
			[
				[ 'name' => 'Test-Only Unlisted Combo (&)', 'count' => 12, 'custom' => true ],
				[ 'name' => 'Draw Fire', 'count' => 12 ],
				[ 'name' => 'Test-Only Run-In Combo', 'count' => 9, 'custom' => true ],
			],
			$saved->sheet_data['vampire-combo-disciplines'] ?? null
		);
		$this->assertArrayNotHasKey( 'vampire-disciplines', $saved->sheet_data, 'nothing labelled as a combo stays a discipline' );
	}

	/**
	 * A pick the file gives no tier for is added with none, never as elder.
	 */
	public function test_add_to_catalog_adds_a_pick_with_no_tier_when_the_file_names_none(): void {
		wp_set_current_user( $this->admin_id );

		$family = 'Test-Only-Untiered-Tradition-' . wp_generate_password( 8, false );
		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [ [ 'name' => "{$family}: Unknown Path", 'total' => '', 'note' => '' ] ],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$entry  = $job['preview']['unresolved'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character' => $entry['character'], 'block' => $entry['block'], 'raw' => $entry['raw'],
					'action' => 'keep_custom', 'add_to_catalog' => true,
				],
			],
		] );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );

		$block = \BeyondElysium\Models\Schema_Block::find_for_game( 'vampire-disciplines', $this->game_slug );
		$power = current( array_filter( $block->definition->powers, static fn( $p ) => $p->name === $family ) );
		$this->assertNotFalse( $power );
		$this->assertCount( 1, $power->levels );
		$this->assertSame( 'Unknown Path', $power->levels[0]->power_name );
		$this->assertObjectNotHasProperty( 'tier', $power->levels[0] );
	}

	/**
	 * `be_import` alone (what commits an import at all) must NOT be enough to mutate the shared catalog.
	 */
	public function test_add_to_catalog_is_silently_ignored_without_be_manage_schemas(): void {
		$st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		// remove_cap() only clears a PER-USER override.
		( new \WP_User( $st_id ) )->add_cap( 'be_manage_schemas', false );
		wp_set_current_user( $st_id );
		$this->assertTrue( user_can( $st_id, 'be_import' ) );
		$this->assertFalse( user_can( $st_id, 'be_manage_schemas' ), 'the capability must actually be stripped for this test to mean anything' );

		$family = 'Test-Only-Unauthorized-' . wp_generate_password( 8, false );
		$character = $this->synthetic_character( 'Iron Will' );
		$character['trait_lists']['Disciplines'] = [
			'name'   => 'Disciplines',
			'traits' => [ [ 'name' => "{$family}: New Path", 'total' => '', 'note' => 'basic' ] ],
		];

		$job_id = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$job    = get_transient( 'be_import_job_' . $job_id );
		$entry  = $job['preview']['unresolved'][0];

		$request = $this->commit_request( $job_id );
		$request->set_param( 'resolutions', [
			'traits' => [
				[
					'character' => $entry['character'], 'block' => $entry['block'], 'raw' => $entry['raw'],
					'action' => 'keep_custom', 'add_to_catalog' => true,
				],
			],
		] );
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'the character import itself is unaffected by lacking be_manage_schemas' );

		$block = \BeyondElysium\Models\Schema_Block::find_by_slug( 'vampire-disciplines' );
		$found = array_filter( $block->definition->powers, static fn( $p ) => $p->name === $family );
		$this->assertEmpty( $found, 'without be_manage_schemas, the catalog must be completely unchanged' );
	}

	public function test_multi_paragraph_notes_survive_import_as_real_paragraphs(): void {
		wp_set_current_user( $this->admin_id );

		$character = $this->synthetic_character( 'Iron Will' );
		$character['notes'] = "HISTORY\nFirst line of history.\n\nSecond paragraph, a new section.";
		$character['biography'] = "Line one.\nLine two.";

		$job_id   = $this->inject_job( $this->synthetic_parsed( [ $character ] ) );
		$response = $this->dispatch( $this->commit_request( $job_id ) );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );

		$found = Character::find( (int) $data['characters'][0]['id'] );
		$this->assertSame( 2, substr_count( $found->notes, '<p>' ), 'two blank-line-separated sections must become two real paragraphs' );
		$this->assertStringContainsString( 'HISTORY<br', $found->notes, 'a single newline within a paragraph becomes a real line break, not a stripped space' );
		$this->assertStringContainsString( '<p>Line one.<br', $found->biography );
	}
}
