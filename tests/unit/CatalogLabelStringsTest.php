<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Catalog_Labels;
use PHPUnit\Framework\TestCase;

/**
 * Every name the shipped catalog gives a section, a creation rule, a pool or a template has a line in the strings file,
 * and the file is exactly what its generator writes.
 */
class CatalogLabelStringsTest extends TestCase {

	/** @var array<string,string> */
	private static array $strings;

	public static function setUpBeforeClass(): void {
		self::$strings = include BE_PLUGIN_DIR . 'includes/Database/catalog-label-strings.php';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function documents( string $kind ): array {
		$found = [];
		foreach ( glob( BE_PLUGIN_DIR . "data/catalog/{$kind}/*.json" ) ?: [] as $file ) {
			$found[ basename( $file ) ] = json_decode( (string) file_get_contents( $file ), true )['definition'] ?? [];
		}
		return $found;
	}

	public function test_every_section_label_has_a_line(): void {
		foreach ( $this->documents( 'stacks' ) as $file => $definition ) {
			foreach ( $definition['sections'] ?? [] as $section ) {
				$this->assertArrayHasKey( $section['label'], self::$strings, "{$file} labels a section \"{$section['label']}\" with no line." );
			}
		}
	}

	public function test_every_creation_rule_label_and_pool_has_a_line(): void {
		foreach ( $this->documents( 'stacks' ) as $file => $definition ) {
			foreach ( $definition['creation_rules']['steps'] ?? [] as $step ) {
				foreach ( [ 'label', 'pool' ] as $key ) {
					if ( isset( $step[ $key ] ) ) {
						$this->assertArrayHasKey( $step[ $key ], self::$strings, "{$file} has a rule {$key} \"{$step[ $key ]}\" with no line." );
					}
				}
				foreach ( $step['quotas'] ?? [] as $quota ) {
					$this->assertArrayHasKey( $quota['label'], self::$strings, "{$file} has a quota \"{$quota['label']}\" with no line." );
				}
			}
		}
	}

	public function test_every_template_title_has_a_line(): void {
		foreach ( $this->documents( 'templates' ) as $file => $definition ) {
			foreach ( $definition['sections'] ?? [] as $section ) {
				if ( ! is_string( $section['title'] ?? null ) || $section['title'] === '' ) {
					continue;
				}
				$this->assertArrayHasKey( $section['title'], self::$strings, "{$file} titles a section \"{$section['title']}\" with no line." );
			}
		}
	}

	public function test_a_line_translates_the_name_it_is_keyed_by(): void {
		foreach ( self::$strings as $english => $translated ) {
			$this->assertSame( $english, $translated, 'The unit shim returns its input, so a key and its value must match.' );
		}
	}

	public function test_the_generator_writes_the_committed_file(): void {
		$script = dirname( BE_PLUGIN_DIR ) . '/tools/catalog/build-label-strings.php';
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' --check 2>&1', $output, $code );
		$this->assertSame( 0, $code, implode( "\n", $output ) );
	}

	public function test_an_unknown_name_comes_back_unchanged(): void {
		Catalog_Labels::reset();
		$this->assertSame( 'A Chronicle\'s Own Section', Catalog_Labels::word( 'A Chronicle\'s Own Section' ) );
		$this->assertSame( 'Abilities', Catalog_Labels::word( 'Abilities' ) );
	}
}
