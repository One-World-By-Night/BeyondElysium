<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * A marker the book adds to a creature type's Health section or to a resource pool reaches a chronicle that already
 * has its own copy, and what the chronicle changed stays.
 */
class CatalogMarkersReachChroniclesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-markers';

	public function setUp(): void {
		parent::setUp();
		Game::create( [ 'slug' => $this->slug, 'name' => 'Markers by Night' ] );
	}

	public function test_a_health_track_marker_added_to_the_book_reaches_a_chronicles_layer(): void {
		Schema_Block::create( [
			'slug' => 'tm-health', 'name' => 'Health', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [ [ 'name' => 'Healthy' ], [ 'name' => 'Bruised' ] ], 'default_held' => [ [ 'name' => 'Healthy', 'count' => 1 ] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'tm-merits', 'name' => 'Merits', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [] ],
		] );
		Creature_Stack::create( [
			'slug' => 'tm-stack', 'name' => 'Marked Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'tm-merits', 'label' => 'Merits', 'display_order' => 10 ],
				[ 'block_slug' => 'tm-health', 'label' => 'Health', 'display_order' => 20 ],
			] ],
		] );
		$this->assertTrue( Creature_Stack::hide_section( 'tm-stack', $this->slug, 'tm-merits' ) );

		$book = json_decode( (string) wp_json_encode( Creature_Stack::find_by_slug( 'tm-stack' )->stack_definition ), true );
		$book['sections'][1]['health_track'] = true;
		Creature_Stack::update( 'tm-stack', [ 'stack_definition' => $book ] );
		$this->assertSame( 1, Creature_Stack::refresh_layers( 'tm-stack' ) );

		$resolved = Creature_Stack::resolve( 'tm-stack', $this->slug );
		$sections = [];
		foreach ( $resolved['stack']->stack_definition->sections as $section ) {
			$sections[ $section->block_slug ] = $section;
		}
		$this->assertTrue( $sections['tm-health']->health_track, "the book's marker" );
		$this->assertTrue( $sections['tm-merits']->hidden, "the chronicle's own change" );
		$this->assertObjectNotHasProperty( 'health_track', $sections['tm-merits'] );
	}

	public function test_a_permanent_spend_marker_added_to_the_book_reaches_a_chronicles_fork_of_the_block(): void {
		Schema_Block::create( [
			'slug' => 'tm-pools', 'name' => 'Resources', 'section_type' => 'resource_pool', 'is_system' => 0,
			'definition' => [ 'pools' => [
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 2, 'max' => 10, 'cost_per_dot' => 3 ],
				[ 'name' => 'Blood', 'value_type' => 'integer', 'default_start' => 10, 'max' => 20, 'cost_per_dot' => 1 ],
			] ],
		] );
		$fork = Schema_Block::find_or_create_fork_for_game( 'tm-pools', $this->slug );
		$copy = json_decode( (string) wp_json_encode( $fork->definition ), true );
		$copy['pools'][1]['max'] = 30;
		$this->assertTrue( Schema_Block::update( 'tm-pools', [ 'definition' => $copy ], $this->slug ) );

		$book = json_decode( (string) wp_json_encode( Schema_Block::find_by_slug( 'tm-pools' )->definition ), true );
		$book['pools'][0]['permanent_spend'] = true;
		$this->assertTrue( Schema_Block::update( 'tm-pools', [ 'definition' => $book ] ) );
		$this->assertGreaterThanOrEqual( 1, Schema_Block::refresh_forks( 'tm-pools' ) );

		$own = Schema_Block::find_for_game( 'tm-pools', $this->slug );
		$this->assertSame( $this->slug, $own->game_slug );
		$this->assertTrue( $own->definition->pools[0]->permanent_spend, "the book's marker" );
		$this->assertObjectNotHasProperty( 'permanent_spend', $own->definition->pools[1] );
		$this->assertSame( 30, $own->definition->pools[1]->max, "the chronicle's own change" );
	}
}
