<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * `Schema::create_tables()` on a site holding a chronicle copy of a schema block, on a real autocommit connection.
 */
class SchemaBlockIndexThreadTest extends TestCase {

	private string $slug = '';

	private string $prior_autocommit = '1';

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR is not set.' );
		}
	}

	/**
	 * A catalog block and one chronicle's copy of it, sharing a slug, committed on autocommit=1.
	 */
	protected function setUp(): void {
		global $wpdb;
		$this->prior_autocommit = (string) $wpdb->get_var( 'SELECT @@autocommit' );
		$wpdb->query( 'SET autocommit = 1;' );

		$this->slug = 'thread-index-' . strtolower( wp_generate_password( 8, false ) );
		foreach ( [ '', 'thread-index-chronicle' ] as $game_slug ) {
			$wpdb->insert(
				$wpdb->prefix . 'be_schema_blocks',
				[
					'slug'         => $this->slug,
					'game_slug'    => $game_slug,
					'name'         => 'Thread Index Block',
					'section_type' => 'trait_list',
					'definition'   => wp_json_encode( [ 'items' => [] ] ),
					'created_by'   => 1,
				]
			);
		}
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s", $this->slug ) );

		$value = $this->prior_autocommit === '0' ? '0' : '1';
		$wpdb->query( "SET autocommit = {$value};" );
	}

	/**
	 * Every index on `be_schema_blocks`, keyed by name, each its columns in order.
	 *
	 * @return array<string, string[]>
	 */
	private function indexes(): array {
		global $wpdb;
		$indexes = [];
		foreach ( $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}be_schema_blocks" ) as $row ) {
			$indexes[ $row->Key_name ][ (int) $row->Seq_in_index - 1 ] = $row->Column_name;
		}
		return $indexes;
	}

	public function test_the_fixture_holds_a_catalog_block_and_its_copy(): void {
		global $wpdb;
		$this->assertSame(
			'2',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s", $this->slug ) )
		);
	}

	public function test_an_upgrade_with_a_chronicle_copy_present_logs_no_database_error(): void {
		global $wpdb, $EZSQL_ERROR;
		$recorded = is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
		$altered  = [];
		$record   = static function ( string $query ) use ( &$altered, $wpdb ): string {
			if ( stripos( $query, "ALTER TABLE {$wpdb->prefix}be_schema_blocks" ) !== false ) {
				$altered[] = trim( $query );
			}
			return $query;
		};
		add_filter( 'query', $record );
		try {
			Schema::create_tables();
		} finally {
			remove_filter( 'query', $record );
		}

		$errors = array_map(
			static fn( array $error ): string => $error['error_str'] . ' :: ' . $error['query'],
			array_slice( is_array( $EZSQL_ERROR ) ? $EZSQL_ERROR : [], $recorded )
		);
		$this->assertSame( [], $errors, 'the upgrade logged a database error' );
		$this->assertSame( [], $altered, 'the upgrade changed be_schema_blocks although nothing about it needed changing' );
	}

	public function test_after_an_upgrade_the_table_is_unique_on_slug_and_chronicle(): void {
		Schema::create_tables();

		$indexes = $this->indexes();
		$this->assertSame( [ 'slug', 'game_slug' ], $indexes['slug_game'] ?? null );
		$this->assertArrayNotHasKey( 'slug', $indexes );
	}
}
