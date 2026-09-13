<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Point_Audit;
use WP_UnitTestCase;

/**
 * `Point_Audit` against the real seeder output and the real demo characters
 * (point-calculator-design.md §7 PC-6) - never hand-built fixtures for the
 * catalog itself.
 *
 * @see BE_PROCESS/point-calculator-design.md
 */
class PointAuditTest extends WP_UnitTestCase {

	private static string $game_slug;

	public static function wpSetUpBeforeClass( $factory ): void {
		// Demo data seeds into the real 'be-demo' game as part of ordinary plugin
		// activation - confirm it, rather than assuming it, since this test depends on it.
		self::$game_slug = 'be-demo';
	}

	private function find_demo_character( string $name ) {
		$character = Character::find_by_name_in_game( $name, self::$game_slug );
		$this->assertNotNull( $character, "Demo character \"{$name}\" must exist in {$this->game_slug_for_message()}." );
		return $character;
	}

	private function game_slug_for_message(): string {
		return self::$game_slug;
	}

	public function test_isolde_marchettis_full_report_never_shows_zero_for_an_unpriced_line(): void {
		$isolde = $this->find_demo_character( 'Isolde Marchetti' );
		$report = Point_Audit::for_character( (int) $isolde->id );

		$this->assertNotNull( $report );
		$this->assertFalse( $report['complete'] );
		$this->assertGreaterThan( 0, $report['coverage']['priced_lines'] );
		$this->assertGreaterThan( 0, $report['coverage']['unpriced_lines'] );

		foreach ( $report['lines'] as $line ) {
			if ( $line['unpriced_reason'] !== null ) {
				$this->assertNull( $line['xp'], "Line \"{$line['label']}\" is marked unpriced but carries a non-null xp - a 0-as-free false claim." );
			}
		}
	}

	public function test_isoldes_generation_identity_field_is_unpriced_never_free(): void {
		$isolde = $this->find_demo_character( 'Isolde Marchetti' );
		$report = Point_Audit::for_character( (int) $isolde->id );

		$generation_line = null;
		foreach ( $report['lines'] as $line ) {
			if ( str_starts_with( $line['label'], 'Generation:' ) ) {
				$generation_line = $line;
				break;
			}
		}

		$this->assertNotNull( $generation_line, 'vampire-identity must declare a Generation field' );
		$this->assertNull( $generation_line['xp'] );
		$this->assertSame( 'identity_field_no_catalog_cost', $generation_line['unpriced_reason'] );
	}

	public function test_isoldes_variance_is_a_real_negative_number_against_her_xp_of_record(): void {
		$isolde = $this->find_demo_character( 'Isolde Marchetti' );
		$report = Point_Audit::for_character( (int) $isolde->id );

		// Expected, not a bug (point-calculator-design.md §7 PC-6): the priced total is
		// always well below her real 80 XP of record, since most of her sheet is
		// currently unpriceable (attribute traits, identity fields, ...).
		$this->assertLessThan( 0, $report['variance'] );
		$this->assertSame( 80, $report['xp_spent_of_record'] );
	}

	public function test_every_seeded_demo_character_reports_incomplete_coverage(): void {
		$demo_characters = Character::all_for_game( self::$game_slug, [ 'per_page' => 100 ] );
		$this->assertGreaterThanOrEqual( 11, count( $demo_characters ), 'one per creature stack at minimum' );

		foreach ( $demo_characters as $character ) {
			$report = Point_Audit::for_character( (int) $character->id );
			$this->assertNotNull( $report, "Character \"{$character->name}\" failed to resolve." );
			$this->assertFalse( $report['complete'], "\"{$character->name}\" must never report complete." );
			$this->assertGreaterThan( 0, $report['coverage']['unpriced_lines'], "\"{$character->name}\" must have at least one real unpriced line." );
		}
	}

	public function test_a_held_blood_magic_path_is_priced_proving_the_union_walk(): void {
		$block = Schema_Block::find_by_slug( 'vampire-blood-magic' );
		$this->assertNotNull( $block, 'vampire-blood-magic must exist as a real seeded catalog block' );
		$definition = $block->definition;
		$this->assertNotEmpty( $definition->powers ?? [], 'the real catalog must have at least one blood magic path' );
		$path = $definition->powers[0];
		$level = $path->levels[0] ?? null;
		$this->assertNotNull( $level, "path \"{$path->name}\" must have at least one level" );

		$character_id = Character::create( [
			'name'       => 'Point Audit Blood Magic Test',
			'owner_slug' => self::$game_slug,
			'stack_slug' => 'vampire',
			'sheet_data' => [
				'vampire-blood-magic' => [ [ 'name' => $path->name, 'level' => (int) $level->level ] ],
			],
			'created_by' => 1,
		] );

		$report = Point_Audit::for_character( $character_id );

		$blood_magic_line = null;
		foreach ( $report['lines'] as $line ) {
			if ( $line['block_slug'] === 'vampire-blood-magic' ) {
				$blood_magic_line = $line;
				break;
			}
		}

		$this->assertNotNull( $blood_magic_line, 'a resolve()-only walk would never surface this line at all (§3.2)' );
		$this->assertFalse( $blood_magic_line['undeclared_by_stack'], 'PC-4 declares vampire-blood-magic in the stack itself now' );

		Character::delete( $character_id );
	}

	public function test_a_non_cumulative_discipline_at_level_five_prices_flat_not_summed(): void {
		$block      = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$definition = $block->definition;
		$this->assertEmpty( $definition->sequential ?? false );

		$power = null;
		foreach ( $definition->powers as $candidate ) {
			$has_five = false;
			foreach ( $candidate->levels as $level ) {
				if ( (int) ( $level->level ?? 0 ) === 5 && isset( $level->cost ) ) {
					$has_five = true;
				}
			}
			if ( $has_five ) {
				$power = $candidate;
				break;
			}
		}
		$this->assertNotNull( $power, 'at least one real seeded discipline must have a priced level 5' );

		$level_5_cost = null;
		$sum_1_through_5 = 0;
		foreach ( $power->levels as $level ) {
			$n = (int) ( $level->level ?? 0 );
			if ( $n >= 1 && $n <= 5 && isset( $level->cost ) ) {
				$sum_1_through_5 += (int) $level->cost;
				if ( $n === 5 ) {
					$level_5_cost = (int) $level->cost;
				}
			}
		}

		$character_id = Character::create( [
			'name'       => 'Point Audit Non Cumulative Test',
			'owner_slug' => self::$game_slug,
			'stack_slug' => 'vampire',
			'sheet_data' => [
				'vampire-disciplines' => [ [ 'name' => $power->name, 'level' => 5 ] ],
			],
			'created_by' => 1,
		] );

		$report = Point_Audit::for_character( $character_id );
		$line   = null;
		foreach ( $report['lines'] as $candidate_line ) {
			if ( $candidate_line['block_slug'] === 'vampire-disciplines' && str_starts_with( $candidate_line['label'], $power->name ) ) {
				$line = $candidate_line;
				break;
			}
		}

		$this->assertNotNull( $line );
		$this->assertSame( $level_5_cost, $line['xp'] );
		$this->assertLessThan( $sum_1_through_5, $line['xp'], 'a cumulative sum would overcharge whenever the ladder is not strictly increasing' );

		Character::delete( $character_id );
	}

	public function test_an_atomic_block_holding_the_same_name_twice_yields_two_lines(): void {
		$character_id = Character::create( [
			'name'       => 'Point Audit Atomic Test',
			'owner_slug' => self::$game_slug,
			'stack_slug' => 'vampire',
			'sheet_data' => [
				'met-merits' => [ [ 'name' => 'Iron Will', 'count' => 3 ], [ 'name' => 'Iron Will', 'count' => 2 ] ],
			],
			'created_by' => 1,
		] );

		$report = Point_Audit::for_character( $character_id );
		$merit_lines = array_values( array_filter( $report['lines'], static fn( $l ) => $l['block_slug'] === 'met-merits' ) );

		$this->assertCount( 2, $merit_lines, 'find_held_trait()-style first-match logic would collapse this to one line' );

		Character::delete( $character_id );
	}
}
