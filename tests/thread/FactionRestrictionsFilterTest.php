<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The sub-faction restriction feature: beneath `enabled_stacks`' own whole-creature-type toggle, a chronicle can
 * further narrow a real catalog-backed identity_field.
 */
class FactionRestrictionsFilterTest extends WP_UnitTestCase {

	private $manager_id;
	private $game_slug = 'faction-restrictions-filter-test';
	private $sabbat_character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->manager_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Game::create( [
			'slug'       => $this->game_slug,
			'name'       => 'Faction Restrictions Filter Test',
			'created_by' => $this->manager_id,
		] );

		// Pre-existing Sabbat character, created before this chronicle ever restricts its Sect.
		$this->sabbat_character_id = Character::create( [
			'name'       => 'Pre-existing Sabbat',
			'owner_slug' => $this->game_slug,
			'stack_slug' => 'vampire',
			'wp_user_id' => $this->manager_id,
			'created_by' => $this->manager_id,
			'sheet_data' => [
				'vampire-identity' => [ 'Clan' => 'Tzimisce', 'Sect' => 'Sabbat' ],
			],
		] );

		Game::update( $this->game_slug, [
			'settings' => [
				'enabled_factions' => [
					'vampire' => [ 'Sect' => [ 'Camarilla', 'Independent' ] ],
				],
			],
		] );
	}

	public function test_narrow_for_creation_removes_the_disallowed_option(): void {
		$resolved = Creature_Stack::resolve( 'vampire', $this->game_slug );
		$narrowed = Creature_Stack::narrow_for_creation( $resolved, 'vampire', $this->game_slug );

		$field = $this->find_field( $narrowed, 'vampire-identity', 'Sect' );
		$this->assertNotNull( $field );
		$this->assertNotContains( 'Sabbat', $field->options );
		$this->assertContains( 'Camarilla', $field->options );
	}

	public function test_narrow_for_creation_leaves_an_unrestricted_field_untouched(): void {
		$resolved = Creature_Stack::resolve( 'vampire', $this->game_slug );
		$narrowed = Creature_Stack::narrow_for_creation( $resolved, 'vampire', $this->game_slug );

		$field = $this->find_field( $narrowed, 'vampire-identity', 'Clan' );
		$this->assertNotNull( $field );
		$this->assertContains( 'Tzimisce', $field->options, 'Clan carries no restriction here - every option must survive' );
	}

	public function test_narrow_for_creation_is_a_noop_for_an_unrestricted_stack(): void {
		$fresh_slug = 'faction-restrictions-no-setting-test';
		Game::create( [ 'slug' => $fresh_slug, 'name' => 'No Setting Test', 'created_by' => $this->manager_id ] );

		$resolved = Creature_Stack::resolve( 'vampire', $fresh_slug );
		$narrowed = Creature_Stack::narrow_for_creation( $resolved, 'vampire', $fresh_slug );

		$field = $this->find_field( $narrowed, 'vampire-identity', 'Sect' );
		$this->assertGreaterThan( 2, count( $field->options ), 'absent enabled_factions must mean every option, not two' );
	}

	public function test_resolve_itself_never_consults_enabled_factions(): void {
		$resolved = Creature_Stack::resolve( 'vampire', $this->game_slug );

		$field = $this->find_field( $resolved, 'vampire-identity', 'Sect' );
		$this->assertContains( 'Sabbat', $field->options, 'resolve() must never narrow - only narrow_for_creation() may' );
	}

	public function test_a_restricted_sects_existing_character_still_loads_and_resolves(): void {
		$character = Character::find( $this->sabbat_character_id );
		$this->assertNotNull( $character );

		$resolved = Creature_Stack::resolve( $character->stack_slug, $this->game_slug );
		$this->assertNotNull( $resolved, 'resolve() must never consult enabled_factions' );
	}

	public function test_find_disallowed_identity_value_flags_a_disallowed_sect(): void {
		$resolved = Creature_Stack::resolve( 'vampire', $this->game_slug );
		$sheet_data = [ 'vampire-identity' => [ 'Clan' => 'Tzimisce', 'Sect' => 'Sabbat' ] ];

		$disallowed = Creature_Stack::find_disallowed_identity_value( 'vampire', $sheet_data, $resolved['blocks'], $this->game_slug );

		$this->assertNotNull( $disallowed );
		$this->assertSame( 'Sect', $disallowed['field'] );
		$this->assertSame( 'Sabbat', $disallowed['value'] );
	}

	public function test_find_disallowed_identity_value_allows_a_permitted_sect(): void {
		$resolved = Creature_Stack::resolve( 'vampire', $this->game_slug );
		$sheet_data = [ 'vampire-identity' => [ 'Clan' => 'Tzimisce', 'Sect' => 'Camarilla' ] ];

		$disallowed = Creature_Stack::find_disallowed_identity_value( 'vampire', $sheet_data, $resolved['blocks'], $this->game_slug );

		$this->assertNull( $disallowed );
	}

	public function test_creating_a_character_with_a_disallowed_sect_is_rejected_with_400(): void {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'POST', '/be/v1/' . $this->game_slug . '/characters' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_param( 'name', 'Should Not Exist' );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'sheet_data', [ 'vampire-identity' => [ 'Clan' => 'Tzimisce', 'Sect' => 'Sabbat' ] ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_creating_a_character_with_an_allowed_sect_still_succeeds(): void {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'POST', '/be/v1/' . $this->game_slug . '/characters' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_param( 'name', 'A New Camarilla Vampire' );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'sheet_data', [ 'vampire-identity' => [ 'Clan' => 'Tzimisce', 'Sect' => 'Camarilla' ] ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_the_create_picker_narrows_sect_options_via_rest(): void {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/creature-stacks/vampire' );
		$request->set_param( 'resolve', true );
		$request->set_param( 'game_slug', $this->game_slug );
		$request->set_param( 'for_creation', true );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$field = $this->find_field( $data, 'vampire-identity', 'Sect' );

		$this->assertNotNull( $field );
		$this->assertNotContains( 'Sabbat', $field->options );
	}

	public function test_the_view_picker_does_not_narrow_without_for_creation(): void {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/creature-stacks/vampire' );
		$request->set_param( 'resolve', true );
		$request->set_param( 'game_slug', $this->game_slug );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$field = $this->find_field( $data, 'vampire-identity', 'Sect' );

		$this->assertNotNull( $field );
		$this->assertContains( 'Sabbat', $field->options, 'omitting for_creation must never narrow' );
	}

	/**
	 * @param array{stack:object,blocks:array<string,object>} $resolved
	 */
	private function find_field( array $resolved, string $block_slug, string $field_name ) {
		$block = $resolved['blocks'][ $block_slug ] ?? null;
		if ( ! $block ) {
			return null;
		}
		foreach ( $block->definition->fields ?? [] as $field ) {
			if ( $field->name === $field_name ) {
				return $field;
			}
		}
		return null;
	}
}
