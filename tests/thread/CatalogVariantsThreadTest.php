<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Catalog_Corrections;
use BeyondElysium\Services\Setup_Status;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle chooses book variants for a base block: its copy is the book with them folded in, characters keep
 * holding entries under the base block's slug, and the entries a choice would unmatch are listed first.
 */
class CatalogVariantsThreadTest extends WP_UnitTestCase {

	private string $slug  = 'thread-variants';
	private string $other = 'thread-variants-other';
	private int $game_id;
	private int $hst;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Variants by Night' ] );
		Game::create( [ 'slug' => $this->other, 'name' => 'Other by Night' ] );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
	}

	private function send( int $user, string $method, string $route, array $body = [] ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function choose( string $base, array $variants ): \WP_REST_Response {
		return $this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/catalog-variants", [ 'base' => $base, 'variants' => $variants ] );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function preview( string $base, array $variants ): array {
		$response = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/catalog-variants/preview", [ 'base' => $base, 'variants' => $variants ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['unmatched'];
	}

	/**
	 * @return string[]
	 */
	private static function families( string $slug, string $game_slug ): array {
		return array_column( json_decode( (string) wp_json_encode( Schema_Block::find_for_game( $slug, $game_slug )->definition ), true )['powers'], 'name' );
	}

	private function copy_count( string $slug ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = %s", $slug, $this->slug ) );
	}

	private function vampire( array $disciplines ): int {
		return (int) Character::create( [
			'name' => 'Variant Tester', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => [ 'vampire-disciplines' => $disciplines ],
		] );
	}

	public function test_choosing_an_add_variant_folds_its_families_into_the_chronicles_block_alone(): void {
		$response = $this->choose( 'vampire-disciplines', [ 'dark-ages' ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$own = self::families( 'vampire-disciplines', $this->slug );
		$this->assertContains( 'Animalism (Dark Ages)', $own );
		$this->assertContains( 'Animalism', $own, 'the book stays beneath' );
		$this->assertNotContains( 'Animalism (Dark Ages)', self::families( 'vampire-disciplines', $this->other ) );
		$this->assertNotContains( 'Animalism (Dark Ages)', self::families( 'vampire-disciplines', '' ) );

		$bases = array_column( $response->get_data()['bases'], null, 'base' );
		$this->assertSame( [ 'dark-ages' ], $bases['vampire-disciplines']['chosen'] );
		$this->assertSame( 'Vampire Disciplines', $bases['vampire-disciplines']['base_name'] );
		$this->assertSame( 'Kuei-Jin Disciplines', $bases['kueijin-disciplines']['base_name'], 'two blocks named alike are told apart' );
		$this->assertSame( 'Wraith Arcanoi', $bases['wraith-arcanoi']['base_name'], 'a name that already says its creature type stays as it is' );
	}

	public function test_a_purchase_from_a_chosen_variant_is_held_under_the_base_blocks_slug(): void {
		$character = $this->vampire( [] );
		Character::update_xp( $character, 30, 30 );
		$this->choose( 'vampire-disciplines', [ 'dark-ages' ] );

		$bought = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/characters/{$character}/changes", [
			'change_type' => 'add_trait', 'category' => 'test',
			'change_data' => [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Animalism (Dark Ages)', 'level' => 1 ] ],
		] );

		$this->assertSame( 201, $bought->get_status(), wp_json_encode( $bought->get_data() ) );
		$this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/changes/{$bought->get_data()->id}", [ 'status' => 'approved' ] );
		$this->assertContains( 'Animalism (Dark Ages)', array_column( Character::find( $character )->sheet_data['vampire-disciplines'], 'name' ) );
	}

	public function test_dropping_a_variant_lists_the_held_entries_it_would_unmatch_before_anything_changes(): void {
		$character = $this->vampire( [ [ 'name' => 'Animalism', 'level' => 2 ], [ 'name' => 'Animalism (Dark Ages)', 'level' => 1 ] ] );
		$this->choose( 'vampire-disciplines', [ 'dark-ages' ] );

		$unmatched = $this->preview( 'vampire-disciplines', [] );

		$this->assertSame(
			[ [ 'character_id' => $character, 'character' => 'Variant Tester', 'name' => 'Animalism (Dark Ages)', 'power_name' => null ] ],
			$unmatched
		);
		$this->assertContains( 'Animalism (Dark Ages)', self::families( 'vampire-disciplines', $this->slug ), 'a preview changes nothing' );

		$this->assertSame( 200, $this->choose( 'vampire-disciplines', [] )->get_status() );
		$this->assertSame( 0, $this->copy_count( 'vampire-disciplines' ), 'a copy with no variant and no change of its own goes' );
		$this->assertContains( 'Animalism (Dark Ages)', array_column( Character::find( $character )->sheet_data['vampire-disciplines'], 'name' ), 'what is held stays held' );
	}

	public function test_the_owbn_replacement_answers_to_the_base_spellings(): void {
		Character::create( [
			'name' => 'Spelling Tester', 'stack_slug' => 'wraith', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => [ 'wraith-arcanoi' => [
				[ 'name' => 'Argos', 'power_name' => 'Tempestpeek' ],
				[ 'name' => 'Castigate', 'power_name' => 'Housecleaning' ],
			] ],
		] );

		$this->assertSame( [], $this->preview( 'wraith-arcanoi', [ 'owbn' ] ) );

		$this->assertSame( 200, $this->choose( 'wraith-arcanoi', [ 'owbn' ] )->get_status() );
		$definition = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'wraith-arcanoi', $this->slug )->definition ), true );
		$castigate  = array_column( $definition['powers'], null, 'name' )['Castigate'];
		$this->assertSame( '7', array_column( $castigate['levels'], 'cost', 'power_name' )['House Cleaning'], "the packet's own cost" );
		$this->assertArrayNotHasKey( '_variant', $definition );
	}

	public function test_a_replacing_variant_goes_first_whatever_order_the_choice_names(): void {
		$variants                     = get_option( Schema_Block::VARIANTS_OPTION );
		$variants['wraith-arcanoi'][] = [ 'slug' => 'owbn-kueijin_disciplines', 'of' => 'wraith-arcanoi', 'id' => 'borrowed', 'label' => 'Borrowed', 'mode' => 'add' ];
		update_option( Schema_Block::VARIANTS_OPTION, $variants );
		$borrowed = array_column( json_decode( (string) wp_json_encode( Schema_Block::find_by_slug( 'owbn-kueijin_disciplines' )->definition ), true )['powers'], 'name' );

		$this->assertSame( 200, $this->choose( 'wraith-arcanoi', [ 'borrowed', 'owbn' ] )->get_status() );

		$families  = self::families( 'wraith-arcanoi', $this->slug );
		$castigate = array_column( json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'wraith-arcanoi', $this->slug )->definition ), true )['powers'], null, 'name' )['Castigate'];
		$this->assertContains( 'House Cleaning', array_column( $castigate['levels'], 'power_name' ), 'the replacement is beneath' );
		$this->assertContains( $borrowed[0], $families, 'and what the adding variant brings stays on top of it' );

		Character::create( [
			'name' => 'Order Tester', 'stack_slug' => 'wraith', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => [ 'wraith-arcanoi' => [ [ 'name' => $borrowed[0] ] ] ],
		] );
		$this->assertSame( [], $this->preview( 'wraith-arcanoi', [ 'borrowed', 'owbn' ] ), 'a preview puts the replacing variant first too' );
	}

	public function test_an_unknown_base_an_unknown_variant_and_two_replacements_are_refused(): void {
		$this->assertSame( 'unknown_base', $this->choose( 'vampire-merits', [ 'dark-ages' ] )->as_error()->get_error_code() );
		$this->assertSame( 'unknown_variant', $this->choose( 'vampire-disciplines', [ 'no-such-printing' ] )->as_error()->get_error_code() );

		$variants                   = get_option( Schema_Block::VARIANTS_OPTION );
		$variants['wraith-arcanoi'][] = [ 'slug' => 'owbn-wraith_arcanoi', 'of' => 'wraith-arcanoi', 'id' => 'second-packet', 'label' => 'Second', 'mode' => 'replace' ];
		update_option( Schema_Block::VARIANTS_OPTION, $variants );

		$two = $this->choose( 'wraith-arcanoi', [ 'owbn', 'second-packet' ] );
		$this->assertSame( 400, $two->get_status() );
		$this->assertSame( 'one_replacement', $two->as_error()->get_error_code() );
		$this->assertSame( 0, $this->copy_count( 'wraith-arcanoi' ) );
	}

	public function test_a_chronicles_own_change_stays_when_it_chooses_and_drops_a_variant(): void {
		$definition                             = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		$definition['_meta']['costs']['elder'] = 10;
		$this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/schema-blocks/vampire-disciplines", [ 'definition' => $definition ] );

		$this->choose( 'vampire-disciplines', [ 'dark-ages' ] );
		$chosen = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		$this->assertSame( 10, $chosen['_meta']['costs']['elder'] );
		$this->assertContains( 'Animalism (Dark Ages)', array_column( $chosen['powers'], 'name' ) );

		$this->choose( 'vampire-disciplines', [] );
		$dropped = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		$this->assertSame( 1, $this->copy_count( 'vampire-disciplines' ), 'the copy keeps its own change' );
		$this->assertSame( 10, $dropped['_meta']['costs']['elder'] );
		$this->assertNotContains( 'Animalism (Dark Ages)', array_column( $dropped['powers'], 'name' ) );
	}

	public function test_a_correction_to_a_chosen_variant_reaches_the_chronicle_and_is_flagged_under_its_change(): void {
		$this->choose( 'vampire-disciplines', [ 'dark-ages' ] );
		$definition = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		foreach ( $definition['powers'] as $p => $power ) {
			if ( $power['name'] === 'Animalism (Dark Ages)' ) {
				$definition['powers'][ $p ]['levels'][0]['cost'] = '4';
			}
		}
		$this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/schema-blocks/vampire-disciplines", [ 'definition' => $definition ] );

		global $wpdb;
		$variant = json_decode( (string) $wpdb->get_var( "SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = 'darkages-vampire_disciplines' AND game_slug = ''" ), true );
		foreach ( $variant['powers'] as $p => $power ) {
			if ( $power['name'] === 'Animalism (Dark Ages)' ) {
				$variant['powers'][ $p ]['levels'][0]['cost'] = '5';
				$variant['powers'][ $p ]['levels'][2]['cost'] = '7';
			}
		}
		Schema_Block::update( 'darkages-vampire_disciplines', [ 'definition' => $variant ] );
		Schema_Block::refresh_forks( 'vampire-disciplines' );

		$flags = Catalog_Corrections::for_game( Game::find( $this->game_id ) );
		$this->assertCount( 1, $flags );
		$this->assertSame( [ 'powers', [ 'Animalism (Dark Ages)' ], 'levels' ], array_slice( $flags[0]['path'], 0, 3 ) );
		$this->assertSame( [ '3', '5', '4' ], [ $flags[0]['was'], $flags[0]['now'], $flags[0]['yours'] ] );

		$own = array_column( json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true )['powers'], null, 'name' );
		$this->assertSame( '7', array_column( $own['Animalism (Dark Ages)']['levels'], 'cost', 'power_name' )['Cowing the Beast'], 'a correction the chronicle left alone reaches it' );
		$this->assertArrayHasKey( 'Auspex (Dark Ages)', $own, 'the rebuilt copy keeps the rest of the variant' );
	}

	public function test_an_entry_held_by_an_alias_of_a_variant_family_is_listed_when_the_variant_goes(): void {
		$variants                     = get_option( Schema_Block::VARIANTS_OPTION );
		$variants['wraith-arcanoi'][] = [ 'slug' => 'darkages-vampire_blood-magic', 'of' => 'wraith-arcanoi', 'id' => 'borrowed', 'label' => 'Borrowed', 'mode' => 'add' ];
		update_option( Schema_Block::VARIANTS_OPTION, $variants );
		$character = (int) Character::create( [
			'name' => 'Alias Tester', 'stack_slug' => 'wraith', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => [ 'wraith-arcanoi' => [ [ 'name' => 'Elemental Mastery' ] ] ],
		] );
		$this->choose( 'wraith-arcanoi', [ 'borrowed' ] );

		$this->assertSame(
			[ [ 'character_id' => $character, 'character' => 'Alias Tester', 'name' => 'Elemental Mastery', 'power_name' => null ] ],
			$this->preview( 'wraith-arcanoi', [] )
		);
	}

	public function test_the_setup_row_names_the_chosen_variants(): void {
		$rows = array_column( Setup_Status::rows( Game::find( $this->game_id ) ), null, 'id' );
		$this->assertSame( 'info', $rows['book_variants']['status'] );

		$this->choose( 'vampire-disciplines', [ 'dark-ages' ] );

		$rows = array_column( Setup_Status::rows( Game::find( $this->game_id ) ), null, 'id' );
		$this->assertSame( 'ok', $rows['book_variants']['status'] );
		$this->assertStringContainsString( 'Vampire Disciplines: Dark Ages', $rows['book_variants']['detail'] );
	}

	public function test_a_player_cannot_choose_variants(): void {
		$response = $this->send( $this->player, 'PUT', "/be/v1/{$this->slug}/catalog-variants", [ 'base' => 'vampire-disciplines', 'variants' => [ 'dark-ages' ] ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->copy_count( 'vampire-disciplines' ) );
	}
}
