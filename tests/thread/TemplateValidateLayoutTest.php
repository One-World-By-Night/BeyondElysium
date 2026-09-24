<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use WP_UnitTestCase;

/**
 * Layout schema validation, against a real be_schema_blocks table.
 */
class TemplateValidateLayoutTest extends WP_UnitTestCase {

	private string $block_slug = 'thread-test-identity-block';

	public function setUp(): void {
		parent::setUp();

		Schema_Block::create( [
			'slug'         => $this->block_slug,
			'name'         => 'Thread Test Identity Block',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [ 'name' => 'Test Field' ] ] ],
		] );
	}

	private function valid_layout(): array {
		return [
			'version'  => 1,
			'columns'  => 3,
			'sections' => [
				[
					'block_slug' => $this->block_slug,
					'column'     => 1,
					'order'      => 1,
					'title'      => 'Identity',
					'display'    => null,
					'collapsed'  => false,
				],
			],
		];
	}

	public function test_valid_layout_passes(): void {
		$this->assertNull( Template::validate_layout( $this->valid_layout() ) );
	}

	public function test_layout_must_be_an_array(): void {
		$this->assertInstanceOf( \WP_Error::class, Template::validate_layout( 'not an array' ) );
		$this->assertInstanceOf( \WP_Error::class, Template::validate_layout( null ) );
	}

	public function test_version_must_be_exactly_1(): void {
		$layout            = $this->valid_layout();
		$layout['version'] = 2;
		$this->assertInstanceOf( \WP_Error::class, Template::validate_layout( $layout ) );
	}

	/**
	 * Widened from 1-4 to 1-6: the real grid (templateLayout.ts) is 6 tracks wide.
	 *
	 * @dataProvider invalid_column_counts
	 */
	public function test_columns_must_be_1_to_6( $columns ): void {
		$layout            = $this->valid_layout();
		$layout['columns'] = $columns;
		$this->assertInstanceOf( \WP_Error::class, Template::validate_layout( $layout ) );
	}

	public function invalid_column_counts(): array {
		return [ 'zero' => [ 0 ], 'seven' => [ 7 ], 'negative' => [ -1 ], 'non-integer' => [ 'three' ] ];
	}

	public function test_section_must_have_a_real_block_slug(): void {
		$layout                              = $this->valid_layout();
		$layout['sections'][0]['block_slug'] = 'no-such-block';
		$this->assertInstanceOf( \WP_Error::class, Template::validate_layout( $layout ) );
	}

	public function test_section_column_cannot_exceed_layout_columns(): void {
		$layout                          = $this->valid_layout();
		$layout['columns']               = 2;
		$layout['sections'][0]['column'] = 3;
		$this->assertInstanceOf( \WP_Error::class, Template::validate_layout( $layout ) );
	}

	public function test_duplicate_block_slug_rejected(): void {
		$layout               = $this->valid_layout();
		$layout['sections'][] = $layout['sections'][0];
		$this->assertInstanceOf( \WP_Error::class, Template::validate_layout( $layout ) );
	}

	public function test_null_display_is_valid(): void {
		$layout                           = $this->valid_layout();
		$layout['sections'][0]['display'] = null;
		$this->assertNull( Template::validate_layout( $layout ) );
	}

	/** @dataProvider all_display_types */
	public function test_every_display_type_is_valid( string $display ): void {
		$layout                           = $this->valid_layout();
		$layout['sections'][0]['display'] = $display;
		$this->assertNull( Template::validate_layout( $layout ) );
	}

	public function all_display_types(): array {
		return array_map( static fn( string $d ): array => [ $d ], Template::DISPLAY_TYPES );
	}

	public function test_unknown_display_type_rejected(): void {
		$layout                           = $this->valid_layout();
		$layout['sections'][0]['display'] = 'not-a-real-mode';
		$this->assertInstanceOf( \WP_Error::class, Template::validate_layout( $layout ) );
	}
}
