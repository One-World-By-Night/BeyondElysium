<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\Rich_Text_Sanitizer;
use WP_UnitTestCase;

/**
 * `Rich_Text_Sanitizer` - the stricter-than-`wp_kses_post()` allowlist for a
 * catalog item's `description` field: formatting, lists, and tables survive;
 * images and anything else are stripped. Modeled on `Pdf_Writer`'s own
 * `PROSE_ALLOWED_TAGS`/`sanitize_prose()`, including its script/style
 * content-stripping fix (`wp_kses()` unwraps a disallowed tag but keeps its
 * inner text, so the tag's own content must be removed first).
 */
class RichTextSanitizerThreadTest extends WP_UnitTestCase {

	public function test_formatting_tags_survive(): void {
		$html = '<p>Some <strong>bold</strong> and <em>italic</em> text.</p>';
		$this->assertSame( $html, Rich_Text_Sanitizer::sanitize( $html ) );
	}

	public function test_lists_survive(): void {
		$html = '<ul><li>One</li><li>Two</li></ul>';
		$this->assertSame( $html, Rich_Text_Sanitizer::sanitize( $html ) );
	}

	public function test_tables_survive(): void {
		$html = '<table><thead><tr><th>Level</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>';
		$this->assertSame( $html, Rich_Text_Sanitizer::sanitize( $html ) );
	}

	public function test_table_cell_colspan_and_rowspan_survive(): void {
		$html = '<table><tr><td colspan="2" rowspan="3">Merged</td></tr></table>';
		$this->assertSame( $html, Rich_Text_Sanitizer::sanitize( $html ) );
	}

	public function test_plain_links_survive(): void {
		$html = '<p>See <a href="https://example.com" title="Source">the source</a>.</p>';
		$this->assertSame( $html, Rich_Text_Sanitizer::sanitize( $html ) );
	}

	public function test_images_are_stripped(): void {
		$html = '<p>Before</p><img src="https://example.com/x.png" alt="x" /><p>After</p>';
		$result = Rich_Text_Sanitizer::sanitize( $html );

		$this->assertStringNotContainsString( '<img', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	public function test_script_tag_and_its_content_are_removed_entirely(): void {
		$html   = '<p>Before</p><script>alert(1)</script><p>After</p>';
		$result = Rich_Text_Sanitizer::sanitize( $html );

		$this->assertStringNotContainsString( 'script', $result );
		$this->assertStringNotContainsString( 'alert(1)', $result, 'wp_kses() alone would strip the tag but leave this text behind' );
	}

	public function test_style_tag_and_its_content_are_removed_entirely(): void {
		$html   = '<style>body{display:none}</style><p>Visible</p>';
		$result = Rich_Text_Sanitizer::sanitize( $html );

		$this->assertStringNotContainsString( 'display:none', $result );
		$this->assertStringContainsString( 'Visible', $result );
	}

	public function test_an_event_handler_attribute_is_stripped_even_on_an_allowed_tag(): void {
		$html   = '<p onclick="alert(1)">Text</p>';
		$result = Rich_Text_Sanitizer::sanitize( $html );

		$this->assertStringNotContainsString( 'onclick', $result );
		$this->assertStringContainsString( 'Text', $result );
	}

	public function test_iframe_and_object_and_embed_are_stripped(): void {
		$html   = '<iframe src="https://evil.example"></iframe><object data="x"></object><embed src="x">';
		$result = Rich_Text_Sanitizer::sanitize( $html );

		$this->assertStringNotContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( '<object', $result );
		$this->assertStringNotContainsString( '<embed', $result );
	}

	public function test_sanitize_sections_keeps_only_the_three_named_keys(): void {
		$result = Rich_Text_Sanitizer::sanitize_sections( [
			'reference'   => '<p>Book, p.42</p>',
			'description' => '<p>House rule</p><img src="x.png">',
			'source'      => '<p>Told by the Storyteller</p>',
			'stray_key'   => '<p>Ignored</p>',
		] );

		$this->assertSame( '<p>Book, p.42</p>', $result['reference'] );
		$this->assertSame( '<p>House rule</p>', $result['description'] );
		$this->assertSame( '<p>Told by the Storyteller</p>', $result['source'] );
		$this->assertArrayNotHasKey( 'stray_key', $result );
	}

	public function test_sanitize_sections_omits_a_section_that_sanitizes_to_nothing(): void {
		$result = Rich_Text_Sanitizer::sanitize_sections( [
			'reference' => '<script>alert(1)</script>',
		] );

		$this->assertArrayNotHasKey( 'reference', $result );
		$this->assertSame( [], $result );
	}

	public function test_sanitize_sections_accepts_a_decoded_json_object(): void {
		$value  = json_decode( wp_json_encode( [ 'description' => '<p>Ok</p>' ] ) );
		$result = Rich_Text_Sanitizer::sanitize_sections( $value );

		$this->assertSame( '<p>Ok</p>', $result['description'] );
	}

	public function test_sanitize_sections_returns_empty_for_a_non_object_value(): void {
		$this->assertSame( [], Rich_Text_Sanitizer::sanitize_sections( 'not an object' ) );
		$this->assertSame( [], Rich_Text_Sanitizer::sanitize_sections( null ) );
	}

	public function test_sanitize_definition_narrows_trait_list_item_descriptions(): void {
		$definition = [
			'items' => [
				[ 'name' => 'Occult', 'description' => [ 'description' => '<p>Ok</p><img src="x.png">' ] ],
				[ 'name' => 'Alertness' ],
			],
		];
		$result = Rich_Text_Sanitizer::sanitize_definition( $definition, 'trait_list' );

		$this->assertSame( [ 'description' => '<p>Ok</p>' ], $result['items'][0]['description'] );
		$this->assertArrayNotHasKey( 'description', $result['items'][1] );
	}

	public function test_sanitize_definition_drops_a_description_key_that_sanitizes_to_nothing(): void {
		$definition = [
			'items' => [
				[ 'name' => 'Occult', 'description' => [ 'description' => '<script>bad()</script>' ] ],
			],
		];
		$result = Rich_Text_Sanitizer::sanitize_definition( $definition, 'trait_list' );

		$this->assertArrayNotHasKey( 'description', $result['items'][0] );
	}

	public function test_sanitize_definition_narrows_tiered_power_family_and_level_descriptions(): void {
		$definition = [
			'powers' => [
				[
					'name'        => 'Celerity',
					'description' => [
						'reference'   => '<p>Ref</p>',
						'description' => '<p>Family note</p><script>bad()</script>',
					],
					'levels'      => [
						[ 'level' => 1, 'power_name' => 'Alacrity', 'description' => [ 'source' => '<ul><li>Fine</li></ul>' ] ],
						[ 'level' => 2, 'power_name' => 'Fleetness' ],
					],
				],
			],
		];
		$result = Rich_Text_Sanitizer::sanitize_definition( $definition, 'tiered_power' );

		$this->assertSame( '<p>Ref</p>', $result['powers'][0]['description']['reference'] );
		$this->assertSame( '<p>Family note</p>', $result['powers'][0]['description']['description'] );
		$this->assertSame( '<ul><li>Fine</li></ul>', $result['powers'][0]['levels'][0]['description']['source'] );
		$this->assertArrayNotHasKey( 'description', $result['powers'][0]['levels'][1] );
	}

	public function test_sanitize_definition_leaves_other_section_types_untouched(): void {
		$definition = [ 'pools' => [ [ 'name' => 'Willpower' ] ] ];
		$result     = Rich_Text_Sanitizer::sanitize_definition( $definition, 'resource_pool' );

		$this->assertSame( $definition, $result );
	}
}
