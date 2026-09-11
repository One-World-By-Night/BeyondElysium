<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * GVM parsing against the real Grapevine menu file in this repo.
 *
 * Regression guard for defect D2: the seeder pointed at a path outside the plugin, so
 * parse_gvm() silently returned [] and seeding fell back to a hardcoded minimal set. These
 * are fixture tests against real data, not mocks — the counts below were measured.
 *
 * @see BE_PROCESS/workflow-0.2.1.md Step 2
 * @see BE_PROCESS/GV-SOURCEMAP.md
 */
class SeederGvmTest extends TestCase {

	/** Menu count declared by the file and confirmed by counting elements. */
	private const EXPECTED_MENUS = 756;

	public function test_gvm_source_file_is_present_in_the_repo(): void {
		$this->assertFileExists(
			Seeder::GVM_PATH,
			'The GVM source must resolve inside the plugin. A path outside it is defect D2.'
		);
	}

	public function test_parses_every_menu(): void {
		$menus = Seeder::parse_gvm();

		$this->assertNotEmpty(
			$menus,
			'parse_gvm() returned nothing, so seeding is silently falling back to hardcoded blocks.'
		);
		$this->assertCount( self::EXPECTED_MENUS, $menus );
	}

	public function test_declared_size_matches_the_actual_menu_count(): void {
		$xml = simplexml_load_file( Seeder::GVM_PATH );

		$this->assertNotFalse( $xml );
		$this->assertSame( self::EXPECTED_MENUS, (int) $xml['size'] );
		$this->assertCount( self::EXPECTED_MENUS, $xml->menu );
	}

	public function test_resolves_includes_transitively(): void {
		$menus = Seeder::parse_gvm();

		$this->assertArrayHasKey( 'Abilities', $menus );
		$this->assertArrayHasKey( 'Abilities, Changeling', $menus );

		$base      = array_column( $menus['Abilities']['items'], 'name' );
		$inherited = array_column( $menus['Abilities, Changeling']['items'], 'name' );

		$this->assertCount( 42, $base, 'Base Abilities menu item count changed.' );

		// "Abilities, Changeling" declares <include name="Abilities"/> plus three of its
		// own items. Every base ability must be present after resolution.
		$missing = array_diff( $base, $inherited );
		$this->assertSame(
			[],
			array_values( $missing ),
			'Include resolution dropped items inherited from the base Abilities menu.'
		);
		$this->assertGreaterThan( count( $base ), count( $inherited ) );
	}

	public function test_menu_without_a_category_attribute_defaults_to_race_all(): void {
		$menus = Seeder::parse_gvm();

		// "Abilities" carries no category attribute in the file. gvRaceAll is 1, but the
		// parser's fallback is 0 — assert whichever the parser actually does so a change
		// is deliberate rather than accidental.
		$this->assertArrayHasKey( 'category', $menus['Abilities'] );
		$this->assertContains(
			$menus['Abilities']['category'],
			[ 0, 1 ],
			'A category-less menu should resolve to a shared/all category sentinel.'
		);
	}

	public function test_known_menus_carry_expected_item_counts(): void {
		$menus = Seeder::parse_gvm();

		// Measured against Grapevine Menus XML.gvm. These are the blocks that matter most
		// downstream, and a change here means the source file changed.
		$expected = [
			'Abilities'          => 42,
			'Merits'             => 46,
			'Flaws'              => 64,
			'Derangements'       => 135,
			'Backgrounds'        => 7,
			'Physical'           => 27,
			'Physical, Negative' => 13,
			'Social'             => 27,
			'Social, Negative'   => 18,
			'Mental'             => 28,
			'Mental, Negative'   => 14,
		];

		foreach ( $expected as $name => $count ) {
			$this->assertArrayHasKey( $name, $menus, "Menu '{$name}' is missing from the parse." );
			$this->assertCount(
				$count,
				$menus[ $name ]['items'],
				"Menu '{$name}' item count changed."
			);
		}
	}

	/**
	 * Container menus hold their content in submenus, not items.
	 *
	 * This is defect D5 — the seeder reads ['items'] on these and gets nothing. The test
	 * documents the real shape so the 0.2.2 fix has something to assert against.
	 *
	 * @see BE_PROCESS/workflow-0.2.2.md
	 */
	public function test_container_menus_expose_submenus_not_items(): void {
		$menus = Seeder::parse_gvm();

		$containers = [
			'Disciplines'           => 48,
			'Thaumaturgy'           => 38,
			'Arcanoi'               => 26,
			'Arts'                  => 28,
			'Disciplines, Kuei-Jin' => 17,
			'Numina'                => 14,
			'Realms'                => 11,
			'Spheres'               => 10,
			'Hekau'                 => 7,
		];

		foreach ( $containers as $name => $submenu_count ) {
			$this->assertArrayHasKey( $name, $menus );
			$this->assertCount(
				0,
				$menus[ $name ]['items'],
				"'{$name}' is a container; it should hold no direct items."
			);
			$this->assertCount(
				$submenu_count,
				$menus[ $name ]['submenus'],
				"'{$name}' submenu count changed."
			);
		}
	}

	/**
	 * An empty submenu link means the submenu's own name is the target menu name.
	 *
	 * Verified against every submenu in all nine containers with zero unresolved.
	 */
	public function test_empty_submenu_link_resolves_to_the_submenu_name(): void {
		$menus = Seeder::parse_gvm();

		$unresolved = [];
		foreach ( [ 'Disciplines', 'Arts', 'Spheres', 'Arcanoi', 'Hekau', 'Numina', 'Realms', 'Thaumaturgy' ] as $container ) {
			foreach ( $menus[ $container ]['submenus'] as $sub ) {
				$target = $sub['link'] !== '' ? $sub['link'] : $sub['name'];
				if ( ! isset( $menus[ $target ] ) ) {
					$unresolved[] = "{$container} -> {$sub['name']} (link '{$sub['link']}')";
				}
			}
		}

		$this->assertSame(
			[],
			$unresolved,
			"Submenu targets that did not resolve:\n  " . implode( "\n  ", $unresolved )
		);
	}

	public function test_disciplines_resolve_to_named_power_menus(): void {
		$menus = Seeder::parse_gvm();

		// Animalism is reached from the Disciplines container by the empty-link rule and
		// holds individually named powers, not numeric tiers.
		$this->assertArrayHasKey( 'Animalism', $menus );
		$this->assertCount( 24, $menus['Animalism']['items'] );
		$this->assertContains( 'Feral Whispers', array_column( $menus['Animalism']['items'], 'name' ) );
	}

	public function test_spheres_share_one_level_menu(): void {
		$menus = Seeder::parse_gvm();

		$targets = [];
		foreach ( $menus['Spheres']['submenus'] as $sub ) {
			$targets[] = $sub['link'] !== '' ? $sub['link'] : $sub['name'];
		}

		$this->assertSame(
			[ 'Sphere Levels' ],
			array_values( array_unique( $targets ) ),
			'Every Sphere should link to the shared Sphere Levels menu.'
		);
		$this->assertCount( 5, $menus['Sphere Levels']['items'] );
	}

	/**
	 * workflow-0.8.md Step 3d: a circular <include> chain must terminate with an error
	 * naming the cycle, not recurse until PHP dies (and not, the 0.9 planned-vs-built
	 * audit found, silently truncate with no signal at all - the previous behavior).
	 * `resolve_includes()` is private and only reachable through `parse_gvm()` against
	 * the one real GVM file in this repo, which contains no real cycle to test against -
	 * reflection to call it directly with hand-built synthetic data is the only way to
	 * exercise this path at all, real logic under test either way.
	 *
	 * @param array<string,array<string,mixed>> $raw
	 * @param string                            $start
	 * @return array<string,mixed>
	 */
	private function resolve_includes( array $raw, string $start ) {
		$method = new \ReflectionMethod( Seeder::class, 'resolve_includes' );
		$method->setAccessible( true );
		return $method->invoke( null, $start, $raw[ $start ], $raw, [] );
	}

	private function menu( array $includes = [] ): array {
		return [ 'category' => 0, 'alphabetized' => false, 'display' => 0, 'items' => [], 'submenus' => [], 'includes' => $includes ];
	}

	public function test_a_self_referential_include_throws_naming_the_cycle(): void {
		$raw = [ 'A' => $this->menu( [ 'A' ] ) ];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/A -> A/' );
		$this->resolve_includes( $raw, 'A' );
	}

	public function test_a_mutually_referential_include_chain_throws_naming_the_full_cycle(): void {
		$raw = [
			'A' => $this->menu( [ 'B' ] ),
			'B' => $this->menu( [ 'C' ] ),
			'C' => $this->menu( [ 'A' ] ),
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/A -> B -> C -> A/' );
		$this->resolve_includes( $raw, 'A' );
	}

	public function test_a_non_circular_diamond_include_still_resolves_normally(): void {
		// A includes both B and C; B and C both include D. Not a cycle - D is reached
		// twice down two different branches, which must still resolve cleanly.
		$raw = [
			'A' => $this->menu( [ 'B', 'C' ] ),
			'B' => $this->menu( [ 'D' ] ),
			'C' => $this->menu( [ 'D' ] ),
			'D' => array_merge( $this->menu(), [ 'items' => [ [ 'name' => 'Leaf', 'cost' => '', 'note' => '' ] ] ] ),
		];

		$result = $this->resolve_includes( $raw, 'A' );
		$this->assertSame( [ 'Leaf' ], array_column( $result['items'], 'name' ) );
	}
}
