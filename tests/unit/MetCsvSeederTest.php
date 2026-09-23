<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * How the MET-Mechanics CSV overlay builds schema-block content, once resolved by the
 * Seeder. See tests/unit/MetCsvParserTest.php for parsing itself, tests/unit/SeederMapTest.php
 * for the GVM-only path this overlay sits on top of.
 *
 * @see BE_PROCESS/releases/workflow-0.10.md
 *
 * **1.3.2 note.** `vampire-disciplines`/`vampire-combo-disciplines`/`vampire-blood-magic`
 * now also have declared JSON files, preferred over these GVM/CSV-built blocks in the real
 * seed list (`Seeder::get_blocks_to_seed()`). This test is about the CSV overlay mechanism
 * itself, not about which block ships today, so it reads `get_gvm_blocks_to_seed()` (the
 * pre-overlay path) directly and stays exactly as it was before the declared catalog
 * existed.
 */
class MetCsvSeederTest extends TestCase {

	/** @var array<string,array> Built blocks, keyed by slug, loaded once. */
	private static $blocks;

	public static function setUpBeforeClass(): void {
		self::$blocks = [];
		foreach ( Seeder::get_gvm_blocks_to_seed() as $block ) {
			self::$blocks[ $block['slug'] ] = $block;
		}
	}

	// -------------------------------------------------------------------------
	// Cost sign normalization
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider cost_cases
	 */
	public function test_normalize_met_cost( string $raw, string $expected ): void {
		$this->assertSame( $expected, Seeder::normalize_met_cost( $raw ) );
	}

	public function cost_cases(): array {
		return [
			'blank stays blank'                 => [ '', '' ],
			'plain positive is untouched'        => [ '2', '2' ],
			'"or" set is untouched'              => [ '2 or 5', '2 or 5' ],
			// The CSV's own Flaw-cost shape: sign stripped, a stored magnitude like every
			// other negative trait_list block (the block's own `negative` flag re-applies
			// the sign at runtime).
			'negative fixed cost loses its sign' => [ '-2', '2' ],
			'negative range: sign stripped'      => [ '-1 to -5', '1-5' ],
			'positive range: "to" becomes "-"'   => [ '1 to 3', '1-3' ],
			'mixed-case "To"'                    => [ '1 To 3', '1-3' ],
			// A Merit anomaly found in the real data - same rule, no special-casing.
			'a positive-type raw negative'       => [ '-2', '2' ],
		];
	}

	// -------------------------------------------------------------------------
	// Subtype label / background routing map
	// -------------------------------------------------------------------------

	public function test_met_csv_map_shape(): void {
		$map = Seeder::met_csv_map();

		$this->assertArrayHasKey( 'discipline_labels', $map );
		$this->assertArrayHasKey( 'ritual_labels', $map );
		$this->assertArrayHasKey( 'background_routing', $map );

		$this->assertSame( 'Thaumaturgy', $map['discipline_labels']['Hermetic'] );
		$this->assertSame( 'Thaumaturgy', $map['ritual_labels']['Hermetic'] );
	}

	/**
	 * 'bete' is a literal alias of 'fera' (Seeder::get_stacks_to_seed()) - it has no block
	 * slugs of its own. A routing table entry naming a non-existent 'bete-backgrounds' slug
	 * would silently no-op, so this guards against that mistake ever creeping back in.
	 */
	public function test_background_routing_never_names_a_bete_slug(): void {
		$map = Seeder::met_csv_map();

		foreach ( $map['background_routing'] as $subtype => $slugs ) {
			foreach ( $slugs as $slug ) {
				$this->assertStringStartsNotWith( 'bete-', $slug, "background_routing['{$subtype}'] names a non-existent bete-* slug" );
			}
		}
	}

	/**
	 * Every slug the routing table names must actually exist among the real
	 * '{stack}-backgrounds' blocks (Seeder::get_stacks_to_seed(), one per non-alias stack -
	 * 'bete' is excluded, see the guard above), or a Background row would silently never
	 * reach anyone.
	 */
	public function test_background_routing_targets_are_real_backgrounds_slugs(): void {
		$map           = Seeder::met_csv_map();
		$real_bg_slugs = [
			'vampire-backgrounds', 'werewolf-backgrounds', 'mage-backgrounds',
			'changeling-backgrounds', 'wraith-backgrounds', 'demon-backgrounds',
			'mummy-backgrounds', 'kueijin-backgrounds', 'mortal-backgrounds',
			'fera-backgrounds',
		];

		foreach ( $map['background_routing'] as $subtype => $slugs ) {
			foreach ( $slugs as $slug ) {
				$this->assertContains( $slug, $real_bg_slugs, "background_routing['{$subtype}'] names '{$slug}', which no stack actually references" );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Discipline + Combo Discipline building
	// -------------------------------------------------------------------------

	/**
	 * Measured against the real shipped CSV. A change here means the source file changed -
	 * re-measure, don't just widen the assertion.
	 *
	 * vampire-disciplines dropped from 281 to 68 when Blood Magic paths (every Discipline
	 * row with a real Group value) moved to their own block - see
	 * BE_PROCESS/releases/0.99.2-workflow.md's "Blood magic" section and BloodMagicSeederTest for the
	 * block that content moved to. Combo count is untouched by that split: Combination
	 * rows were never part of it either way.
	 *
	 * 68 dropped again to 57 (1.1.0, owner report - an unbuyable 5th level surfaced a
	 * systemic bug): eleven of those sixty-eight were never real, leveled Disciplines at
	 * all - GVM's own "Disciplines, <Clan>" menus (Assamite, Brujah, Einherjar, Followers
	 * of Set, Gangrel, Lasombra, Ravnos, Toreador, Tremere, Tzimisce, Ventrue) are each
	 * that clan's own signature combination powers, named explicitly in
	 * Seeder::CLAN_SIGNATURE_COMBO_MENUS rather than detected by shape (a first attempt
	 * that flagged any family with a "+"-joined item note broke `ImportControllerCommitTest
	 * ::test_commit_resolves_real_tiered_power_traits` - "Disciplines, Long Night Combo" has
	 * the identical shape but is not a clan, and its items already resolved correctly
	 * under their own bare CSV row; left exactly as it was, logged as D65, not folded into
	 * this fix). Combo count rose from 384 to 390: the eleven clans' own real combo items
	 * enter this block, several (e.g. "Shroud of Absence") superseding an already-present
	 * bare, unprefixed CSV row rather than duplicating it.
	 */
	public function test_discipline_and_combo_counts(): void {
		$this->assertCount( 57, self::$blocks['vampire-disciplines']['definition']['powers'] );
		$this->assertCount( 390, self::$blocks['vampire-combo-disciplines']['definition']['items'] );
	}

	/**
	 * 1.1.0 D3: a held combo's stored count is its flat XP cost, not a rating - the
	 * flag that lets the sheet draw it as "6 XP" instead of an unreadable wall of pips.
	 */
	public function test_combo_disciplines_are_flagged_count_is_cost(): void {
		$this->assertTrue( self::$blocks['vampire-combo-disciplines']['definition']['count_is_cost'] );
	}

	/**
	 * "Path of Blood" used to be five separately-labeled tradition-prefixed entries
	 * (Akhu/Bacaban/Thaumaturgy (Camarilla)/Necromancy/Wanga) plus GVM's own bare "Path of
	 * Blood" family standing apart because the five tied for the best match against it - the
	 * exact duplication the Blood Magic redesign exists to remove (see
	 * BloodMagicSeederTest::test_path_of_blood_collapses_to_one_tradition_agnostic_path for
	 * where it lives and how it is now modeled). None of that may survive in
	 * vampire-disciplines: not bare, not under any tradition prefix.
	 */
	public function test_path_of_blood_no_longer_lives_in_ordinary_disciplines(): void {
		$names = array_column( self::$blocks['vampire-disciplines']['definition']['powers'], 'name' );

		$this->assertNotContains( 'Path of Blood', $names );
		foreach ( [ 'Akhu', 'Bacaban', 'Thaumaturgy (Camarilla)', 'Necromancy', 'Wanga' ] as $tradition ) {
			$this->assertNotContains( "{$tradition}: Path of Blood", $names, "{$tradition}: Path of Blood must have moved to vampire-blood-magic" );
		}
	}

	/**
	 * Every Discipline row this class routes to vampire-blood-magic (any row with a real
	 * Group value) must actually be gone from here - the two blocks partition the CSV's
	 * Discipline rows, they do not both draw from the same pool.
	 */
	public function test_no_tradition_prefixed_name_survives_in_ordinary_disciplines(): void {
		foreach ( array_column( self::$blocks['vampire-disciplines']['definition']['powers'], 'name' ) as $name ) {
			$this->assertStringNotContainsString( ':', $name, "'{$name}' looks tradition-prefixed and belongs in vampire-blood-magic instead" );
		}
	}

	/**
	 * A core discipline (no Group value - Subtype IS the discipline name) is named plainly,
	 * with no tradition prefix - "Animalism", not "Animalism: Animalism".
	 */
	public function test_core_disciplines_are_named_plainly(): void {
		$names = array_column( self::$blocks['vampire-disciplines']['definition']['powers'], 'name' );

		$this->assertContains( 'Animalism', $names );
		$this->assertNotContains( 'Animalism: Animalism', $names );
	}

	/**
	 * 1.1.0, owner report: a clan's own signature combination powers
	 * ("Disciplines, Lasombra" etc. in GVM) are not a real, leveled Discipline - they
	 * never survive in vampire-disciplines under the clan's own name at all, real or
	 * fake-leveled.
	 */
	public function test_clan_signature_combos_do_not_survive_as_fake_disciplines(): void {
		$names = array_column( self::$blocks['vampire-disciplines']['definition']['powers'], 'name' );

		foreach ( [ 'Assamite', 'Brujah', 'Einherjar', 'Followers of Set', 'Gangrel', 'Lasombra', 'Ravnos', 'Toreador', 'Tremere', 'Tzimisce', 'Ventrue' ] as $clan ) {
			$this->assertNotContains( $clan, $names, "'{$clan}' is a clan's own combo-power menu, not a Discipline" );
		}
	}

	/**
	 * Each clan's real combo powers land in vampire-combo-disciplines instead, named
	 * "<Clan>: <power>" per the owner's own ruling, carrying GVM's real cost and a
	 * readable recipe note built from its "+"-joined prerequisite text.
	 */
	public function test_clan_signature_combos_land_in_combo_disciplines_named_and_costed(): void {
		$items = self::$blocks['vampire-combo-disciplines']['definition']['items'];
		$by_name = [];
		foreach ( $items as $item ) {
			$by_name[ $item['name'] ] = $item;
		}

		$this->assertArrayHasKey( 'Lasombra: Shroud of Absence', $by_name );
		$this->assertSame( '12', $by_name['Lasombra: Shroud of Absence']['cost'] );
		$this->assertSame( 'Forgetful Mind + Arms Of The Abyss', $by_name['Lasombra: Shroud of Absence']['note'] );

		$this->assertArrayNotHasKey( 'Shroud of Absence', $by_name, 'The bare CSV row must be superseded, not left duplicated alongside the prefixed one' );
	}

	/**
	 * Every numbered power ladder in vampire-disciplines runs to a real level 5 (or is a
	 * genuinely shorter real ladder some sourcebook never extended past) with no
	 * mid-ladder gap - the owner's own "no empty power levels" ruling. This is the
	 * regression guard for the Quietus caste-variant bug specifically (four families
	 * capped at level 4 with a real, unbought level 5 sitting in the CSV all along).
	 */
	/**
	 * D66 (1.2.5-design-workflow.md §A, A1): a rank's real numeric `level` only
	 * survives on an item when nothing else shares its tier - two or more items
	 * at the same tier all get `level: null` (the named-pool shape Decision 037
	 * already established for Elder-and-above). "No gap" is therefore checked
	 * against the family's real TIER coverage, not the surviving numeric values,
	 * which a tie can legitimately null out anywhere in the ladder.
	 */
	public function test_no_discipline_ladder_has_a_gap_in_its_tier_coverage(): void {
		$tier_rank = [
			'basic' => 1, 'intermediate' => 2, 'advanced' => 3, 'elder' => 4,
			'master' => 5, 'ascended' => 6, 'methuselah' => 7,
		];
		foreach ( self::$blocks['vampire-disciplines']['definition']['powers'] as $power ) {
			$ranks = [];
			foreach ( $power['levels'] as $level ) {
				$tier = $level['tier'] ?? null;
				if ( isset( $tier_rank[ $tier ] ) ) {
					$ranks[ $tier_rank[ $tier ] ] = true;
				}
			}
			if ( empty( $ranks ) ) {
				continue;
			}
			$ranks = array_keys( $ranks );
			sort( $ranks );
			$max     = max( $ranks );
			$missing = array_diff( range( 1, $max ), $ranks );
			$this->assertSame( [], array_values( $missing ), "{$power['name']} is missing tier rank(s) " . implode( ',', $missing ) . " below its own max of {$max}" );
		}
	}

	/**
	 * The four Quietus caste variants specifically: each is a real 5-item family
	 * (two tied basic powers, two tied intermediate powers, one untied advanced
	 * power) topping out at Advanced - real source data, confirmed by direct
	 * measurement, never reaching Elder or Master for any of the four. The old
	 * array-position numberer (D66) counted these five items as levels 1-5
	 * regardless of tier, which is exactly the fabricated-progression bug this
	 * fix removes - "reaches level 5" was true only of that bug.
	 */
	public function test_quietus_caste_variants_cover_basic_through_advanced_and_no_higher(): void {
		$by_name = [];
		foreach ( self::$blocks['vampire-disciplines']['definition']['powers'] as $power ) {
			$by_name[ $power['name'] ] = $power;
		}

		foreach ( [ 'Quietus, Cruscitus / Warrior', 'Quietus, Hematus / Vizier', 'Quietus, Minhit Dume / Vizier', 'Quietus, Sorcerer' ] as $name ) {
			$this->assertArrayHasKey( $name, $by_name );

			// 1.2.10 S2: a family's levels now live in three containers, so the tier check
			// reads across all of them - the assertion is about what the caste variant
			// *covers*, which the split must not change.
			$power = $by_name[ $name ];
			$all   = $power['levels'];
			foreach ( ( $power['elder'] ?? [] ) as $picks ) {
				$all = array_merge( $all, $picks );
			}
			$all = array_merge( $all, $power['overflow'] ?? [] );

			$tiers = array_unique( array_column( $all, 'tier' ) );
			sort( $tiers );
			$this->assertSame( [ 'advanced', 'basic', 'intermediate' ], $tiers, "{$name} must cover basic/intermediate/advanced and no higher" );

			// Rungs are numbered by their place on the declared ladder now, not by which
			// tier happened to be untied - so a caste variant covering all three ladder
			// ranks numbers them consecutively from 1, with no gaps.
			$numbered = array_column( $power['levels'], 'level' );
			$this->assertSame( range( 1, count( $numbered ) ), $numbered, "{$name}'s rungs number consecutively from 1" );
			$this->assertLessThanOrEqual( 5, count( $numbered ), "{$name} cannot exceed the declared 2+2+1 ladder" );
		}
	}

	/**
	 * The CSV's Description column is never surfaced by the parser at all (see
	 * MET_CSV_Parser::KEPT_COLUMNS) - this asserts the built blocks reflect that all the way
	 * through, not just at the parsing layer.
	 */
	public function test_no_description_leaks_into_built_blocks(): void {
		foreach ( self::$blocks['vampire-combo-disciplines']['definition']['items'] as $item ) {
			$this->assertArrayNotHasKey( 'description', $item );
		}
		foreach ( [ 'vampire-disciplines', 'vampire-blood-magic' ] as $slug ) {
			foreach ( self::$blocks[ $slug ]['definition']['powers'] as $power ) {
				foreach ( $power['levels'] as $level ) {
					$this->assertArrayNotHasKey( 'description', $level );
				}
			}
		}
	}

	public function test_no_duplicate_power_or_combo_names(): void {
		foreach ( [ 'vampire-disciplines', 'vampire-blood-magic' ] as $slug ) {
			$power_names = array_column( self::$blocks[ $slug ]['definition']['powers'], 'name' );
			$this->assertSame( count( $power_names ), count( array_unique( $power_names ) ), "duplicate power family name in {$slug}" );
		}

		$combo_names = array_column( self::$blocks['vampire-combo-disciplines']['definition']['items'], 'name' );
		$this->assertSame( count( $combo_names ), count( array_unique( $combo_names ) ), 'duplicate combo discipline name' );
	}

	/**
	 * Every 'Ref' cross-reference placeholder (17 in the shipped CSV) must be excluded, not
	 * seeded as a real, costless, level-less catalog entry.
	 */
	public function test_ref_placeholders_are_excluded(): void {
		foreach ( [ 'vampire-disciplines', 'vampire-blood-magic' ] as $slug ) {
			foreach ( self::$blocks[ $slug ]['definition']['powers'] as $power ) {
				foreach ( $power['levels'] as $level ) {
					$this->assertNotSame( 'Ref', $level['tier'] );
				}
			}
		}
	}

	/**
	 * vampire-disciplines keeps its atomic flag (Query_Engine::block_is_atomic()) across the
	 * CSV overlay - D14: atomic is a hand-curated per-creature-class constant, not menu/CSV
	 * data, and must not silently disappear just because the content source changed.
	 * vampire-blood-magic is atomic for the same reason (it is a split of the same
	 * originally-atomic Disciplines menu) - see BloodMagicSeederTest for its own coverage.
	 */
	public function test_vampire_disciplines_stays_atomic(): void {
		$this->assertTrue( self::$blocks['vampire-disciplines']['definition']['atomic'] ?? false );
	}

	// -------------------------------------------------------------------------
	// Ritual merging
	// -------------------------------------------------------------------------

	/**
	 * Measured against the real shipped CSV + GVM data. A change here means one of the two
	 * source files changed - re-measure, don't just widen the assertion.
	 *
	 * Stays 1291, 1.3.0 C8 (1.3.0-design-workflow.md §5.1 rules 1/2): the raw GVM side of
	 * this merge counted six same-name rituals twice, but dedup has to run on each item's
	 * real *final* (labeled) name, not its raw one - "Craft Bloodstone," "Dominoe of
	 * Life," and "Impassable Trail" each print at the same cost in both "Rituals, Basic"
	 * and "Rituals, Sabbat, Basic," but resolve to genuinely different labeled rows
	 * ("Thaumaturgy: ..." vs "Sabbat: ...") - real, distinct catalog entries, not a
	 * duplicate. "Eyes of the Grave" (Mortis + Necromancy) is the same shape - "Mortis: ..."
	 * and "Necromancy: ..." stay separate. Only "Grasp the Ghostly" and "Healing Blood"
	 * are genuine duplicates: their own notes ("adv. necro"/"adv. assamite") trigger
	 * `note_overrides`, which resolves both occurrences to the identical final label
	 * ("Necromancy: Grasp the Ghostly (adv)", "Assamite: Healing Blood (adv)") - those two
	 * collapse to one row each (205 raw items, not 207); the CSV overlay's own downstream
	 * total is unaffected either way, since it was already independently deduping this
	 * exact overlap on its own end.
	 */
	public function test_ritual_count(): void {
		$this->assertCount( 1291, self::$blocks['vampire-rituals']['definition']['items'] );
	}

	/**
	 * "Ward Versus Ghouls" is one of 63 real GVM ritual names (verified directly) with no
	 * match anywhere in this CSV export - a wholesale replace would have silently deleted it.
	 * GVM's own existing, already-correctly-labeled item must survive the merge untouched.
	 */
	public function test_gvm_only_rituals_survive_the_merge(): void {
		$names = array_column( self::$blocks['vampire-rituals']['definition']['items'], 'name' );

		$this->assertContains( 'Thaumaturgy: Ward Versus Ghouls (basic)', $names );
	}

	/**
	 * "Grasp the Ghostly" is a real ritual offered separately by Necromancy and Wanga alike -
	 * both must stay independently pickable, the same rule already established and shipped
	 * for vampire-rituals (v0.9.20).
	 */
	public function test_ritual_multi_tradition_offerings_stay_separate(): void {
		$names = array_column( self::$blocks['vampire-rituals']['definition']['items'], 'name' );

		$this->assertContains( 'Necromancy: Grasp the Ghostly (adv)', $names );
		$this->assertContains( 'Wanga: Grasp the Ghostly (Advanced)', $names );
	}

	/**
	 * Regression guard for a real, pre-existing (pre-dating this CSV work) defect found while
	 * merging: GVM's own data cross-lists "Grasp the Ghostly" in both the general "Rituals,
	 * Advanced" menu (tagged "necro", relabeled via note_overrides) AND natively in "Rituals,
	 * Necromancy" - two distinct GVM menu entries that, before dedupe_built_items_by_name()
	 * existed, both resolved to the identical string "Necromancy: Grasp the Ghostly (adv)"
	 * and shipped as a true duplicate in v0.9.20.
	 */
	public function test_no_duplicate_ritual_names(): void {
		$names = array_column( self::$blocks['vampire-rituals']['definition']['items'], 'name' );
		$this->assertSame( count( $names ), count( array_unique( $names ) ) );
	}

	public function test_vampire_rituals_stays_atomic(): void {
		$this->assertTrue( self::$blocks['vampire-rituals']['definition']['atomic'] ?? false );
	}

	// -------------------------------------------------------------------------
	// Archetype merging
	// -------------------------------------------------------------------------

	private function archetype_options(): array {
		foreach ( self::$blocks['met-archetypes']['definition']['fields'] as $field ) {
			if ( $field['name'] === 'Nature' ) {
				return $field['options'];
			}
		}
		$this->fail( 'Nature field not found on met-archetypes.' );
	}

	/**
	 * Measured against the real shipped CSV + GVM data. A change here means one of the two
	 * source files changed - re-measure, don't just widen the assertion.
	 */
	public function test_archetype_count(): void {
		$options = $this->archetype_options();
		$this->assertCount( 320, $options );
		$this->assertSame( count( $options ), count( array_unique( $options ) ) );
	}

	/**
	 * "Interrogator" is one of 14 real GVM Archetype names (verified directly) with no match
	 * in this CSV export - a wholesale replace would have silently deleted it.
	 */
	public function test_gvm_only_archetypes_survive_the_merge(): void {
		$this->assertContains( 'Interrogator', $this->archetype_options() );
	}

	public function test_archetype_options_are_alphabetized(): void {
		$options = $this->archetype_options();
		$sorted  = $options;
		sort( $sorted );
		$this->assertSame( $sorted, $options );
	}

	public function test_demeanor_shares_the_same_options_as_nature(): void {
		$nature = $this->archetype_options();
		foreach ( self::$blocks['met-archetypes']['definition']['fields'] as $field ) {
			if ( $field['name'] === 'Demeanor' ) {
				$this->assertSame( $nature, $field['options'] );
				return;
			}
		}
		$this->fail( 'Demeanor field not found on met-archetypes.' );
	}

	// -------------------------------------------------------------------------
	// Clan / Morality Path / Revenant Family merging
	// -------------------------------------------------------------------------

	private function identity_field_options( string $slug, string $field_name ): array {
		foreach ( self::$blocks[ $slug ]['definition']['fields'] as $field ) {
			if ( $field['name'] === $field_name ) {
				return $field['options'];
			}
		}
		$this->fail( "{$field_name} field not found on {$slug}." );
	}

	/**
	 * Measured against the real shipped CSV + GVM data. A change here means one of the two
	 * source files changed - re-measure, don't just widen the assertion.
	 */
	public function test_clan_and_morality_path_counts(): void {
		$clans = $this->identity_field_options( 'vampire-identity', 'Clan' );
		$this->assertCount( 87, $clans );
		$this->assertSame( count( $clans ), count( array_unique( $clans ) ) );

		$paths = $this->identity_field_options( 'vampire-identity', 'Morality Path' );
		$this->assertCount( 103, $paths );
		$this->assertSame( count( $paths ), count( array_unique( $paths ) ) );
	}

	/**
	 * "Assamites" (Clan) and "Path of Ecstacy" (Morality Path) are real GVM names with no
	 * match in this CSV export (12 and 4 such names respectively, verified directly) - a
	 * wholesale replace would have silently deleted them.
	 */
	public function test_gvm_only_clans_and_paths_survive_the_merge(): void {
		$this->assertContains( 'Assamites', $this->identity_field_options( 'vampire-identity', 'Clan' ) );
		$this->assertContains( 'Ghoul', $this->identity_field_options( 'vampire-identity', 'Clan' ) );
		$this->assertContains( 'Path of Ecstacy', $this->identity_field_options( 'vampire-identity', 'Morality Path' ) );
	}

	/**
	 * Revenant Family is new - the CSV's 20 ghoul-family names ("they are ghouls," not a
	 * Vampire-stack concept) land on mortal-identity, not vampire-identity, with no GVM
	 * equivalent to merge against.
	 */
	public function test_revenant_family_lands_on_mortal_identity(): void {
		$options = $this->identity_field_options( 'mortal-identity', 'Revenant Family' );
		$this->assertCount( 20, $options );
		$this->assertContains( 'Bratovitch', $options );
	}

	// -------------------------------------------------------------------------
	// Merit / Flaw / Ability / Background merging
	// -------------------------------------------------------------------------

	/**
	 * Measured against the real shipped CSV + GVM data. A change here means one of the two
	 * source files changed - re-measure, don't just widen the assertion. All three are pure
	 * union (no GVM-vs-CSV replace decision was ever in question here, unlike Discipline/
	 * Ritual/Archetype/Clan/Path) so counts only ever grow relative to GVM-alone.
	 */
	public function test_merit_flaw_ability_counts(): void {
		foreach ( [ 'met-merits' => 427, 'met-flaws' => 414, 'met-abilities' => 51 ] as $slug => $expected ) {
			$names = array_column( self::$blocks[ $slug ]['definition']['items'], 'name' );
			$this->assertCount( $expected, $names, "{$slug} count" );
			$this->assertSame( count( $names ), count( array_unique( $names ) ), "{$slug} has a duplicate name" );
		}
	}

	/**
	 * met-flaws' structural flags (negative, atomic) must survive the CSV merge - D14's
	 * atomic flag and the negative-cost-sign convention are per-block constants, not
	 * something either data source carries, and losing them silently would mean every CSV-
	 * merged Flaw's cost gets the wrong sign at runtime (Cost_Engine::price_trait_list_change()
	 * reads $definition->negative directly).
	 */
	public function test_flaw_and_merit_keep_their_structural_flags(): void {
		$this->assertTrue( self::$blocks['met-flaws']['definition']['negative'] ?? false );
		$this->assertTrue( self::$blocks['met-flaws']['definition']['atomic'] ?? false );
		$this->assertTrue( self::$blocks['met-merits']['definition']['atomic'] ?? false );
		$this->assertTrue( self::$blocks['met-abilities']['definition']['has_specializations'] ?? false );
	}

	/**
	 * Real regression guard for the exact bug normalize_met_cost() exists to prevent: the
	 * CSV stores Flaw costs already negative ("-1 to -3" for Addiction); met-flaws' own
	 * `negative` flag re-applies the sign at runtime, so the stored value must be the
	 * positive magnitude "1-3", not "-1--3" or any other double-inverted shape.
	 */
	public function test_flaw_costs_are_stored_as_positive_magnitudes(): void {
		foreach ( self::$blocks['met-flaws']['definition']['items'] as $item ) {
			if ( ! isset( $item['cost'] ) ) {
				continue;
			}
			$this->assertStringNotContainsString( '-1', substr( $item['cost'], 0, 2 ), "{$item['name']}'s cost '{$item['cost']}' looks double-signed" );
		}
	}

	/**
	 * Background rows route by Subtype to specific stacks (Mage/Fae/Changing
	 * Breeds/Wraith/Vampire) and to every stack via "General" - measured against the real
	 * shipped CSV + GVM data.
	 */
	public function test_background_counts_by_stack(): void {
		$expected = [
			'vampire-backgrounds'    => 56,
			'mage-backgrounds'       => 77,
			'changeling-backgrounds' => 73,
			'werewolf-backgrounds'   => 66,
			'wraith-backgrounds'     => 60,
			'demon-backgrounds'      => 54,
		];
		foreach ( $expected as $slug => $count ) {
			$names = array_column( self::$blocks[ $slug ]['definition']['items'], 'name' );
			$this->assertCount( $count, $names, "{$slug} count" );
			$this->assertSame( count( $names ), count( array_unique( $names ) ), "{$slug} has a duplicate name" );
		}
	}

	/**
	 * No CSV row names an explicit 'bete-backgrounds' target (only 'fera-backgrounds' is
	 * real - see test_background_routing_never_names_a_bete_slug()) - confirm build_met_backgrounds()
	 * never produces one either, end to end.
	 */
	public function test_no_bete_backgrounds_block_is_ever_built(): void {
		$this->assertArrayNotHasKey( 'bete-backgrounds', self::$blocks );
	}

	// name_pt/power_name_pt coverage-floor regression guards used to live here, against the
	// blocks this class builds (i18n-pt-br-design.md). 1.2.0 (§8/B9) retired that mechanism:
	// Seeder no longer writes name_pt at all (its 13 call sites deleted), so a built block
	// never carries one to measure. The same coverage-floor discipline (real measured
	// minimums, not a bare non-zero check) now runs against the translations table instead -
	// see CatalogTranslationMigrationThreadTest's T8 section, which also proves a reseed
	// cannot touch what's there, unlike the definition-embedded mechanism this replaced.
}
