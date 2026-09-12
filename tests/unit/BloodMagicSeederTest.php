<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * How vampire-blood-magic is built from the CSV's tradition/path Discipline rows (every
 * row with a real Group value). See BE_PROCESS/0.99.2-workflow.md's "Blood magic" section
 * for the design this implements and BE_PROCESS/blood-magic-paradigm.md for the original
 * measurement that motivated it. See MetCsvSeederTest for the ordinary-discipline side of
 * the same split (Group-less rows) and vampire-combo-disciplines (untouched by this split).
 *
 * @see BE_PROCESS/0.99.2-workflow.md
 */
class BloodMagicSeederTest extends TestCase {

	/** @var array<string,array> Built blocks, keyed by slug, loaded once. */
	private static $blocks;

	public static function setUpBeforeClass(): void {
		self::$blocks = [];
		foreach ( Seeder::get_blocks_to_seed() as $block ) {
			self::$blocks[ $block['slug'] ] = $block;
		}
	}

	private static function definition(): array {
		return self::$blocks['vampire-blood-magic']['definition'];
	}

	private static function power( string $name ): ?array {
		foreach ( self::definition()['powers'] as $power ) {
			if ( $power['name'] === $name ) {
				return $power;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Shape and counts - measured against the real shipped CSV. A change here
	// means the source file changed - re-measure, don't just widen the assertion.
	// -------------------------------------------------------------------------

	public function test_block_shape(): void {
		$this->assertArrayHasKey( 'vampire-blood-magic', self::$blocks );
		$this->assertSame( 'tiered_power', self::$blocks['vampire-blood-magic']['section_type'] );
		$this->assertTrue( self::definition()['blood_magic'] ?? false );
		$this->assertTrue( self::definition()['atomic'] ?? false );
	}

	public function test_path_and_tradition_counts(): void {
		$this->assertCount( 111, self::definition()['powers'] );
		$this->assertCount( 14, self::definition()['traditions'] );
	}

	/**
	 * The 14 real traditions, alphabetically - a fixed, curated list, not a guess. Bare
	 * "Hermetic" and the four Quietus caste entries are deliberately absent (see
	 * test_excluded_subtypes_never_become_traditions); Black Hand and Setite Sorcery are
	 * deliberately absent too (see test_ref_only_subtypes_contribute_no_tradition).
	 */
	public function test_the_real_tradition_list(): void {
		$this->assertSame(
			[
				'Akhu', 'Bacaban', 'Dark Thaumaturgy', 'Dur An Ki', 'Judicium', 'Koldunism',
				'Mortis', 'Nahuallotl', 'Necromancy', 'Sadhana', 'Sielanic',
				'Thaumaturgy (Anarch)', 'Thaumaturgy (Camarilla)', 'Wanga',
			],
			self::definition()['traditions']
		);
	}

	/**
	 * Black Hand's "Biothaumaturgic Experimentation" and Setite Sorcery's "Ushabti" are each
	 * a single row whose Rtg/lNum/lName/Cost are all the literal string 'Ref' - full
	 * cross-reference placeholders pointing at published sourcebook material, with zero real
	 * level data, excluded by the same is_met_ref_placeholder() filter every other Discipline
	 * row passes through. Neither may appear as a tradition, and no path may claim them as an
	 * offering tradition, since there is nothing real behind either one.
	 */
	public function test_ref_only_subtypes_contribute_no_tradition(): void {
		$this->assertNotContains( 'Black Hand', self::definition()['traditions'] );
		$this->assertNotContains( 'Setite Sorcery', self::definition()['traditions'] );

		foreach ( self::definition()['powers'] as $power ) {
			$this->assertArrayNotHasKey( 'Black Hand', $power['traditions'] );
			$this->assertArrayNotHasKey( 'Setite Sorcery', $power['traditions'] );
		}
	}

	/**
	 * Bare "Hermetic" is unlabelled data, not a tradition of its own - every path it carries
	 * (Path of Conjuring, Path of Corruption, Path of Curses) already exists under a real
	 * tradition elsewhere. The four Quietus entries are Assamite castes, not traditions - the
	 * same caste/tradition trap D39 avoided. None of the five may appear as a tradition label
	 * anywhere in the block.
	 */
	public function test_excluded_subtypes_never_become_traditions(): void {
		$excluded = [
			'Hermetic',
			'Quietus, Cruscitus / Warrior', 'Quietus, Hematus / Vizier',
			'Quietus, Minhit Dume / Vizier', 'Quietus, Sorcerer',
		];

		$this->assertEmpty( array_intersect( $excluded, self::definition()['traditions'] ) );

		foreach ( self::definition()['powers'] as $power ) {
			$this->assertEmpty(
				array_intersect( $excluded, array_keys( $power['traditions'] ) ),
				"'{$power['name']}' lists an excluded subtype as an offering tradition"
			);
		}
	}

	/**
	 * Every path excluding bare Hermetic and the Quietus castes still survives under a real
	 * tradition - confirms nothing was lost, only relabeled, by the exclusion above.
	 */
	public function test_hermetic_only_paths_survive_under_a_real_tradition(): void {
		foreach ( [ 'Path of Conjuring', 'Path of Corruption', 'Path of Curses' ] as $name ) {
			$power = self::power( $name );
			$this->assertNotNull( $power, "'{$name}' must still exist" );
			$this->assertNotEmpty( $power['traditions'] );
		}
	}

	// -------------------------------------------------------------------------
	// The core redesign: one path, tradition-agnostic, no duplication.
	// -------------------------------------------------------------------------

	/**
	 * Regression guard for the defect this whole feature replaces: "Path of Blood" used to be
	 * five separately-labeled tradition-prefixed catalog entries plus a sixth bare GVM-only
	 * copy. It is now exactly one power, offered by every tradition that used to duplicate it.
	 */
	public function test_path_of_blood_collapses_to_one_tradition_agnostic_path(): void {
		$matches = array_filter( self::definition()['powers'], static fn( $p ) => $p['name'] === 'Path of Blood' );
		$this->assertCount( 1, $matches, 'Path of Blood must exist exactly once' );

		$power = array_values( $matches )[0];
		foreach ( [ 'Akhu', 'Bacaban', 'Necromancy', 'Thaumaturgy (Camarilla)', 'Wanga' ] as $tradition ) {
			$this->assertArrayHasKey( $tradition, $power['traditions'], "{$tradition} must still offer Path of Blood" );
		}
		// Dur An Ki and Sadhana know this path under their own name, carried as the
		// alternate rather than lost.
		$this->assertSame( "Path of Life's Water", $power['traditions']['Dur An Ki'] );
		$this->assertSame( 'Path of Kali', $power['traditions']['Sadhana'] );
	}

	/**
	 * A leading "A/An/The" is stripped from every path's display name, not only when two
	 * spellings of the same path collide ("Snake Inside" vs "The Snake Inside") - a real
	 * .gex import (Chase Ashford, 2026-09-11) carries "Dur-An-Ki: Hunter's Wind" with no
	 * article at all, and the catalog must not require the importer to guess which form to
	 * try. Three real paths are affected: "The Dragon Path", "The Hunter's Wind" and "The
	 * Keeper's Way" all seed article-free.
	 */
	public function test_leading_article_is_always_stripped_from_the_display_name(): void {
		$names = array_column( self::definition()['powers'], 'name' );

		foreach ( [ 'Snake Inside', 'Dragon Path', "Hunter's Wind", "Keeper's Way" ] as $expected ) {
			$this->assertContains( $expected, $names );
		}
		foreach ( $names as $name ) {
			$this->assertDoesNotMatchRegularExpression( '/^(a|an|the)\s+/i', $name, "'{$name}' still carries a leading article" );
		}
	}

	/**
	 * @dataProvider bare_duplicate_names
	 *
	 * A GVM family bare-named after a canonical path (verified 2026-09-11: every one found
	 * was an empty container, contributing nothing) must not survive as a duplicate,
	 * unlabeled entry once this method has claimed the path.
	 */
	public function test_bare_gvm_duplicate_is_not_a_second_copy( string $name ): void {
		$ordinary_names = array_column( self::$blocks['vampire-disciplines']['definition']['powers'], 'name' );
		$this->assertNotContains( $name, $ordinary_names, "'{$name}' must not survive bare in vampire-disciplines" );

		$blood_magic_names = array_column( self::definition()['powers'], 'name' );
		$this->assertContains( $name, $blood_magic_names, "'{$name}' must exist exactly once, in vampire-blood-magic" );
	}

	public function bare_duplicate_names(): array {
		return array_map(
			static fn( $name ) => [ $name ],
			[
				'Alchemy', 'Ash Path', 'Biothaumaturgy', 'Bone Path', 'Cadaverous Animation',
				'Corpse in the Monster', 'Elemental Mastery', 'Fires of the Inferno',
				'Focused Mind', 'Four Humours', 'Gift of Morpheus', "Grave's Decay",
				'Hands of Destruction', 'Hearth Path', 'Lure of Flames',
				'Mastery of the Mortal Shell', 'Mortuus Path', 'Movement of the Mind',
				"Neptune's Might", 'Oneiromancy', 'Path of Blood', 'Path of Conjuring',
				'Path of Corruption', 'Path of Curses', "Path of Father's Vengeance",
				'Path of Mars', 'Path of Phobos', 'Path of Transmutation', 'Sepulchre Path',
				'Spirit Manipulation', 'Spirit Thaumaturgy', 'Taking of the Spirit',
				'Thaumaturgical Countermagic', 'Green Path', 'Vine of Dionysus',
				'Vitreous Path', 'Weather Control',
			]
		);
	}

	// -------------------------------------------------------------------------
	// Restrictions - a trailing slash segment naming a caste/covenant, not an alternate name.
	// -------------------------------------------------------------------------

	/**
	 * Exactly three real rows carry a restriction keyword as the trailing Group segment.
	 * Verified 2026-09-11 - a change here means the source file changed.
	 */
	public function test_restriction_bearing_paths(): void {
		$restricted = [];
		foreach ( self::definition()['powers'] as $power ) {
			if ( isset( $power['restriction'] ) ) {
				$restricted[ $power['name'] ] = $power['restriction'];
			}
		}

		$this->assertSame(
			[
				'Gift of Morpheus' => 'Sabbat',
				"Keeper's Way"     => 'Loyalist Only',
				'Path of Mars'     => 'Warrior Only',
			],
			$restricted
		);
	}

	/**
	 * The three-segment case: "Gift of Morpheus / Path of Morpheus / Sabbat" is canonical
	 * "Gift of Morpheus", Thaumaturgy (Camarilla)'s own alternate name "Path of Morpheus", and
	 * a Sabbat restriction - not an alternate name of "Sabbat".
	 */
	public function test_restriction_is_never_mistaken_for_an_alternate_name(): void {
		$power = self::power( 'Gift of Morpheus' );
		$this->assertNotNull( $power );
		$this->assertSame( 'Sabbat', $power['restriction'] );
		$this->assertSame( 'Path of Morpheus', $power['traditions']['Thaumaturgy (Camarilla)'] );
		$this->assertNotContains( 'Sabbat', $power['traditions'] );
	}

	// -------------------------------------------------------------------------
	// The same-name-across-levels defect: dedupe_met_rows_for_ladder() and
	// pick_blood_magic_ladder()'s informative/placeholder split.
	// -------------------------------------------------------------------------

	/**
	 * No path may seed with zero levels - every one of the 111 paths has a real ladder
	 * somewhere among its offering traditions.
	 */
	public function test_no_path_is_empty(): void {
		foreach ( self::definition()['powers'] as $power ) {
			$this->assertNotEmpty( $power['levels'], "'{$power['name']}' has no levels at all" );
		}
	}

	/**
	 * Judicium's "Path of Mercury" gives every one of its five real levels (costing
	 * 3/3/6/6/9) the identical Name text "Path of Mercury" - the source material names the
	 * path, not the rung. dedupe_met_rows_by_name() (Name only) would collapse this to a
	 * single level; dedupe_met_rows_for_ladder() (Name + lNum) keeps all five.
	 */
	public function test_identically_named_levels_are_not_collapsed_by_dedup(): void {
		$power = self::power( 'Path of Mercury' );
		$this->assertNotNull( $power );
		$this->assertCount( 5, $power['levels'] );
		$this->assertSame( [ '3', '3', '6', '6', '9' ], array_column( $power['levels'], 'cost' ) );
	}

	/**
	 * Two placeholder candidates for the same path (Sadhana and Hermetic Anarch each give
	 * "Path of Blood Nectar" all-identical level names, same 3/3/6/6/9 cost progression) must
	 * resolve to one five-level ladder, not a naive ten - merging placeholder candidates by
	 * name silently collapses one side's distinct levels while accepting the other's
	 * unchanged, which previously produced six, not five or ten. Regression guard for the
	 * exact bug pick_blood_magic_ladder()'s informative/placeholder split fixes.
	 */
	public function test_two_placeholder_candidates_do_not_inflate_the_ladder(): void {
		$power = self::power( 'Path of Blood Nectar' );
		$this->assertNotNull( $power );
		$this->assertCount( 5, $power['levels'] );
	}

	/**
	 * One informative candidate (Wanga names all five levels distinctly) plus one placeholder
	 * candidate (Sadhana repeats "Path of Woe" for all five, same costs) must resolve to
	 * Wanga's five real names, not all ten - naive name-based merging inflated this to ten
	 * because none of Wanga's real names matched Sadhana's repeated placeholder text.
	 */
	public function test_placeholder_candidate_never_merges_against_an_informative_one(): void {
		$power = self::power( 'Path of Woe' );
		$this->assertNotNull( $power );
		$this->assertCount( 5, $power['levels'] );
		$this->assertContains( 'Finding the Locus', array_column( $power['levels'], 'power_name' ) );
		$this->assertNotContains( 'Path of Woe', array_column( $power['levels'], 'power_name' ) );
	}

	/**
	 * Two genuinely informative candidates (real, mostly-distinct names on both sides) still
	 * merge exactly as before this fix - only placeholder candidates are treated specially.
	 * "Path of Blood" gains three real Elder-tier names (Akhu/Bacaban/etc.'s shared five plus
	 * Necromancy's or Wanga's three additional named levels) for eight total.
	 */
	public function test_two_informative_candidates_still_merge_normally(): void {
		$power = self::power( 'Path of Blood' );
		$this->assertNotNull( $power );
		$this->assertCount( 8, $power['levels'] );
		$this->assertSame( count( $power['levels'] ), count( array_unique( array_column( $power['levels'], 'power_name' ) ) ) );
	}
}
