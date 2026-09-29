<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Point_Audit;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The cost expressions the book declares, priced through the real routes and the real seeded catalog: a chronicle's
 * per-rank modifier, a rote's cost from its Spheres, Balance priced per level, and a held Realm in the audit.
 */
class CostEvaluatorsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-evaluators';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Evaluators by Night' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
	}

	private function send( string $method, string $route, array $body = [] ): \WP_REST_Response {
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function character( string $stack, array $sheet ): int {
		$id = (int) Character::create( [
			'name' => 'Evaluator ' . $stack, 'stack_slug' => $stack, 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => $sheet,
		] );
		Character::update_xp( $id, 100, 100 );
		return $id;
	}

	/**
	 * What a submitted change was priced at.
	 */
	private function priced( int $character, string $change_type, array $change_data ): int {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/characters/{$character}/changes", [
			'change_type' => $change_type, 'category' => 'test', 'change_data' => $change_data,
		] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return (int) $response->get_data()->xp_cost;
	}

	/**
	 * Sets this chronicle's out-of-type modifier for Disciplines' basic rank.
	 */
	private function house_rule_basic_out_of_type( string $expression ): void {
		$definition = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		$definition['_meta']['out_of_type']['basic'] = $expression;
		$response = $this->send( 'PUT', "/be/v1/{$this->slug}/schema-blocks/vampire-disciplines", [ 'definition' => $definition ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	public function test_the_book_declares_disciplines_modifiers_per_rank_and_no_flat_one(): void {
		$definition = Schema_Block::find_by_slug( 'vampire-disciplines' )->definition;

		$this->assertSame( '+1', $definition->_meta->out_of_type->basic );
		$this->assertObjectNotHasProperty( 'out_of_type_cost_modifier', $definition );
	}

	public function test_a_chronicles_per_rank_modifier_prices_an_out_of_clan_purchase_and_leaves_in_clan_alone(): void {
		$this->house_rule_basic_out_of_type( '+2' );
		$brujah = $this->character( 'vampire', [ 'vampire-identity' => [ 'Clan' => 'Brujah' ] ] );

		$this->assertSame( 5, $this->priced( $brujah, 'add_trait', [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Obfuscate', 'level' => 1 ] ] ), '3 and the chronicle\'s +2' );
		$this->assertSame( 3, $this->priced( $brujah, 'add_trait', [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 1 ] ] ) );
	}

	public function test_the_audit_names_how_much_a_modifier_added_and_on_which_side(): void {
		$this->house_rule_basic_out_of_type( '+2' );
		$brujah = $this->character( 'vampire', [
			'vampire-identity'    => [ 'Clan' => 'Brujah' ],
			'vampire-disciplines' => [ [ 'name' => 'Obfuscate', 'level' => 2 ], [ 'name' => 'Celerity', 'level' => 2 ] ],
		] );

		$lines = array_column( array_filter( Point_Audit::for_character( $brujah )['lines'], static fn( $l ) => $l['block_slug'] === 'vampire-disciplines' ), null, 'label' );

		$this->assertSame( 10, $lines['Obfuscate 2']['xp'], '(3+2) and (3+2)' );
		$this->assertSame( 4, $lines['Obfuscate 2']['modifier'] );
		$this->assertSame( 'out_of_type', $lines['Obfuscate 2']['modifier_side'] );
		$this->assertSame( 6, $lines['Celerity 2']['xp'] );
		$this->assertNull( $lines['Celerity 2']['modifier'] );
	}

	public function test_a_rote_costs_one_for_each_sphere_level_it_uses(): void {
		$mage = $this->character( 'mage', [] );

		$this->assertSame( 4, $this->priced( $mage, 'add_trait', [ 'block_slug' => 'mage-rotes', 'trait' => [ 'name' => 'Access This', 'count' => 1 ] ] ), 'Correspondence Initiate and Forces Initiate' );
		$this->assertSame( 4, $this->priced( $mage, 'add_trait', [ 'block_slug' => 'mage-rotes', 'trait' => [ 'name' => '108 Plum Blossoms', 'count' => 1 ] ] ), 'Forces 2 and Correspondence 2' );
	}

	public function test_raising_balance_costs_the_level_it_reaches(): void {
		$mummy = $this->character( 'mummy', [ 'mummy-resources' => [ 'Balance' => [ 'permanent' => 5, 'temporary' => 5 ] ] ] );

		$this->assertSame( 13, $this->priced( $mummy, 'modify_resource', [ 'block_slug' => 'mummy-resources', 'values' => [ 'Balance' => [ 'permanent' => 7, 'temporary' => 7 ] ] ] ), '6 and 7' );
	}

	public function test_a_held_realm_is_priced_in_the_audit(): void {
		$changeling = $this->character( 'changeling', [ 'changeling-realms' => [ [ 'name' => 'Actor', 'level' => 3 ] ] ] );

		$lines = array_column( array_filter( Point_Audit::for_character( $changeling )['lines'], static fn( $l ) => $l['block_slug'] === 'changeling-realms' ), null, 'label' );

		$this->assertSame( 6, $lines['Actor 3']['xp'] );
		$this->assertNull( $lines['Actor 3']['unpriced_reason'] );
	}
}
