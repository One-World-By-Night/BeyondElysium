<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/LegacyInstall.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Tests\Support\LegacyInstall;
use WP_UnitTestCase;

/**
 * `Catalog_Cutover::plan()`: the read-only report of what a move to per-creature lists would do.
 */
class CatalogCutoverPlanThreadTest extends WP_UnitTestCase {

	private string $a = 'cutover-plan-a';
	private string $b = 'cutover-plan-b';

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		LegacyInstall::put_in_place();
		Game::create( [ 'slug' => $this->a, 'name' => 'Cutover Plan A' ] );
		Game::create( [ 'slug' => $this->b, 'name' => 'Cutover Plan B' ] );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		parent::tearDown();
	}

	/** @param array<string,mixed> $sheet */
	private function character( string $game, string $stack, string $name, array $sheet ): int {
		return (int) Character::create( [
			'name' => $name, 'stack_slug' => $stack, 'owner_type' => 'chronicle',
			'owner_slug' => $game, 'status' => 'active', 'sheet_data' => $sheet,
		] );
	}

	/** @return array<string,mixed> */
	private function vampire_sheet(): array {
		return [
			'vampire-identity' => [ 'Clan' => 'Tremere' ],
			'met-abilities'    => [
				[ 'name' => 'Brawl', 'count' => 3 ],
				[ 'name' => 'Lore: Kindred', 'count' => 2, 'custom' => true ],
				[ 'name' => 'Basket Weaving', 'count' => 1, 'custom' => true ],
			],
			'met-merits'       => [ [ 'name' => 'Iron Will' ] ],
		];
	}

	public function test_a_plan_counts_the_characters_and_the_ones_a_move_would_change(): void {
		$this->character( $this->a, 'vampire', 'Plan Vampire', $this->vampire_sheet() );
		$this->character( $this->a, 'vampire', 'Already Moved', [ 'vampire-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ] ] ] );

		$plan = Catalog_Cutover::plan( $this->a );

		$this->assertTrue( $plan['available'] );
		$this->assertSame( 2, $plan['characters'] );
		$this->assertSame( 1, $plan['characters_changed'] );
		$this->assertSame( [], $plan['retention_gaps'] );
	}

	public function test_a_plan_changes_nothing_anywhere(): void {
		$id      = $this->character( $this->a, 'vampire', 'Plan Vampire', $this->vampire_sheet() );
		$before  = [
			'sheet'     => wp_json_encode( Character::find( $id )->sheet_data ),
			'templates' => wp_json_encode( array_map( static fn( $t ) => [ $t->id, $t->layout ], Template::all() ) ),
			'option'    => get_option( Catalog_Cutover::OPTION ),
		];

		Catalog_Cutover::plan();
		Catalog_Cutover::plan( $this->a );

		$this->assertSame( $before['sheet'], wp_json_encode( Character::find( $id )->sheet_data ) );
		$this->assertSame( $before['templates'], wp_json_encode( array_map( static fn( $t ) => [ $t->id, $t->layout ], Template::all() ) ) );
		$this->assertSame( $before['option'], get_option( Catalog_Cutover::OPTION ) );
	}

	public function test_each_character_is_judged_against_its_own_chronicles_blocks(): void {
		// Chronicle B keeps its own copy of vampire-abilities, without Brawl.
		$fork                  = Schema_Block::find_or_create_fork_for_game( 'vampire-abilities', $this->b );
		$definition            = json_decode( wp_json_encode( $fork->definition ), true );
		$definition['items']   = array_values( array_filter( $definition['items'], static fn( $item ) => $item['name'] !== 'Brawl' ) );
		Schema_Block::update( 'vampire-abilities', [ 'definition' => $definition ], $this->b );

		$sheet = [ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ] ] ];
		$this->character( $this->a, 'vampire', 'Global Vampire', $sheet );
		$forked = $this->character( $this->b, 'vampire', 'Forked Vampire', $sheet );

		$plan = Catalog_Cutover::plan();

		$this->assertSame( [ $forked ], array_column( $plan['retention_gaps'], 'character_id' ), 'only the chronicle whose copy lacks Brawl would lose it' );
	}

	public function test_a_catalog_row_the_replacement_lacks_is_a_retention_gap_with_its_character(): void {
		$id = $this->character( $this->a, 'vampire', 'Gap Vampire', [ 'met-abilities' => [ [ 'name' => 'Definitely Not An Ability' ] ] ] );
		$this->assertNotNull( Schema_Block::find_by_slug( 'met-abilities' ) );

		// The shared block lists the entry.
		$block      = Schema_Block::find_by_slug( 'met-abilities' );
		$definition = json_decode( wp_json_encode( $block->definition ), true );
		$definition['items'][] = [ 'name' => 'Definitely Not An Ability', 'cost' => '1' ];
		Schema_Block::update( 'met-abilities', [ 'definition' => $definition ] );

		$gap = Catalog_Cutover::plan( $this->a )['retention_gaps'][0];

		$this->assertSame( $id, $gap['character_id'] );
		$this->assertSame( 'Gap Vampire', $gap['character'] );
		$this->assertSame( 'met-abilities', $gap['block_from'] );
		$this->assertSame( 'vampire-abilities', $gap['block_to'] );
		$this->assertSame( 'Definitely Not An Ability', $gap['name'] );
	}

	public function test_a_catalog_row_the_declared_catalog_spells_differently_is_not_a_gap(): void {
		// The shared lists spelled "Fortune-telling", "Light Sensitive" and "Meditiation".
		$this->character( $this->a, 'vampire', 'Spelling Vampire', [
			'met-abilities' => [ [ 'name' => 'Fortune-telling', 'count' => 2 ], [ 'name' => 'Meditiation', 'count' => 1 ] ],
			'met-flaws'     => [ [ 'name' => 'Light Sensitive' ] ],
		] );

		$plan = Catalog_Cutover::plan( $this->a );

		$this->assertSame( [], $plan['retention_gaps'] );
		$this->assertSame( 1, $plan['characters_changed'] );
	}

	public function test_a_plan_says_so_when_no_declared_catalog_ships(): void {
		$this->character( $this->a, 'vampire', 'Plan Vampire', $this->vampire_sheet() );
		$without = new class() extends Catalog_Cutover {
			protected static function catalog_available(): bool {
				return false;
			}
		};

		$plan = $without::plan( $this->a );

		$this->assertFalse( $plan['available'] );
		$this->assertSame( 0, $plan['characters'], 'nothing is read without a catalog to plan against' );
	}
}
