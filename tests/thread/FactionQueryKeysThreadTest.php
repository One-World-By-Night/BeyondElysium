<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Position;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * The real Grapevine "Group" and "Position" query keys (field-map.php's `group`/`position` entries) now resolve to a
 * character's active `be_faction_members` and `be_positions` rows, comma-joined.
 */
class FactionQueryKeysThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-faction-query-keys';
	private int $game_id;
	private int $storyteller_id;

	public function setUp(): void {
		parent::setUp();
		$this->game_id       = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Faction Query Keys' ] );
		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
	}

	private function character( string $name ): int {
		return (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'status' => 'active', 'created_by' => $this->storyteller_id,
		] );
	}

	/** @return string[] */
	private function names( string $field, string $operator, $value ): array {
		$result = Query_Engine::execute( $this->slug, [ [ 'field' => $field, 'operator' => $operator, 'find' => $value, 'value' => $value ] ], 'AND' );
		$names  = array_map( static fn( $row ) => $row->name, $result['results'] );
		sort( $names );
		return $names;
	}

	public function test_group_matches_an_active_faction_membership(): void {
		$member = $this->character( 'A Member' );
		$this->character( 'Not A Member' );
		$faction_id = (int) Faction::create( [
			'game_id' => $this->game_id, 'name' => 'Coterie of Thorns', 'faction_type' => 'coterie',
			'audience' => 'storytellers', 'created_by' => $this->storyteller_id,
		] );
		Faction_Member::add( $faction_id, $member, $this->storyteller_id );

		$this->assertSame( [ 'A Member' ], $this->names( 'group', 'contains', 'Thorns' ) );
	}

	public function test_group_ignores_a_disbanded_faction(): void {
		$member     = $this->character( 'A Member' );
		$faction_id = (int) Faction::create( [
			'game_id' => $this->game_id, 'name' => 'Coterie of Thorns', 'faction_type' => 'coterie',
			'created_by' => $this->storyteller_id,
		] );
		Faction_Member::add( $faction_id, $member, $this->storyteller_id );
		// A faction is always created active.
		Faction::update( $faction_id, [ 'status' => 'disbanded' ] );

		$this->assertSame( [], $this->names( 'group', 'contains', 'Thorns' ) );
	}

	public function test_group_joins_more_than_one_faction(): void {
		$member = $this->character( 'A Member' );
		$first  = (int) Faction::create( [ 'game_id' => $this->game_id, 'name' => 'Coterie of Thorns', 'faction_type' => 'coterie', 'created_by' => $this->storyteller_id ] );
		$second = (int) Faction::create( [ 'game_id' => $this->game_id, 'name' => 'The Camarilla', 'faction_type' => 'sect', 'created_by' => $this->storyteller_id ] );
		Faction_Member::add( $first, $member, $this->storyteller_id );
		Faction_Member::add( $second, $member, $this->storyteller_id );

		$this->assertSame( [ 'A Member' ], $this->names( 'group', 'contains', 'Camarilla' ) );
		$this->assertSame( [ 'A Member' ], $this->names( 'group', 'contains', 'Thorns' ) );
	}

	public function test_position_matches_a_title_regardless_of_holder_public(): void {
		$holder = $this->character( 'The Sheriff' );
		Position::create( [
			'game_id' => $this->game_id, 'title' => 'Sheriff', 'character_id' => $holder,
			'holder_public' => false, 'created_by' => $this->storyteller_id,
		] );

		$this->assertSame( [ 'The Sheriff' ], $this->names( 'position', 'equals', 'Sheriff' ) );
	}

	public function test_position_joins_more_than_one_title(): void {
		$holder = $this->character( 'The Prince' );
		Position::create( [ 'game_id' => $this->game_id, 'title' => 'Prince', 'character_id' => $holder, 'created_by' => $this->storyteller_id ] );
		Position::create( [ 'game_id' => $this->game_id, 'title' => 'Keeper of Elysium', 'character_id' => $holder, 'created_by' => $this->storyteller_id ] );

		$this->assertSame( [ 'The Prince' ], $this->names( 'position', 'contains', 'Keeper' ) );
	}

	public function test_a_vacant_position_matches_no_one(): void {
		Position::create( [ 'game_id' => $this->game_id, 'title' => 'Prince', 'created_by' => $this->storyteller_id ] );
		$this->character( 'Anyone' );

		$this->assertSame( [], $this->names( 'position', 'equals', 'Prince' ) );
	}
}
