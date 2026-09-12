<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * The GVM block map must name menus that actually exist.
 *
 * Regression guard for defect D5. The map was previously twenty-eight inline isset()
 * checks; six named menus that appear nowhere in the file, so six schema blocks — the core
 * Physical, Social and Mental attributes, referenced by all eleven creature stacks — were
 * never created. isset() fails silently. This test does not.
 *
 * @see BE_PROCESS/workflow-0.2.2.md
 */
class SeederMapTest extends TestCase {

	/** @var array Parsed GVM menus, loaded once. */
	private static $gvm;

	public static function setUpBeforeClass(): void {
		self::$gvm = Seeder::parse_gvm();
	}

	public function test_map_is_not_empty(): void {
		$this->assertNotEmpty( Seeder::block_map() );
	}

	/**
	 * Every menu the map names must be present in the real menu file.
	 */
	public function test_every_named_menu_exists(): void {
		$missing = [];

		foreach ( Seeder::block_map() as $slug => $entry ) {
			$names = [];

			if ( $entry['source'] === 'menu' || $entry['source'] === 'container' ) {
				$names[] = $entry['menu'];
			} elseif ( $entry['source'] === 'merge' ) {
				$names = $entry['menus'];
			}

			foreach ( $names as $name ) {
				if ( ! isset( self::$gvm[ $name ] ) ) {
					$missing[] = "{$slug} -> '{$name}'";
				}
			}
		}

		$this->assertSame(
			[],
			$missing,
			"Block map names menus that do not exist:\n  " . implode( "\n  ", $missing )
		);
	}

	/**
	 * Every pattern entry must match at least one menu.
	 */
	public function test_every_pattern_matches_something(): void {
		foreach ( Seeder::block_map() as $slug => $entry ) {
			if ( $entry['source'] !== 'pattern' ) {
				continue;
			}

			$matched = 0;
			foreach ( self::$gvm as $name => $menu ) {
				if ( ! preg_match( $entry['pattern'], $name ) ) {
					continue;
				}
				if ( isset( $entry['category'] ) && (int) $menu['category'] !== (int) $entry['category'] ) {
					continue;
				}
				$matched++;
			}

			$this->assertGreaterThan(
				0,
				$matched,
				"Pattern '{$entry['pattern']}' for {$slug} matched no menus."
			);
		}
	}

	/**
	 * Every container entry must resolve all of its submenus.
	 */
	public function test_every_container_resolves_completely(): void {
		$unresolved = [];

		foreach ( Seeder::block_map() as $slug => $entry ) {
			if ( $entry['source'] !== 'container' ) {
				continue;
			}
			$resolved   = Seeder::resolve_block_source( self::$gvm, $slug, $entry );
			$unresolved = array_merge( $unresolved, $resolved['unresolved'] );

			$this->assertNotEmpty(
				$resolved['powers'],
				"Container '{$entry['menu']}' for {$slug} produced no powers."
			);
		}

		$this->assertSame( [], $unresolved, implode( "\n  ", $unresolved ) );
	}

	/**
	 * `alphabetized` and `display` come from the GVM menu itself and must survive both
	 * `parse_gvm()`'s include resolution and `resolve_block_source()` - a regression
	 * guard for a bug that silently dropped both, discovered while building
	 * TraitListRenderer (workflow-0.3.md Step 4a), which depends on `alphabetize`.
	 *
	 * `<menu name="Abilities" ... abc="yes" ... display="0">` in the source GVM
	 * (GV-SOURCEMAP.md) is the ground truth: alphabetized, display 0 (simple).
	 */
	public function test_alphabetized_and_display_survive_resolution(): void {
		$map = Seeder::block_map();

		$abilities = Seeder::resolve_block_source( self::$gvm, 'met-abilities', $map['met-abilities'] );
		$this->assertTrue( $abilities['alphabetized'], 'Abilities is abc="yes" in the source GVM' );
		$this->assertSame( 0, $abilities['display'] );

		// Merits and Flaws render with a display type of "cost" (index 4) in the real
		// menu set - a distinct, meaningful value from the neutral default, and the
		// clearest proof this isn't defaulting to -1/false and coincidentally passing.
		$merits = Seeder::resolve_block_source( self::$gvm, 'met-merits', $map['met-merits'] );
		$this->assertSame( 4, $merits['display'] );
	}

	/**
	 * Container shape is derived from the data, not declared, so a chronicle's custom
	 * menu set resolves correctly too.
	 */
	public function test_container_shape_is_derived(): void {
		$map = Seeder::block_map();

		// Every Sphere links to one shared "Sphere Levels" menu.
		$spheres = Seeder::resolve_block_source( self::$gvm, 'mage-spheres', $map['mage-spheres'] );
		$this->assertSame( 'shared_levels', $spheres['shape'] );
		$this->assertCount( 10, $spheres['powers'] );

		// Each Discipline has its own menu of individually named powers. 101, not 48 -
		// resolve_container() now recurses into a submenu whose own target is itself
		// another container (Thaumaturgy's 38 Paths, and the equivalent nested-path shape
		// under Necromancy/Dark Thaumaturgy/Mortis/Valeren/Koldunic Sorcery/Assamite/
		// Gargoyle Powers) instead of silently reading an empty item list and stopping.
		$disc = Seeder::resolve_block_source( self::$gvm, 'vampire-disciplines', $map['vampire-disciplines'] );
		$this->assertSame( 'named', $disc['shape'] );
		$this->assertCount( 101, $disc['powers'] );
	}

	/**
	 * Real bug (found 2026-09-09, alongside the identity-options fix): vampire-rituals was
	 * seeded from the "Thaumaturgy" container - a category label with zero items of its own
	 * and 38 submenus, each a real named Path (Path of Blood, Lure of Flames, ...). Every
	 * one of those Paths landed in the Rituals block wearing a Path's name, while the real
	 * Rituals menu (individually-named formulas: Ward Versus Ghouls, Blood Mastery, ...,
	 * each tagged with a tier - basic/int./adv./superior - never leveled 1-5) was never
	 * seeded anywhere. Counts alone could pass by coincidence if content were shuffled
	 * wrong; this asserts real names land in the right bucket.
	 */
	public function test_thaumaturgy_paths_and_rituals_are_not_conflated(): void {
		$map = Seeder::block_map();

		$disciplines   = Seeder::resolve_block_source( self::$gvm, 'vampire-disciplines', $map['vampire-disciplines'] );
		$discipline_names = array_column( $disciplines['powers'], 'name' );
		$this->assertContains( 'Path of Blood', $discipline_names, 'a real Thaumaturgy Path belongs in Disciplines' );
		$this->assertContains( 'Lure of Flames', $discipline_names );
		$this->assertNotContains( 'Thaumaturgy', $discipline_names, 'the bare category label carries no powers of its own' );

		$rituals      = Seeder::resolve_block_source( self::$gvm, 'vampire-rituals', $map['vampire-rituals'] );
		$ritual_names = array_column( $rituals['items'], 'name' );
		$this->assertContains( 'Thaumaturgy: Ward Versus Ghouls (basic)', $ritual_names, 'a real ritual formula belongs in Rituals' );
		$this->assertContains( 'Thaumaturgy: Blood Mastery (basic)', $ritual_names );
		$this->assertNotContains( 'Path of Blood', $ritual_names, 'a Path is not a Ritual' );

		// Sabbat Ritae (Auctoritas/Ignoblis) must stay out of Rituals - they're a
		// different pool, already seeded as vampire-ritae.
		$this->assertNotContains( 'Vaulderie', $ritual_names, 'Ritae must not leak into Rituals' );
	}

	/**
	 * "<Type>: <name> (<tier>)" (user report, 2026-09-09) - e.g. "Thaumaturgy: Blood
	 * Mastery (basic)", matching real Grapevine/MET naming convention. Real names, not
	 * guessed: Blood Mastery is a real Rituals, Basic item (confirmed against
	 * Grapevine Menus XML.gvm).
	 */
	public function test_ritual_names_carry_their_type_and_tier(): void {
		$map     = Seeder::block_map();
		$rituals = Seeder::resolve_block_source( self::$gvm, 'vampire-rituals', $map['vampire-rituals'] );
		$names   = array_column( $rituals['items'], 'name' );

		$this->assertContains( 'Thaumaturgy: Blood Mastery (basic)', $names );
		// Two items physically live in "Rituals, Advanced" (Thaumaturgy) but carry a
		// discipline override in their own note ("adv. necro", "adv. assamite") - GVM's
		// own flag that they actually belong to a different discipline. The override
		// wins over the container-menu label, and the matched word is dropped from the
		// displayed tier so it isn't shown twice.
		$this->assertContains( 'Necromancy: Grasp the Ghostly (adv)', $names );
		$this->assertContains( 'Assamite: Healing Blood (adv)', $names );
		$this->assertNotContains( 'Thaumaturgy: Grasp the Ghostly (adv. necro)', $names );
	}

	/**
	 * GIFTS-GROUPING-FIX.md (2026-09-10): `werewolf-gifts`/`fera-gifts` items carry real
	 * `group`/`subgroup`/`tier` fields instead of a compound name string. Covers every real
	 * shape confirmed against the actual GVM data before this was built: a plain Werewolf
	 * tribe gift, a Fera item with a real subgroup (Ananasi's Hatar faction), a Fera item
	 * from a breed's own "General" menu (which self-matches the block's pattern and used to
	 * produce the malformed "Ananasi General (Gifts, Ananasi General): ..." name - 226 of
	 * 865 real items, not just the one originally-reported Nuwisha case), and Nuwisha's own
	 * top-container items (no distinct "General" submenu at all, the originally-reported
	 * malformed case). No item name should ever contain a literal "Gifts," again.
	 */
	public function test_gift_items_carry_group_subgroup_and_tier(): void {
		$map = Seeder::block_map();

		$werewolf = Seeder::resolve_block_source( self::$gvm, 'werewolf-gifts', $map['werewolf-gifts'] )['items'];
		$by_name  = [];
		foreach ( $werewolf as $item ) {
			$by_name[ $item['name'] ] = $item;
		}
		$this->assertSame( 'Fianna', $by_name['Salmon Leap']['group'] );
		$this->assertSame( 'basic', $by_name['Salmon Leap']['tier'] );
		$this->assertArrayNotHasKey( 'subgroup', $by_name['Salmon Leap'] );

		$fera    = Seeder::resolve_block_source( self::$gvm, 'fera-gifts', $map['fera-gifts'] )['items'];
		$by_name = [];
		foreach ( $fera as $item ) {
			$by_name[ $item['name'] ] = $item;
		}
		$this->assertSame( 'Ananasi', $by_name['Blood of Illusion']['group'] );
		$this->assertSame( 'Hatar', $by_name['Blood of Illusion']['subgroup'] );

		$this->assertSame( 'Ananasi', $by_name['Balance']['group'] );
		$this->assertArrayNotHasKey( 'subgroup', $by_name['Balance'] );

		$this->assertSame( 'Nuwisha', $by_name['Bad Joke']['group'] );
		$this->assertArrayNotHasKey( 'subgroup', $by_name['Bad Joke'] );

		foreach ( array_merge( $werewolf, $fera ) as $item ) {
			$this->assertStringNotContainsString( 'Gifts,', $item['name'] );
			$this->assertStringNotContainsString( ':', $item['name'] );
		}
	}

	/**
	 * Measured counts. A change here means the source menu file changed.
	 *
	 * @dataProvider expected_block_content
	 */
	public function test_blocks_resolve_to_expected_content( string $slug, int $expected, string $unit ): void {
		$map = Seeder::block_map();
		$this->assertArrayHasKey( $slug, $map );

		$resolved = Seeder::resolve_block_source( self::$gvm, $slug, $map[ $slug ] );

		if ( $unit === 'powers' ) {
			$this->assertCount( $expected, $resolved['powers'] );
		} else {
			$this->assertCount( $expected, $resolved['items'] );
		}
	}

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function expected_block_content(): array {
		return [
			// The six blocks defect D5 dropped entirely.
			'physical traits'     => [ 'met-physical-traits', 27, 'items' ],
			'physical negative'   => [ 'met-physical-traits-neg', 13, 'items' ],
			'social traits'       => [ 'met-social-traits', 27, 'items' ],
			'social negative'     => [ 'met-social-traits-neg', 18, 'items' ],
			'mental traits'       => [ 'met-mental-traits', 28, 'items' ],
			'mental negative'     => [ 'met-mental-traits-neg', 14, 'items' ],
			// Containers that previously resolved to nothing.
			// D-new (2026-09-09): vampire-disciplines/mortal-numina/mummy-hekau counts grew
			// because resolve_container() now recurses into a submenu whose own target is
			// itself another container (Thaumaturgy's 38 Paths, Necromancy's/Mortis's/
			// Valeren's/Koldunic Sorcery's own child paths, and the equivalent shape in
			// Numina/Hekau) instead of silently reading an empty item list and stopping.
			'disciplines'         => [ 'vampire-disciplines', 101, 'powers' ],
			'arcanoi'             => [ 'wraith-arcanoi', 26, 'powers' ],
			'arts'                => [ 'changeling-arts', 28, 'powers' ],
			'kuei-jin disciplines'=> [ 'kueijin-disciplines', 17, 'powers' ],
			'numina'              => [ 'mortal-numina', 356, 'powers' ],
			'realms'              => [ 'changeling-realms', 11, 'powers' ],
			'spheres'             => [ 'mage-spheres', 10, 'powers' ],
			'hekau'               => [ 'mummy-hekau', 12, 'powers' ],
			// vampire-rituals: real bug fix, 2026-09-09 - was seeded from the "Thaumaturgy"
			// container (Paths, wrongly landing here instead of in vampire-disciplines).
			// The real Rituals menu is 'merge'-sourced (trait_list shape, not tiered_power -
			// see gvm-block-map.php's own comment), so this checks 'items', not 'powers'.
			'rituals'             => [ 'vampire-rituals', 207, 'items' ],
			// Names that did not exist in any form.
			'ritae merged'        => [ 'vampire-ritae', 25, 'items' ],
			'vampire status'      => [ 'vampire-statuses', 45, 'items' ],
		];
	}

	/**
	 * Item cost and note survive parsing. The cost engine in 0.4 needs them, and cost is
	 * free text ('1', '1 or 3', '1-7'), never an integer.
	 */
	public function test_item_cost_and_note_are_preserved(): void {
		$merits = self::$gvm['Merits']['items'];

		$by_name = [];
		foreach ( $merits as $item ) {
			$by_name[ $item['name'] ] = $item;
		}

		$this->assertArrayHasKey( 'Acute Sense', $by_name );
		$this->assertSame( '1 or 3', $by_name['Acute Sense']['cost'], 'Cost must survive as free text.' );

		$this->assertArrayHasKey( 'Boon', $by_name );
		$this->assertSame( '1-7', $by_name['Boon']['cost'] );

		$with_cost = 0;
		foreach ( self::$gvm as $menu ) {
			foreach ( $menu['items'] as $item ) {
				if ( $item['cost'] !== '' ) {
					$with_cost++;
				}
			}
		}
		$this->assertGreaterThan( 4000, $with_cost, 'Most menu items carry a cost; almost none came through.' );
	}

	/**
	 * A negative list inverts cost arithmetic; Flaws grant points.
	 */
	public function test_negative_lists_are_flagged(): void {
		$map = Seeder::block_map();

		foreach ( [ 'met-flaws', 'met-physical-traits-neg', 'met-social-traits-neg', 'met-mental-traits-neg' ] as $slug ) {
			$this->assertTrue(
				! empty( $map[ $slug ]['negative'] ),
				"{$slug} must be marked negative — Grapevine multiplies its cost by -1."
			);
		}

		$this->assertArrayNotHasKey( 'negative', $map['met-merits'] );
	}

	/**
	 * D14: atomic is a per-creature-class constant from LinkedTraitList.Initialize() in
	 * the VB6 source, verified true for Merits, Flaws and Derangements across every
	 * creature class that defines them. It must survive from the block map into the
	 * built block definition for both trait_list and tiered_power (container) blocks.
	 */
	public function test_atomic_lists_are_flagged(): void {
		$map = Seeder::block_map();

		foreach ( [ 'met-merits', 'met-flaws', 'met-derangements', 'vampire-disciplines' ] as $slug ) {
			$this->assertTrue(
				! empty( $map[ $slug ]['atomic'] ),
				"{$slug} must be marked atomic per LinkedTraitList.Initialize() in the VB6 source."
			);
		}

		// vampire-combo-disciplines has no VB6 base-class Initialize() call at all - no
		// ground truth exists, so it stays unmarked rather than guessed.
		$this->assertArrayNotHasKey( 'atomic', $map['vampire-combo-disciplines'] ?? [] );

		$blocks   = new \ReflectionMethod( Seeder::class, 'build_blocks_from_gvm' );
		$built    = $blocks->invoke( null, self::$gvm );
		$by_slug  = array_column( $built, null, 'slug' );

		$this->assertTrue( $by_slug['met-merits']['definition']['atomic'] ?? false, 'atomic must reach the built trait_list definition.' );
		$this->assertTrue( $by_slug['vampire-disciplines']['definition']['atomic'] ?? false, 'atomic must reach the built tiered_power definition.' );
	}

	/**
	 * Every block a creature stack references must be produced by the seeder.
	 *
	 * This is the check that would have caught defect D5 on day one: the six core
	 * attribute blocks were referenced by all eleven stacks and built by none. Runs
	 * against the seeder's output directly, so it needs no database.
	 */
	public function test_every_stack_block_reference_is_built(): void {
		$blocks = new \ReflectionMethod( Seeder::class, 'build_blocks_from_gvm' );
		$built = array_column( $blocks->invoke( null, self::$gvm ), 'slug' );

		$stacks = new \ReflectionMethod( Seeder::class, 'get_stacks_to_seed' );

		$missing = [];
		foreach ( $stacks->invoke( null ) as $stack ) {
			$definition = is_string( $stack['stack_definition'] )
				? json_decode( $stack['stack_definition'], true )
				: $stack['stack_definition'];

			foreach ( ( $definition['sections'] ?? [] ) as $section ) {
				foreach ( [ 'block_slug', 'negative_block_slug' ] as $key ) {
					if ( empty( $section[ $key ] ) ) {
						continue;
					}
					if ( ! in_array( $section[ $key ], $built, true ) ) {
						$missing[] = $stack['slug'] . ' -> ' . $section[ $key ];
					}
				}
			}
		}

		$this->assertSame(
			[],
			array_values( array_unique( $missing ) ),
			"Creature stacks reference blocks the seeder never builds:\n  "
				. implode( "\n  ", array_unique( $missing ) )
		);
	}

	/**
	 * mage-rotes has no GVM source at all - GVM's menu set genuinely has no Rotes menu, so
	 * the block map correctly says so rather than pretend. This no longer means the built
	 * block ships empty (see test_mage_rotes_is_no_longer_empty below,
	 * BE_PROCESS/0.99.2-workflow.md "`mage-rotes` ships as an empty catalog") - the seeder
	 * special-cases this slug in build_mapped_blocks() to source real content from
	 * data/Rotes.gex instead, a source GVM's own menu set has no equivalent of.
	 */
	public function test_rotes_have_no_gvm_source(): void {
		$entry = Seeder::block_map()['mage-rotes'];

		$this->assertSame( 'none', $entry['source'] );
	}

	/**
	 * The defect this closes: every Mage character showed a Rotes section with nothing in
	 * it, despite the data (Rotes.gex, 201 real rotes) and the reader (GEX_Xml_Parser, tests
	 * already passing against this exact file) both already existing.
	 *
	 * `note`/`source` assertions updated for the backfill added the same release
	 * (`BE_PROCESS/mage-rotes-grimoire-design.md` §5.4): `Rotes.gex` was already parsed for
	 * its 289 real sphere prerequisites and 201 real source citations, and the seeder was
	 * discarding both. 201 is still the right count here - the ~880 net-new rotes extracted
	 * from the Grimoire PDF are a separate, larger, not-yet-built item (MR-4 onward).
	 */
	public function test_mage_rotes_is_no_longer_empty(): void {
		$ref    = new \ReflectionMethod( Seeder::class, 'build_mapped_blocks' );
		$ref->setAccessible( true );
		$blocks = $ref->invoke( null, self::$gvm );

		$mage_rotes = current( array_filter( $blocks, static fn( $b ) => $b['slug'] === 'mage-rotes' ) );
		$this->assertNotFalse( $mage_rotes );
		$this->assertCount( 201, $mage_rotes['definition']['items'] );
		$this->assertArrayNotHasKey( 'deferred_to', $mage_rotes['definition'], 'no longer deferred - the flag must not survive into the built block' );

		$sample = current( array_filter( $mage_rotes['definition']['items'], static fn( $i ) => $i['name'] === 'Access This' ) );
		$this->assertSame( 'Level 2, One Scene or Hour — Correspondence: Initiate, Forces: Initiate', $sample['note'] );
		$this->assertSame( 'Laws of Ascension Companion p. 137', $sample['source'] );
		$this->assertArrayNotHasKey( 'cost', $sample, 'rotes are never separately priced - the sphere levels they require are what cost XP' );

		// Beginner's Luck legitimately works at either of two ranks - both must survive, not deduped.
		$dual = current( array_filter( $mage_rotes['definition']['items'], static fn( $i ) => $i['name'] === "Beginner's Luck" ) );
		$this->assertNotFalse( $dual );
		$this->assertSame( 'Level 2, See Description — Entropy: Apprentice, Entropy: Initiate', $dual['note'] );
		$this->assertSame( 'Laws of Ascension, p. 147', $dual['source'] );

		// No item's `note` should ever be silently missing its sphere prerequisites -
		// Rotes.gex confirms 0 of 289 sphere traits are empty, so every one of the 201
		// items must show a spaced em-dash section in its note.
		foreach ( $mage_rotes['definition']['items'] as $item ) {
			$this->assertStringContainsString( ' — ', $item['note'], "{$item['name']} is missing its sphere-prerequisite suffix" );
		}
	}

	/**
	 * Regression guard for D29: `met-archetypes`' Nature/Demeanor `identity_field`
	 * options must be plain name strings, never the raw `{name, cost, note}` GVM item
	 * objects - the latter crashes `IdentityFieldEditor.tsx` outright (React error #31,
	 * "Objects are not valid as a React child"), taking the whole Character Editor with
	 * it, not just the one field.
	 */
	public function test_archetype_options_are_plain_strings(): void {
		$blocks = array_filter(
			Seeder::get_blocks_to_seed(),
			static fn( $block ) => $block['slug'] === 'met-archetypes'
		);

		$this->assertNotEmpty( $blocks, 'met-archetypes must be seeded from real GVM data.' );

		$block = array_values( $blocks )[0];

		foreach ( $block['definition']['fields'] as $field ) {
			foreach ( $field['options'] as $option ) {
				$this->assertIsString(
					$option,
					"{$field['name']}'s options must be plain strings, not raw GVM item objects."
				);
			}
		}
	}
}
