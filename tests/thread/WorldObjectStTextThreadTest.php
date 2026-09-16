<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-046 (D44). Owner ruling 2026-09-14: item and location text supports `[ST]` the
 * way character text does - hidden from anyone who isn't a Storyteller of the chronicle.
 *
 * Every member reads the world-object catalog, and nothing filtered it: a Storyteller's
 * `[ST]...[/ST]` note in an item's description, a location's security, or a boon's terms went to
 * every player, through the catalog and through the item, location, and rote cards.
 */
class WorldObjectStTextThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-world-object-st';
	private int $player;
	private int $hst;
	private int $item;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id      = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'World Object ST' ] );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->hst    = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );

		$this->item = (int) World_Object::create( [
			'game_id' => $game_id, 'object_type' => 'item', 'name' => 'Cursed Blade',
			'description' => 'An old sword. [ST]It drinks the wielder\'s blood.[/ST]',
			'limitations' => 'Two-handed. [ST]Breaks at midnight.[/ST]',
			'properties'  => [ 'powers' => 'Aggravated damage. [ST]Summons its maker.[/ST]', 'level' => 3 ],
		] );
		World_Object::create( [
			'game_id' => $game_id, 'object_type' => 'location', 'name' => 'The Vault',
			'properties' => [ 'security' => 'Locked. [ST]The key is under the mat.[/ST]' ],
		] );
	}

	private function get( int $as, string $route, array $query = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'GET', $route );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_never_sees_storyteller_text_in_the_catalog(): void {
		$list = wp_json_encode( $this->get( $this->player, "/be/v1/{$this->slug}/world-objects" )->get_data() );
		$one  = wp_json_encode( $this->get( $this->player, "/be/v1/{$this->slug}/world-objects/{$this->item}" )->get_data() );

		foreach ( [ 'drinks the wielder', 'Breaks at midnight', 'Summons its maker', 'under the mat' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $list );
		}
		foreach ( [ 'drinks the wielder', 'Breaks at midnight', 'Summons its maker' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $one );
		}
		$this->assertStringContainsString( 'An old sword.', $one, 'the public text stays' );
	}

	public function test_a_player_never_sees_storyteller_text_on_catalog_cards(): void {
		$cards = wp_json_encode( $this->get( $this->player, "/be/v1/{$this->slug}/reports/item-cards" )->get_data() );

		$this->assertStringContainsString( 'Cursed Blade', $cards );
		$this->assertStringNotContainsString( 'drinks the wielder', $cards );
		$this->assertStringNotContainsString( 'Summons its maker', $cards );
	}

	public function test_a_player_cannot_probe_storyteller_text_by_searching_or_filtering(): void {
		$this->assertSame( [], $this->get( $this->player, "/be/v1/{$this->slug}/world-objects", [ 'search' => 'drinks the wielder' ] )->get_data() );
		$this->assertCount( 1, $this->get( $this->player, "/be/v1/{$this->slug}/world-objects", [ 'search' => 'old sword' ] )->get_data(), 'public text is still searchable' );

		$cards = $this->get( $this->player, "/be/v1/{$this->slug}/reports/item-cards", [
			'conditions' => wp_json_encode( [ [ 'field' => 'powers', 'operator' => 'contains', 'find' => 'Summons' ] ] ),
		] )->get_data();
		$this->assertSame( [], $cards['cards'] ?? null );
	}

	public function test_a_player_never_sees_storyteller_text_in_boon_terms(): void {
		$debtor   = Character::create( [ 'name' => 'Debtor', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		$creditor = Character::create( [ 'name' => 'Creditor', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/boons" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'owed_by_character_id' => $debtor, 'owed_to_character_id' => $creditor, 'boon_level' => 'minor',
			'terms' => 'A favor at court. [ST]Actually a blood bond.[/ST]',
		] ) );
		$this->assertSame( 201, rest_get_server()->dispatch( $request )->get_status() );

		$ledger = wp_json_encode( $this->get( $this->player, "/be/v1/{$this->slug}/boons" )->get_data() );
		$this->assertStringContainsString( 'A favor at court.', $ledger );
		$this->assertStringNotContainsString( 'blood bond', $ledger );
	}

	public function test_the_chronicles_storyteller_sees_all_of_it(): void {
		$one = wp_json_encode( $this->get( $this->hst, "/be/v1/{$this->slug}/world-objects/{$this->item}" )->get_data() );

		$this->assertStringContainsString( 'drinks the wielder', $one );
		$this->assertStringContainsString( 'Summons its maker', $one );
	}
}
