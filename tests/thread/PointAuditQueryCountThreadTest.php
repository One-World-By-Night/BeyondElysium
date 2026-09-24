<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Point_Audit;
use WP_UnitTestCase;

/**
 * The point audit costs no more queries for eight more Disciplines, and in-clan and out-of-clan Disciplines still price
 * apart.
 */
class PointAuditQueryCountThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-audit-queries';

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * @param string[] $disciplines
	 * @return array{0:array<string,mixed>,1:int} The report, and the queries it took.
	 */
	private function audit_brujah_holding( array $disciplines ): array {
		$id = Character::create( [
			'name'       => 'Audit Queries ' . count( $disciplines ),
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'sheet_data' => [
				'vampire-identity'    => [ 'Clan' => 'Brujah' ],
				'vampire-disciplines' => array_map( static fn( $name ) => [ 'name' => $name, 'level' => 1 ], $disciplines ),
			],
		] );
		$this->assertIsInt( $id );

		global $wpdb;
		Point_Audit::for_character( $id );
		$before = $wpdb->num_queries;
		$report = Point_Audit::for_character( $id );
		return [ $report, $wpdb->num_queries - $before ];
	}

	/**
	 * @param array<string,mixed> $report
	 * @return array<string,mixed>
	 */
	private function discipline_line( array $report, string $name ): array {
		foreach ( $report['lines'] as $line ) {
			if ( $line['block_slug'] === 'vampire-disciplines' && str_starts_with( $line['label'], $name ) ) {
				return $line;
			}
		}
		$this->fail( "No audit line for {$name}." );
	}

	public function test_eight_more_disciplines_cost_no_more_queries(): void {
		[ , $two ] = $this->audit_brujah_holding( [ 'Celerity', 'Obfuscate' ] );
		[ , $ten ] = $this->audit_brujah_holding( [ 'Animalism', 'Auspex', 'Celerity', 'Dominate', 'Fortitude', 'Obfuscate', 'Potence', 'Presence', 'Protean', 'Dementation' ] );

		$this->assertLessThanOrEqual( $two, $ten, "Two Disciplines took {$two} queries, ten took {$ten}." );
	}

	public function test_in_clan_and_out_of_clan_disciplines_still_price_apart(): void {
		[ $report ] = $this->audit_brujah_holding( [ 'Celerity', 'Obfuscate' ] );
		$modifier   = (int) ( Schema_Block::find_by_slug( 'vampire-disciplines' )->definition->out_of_type_cost_modifier ?? 0 );
		$this->assertGreaterThan( 0, $modifier, 'The seeded catalog charges more for an out-of-clan Discipline.' );

		$this->assertNull( $this->discipline_line( $report, 'Celerity' )['modifier'], 'Celerity is in-clan for a Brujah.' );
		$this->assertSame( $modifier, $this->discipline_line( $report, 'Obfuscate' )['modifier'] );
	}
}
