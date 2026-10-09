<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use WP_UnitTestCase;

/**
 * The upgrade's changes to tables an earlier release created: transfer rows move onto the visit vocabulary, and each
 * secret entity column left NOT NULL is made nullable, whatever the other one is.
 */
class SchemaLegacyShapeThreadTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $altered = [];

	public function tearDown(): void {
		remove_all_filters( 'query' );
		parent::tearDown();
	}

	private function transfer( string $direction, string $state ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_character_transfers', [
			'character_uuid' => wp_generate_uuid4(),
			'direction'      => $direction,
			'state'          => $state,
			'home_slug'      => 'home',
			'home_site'      => 'https://home.example',
			'home_chronicle' => 'Home',
			'payload_hash'   => str_repeat( 'a', 64 ),
			'initiated_by'   => 1,
			'initiated_at'   => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function state( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}be_character_transfers WHERE id = %d", $id ) );
	}

	public function test_transfer_rows_move_onto_the_visit_vocabulary(): void {
		$rows = [
			[ 'outbound', 'pending', 'offered' ],
			[ 'outbound', 'abroad', 'visiting' ],
			[ 'outbound', 'returned', 'ended' ],
			[ 'outbound', 'sent_home', 'ended' ],
			[ 'inbound', 'returned', 'ended' ],
			[ 'inbound', 'sent_home', 'ended' ],
			[ 'inbound', 'pending', 'pending' ],
			[ 'inbound', 'visiting', 'visiting' ],
		];
		$ids = [];
		foreach ( $rows as [ $direction, $state ] ) {
			$ids[] = $this->transfer( $direction, $state );
		}

		Schema::rename_transfer_states_for_the_visit_model();
		Schema::rename_transfer_states_for_the_visit_model();

		foreach ( $rows as $i => [ $direction, $state, $expected ] ) {
			$this->assertSame( $expected, $this->state( $ids[ $i ] ), "{$direction} {$state}" );
		}
	}

	public function test_an_entity_column_left_not_null_is_made_nullable_when_the_other_already_is(): void {
		// The secrets table reports entity_id as NOT NULL while entity_type is nullable; ALTERs are recorded, not run.
		add_filter(
			'query',
			function ( string $query ): string {
				if ( str_contains( $query, 'information_schema.columns' ) && str_contains( $query, "'entity_id'" ) ) {
					return 'SELECT 1';
				}
				if ( str_contains( $query, 'ALTER TABLE' ) && str_contains( $query, 'be_secrets' ) ) {
					$this->altered[] = $query;
					return 'SELECT 1';
				}
				return $query;
			}
		);

		Schema::make_secret_entity_nullable();

		$this->assertCount( 1, $this->altered );
		$this->assertStringContainsString( 'MODIFY COLUMN entity_id', $this->altered[0] );
	}
}
