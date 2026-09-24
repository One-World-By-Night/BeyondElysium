<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GEX_Xml_Parser;
use PHPUnit\Framework\TestCase;

/**
 * GEX XML exchange-file parsing, against real XML `.gex` files in this repo.
 */
class GexXmlParserTest extends TestCase {

	private function path( string $relative ): string {
		$path = be_reference_path( $relative );
		// Real players' sample files live in samples/.
		if ( strpos( $relative, 'samples/' ) === 0 && ! file_exists( $path ) ) {
			$this->markTestSkipped( "{$relative} is not present in this checkout." );
		}
		return $path;
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

	public function test_a_trait_with_no_val_attribute_defaults_total_to_one(): void {
		$data  = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Artifacts and Devices.gex' ) );
		$claws = current( array_filter( $data['items'], static fn( $i ) => $i['name'] === 'Claws' ) );

		$this->assertSame(
			[ 'name' => 'Technocracy', 'total' => '1', 'note' => '' ],
			$claws['availability']['traits'][0]
		);
	}

	public function test_no_val_attribute_across_the_real_file_now_defaults_to_one_for_all_21(): void {
		$data = GEX_Xml_Parser::parse_file( $this->path( 'GV301Source/Code/Artifacts and Devices.gex' ) );

		$ones = 0;
		foreach ( $data['items'] as $item ) {
			foreach ( [ 'temper_list', 'ability_list', 'negative_list', 'availability' ] as $list_key ) {
				foreach ( $item[ $list_key ]['traits'] as $trait ) {
					if ( $trait['total'] === '1' ) {
						$ones++;
					}
				}
			}
		}

		$this->assertSame( 21, $ones );
	}

	// -------------------------------------------------------------------------
	// Real character-bearing files
	// -------------------------------------------------------------------------

	public function test_a_real_vampire_export_matches_the_binary_readers_shape(): void {
		$data      = GEX_Xml_Parser::parse_file( $this->path( 'samples/data/laslo_throndsen-vampire.gex' ) );
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
		$this->assertSame( $character['willpower'], $character['temp_willpower'] );

		$this->assertSame( 728.0, $character['experience']['earned'] );
		$this->assertSame( 39.0, $character['experience']['unspent'] );
		$this->assertCount( 99, $character['experience']['history'] );
		$this->assertSame( '2021-08-20 00:00:00', $character['experience']['history'][0]['when'] );

		$this->assertArrayHasKey( 'Disciplines', $character['trait_lists'] );
		$this->assertArrayHasKey( 'Abilities', $character['trait_lists'] );

		// Confirmed genuinely absent from every real XML sample.
		$this->assertSame( '', $character['coterie'] );
		$this->assertSame( '', $character['player'] );
		$this->assertFalse( $character['is_npc'] );
		$this->assertSame( [], $character['boons'] );
	}

	/**
	 * Grapevine writes a vampire's coterie, player, and narrator and a werewolf's player and narrator
	 * (VampireClass.cls:358,380,384; WerewolfClass.cls:369,373).
	 */
	public function test_a_vampire_and_a_werewolf_keep_their_player_narrator_and_coterie(): void {
		$data = GEX_Xml_Parser::parse_string(
			'<?xml version="1.0"?><grapevine version="3.0">' .
			'<vampire name="Marcus" coterie="The Nightwatch" player="Dana Reyes" narrator="Sam"><experience unspent="0" earned="0"/></vampire>' .
			'<werewolf name="Ezra" player="Lee Park" narrator="Jo"><experience unspent="0" earned="0"/></werewolf>' .
			'</grapevine>'
		);

		$this->assertSame( [ 'The Nightwatch', 'Dana Reyes', 'Sam' ], [ $data['characters'][0]['coterie'], $data['characters'][0]['player'], $data['characters'][0]['narrator'] ] );
		$this->assertSame( [ 'Lee Park', 'Jo' ], [ $data['characters'][1]['player'], $data['characters'][1]['narrator'] ] );
	}

	public function test_a_real_werewolf_export_matches_the_binary_readers_shape(): void {
		$data      = GEX_Xml_Parser::parse_file( $this->path( 'samples/data/carrick_macdounagh-werewolf.gex' ) );
		$character = $data['characters'][0];

		$this->assertSame( 'werewolf', $character['race'] );
		$this->assertSame( 'Fianna', $character['tribe'] );
		$this->assertSame( 'Homid', $character['breed'] );
		$this->assertSame( 'Galliard', $character['auspice'] );
		$this->assertSame( 'Adren', $character['rank'] );
		$this->assertSame( 6, $character['rage'] );
		$this->assertSame( 6, $character['gnosis'] );
		$this->assertSame( 6, $character['willpower'] );
		$this->assertSame( 0, $character['honor'] );
		$this->assertSame( 0, $character['glory'] );
		$this->assertSame( 0, $character['wisdom'] );

		$this->assertArrayNotHasKey( 'boons', $character, 'werewolf has no boons key, matching the binary reader\'s own shape - vampire-only field' );
	}

	public function test_a_second_real_vampire_export_cross_validates_against_the_manual_import(): void {
		$data      = GEX_Xml_Parser::parse_file( $this->path( 'samples/data/hitchens-vampire.gex' ) );
		$character = $data['characters'][0];

		$this->assertSame( 'Hitchens', $character['name'] );
		$this->assertSame( 'Pander', $character['clan'] );
		$this->assertSame( 8, $character['generation'] );
		$this->assertSame( 12, $character['willpower'] );
		$this->assertSame( 1491.0, $character['experience']['earned'] );
		$this->assertSame( 79.0, $character['experience']['unspent'] );
	}

	public function test_a_real_export_with_section_divider_rows_does_not_surface_them_as_traits(): void {
		$data        = GEX_Xml_Parser::parse_file( $this->path( 'samples/data/1506_chase_ashford_.gex' ) );
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

		// A real held power sitting immediately either side of a divider row must survive.
		$this->assertContains( 'Watcher Valeren', $names );
		$this->assertContains( 'Dur-An-Ki: Awakening of the Steel', $names );
	}

	public function test_traits_carry_the_section_they_actually_sat_under(): void {
		$data        = GEX_Xml_Parser::parse_file( $this->path( 'samples/data/1506_chase_ashford_.gex' ) );
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

		$this->assertArrayNotHasKey( 'section', $by_name['Fortitude'] ?? [ 'section' => null ] );
		$this->assertArrayNotHasKey( 'section', $by_name['Watcher Valeren'] ?? [ 'section' => null ] );
	}

	// -------------------------------------------------------------------------
	// Defects 2-7 - no real aura/NPC/boon-carrying file exists in this repo
	// -------------------------------------------------------------------------

	private function vampire_xml( string $attributes, string $body = '' ): string {
		return '<?xml version="1.0"?><grapevine version="2.399">' .
			'<vampire name="Defect Fixture" clan="Toreador" sect="Camarilla" ' .
			'blood="10" willpower="7" conscience="3" selfcontrol="3" courage="3" ' .
			'pathtraits="7" physicalmax="5" socialmax="5" mentalmax="5" ' . $attributes . '>' .
			'<experience unspent="0" earned="0"></experience>' . $body .
			'</vampire></grapevine>';
	}

	public function test_npc_yes_reads_as_a_true_is_npc(): void {
		$data = GEX_Xml_Parser::parse_string( $this->vampire_xml( 'npc="yes"' ) );
		$this->assertTrue( $data['characters'][0]['is_npc'] );
	}

	public function test_npc_absent_still_reads_as_false(): void {
		$data = GEX_Xml_Parser::parse_string( $this->vampire_xml( '' ) );
		$this->assertFalse( $data['characters'][0]['is_npc'] );
	}

	public function test_id_attribute_is_read_not_hardcoded_empty(): void {
		$data = GEX_Xml_Parser::parse_string(
			$this->vampire_xml( 'id="https://kony-sabbat.net/be-verify/K3F7-QM2P"' )
		);
		$this->assertSame( 'https://kony-sabbat.net/be-verify/K3F7-QM2P', $data['characters'][0]['id'] );
	}

	public function test_biography_cdata_is_read_not_hardcoded_empty(): void {
		$data = GEX_Xml_Parser::parse_string(
			$this->vampire_xml( '', '<biography><![CDATA[A brief history.]]></biography>' )
		);
		$this->assertSame( 'A brief history.', $data['characters'][0]['biography'] );
	}

	public function test_aura_and_aurabonus_are_read_as_separate_attributes(): void {
		$data = GEX_Xml_Parser::parse_string( $this->vampire_xml( 'aura="Serene" aurabonus="+2"' ) );
		$this->assertSame( 'Serene', $data['characters'][0]['aura'] );
		$this->assertSame( '+2', $data['characters'][0]['aura_bonus'] );
	}

	public function test_aurabonus_absent_defaults_to_the_same_plus_zero_the_writer_will_omit(): void {
		$data = GEX_Xml_Parser::parse_string( $this->vampire_xml( 'aura="Serene"' ) );
		$this->assertSame( '+0', $data['characters'][0]['aura_bonus'] );
	}

	public function test_a_boon_child_is_read_into_the_binary_readers_boon_shape(): void {
		$data = GEX_Xml_Parser::parse_string( $this->vampire_xml(
			'',
			'<boon type="Life" partner="Marcus Vitel" owed="yes" date="6/1/2026 12:00:00 AM">' .
			'<description><![CDATA[Saved from the Sabbat.]]></description></boon>'
		) );

		$this->assertSame(
			[
				'boon_type'   => 'Life',
				'char_name'   => 'Marcus Vitel',
				'is_owed'     => true,
				'boon_date'   => '2026-06-01 00:00:00',
				'description' => 'Saved from the Sabbat.',
			],
			$data['characters'][0]['boons'][0]
		);
	}

	public function test_an_explicitly_empty_val_defaults_to_one_same_as_an_absent_val(): void {
		$data = GEX_Xml_Parser::parse_string(
			'<?xml version="1.0"?><grapevine version="2.396">' .
			'<rote name="Empty Val" level="1" duration="Instant">' .
			'<traitlist name="Spheres" abc="no" atomic="yes" display="5">' .
			'<trait name="Correspondence: Initiate" val="" note="basic"/>' .
			'</traitlist></rote></grapevine>'
		);

		$this->assertSame( '1', $data['rotes'][0]['sphere_list']['traits'][0]['total'] );
	}

	public function test_an_explicit_val_of_zero_is_never_defaulted_to_one(): void {
		$data = GEX_Xml_Parser::parse_string(
			'<?xml version="1.0"?><grapevine version="2.396">' .
			'<rote name="Explicit Zero" level="1" duration="Instant">' .
			'<traitlist name="Spheres" abc="no" atomic="yes" display="5">' .
			'<trait name="Correspondence: Initiate" val="0" note="removed"/>' .
			'</traitlist></rote></grapevine>'
		);

		$this->assertSame( '0', $data['rotes'][0]['sphere_list']['traits'][0]['total'] );
	}

	public function test_no_boon_children_still_reads_as_an_empty_array(): void {
		$data = GEX_Xml_Parser::parse_string( $this->vampire_xml( '' ) );
		$this->assertSame( [], $data['characters'][0]['boons'] );
	}

	public function test_temp_attributes_are_read_when_present_and_differ_from_permanent(): void {
		$data = GEX_Xml_Parser::parse_string( $this->vampire_xml(
			'tempblood="6" tempwillpower="4" tempconscience="1" tempselfcontrol="2" ' .
			'tempcourage="0" temppathtraits="3"'
		) );
		$character = $data['characters'][0];

		$this->assertSame( 10, $character['blood'] );
		$this->assertSame( 6, $character['temp_blood'] );
		$this->assertSame( 7, $character['willpower'] );
		$this->assertSame( 4, $character['temp_willpower'] );
		$this->assertSame( 3, $character['conscience'] );
		$this->assertSame( 1, $character['temp_conscience'] );
		$this->assertSame( 3, $character['self_control'] );
		$this->assertSame( 2, $character['temp_self_control'] );
		$this->assertSame( 3, $character['courage'] );
		$this->assertSame( 0, $character['temp_courage'] );
		$this->assertSame( 7, $character['path_traits'] );
		$this->assertSame( 3, $character['temp_path_traits'] );
	}

	public function test_temp_attributes_absent_still_mirror_permanent(): void {
		$data      = GEX_Xml_Parser::parse_string( $this->vampire_xml( '' ) );
		$character = $data['characters'][0];

		$this->assertSame( $character['blood'], $character['temp_blood'] );
		$this->assertSame( $character['willpower'], $character['temp_willpower'] );
		$this->assertSame( $character['conscience'], $character['temp_conscience'] );
		$this->assertSame( $character['self_control'], $character['temp_self_control'] );
		$this->assertSame( $character['courage'], $character['temp_courage'] );
		$this->assertSame( $character['path_traits'], $character['temp_path_traits'] );
	}

	public function test_werewolf_temp_honor_glory_wisdom_are_read_when_present(): void {
		$data = GEX_Xml_Parser::parse_string(
			'<?xml version="1.0"?><grapevine version="2.399">' .
			'<werewolf name="Defect Fixture" tribe="Fianna" breed="Homid" auspice="Galliard" ' .
			'rage="6" temprage="4" gnosis="6" tempgnosis="5" willpower="6" ' .
			'honor="3" temphonor="1" glory="3" tempglory="2" wisdom="3" tempwisdom="0">' .
			'<experience unspent="0" earned="0"></experience>' .
			'</werewolf></grapevine>'
		);
		$character = $data['characters'][0];

		$this->assertSame( 4, $character['temp_rage'] );
		$this->assertSame( 5, $character['temp_gnosis'] );
		$this->assertSame( 1.0, $character['temp_honor'] );
		$this->assertSame( 2.0, $character['temp_glory'] );
		$this->assertSame( 0.0, $character['temp_wisdom'] );
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
	 * No `<character>` element (or any character-race element) exists in any real GEX XML sample in this repo.
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
