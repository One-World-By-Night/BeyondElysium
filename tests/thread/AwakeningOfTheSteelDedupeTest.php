<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * The duplicate "Awakening of the Steel" family in `vampire-disciplines` is removed, keeping the tradition-prefixed
 * "Dur An Ki: Awakening the Steel" entry.
 */
class AwakeningOfTheSteelDedupeTest extends WP_UnitTestCase {

	private const BARE      = 'Awakening of the Steel';
	private const SURVIVING = 'Dur An Ki: Awakening the Steel';

	private function levels(): array {
		return [
			[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Confer with the Blade', 'cost' => '3' ],
			[ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Grasp of the Mountain', 'cost' => '3' ],
		];
	}

	/**
	 * Synthesizes both families directly onto `vampire-disciplines`, only adding whichever one a prior dedupe run within
	 * the same test already removed.
	 */
	private function ensure_both_families_present(): void {
		$block      = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$definition = $block->definition;
		$powers     = is_array( $definition->powers ?? null ) ? $definition->powers : [];

		foreach ( [ self::BARE, self::SURVIVING ] as $name ) {
			$exists = false;
			foreach ( $powers as $power ) {
				if ( strcasecmp( (string) ( $power->name ?? '' ), $name ) === 0 ) {
					$exists = true;
					break;
				}
			}
			if ( ! $exists ) {
				$powers[] = (object) [ 'name' => $name, 'levels' => array_map( 'json_decode', array_map( 'wp_json_encode', $this->levels() ) ) ];
			}
		}

		$definition->powers = $powers;
		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $definition ] );
	}

	public function test_the_bare_duplicate_is_removed_and_the_prefixed_one_survives(): void {
		$this->ensure_both_families_present();

		Schema::dedupe_awakening_of_the_steel();

		$block = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$names = array_column( $block->definition->powers, 'name' );
		$this->assertNotContains( self::BARE, $names );
		$this->assertContains( self::SURVIVING, $names );
	}

	public function test_a_held_pick_under_the_bare_name_is_renamed_to_the_surviving_name(): void {
		$this->ensure_both_families_present();

		$character_id = Character::create( [
			'name' => 'Awakening Dedupe Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'thread-test-dedupe-game',
			'sheet_data' => [
				'vampire-disciplines' => [
					[ 'name' => self::BARE, 'level' => 2, 'power_name' => 'Grasp of the Mountain' ],
				],
			],
		] );

		Schema::dedupe_awakening_of_the_steel();

		$character = Character::find( $character_id );
		$held      = $character->sheet_data['vampire-disciplines'][0];
		$this->assertSame( self::SURVIVING, $held['name'] );
		$this->assertSame( 2, $held['level'], 'level/power_name must carry over unchanged - this is a rename, not a re-derivation' );
		$this->assertSame( 'Grasp of the Mountain', $held['power_name'] );
	}

	public function test_is_idempotent(): void {
		$this->ensure_both_families_present();
		Schema::dedupe_awakening_of_the_steel();

		// Running it again against an already-clean catalog must be a safe no-op.
		Schema::dedupe_awakening_of_the_steel();

		$block = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$this->assertContains( self::SURVIVING, array_column( $block->definition->powers, 'name' ) );
	}

	public function test_refuses_to_remove_the_bare_entry_if_no_surviving_twin_exists(): void {
		$this->ensure_both_families_present();

		// Remove the real surviving entry for this one test only.
		$block      = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$definition = $block->definition;
		$definition->powers = array_values( array_filter(
			$definition->powers,
			static fn( $p ) => strcasecmp( (string) ( $p->name ?? '' ), AwakeningOfTheSteelDedupeTest::SURVIVING ) !== 0
		) );
		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $definition ] );

		Schema::dedupe_awakening_of_the_steel();

		$refetched = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$names     = array_column( $refetched->definition->powers, 'name' );
		$this->assertContains( self::BARE, $names, 'never delete data with nothing real to fall back to' );
		$this->assertNotContains( self::SURVIVING, $names, 'precondition check - the survivor really was removed for this test' );
	}
}
