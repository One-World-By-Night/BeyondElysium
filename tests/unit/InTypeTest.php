<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\In_Type;
use PHPUnit\Framework\TestCase;

/**
 * The in-type tests a creature type's section declares: values from a field, a map or a constant, compared by name, by
 * group or subgroup, or against the character's own picks, joined by `all` and limited by `when`.
 */
class InTypeTest extends TestCase {

	private static function decoded( array $data ) {
		return json_decode( (string) json_encode( $data ) );
	}

	/**
	 * Identity blocks by slug, as a resolver loads them.
	 */
	private static function blocks(): callable {
		$blocks = [
			'vampire-identity' => self::decoded( [ 'definition' => [ 'clan_disciplines' => [ 'Brujah' => [ 'Celerity', 'Potence', 'Presence' ] ] ] ] ),
			'mage-identity'    => self::decoded( [ 'definition' => [ 'specialty' => [ 'Society of Ether' => [ 'Matter' ], 'Verbena' => [ 'Life' ], 'Hollow Ones' => [ 'Entropy' ] ] ] ] ),
		];
		return static fn( string $slug ): ?object => $blocks[ $slug ] ?? null;
	}

	/**
	 * Vampire's own tests, as its creature type states them.
	 */
	private static function vampire(): array {
		return [
			[ 'kind' => 'names', 'values' => [ 'map' => 'vampire-identity.clan_disciplines', 'by' => [ 'Clan' ] ] ],
			[ 'kind' => 'chosen', 'values' => [ 'field' => 'vampire-identity.chosen_in_clan' ], 'when' => [ 'field' => 'vampire-identity.Clan', 'is' => [ 'Caitiff', 'Pander' ] ] ],
		];
	}

	private static function passes( array $tests, string $name, array $sheet, ?array $family = null ): bool {
		return In_Type::passes_any( $tests, $name, $family === null ? null : (object) self::decoded( $family ), $sheet, self::blocks() );
	}

	public function test_a_clans_own_disciplines_are_in_clan_and_others_are_not(): void {
		$brujah = [ 'vampire-identity' => [ 'Clan' => 'Brujah' ] ];

		$this->assertTrue( self::passes( self::vampire(), 'Celerity', $brujah ) );
		$this->assertFalse( self::passes( self::vampire(), 'Obfuscate', $brujah ) );
	}

	public function test_a_caitiff_counts_its_own_picks_and_a_brujah_does_not(): void {
		$caitiff = [ 'vampire-identity' => [ 'Clan' => 'Caitiff', 'chosen_in_clan' => [ 'Auspex', 'Fortitude', 'Obfuscate' ] ] ];
		$brujah  = [ 'vampire-identity' => [ 'Clan' => 'Brujah', 'chosen_in_clan' => [ 'Obfuscate' ] ] ];

		$this->assertTrue( self::passes( self::vampire(), 'Auspex', $caitiff ) );
		$this->assertFalse( self::passes( self::vampire(), 'Celerity', $caitiff ) );
		$this->assertFalse( self::passes( self::vampire(), 'Obfuscate', $brujah ), 'picks count only where `when` says' );
	}

	public function test_a_caitiff_with_no_picks_has_nothing_in_clan(): void {
		$this->assertFalse( self::passes( self::vampire(), 'Auspex', [ 'vampire-identity' => [ 'Clan' => 'Caitiff' ] ] ) );
	}

	public function test_a_character_who_has_not_said_what_they_are_is_never_out_of_type(): void {
		$this->assertTrue( self::passes( self::vampire(), 'Obfuscate', [] ) );
		$this->assertTrue( self::passes( self::vampire(), 'Obfuscate', [ 'vampire-identity' => [ 'Clan' => '' ] ] ) );
	}

	public function test_a_map_takes_the_first_field_it_knows(): void {
		$tests = [ [ 'kind' => 'names', 'values' => [ 'map' => 'mage-identity.specialty', 'by' => [ 'Faction', 'Tradition' ] ] ] ];

		$hollow = [ 'mage-identity' => [ 'Faction' => 'Hollow Ones', 'Tradition' => 'Verbena' ] ];
		$this->assertTrue( self::passes( $tests, 'Entropy', $hollow ), "the faction's specialty" );
		$this->assertFalse( self::passes( $tests, 'Life', $hollow ), "not the Tradition's as well" );

		$verbena = [ 'mage-identity' => [ 'Faction' => 'Independent', 'Tradition' => 'Verbena' ] ];
		$this->assertTrue( self::passes( $tests, 'Life', $verbena ), 'a faction the map does not know falls to the Tradition' );

		$unknown = [ 'mage-identity' => [ 'Tradition' => 'Orphans' ] ];
		$this->assertFalse( self::passes( $tests, 'Life', $unknown ), 'a value the map does not know gives nothing' );
	}

	public function test_names_over_several_fields_counts_any_of_them(): void {
		$tests = [ [ 'kind' => 'names', 'values' => [ 'field' => [ 'werewolf-identity.Breed', 'werewolf-identity.Auspice', 'werewolf-identity.Tribe' ] ] ] ];
		$sheet = [ 'werewolf-identity' => [ 'Breed' => 'Homid', 'Auspice' => 'Ragabash', 'Tribe' => 'Bone Gnawers' ] ];

		$this->assertTrue( self::passes( $tests, 'Ragabash', $sheet ) );
		$this->assertTrue( self::passes( $tests, 'Bone Gnawers', $sheet ) );
		$this->assertFalse( self::passes( $tests, 'Philodox', $sheet ) );
		$this->assertFalse( self::passes( $tests, 'Fianna', [ 'werewolf-identity' => [ 'Breed' => 'Homid' ] ] ), 'one field said is enough to judge by' );
		$this->assertTrue( self::passes( $tests, 'Fianna', [ 'werewolf-identity' => [ 'Pack' => 'Moon Runners' ] ] ), 'none of them said' );
	}

	public function test_a_list_field_counts_each_of_its_values(): void {
		$tests = [ [ 'kind' => 'names', 'values' => [ 'field' => 'test-identity.Paths' ] ] ];

		$this->assertTrue( self::passes( $tests, 'Alchemy', [ 'test-identity' => [ 'Paths' => [ 'Alchemy', 'Celestial' ] ] ] ) );
	}

	public function test_a_constant_is_the_same_for_every_character(): void {
		$tests = [ [ 'kind' => 'names', 'values' => [ 'constant' => [ 'Potence' ] ] ] ];

		$this->assertTrue( self::passes( $tests, 'Potence', [] ) );
		$this->assertFalse( self::passes( $tests, 'Celerity', [] ) );
	}

	public function test_a_facet_compares_the_familys_group_or_subgroup(): void {
		$species = [ 'kind' => 'facet', 'facet' => 'group', 'values' => [ 'field' => 'fera-identity.Fera Type' ] ];
		$tribe   = [ 'kind' => 'facet', 'facet' => 'subgroup', 'values' => [ 'field' => 'fera-identity.Breed' ] ];
		$sheet   = [ 'fera-identity' => [ 'Fera Type' => 'Bastet', 'Breed' => 'Bagheera' ] ];

		$this->assertTrue( self::passes( [ $species ], 'Bastet: Bagheera', $sheet, [ 'group' => 'Bastet', 'subgroup' => 'Bagheera' ] ) );
		$this->assertFalse( self::passes( [ $species ], 'Ajaba: Hyena', $sheet, [ 'group' => 'Ajaba', 'subgroup' => 'Hyena' ] ) );
		$this->assertFalse( self::passes( [ $species ], 'Loose', $sheet, [] ), 'no group matches no species' );
		$this->assertTrue( self::passes( [ $tribe ], 'Bastet', $sheet, [ 'group' => 'Bastet' ] ), 'a family with no subgroup is general to its group' );
		$this->assertFalse( self::passes( [ $tribe ], 'Bastet: Simba', $sheet, [ 'group' => 'Bastet', 'subgroup' => 'Simba' ] ) );
	}

	public function test_a_facet_names_a_tiered_powers_own_category_value(): void {
		$breed = [ 'kind' => 'facet', 'facet' => 'breed', 'values' => [ 'field' => 'werewolf-identity.Breed' ] ];
		$sheet = [ 'werewolf-identity' => [ 'Breed' => 'Homid' ] ];

		$this->assertTrue( self::passes( [ $breed ], 'Werewolf: Homid Gift', $sheet, [ 'category_values' => [ 'breed' => 'Homid' ] ] ) );
		$this->assertFalse( self::passes( [ $breed ], 'Werewolf: Lupus Gift', $sheet, [ 'category_values' => [ 'breed' => 'Lupus' ] ] ) );
		$this->assertFalse( self::passes( [ $breed ], 'Ungrouped', $sheet, [] ), 'no category_values entry for the facet is never in-type' );
		$this->assertFalse( self::passes( [ $breed ], 'Unknown Family', $sheet ), 'a family the block cannot find is never in-type' );
	}

	public function test_all_passes_only_when_every_test_it_holds_does(): void {
		$tests = [ [ 'kind' => 'all', 'tests' => [
			[ 'kind' => 'facet', 'facet' => 'group', 'values' => [ 'field' => 'fera-identity.Fera Type' ] ],
			[ 'kind' => 'facet', 'facet' => 'subgroup', 'values' => [ 'field' => 'fera-identity.Breed' ] ],
		] ] ];
		$sheet = [ 'fera-identity' => [ 'Fera Type' => 'Bastet', 'Breed' => 'Bagheera' ] ];

		$this->assertTrue( self::passes( $tests, 'Bastet: Bagheera', $sheet, [ 'group' => 'Bastet', 'subgroup' => 'Bagheera' ] ) );
		$this->assertTrue( self::passes( $tests, 'Bastet', $sheet, [ 'group' => 'Bastet' ] ) );
		$this->assertFalse( self::passes( $tests, 'Bastet: Simba', $sheet, [ 'group' => 'Bastet', 'subgroup' => 'Simba' ] ) );
		$this->assertFalse( self::passes( $tests, 'Ajaba: Bagheera', $sheet, [ 'group' => 'Ajaba', 'subgroup' => 'Bagheera' ] ) );
		$this->assertFalse( self::passes( [ [ 'kind' => 'all', 'tests' => [] ] ], 'Anything', $sheet ) );
	}

	public function test_when_limits_a_test_to_the_characters_it_names(): void {
		$ghoul    = [ 'kind' => 'names', 'values' => [ 'constant' => [ 'Potence' ] ], 'when' => [ 'field' => 'mortal-identity.Revenant Family', 'set' => false ] ];
		$revenant = [ 'kind' => 'names', 'values' => [ 'constant' => [ 'Vicissitude' ] ], 'when' => [ 'field' => 'mortal-identity.Revenant Family', 'set' => true ] ];

		$this->assertTrue( self::passes( [ $ghoul, $revenant ], 'Potence', [] ) );
		$this->assertFalse( self::passes( [ $ghoul, $revenant ], 'Vicissitude', [] ) );
		$bratovitch = [ 'mortal-identity' => [ 'Revenant Family' => 'Bratovitch' ] ];
		$this->assertTrue( self::passes( [ $ghoul, $revenant ], 'Vicissitude', $bratovitch ) );
		$this->assertFalse( self::passes( [ $ghoul, $revenant ], 'Potence', $bratovitch ) );
	}

	public function test_an_unknown_kind_passes_nothing(): void {
		$this->assertFalse( self::passes( [ [ 'kind' => 'maybe', 'values' => [ 'constant' => [ 'Celerity' ] ] ] ], 'Celerity', [] ) );
	}
}
