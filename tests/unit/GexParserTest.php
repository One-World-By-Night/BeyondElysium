<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GV_Binary_Reader;
use BeyondElysium\Services\GEX_Parser;
use PHPUnit\Framework\TestCase;

/**
 * GVBE exchange-file parsing, against real Grapevine files in this repo plus a handful
 * of hand-built synthetic buffers for cases no real fixture covers.
 *
 * Real fixtures used:
 *   GV301Source/Code/New Game Items.gex        version 2.396 - no APR/XP-award/template
 *                                               section, 46 items
 *   GV301Source/Code/Fetishes and Talens.gex    version 2.397 - has the section (all
 *                                               three counts happen to be 0 in this file)
 *
 * None of `New Game Items.gex`, `Fetishes and Talens.gex`, or `Dark Ages Arsenal.gex`
 * (also real GVBE binary, version 2.399) contains any character records - all three are
 * pure item exchanges. `Artifacts and Devices.gex` and `Rotes.gex` also carry the `.gex`
 * extension but are actually XML (`<?xml` header, not `GVBE`), out of scope for this
 * binary parser.
 *
 * `data-samples/Sabbat.gex` (added 2026-09-10, GVBE binary, version 3.0) closes the gap
 * this docblock used to describe as permanent: it is a real character-bearing binary
 * exchange - 1 vampire ("Ian Kincaid II", 20 trait lists), 1 item, 1 location, 1 player -
 * and is used below as the real fixture for the binary character-record path. The
 * remaining hand-built synthetic byte buffers cover cases this one real file doesn't
 * reach (other races, other version-gated branches) - per workflow-0.8.md Step 2h's "real
 * fixtures, not mocks" rule, synthetic bytes are used only where no real fixture exists,
 * not as a substitute for one that does.
 *
 * @see BE_PROCESS/workflow-0.8.md Step 2
 */
class GexParserTest extends TestCase {

	private function path( string $relative ): string {
		return BE_PLUGIN_ROOT . '/' . $relative;
	}

	// -------------------------------------------------------------------------
	// Real files
	// -------------------------------------------------------------------------

	/**
	 * The first, and only, real character-bearing binary exchange file in this repo
	 * (added 2026-09-10). Version 3.0 - exactly what a writer emits (GX-1/GX-2) - with all
	 * 20 vampire trait lists present, in exactly `VampireClass.Initialize()`'s declared
	 * order, giving GX-1's shape table a byte-exact real fixture to prove itself against
	 * before any writer trusts a row of it.
	 */
	public function test_sabbat_gex_parses_a_real_binary_vampire_with_all_20_trait_lists(): void {
		$data = GEX_Parser::parse_file( $this->path( 'data-samples/Sabbat.gex' ) );

		$this->assertSame( 3.0, $data['version'] );
		$this->assertCount( 1, $data['characters'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertCount( 1, $data['locations'] );
		$this->assertCount( 1, $data['players'] );

		$character = $data['characters'][0];
		$this->assertSame( 'vampire', $character['race'] );
		$this->assertSame( 'Ian Kincaid II', $character['name'] );
		$this->assertFalse( $character['is_npc'] );
		$this->assertSame( [], $character['boons'] );

		$this->assertSame(
			[
				'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social',
				'Negative Mental', 'Status', 'Abilities', 'Influences', 'Backgrounds',
				'Health Levels', 'Bonds', 'Miscellaneous', 'Derangements', 'Disciplines',
				'Rituals', 'Merits', 'Flaws', 'Equipment', 'Locations',
			],
			array_keys( $character['trait_lists'] )
		);
	}

	public function test_new_game_items_parses_to_46_items(): void {
		$data = GEX_Parser::parse_file( $this->path( 'GV301Source/Code/New Game Items.gex' ) );

		$this->assertSame( 2.396, $data['version'] );
		$this->assertCount( 46, $data['items'] );

		$first = $data['items'][0];
		$this->assertSame( '(Artifacts and Devices)', $first['name'] );
		$this->assertSame( 'Artifact', $first['item_type'] );
		$this->assertSame( 'User Information', $first['item_subtype'] );

		// The trait lists on this first item, for real coverage of parse_trait_list()
		// beyond just the count.
		$this->assertSame( 'Tempers', $first['temper_list']['name'] );
		$this->assertTrue( $first['temper_list']['alphabetized'] );
		$this->assertCount( 1, $first['temper_list']['traits'] );
		$this->assertSame( 'Click the "Details" Tab!', $first['temper_list']['traits'][0]['name'] );
		$this->assertSame( 'Negatives', $first['negative_list']['name'] );
		$this->assertTrue( $first['negative_list']['negative'] );

		// A 2.396 file has no APR/XP-award/template section at all.
		$this->assertNull( $data['calendar'] );
		$this->assertNull( $data['apr_engine'] );
		$this->assertSame( [], $data['experience_awards'] );
		$this->assertSame( [], $data['templates'] );

		// This particular fixture happens to carry no characters, players, or other
		// world objects - a pure item exchange.
		$this->assertSame( [], $data['characters'] );
	}

	public function test_fetishes_and_talens_is_a_2397_file_with_the_extra_section_read(): void {
		$data = GEX_Parser::parse_file( $this->path( 'GV301Source/Code/Fetishes and Talens.gex' ) );

		$this->assertSame( 2.397, $data['version'] );

		// The section exists structurally in a 2.397 file (the counts are read), even
		// though this particular fixture's counts all happen to be zero. The direct
		// proof that a 2.396 buffer skips these reads entirely - not just that this one
		// real file's counts are zero - is in the synthetic version-gating test below.
		$this->assertNull( $data['calendar'] );
		$this->assertNull( $data['apr_engine'] );
		$this->assertSame( [], $data['experience_awards'] );
		$this->assertSame( [], $data['templates'] );

		$this->assertNotEmpty( $data['items'] );
	}

	public function test_dark_ages_arsenal_is_a_real_2399_file_and_parses_clean(): void {
		// Exercises the deepest version threshold (2.399) end-to-end against a real
		// file, even though (like the other two) it carries no character records.
		$data = GEX_Parser::parse_file( $this->path( 'GV301Source/Code/Dark Ages Arsenal.gex' ) );

		$this->assertSame( 2.399, $data['version'] );
		$this->assertNotEmpty( $data['items'] );
		$this->assertSame( [], $data['characters'] );
	}

	// -------------------------------------------------------------------------
	// Version gating (synthetic - proves the branch taken, not just that a real
	// file's counts happened to be zero)
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal top-level GVBE buffer for a given version with every count
	 * zero, honoring the real section-presence rules from
	 * GameClass.LoadExchangeBinary (GameClass.cls lines 536-790):
	 *
	 *   >= 2.395            calendar count
	 *   >= 2.397 (also)     APR count, XP-award count, template count
	 *   always              player, character, query, item, rote, location, action,
	 *                       plot, rumor counts
	 */
	private function build_minimal_buffer( float $version ): string {
		$buf  = pack( 'v', 4 ) . 'GVBE';
		$buf .= pack( 'e', $version );

		if ( $version >= 2.395 ) {
			$buf .= pack( 'v', 0 ); // calendar count
			if ( $version >= 2.397 ) {
				$buf .= pack( 'v', 0 ); // APR count
				$buf .= pack( 'v', 0 ); // XP-award count
				$buf .= pack( 'v', 0 ); // template count
			}
		}

		// player, character, query, item, rote, location, action, plot, rumor
		$buf .= str_repeat( pack( 'v', 0 ), 9 );

		return $buf;
	}

	public function test_a_2396_buffer_does_not_read_the_2397_only_section(): void {
		$buf    = $this->build_minimal_buffer( 2.396 );
		$reader = new GV_Binary_Reader( $buf );

		$data = GEX_Parser::parse_binary( $reader );

		$this->assertSame( 2.396, $data['version'] );
		$this->assertNull( $data['calendar'] );
		$this->assertNull( $data['apr_engine'] );

		// If parse_binary() had wrongly tried to read the three 2.397-only counts on
		// this 2.396 buffer, it would either run past the end of a too-short buffer
		// (RuntimeException) or, since this buffer only has the 2.396-shaped bytes,
		// misread the real player/character/... counts as those three extra fields -
		// either way the stream would desynchronize and the trailing eof() check in
		// parse_binary() would fail. Reaching this line at all is half the proof;
		// asserting eof()/tell() directly is the other half the task calls for.
		$this->assertTrue( $reader->eof(), 'A correct 2.396 parse must consume every byte.' );
		$this->assertSame( $reader->size(), $reader->tell() );
	}

	public function test_a_2397_buffer_does_read_the_extra_section(): void {
		$buf    = $this->build_minimal_buffer( 2.397 );
		$reader = new GV_Binary_Reader( $buf );

		$data = GEX_Parser::parse_binary( $reader );

		$this->assertSame( 2.397, $data['version'] );
		// Calendar count was 0, so no CalendarClass was read, but the count field
		// itself still had to be consumed to reach this point without desyncing.
		$this->assertNull( $data['calendar'] );
		$this->assertNull( $data['apr_engine'] );
		$this->assertSame( [], $data['experience_awards'] );
		$this->assertSame( [], $data['templates'] );

		$this->assertTrue( $reader->eof() );
		$this->assertSame( $reader->size(), $reader->tell() );
	}

	public function test_a_2396_buffer_is_shorter_than_the_equivalent_2397_buffer(): void {
		// Directly confirms the two buffers differ by exactly the three int16 fields
		// the 2.397-only section adds - the most literal form of "the extra section is
		// read" the task asks for.
		$buf_2396 = $this->build_minimal_buffer( 2.396 );
		$buf_2397 = $this->build_minimal_buffer( 2.397 );

		$this->assertSame( strlen( $buf_2396 ) + 6, strlen( $buf_2397 ) );
	}

	// -------------------------------------------------------------------------
	// Character record dispatch (synthetic - no real fixture in this repo has any
	// character data at all, see class doc comment)
	// -------------------------------------------------------------------------

	private function build_string( string $s ): string {
		return pack( 'v', strlen( $s ) ) . $s;
	}

	private function build_bool( bool $b ): string {
		return pack( 'v', $b ? 0xFFFF : 0 );
	}

	private function build_int16( int $v ): string {
		return pack( 'v', $v & 0xFFFF );
	}

	private function build_int32( int $v ): string {
		return pack( 'V', $v & 0xFFFFFFFF );
	}

	private function build_double( float $v ): string {
		return pack( 'e', $v );
	}

	private function build_single( float $v ): string {
		return pack( 'g', $v );
	}

	/**
	 * An empty LinkedTraitList: name, three bools, one int32 display, zero-count.
	 */
	private function build_empty_trait_list( string $name ): string {
		return $this->build_string( $name )
			. $this->build_bool( false )
			. $this->build_bool( false )
			. $this->build_bool( false )
			. $this->build_int32( 1 )
			. $this->build_int16( 0 );
	}

	private function build_experience(): string {
		// Unspent, Earned (both Single), history count 0.
		return $this->build_single( 0.0 ) . $this->build_single( 0.0 ) . $this->build_int16( 0 );
	}

	/**
	 * The Player/Status/ID/StartDate/Narrator/IsNPC/LastModified block every
	 * character class ends its identity section with. At version 2.399 (>= 2.397),
	 * StartDate is a plain date read on every class, including HunterClass/
	 * DemonClass's unconditional read - so this one helper is valid for all twelve.
	 */
	private function build_id_block(): string {
		return $this->build_string( 'Player' )
			. $this->build_string( 'Active' )
			. $this->build_string( 'ID' )
			. $this->build_double( 0.0 ) // StartDate
			. $this->build_string( 'Narrator' )
			. $this->build_bool( false ) // IsNPC
			. $this->build_double( 0.0 ); // LastModified
	}

	/**
	 * @param string[] $names
	 */
	private function build_trait_lists( array $names ): string {
		$buf = '';
		foreach ( $names as $name ) {
			$buf .= $this->build_empty_trait_list( $name );
		}
		return $buf;
	}

	/**
	 * A full synthetic VampireClass record at version 2.399 - the deepest
	 * version-gating of any character class (VampireClass.cls lines 640-762) - to
	 * exercise every conditional branch and the trailing BoonClass loop end to end.
	 */
	private function build_vampire_character( float $version = 2.399 ): string {
		$buf  = $this->build_int16( 2 ); // gvRaceVampire
		$buf .= $this->build_string( 'Marcus Vitel' );  // Name
		$buf .= $this->build_string( 'Architect' );      // Nature
		$buf .= $this->build_string( 'Autocrat' );       // Demeanor
		$buf .= $this->build_string( 'Ventrue' );        // Clan
		$buf .= $this->build_string( 'Camarilla' );      // Sect
		if ( $version >= 2.395 ) {
			$buf .= $this->build_string( 'Primogen Council' ); // Coterie
		}
		if ( $version >= 2.399 ) {
			$buf .= $this->build_string( 'Marcus the Elder' ); // Sire
		}
		$buf .= $this->build_int16( 8 );                // Generation
		$buf .= $this->build_string( 'Prince' );          // Title

		if ( $version >= 2.397 ) {
			$buf .= $this->build_int16( 10 ); // Blood
			$buf .= $this->build_int16( 8 );  // TempBlood
			$buf .= $this->build_int16( 6 );  // Willpower
			$buf .= $this->build_int16( 5 );  // TempWillpower
			$buf .= $this->build_int16( 7 );  // Conscience
			$buf .= $this->build_int16( 7 );  // TempConscience
			$buf .= $this->build_int16( 6 );  // SelfControl
			$buf .= $this->build_int16( 6 );  // TempSelfControl
			$buf .= $this->build_int16( 5 );  // Courage
			$buf .= $this->build_int16( 5 );  // TempCourage
			$buf .= $this->build_string( 'Humanity' ); // Path
			$buf .= $this->build_int16( 7 );  // PathTraits
			$buf .= $this->build_int16( 7 );  // TempPathTraits
			if ( $version >= 2.399 ) {
				$buf .= $this->build_string( 'Composed' );  // Aura
				$buf .= $this->build_string( '' );          // AuraBonus
			}
			$buf .= $this->build_int16( 5 ); // PhysicalMax
			$buf .= $this->build_int16( 5 ); // SocialMax
			$buf .= $this->build_int16( 5 ); // MentalMax
		} else {
			$buf .= $this->build_int16( 10 ); // Blood
			$buf .= $this->build_int16( 6 );  // Willpower
			$buf .= $this->build_int16( 7 );  // Conscience
			$buf .= $this->build_int16( 6 );  // SelfControl
			$buf .= $this->build_int16( 5 );  // Courage
			$buf .= $this->build_string( 'Humanity' ); // Path
			$buf .= $this->build_int16( 7 );  // PathTraits
		}

		$buf .= $this->build_string( 'Marcus Player' ); // Player
		$buf .= $this->build_string( 'Active' );          // Status
		$buf .= $this->build_string( 'ID-001' );          // ID
		if ( $version >= 2.397 ) {
			$buf .= $this->build_double( 0.0 ); // StartDate, "unset"
		} else {
			$buf .= $this->build_string( '' ); // legacy Narrator-as-date
		}
		$buf .= $this->build_string( 'Approved by ST' ); // Narrator
		$buf .= $this->build_bool( false );               // IsNPC
		$buf .= $this->build_double( 0.0 );               // LastModified

		$buf .= $this->build_experience();

		$list_names = [
			'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social',
			'Negative Mental', 'Status', 'Abilities', 'Influences', 'Backgrounds',
			'Health Levels', 'Bonds', 'Miscellaneous', 'Derangements', 'Disciplines',
			'Rituals', 'Merits', 'Flaws', 'Equipment',
		];
		foreach ( $list_names as $name ) {
			$buf .= $this->build_empty_trait_list( $name );
		}
		if ( $version >= 2.395 ) {
			$buf .= $this->build_empty_trait_list( 'Hangouts' );
		}

		if ( $version >= 2.399 ) {
			$buf .= $this->build_int16( 1 ); // one boon
			$buf .= $this->build_string( 'Life Boon' ); // BoonType
			$buf .= $this->build_string( 'Some NPC' );  // CharName
			$buf .= $this->build_bool( true );          // IsOwed
			$buf .= $this->build_double( 0.0 );         // BoonDate
			$buf .= $this->build_string( 'Saved his life' ); // Description
		}

		if ( $version >= 2.397 ) {
			$buf .= $this->build_string( 'A long biography.' ); // Biography
		}
		$buf .= $this->build_string( 'Some notes.' ); // Notes

		return $buf;
	}

	public function test_full_vampire_character_round_trip(): void {
		// Wrap the single synthetic character record in a minimal but complete GEX
		// buffer at version 2.399 so it goes through the real top-level parse_binary()
		// path, not just the private character parser in isolation.
		$version = 2.399;
		$buf  = pack( 'v', 4 ) . 'GVBE';
		$buf .= pack( 'e', $version );
		$buf .= $this->build_int16( 0 ); // calendar count
		$buf .= $this->build_int16( 0 ); // APR count
		$buf .= $this->build_int16( 0 ); // XP-award count
		$buf .= $this->build_int16( 0 ); // template count
		$buf .= $this->build_int16( 0 ); // player count
		$buf .= $this->build_int16( 1 ); // character count
		$buf .= $this->build_vampire_character( $version );
		$buf .= str_repeat( $this->build_int16( 0 ), 7 ); // query, item, rote, location, action, plot, rumor

		$reader = new GV_Binary_Reader( $buf );
		$data   = GEX_Parser::parse_binary( $reader );

		$this->assertCount( 1, $data['characters'] );
		$char = $data['characters'][0];

		$this->assertSame( 'vampire', $char['race'] );
		$this->assertSame( 'Marcus Vitel', $char['name'] );
		$this->assertSame( 'Ventrue', $char['clan'] );
		$this->assertSame( 'Camarilla', $char['sect'] );
		$this->assertSame( 'Primogen Council', $char['coterie'] );
		$this->assertSame( 'Marcus the Elder', $char['sire'] );
		$this->assertSame( 8, $char['generation'] );
		$this->assertSame( 'Composed', $char['aura'] );
		$this->assertSame( 10, $char['blood'] );
		$this->assertSame( 8, $char['temp_blood'] );
		$this->assertArrayHasKey( 'Disciplines', $char['trait_lists'] );
		$this->assertArrayHasKey( 'Hangouts', $char['trait_lists'] );
		$this->assertCount( 1, $char['boons'] );
		$this->assertSame( 'Life Boon', $char['boons'][0]['boon_type'] );
		$this->assertTrue( $char['boons'][0]['is_owed'] );
		$this->assertSame( 'A long biography.', $char['biography'] );
		$this->assertSame( 'Some notes.', $char['notes'] );

		$this->assertTrue( $reader->eof() );
	}

	public function test_pre_2397_vampire_backfills_temp_fields_and_pool_max(): void {
		$version = 2.0;
		$buf     = $this->build_vampire_character( $version );
		$reader  = new GV_Binary_Reader( $buf );

		// parse_character_vampire() is private - go through parse_character() via the
		// int16 RaceType selector already embedded by build_vampire_character().
		$ref    = new \ReflectionMethod( GEX_Parser::class, 'parse_character' );
		$ref->setAccessible( true );
		$char = $ref->invoke( null, $reader, $version );

		// Pre-2.397 files never wrote separate Temp fields - VB6 copies the base value.
		$this->assertSame( $char['blood'], $char['temp_blood'] );
		$this->assertSame( $char['willpower'], $char['temp_willpower'] );
		$this->assertSame( $char['courage'], $char['temp_courage'] );
		// No Aura/AuraBonus/Sire/Coterie on a pre-2.395 file.
		$this->assertSame( '', $char['coterie'] );
		$this->assertSame( '', $char['sire'] );
		$this->assertSame( '', $char['aura'] );
		// Every trait list in this fixture is empty, so the backfilled pool max stays 0.
		$this->assertSame( 0, $char['physical_max'] );
		$this->assertSame( $char['physical_max'], $char['social_max'] );
		$this->assertSame( $char['physical_max'], $char['mental_max'] );

		$this->assertTrue( $reader->eof() );
	}

	/**
	 * One minimal synthetic character for every remaining RaceType code (all eleven
	 * besides Vampire, covered above), at version 2.399 - the newest, most heavily
	 * gated shape each class supports - asserting each dispatches to the right
	 * parser and round-trips to EOF without desyncing. Not a full field-by-field
	 * check the way the Vampire test above is, but every field IS present and
	 * correctly typed/ordered, since any mismatch against GEX_Parser's real read
	 * sequence would throw a truncation error or fail the trailing eof() assertion.
	 * Werewolf and Fera share the pre-2.395 packed-Honor/Glory/Wisdom quirk (see
	 * GEX_Parser's own doc comment on parse_character_werewolf()); Hunter and Demon
	 * are the two classes with almost no version conditionals at all; Wraith has no
	 * Biography field; Various reads TemperList before the Physical/Social/Mental
	 * block.
	 */
	public function test_every_race_type_code_dispatches_and_round_trips(): void {
		$version = 2.399;

		$builders = [
			'werewolf' => function () use ( $version ) {
				$buf  = $this->build_int16( 3 );
				$buf .= $this->build_string( 'Fenris' ) . $this->build_string( 'Wanderer' ) . $this->build_string( 'Bravo' );
				$buf .= $this->build_string( 'Silver Fangs' ) . $this->build_string( 'Homid' ) . $this->build_string( 'Ragabash' );
				$buf .= $this->build_string( 'Alpha' ) . $this->build_string( 'The Pack' ) . $this->build_string( 'Griffin' ) . $this->build_string( 'Camp' ) . $this->build_string( 'Position' );
				$buf .= $this->build_int16( 1 ); // Notoriety
				$buf .= $this->build_int16( 3 ) . $this->build_int16( 3 ) . $this->build_int16( 4 ) . $this->build_int16( 4 ) . $this->build_int16( 6 ) . $this->build_int16( 6 ); // Rage/TempRage/Gnosis/TempGnosis/Willpower/TempWillpower
				$buf .= $this->build_int16( 1 ) . $this->build_int16( 1 ) . $this->build_int16( 1 ); // Honor/Glory/Wisdom
				$buf .= $this->build_single( 1.0 ) . $this->build_single( 1.0 ) . $this->build_single( 1.0 ); // TempHonor/Glory/Wisdom
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Features', 'Gifts', 'Rites', 'Honor', 'Glory', 'Wisdom', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'mage' => function () use ( $version ) {
				$buf  = $this->build_int16( 7 );
				$buf .= $this->build_string( 'Marion' ) . $this->build_string( 'Visionary' ) . $this->build_string( 'Fanatic' );
				$buf .= $this->build_string( 'Human' ) . $this->build_string( 'Order of Hermes' ) . $this->build_string( 'Traditions' ) . $this->build_string( 'The Cabal' ) . $this->build_string( 'Adept' );
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 4 ) . $this->build_int16( 4 ) . $this->build_int16( 3 ) . $this->build_int16( 3 ) . $this->build_int16( 0 ) . $this->build_int16( 0 ); // Willpower/Temp, Arete/Temp, Quintessence/Temp, Paradox/Temp
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Resonance', 'Reputation', 'Spheres', 'Rotes', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'A focus item' ); // Foci
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'changeling' => function () use ( $version ) {
				$buf  = $this->build_int16( 5 );
				$buf .= $this->build_string( 'Puck' ) . $this->build_string( 'Seelie Legacy' ) . $this->build_string( 'Unseelie Legacy' );
				$buf .= $this->build_string( 'Court' ) . $this->build_string( 'Pooka' ) . $this->build_string( 'Wilder' ) . $this->build_string( 'House' ) . $this->build_string( 'Threshold' ) . $this->build_string( 'Title' );
				$buf .= $this->build_int16( 4 ) . $this->build_int16( 4 ) . $this->build_int16( 3 ) . $this->build_int16( 3 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // Glamour/Temp, Banality/Temp, Willpower/Temp
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Status', 'Arts', 'Realms', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Sworn oaths' ); // Oaths
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'wraith' => function () use ( $version ) {
				$buf  = $this->build_int16( 6 );
				$buf .= $this->build_string( 'Ash' );
				$buf .= $this->build_int32( 0 ); // Ethnos
				$buf .= $this->build_string( 'Bureaucrat' ) . $this->build_string( 'Traditionalist' ) . $this->build_string( 'Guild' ) . $this->build_string( 'Faction' ) . $this->build_string( 'Legion' ) . $this->build_string( 'Rank' );
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // Pathos/Temp, Corpus/Temp, Willpower/Temp
				$buf .= $this->build_string( 'Archetype' ) . $this->build_string( 'ShadowPlayer' ) . $this->build_int16( 3 ); // Angst
				$buf .= $this->build_int16( 3 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // TempAngst, pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Backgrounds', 'Status', 'Influences' ] );
				$buf .= $this->build_string( 'Passions' ) . $this->build_string( 'Fetters' ) . $this->build_string( 'Life' ) . $this->build_string( 'Death' ) . $this->build_string( 'Haunt' ) . $this->build_string( 'Regret' );
				$buf .= $this->build_trait_lists( [ 'Arcanoi', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Dark Passions' );
				$buf .= $this->build_trait_lists( [ 'Thorns' ] );
				$buf .= $this->build_string( 'Notes' ); // no Biography field on WraithClass at all
				return $buf;
			},
			'mortal' => function () use ( $version ) {
				$buf  = $this->build_int16( 4 );
				$buf .= $this->build_string( 'Jane' ) . $this->build_string( 'Caregiver' ) . $this->build_string( 'Bravo' ) . $this->build_string( 'Motivation' ) . $this->build_string( 'Association' );
				$buf .= $this->build_string( 'Regnant' ); // >= 2.399
				$buf .= $this->build_string( 'Title' );
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 7 ) . $this->build_int16( 7 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 0 ) . $this->build_int16( 0 ) . $this->build_int16( 0 ) . $this->build_int16( 0 ); // Willpower/Temp,Humanity/Temp,Conscience/Temp,SelfControl/Temp,Courage/Temp,Blood/Temp,TrueFaith/Temp
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Humanity', 'Derangements', 'Numina', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Other' );
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'mummy' => function () use ( $version ) {
				$buf  = $this->build_int16( 10 );
				$buf .= $this->build_string( 'Nefer' ) . $this->build_string( 'Amenti' ) . $this->build_string( 'Nature' ) . $this->build_string( 'Demeanor' );
				$buf .= str_repeat( $this->build_int16( 5 ) . $this->build_int16( 5 ), 8 ); // Willpower/Temp,Sekhem/Temp,Balance/Temp,Memory/Temp,Integrity/Temp,Joy/Temp,Ba/Temp,Ka/Temp
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Humanity', 'Status', 'Backgrounds', 'Health Levels', 'Hekau', 'Spells', 'Rituals', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Inheritance' );
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'kueijin' => function () use ( $version ) {
				$buf  = $this->build_int16( 11 );
				$buf .= $this->build_string( 'Wu' ) . $this->build_string( 'Nature' ) . $this->build_string( 'Demeanor' ) . $this->build_string( 'Dharma' ) . $this->build_string( 'Balance' ) . $this->build_string( 'Direction' ) . $this->build_string( 'Station' ) . $this->build_string( 'PoArchetype' );
				$buf .= str_repeat( $this->build_int16( 5 ) . $this->build_int16( 5 ), 7 ); // Hun/Temp,Po/Temp,YinChi/Temp,YangChi/Temp,DemonChi/Temp,DharmaTraits/Temp,Willpower/Temp
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Status', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Guanxi', 'Disciplines', 'Rites', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'fera' => function () use ( $version ) {
				$buf  = $this->build_int16( 8 );
				$buf .= $this->build_string( 'Bagheera' ) . $this->build_string( 'Nature' ) . $this->build_string( 'Demeanor' ) . $this->build_string( 'Bastet' ) . $this->build_string( 'Breed' ) . $this->build_string( 'Auspice' ) . $this->build_string( 'Rank' ) . $this->build_string( 'Pack' ) . $this->build_string( 'Totem' ) . $this->build_string( 'Position' );
				$buf .= $this->build_int16( 1 ); // Notoriety
				$buf .= $this->build_int16( 3 ) . $this->build_int16( 3 ) . $this->build_int16( 4 ) . $this->build_int16( 4 ) . $this->build_int16( 6 ) . $this->build_int16( 6 ); // Rage/Temp, Gnosis/Temp, Willpower/Temp
				$buf .= $this->build_int16( 1 ) . $this->build_int16( 1 ) . $this->build_int16( 1 ); // Honor/Glory/Wisdom
				$buf .= $this->build_single( 1.0 ) . $this->build_single( 1.0 ) . $this->build_single( 1.0 ); // TempHonor/Glory/Wisdom
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Features', 'Gifts', 'Rites', 'Honor', 'Glory', 'Wisdom', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'various' => function () use ( $version ) {
				$buf  = $this->build_int16( 9 );
				$buf .= $this->build_string( 'Something' ) . $this->build_string( 'Nature' ) . $this->build_string( 'Demeanor' ) . $this->build_string( 'Class' ) . $this->build_string( 'Subclass' ) . $this->build_string( 'Affinity' ) . $this->build_string( 'Plane' );
				$buf .= $this->build_string( 'Brood' ) . $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Tempers', 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Powers', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Other' );
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'hunter' => function () use ( $version ) {
				$buf  = $this->build_int16( 12 );
				$buf .= $this->build_string( 'Sam' ) . $this->build_string( 'Zealot' ) . $this->build_string( 'Rogue' ) . $this->build_string( 'Judge' ) . $this->build_string( 'The Wire' ) . $this->build_string( 'Handle' );
				for ( $i = 0; $i < 5; $i++ ) {
					$buf .= $this->build_int16( 3 ) . $this->build_int16( 3 );
				}
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 );
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Derangements', 'Edges', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
			'demon' => function () use ( $version ) {
				$buf  = $this->build_int16( 13 );
				$buf .= $this->build_string( 'Az' ) . $this->build_string( 'House' ) . $this->build_string( 'Faction' ) . $this->build_string( 'Nature' ) . $this->build_string( 'Demeanor' );
				$buf .= str_repeat( $this->build_int16( 5 ) . $this->build_int16( 5 ), 6 ); // Torment/Temp,Faith/Temp,Willpower/Temp,Conscience/Temp,Conviction/Temp,Courage/Temp
				$buf .= $this->build_int16( 5 ) . $this->build_int16( 5 ) . $this->build_int16( 5 ); // pool max
				$buf .= $this->build_id_block();
				$buf .= $this->build_experience();
				$buf .= $this->build_trait_lists( [ 'Physical', 'Social', 'Mental', 'Negative Physical', 'Negative Social', 'Negative Mental', 'Abilities', 'Influences', 'Backgrounds', 'Health Levels', 'Lores', 'Apocalyptic Form', 'Merits', 'Flaws', 'Equipment', 'Hangouts' ] );
				$buf .= $this->build_string( 'Bio' ) . $this->build_string( 'Notes' );
				return $buf;
			},
		];

		foreach ( $builders as $expected_race => $builder ) {
			$reader = new GV_Binary_Reader( $builder() );
			$ref    = new \ReflectionMethod( GEX_Parser::class, 'parse_character' );
			$ref->setAccessible( true );
			$char = $ref->invoke( null, $reader, $version );

			$this->assertSame( $expected_race, $char['race'] );
			$this->assertTrue( $reader->eof(), "$expected_race record must consume every byte." );
		}
	}

	public function test_unrecognized_race_type_code_aborts_with_a_clear_message(): void {
		$buf    = $this->build_int16( 999 );
		$reader = new GV_Binary_Reader( $buf );

		$ref = new \ReflectionMethod( GEX_Parser::class, 'parse_character' );
		$ref->setAccessible( true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unrecognized RaceType code 999/' );
		$ref->invoke( null, $reader, 2.397 );
	}

	public function test_rejects_a_non_grapevine_file(): void {
		$reader = new GV_Binary_Reader( pack( 'v', 4 ) . 'NOPE' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Not a Grapevine binary exchange file/' );
		GEX_Parser::parse_binary( $reader );
	}

	/**
	 * Decision 074 - shared by both the binary and XML readers, so exhaustively covered
	 * once here rather than duplicated in `GexXmlParserTest`.
	 */
	public function test_is_section_divider(): void {
		$this->assertTrue( GEX_Parser::is_section_divider( [ 'name' => '——Blood Magic——', 'total' => '0', 'note' => '' ] ) );
		$this->assertTrue( GEX_Parser::is_section_divider( [ 'name' => '——Combination Disciplines——', 'total' => '0', 'note' => '' ] ) );
		$this->assertTrue( GEX_Parser::is_section_divider( [ 'name' => '  ——Padded——  ', 'total' => '', 'note' => '' ] ), 'surrounding whitespace on the row itself must not defeat the match' );

		$this->assertFalse( GEX_Parser::is_section_divider( [ 'name' => 'Animalism', 'total' => '5', 'note' => '' ] ) );
		$this->assertFalse( GEX_Parser::is_section_divider( [ 'name' => '——Blood Magic——', 'total' => '1', 'note' => '' ] ), 'a nonzero value means this is a real held trait, however it is named' );
		$this->assertFalse( GEX_Parser::is_section_divider( [ 'name' => '-Single Hyphen-', 'total' => '0', 'note' => '' ] ), 'a plain hyphen is not an em-dash - must not false-positive on ordinary punctuation' );
		$this->assertFalse( GEX_Parser::is_section_divider( [ 'name' => '——Unbalanced', 'total' => '0', 'note' => '' ] ), 'must be wrapped on BOTH sides, not just prefixed' );
	}

	/**
	 * workflow-0.9.md Step 0e - the label stamped onto every trait that follows a divider,
	 * shared by both readers the same way `is_section_divider()` itself is.
	 */
	public function test_divider_label_strips_the_em_dash_wrapping(): void {
		$this->assertSame( 'Blood Magic', GEX_Parser::divider_label( [ 'name' => '——Blood Magic——', 'total' => '0', 'note' => '' ] ) );
		$this->assertSame( 'Combination Disciplines', GEX_Parser::divider_label( [ 'name' => '——Combination Disciplines——', 'total' => '0', 'note' => '' ] ) );
		$this->assertSame( 'Padded', GEX_Parser::divider_label( [ 'name' => '  ——Padded——  ', 'total' => '', 'note' => '' ] ) );
	}
}
