<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * R4: `Catalog_Cutover::plan()` - the read-only report of what `apply()` would do. Every case runs
 * against the real seeded declared catalog (`vampire-abilities`, `vampire-merits`, ...) through
 * real chronicles, because the point of the plan is that each character is judged against the
 * blocks *its own chronicle* sees.
 */
class CatalogCutoverPlanThreadTest extends WP_UnitTestCase {

	private string $a = 'cutover-plan-a';
	private string $b = 'cutover-plan-b';

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		Game::create( [ 'slug' => $this->a, 'name' => 'Cutover Plan A' ] );
		Game::create( [ 'slug' => $this->b, 'name' => 'Cutover Plan B' ] );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::reset_cache();
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

	public function test_a_chronicle_plan_reports_what_moves_what_rekeys_and_what_stays(): void {
		$this->character( $this->a, 'vampire', 'Plan Vampire', $this->vampire_sheet() );

		$plan = Catalog_Cutover::plan( $this->a );

		$this->assertTrue( $plan['available'] );
		$this->assertFalse( $plan['declared'] );
		$this->assertSame( 1, $plan['characters'] );
		$this->assertSame( 1, $plan['characters_changed'] );
		$this->assertSame( 4, $plan['totals']['moved_rows'] );
		$this->assertSame( 1, $plan['totals']['rekeyed'] );
		$this->assertSame( 1, $plan['totals']['kept_custom'] );
		$this->assertSame( [ 'label' => 1 ], $plan['totals']['by_tier'] );
		$this->assertSame( [ 'no_match' => 1 ], $plan['totals']['by_reason'] );
		$this->assertSame( [ 'characters' => 1, 'changed' => 1, 'rekeyed' => 1, 'kept_custom' => 1, 'untouched' => 0 ], $plan['by_game'][ $this->a ] );
		$this->assertSame( [], $plan['retention_gaps'] );
	}

	public function test_the_plan_counts_the_characters_a_reimport_could_replace_by_chronicle(): void {
		$as_imported = $this->character( $this->a, 'vampire', 'As Imported', $this->vampire_sheet() );
		$edited      = $this->character( $this->a, 'vampire', 'Edited', $this->vampire_sheet() );
		$elsewhere   = $this->character( $this->b, 'vampire', 'Elsewhere', $this->vampire_sheet() );
		foreach ( [ $as_imported, $edited, $elsewhere ] as $id ) {
			Change::create( [ 'character_id' => $id, 'change_type' => 'import_note', 'category' => 'import', 'change_data' => [ 'source_file' => 'x.gex' ], 'xp_cost' => 0, 'status' => 'approved', 'submitted_by' => 1 ] );
		}
		Change::create( [ 'character_id' => $edited, 'change_type' => 'xp_earn', 'category' => 'xp', 'change_data' => [ 'amount' => 3 ], 'xp_cost' => 0, 'status' => 'approved', 'submitted_by' => 1 ] );
		Character::update_header( $as_imported, [ 'wp_user_id' => self::factory()->user->create() ] );

		$all = Catalog_Cutover::plan();
		$one = Catalog_Cutover::plan( $this->a );

		$this->assertSame( 1, $one['untouched']['characters'] );
		$this->assertSame( 1, $one['by_game'][ $this->a ]['untouched'] );
		$this->assertSame( 2, $all['by_game'][ $this->a ]['untouched'] + $all['by_game'][ $this->b ]['untouched'] );
		$this->assertSame( 1, $one['untouched']['with_player'], 'the one untouched character here has a player, which a re-import would have to put back' );
		$this->assertSame( 1, $all['untouched']['with_player'], 'and it is counted once across the install' );
	}

	public function test_every_custom_row_is_a_row_with_its_character_attached(): void {
		$id   = $this->character( $this->a, 'vampire', 'Plan Vampire', $this->vampire_sheet() );
		$rows = Catalog_Cutover::plan( $this->a )['rows'];

		$this->assertCount( 2, $rows );
		$this->assertSame( [ 'character_id' => $id, 'character' => 'Plan Vampire', 'game' => $this->a ], array_intersect_key( $rows[0], [ 'character_id' => 1, 'character' => 1, 'game' => 1 ] ) );

		$by_from = array_column( $rows, null, 'from' );
		$this->assertSame( 'rekeyed', $by_from['Lore: Kindred']['outcome'] );
		$this->assertSame( 'Lore', $by_from['Lore: Kindred']['to'] );
		$this->assertSame( 'specialization', $by_from['Lore: Kindred']['home'] );
		$this->assertSame( 'kept', $by_from['Basket Weaving']['outcome'] );
		$this->assertSame( 'no_match', $by_from['Basket Weaving']['reason'] );
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
		$this->assertNull(
			array_column( Schema_Block::find_by_slug( 'vampire-abilities' )->definition->items, null, 'name' )['Basket Weaving'] ?? null,
			'precondition: the global catalog has no Basket Weaving'
		);

		// Chronicle B forks vampire-abilities and adds it.
		$fork       = Schema_Block::find_or_create_fork_for_game( 'vampire-abilities', $this->b );
		$definition = json_decode( wp_json_encode( $fork->definition ), true );
		$definition['items'][] = [ 'name' => 'Basket Weaving', 'cost' => '1', 'tier' => null, 'group' => null, 'subgroup' => null ];
		Schema_Block::update( 'vampire-abilities', [ 'definition' => $definition ], $this->b );

		$this->character( $this->a, 'vampire', 'Global Vampire', $this->vampire_sheet() );
		$this->character( $this->b, 'vampire', 'Forked Vampire', $this->vampire_sheet() );

		$plan = Catalog_Cutover::plan();

		$this->assertSame( 1, $plan['by_game'][ $this->a ]['kept_custom'], 'the global catalog does not know Basket Weaving' );
		$this->assertSame( 0, $plan['by_game'][ $this->b ]['kept_custom'], 'the fork does' );
		$this->assertSame( 2, $plan['by_game'][ $this->b ]['rekeyed'], 'Lore: Kindred and the forked Basket Weaving' );
	}

	public function test_a_catalog_row_the_replacement_lacks_is_a_retention_gap_with_its_character(): void {
		$id = $this->character( $this->a, 'vampire', 'Gap Vampire', [ 'met-abilities' => [ [ 'name' => 'Definitely Not An Ability' ] ] ] );

		$gap = Catalog_Cutover::plan( $this->a )['retention_gaps'][0];

		$this->assertSame( $id, $gap['character_id'] );
		$this->assertSame( 'Gap Vampire', $gap['character'] );
		$this->assertSame( 'met-abilities', $gap['block_from'] );
		$this->assertSame( 'vampire-abilities', $gap['block_to'] );
		$this->assertSame( 'Definitely Not An Ability', $gap['name'] );
	}

	public function test_a_catalog_row_the_declared_catalog_spells_differently_is_respelled_not_a_gap(): void {
		// R10, from the real corpus: the legacy catalog holds "Fortune-telling", "Light Sensitive" and a
		// "Meditiation" typo; the declared one spells them Fortune-Telling, Light-Sensitive and Meditation.
		$this->character( $this->a, 'vampire', 'Spelling Vampire', [
			'met-abilities' => [ [ 'name' => 'Fortune-telling', 'count' => 2 ], [ 'name' => 'Meditiation', 'count' => 1 ] ],
			'met-flaws'     => [ [ 'name' => 'Light Sensitive' ] ],
		] );

		$plan = Catalog_Cutover::plan( $this->a );

		$this->assertSame( [], $plan['retention_gaps'] );
		$this->assertSame( 3, $plan['totals']['respelled'] );
		$this->assertSame( 0, $plan['totals']['rekeyed'], 'these are catalog rows, not custom entries' );
		$this->assertSame( 0, $plan['totals']['kept_custom'] );

		$rows = array_column( $plan['rows'], null, 'from' );
		$this->assertSame( 'Fortune-Telling', $rows['Fortune-telling']['to'] );
		$this->assertTrue( $rows['Fortune-telling']['catalog'] );
		$this->assertSame( 'alias', $rows['Meditiation']['tier'] );
		$this->assertSame( 'Meditation', $rows['Meditiation']['to'] );
		$this->assertSame( 'Light-Sensitive', $rows['Light Sensitive']['to'] );
	}

	public function test_rows_under_a_retired_block_with_no_replacement_are_reported_not_lost(): void {
		$id = $this->character( $this->a, 'demon', 'Lore Demon', [
			'met-abilities' => [ [ 'name' => 'Occult', 'count' => 2 ] ],
			'demon-lores'   => [ [ 'name' => 'Vampire' ], [ 'name' => 'Werewolf' ] ],
		] );

		$orphans = Catalog_Cutover::plan( $this->a )['unmapped_retired_data'];

		$this->assertCount( 1, $orphans );
		$this->assertSame( $id, $orphans[0]['character_id'] );
		$this->assertSame( 'demon-lores', $orphans[0]['block'] );
		$this->assertSame( 2, $orphans[0]['rows'] );
	}

	public function test_a_declared_install_reports_no_unmapped_data_because_there_is_no_legacy_to_compare(): void {
		$this->character( $this->a, 'demon', 'Lore Demon', [ 'demon-lores' => [ [ 'name' => 'Vampire' ] ] ] );
		update_option( Catalog_Cutover::OPTION, 'declared' );

		$plan = Catalog_Cutover::plan( $this->a );

		$this->assertTrue( $plan['declared'] );
		$this->assertSame( [], $plan['unmapped_retired_data'] );
	}

	public function test_pending_changes_on_a_retired_slug_are_listed_with_where_they_will_go(): void {
		$id      = $this->character( $this->a, 'vampire', 'Pending Vampire', $this->vampire_sheet() );
		$moving  = Change::create( [
			'character_id' => $id, 'change_type' => 'add_trait', 'category' => 'met-merits',
			'change_data'  => [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ], 'submitted_by' => 1,
		] );
		Change::create( [
			'character_id' => $id, 'change_type' => 'modify_identity', 'category' => 'vampire-identity',
			'change_data'  => [ 'block_slug' => 'vampire-identity', 'fields' => [ 'Clan' => 'Ventrue' ] ], 'submitted_by' => 1,
		] );
		Change::create( [
			'character_id' => $id, 'change_type' => 'add_trait', 'category' => 'met-flaws', 'status' => 'approved',
			'change_data'  => [ 'block_slug' => 'met-flaws', 'trait' => [ 'name' => 'Prey Exclusion' ] ], 'submitted_by' => 1,
		] );

		$this->assertSame(
			[ [ 'id' => $moving, 'character_id' => $id, 'from' => 'met-merits', 'to' => 'vampire-merits' ] ],
			Catalog_Cutover::plan( $this->a )['pending_changes']
		);
	}

	public function test_the_options_trim_the_report(): void {
		$this->character( $this->a, 'vampire', 'Plan Vampire', $this->vampire_sheet() );

		$plan = Catalog_Cutover::plan( $this->a, [ 'rows' => false, 'suggestions' => false ] );
		$this->assertSame( [], $plan['rows'] );
		$this->assertSame( 1, $plan['totals']['kept_custom'], 'the counts survive without the per-row list' );

		$kept = static fn( array $rows ) => array_values( array_filter( $rows, static fn( $r ) => $r['outcome'] === 'kept' ) )[0];

		$this->assertArrayHasKey( 'suggestions', $kept( Catalog_Cutover::plan( $this->a )['rows'] ) );
		$this->assertArrayNotHasKey( 'suggestions', $kept( Catalog_Cutover::plan( $this->a, [ 'suggestions' => false ] )['rows'] ) );
	}

	public function test_custom_rows_in_a_block_that_does_not_move_are_matched_too(): void {
		// vampire-backgrounds is not a retired block: its custom rows are judged against it in place.
		$this->character( $this->a, 'vampire', 'Backgrounds Vampire', [
			'vampire-backgrounds' => [ [ 'name' => 'Allies (the Prince)', 'count' => 2, 'custom' => true ] ],
		] );

		$row = Catalog_Cutover::plan( $this->a )['rows'][0];

		$this->assertSame( 'vampire-backgrounds', $row['block_from'] );
		$this->assertSame( 'vampire-backgrounds', $row['block_to'] );
		$this->assertSame( 'rekeyed', $row['outcome'] );
		$this->assertSame( 'Allies', $row['to'] );
		$this->assertSame( 'specialization', $row['home'] );
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
