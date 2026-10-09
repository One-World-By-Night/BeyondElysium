<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Various creature type lists every other creature type's base power family, and its merged Abilities,
 * Backgrounds, Merits, Flaws and Tempers hold every name their source blocks do.
 */
class VariousCatalogTest extends TestCase {

	private const CATALOG = BE_PLUGIN_PATH . '/data/catalog/';

	/** @var array<string,array<string,mixed>>|null */
	private static ?array $blocks = null;

	/**
	 * @return array<string,mixed>
	 */
	private static function read( string $relative ): array {
		$decoded = json_decode( (string) file_get_contents( self::CATALOG . $relative ), true );
		self::assertIsArray( $decoded, $relative );
		return $decoded;
	}

	/**
	 * @return array<string,array<string,mixed>> Every block file, keyed by slug.
	 */
	private static function blocks(): array {
		if ( self::$blocks === null ) {
			self::$blocks = [];
			foreach ( glob( self::CATALOG . 'blocks/*.json' ) ?: [] as $path ) {
				$block                          = self::read( 'blocks/' . basename( $path ) );
				self::$blocks[ $block['slug'] ] = $block;
			}
		}
		return self::$blocks;
	}

	/**
	 * @return string[] The block slugs a stack definition or template lists, negative halves included.
	 */
	private static function listed_slugs( array $sections ): array {
		$slugs = [];
		foreach ( $sections as $section ) {
			$slugs[] = $section['block_slug'];
			if ( ! empty( $section['negative_block_slug'] ) ) {
				$slugs[] = $section['negative_block_slug'];
			}
		}
		return $slugs;
	}

	private static function normalise( string $name ): string {
		return preg_replace( '/[^a-z0-9]/', '', str_replace( '&', 'and', strtolower( $name ) ) ) ?? '';
	}

	/**
	 * @return string[]
	 */
	private static function stack_slugs(): array {
		return self::listed_slugs( self::read( 'stacks/various.json' )['definition']['sections'] );
	}

	public function test_the_various_stack_lists_every_base_power_family(): void {
		$listed  = self::stack_slugs();
		$missing = [];
		foreach ( self::blocks() as $slug => $block ) {
			if ( $block['section_type'] === 'tiered_power' && ! isset( $block['variant'] ) && ! in_array( $slug, $listed, true ) ) {
				$missing[] = $slug;
			}
		}
		$this->assertSame( [], $missing, 'a base tiered-power block missing from the Various stack: add it to stacks/various.json and both Various full templates' );
	}

	public function test_every_block_the_various_stack_lists_exists(): void {
		$unknown = array_values( array_diff( self::stack_slugs(), array_keys( self::blocks() ) ) );
		$this->assertSame( [], $unknown );
	}

	public function test_both_full_templates_place_exactly_the_stack_sections(): void {
		$stack = self::stack_slugs();
		foreach ( [ 'various.sheet_full' => [], 'various.npc_full' => [ 'npc-roleplaying-notes' ] ] as $template => $extra ) {
			$placed = array_column( self::read( "templates/{$template}.json" )['definition']['sections'], 'block_slug' );
			$expect = array_merge( $stack, $extra );
			sort( $placed );
			sort( $expect );
			$this->assertSame( $expect, $placed, $template );
		}
	}

	/**
	 * @return array<string,array{0:string,1:string[],2:string[]}> merged slug => [ merged slug, source suffixes, extra source slugs ]
	 */
	public static function merged_lists(): array {
		return [
			'abilities'   => [ 'various-abilities', [ '-abilities' ], [] ],
			'backgrounds' => [ 'various-backgrounds', [ '-backgrounds' ], [] ],
			'merits'      => [ 'various-merits', [ '-merits' ], [] ],
			'flaws'       => [ 'various-flaws', [ '-flaws' ], [] ],
		];
	}

	/**
	 * @dataProvider merged_lists
	 * @param string[] $suffixes
	 * @param string[] $extra
	 */
	public function test_a_merged_list_holds_every_name_its_source_blocks_hold( string $merged, array $suffixes, array $extra ): void {
		$expected = [];
		foreach ( self::blocks() as $slug => $block ) {
			if ( str_starts_with( $slug, 'various-' ) || $block['section_type'] !== 'trait_list' ) {
				continue;
			}
			$is_source = in_array( $slug, $extra, true );
			foreach ( $suffixes as $suffix ) {
				$is_source = $is_source || str_ends_with( $slug, $suffix );
			}
			if ( ! $is_source ) {
				continue;
			}
			foreach ( $block['definition']['items'] as $item ) {
				$expected[ self::normalise( $item['name'] ) ] = $item['name'];
			}
		}

		$held = [];
		foreach ( self::blocks()[ $merged ]['definition']['items'] as $item ) {
			$held[ self::normalise( $item['name'] ) ] = $item['name'];
		}

		$this->assertSame( [], array_values( array_diff_key( $expected, $held ) ), "{$merged} lacks a source name: run python3 tools/catalog/build_various.py" );
		$this->assertSame( [], array_values( array_diff_key( $held, $expected ) ), "{$merged} holds a name no source block does: run python3 tools/catalog/build_various.py" );
		$this->assertCount( count( $held ), self::blocks()[ $merged ]['definition']['items'], "{$merged} lists a name twice" );
	}

	public function test_the_merged_tempers_hold_every_resource_pool_name(): void {
		$expected = [ self::normalise( 'Essence' ) => 'Essence' ];
		foreach ( self::blocks() as $slug => $block ) {
			if ( ! str_starts_with( $slug, 'various-' ) && str_ends_with( $slug, '-resources' ) && $block['section_type'] === 'resource_pool' ) {
				foreach ( $block['definition']['pools'] as $pool ) {
					$expected[ self::normalise( $pool['name'] ) ] = $pool['name'];
				}
			}
		}

		$held = [];
		foreach ( self::blocks()['various-tempers']['definition']['items'] as $item ) {
			$held[ self::normalise( $item['name'] ) ] = $item['name'];
		}

		$this->assertSame( [], array_values( array_diff_key( $expected, $held ) ), 'a resource pool is missing from Tempers: run python3 tools/catalog/build_various.py' );
	}

	public function test_a_merged_list_carries_no_approval_content(): void {
		foreach ( [ 'various-abilities', 'various-backgrounds', 'various-merits', 'various-flaws', 'various-tempers' ] as $slug ) {
			foreach ( self::blocks()[ $slug ]['definition']['items'] as $item ) {
				$this->assertNull( $item['approval'], "{$slug}: {$item['name']}" );
				$this->assertNull( $item['reason'], "{$slug}: {$item['name']}" );
				$this->assertSame( [], $item['approval_by_value'], "{$slug}: {$item['name']}" );
			}
		}
	}

	public function test_the_old_various_names_survive_in_the_merged_lists(): void {
		$names = static function ( string $slug ): array {
			return array_map(
				static fn( $item ) => $item['name'],
				self::blocks()[ $slug ]['definition']['items']
			);
		};
		$this->assertContains( 'Academics', $names( 'various-abilities' ) );
		$this->assertContains( 'Allies', $names( 'various-backgrounds' ) );
		$this->assertContains( 'Essence', $names( 'various-tempers' ) );
		$this->assertContains( 'Rage', $names( 'various-tempers' ) );
	}
}
