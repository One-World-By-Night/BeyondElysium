<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-011. Owner ruling 2026-09-14: an update refreshes only what the seed data owns
 * (names, costs, notes, translations); admin-set approvals, reasons, schedules, descriptions, and
 * admin-added entries survive every update.
 *
 * Every version bump reseeds the shared catalog, and the reseed replaced each system block's
 * whole definition and each system creature stack wholesale - only item and power descriptions
 * were carried forward. An administrator's approval rules, value schedules, and added entries
 * disappeared at the next plugin update.
 */
class CatalogUpdateKeepsAdminEditsThreadTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function put( string $route, array $body ) {
		$request = new WP_REST_Request( 'PUT', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function definition( string $slug ): array {
		return json_decode( wp_json_encode( Schema_Block::find_by_slug( $slug )->definition ), true );
	}

	private static function named( array $entries, string $name, string $key = 'name' ): ?array {
		foreach ( $entries as $entry ) {
			if ( ( $entry[ $key ] ?? null ) === $name ) {
				return $entry;
			}
		}
		return null;
	}

	public function test_an_update_keeps_every_admin_edit_to_system_blocks_and_still_refreshes_source_data(): void {
		// trait_list: an approval rule, a reason, a value schedule, and a cost edit on one merit; a merit
		// the admin added; a block-wide rule.
		$merits      = $this->definition( 'met-merits' );
		$merit       = $merits['items'][0]['name'];
		$source_cost = $merits['items'][0]['cost'] ?? null;
		$merits['items'][0]['approval']          = 'st';
		$merits['items'][0]['reason']            = 'Chronicle house rule';
		$merits['items'][0]['approval_by_value'] = [ [ 'from' => 2, 'to' => 5, 'approval' => 'st' ] ];
		$merits['items'][0]['cost']              = '99';
		$merits['items'][]                       = [ 'name' => 'Thread House Merit', 'cost' => '2' ];
		$merits['approval_rules']                = [ 'default' => 'st' ];
		$this->assertSame( 200, $this->put( '/be/v1/schema-blocks/met-merits', [ 'definition' => $merits ] )->get_status() );

		// tiered_power: a family override, a level's approval and reason, and a level the admin added.
		$disciplines = $this->definition( 'vampire-disciplines' );
		foreach ( $disciplines['powers'] as &$power ) {
			if ( $power['name'] === 'Celerity' ) {
				$power['approval_override'] = 'st';
				foreach ( $power['levels'] as &$level ) {
					if ( $level['power_name'] === 'Alacrity' ) {
						$level['approval'] = 'st';
						$level['reason']   = 'Speed needs a word first';
					}
				}
				unset( $level );
				$power['levels'][] = [ 'power_name' => 'Thread House Speed', 'level' => null, 'tier' => 'elder', 'cost' => '12' ];
			}
		}
		unset( $power );
		$this->assertSame( 200, $this->put( '/be/v1/schema-blocks/vampire-disciplines', [ 'definition' => $disciplines ] )->get_status() );

		// resource_pool and identity_field schedules.
		$resources = $this->definition( 'vampire-resources' );
		foreach ( $resources['pools'] as &$pool ) {
			if ( $pool['name'] === 'Willpower' ) {
				$pool['approval_by_value'] = [ [ 'from' => 8, 'to' => 10, 'approval' => 'st' ] ];
			}
		}
		unset( $pool );
		$this->assertSame( 200, $this->put( '/be/v1/schema-blocks/vampire-resources', [ 'definition' => $resources ] )->get_status() );

		$identity = $this->definition( 'vampire-identity' );
		foreach ( $identity['fields'] as &$field ) {
			if ( $field['name'] === 'Clan' ) {
				$field['approval_by_option'] = [ 'Baali' => [ 'approval' => 'st', 'reason' => 'Bloodline needs approval' ] ];
			}
		}
		unset( $field );
		$this->assertSame( 200, $this->put( '/be/v1/schema-blocks/vampire-identity', [ 'definition' => $identity ] )->get_status() );

		// What a plugin version bump runs.
		Seeder::seed_schema_blocks();

		$merits = $this->definition( 'met-merits' );
		$kept   = self::named( $merits['items'], $merit );
		$this->assertSame( 'st', $kept['approval'] ?? null );
		$this->assertSame( 'Chronicle house rule', $kept['reason'] ?? null );
		// assertEquals: the JSON column stores object keys in its own order.
		$this->assertEquals( [ [ 'from' => 2, 'to' => 5, 'approval' => 'st' ] ], $kept['approval_by_value'] ?? null );
		$this->assertSame( $source_cost, $kept['cost'] ?? null, 'a cost is the seed data\'s - the update still refreshes it' );
		$this->assertNotNull( self::named( $merits['items'], 'Thread House Merit' ), 'an entry the admin added survives' );
		$this->assertEquals( [ 'default' => 'st' ], $merits['approval_rules'] ?? null );

		$celerity = self::named( $this->definition( 'vampire-disciplines' )['powers'], 'Celerity' );
		$this->assertSame( 'st', $celerity['approval_override'] ?? null );
		$alacrity = self::named( $celerity['levels'], 'Alacrity', 'power_name' );
		$this->assertSame( 'st', $alacrity['approval'] ?? null );
		$this->assertSame( 'Speed needs a word first', $alacrity['reason'] ?? null );
		$this->assertNotNull( self::named( $celerity['levels'], 'Thread House Speed', 'power_name' ) );

		$willpower = self::named( $this->definition( 'vampire-resources' )['pools'], 'Willpower' );
		$this->assertEquals( [ [ 'from' => 8, 'to' => 10, 'approval' => 'st' ] ], $willpower['approval_by_value'] ?? null );

		$clan = self::named( $this->definition( 'vampire-identity' )['fields'], 'Clan' );
		$this->assertSame( 'Bloodline needs approval', $clan['approval_by_option']['Baali']['reason'] ?? null );
	}

	public function test_an_entry_the_seed_data_dropped_goes_unless_an_admin_added_it(): void {
		$method = new \ReflectionMethod( Seeder::class, 'preserve_admin_edits' );
		$method->setAccessible( true );

		$old = json_decode( wp_json_encode( [
			'items' => [ [ 'name' => 'Retired Source Item', 'approval' => 'st' ], [ 'name' => 'Admin Item', 'admin_added' => true ] ],
		] ) );
		$new = [ 'section_type' => 'trait_list', 'definition' => [ 'items' => [ [ 'name' => 'Current Source Item' ] ] ] ];

		$result = $method->invoke( null, $new, $old );

		$this->assertSame( [ 'Current Source Item', 'Admin Item' ], array_column( $result['definition']['items'], 'name' ) );
	}

	public function test_an_update_keeps_a_section_an_admin_added_to_a_system_stack(): void {
		Schema_Block::create( [
			'slug' => 'thread-house-rules', 'name' => 'House Rules', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [] ], 'is_system' => 0,
		] );
		$definition               = json_decode( wp_json_encode( Creature_Stack::find_by_slug( 'vampire' )->stack_definition ), true );
		$definition['sections'][] = [ 'block_slug' => 'thread-house-rules', 'label' => 'House Rules', 'display_order' => 99, 'required' => false ];

		$response = $this->put( '/be/v1/creature-stacks/vampire', [ 'name' => 'Vampire (renamed here)', 'stack_definition' => $definition ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		Seeder::seed_creature_stacks();

		$after = Creature_Stack::find_by_slug( 'vampire' );
		$this->assertContains( 'thread-house-rules', array_column( json_decode( wp_json_encode( $after->stack_definition->sections ), true ), 'block_slug' ) );
		$this->assertSame( 'Vampire', $after->name, 'the name is the seed data\'s - the update still refreshes it' );
	}
}
