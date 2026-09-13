<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * Health Levels: an ordinary, unpriced trait_list per stack, seeded from Grapevine's own
 * HealthList.Initialize/.Append sequence (every *Class.cls) - Healthy(2), Bruised(3),
 * Wounded(2), Incapacitated(1) are identical everywhere; only the terminal box(es) differ.
 * Extended health only - old/standard Health is not offered. Wraith has no HealthList at
 * all (uses its own Corpus resource pool) and gets no health block. See
 * BE_PROCESS/health-level-tracker-design.md.
 */
class HealthLevelSeederTest extends TestCase {

	/** @var array<string,array> Built blocks, keyed by slug, loaded once. */
	private static $blocks;

	/** @var array<string,array> Every stack's insert array, keyed by slug, loaded once. */
	private static $stacks;

	public static function setUpBeforeClass(): void {
		self::$blocks = [];
		foreach ( Seeder::get_blocks_to_seed() as $block ) {
			self::$blocks[ $block['slug'] ] = $block;
		}

		self::$stacks = [];
		$ref = new \ReflectionMethod( Seeder::class, 'get_stacks_to_seed' );
		$ref->setAccessible( true );
		foreach ( $ref->invoke( null ) as $stack ) {
			self::$stacks[ $stack['slug'] ] = $stack;
		}
	}

	private static function names( string $slug ): array {
		return array_column( self::$blocks[ $slug ]['definition']['items'], 'name' );
	}

	private static function held_names( array $composition ): array {
		return array_map( static fn( $c ) => $c['name'], $composition );
	}

	// -------------------------------------------------------------------------
	// Every applicable stack has its own (or a shared) health block.
	// -------------------------------------------------------------------------

	public function test_every_applicable_stack_has_a_health_block(): void {
		foreach ( [ 'vampire', 'werewolf', 'mage', 'changeling', 'demon', 'mummy', 'kueijin', 'mortal' ] as $stack ) {
			$this->assertArrayHasKey( "{$stack}-health", self::$blocks, "{$stack} must have its own health block" );
		}
	}

	public function test_wraith_has_no_health_block(): void {
		$this->assertArrayNotHasKey( 'wraith-health', self::$blocks );
	}

	public function test_fera_and_bete_share_werewolfs_health_block_not_their_own(): void {
		$this->assertArrayNotHasKey( 'fera-health', self::$blocks );
		$this->assertArrayNotHasKey( 'bete-health', self::$blocks );

		foreach ( [ 'fera', 'bete' ] as $slug ) {
			$slugs = array_column( self::$stacks[ $slug ]['stack_definition']['sections'], 'block_slug' );
			$this->assertContains( 'werewolf-health', $slugs, "{$slug} must reference werewolf-health" );
		}
	}

	// -------------------------------------------------------------------------
	// Shape: unpriced trait_list, no free-text add, standard names in order.
	// -------------------------------------------------------------------------

	public function test_health_block_is_an_unpriced_non_custom_trait_list(): void {
		foreach ( [ 'vampire', 'werewolf', 'mage', 'changeling', 'demon', 'mummy', 'kueijin', 'mortal' ] as $stack ) {
			$block = self::$blocks[ "{$stack}-health" ];
			$this->assertSame( 'trait_list', $block['section_type'], "{$stack}-health" );
			$this->assertFalse( $block['definition']['allow_custom'], "{$stack}-health must not allow free-text add" );
			foreach ( $block['definition']['items'] as $item ) {
				$this->assertArrayNotHasKey( 'cost', $item, "{$stack}-health's '{$item['name']}' must have no cost key" );
			}
		}
	}

	public function test_standard_nine_box_composition(): void {
		foreach ( [ 'werewolf', 'mage', 'changeling', 'demon', 'mortal' ] as $stack ) {
			$this->assertSame(
				[ 'Healthy', 'Bruised', 'Wounded', 'Incapacitated', 'Mortally Wounded' ],
				self::names( "{$stack}-health" ),
				"{$stack}-health"
			);
		}
	}

	public function test_torpor_terminal_composition(): void {
		foreach ( [ 'vampire', 'kueijin' ] as $stack ) {
			$this->assertSame(
				[ 'Healthy', 'Bruised', 'Wounded', 'Incapacitated', 'Torpor' ],
				self::names( "{$stack}-health" ),
				"{$stack}-health"
			);
		}
	}

	public function test_mummys_eleven_box_composition(): void {
		$this->assertSame(
			[ 'Healthy', 'Bruised', 'Wounded', 'Incapacitated', 'Mortally Wounded', 'Dead' ],
			self::names( 'mummy-health' )
		);
	}

	// -------------------------------------------------------------------------
	// default_held: the real per-box counts, applied automatically at creation
	// (Characters_Controller::create_item()), never left for manual +Add.
	// -------------------------------------------------------------------------

	public function test_default_held_counts_match_the_real_composition(): void {
		$expected = [
			'vampire'    => [ 2, 3, 2, 1, 1 ],
			'werewolf'   => [ 2, 3, 2, 1, 1 ],
			'mage'       => [ 2, 3, 2, 1, 1 ],
			'changeling' => [ 2, 3, 2, 1, 1 ],
			'demon'      => [ 2, 3, 2, 1, 1 ],
			'kueijin'    => [ 2, 3, 2, 1, 1 ],
			'mortal'     => [ 2, 3, 2, 1, 1 ],
			'mummy'      => [ 2, 3, 2, 1, 1, 2 ],
		];

		foreach ( $expected as $stack => $counts ) {
			$default_held = self::$blocks[ "{$stack}-health" ]['definition']['default_held'];
			$this->assertSame( $counts, array_column( $default_held, 'count' ), "{$stack}-health" );
			$this->assertSame( self::names( "{$stack}-health" ), self::held_names( $default_held ), "{$stack}-health" );
			$this->assertSame( array_sum( $counts ), array_sum( array_column( $default_held, 'count' ) ) );
		}
	}

	public function test_totals_match_the_real_grapevine_math(): void {
		// Confirmed against pp-samples/mage-pc-print.html: 2+3+2+1+1 = 9.
		foreach ( [ 'vampire', 'werewolf', 'mage', 'changeling', 'demon', 'kueijin', 'mortal' ] as $stack ) {
			$total = array_sum( array_column( self::$blocks[ "{$stack}-health" ]['definition']['default_held'], 'count' ) );
			$this->assertSame( 9, $total, "{$stack}-health" );
		}
		$mummy_total = array_sum( array_column( self::$blocks['mummy-health']['definition']['default_held'], 'count' ) );
		$this->assertSame( 11, $mummy_total );
	}

	// -------------------------------------------------------------------------
	// Wired into each stack's own sections, after Resources/Virtues.
	// -------------------------------------------------------------------------

	public function test_health_section_is_wired_into_the_stack_after_resources(): void {
		foreach ( [ 'vampire', 'werewolf', 'mage', 'changeling', 'demon', 'mummy', 'kueijin', 'mortal' ] as $stack ) {
			$sections = self::$stacks[ $stack ]['stack_definition']['sections'];
			$slugs    = array_column( $sections, 'block_slug' );
			$this->assertContains( "{$stack}-health", $slugs, "{$stack} sections" );

			$by_slug        = array_combine( $slugs, array_column( $sections, 'display_order' ) );
			$resources_order = $by_slug["{$stack}-resources"] ?? null;
			if ( $resources_order !== null ) {
				$this->assertGreaterThan( $resources_order, $by_slug[ "{$stack}-health" ], "{$stack}-health must display after {$stack}-resources" );
			}
		}
	}

	public function test_no_health_section_is_required(): void {
		foreach ( [ 'vampire', 'werewolf', 'mage', 'changeling', 'demon', 'mummy', 'kueijin', 'mortal', 'fera', 'bete' ] as $stack ) {
			foreach ( self::$stacks[ $stack ]['stack_definition']['sections'] as $section ) {
				if ( $section['block_slug'] === 'werewolf-health' || $section['block_slug'] === "{$stack}-health" ) {
					$this->assertFalse( $section['required'], "{$stack}'s health section must not be required" );
				}
			}
		}
	}

	public function test_vampire_no_longer_carries_the_stale_numeric_health_levels_preference(): void {
		$this->assertArrayNotHasKey( 'health_levels', self::$stacks['vampire']['stack_definition']['display_preferences'] ?? [] );
	}
}
