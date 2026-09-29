<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Setup_Status;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle's book corrections: listed under its Setup row, each kept or taken, no XP moved either way.
 */
class CatalogCorrectionsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-corrections';
	private int $game_id;
	private int $hst;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Corrections by Night' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
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

	/**
	 * @return array<string,mixed>
	 */
	private static function stored( string $slug, string $game_slug = '' ): array {
		global $wpdb;
		return json_decode( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = %s",
			$slug,
			$game_slug
		) ), true );
	}

	/**
	 * The chronicle's Elder cost of 10, and the book correcting its 12 to 15.
	 */
	private function house_rule_then_correction(): void {
		$definition                             = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		$definition['_meta']['costs']['elder'] = 10;
		$this->assertSame( 200, $this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/schema-blocks/vampire-disciplines", [ 'definition' => $definition ] )->get_status() );

		$book                             = self::stored( 'vampire-disciplines' );
		$book['_meta']['costs']['elder'] = 15;
		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $book ] );
		Schema_Block::refresh_forks( 'vampire-disciplines' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function listed(): array {
		$response = $this->send( $this->hst, 'GET', "/be/v1/{$this->slug}/catalog-corrections" );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data()['corrections'];
	}

	private function setup_row(): array {
		foreach ( Setup_Status::rows( Game::find( $this->game_id ) ) as $row ) {
			if ( $row['id'] === 'catalog_customisation' ) {
				return $row;
			}
		}
		$this->fail( 'no catalog row' );
	}

	public function test_a_correction_under_a_chronicles_change_is_listed_and_counted_on_its_setup_row(): void {
		$this->house_rule_then_correction();

		$listed = $this->listed();
		$this->assertCount( 1, $listed );
		$this->assertSame( [ 'block', 'vampire-disciplines', [ '_meta', 'costs', 'elder' ] ], [ $listed[0]['kind'], $listed[0]['target'], $listed[0]['path'] ] );
		$this->assertSame( [ 12, 15, 10 ], [ $listed[0]['was'], $listed[0]['now'], $listed[0]['yours'] ] );
		$this->assertSame( 'Vampire Disciplines', $listed[0]['target_name'] );

		$row = $this->setup_row();
		$this->assertSame( 'attention', $row['status'] );
		$this->assertSame( '1 book correction to review.', $row['detail'] );
	}

	public function test_keep_mine_clears_the_correction_and_keeps_the_chronicles_value(): void {
		$this->house_rule_then_correction();

		$response = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/catalog-corrections/keep", [
			'kind' => 'block', 'target' => 'vampire-disciplines', 'path' => [ '_meta', 'costs', 'elder' ],
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['count'] );
		$this->assertSame( 10, self::stored( 'vampire-disciplines', $this->slug )['_meta']['costs']['elder'] );
		$this->assertNotSame( 'attention', $this->setup_row()['status'] );
		Schema_Block::refresh_forks( 'vampire-disciplines' );
		$this->assertSame( [], $this->listed(), 'a rebuild raises it no more' );
	}

	public function test_use_the_books_takes_the_books_value_and_moves_no_xp(): void {
		$character = (int) Character::create( [ 'name' => 'Priced Once', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		Character::update_xp( $character, 80, 30 );
		$this->house_rule_then_correction();

		$response = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/catalog-corrections/take", [
			'kind' => 'block', 'target' => 'vampire-disciplines', 'path' => [ '_meta', 'costs', 'elder' ],
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['corrections'] );
		$this->assertSame( 15, self::stored( 'vampire-disciplines', $this->slug )['_meta']['costs']['elder'] );
		$after = Character::find( $character );
		$this->assertSame( [ 80, 30 ], [ (int) $after->xp_earned, (int) $after->xp_unspent ], 'forward only: no XP moves' );
	}

	public function test_keep_all_mine_clears_corrections_across_blocks_creature_types_and_templates(): void {
		$this->house_rule_then_correction();

		Creature_Stack::update_for_game( 'vampire', $this->slug, [ 'stack_definition' => self::relabelled_stack( 'Our Rituals' ) ] );
		Creature_Stack::update( 'vampire', [ 'stack_definition' => self::relabelled_stack( 'Rituals and Ritae' ) ] );
		Creature_Stack::refresh_layers( 'vampire' );

		$site   = Template::resolve( 'vampire', 'sheet_full', null );
		$widths = array_values( array_diff( [ 'full', 'half', 'third' ], [ $site->layout['sections'][0]['width'] ?? null ] ) );
		$mine   = $site->layout;
		$mine['sections'][0]['width'] = $widths[0];
		$own_id = Template::create( [ 'game_id' => $this->game_id, 'stack_slug' => 'vampire', 'name' => 'Our Sheet', 'template_type' => 'sheet_full', 'layout' => $mine ] );
		$book   = $site->layout;
		$book['sections'][0]['width'] = $widths[1];
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_templates', [ 'layout' => wp_json_encode( $book ) ], [ 'id' => (int) $site->id ] );

		$kinds = array_column( $this->listed(), 'kind' );
		sort( $kinds );
		$this->assertSame( [ 'block', 'stack', 'template' ], $kinds );

		$response = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/catalog-corrections/keep", [ 'all' => true ] );

		$this->assertSame( 0, $response->get_data()['count'] );
		$this->assertSame( 10, self::stored( 'vampire-disciplines', $this->slug )['_meta']['costs']['elder'] );
		$labels = array_column( (array) Creature_Stack::find_for_game( 'vampire', $this->slug )->stack_definition->sections, 'label', 'block_slug' );
		$this->assertSame( 'Our Rituals', $labels['vampire-rituals'] );
		$this->assertSame( $widths[0], Template::find( $own_id )->layout['sections'][0]['width'] );
	}

	public function test_a_book_that_removes_an_entry_the_chronicle_changed_is_one_correction_on_the_entry(): void {
		$definition = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		foreach ( $definition['powers'] as $p => $power ) {
			if ( $power['name'] === 'Animalism' ) {
				foreach ( $power['elder']['master'] as $i => $pick ) {
					if ( $pick['power_name'] === 'Stampede' ) {
						$definition['powers'][ $p ]['elder']['master'][ $i ]['cost'] = '10';
					}
				}
			}
		}
		$this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/schema-blocks/vampire-disciplines", [ 'definition' => $definition ] );

		$book = self::stored( 'vampire-disciplines' );
		foreach ( $book['powers'] as $p => $power ) {
			if ( $power['name'] === 'Animalism' ) {
				$book['powers'][ $p ]['elder']['master'] = array_values( array_filter( $power['elder']['master'], static fn( $pick ) => $pick['power_name'] !== 'Stampede' ) );
			}
		}
		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $book ] );
		Schema_Block::refresh_forks( 'vampire-disciplines' );

		$listed = $this->listed();
		$this->assertCount( 1, $listed );
		$this->assertTrue( $listed[0]['removed'] );
		$this->assertSame( [ null, 'Animalism', null, null, 'Stampede' ], $listed[0]['labels'] );
		$this->assertSame( '10', $listed[0]['yours']['cost'] );
	}

	public function test_a_player_cannot_reach_the_corrections(): void {
		$this->house_rule_then_correction();

		$this->assertSame( 403, $this->send( $this->player, 'GET', "/be/v1/{$this->slug}/catalog-corrections" )->get_status() );
		$this->assertSame( 403, $this->send( $this->player, 'POST', "/be/v1/{$this->slug}/catalog-corrections/keep", [ 'all' => true ] )->get_status() );
		$this->assertCount( 1, $this->listed() );
	}

	public function test_a_path_the_chronicle_has_no_layer_for_is_refused(): void {
		$missing = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/catalog-corrections/take", [
			'kind' => 'block', 'target' => 'vampire-rituals', 'path' => [ 'items', [ 'Anything' ] ],
		] );
		$this->assertSame( 404, $missing->get_status() );

		$malformed = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/catalog-corrections/take", [
			'kind' => 'block', 'target' => 'vampire-disciplines', 'path' => [ [ 'a', 'b' ] ],
		] );
		$this->assertSame( 400, $malformed->get_status() );
	}

	/**
	 * The book's Vampire sections with the Rituals section's label changed.
	 *
	 * @return array<string,mixed>
	 */
	private static function relabelled_stack( string $label ): array {
		$definition = json_decode( (string) wp_json_encode( Creature_Stack::find_by_slug( 'vampire' )->stack_definition ), true );
		foreach ( $definition['sections'] as $i => $section ) {
			if ( $section['block_slug'] === 'vampire-rituals' ) {
				$definition['sections'][ $i ]['label'] = $label;
			}
		}
		return $definition;
	}
}
