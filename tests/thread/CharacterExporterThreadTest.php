<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\GEX_Xml_Parser;
use WP_UnitTestCase;

/**
 * GX-3/GX-4: `Character_Exporter` against a real vampire character with real
 * `sheet_data` set against this install's own already-seeded global MET
 * catalog (`vampire-identity`, `vampire-resources`, `met-merits`,
 * `vampire-backgrounds` - every install ships these pre-seeded, per the
 * Storyteller Guide; this suite deliberately does not insert its own
 * competing schema_blocks/creature_stacks rows, since the real ones already
 * exist globally and a same-slug insert collides), a real `be_connections`
 * item, a real approved XP history, and a real `import_note` change carrying
 * a `preserve_as_note` list - proving every routing outcome
 * `gex-trait-list-map.php` defines at once, then confirming the whole
 * document round-trips through our own `GEX_Xml_Parser` cleanly.
 *
 * `Toreador`/`Camarilla`/`Humanity`/`Bureaucracy` (Influences)/`Allies`
 * (Backgrounds) are confirmed real entries in the seeded catalog, not
 * fabricated test values - queried directly against the global
 * `vampire-identity`/`vampire-backgrounds` block definitions before writing
 * this test.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-3, GX-4
 */
class CharacterExporterThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-exporter-game';
	private int $character_id;
	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Exporter Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [ 'st_comment_start' => '[ST]', 'st_comment_end' => '[/ST]' ] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->character_id = Character::create( [
			'name' => 'Export Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'status' => 'active', 'narrator' => 'Test ST', 'biography' => 'A test vampire.',
			'notes' => 'Public notes [ST]secret ST-only notes[/ST] end.',
			'sheet_data' => [
				'vampire-identity'    => [ 'Clan' => 'Toreador', 'Sect' => 'Camarilla', 'Generation' => 9, 'Sire' => 'Marcus', 'Title' => 'Harpy', 'Morality Path' => 'Humanity' ],
				'vampire-resources'   => [
					'Blood'     => [ 'permanent' => 10, 'temporary' => 7 ],
					'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ],
					'Morality'  => [ 'permanent' => 7, 'temporary' => 7 ],
				],
				'met-merits'          => [ [ 'name' => 'Common Sense', 'count' => 1 ] ],
				'vampire-backgrounds' => [
					[ 'name' => 'Allies', 'count' => 3 ],
					[ 'name' => 'Bureaucracy', 'count' => 2 ],
				],
			],
		] );

		// A real approved XP-earn event, for the experience-history reconstruction.
		Change_Engine::submit( $this->character_id, [
			'change_type' => 'xp_earn', 'category' => null,
			'change_data' => [ 'amount' => 5, 'reason' => 'Game attendance' ],
			'xp_cost'     => 0,
		], 1 );
		global $wpdb;
		$wpdb->update(
			Manager::table( 'character_changes' ),
			[ 'status' => 'approved', 'reviewed_by' => 1, 'reviewed_at' => current_time( 'mysql' ) ],
			[ 'character_id' => $this->character_id, 'change_type' => 'xp_earn' ]
		);
		$wpdb->update(
			Manager::table( 'characters' ),
			[ 'xp_earned' => 5, 'xp_unspent' => 5 ],
			[ 'id' => $this->character_id ]
		);

		// A real be_connections item, for the world_object (Equipment) routing path.
		$item_id = World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Kevlar Vest',
			'properties' => [ 'item_type' => 'Equipment' ], 'created_by' => 1,
		] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $this->character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'created_by' => 1,
		] );

		// A real import_note change, for the preserve_as_note (Health Levels) backfill path.
		Change_Engine::submit( $this->character_id, [
			'change_type' => 'import_note', 'category' => 'import',
			'change_data' => [
				'source_file' => 'test.gex', 'imported_at' => current_time( 'mysql' ), 'action' => 'created',
				'raw_record'  => [ 'trait_lists' => [
					[ 'name' => 'Health Levels', 'traits' => [
						[ 'name' => 'Bruised', 'total' => '3', 'note' => '' ],
						[ 'name' => 'Wounded', 'total' => '2', 'note' => '' ],
					] ],
				] ],
			],
		], 1 );
	}

	public function test_export_produces_a_document_that_parses_back_through_our_own_reader(): void {
		$result = Character_Exporter::export( $this->character_id );

		$this->assertArrayHasKey( 'xml', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertArrayHasKey( 'transliterations', $result );

		$data      = GEX_Xml_Parser::parse_string( $result['xml'] );
		$character = $data['characters'][0];

		$this->assertSame( 'vampire', $character['race'] );
		$this->assertSame( 'Export Test Vampire', $character['name'] );
	}

	public function test_identity_fields_export_correctly(): void {
		$result    = Character_Exporter::export( $this->character_id );
		$character = GEX_Xml_Parser::parse_string( $result['xml'] )['characters'][0];

		$this->assertSame( 'Toreador', $character['clan'] );
		$this->assertSame( 'Camarilla', $character['sect'] );
		$this->assertSame( 'Marcus', $character['sire'] );
		$this->assertSame( 'Harpy', $character['title'] );
		$this->assertSame( 'Humanity', $character['path'] );
	}

	public function test_resource_pools_export_permanent_and_temporary_separately(): void {
		$result    = Character_Exporter::export( $this->character_id );
		$character = GEX_Xml_Parser::parse_string( $result['xml'] )['characters'][0];

		$this->assertSame( 10, $character['blood'] );
		$this->assertSame( 7, $character['temp_blood'] );
		$this->assertSame( 6, $character['willpower'] );
	}

	public function test_a_sheet_block_trait_list_exports_its_held_merit(): void {
		$result    = GEX_Xml_Parser::parse_string( Character_Exporter::export( $this->character_id )['xml'] );
		$merits    = $result['characters'][0]['trait_lists']['Merits']['traits'];

		$this->assertSame( [ 'name' => 'Common Sense', 'total' => '1', 'note' => '' ], $merits[0] );
	}

	public function test_influences_and_backgrounds_split_the_same_block_by_catalog_source(): void {
		$result      = GEX_Xml_Parser::parse_string( Character_Exporter::export( $this->character_id )['xml'] );
		$trait_lists = $result['characters'][0]['trait_lists'];

		$this->assertSame( [ 'Bureaucracy' ], array_column( $trait_lists['Influences']['traits'], 'name' ) );
		$this->assertSame( [ 'Allies' ], array_column( $trait_lists['Backgrounds']['traits'], 'name' ) );
	}

	public function test_a_connected_item_exports_as_an_equipment_trait(): void {
		$result   = GEX_Xml_Parser::parse_string( Character_Exporter::export( $this->character_id )['xml'] );
		$equipment = $result['characters'][0]['trait_lists']['Equipment']['traits'];

		$this->assertSame( [ 'Kevlar Vest' ], array_column( $equipment, 'name' ) );
	}

	public function test_health_levels_is_backfilled_from_the_import_note_raw_record(): void {
		$result = GEX_Xml_Parser::parse_string( Character_Exporter::export( $this->character_id )['xml'] );
		$health = $result['characters'][0]['trait_lists']['Health Levels']['traits'];

		$this->assertSame( [ 'Bruised', 'Wounded' ], array_column( $health, 'name' ) );
	}

	public function test_xp_totals_and_history_reflect_the_real_approved_earn(): void {
		$result    = GEX_Xml_Parser::parse_string( Character_Exporter::export( $this->character_id )['xml'] );
		$character = $result['characters'][0];

		$this->assertSame( 5.0, $character['experience']['earned'] );
		$this->assertSame( 5.0, $character['experience']['unspent'] );
		$this->assertCount( 1, $character['experience']['history'] );
		$this->assertSame( 0, $character['experience']['history'][0]['change_type'] ); // ecEarned
		$this->assertSame( 5.0, $character['experience']['history'][0]['change'] );
	}

	public function test_hide_st_strips_st_only_text_from_notes(): void {
		$result = Character_Exporter::export( $this->character_id, [ 'hide_st' => true ] );
		$this->assertStringNotContainsString( 'secret ST-only notes', $result['xml'] );
		$this->assertStringContainsString( 'Public notes', $result['xml'] );

		$full = Character_Exporter::export( $this->character_id, [ 'hide_st' => false ] );
		$this->assertStringContainsString( 'secret ST-only notes', $full['xml'] );
	}

	public function test_without_verify_the_id_field_is_always_empty(): void {
		$xml       = Character_Exporter::export( $this->character_id )['xml'];
		$character = GEX_Xml_Parser::parse_string( $xml )['characters'][0];

		$this->assertSame( '', $character['id'] );
		$this->assertStringNotContainsString( '<verification', $xml );
	}

	public function test_verify_embeds_a_working_code_and_a_verification_element(): void {
		$xml = Character_Exporter::export( $this->character_id, [ 'verify' => true ] )['xml'];

		$character = GEX_Xml_Parser::parse_string( $xml )['characters'][0];
		$this->assertNotSame( '', $character['id'], 'the id field must carry the verification URL' );
		$this->assertStringContainsString( 'be-verify', $character['id'] );

		$this->assertMatchesRegularExpression( '#<verification url="[^"]+" issued="[^"]+"/>#', $xml );

		preg_match( '/code=([A-Za-z0-9-]+)/', $character['id'], $m );
		$this->assertNotEmpty( $m[1] ?? null );
		$this->assertNotNull( \BeyondElysium\Models\Attestation::resolve( $m[1] ), 'the embedded code must resolve to a real attestation' );
	}

	public function test_verify_mints_a_fresh_code_on_every_call(): void {
		$first  = Character_Exporter::export( $this->character_id, [ 'verify' => true ] )['xml'];
		$second = Character_Exporter::export( $this->character_id, [ 'verify' => true ] )['xml'];

		preg_match( '/code=([A-Za-z0-9-]+)/', $first, $m1 );
		preg_match( '/code=([A-Za-z0-9-]+)/', $second, $m2 );

		$this->assertNotSame( $m1[1], $m2[1] );
	}

	public function test_verify_still_produces_a_document_that_parses_back_through_our_own_reader(): void {
		$xml       = Character_Exporter::export( $this->character_id, [ 'verify' => true ] )['xml'];
		$character = GEX_Xml_Parser::parse_string( $xml )['characters'][0];

		// Everything else in the document is unaffected by verify - same identity, same trait lists.
		$this->assertSame( 'Export Test Vampire', $character['name'] );
		$this->assertSame( 'Toreador', $character['clan'] );
	}
}
