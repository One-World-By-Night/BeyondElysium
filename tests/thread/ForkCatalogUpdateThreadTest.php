<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-034. A chronicle's copy of a catalog block - made the first time the chronicle
 * changes the block, or sets one approval rule on it - was frozen: no plugin update and no
 * administrator's catalog fix ever reached it again. A copy now keeps what the chronicle changed
 * and takes everything else from the catalog, at every update and every catalog save.
 */
class ForkCatalogUpdateThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-fork-catalog';
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id   = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Fork Catalog' ] );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );
	}

	private function send( int $user, string $method, string $route, array $body ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function definition( string $game_slug = '' ): array {
		return json_decode( wp_json_encode( Schema_Block::find_for_game( 'met-merits', $game_slug )->definition ), true );
	}

	/**
	 * @param array<string,mixed> $definition
	 * @return array<string,array<string,mixed>>
	 */
	private static function items( array $definition ): array {
		return array_column( $definition['items'], null, 'name' );
	}

	/** Writes a copy's definition as an old copy would have it, recording nothing. */
	private function age_copy( callable $change ): void {
		global $wpdb;
		$definition = $change( $this->definition( $this->slug ) );
		$wpdb->update(
			$wpdb->prefix . 'be_schema_blocks',
			[ 'definition' => wp_json_encode( $definition ) ],
			[ 'slug' => 'met-merits', 'game_slug' => $this->slug ]
		);
	}

	/**
	 * The chronicle changes one merit's cost and adds a merit of its own, through its own route.
	 *
	 * @return array{0:string,1:string,2:string} The changed merit, and two it never touched.
	 */
	private function chronicle_edits_merits(): array {
		$definition = $this->definition();
		[ $changed, $untouched, $other ] = array_column( array_slice( $definition['items'], 0, 3 ), 'name' );
		$definition['items'][0]['cost'] = '7';
		$definition['items'][]          = [ 'name' => 'Thread Chronicle Merit', 'cost' => '2' ];

		$response = $this->send( $this->hst, 'PUT', "/be/v1/{$this->slug}/schema-blocks/met-merits", [ 'definition' => $definition ] );
		$this->assertSame( 200, $response->get_status() );
		return [ $changed, $untouched, $other ];
	}

	public function test_a_plugin_update_reaches_a_chronicles_copy_and_keeps_what_it_changed(): void {
		[ $changed, $untouched, $other ] = $this->chronicle_edits_merits();

		// A catalog fix since the copy was made: one merit's note, and a merit the copy lacks.
		$this->age_copy( static function ( array $definition ) use ( $untouched, $other ) {
			foreach ( $definition['items'] as $i => $item ) {
				if ( $item['name'] === $untouched ) {
					$definition['items'][ $i ]['note'] = 'STALE';
				}
			}
			$definition['items'] = array_values( array_filter( $definition['items'], static fn( $item ) => $item['name'] !== $other ) );
			return $definition;
		} );

		// What a plugin version bump runs.
		Seeder::seed_schema_blocks();

		$catalog = self::items( $this->definition() );
		$copy    = self::items( $this->definition( $this->slug ) );
		$this->assertSame( '7', $copy[ $changed ]['cost'], "the chronicle's own cost" );
		$this->assertArrayHasKey( 'Thread Chronicle Merit', $copy );
		$this->assertSame( $catalog[ $untouched ]['note'] ?? null, $copy[ $untouched ]['note'] ?? null, 'the catalog fix reached the copy' );
		$this->assertArrayHasKey( $other, $copy, 'so did the merit the copy lacked' );
	}

	public function test_an_administrators_catalog_save_reaches_every_chronicles_copy(): void {
		[ , $untouched ] = $this->chronicle_edits_merits();

		$admin      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$definition = $this->definition();
		foreach ( $definition['items'] as $i => $item ) {
			if ( $item['name'] === $untouched ) {
				$definition['items'][ $i ]['description'] = [ 'description' => '<p>House rule for everyone.</p>' ];
			}
		}
		$this->assertSame( 200, $this->send( $admin, 'PUT', '/be/v1/schema-blocks/met-merits', [ 'definition' => $definition ] )->get_status() );

		$copy = self::items( $this->definition( $this->slug ) );
		$this->assertSame( '<p>House rule for everyone.</p>', $copy[ $untouched ]['description']['description'] ?? null );
		$this->assertArrayHasKey( 'Thread Chronicle Merit', $copy );
	}

	public function test_an_approval_rule_on_a_copy_survives_a_plugin_update(): void {
		$merit    = $this->definition()['items'][0]['name'];
		$response = $this->send( $this->hst, 'POST', "/be/v1/{$this->slug}/approval-rules", [
			'block_slug' => 'met-merits', 'target_type' => 'item', 'target_name' => $merit, 'approval' => 'st', 'reason' => 'Ask first',
		] );
		$this->assertSame( 201, $response->get_status() );

		Seeder::seed_schema_blocks();

		$copy = self::items( $this->definition( $this->slug ) );
		$this->assertSame( 'st', $copy[ $merit ]['approval'] ?? null );
		$this->assertSame( 'Ask first', $copy[ $merit ]['reason'] ?? null );
	}

	public function test_a_copy_made_before_copies_recorded_changes_keeps_its_differences(): void {
		// A copy as the old code left it: the whole definition, nothing recorded.
		$copy                     = $this->definition();
		$merit                    = $copy['items'][0]['name'];
		$copy['items'][0]['cost'] = '11';
		global $wpdb;
		$global = Schema_Block::find_by_slug( 'met-merits' );
		$wpdb->insert( $wpdb->prefix . 'be_schema_blocks', [
			'slug' => 'met-merits', 'game_slug' => $this->slug, 'name' => $global->name, 'section_type' => 'trait_list',
			'definition' => wp_json_encode( $copy ), 'is_system' => 0, 'storyteller_only' => 0, 'version' => 1,
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		Schema::record_fork_changes();
		Seeder::seed_schema_blocks();

		$this->assertSame( '11', self::items( $this->definition( $this->slug ) )[ $merit ]['cost'] );
	}
}
