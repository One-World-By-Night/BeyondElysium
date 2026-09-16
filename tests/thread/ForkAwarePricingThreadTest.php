<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Cost_Engine;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-013: a chronicle's own customization of a schema block (0.5e) is honored
 * everywhere except two lookups that read the global row. Cost_Engine's in-clan check loaded the
 * identity block for `in_type_source` from the global catalog, so a chronicle that forked
 * vampire-identity to add a bloodline and its in-clan Disciplines had that bloodline's purchases
 * priced - and approved - as out-of-clan.
 */
class ForkAwarePricingThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-fork-pricing';

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
	}

	public function test_a_chronicles_own_bloodline_prices_its_disciplines_in_clan(): void {
		$global = Schema_Block::find_by_slug( 'vampire-identity' );
		$this->assertNotNull( $global, 'The seeded catalog provides vampire-identity.' );

		// The chronicle forks vampire-identity and adds a bloodline whose in-clan Discipline is Celerity.
		$fork       = Schema_Block::find_or_create_fork_for_game( 'vampire-identity', $this->slug );
		$definition = json_decode( wp_json_encode( $fork->definition ), true );
		$definition['clan_disciplines']['Thread Bloodline'] = [ 'Celerity' ];
		Schema_Block::update( 'vampire-identity', [ 'definition' => $definition ], $this->slug );

		$character = (object) [
			'stack_slug'  => 'vampire',
			'owner_slug'  => $this->slug,
			'sheet_data'  => [ 'vampire-identity' => [ 'Clan' => 'Thread Bloodline' ] ],
		];

		$this->assertTrue( Cost_Engine::is_in_type( $character, 'vampire-disciplines', 'Celerity' ) );
		$this->assertFalse( Cost_Engine::is_in_type( $character, 'vampire-disciplines', 'Obfuscate' ) );
	}

	public function test_a_character_elsewhere_still_prices_against_the_global_catalog(): void {
		$character = (object) [
			'stack_slug' => 'vampire',
			'owner_slug' => $this->slug,
			'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Brujah' ] ],
		];

		$this->assertTrue( Cost_Engine::is_in_type( $character, 'vampire-disciplines', 'Celerity' ) );
	}
}
