<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Rumor_Generator;
use PHPUnit\Framework\TestCase;

/**
 * Port of `APREngineClass.AddStandardRumors` (GV301Source/Code/APREngineClass.cls).
 * `resolve_character_candidates()` and `rumor_config()` take plain arrays and touch no
 * database - the persistence and existing-title lookups are covered at the thread
 * layer.
 *
 * @see BE_PROCESS/releases/workflow-0.5.md Step 5g
 * @see BE_PROCESS/reference/GV-SOURCEMAP.md "Rumor auto-generation"
 */
class RumorGeneratorTest extends TestCase {

	private function toggles( array $overrides = [] ): array {
		return array_merge( [
			'public_rumors'    => false,
			'personal_rumors'  => false,
			'race_rumors'      => false,
			'group_rumors'     => false,
			'subgroup_rumors'  => false,
			'influence_rumors' => false,
			'previous_rumors'  => false,
			'copy_previous'    => false,
		], $overrides );
	}

	private function character( array $overrides = [] ): array {
		return array_merge( [
			'name'        => 'Marcus Vitel',
			'stack_slug'  => 'vampire',
			'stack_label' => 'Vampire',
			'influences'  => [],
		], $overrides );
	}

	// -------------------------------------------------------------------------
	// rumor_config() defaults
	// -------------------------------------------------------------------------

	public function test_rumor_config_falls_back_to_gv_defaults_when_unset(): void {
		$game   = (object) [ 'settings' => (object) [] ];
		$config = Rumor_Generator::rumor_config( $game );

		$this->assertTrue( $config['public_rumors'] );
		$this->assertFalse( $config['personal_rumors'] );
		$this->assertFalse( $config['race_rumors'] );
		$this->assertFalse( $config['group_rumors'] );
		$this->assertFalse( $config['subgroup_rumors'] );
		$this->assertTrue( $config['influence_rumors'] );
		$this->assertTrue( $config['previous_rumors'] );
		$this->assertFalse( $config['copy_previous'] );
	}

	// -------------------------------------------------------------------------
	// Per-toggle output
	// -------------------------------------------------------------------------

	public function test_personal_rumors_toggle_produces_one_per_character(): void {
		$characters = [ $this->character( [ 'name' => 'Marcus Vitel' ] ), $this->character( [ 'name' => 'Sara Redhawk' ] ) ];
		$result     = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'personal_rumors' => true ] ), [] );

		$titles = array_column( $result, 'title' );
		$this->assertSame( [ 'Marcus Vitel', 'Sara Redhawk' ], $titles );
		$this->assertSame( 'personal', $result[0]['category'] );
		$this->assertSame( [ 'field' => 'name', 'operator' => 'equals', 'value' => 'Marcus Vitel' ], $result[0]['target_query'] );
	}

	public function test_race_rumors_toggle_uses_the_stack_label_as_title(): void {
		$characters = [ $this->character( [ 'stack_slug' => 'vampire', 'stack_label' => 'Vampire' ] ) ];
		$result     = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'race_rumors' => true ] ), [] );

		$this->assertSame( 'Vampire', $result[0]['title'] );
		$this->assertSame( [ 'field' => 'stack_slug', 'operator' => 'equals', 'value' => 'vampire' ], $result[0]['target_query'] );
	}

	public function test_influence_rumors_toggle_titles_with_influence_suffix(): void {
		$characters = [ $this->character( [ 'influences' => [ 'Bureaucracy', 'Church' ] ] ) ];
		$result     = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'influence_rumors' => true ] ), [] );

		$titles = array_column( $result, 'title' );
		$this->assertSame( [ 'Bureaucracy Influence', 'Church Influence' ], $titles );
		$this->assertSame( [ 'field' => 'influences', 'operator' => 'contains', 'value' => 'Bureaucracy' ], $result[0]['target_query'] );
	}

	/** 1.1.0 §3.4 item 4: "The generator sets both on every influence rumor." */
	public function test_an_influence_rumor_carries_its_own_rumor_level_key_and_match(): void {
		$characters = [ $this->character( [ 'influences' => [ 'Bureaucracy' ] ] ) ];
		$result     = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'influence_rumors' => true ] ), [] );

		$this->assertSame( 'influences', $result[0]['rumor_level_key'] );
		$this->assertSame( 'Bureaucracy', $result[0]['rumor_level_match'] );
	}

	/** A rumor with no per-character rating to gate on never carries these keys at all. */
	public function test_a_personal_rumor_carries_no_rumor_level_key(): void {
		$characters = [ $this->character( [ 'name' => 'Marcus Vitel' ] ) ];
		$result     = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'personal_rumors' => true ] ), [] );

		$this->assertArrayNotHasKey( 'rumor_level_key', $result[0] );
		$this->assertArrayNotHasKey( 'rumor_level_match', $result[0] );
	}

	/**
	 * 1.0.0-review F-037: both toggles saved and changed nothing. A character's group and
	 * subgroup are the fields Grapevine's own Group()/Subgroup() name for its creature type -
	 * a vampire's Clan and Sect (rumor-group-map.php).
	 */
	public function test_group_and_subgroup_toggles_title_a_rumor_with_the_characters_group_and_subgroup(): void {
		$characters = [
			$this->character( [ 'group' => [ 'field' => 'clan', 'label' => 'Clan', 'value' => 'Brujah' ], 'subgroup' => [ 'field' => 'sect', 'label' => 'Sect', 'value' => 'Anarch' ] ] ),
			$this->character( [ 'name' => 'Isolde', 'group' => [ 'field' => 'clan', 'label' => 'Clan', 'value' => 'Brujah' ], 'subgroup' => [ 'field' => 'sect', 'label' => 'Sect', 'value' => 'Camarilla' ] ] ),
		];

		$result = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'group_rumors' => true, 'subgroup_rumors' => true ] ), [] );

		$this->assertSame( [ 'Brujah', 'Anarch', 'Camarilla' ], array_column( $result, 'title' ) );
		$this->assertSame( [ 'group', 'subgroup', 'subgroup' ], array_column( $result, 'category' ) );
		$this->assertSame( [ 'field' => 'clan', 'operator' => 'equals', 'value' => 'Brujah' ], $result[0]['target_query'] );
	}

	public function test_a_creature_type_without_a_group_or_an_empty_one_makes_no_rumor(): void {
		$characters = [
			$this->character( [ 'group' => null, 'subgroup' => [ 'field' => 'guild', 'label' => 'Guild', 'value' => '' ] ] ),
		];

		$this->assertSame( [], Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'group_rumors' => true, 'subgroup_rumors' => true ] ), [] ) );
	}

	public function test_a_number_is_titled_with_its_field_so_the_rumor_says_what_it_is(): void {
		$characters = [ $this->character( [ 'subgroup' => [ 'field' => 'rank', 'label' => 'Rank', 'value' => '2' ] ] ) ];

		$result = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'subgroup_rumors' => true ] ), [] );

		$this->assertSame( 'Rank 2', $result[0]['title'] );
		$this->assertSame( [ 'field' => 'rank', 'operator' => 'equals', 'value' => '2' ], $result[0]['target_query'] );
	}

	public function test_group_and_subgroup_toggles_off_make_no_group_rumors(): void {
		$characters = [ $this->character( [ 'group' => [ 'field' => 'clan', 'label' => 'Clan', 'value' => 'Brujah' ] ] ) ];

		$this->assertSame( [], Rumor_Generator::resolve_character_candidates( $characters, $this->toggles(), [] ) );
	}

	public function test_all_toggles_off_produces_nothing(): void {
		$characters = [ $this->character( [ 'influences' => [ 'Bureaucracy' ] ] ) ];
		$result     = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles(), [] );

		$this->assertSame( [], $result );
	}

	// -------------------------------------------------------------------------
	// Title de-duplication within one generation pass
	// -------------------------------------------------------------------------

	public function test_two_characters_of_the_same_race_produce_one_rumor_not_two(): void {
		$characters = [
			$this->character( [ 'name' => 'Marcus Vitel', 'stack_slug' => 'vampire', 'stack_label' => 'Vampire' ] ),
			$this->character( [ 'name' => 'Lucretia Nightshade', 'stack_slug' => 'vampire', 'stack_label' => 'Vampire' ] ),
		];
		$result = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'race_rumors' => true ] ), [] );

		$this->assertCount( 1, $result );
	}

	public function test_shared_influence_across_characters_produces_one_rumor(): void {
		$characters = [
			$this->character( [ 'name' => 'Marcus Vitel', 'influences' => [ 'Bureaucracy' ] ] ),
			$this->character( [ 'name' => 'Sara Redhawk', 'influences' => [ 'Bureaucracy' ] ] ),
		];
		$result = Rumor_Generator::resolve_character_candidates( $characters, $this->toggles( [ 'influence_rumors' => true ] ), [] );

		$this->assertCount( 1, $result );
	}

	public function test_title_already_existing_for_the_date_is_skipped(): void {
		$characters = [ $this->character( [ 'name' => 'Marcus Vitel' ] ) ];
		$result     = Rumor_Generator::resolve_character_candidates(
			$characters,
			$this->toggles( [ 'personal_rumors' => true ] ),
			[ 'Marcus Vitel' => true ]
		);

		$this->assertSame( [], $result );
	}
}
