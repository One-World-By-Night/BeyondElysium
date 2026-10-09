<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Item_Catalog;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Creating and editing a world object store the same cleaned values for its name, description and limitations.
 */
class WorldObjectWritesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-world-object-writes';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'World Object Writes' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function send( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_edit_cleans_name_description_and_limitations_the_way_creating_does(): void {
		$unsafe  = [
			'name'        => 'Dagger <script>alert(1)</script>',
			'description' => 'Sharp. <script>alert(2)</script>',
			'limitations' => 'Breaks. <script>alert(3)</script>',
		];
		$created = $this->send( 'POST', '/world-objects', array_merge( [ 'object_type' => 'item' ], $unsafe ) );
		$this->assertSame( 201, $created->get_status() );
		$id = (int) $created->get_data()->id;

		$this->assertSame( 200, $this->send( 'PUT', "/world-objects/{$id}", $unsafe )->get_status() );

		$object = World_Object::find( $id );
		foreach ( array_keys( $unsafe ) as $field ) {
			$this->assertStringNotContainsString( '<script', (string) $object->$field, $field );
		}
		$this->assertStringContainsString( 'Sharp.', (string) $object->description );
	}

	public function test_rarity_and_cost_are_cleaned_on_create_and_on_edit(): void {
		$unsafe  = [ 'rarity' => '<b>Rare</b>', 'cost' => '3 <img src=x onerror=alert(5)>' ];
		$created = $this->send( 'POST', '/world-objects', array_merge( [ 'object_type' => 'item', 'name' => 'Dagger' ], $unsafe ) );
		$id      = (int) $created->get_data()->id;
		$this->assertSame( [ 'Rare', '3' ], [ World_Object::find( $id )->rarity, World_Object::find( $id )->cost ] );

		$this->send( 'PUT', "/world-objects/{$id}", [ 'rarity' => '<i>Unique</i>', 'cost' => '<script>x</script>9' ] );
		$this->assertSame( [ 'Unique', '9' ], [ World_Object::find( $id )->rarity, World_Object::find( $id )->cost ] );
	}

	/**
	 * Rarity is a 20-character column behind a free-text box.
	 */
	public function test_a_rarity_longer_than_its_column_is_refused_by_name_not_as_a_server_error(): void {
		$response = $this->send( 'POST', '/world-objects', [ 'object_type' => 'item', 'name' => 'Relic', 'rarity' => 'Legendary artifact (unique)' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'Rarity', $response->as_error()->get_error_message() );
		$this->assertSame( 400, $this->send( 'POST', '/world-objects', [ 'object_type' => 'item', 'name' => '<script>alert(1)</script>' ] )->get_status(), 'a name that cleans down to nothing' );
	}

	private function a_boon(): int {
		$debtor   = Character::create( [ 'name' => 'Debtor', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		$creditor = Character::create( [ 'name' => 'Creditor', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/boons" );
		$request->set_param( 'owed_by_character_id', $debtor );
		$request->set_param( 'owed_to_character_id', $creditor );
		$request->set_param( 'boon_level', 'major' );
		$request->set_param( 'terms', 'One favor' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );
		return (int) $response->get_data()['id'];
	}

	public function test_a_boon_is_never_deleted_through_the_world_object_route(): void {
		$boon = $this->a_boon();

		$this->assertSame( 409, $this->send( 'DELETE', "/world-objects/{$boon}" )->get_status() );
		$this->assertNotNull( World_Object::find( $boon ) );
		$this->assertCount( 2, Connection::for_source( 'world_object', $boon ) );
	}

	public function test_a_boon_is_not_rewritten_or_made_through_the_world_object_route(): void {
		$boon = $this->a_boon();

		$this->assertSame( 409, $this->send( 'PUT', "/world-objects/{$boon}", [ 'properties' => [ 'terms' => 'Forgiven', 'status' => 'repaid' ] ] )->get_status() );
		$this->assertSame( 'One favor', World_Object::find( $boon )->properties['terms'] );

		$this->assertSame( 409, $this->send( 'POST', '/world-objects', [ 'object_type' => 'boon', 'name' => 'A loose boon' ] )->get_status() );
	}

	public function test_an_item_still_edits_and_deletes(): void {
		$item = (int) $this->send( 'POST', '/world-objects', [ 'object_type' => 'item', 'name' => 'Lantern' ] )->get_data()->id;

		$this->assertSame( 200, $this->send( 'PUT', "/world-objects/{$item}", [ 'name' => 'Old Lantern' ] )->get_status() );
		$this->assertSame( 204, $this->send( 'DELETE', "/world-objects/{$item}" )->get_status() );
	}

	/**
	 * A text property is sanitized as HTML and a string property as plain text.
	 */
	public function test_a_text_property_is_sanitized_as_html_and_a_string_one_as_plain_text(): void {
		$unsafe  = [ 'powers' => 'Bites. <script>alert(1)</script> <strong>Bold.</strong>', 'concealability' => 'Easy <script>alert(2)</script>' ];
		$created = $this->send( 'POST', '/world-objects', [ 'object_type' => 'item', 'name' => 'Cursed Ring', 'properties' => $unsafe ] );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id = (int) $created->get_data()->id;

		$stored = World_Object::find( $id )->properties;
		$this->assertStringNotContainsString( '<script', $stored['powers'] );
		$this->assertStringContainsString( '<strong>Bold.</strong>', $stored['powers'], 'a text property keeps safe markup' );
		$this->assertSame( 'Easy', $stored['concealability'], 'sanitize_text_field() drops a script element - tag and content both' );

		$this->assertSame( 200, $this->send( 'PUT', "/world-objects/{$id}", [ 'properties' => $unsafe ] )->get_status() );
		$restored = World_Object::find( $id )->properties;
		$this->assertStringNotContainsString( '<script', $restored['powers'] );
		$this->assertSame( 'Easy', $restored['concealability'] );
	}

	public function test_a_dark_epics_club_saves_as_the_chronicles_own_item_with_a_book_ref(): void {
		$entry = Item_Catalog::find( 'dark-epics:club-stake' );
		$this->assertNotNull( $entry, 'the real shipped Dark Epics catalog must have a "club" entry' );

		$response = $this->send( 'POST', '/world-objects', [
			'object_type' => 'item',
			'name'        => $entry['name'],
			'description' => $entry['description'] ?? '',
			'properties'  => array_merge( $entry['properties'], [ 'book_ref' => $entry['book_ref'] ] ),
		] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id    = (int) $response->get_data()->id;
		$saved = World_Object::find( $id );

		$this->assertSame( $this->game_id, (int) $saved->game_id, 'it belongs to this chronicle, not the book' );
		$this->assertSame( 'dark-epics:club-stake', $saved->properties['book_ref'] );
		$this->assertSame( $entry['properties']['bonus'], $saved->properties['bonus'] );
	}

	public function test_editing_a_book_started_copy_leaves_the_book_itself_untouched(): void {
		$before = Item_Catalog::find( 'dark-epics:club-stake' );

		$created = $this->send( 'POST', '/world-objects', [
			'object_type' => 'item',
			'name'        => $before['name'],
			'properties'  => array_merge( $before['properties'], [ 'book_ref' => $before['book_ref'] ] ),
		] );
		$id = (int) $created->get_data()->id;

		$this->send( 'PUT', "/world-objects/{$id}", [
			'name'       => 'Nail-Studded Club',
			'properties' => [ 'bonus' => 9 ],
		] );

		Item_Catalog::reset_cache();
		$after = Item_Catalog::find( 'dark-epics:club-stake' );

		$this->assertSame( $before['name'], $after['name'] );
		$this->assertSame( $before['properties']['bonus'], $after['properties']['bonus'] );
		$this->assertSame( 'Nail-Studded Club', World_Object::find( $id )->name, 'the chronicle\'s own copy did change' );
	}

	public function test_a_fetishs_gnosis_is_a_temper_and_its_cost_the_level(): void {
		$entry = Item_Catalog::find( 'changing-breeds-1:coyotes-fang' );
		$this->assertNotNull( $entry, 'the real shipped Changing Breeds catalog must have a "coyotes-fang" entry' );

		$response = $this->send( 'POST', '/world-objects', [
			'object_type' => 'item',
			'name'        => $entry['name'],
			'properties'  => array_merge( $entry['properties'], [ 'book_ref' => $entry['book_ref'] ] ),
		] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$saved = World_Object::find( (int) $response->get_data()->id );

		$this->assertSame( 'Fetish', $saved->properties['item_type'] );
		$this->assertSame( 3, $saved->properties['level'], 'Fetish Trait Cost becomes properties.level' );
		$this->assertSame(
			[ [ 'name' => 'Gnosis', 'count' => 4 ] ],
			$saved->properties['tempers'],
			'Gnosis becomes a tempers entry'
		);
	}
}
