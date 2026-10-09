<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Cost_Engine;
use BeyondElysium\Services\In_Type;
use WP_UnitTestCase;

/**
 * One case per creature type in the design's in-type table, each through the real creature type and its real blocks:
 * Vampire on the book as it ships, the others with the tests and maps their book data will declare.
 */
class InTypeThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-in-type';

	public function setUp(): void {
		parent::setUp();
		Game::create( [ 'slug' => $this->slug, 'name' => 'In-Type by Night' ] );
	}

	private function character( string $stack, array $sheet ): object {
		$id = (int) Character::create( [
			'name' => 'In-Type ' . $stack, 'stack_slug' => $stack, 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => $sheet,
		] );
		return Character::find( $id );
	}

	/**
	 * Sets the in-type tests on one section of a book creature type.
	 */
	private static function tests( string $stack, string $block, array $tests ): void {
		$definition = json_decode( (string) wp_json_encode( Creature_Stack::find_by_slug( $stack )->stack_definition ), true );
		foreach ( $definition['sections'] as $i => $section ) {
			if ( $section['block_slug'] === $block ) {
				$definition['sections'][ $i ]['in_type'] = $tests;
			}
		}
		Creature_Stack::update( $stack, [ 'stack_definition' => $definition ] );
	}

	/**
	 * Sets one key of a book block's definition.
	 *
	 * @param mixed $value
	 */
	private static function keep( string $block, string $key, $value ): void {
		$definition         = json_decode( (string) wp_json_encode( Schema_Block::find_by_slug( $block )->definition ), true );
		$definition[ $key ] = $value;
		Schema_Block::update( $block, [ 'definition' => $definition ] );
	}

	/**
	 * Files some of a book block's families under a group and subgroup.
	 *
	 * @param array<string,array{0:string,1:?string}> $facets
	 */
	private static function file_under( string $block, array $facets ): void {
		$definition = json_decode( (string) wp_json_encode( Schema_Block::find_by_slug( $block )->definition ), true );
		foreach ( $definition['powers'] as $i => $power ) {
			if ( isset( $facets[ $power['name'] ] ) ) {
				$definition['powers'][ $i ]['group'] = $facets[ $power['name'] ][0];
				if ( $facets[ $power['name'] ][1] !== null ) {
					$definition['powers'][ $i ]['subgroup'] = $facets[ $power['name'] ][1];
				}
			}
		}
		Schema_Block::update( $block, [ 'definition' => $definition ] );
	}

	private function in_type( object $character, string $block, string $family ): bool {
		return Cost_Engine::is_in_type( $character, $block, $family );
	}

	public function test_vampire_on_the_book_as_it_ships(): void {
		$brujah  = $this->character( 'vampire', [ 'vampire-identity' => [ 'Clan' => 'Brujah' ] ] );
		$caitiff = $this->character( 'vampire', [ 'vampire-identity' => [ 'Clan' => 'Caitiff', 'In-clan Disciplines' => [ 'Obfuscate' ] ] ] );
		$pander  = $this->character( 'vampire', [ 'vampire-identity' => [ 'Clan' => 'Pander' ] ] );
		$none    = $this->character( 'vampire', [] );
		$stale   = $this->character( 'vampire', [ 'vampire-identity' => [ 'Clan' => 'Brujah', 'In-clan Disciplines' => [ 'Obfuscate' ] ] ] );

		$this->assertTrue( $this->in_type( $brujah, 'vampire-disciplines', 'Celerity' ) );
		$this->assertFalse( $this->in_type( $brujah, 'vampire-disciplines', 'Obfuscate' ) );
		$this->assertTrue( $this->in_type( $caitiff, 'vampire-disciplines', 'Obfuscate' ), "a Caitiff's own pick" );
		$this->assertFalse( $this->in_type( $caitiff, 'vampire-disciplines', 'Celerity' ) );
		$this->assertFalse( $this->in_type( $pander, 'vampire-disciplines', 'Auspex' ), 'a Pander who picked nothing' );
		$this->assertTrue( $this->in_type( $none, 'vampire-disciplines', 'Obfuscate' ), 'no clan said' );
		$this->assertFalse( $this->in_type( $stale, 'vampire-disciplines', 'Obfuscate' ), 'picks count for Caitiff and Pander alone' );

		$change = [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Obfuscate', 'level' => 1 ] ] ];
		$this->assertSame( 4, Cost_Engine::cost_for_change( $brujah, $change ), 'out of clan, the book\'s +1' );
		$this->assertSame( 3, Cost_Engine::cost_for_change( $caitiff, $change ) );
	}

	public function test_werewolf_gifts_named_for_breed_auspice_or_tribe(): void {
		self::tests( 'werewolf', 'werewolf-gifts', [
			[ 'kind' => 'names', 'values' => [ 'field' => [ 'werewolf-identity.Breed', 'werewolf-identity.Auspice', 'werewolf-identity.Tribe' ] ] ],
		] );
		$garou = $this->character( 'werewolf', [ 'werewolf-identity' => [ 'Breed' => 'Homid', 'Auspice' => 'Ragabash', 'Tribe' => 'Bone Gnawers' ] ] );

		foreach ( [ 'Homid', 'Ragabash', 'Bone Gnawers' ] as $family ) {
			$this->assertTrue( $this->in_type( $garou, 'werewolf-gifts', $family ), $family );
		}
		$this->assertFalse( $this->in_type( $garou, 'werewolf-gifts', 'Philodox' ) );
		$this->assertFalse( $this->in_type( $garou, 'werewolf-gifts', 'Fianna' ) );
	}

	public function test_fera_gifts_of_their_species_matching_a_breed_or_general_to_it(): void {
		self::keep( 'fera-identity', 'species', [ 'Bagheera' => [ 'Bastet' ], 'Hatar' => [ 'Ananasi' ] ] );
		self::file_under( 'fera-gifts', [
			'Bastet: Bagheera' => [ 'Bastet', 'Bagheera' ],
			'Bastet: Feline'   => [ 'Bastet', 'Feline' ],
			'Bastet: Balam'    => [ 'Bastet', 'Balam' ],
			'Ananasi'          => [ 'Ananasi', null ],
			'Ananasi: Hatar'   => [ 'Ananasi', 'Hatar' ],
		] );
		self::tests( 'fera', 'fera-gifts', [ [ 'kind' => 'all', 'tests' => [
			[ 'kind' => 'facet', 'facet' => 'group', 'values' => [ 'map' => 'fera-identity.species', 'by' => [ 'Fera Type' ] ] ],
			[ 'kind' => 'facet', 'facet' => 'subgroup', 'values' => [ 'field' => [ 'fera-identity.Fera Type', 'fera-identity.Breed', 'fera-identity.Auspice' ] ] ],
		] ] ] );
		$bagheera = $this->character( 'fera', [ 'fera-identity' => [ 'Fera Type' => 'Bagheera', 'Breed' => 'Feline' ] ] );

		$this->assertTrue( $this->in_type( $bagheera, 'fera-gifts', 'Bastet: Bagheera' ) );
		$this->assertTrue( $this->in_type( $bagheera, 'fera-gifts', 'Bastet: Feline' ), 'its breed' );
		$this->assertFalse( $this->in_type( $bagheera, 'fera-gifts', 'Bastet: Balam' ), 'another tribe of its own species' );
		$this->assertFalse( $this->in_type( $bagheera, 'fera-gifts', 'Ananasi' ), "another species' general family" );

		$hatar = $this->character( 'fera', [ 'fera-identity' => [ 'Fera Type' => 'Hatar' ] ] );
		$this->assertTrue( $this->in_type( $hatar, 'fera-gifts', 'Ananasi' ), "its species' general family" );
	}

	public function test_mage_specialty_from_the_faction_then_the_tradition(): void {
		self::keep( 'mage-identity', 'specialty', [ 'Hollow Ones' => [ 'Entropy' ], 'Dreamspeakers' => [ 'Spirit' ], 'Acharne' => [ 'Prime' ] ] );
		self::tests( 'mage', 'mage-spheres', [ [ 'kind' => 'names', 'values' => [ 'map' => 'mage-identity.specialty', 'by' => [ 'Faction', 'Tradition' ] ] ] ] );
		$faction   = $this->character( 'mage', [ 'mage-identity' => [ 'Tradition' => 'Dreamspeakers', 'Faction' => 'Acharne' ] ] );
		$tradition = $this->character( 'mage', [ 'mage-identity' => [ 'Tradition' => 'Dreamspeakers', 'Faction' => 'Adventurers' ] ] );

		$this->assertTrue( $this->in_type( $faction, 'mage-spheres', 'Prime' ), "the faction's own specialty" );
		$this->assertFalse( $this->in_type( $faction, 'mage-spheres', 'Spirit' ) );
		$this->assertTrue( $this->in_type( $tradition, 'mage-spheres', 'Spirit' ), "a faction with none falls to the Tradition's" );

		$sphere = [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'mage-spheres', 'trait' => [ 'name' => 'Forces', 'level' => 3 ] ] ];
		$in     = [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'mage-spheres', 'trait' => [ 'name' => 'Spirit', 'level' => 3 ] ] ];
		$this->assertSame( Cost_Engine::cost_for_change( $tradition, $in ) + 4, Cost_Engine::cost_for_change( $tradition, $sphere ), '+1, +1 and +2 out of the specialty' );
	}

	public function test_changeling_has_no_in_type_rule(): void {
		$changeling = $this->character( 'changeling', [ 'changeling-identity' => [ 'Kith' => 'Sidhe' ] ] );

		$this->assertTrue( $this->in_type( $changeling, 'changeling-arts', 'Chicanery' ) );
		$this->assertTrue( $this->in_type( $changeling, 'changeling-arts', 'Wayfare' ) );
	}

	public function test_wraith_arcanoi_of_the_guild(): void {
		self::keep( 'wraith-identity', 'guild_arcanoi', [ 'Harbinger' => [ 'Argos' ], 'Chanteur' => [ 'Keening' ] ] );
		self::tests( 'wraith', 'wraith-arcanoi', [ [ 'kind' => 'names', 'values' => [ 'map' => 'wraith-identity.guild_arcanoi', 'by' => [ 'Guild' ] ] ] ] );
		$harbinger = $this->character( 'wraith', [ 'wraith-identity' => [ 'Guild' => 'Harbinger' ] ] );

		$this->assertTrue( $this->in_type( $harbinger, 'wraith-arcanoi', 'Argos' ) );
		$this->assertFalse( $this->in_type( $harbinger, 'wraith-arcanoi', 'Keening' ) );
	}

	public function test_mummy_hekau_of_the_amenti_and_the_udja_sens_own(): void {
		self::keep( 'mummy-identity', 'primary_hekau', [ 'Kher-Minu' => [ 'Necromancy' ], 'Sakhmu' => [ 'Celestial' ] ] );
		self::tests( 'mummy', 'mummy-hekau', [
			[ 'kind' => 'names', 'values' => [ 'map' => 'mummy-identity.primary_hekau', 'by' => [ 'Amenti' ] ] ],
			[ 'kind' => 'chosen', 'values' => [ 'field' => 'mummy-identity.chosen_hekau' ], 'when' => [ 'field' => 'mummy-identity.Amenti', 'is' => [ 'Udja-Sen' ] ] ],
		] );
		$kher = $this->character( 'mummy', [ 'mummy-identity' => [ 'Amenti' => 'Kher-Minu' ] ] );
		$udja = $this->character( 'mummy', [ 'mummy-identity' => [ 'Amenti' => 'Udja-Sen', 'chosen_hekau' => [ 'Alchemy' ] ] ] );

		$this->assertTrue( $this->in_type( $kher, 'mummy-hekau', 'Necromancy' ) );
		$this->assertFalse( $this->in_type( $kher, 'mummy-hekau', 'Alchemy' ) );
		$this->assertTrue( $this->in_type( $udja, 'mummy-hekau', 'Alchemy' ), "the Udja-sen's own pick" );
		$this->assertFalse( $this->in_type( $udja, 'mummy-hekau', 'Necromancy' ) );
	}

	public function test_kuei_jin_has_no_in_type_rule(): void {
		$kuei = $this->character( 'kueijin', [ 'kueijin-identity' => [ 'Direction' => 'East' ] ] );

		$this->assertTrue( $this->in_type( $kuei, 'kueijin-disciplines', 'Jade Shintai' ) );
	}

	public function test_demon_lores_of_the_house_or_common(): void {
		self::file_under( 'demon-evocations', [
			'Lore of Flame'    => [ 'Devil', null ],
			'Lore of Humanity' => [ 'Common', null ],
			'Lore of the Winds' => [ 'Scourge', null ],
		] );
		self::tests( 'demon', 'demon-evocations', [
			[ 'kind' => 'facet', 'facet' => 'group', 'values' => [ 'field' => 'demon-identity.House' ] ],
			[ 'kind' => 'facet', 'facet' => 'group', 'values' => [ 'constant' => [ 'Common' ] ] ],
		] );
		$devil = $this->character( 'demon', [ 'demon-identity' => [ 'House' => 'Devil' ] ] );

		$this->assertTrue( $this->in_type( $devil, 'demon-evocations', 'Lore of Flame' ) );
		$this->assertTrue( $this->in_type( $devil, 'demon-evocations', 'Lore of Humanity' ), 'a Common Lore' );
		$this->assertFalse( $this->in_type( $devil, 'demon-evocations', 'Lore of the Winds' ) );
	}

	public function test_has_test_for_block_finds_a_book_tests_real_section(): void {
		$this->assertTrue( In_Type::has_test_for_block( 'vampire-disciplines', $this->slug ) );
		$this->assertFalse( In_Type::has_test_for_block( 'changeling-arts', $this->slug ) );
		$this->assertFalse( In_Type::has_test_for_block( 'nonexistent-block', $this->slug ) );
	}

	public function test_mortal_a_ghouls_potence_and_a_revenants_family_disciplines(): void {
		self::keep( 'mortal-identity', 'revenant_disciplines', [ 'Bratovitch' => [ 'Animalism', 'Potence', 'Vicissitude' ], 'Grimaldi' => [ 'Celerity', 'Dominate', 'Fortitude' ] ] );
		self::tests( 'mortal', 'vampire-disciplines', [
			[ 'kind' => 'names', 'values' => [ 'constant' => [ 'Potence' ] ], 'when' => [ 'field' => 'mortal-identity.Revenant Family', 'set' => false ] ],
			[ 'kind' => 'names', 'values' => [ 'map' => 'mortal-identity.revenant_disciplines', 'by' => [ 'Revenant Family' ] ], 'when' => [ 'field' => 'mortal-identity.Revenant Family', 'set' => true ] ],
		] );
		$ghoul    = $this->character( 'mortal', [ 'mortal-identity' => [ 'Regnant' => 'A Brujah' ] ] );
		$grimaldi = $this->character( 'mortal', [ 'mortal-identity' => [ 'Revenant Family' => 'Grimaldi' ] ] );

		$this->assertTrue( $this->in_type( $ghoul, 'vampire-disciplines', 'Potence' ) );
		$this->assertFalse( $this->in_type( $ghoul, 'vampire-disciplines', 'Celerity' ), 'every other Discipline costs a ghoul one more' );
		$this->assertTrue( $this->in_type( $grimaldi, 'vampire-disciplines', 'Dominate' ) );
		$this->assertFalse( $this->in_type( $grimaldi, 'vampire-disciplines', 'Potence' ), "the ghoul's Potence is not a revenant's" );
		$this->assertTrue( $this->in_type( $ghoul, 'werewolf-gifts', 'Homid' ), 'a section with no tests of its own' );
	}
}
