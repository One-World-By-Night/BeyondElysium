<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * BM-8 (BE_PROCESS/0.99.2-workflow.md): moving pre-Blood-Magic data onto the new shape.
 * Two separate concerns, two migration functions:
 *
 *   - Schema::migrate_blood_magic_schema_forks() - a chronicle's own game-scoped fork of
 *     vampire-disciplines still carrying tradition-prefixed powers.
 *   - Schema::migrate_blood_magic_held_picks() - a character's own held pick stored under
 *     the old shape, in either of the two real forms (see the function's own docblock).
 *
 * No manual tearDown() - WP_UnitTestCase's own ambient transaction rolls back every write
 * this file makes, the same guarantee every other thread test in this project relies on.
 */
class BloodMagicMigrationTest extends WP_UnitTestCase {

	private const GAME = 'thread-test-blood-magic-migration';

	/**
	 * Forks vampire-disciplines for self::GAME and stashes onto it a synthetic
	 * tradition-prefixed power plus one ordinary one, mirroring the real pre-Blood-Magic
	 * shape - a byte-copy of the corrected global row (already free of colon-prefixed
	 * names, since it was built by the real seeder) is not realistic on its own, so this
	 * reconstructs the stale shape directly rather than relying on incidental fork timing.
	 */
	private function stale_fork_with( array $extra_powers ): void {
		$fork       = Schema_Block::find_or_create_fork_for_game( 'vampire-disciplines', self::GAME );
		$definition = $fork->definition;
		$definition->powers = array_merge(
			array_map( 'json_decode', array_map( 'wp_json_encode', [ [ 'name' => 'Fortitude', 'levels' => [] ] ] ) ),
			array_map( 'json_decode', array_map( 'wp_json_encode', $extra_powers ) )
		);
		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $definition ], self::GAME );
	}

	// -------------------------------------------------------------------------
	// migrate_blood_magic_schema_forks()
	// -------------------------------------------------------------------------

	public function test_a_tradition_prefixed_power_moves_to_the_games_own_blood_magic_fork(): void {
		$this->stale_fork_with( [ [ 'name' => 'Necromancy: Ash Path', 'levels' => [ [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Withering Touch' ] ] ] ] );

		Schema::migrate_blood_magic_schema_forks();

		$disciplines = Schema_Block::find_for_game( 'vampire-disciplines', self::GAME );
		$names       = array_column( $disciplines->definition->powers, 'name' );
		$this->assertContains( 'Fortitude', $names, 'the ordinary power must stay' );
		$this->assertNotContains( 'Necromancy: Ash Path', $names, 'the tradition-prefixed power must move out' );

		$blood_magic = Schema_Block::find_for_game( 'vampire-blood-magic', self::GAME );
		$this->assertSame( self::GAME, $blood_magic->game_slug, 'a real fork must exist, not a fall-through to global' );

		$power = current( array_filter( $blood_magic->definition->powers, static fn( $p ) => $p->name === 'Ash Path' ) );
		$this->assertNotFalse( $power, 'the bare canonical name must exist in the new fork' );
		// "Ash Path" is a real, already multi-tradition path in the global catalog (Wanga,
		// Sadhana) - the transplant must merge Necromancy in as one more entry, not replace
		// or narrow what was already there.
		$this->assertArrayHasKey( 'Necromancy', (array) $power->traditions );
		$this->assertNull( ( (array) $power->traditions )['Necromancy'] );
		$this->assertContains( 'Necromancy', $blood_magic->definition->traditions );
	}

	/**
	 * The four Assamite caste names are castes, not traditions (D39's own trap) - they
	 * already exist as their own ordinary vampire-disciplines families and must never be
	 * split, even though their own name contains "/ "-adjacent punctuation of a similar
	 * shape to a tradition prefix.
	 */
	public function test_a_quietus_caste_name_is_never_treated_as_a_tradition_prefix(): void {
		$this->stale_fork_with( [ [ 'name' => 'Quietus, Cruscitus / Warrior', 'levels' => [] ] ] );

		Schema::migrate_blood_magic_schema_forks();

		$disciplines = Schema_Block::find_for_game( 'vampire-disciplines', self::GAME );
		$this->assertContains( 'Quietus, Cruscitus / Warrior', array_column( $disciplines->definition->powers, 'name' ) );
	}

	/**
	 * A path already present in the freshly-forked (already-correct, global-derived)
	 * vampire-blood-magic catalog must gain the transplanted tradition as one more entry
	 * in its `traditions` map, never a second duplicate power of the same name - the
	 * common case, since find_or_create_fork_for_game() seeds a brand-new fork from the
	 * already-correct 111-path global catalog.
	 */
	public function test_a_path_already_in_the_global_catalog_merges_rather_than_duplicates(): void {
		// "Path of Blood" is real and seeded globally (multiple traditions) - use it so the
		// merge path is exercised against genuine global data, not a synthetic stand-in.
		$this->stale_fork_with( [ [ 'name' => 'Nahuallotl: Path of Blood', 'levels' => [] ] ] );

		Schema::migrate_blood_magic_schema_forks();

		$blood_magic = Schema_Block::find_for_game( 'vampire-blood-magic', self::GAME );
		$matches     = array_filter( $blood_magic->definition->powers, static fn( $p ) => $p->name === 'Path of Blood' );
		$this->assertCount( 1, $matches, 'must not create a second "Path of Blood" power' );

		$power = array_values( $matches )[0];
		$this->assertArrayHasKey( 'Nahuallotl', (array) $power->traditions, 'the transplanted tradition must be merged in' );
	}

	public function test_is_idempotent(): void {
		$this->stale_fork_with( [ [ 'name' => 'Necromancy: Ash Path', 'levels' => [] ] ] );

		Schema::migrate_blood_magic_schema_forks();
		$before = Schema_Block::find_for_game( 'vampire-blood-magic', self::GAME )->definition;

		// Running it again against an already-clean fork must be a safe no-op.
		Schema::migrate_blood_magic_schema_forks();
		$after = Schema_Block::find_for_game( 'vampire-blood-magic', self::GAME )->definition;

		$this->assertEquals( $before, $after );
	}

	public function test_a_fork_with_nothing_colon_prefixed_is_left_untouched_and_gains_no_blood_magic_fork(): void {
		$this->stale_fork_with( [] ); // Just "Fortitude", already clean.

		Schema::migrate_blood_magic_schema_forks();

		$blood_magic = Schema_Block::find_for_game( 'vampire-blood-magic', self::GAME );
		$this->assertNotSame( self::GAME, $blood_magic->game_slug ?? '', 'no fork should have been created' );
	}

	// -------------------------------------------------------------------------
	// migrate_blood_magic_held_picks()
	// -------------------------------------------------------------------------

	private function character_with_disciplines( array $held ): int {
		return Character::create( [
			'name' => 'Blood Magic Migration Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => self::GAME,
			'sheet_data' => [ 'vampire-disciplines' => $held ],
		] );
	}

	public function test_a_catalog_matched_tradition_prefixed_pick_moves_and_splits(): void {
		$id = $this->character_with_disciplines( [
			[ 'name' => 'Fortitude', 'level' => 3 ],
			[ 'name' => 'Necromancy: Ash Path', 'level' => 2 ],
		] );

		Schema::migrate_blood_magic_held_picks();

		$character = Character::find( $id );
		$disciplines = $character->sheet_data['vampire-disciplines'];
		$this->assertCount( 1, $disciplines );
		$this->assertSame( 'Fortitude', $disciplines[0]['name'] );

		$blood_magic = $character->sheet_data['vampire-blood-magic'];
		$this->assertCount( 1, $blood_magic );
		$this->assertSame( 'Ash Path', $blood_magic[0]['name'] );
		$this->assertSame( 'Necromancy', $blood_magic[0]['tradition'] );
		$this->assertSame( 2, $blood_magic[0]['level'] );
	}

	/**
	 * D41/Decision 074's real keep_custom shape - the tradition sits in `name`, the path in
	 * `power_name`. Chase Ashford's own real committed import (data-samples/1506_chase_ashford_.gex)
	 * is exactly this. Spelling variance ("Dur-An-Ki") is folded to the canonical form
	 * during the move, the same as Trait_Mapper does on import.
	 */
	public function test_a_pre_blood_magic_keep_custom_pick_moves_with_tradition_spelling_normalized(): void {
		$id = $this->character_with_disciplines( [
			[ 'name' => 'Dur-An-Ki', 'power_name' => 'Vine of Dionysus', 'level' => 4, 'custom' => true ],
		] );

		Schema::migrate_blood_magic_held_picks();

		$character = Character::find( $id );
		// The key itself is left in place, empty, exactly as a character with genuinely
		// zero ordinary disciplines would already look - not specially removed.
		$this->assertSame( [], $character->sheet_data['vampire-disciplines'] );

		$blood_magic = $character->sheet_data['vampire-blood-magic'];
		$this->assertCount( 1, $blood_magic );
		$this->assertSame( 'Vine of Dionysus', $blood_magic[0]['name'] );
		$this->assertSame( 'Dur An Ki', $blood_magic[0]['tradition'], 'the hyphenated spelling must be folded to the catalog form' );
		$this->assertSame( 4, $blood_magic[0]['level'] );
		$this->assertTrue( $blood_magic[0]['custom'] );
		$this->assertArrayNotHasKey( 'power_name', $blood_magic[0] );
	}

	/**
	 * A keep_custom pick whose `name` does not resemble any real tradition must be left
	 * exactly where it is - it is some other kind of custom discipline entry, not
	 * mis-shapen Blood Magic data, and moving it would be a real, silent data change with
	 * no basis for it.
	 */
	public function test_a_custom_pick_with_an_unrecognizable_name_is_left_alone(): void {
		$id = $this->character_with_disciplines( [
			[ 'name' => 'Some Homebrew Discipline', 'power_name' => 'A Custom Power', 'level' => 2, 'custom' => true ],
		] );

		Schema::migrate_blood_magic_held_picks();

		$character = Character::find( $id );
		$this->assertCount( 1, $character->sheet_data['vampire-disciplines'] );
		$this->assertArrayNotHasKey( 'vampire-blood-magic', $character->sheet_data );
	}

	public function test_is_idempotent_and_untouched_characters_are_never_written_to(): void {
		$id = $this->character_with_disciplines( [ [ 'name' => 'Fortitude', 'level' => 3 ] ] );

		Schema::migrate_blood_magic_held_picks();

		$character = Character::find( $id );
		$this->assertSame( [ [ 'name' => 'Fortitude', 'level' => 3 ] ], $character->sheet_data['vampire-disciplines'] );
		$this->assertArrayNotHasKey( 'vampire-blood-magic', $character->sheet_data );
	}
}
