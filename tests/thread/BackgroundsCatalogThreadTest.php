<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Backgrounds_Catalog;
use WP_UnitTestCase;

/**
 * The single fork-aware backgrounds lookup that replaced three copies of
 * Schema_Block::find_by_slug(), which were blind to a chronicle's own fork
 * of a `{stack}-backgrounds` block (BE_PROCESS/background-ledger-apr-design.md
 * §3.1). Placed in the thread layer rather than unit, unlike the design
 * doc's own file listing suggests - every method here resolves through
 * Schema_Block::find_for_game()/all_for_game_by_types(), which are real
 * database reads with no pure-function path to exercise instead.
 */
class BackgroundsCatalogThreadTest extends WP_UnitTestCase {

	private function seed_global( string $slug, array $items ): void {
		Schema_Block::create( [
			'slug' => $slug, 'name' => $slug, 'section_type' => 'trait_list',
			'definition' => [ 'items' => $items ],
		] );
	}

	public function test_sources_for_returns_the_global_block_when_no_fork_exists(): void {
		$this->seed_global( 'thread-catalog-vampire-backgrounds', [
			[ 'name' => 'Bureaucracy', 'source' => 'Influences' ],
			[ 'name' => 'Resources', 'source' => 'Backgrounds' ],
		] );

		$sources = Backgrounds_Catalog::sources_for( 'thread-catalog-vampire-backgrounds', 'thread-catalog-game' );

		$this->assertSame( 'Influences', $sources['Bureaucracy'] );
		$this->assertSame( 'Backgrounds', $sources['Resources'] );
	}

	public function test_sources_for_prefers_the_chronicles_own_fork(): void {
		$this->seed_global( 'thread-catalog-fork-backgrounds', [
			[ 'name' => 'Contacts', 'source' => 'Backgrounds' ],
		] );

		// This chronicle re-labels Contacts as an Influence and adds a house item -
		// the fork the global row's own reader must never see (find_by_slug() would).
		$fork = Schema_Block::find_or_create_fork_for_game( 'thread-catalog-fork-backgrounds', 'thread-catalog-forked-game' );
		$fork->definition->items   = [
			[ 'name' => 'Contacts', 'source' => 'Influences' ],
			[ 'name' => 'House Retainer', 'source' => 'Backgrounds' ],
		];
		Schema_Block::update( 'thread-catalog-fork-backgrounds', [ 'definition' => $fork->definition ], 'thread-catalog-forked-game' );

		$forked_sources = Backgrounds_Catalog::sources_for( 'thread-catalog-fork-backgrounds', 'thread-catalog-forked-game' );
		$this->assertSame( 'Influences', $forked_sources['Contacts'], 'the fork\'s own re-labeling wins' );
		$this->assertSame( 'Backgrounds', $forked_sources['House Retainer'] );

		// A different chronicle with no fork of its own still sees the untouched global row.
		$unforked_sources = Backgrounds_Catalog::sources_for( 'thread-catalog-fork-backgrounds', 'thread-catalog-other-game' );
		$this->assertSame( 'Backgrounds', $unforked_sources['Contacts'] );
		$this->assertArrayNotHasKey( 'House Retainer', $unforked_sources );
	}

	public function test_items_for_is_fork_aware(): void {
		$this->seed_global( 'thread-catalog-items-backgrounds', [ [ 'name' => 'Allies', 'source' => 'Backgrounds' ] ] );

		$fork = Schema_Block::find_or_create_fork_for_game( 'thread-catalog-items-backgrounds', 'thread-catalog-items-game' );
		$fork->definition->items = [ [ 'name' => 'Allies', 'source' => 'Backgrounds' ], [ 'name' => 'Herd', 'source' => 'Backgrounds' ] ];
		Schema_Block::update( 'thread-catalog-items-backgrounds', [ 'definition' => $fork->definition ], 'thread-catalog-items-game' );

		$items = Backgrounds_Catalog::items_for( 'thread-catalog-items-backgrounds', 'thread-catalog-items-game' );
		$this->assertCount( 2, $items );
	}

	public function test_union_names_merges_every_backgrounds_block_and_reports_influence_and_stacks(): void {
		$this->seed_global( 'thread-catalog-union-vampire-backgrounds', [
			[ 'name' => 'Thread Union Bureaucracy', 'source' => 'Influences' ],
			[ 'name' => 'Thread Union Shared', 'source' => 'Backgrounds' ],
		] );
		$this->seed_global( 'thread-catalog-union-werewolf-backgrounds', [
			[ 'name' => 'Thread Union Shared', 'source' => 'Backgrounds' ],
			[ 'name' => 'Thread Union Pure Breed', 'source' => 'Backgrounds' ],
		] );

		$names   = Backgrounds_Catalog::union_names( 'thread-catalog-union-game' );
		$by_name = array_column( $names, null, 'name' );

		$this->assertTrue( $by_name['Thread Union Bureaucracy']['is_influence'] );
		$this->assertSame( [ 'thread-catalog-union-vampire' ], $by_name['Thread Union Bureaucracy']['stacks'] );

		$this->assertFalse( $by_name['Thread Union Shared']['is_influence'] );
		$this->assertEqualsCanonicalizing(
			[ 'thread-catalog-union-vampire', 'thread-catalog-union-werewolf' ],
			$by_name['Thread Union Shared']['stacks'],
			'a name held by two stacks lists both'
		);

		$this->assertSame( [ 'thread-catalog-union-werewolf' ], $by_name['Thread Union Pure Breed']['stacks'] );
	}

	public function test_union_names_is_fork_aware(): void {
		$this->seed_global( 'thread-catalog-union-fork-backgrounds', [ [ 'name' => 'Thread Global Only', 'source' => 'Backgrounds' ] ] );

		$fork = Schema_Block::find_or_create_fork_for_game( 'thread-catalog-union-fork-backgrounds', 'thread-catalog-union-forked-game' );
		$fork->definition->items = [ [ 'name' => 'Thread Forked Extra', 'source' => 'Backgrounds' ] ];
		Schema_Block::update( 'thread-catalog-union-fork-backgrounds', [ 'definition' => $fork->definition ], 'thread-catalog-union-forked-game' );

		$forked_names = array_column( Backgrounds_Catalog::union_names( 'thread-catalog-union-forked-game' ), 'name' );
		$this->assertContains( 'Thread Forked Extra', $forked_names );
		$this->assertNotContains( 'Thread Global Only', $forked_names, 'the fork replaces the global block entirely, not merges into it' );

		$unforked_names = array_column( Backgrounds_Catalog::union_names( 'thread-catalog-union-other-game' ), 'name' );
		$this->assertContains( 'Thread Global Only', $unforked_names );
	}
}
