<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GEX_Xml_Parser;
use PHPUnit\Framework\TestCase;

/**
 * GEX XML exchange-file parsing (workflow-0.8.md Step 9), against real XML `.gex` files in
 * this repo - `GexParserTest`'s own doc comment already flagged the original two (Rotes,
 * Artifacts and Devices) as carrying the `.gex` extension while actually being `<?xml`
 * documents, out of scope for the binary parser.
 *
 * Three more real samples arrived 2026-09-10 (Decision 068), all character-bearing - two
 * `<vampire>`, one `<werewolf>` - closing the gap the class's own history records: no
 * character-bearing XML sample existed when it originally shipped, so it only ever
 * implemented `<item>`/`<rote>`. The refusal path for every OTHER root element (still no
 * real sample for the other 10 `RaceType`s) is exercised against a hand-built document,
 * not a real one - matching `GexParserTest`'s own honesty about its synthetic fixtures.
 *
 * @see BE_PROCESS/workflow-0.8.md Step 9
 * @see BE_PROCESS/DECISIONLOG.md Decision 068
 */
class GexXmlParserTest extends TestCase {

	private function path( string $relative ): string {
		return BE_PLUGIN_ROOT . '/' . $relative;
	}

	// -------------------------------------------------------------------------
	// Real files
	// -------------------------------------------------------------------------

	public function test_rotes_gex_parses_to_201_rotes_and_zero_items(): void {
		$data = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Rotes.gex' ) );

		$this->assertSame( 2.396, $data['version'] );
		$this->assertCount( 201, $data['rotes'] );
		$this->assertCount( 0, $data['items'] );
		$this->assertSame( [], $data['characters'] );
		$this->assertSame( [], $data['players'] );
		$this->assertNull( $data['calendar'] );
	}

	public function test_a_rote_with_a_simple_trait_matches_the_real_shape(): void {
		$data = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Rotes.gex' ) );
		$rote = $data['rotes'][0];

		$this->assertSame( 'Access This', $rote['name'] );
		$this->assertSame( 2, $rote['level'] );
		$this->assertSame( 'One Scene or Hour', $rote['duration'] );
		$this->assertSame( 'Laws of Ascension Companion p. 137', $rote['description'] );
		$this->assertSame( '', $rote['grades'], 'grades has no XML attribute at all - emitted as empty string, never omitted' );
		$this->assertSame( '2002-11-20 00:13:04', $rote['last_modified'] );

		$spheres = $rote['sphere_list'];
		$this->assertSame( 'Spheres', $spheres['name'] );
		$this->assertFalse( $spheres['alphabetized'] );
		$this->assertTrue( $spheres['atomic'] );
		$this->assertFalse( $spheres['negative'] );
		$this->assertSame( 5, $spheres['display'] );
		$this->assertSame(
			[ 'name' => 'Correspondence: Initiate', 'total' => '3', 'note' => 'basic' ],
			$spheres['traits'][0]
		);
	}

	public function test_a_rote_with_a_multi_trait_sphere_list_captures_every_trait(): void {
		$data = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Rotes.gex' ) );
		$rote = current( array_filter( $data['rotes'], static fn( $r ) => $r['name'] === 'Activate Next Clone' ) );

		$this->assertNotFalse( $rote );
		$this->assertCount( 6, $rote['sphere_list']['traits'] );
		$this->assertSame(
			[ 'name' => 'Life: Master', 'total' => '9', 'note' => 'adv.' ],
			$rote['sphere_list']['traits'][0]
		);
	}

	public function test_artifacts_and_devices_gex_parses_to_34_items(): void {
		$data = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Artifacts and Devices.gex' ) );

		$this->assertSame( 2.396, $data['version'] );
		$this->assertCount( 34, $data['items'] );
		$this->assertCount( 0, $data['rotes'] );
	}

	public function test_an_item_carries_all_four_named_trait_lists_by_name_not_position(): void {
		$data = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Artifacts and Devices.gex' ) );
		$item = $data['items'][0];

		$this->assertSame( "Baby's New Shoes Dice", $item['name'] );
		$this->assertSame( 'Artifact', $item['item_type'] );
		$this->assertSame( '', $item['item_subtype'], 'not every item has a subtype attribute' );
		$this->assertSame( 2, $item['level'] );
		$this->assertSame( 0, $item['bonus'] );
		$this->assertSame( '', $item['damage_type'] );
		$this->assertSame( 0, $item['damage_amount'] );
		$this->assertSame( 'Pocket', $item['concealability'] );
		$this->assertSame( 'Laws of Ascension Companion p. 126', $item['powers'] );
		$this->assertSame( '', $item['appearance'], 'appearance has no XML attribute at all - emitted as empty string, never omitted' );
		$this->assertSame( '', $item['notes'], 'notes has no XML attribute at all - emitted as empty string, never omitted' );
		$this->assertSame( '2002-11-19 22:17:11', $item['last_modified'] );

		$this->assertSame( 'Tempers', $item['temper_list']['name'] );
		$this->assertSame( 'Abilities', $item['ability_list']['name'] );
		$this->assertSame( 'Negatives', $item['negative_list']['name'] );
		$this->assertTrue( $item['negative_list']['negative'] );
		$this->assertSame( 'Availability', $item['availability']['name'] );
	}

	public function test_an_item_with_subtype_and_damage_populated_reads_them(): void {
		$data = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Artifacts and Devices.gex' ) );
		$claws = current( array_filter( $data['items'], static fn( $i ) => $i['name'] === 'Claws' ) );

		$this->assertNotFalse( $claws );
		$this->assertSame( 'Device', $claws['item_type'] );
		$this->assertSame( 'Enhancement', $claws['item_subtype'] );
		$this->assertSame( 'Lethal', $claws['damage_type'] );
		$this->assertSame( 1, $claws['damage_amount'] );
	}

	public function test_a_trait_with_no_val_or_note_attribute_reads_as_empty_strings(): void {
		// Real data, not a guess: <trait name="Technocracy"/> in the real file has
		// neither val= nor note= at all.
		$data  = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Artifacts and Devices.gex' ) );
		$claws = current( array_filter( $data['items'], static fn( $i ) => $i['name'] === 'Claws' ) );

		$this->assertSame(
			[ 'name' => 'Technocracy', 'total' => '', 'note' => '' ],
			$claws['availability']['traits'][0]
		);
	}

	// -------------------------------------------------------------------------
	// Real character-bearing files (Decision 068)
	// -------------------------------------------------------------------------

	public function test_a_real_vampire_export_matches_the_binary_readers_shape(): void {
		$data      = GEX_Xml_Parser::parse_file( $this->path( 'data-samples/laslo_throndsen-vampire.gex' ) );
		$character = $data['characters'][0];

		$this->assertCount( 1, $data['characters'] );
		$this->assertSame( 'vampire', $character['race'] );
		$this->assertSame( 'Laslo Throndsen', $character['name'] );
		$this->assertSame( 'Tremere', $character['clan'] );
		$this->assertSame( 'Camarilla', $character['sect'] );
		$this->assertSame( 11, $character['generation'] );
		$this->assertSame( 'Humanity', $character['path'] );
		$this->assertSame( 6, $character['path_traits'] );
		$this->assertSame( 12, $character['blood'] );
		$this->assertSame( 6, $character['willpower'] );
		// temp_* mirrors the permanent value - the same fallback the binary reader itself
		// uses for a source with no separate temp field.
		$this->assertSame( $character['willpower'], $character['temp_willpower'] );

		$this->assertSame( 728.0, $character['experience']['earned'] );
		$this->assertSame( 39.0, $character['experience']['unspent'] );
		$this->assertCount( 99, $character['experience']['history'] );
		// The date-only bug this decision found and fixed: a history entry dated only
		// "08/20/2021" must read as real midnight, not whatever time the test happened to
		// run at (DateTime::createFromFormat() silently fills unspecified fields from the
		// current system time without a leading `!`).
		$this->assertSame( '2021-08-20 00:00:00', $character['experience']['history'][0]['when'] );

		$this->assertArrayHasKey( 'Disciplines', $character['trait_lists'] );
		$this->assertArrayHasKey( 'Abilities', $character['trait_lists'] );

		// Confirmed genuinely absent from every real XML sample - emitted as the same
		// empty/false defaults the binary reader uses when its own source lacks them,
		// never omitted.
		$this->assertSame( '', $character['coterie'] );
		$this->assertSame( '', $character['player'] );
		$this->assertFalse( $character['is_npc'] );
		$this->assertSame( [], $character['boons'] );
	}

	public function test_a_real_werewolf_export_matches_the_binary_readers_shape(): void {
		$data      = GEX_Xml_Parser::parse_file( $this->path( 'data-samples/carrick_macdounagh-werewolf.gex' ) );
		$character = $data['characters'][0];

		$this->assertSame( 'werewolf', $character['race'] );
		$this->assertSame( 'Fianna', $character['tribe'] );
		$this->assertSame( 'Homid', $character['breed'] );
		$this->assertSame( 'Galliard', $character['auspice'] );
		$this->assertSame( 'Adren', $character['rank'] );
		$this->assertSame( 6, $character['rage'] );
		$this->assertSame( 6, $character['gnosis'] );
		$this->assertSame( 6, $character['willpower'] );
		// Real-data quirk confirmed directly (not assumed): this sample carries
		// honor=""/glory="" but no `wisdom` attribute at all - both resolve to 0 with no
		// special-casing needed, SimpleXML already treats a missing attribute and an
		// empty one identically under (int)/(float) casts.
		$this->assertSame( 0, $character['honor'] );
		$this->assertSame( 0, $character['glory'] );
		$this->assertSame( 0, $character['wisdom'] );

		$this->assertArrayNotHasKey( 'boons', $character, 'werewolf has no boons key, matching the binary reader\'s own shape - vampire-only field' );
	}

	public function test_a_second_real_vampire_export_cross_validates_against_the_manual_import(): void {
		// Hitchens exists in this project's own seeded data too, manually imported from a
		// PDF (working.md/DECISIONLOG's own record) with XP 1491/79 - "the user's own
		// choice between two conflicting source figures." This real .gex export matches
		// that figure exactly, independent corroboration this parser reads the real value
		// correctly, not just that it doesn't crash.
		$data      = GEX_Xml_Parser::parse_file( $this->path( 'data-samples/hitchens-vampire.gex' ) );
		$character = $data['characters'][0];

		$this->assertSame( 'Hitchens', $character['name'] );
		$this->assertSame( 'Pander', $character['clan'] );
		$this->assertSame( 8, $character['generation'] );
		// The exact real-world case the user's own generation-cap defect report named:
		// 8th generation, Willpower 12 - confirms the parser reads the real stored value
		// rather than something already capped upstream.
		$this->assertSame( 12, $character['willpower'] );
		$this->assertSame( 1491.0, $character['experience']['earned'] );
		$this->assertSame( 79.0, $character['experience']['unspent'] );
	}

	/**
	 * Decision 074: a real PuppetPrince export (a third-party MET character tracker,
	 * confirmed against this same character's own printed sheet, `data-samples/Chase
	 * Ashford.pdf`) inserts zero-value, em-dash-wrapped pseudo-traits into a long
	 * Disciplines list purely to group it for a human reader - `"——Blood Magic——"`,
	 * `"——Combination Disciplines——"` - with no game-mechanical meaning at all. Before
	 * this fix both rows surfaced as real traits needing an ST's manual review.
	 */
	public function test_a_real_export_with_section_divider_rows_does_not_surface_them_as_traits(): void {
		$data        = GEX_Xml_Parser::parse_file( $this->path( 'data-samples/1506_chase_ashford_.gex' ) );
		$character   = $data['characters'][0];
		$disciplines = $character['trait_lists']['Disciplines']['traits'];

		$this->assertSame( 'Chase Ashford', trim( $character['name'] ) );

		$names = array_column( $disciplines, 'name' );
		foreach ( $names as $name ) {
			$this->assertDoesNotMatchRegularExpression(
				'/^\x{2014}{2,}.*\x{2014}{2,}$/u',
				$name,
				'a section-header row must never reach the parsed trait list'
			);
		}

		// A real held power sitting immediately either side of a divider row must
		// survive - proves the filter removes exactly the header rows, not a whole
		// neighboring range by mistake.
		$this->assertContains( 'Watcher Valeren', $names );
		$this->assertContains( 'Dur-An-Ki: Awakening of the Steel', $names );
	}

	/**
	 * workflow-0.9.md Step 0e: the divider row is still dropped (Decision 074, proven
	 * above), but its label now survives as every following trait's own `section` - real
	 * fix for "Vicente de las Navas de Tolosa's Holy Shield" (a real combo, cost 3)
	 * previously misread as "level 3" of a discipline family, since nothing told the
	 * level-derivation heuristic it came from a combo section rather than a real ladder.
	 */
	public function test_traits_carry_the_section_they_actually_sat_under(): void {
		$data        = GEX_Xml_Parser::parse_file( $this->path( 'data-samples/1506_chase_ashford_.gex' ) );
		$disciplines = $data['characters'][0]['trait_lists']['Disciplines']['traits'];
		$by_name     = [];
		foreach ( $disciplines as $trait ) {
			$by_name[ $trait['name'] ] = $trait;
		}

		$this->assertSame( 'Blood Magic', $by_name['Dur-An-Ki: Awakening of the Steel']['section'] ?? null );
		$this->assertSame(
			'Combination Disciplines',
			$by_name["Vicente de las Navas de Tolosa's Holy Shield"]['section'] ?? null
		);

		// Real, ordinary Disciplines (Fortitude) and "Watcher Valeren" both sit BEFORE the
		// first divider in this real file - confirmed directly, not assumed - so both must
		// carry no `section` at all, not an empty string or a stale value from elsewhere.
		$this->assertArrayNotHasKey( 'section', $by_name['Fortitude'] ?? [ 'section' => null ] );
		$this->assertArrayNotHasKey( 'section', $by_name['Watcher Valeren'] ?? [ 'section' => null ] );
	}

	// -------------------------------------------------------------------------
	// Synthetic documents - no real fixture covers these
	// -------------------------------------------------------------------------

	public function test_rejects_a_non_grapevine_document(): void {
		$this->expectException( \RuntimeException::class );
		GEX_Xml_Parser::parse_string( '<?xml version="1.0"?><notgrapevine version="1.0"></notgrapevine>' );
	}

	public function test_rejects_malformed_xml(): void {
		$this->expectException( \RuntimeException::class );
		GEX_Xml_Parser::parse_string( '<?xml version="1.0"?><grapevine version="2.396">' );
	}

	/**
	 * No `<character>` element (or any character-race element) exists in any real GEX
	 * XML sample in this repo. Refusing loudly rather than silently skipping the element
	 * is the whole point (workflow-0.8.md Step 9c) - a silent skip would let a
	 * character-bearing file "succeed" as a clean, empty import.
	 */
	public function test_an_unrecognized_element_throws_by_name_rather_than_silently_skipping(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/<character>/' );
		GEX_Xml_Parser::parse_string(
			'<?xml version="1.0"?><grapevine version="2.396"><character name="Test"/></grapevine>'
		);
	}

	public function test_a_rote_or_item_missing_a_traitlist_entirely_falls_back_to_empty(): void {
		$data = GEX_Xml_Parser::parse_string(
			'<?xml version="1.0"?><grapevine version="2.396">' .
			'<rote name="No Spheres" level="1" duration="Instant"></rote>' .
			'</grapevine>'
		);

		$this->assertSame( 'Spheres', $data['rotes'][0]['sphere_list']['name'] );
		$this->assertSame( [], $data['rotes'][0]['sphere_list']['traits'] );
	}

	public function test_an_unparseable_lastmodified_value_is_null_not_an_error(): void {
		$data = GEX_Xml_Parser::parse_string(
			'<?xml version="1.0"?><grapevine version="2.396">' .
			'<rote name="Bad Date" level="1" duration="Instant" lastmodified="not a date"></rote>' .
			'</grapevine>'
		);

		$this->assertNull( $data['rotes'][0]['last_modified'] );
	}
}
