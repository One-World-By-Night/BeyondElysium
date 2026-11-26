<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shipped catalog marks the pools the books spend permanently and each creature type's health track, and nothing
 * else.
 */
class ShippedCatalogMarkersTest extends TestCase {

	/**
	 * The pools the creature types' books spend permanently, by block.
	 *
	 * @var array<string,string[]>
	 */
	private const PERMANENT_SPENDS = [
		'changeling-resources' => [ 'Glamour', 'Willpower' ],
		'demon-resources'      => [ 'Faith', 'Willpower' ],
		'hunter-resources'     => [ 'Willpower' ],
		'kueijin-resources'    => [ 'Willpower' ],
		'mage-resources'       => [ 'Willpower' ],
		'mortal-resources'     => [ 'Willpower' ],
		'mummy-resources'      => [ 'Balance', 'Sekhem', 'Willpower' ],
		'vampire-resources'    => [ 'Willpower' ],
		'werewolf-resources'   => [ 'Gnosis', 'Willpower' ],
		'wraith-resources'     => [ 'Willpower' ],
	];

	/**
	 * @return array<string,mixed>
	 */
	private function read( string $path ): array {
		$data = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $data, $path );
		return $data;
	}

	public function test_only_the_pools_the_books_spend_permanently_are_marked(): void {
		$marked = [];
		foreach ( glob( BE_PLUGIN_PATH . '/data/catalog/blocks/*.json' ) ?: [] as $file ) {
			$data = $this->read( $file );
			if ( ( $data['section_type'] ?? '' ) !== 'resource_pool' ) {
				continue;
			}
			foreach ( (array) ( $data['definition']['pools'] ?? [] ) as $pool ) {
				if ( array_key_exists( 'permanent_spend', $pool ) ) {
					$this->assertTrue( $pool['permanent_spend'], $data['slug'] . ' ' . $pool['name'] );
					$marked[ (string) $data['slug'] ][] = (string) $pool['name'];
				}
			}
		}
		foreach ( $marked as &$names ) {
			sort( $names );
		}
		unset( $names );
		ksort( $marked );

		$expected = self::PERMANENT_SPENDS;
		ksort( $expected );
		$this->assertSame( $expected, $marked );
	}

	public function test_each_creature_type_marks_its_health_levels_and_wraith_has_none(): void {
		$stacks = 0;
		foreach ( glob( BE_PLUGIN_PATH . '/data/catalog/stacks/*.json' ) ?: [] as $file ) {
			$data   = $this->read( $file );
			$slug   = (string) $data['slug'];
			$health = [];
			$marked = [];
			foreach ( (array) $data['definition']['sections'] as $section ) {
				if ( substr( (string) $section['block_slug'], -7 ) === '-health' ) {
					$health[] = (string) $section['block_slug'];
				}
				if ( array_key_exists( 'health_track', $section ) ) {
					$this->assertTrue( $section['health_track'], $slug );
					$marked[] = (string) $section['block_slug'];
				}
			}
			$this->assertSame( $health, $marked, "{$slug} marks its Health section, and only that one" );
			$this->assertLessThanOrEqual( 1, count( $marked ), $slug );
			if ( $slug === 'wraith' ) {
				$this->assertSame( [], $marked );
			}
			$stacks += count( $marked );
		}
		$this->assertSame( 12, $stacks, 'twelve creature types have a Health section; Wraith has none' );
	}
}
