<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Fork_Merge;
use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle's change to a catalog block is recorded where it sits, over the catalog's value there: a later catalog
 * correction beside it reaches the chronicle's copy, and one beneath it is flagged while the chronicle's value stays.
 */
class ForkChangeRecordThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-fork-record';
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id   = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Fork Record' ] );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );
	}

	/**
	 * The stored definition of the Disciplines block: the catalog's, or a chronicle's copy.
	 *
	 * @return array<string,mixed>
	 */
	private function stored( string $game_slug = '' ): array {
		global $wpdb;
		return json_decode( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = 'vampire-disciplines' AND game_slug = %s",
			$game_slug
		) ), true );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function recorded(): array {
		global $wpdb;
		return json_decode( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT fork_changes FROM {$wpdb->prefix}be_schema_blocks WHERE slug = 'vampire-disciplines' AND game_slug = %s",
			$this->slug
		) ), true );
	}

	/**
	 * The chronicle saves its copy through its own route.
	 */
	private function chronicle_saves( callable $change ): void {
		$definition = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/schema-blocks/vampire-disciplines" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'definition' => $change( $definition ) ] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * What a plugin update runs when the catalog file changes.
	 */
	private function catalog_changes( callable $change ): void {
		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $change( $this->stored() ) ] );
		Schema_Block::refresh_forks( 'vampire-disciplines' );
	}

	/**
	 * @param array<string,mixed> $definition
	 * @return array<string,string>
	 */
	private static function animalism_master_costs( array $definition ): array {
		$animalism = array_column( $definition['powers'], null, 'name' )['Animalism'];
		return array_column( $animalism['elder']['master'], 'cost', 'power_name' );
	}

	public function test_a_chronicles_cost_is_recorded_where_it_sits_over_the_catalogs_value(): void {
		$this->assertSame( 12, $this->stored()['_meta']['costs']['elder'] );

		$this->chronicle_saves( static function ( array $definition ): array {
			$definition['_meta']['costs']['elder'] = 10;
			return $definition;
		} );

		$this->assertSame( [ [ 'path' => [ '_meta', 'costs', 'elder' ], 'under' => 12 ] ], $this->recorded()['changes'] );
	}

	public function test_a_catalog_correction_beside_the_chronicles_cost_reaches_its_copy_unflagged(): void {
		$this->chronicle_saves( static function ( array $definition ): array {
			$definition['_meta']['costs']['elder'] = 10;
			return $definition;
		} );

		$this->catalog_changes( static function ( array $definition ): array {
			$definition['_meta']['costs']['master'] = 16;
			return $definition;
		} );

		$costs = $this->stored( $this->slug )['_meta']['costs'];
		$this->assertSame( 10, $costs['elder'], "the chronicle's own cost" );
		$this->assertSame( 16, $costs['master'], 'the correction it never touched' );
		$this->assertSame( [], Fork_Merge::flags( $this->stored(), $this->stored( $this->slug ), $this->recorded() ) );
	}

	public function test_a_catalog_correction_beneath_the_chronicles_cost_is_flagged_and_the_chronicles_cost_kept(): void {
		$this->chronicle_saves( static function ( array $definition ): array {
			$definition['_meta']['costs']['elder'] = 10;
			return $definition;
		} );

		$this->catalog_changes( static function ( array $definition ): array {
			$definition['_meta']['costs']['elder'] = 15;
			return $definition;
		} );

		$this->assertSame( 10, $this->stored( $this->slug )['_meta']['costs']['elder'] );
		$flags = Fork_Merge::flags( $this->stored(), $this->stored( $this->slug ), $this->recorded() );
		$this->assertSame( [ [ '_meta', 'costs', 'elder' ] ], array_column( $flags, 'path' ) );
		$this->assertSame( [ 12, 15, 10 ], [ $flags[0]['was'], $flags[0]['now'], $flags[0]['yours'] ] );
	}

	public function test_editing_a_flagged_cost_through_the_chronicles_route_clears_its_flag(): void {
		$this->chronicle_saves( static function ( array $definition ): array {
			$definition['_meta']['costs']['elder'] = 10;
			return $definition;
		} );
		$this->catalog_changes( static function ( array $definition ): array {
			$definition['_meta']['costs']['elder'] = 15;
			return $definition;
		} );

		$this->chronicle_saves( static function ( array $definition ): array {
			$definition['_meta']['costs']['elder'] = 11;
			return $definition;
		} );

		$this->assertSame( [ [ 'path' => [ '_meta', 'costs', 'elder' ], 'under' => 15 ] ], $this->recorded()['changes'] );
		$this->assertSame( [], Fork_Merge::flags( $this->stored(), $this->stored( $this->slug ), $this->recorded() ) );
	}

	public function test_a_chronicles_change_to_one_elder_pick_leaves_the_familys_other_picks_to_the_catalog(): void {
		$this->assertSame( [ 'Conquer the Beast' => '15', 'Stampede' => '15' ], self::animalism_master_costs( $this->stored() ) );

		$this->chronicle_saves( static function ( array $definition ): array {
			foreach ( $definition['powers'] as $p => $power ) {
				if ( $power['name'] === 'Animalism' ) {
					$definition['powers'][ $p ]['elder']['master'][1]['cost'] = '10';
				}
			}
			return $definition;
		} );
		$this->assertSame(
			[ [ 'path' => [ 'powers', [ 'Animalism' ], 'elder', 'master', [ 'Stampede' ], 'cost' ], 'under' => '15' ] ],
			$this->recorded()['changes']
		);

		$this->catalog_changes( static function ( array $definition ): array {
			foreach ( $definition['powers'] as $p => $power ) {
				if ( $power['name'] === 'Animalism' ) {
					$definition['powers'][ $p ]['elder']['master'][0]['cost'] = '16';
				}
			}
			return $definition;
		} );

		$this->assertSame( [ 'Conquer the Beast' => '16', 'Stampede' => '10' ], self::animalism_master_costs( $this->stored( $this->slug ) ) );
	}

	public function test_the_upgrade_records_by_path_a_copy_whose_changes_were_recorded_by_whole_key(): void {
		global $wpdb;
		$copy                            = $this->stored();
		$copy['_meta']['costs']['elder'] = 10;
		$wpdb->insert( $wpdb->prefix . 'be_schema_blocks', [
			'slug'             => 'vampire-disciplines',
			'game_slug'        => $this->slug,
			'name'             => Schema_Block::find_by_slug( 'vampire-disciplines' )->name,
			'section_type'     => 'tiered_power',
			'definition'       => wp_json_encode( $copy ),
			'fork_changes'     => wp_json_encode( [ 'keys' => [ '_meta' ], 'lists' => [], 'removed' => [] ] ),
			'is_system'        => 0,
			'storyteller_only' => 0,
			'version'          => 1,
			'created_by'       => 1,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		] );

		Schema::record_fork_changes();

		$this->assertSame( [ [ 'path' => [ '_meta', 'costs', 'elder' ], 'under' => 12 ] ], $this->recorded()['changes'] );
		$this->catalog_changes( static function ( array $definition ): array {
			$definition['_meta']['costs']['master'] = 16;
			return $definition;
		} );
		$this->assertSame( 16, $this->stored( $this->slug )['_meta']['costs']['master'] );
	}
}
