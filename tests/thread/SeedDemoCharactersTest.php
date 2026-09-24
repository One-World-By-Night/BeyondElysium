<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * "Port ALL 22 test characters into the DEFAULT instance to demonstrate that it WORKS... make it part of our core
 * install".
 */
class SeedDemoCharactersTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_characters WHERE owner_slug = 'be-demo'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_games WHERE slug = 'be-demo'" );
		delete_option( Seeder::DEMO_SEEDED_OPTION );
	}

	public function test_creates_the_demo_game_if_it_does_not_exist(): void {
		$this->assertNull( Game::find_by_slug( 'be-demo' ) );

		Seeder::seed_demo_characters( true );

		$game = Game::find_by_slug( 'be-demo' );
		$this->assertNotNull( $game );
		$this->assertSame( 'Beyond Elysium Demo', $game->name );
	}

	public function test_creates_all_22_characters_across_all_11_stacks(): void {
		Seeder::seed_demo_characters( true );

		global $wpdb;
		$table = $wpdb->prefix . 'be_characters';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_slug = %s", 'be-demo' ) );
		$this->assertSame( 22, $count );

		$stacks = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT stack_slug FROM {$table} WHERE owner_slug = %s", 'be-demo' ) );
		sort( $stacks );
		$this->assertSame(
			[ 'bete', 'changeling', 'demon', 'fera', 'kueijin', 'mage', 'mortal', 'mummy', 'vampire', 'werewolf', 'wraith' ],
			$stacks
		);
	}

	public function test_a_real_character_has_its_full_sheet_data_and_xp(): void {
		Seeder::seed_demo_characters( true );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_characters WHERE name = %s AND owner_slug = %s",
			'Isolde Marchetti', 'be-demo'
		) );

		$this->assertNotNull( $row );
		$this->assertSame( 90, (int) $row->xp_earned );
		$this->assertSame( 10, (int) $row->xp_unspent );
		$sheet = json_decode( $row->sheet_data, true );
		$this->assertSame( 'Tremere', $sheet['vampire-identity']['Clan'] );
	}

	/**
	 * Every demo trait is a real catalog name in its own block - a Gift by its plain name, a Blood Magic path under Blood
	 * Magic - and every identity value is one its select offers.
	 */
	public function test_every_demo_trait_and_identity_value_is_a_real_catalog_entry(): void {
		$problems = [];
		foreach ( require BE_PLUGIN_PATH . '/includes/Database/demo-characters.php' as $fixture ) {
			foreach ( $fixture['sheet_data'] as $slug => $held ) {
				$block = Schema_Block::find_by_slug( $slug );
				if ( ! $block ) {
					$problems[] = "{$fixture['name']}: no block {$slug}";
					continue;
				}
				$definition = $block->definition;
				if ( $block->section_type === 'trait_list' || $block->section_type === 'tiered_power' ) {
					$names = array_column( json_decode( wp_json_encode( $definition->items ?? $definition->powers ?? [] ), true ), 'name' );
					foreach ( $held as $entry ) {
						if ( ! in_array( $entry['name'], $names, true ) ) {
							$problems[] = "{$fixture['name']}: {$slug} has no {$entry['name']}";
						}
					}
				} elseif ( $block->section_type === 'identity_field' ) {
					foreach ( (array) $definition->fields as $field ) {
						$value = $held[ $field->name ] ?? null;
						if ( $value !== null && $field->field_type === 'select' && empty( $field->allow_custom ) && ! empty( $field->options ) && ! in_array( $value, (array) $field->options, true ) ) {
							$problems[] = "{$fixture['name']}: {$slug} {$field->name} offers no {$value}";
						}
					}
				}
			}
		}

		$this->assertSame( [], $problems );
	}

	public function test_running_it_twice_does_not_create_duplicates(): void {
		Seeder::seed_demo_characters( true );
		Seeder::seed_demo_characters( true );

		global $wpdb;
		$table = $wpdb->prefix . 'be_characters';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_slug = %s", 'be-demo' ) );
		$this->assertSame( 22, $count );
	}

	public function test_never_touches_a_real_chronicles_own_game(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-real-chronicle', 'name' => 'A Real Chronicle',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		Seeder::seed_demo_characters( true );

		$count = Character::count_for_game( 'thread-test-real-chronicle' );
		$this->assertSame( 0, $count, 'Demo characters must only ever land in be-demo, never an existing real game.' );
	}
}
