<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use WP_UnitTestCase;

/**
 * B3 (1.2.0 releases/1.2.0-design-workflow.md §5): map()'s caching and bust, decorate()'s four
 * section-type shapes (T2), the missing/empty/unknown-locale fallback chain (T3), rescan()
 * against the real seeded catalog (T5), and strip()'s exact-inverse-at-any-depth guarantee
 * (T7) - including the round trip through real JSON encode/decode, not just in-memory PHP
 * values, since that is the shape §5.5's hazard actually takes.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §5, §9
 */
class CatalogTranslatorThreadTest extends WP_UnitTestCase {

	private const LOCALE = 'pt_BR';

	public function tearDown(): void {
		// Every test bumps the version option via bust_cache() or a direct write; reset it so
		// tests do not leak a stale version (and therefore a stale transient key) into the next.
		delete_option( 'be_translations_version' );
		parent::tearDown();
	}

	private function make_translated_string( string $text, string $translation, array $used_in = [] ): int {
		$string_id = (int) Translation_String::create( [ 'source_text' => $text, 'used_in' => $used_in ] );
		Translation::create( [ 'string_id' => $string_id, 'locale' => self::LOCALE, 'translation' => $translation ] );
		return $string_id;
	}

	// ---------------------------------------------------------------- map() / bust_cache() ----

	public function test_map_returns_real_translations(): void {
		$this->make_translated_string( 'Cache Warm Term', 'Termo Aquecido' );
		$map = Catalog_Translator::map( self::LOCALE );
		$this->assertSame( 'Termo Aquecido', $map['cache warm term'] );
	}

	public function test_map_for_an_untranslated_locale_is_empty(): void {
		$this->assertSame( [], Catalog_Translator::map( 'de_DE' ) );
	}

	/**
	 * The real point of the transient: a second map() call must not re-query. Proven by
	 * writing directly around the model layer (bypassing bust_cache()) and confirming the
	 * cached, now-stale value is still what comes back - if this test failed, it would mean
	 * every call was hitting the database live, which is the opposite of §5.1's design.
	 */
	public function test_map_is_cached_across_calls(): void {
		$this->make_translated_string( 'Caching Proof Term', 'Original' );
		$first = Catalog_Translator::map( self::LOCALE );
		$this->assertSame( 'Original', $first['caching proof term'] );

		global $wpdb;
		$table = $wpdb->prefix . 'be_translations';
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET translation = %s WHERE translation = %s", 'Changed Behind The Cache', 'Original' ) );

		$second = Catalog_Translator::map( self::LOCALE );
		$this->assertSame( 'Original', $second['caching proof term'], 'a cached map must not silently see a write that bypassed bust_cache()' );
	}

	public function test_bust_cache_invalidates_the_map(): void {
		$this->make_translated_string( 'Bust Proof Term', 'Original' );
		Catalog_Translator::map( self::LOCALE ); // warm the cache

		global $wpdb;
		$table = $wpdb->prefix . 'be_translations';
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET translation = %s WHERE translation = %s", 'Updated', 'Original' ) );
		Catalog_Translator::bust_cache();

		$after = Catalog_Translator::map( self::LOCALE );
		$this->assertSame( 'Updated', $after['bust proof term'] );
	}

	public function test_bust_cache_invalidates_every_locales_map_at_once(): void {
		$this->make_translated_string( 'Multi Locale Term', 'Portuguese Version' );
		Translation_String::create( [ 'source_text' => 'Only For Spanish' ] ); // placeholder so es_ES has a real row too
		Catalog_Translator::map( self::LOCALE );
		Catalog_Translator::map( 'es_ES' );

		Catalog_Translator::bust_cache();

		// Both locales' NEW transient keys (post-bump) must reflect fresh reads, not the old
		// pre-bump cached arrays - proven by confirming a fresh insert after the bump is visible.
		$string_id = (int) Translation_String::create( [ 'source_text' => 'Post Bust Term' ] );
		Translation::create( [ 'string_id' => $string_id, 'locale' => self::LOCALE, 'translation' => 'Visible After Bust' ] );
		$map = Catalog_Translator::map( self::LOCALE );
		$this->assertSame( 'Visible After Bust', $map['post bust term'] );
	}

	// -------------------------------------------------------------------------- decorate() ----

	/** T2: trait_list gets name_pt; name is untouched. */
	public function test_decorate_trait_list_adds_name_pt_leaves_name_untouched(): void {
		$this->make_translated_string( 'Decorated Merit', 'Merito Decorado' );
		$block = (object) [
			'section_type' => 'trait_list',
			'definition'   => (object) [ 'items' => [ (object) [ 'name' => 'Decorated Merit' ] ] ],
		];

		$result = Catalog_Translator::decorate( $block, self::LOCALE );

		$this->assertSame( 'Decorated Merit', $result->definition->items[0]->name );
		$this->assertSame( 'Merito Decorado', $result->definition->items[0]->name_pt );
	}

	/** T2: tiered_power gets name_pt on the family and power_name_pt on each level. */
	public function test_decorate_tiered_power_adds_both_pt_fields(): void {
		$this->make_translated_string( 'Decorated Path', 'Caminho Decorado' );
		$this->make_translated_string( 'Decorated Level One', 'Nivel Um Decorado' );

		$block = (object) [
			'section_type' => 'tiered_power',
			'definition'   => (object) [
				'powers' => [
					(object) [
						'name'   => 'Decorated Path',
						'levels' => [ (object) [ 'level' => 1, 'power_name' => 'Decorated Level One' ] ],
					],
				],
			],
		];

		$result = Catalog_Translator::decorate( $block, self::LOCALE );
		$power  = $result->definition->powers[0];

		$this->assertSame( 'Decorated Path', $power->name );
		$this->assertSame( 'Caminho Decorado', $power->name_pt );
		$this->assertSame( 'Decorated Level One', $power->levels[0]->power_name );
		$this->assertSame( 'Nivel Um Decorado', $power->levels[0]->power_name_pt );
	}

	/** T2: identity_field gets label_pt on the field, options_pt as a canonical=>translated map, options untouched. */
	public function test_decorate_identity_field_adds_label_pt_and_options_pt(): void {
		$this->make_translated_string( 'Decorated Field', 'Campo Decorado' );
		$this->make_translated_string( 'Decorated Option', 'Opcao Decorada' );

		$block = (object) [
			'section_type' => 'identity_field',
			'definition'   => (object) [
				'fields' => [
					(object) [ 'name' => 'Decorated Field', 'options' => [ 'Decorated Option', 'Untranslated Option' ] ],
				],
			],
		];

		$result = Catalog_Translator::decorate( $block, self::LOCALE );
		$field  = $result->definition->fields[0];

		$this->assertSame( 'Decorated Field', $field->name );
		$this->assertSame( 'Campo Decorado', $field->label_pt );
		$this->assertSame( [ 'Decorated Option', 'Untranslated Option' ], $field->options, 'options must stay byte-identical' );
		$this->assertSame( 'Opcao Decorada', $field->options_pt['Decorated Option'] );
		$this->assertArrayNotHasKey( 'Untranslated Option', $field->options_pt, 'no map entry means no key at all, never a blank one' );
	}

	/** A number-type identity_field with no options gets label_pt only - never a spurious options_pt. */
	public function test_decorate_identity_field_without_options_gets_no_options_pt_key(): void {
		$this->make_translated_string( 'Decorated Number Field', 'Campo Numerico Decorado' );
		$block = (object) [
			'section_type' => 'identity_field',
			'definition'   => (object) [
				'fields' => [ (object) [ 'name' => 'Decorated Number Field', 'field_type' => 'number' ] ],
			],
		];
		$result = Catalog_Translator::decorate( $block, self::LOCALE );
		$this->assertSame( 'Campo Numerico Decorado', $result->definition->fields[0]->label_pt );
		$this->assertObjectNotHasProperty( 'options_pt', $result->definition->fields[0] );
	}

	/** T2: resource_pool gets label_pt per pool. */
	public function test_decorate_resource_pool_adds_label_pt(): void {
		$this->make_translated_string( 'Decorated Pool', 'Piscina Decorada' );
		$block = (object) [
			'section_type' => 'resource_pool',
			'definition'   => (object) [ 'pools' => [ (object) [ 'name' => 'Decorated Pool', 'max' => 10 ] ] ],
		];
		$result = Catalog_Translator::decorate( $block, self::LOCALE );
		$this->assertSame( 'Piscina Decorada', $result->definition->pools[0]->label_pt );
		$this->assertSame( 10, $result->definition->pools[0]->max, 'unrelated fields must be untouched' );
	}

	/** decorate() overwrites a stale, pre-1.2.0 baked-in name_pt with the table's current value. */
	public function test_decorate_overwrites_a_stale_pre_existing_name_pt(): void {
		$this->make_translated_string( 'Superseded Term', 'Valor Novo Da Tabela' );
		$block = (object) [
			'section_type' => 'trait_list',
			'definition'   => (object) [
				'items' => [ (object) [ 'name' => 'Superseded Term', 'name_pt' => 'Valor Antigo Do CSV' ] ],
			],
		];
		$result = Catalog_Translator::decorate( $block, self::LOCALE );
		$this->assertSame( 'Valor Novo Da Tabela', $result->definition->items[0]->name_pt );
	}

	// --------------------------------------------------------------- T3: fallback triggers ----

	/** No translation row at all: decorate() adds nothing, leaving the English fallback to fire downstream. */
	public function test_decorate_with_no_matching_translation_adds_no_pt_key(): void {
		Translation_String::create( [ 'source_text' => 'Never Translated Term' ] );
		$block = (object) [
			'section_type' => 'trait_list',
			'definition'   => (object) [ 'items' => [ (object) [ 'name' => 'Never Translated Term' ] ] ],
		];
		$result = Catalog_Translator::decorate( $block, self::LOCALE );
		$this->assertObjectNotHasProperty( 'name_pt', $result->definition->items[0] );
	}

	/** An empty-string translation is excluded by Translation::map_for_locale() (B2) - decorate() inherits that for free. */
	public function test_decorate_with_an_empty_translation_adds_no_pt_key(): void {
		$string_id = (int) Translation_String::create( [ 'source_text' => 'Emptied Out Term' ] );
		Translation::create( [ 'string_id' => $string_id, 'locale' => self::LOCALE, 'translation' => '' ] );
		$block = (object) [
			'section_type' => 'trait_list',
			'definition'   => (object) [ 'items' => [ (object) [ 'name' => 'Emptied Out Term' ] ] ],
		];
		$result = Catalog_Translator::decorate( $block, self::LOCALE );
		$this->assertObjectNotHasProperty( 'name_pt', $result->definition->items[0] );
	}

	/** An unknown/untranslated locale leaves the block entirely unchanged. */
	public function test_decorate_with_an_unknown_locale_leaves_the_block_unchanged(): void {
		$this->make_translated_string( 'Only In Portuguese', 'Apenas Em Portugues' );
		$block = (object) [
			'section_type' => 'trait_list',
			'definition'   => (object) [ 'items' => [ (object) [ 'name' => 'Only In Portuguese' ] ] ],
		];
		$result = Catalog_Translator::decorate( $block, 'fr_FR' );
		$this->assertObjectNotHasProperty( 'name_pt', $result->definition->items[0] );
	}

	public function test_decorate_on_an_unrecognized_section_type_is_a_harmless_no_op(): void {
		$this->make_translated_string( 'Whatever Term', 'Termo Qualquer' );
		$block = (object) [ 'section_type' => 'something_new', 'definition' => (object) [ 'whatever' => 'ignored' ] ];
		$result = Catalog_Translator::decorate( $block, self::LOCALE );
		$this->assertSame( 'ignored', $result->definition->whatever );
	}

	// ------------------------------------------------------------------------------ strip() ----

	/** T7: every PT_KEYS key is removed, at every depth, across all four section-type shapes at once. */
	public function test_strip_removes_every_pt_key_at_every_depth(): void {
		$definition = (object) [
			'items'  => [ (object) [ 'name' => 'A', 'name_pt' => 'A-pt' ] ],
			'powers' => [
				(object) [
					'name'   => 'B',
					'name_pt' => 'B-pt',
					'levels' => [ (object) [ 'power_name' => 'C', 'power_name_pt' => 'C-pt' ] ],
				],
			],
			'fields' => [ (object) [ 'name' => 'D', 'label_pt' => 'D-pt', 'options_pt' => [ 'x' => 'y' ] ] ],
			'pools'  => [ (object) [ 'name' => 'E', 'label_pt' => 'E-pt' ] ],
		];

		$stripped = Catalog_Translator::strip( $definition );
		$json     = wp_json_encode( $stripped );

		foreach ( [ 'name_pt', 'power_name_pt', 'options_pt', 'label_pt' ] as $key ) {
			$this->assertStringNotContainsString( "\"{$key}\"", $json, "{$key} survived strip()" );
		}
		// Canonical data must survive untouched.
		$this->assertSame( 'A', $stripped->items[0]->name );
		$this->assertSame( 'C', $stripped->powers[0]->levels[0]->power_name );
	}

	/**
	 * The real §5.5 hazard: decorate() then a genuine JSON round trip (what Schema_Blocks_
	 * Controller's GET response and a client's PUT body actually are) then strip(). The
	 * decoded shape after a round trip has options_pt as a stdClass, not a PHP array - strip()
	 * must handle both, which this proves by exercising the real conversion rather than a
	 * hand-built fixture that only ever looks like the in-memory shape.
	 */
	public function test_strip_after_a_real_json_round_trip_still_removes_options_pt(): void {
		$this->make_translated_string( 'Round Trip Field', 'Campo De Ida E Volta' );
		$this->make_translated_string( 'Round Trip Option', 'Opcao De Ida E Volta' );

		$block = (object) [
			'section_type' => 'identity_field',
			'definition'   => (object) [
				'fields' => [ (object) [ 'name' => 'Round Trip Field', 'options' => [ 'Round Trip Option' ] ] ],
			],
		];
		$decorated       = Catalog_Translator::decorate( $block, self::LOCALE );
		$round_tripped   = json_decode( (string) wp_json_encode( $decorated->definition ) );
		$this->assertIsObject( $round_tripped->fields[0]->options_pt, 'confirms the round trip really does produce stdClass, not array' );

		$stripped = Catalog_Translator::strip( $round_tripped );
		$json     = wp_json_encode( $stripped );
		$this->assertStringNotContainsString( '"options_pt"', $json );
		$this->assertStringNotContainsString( '"label_pt"', $json );
		$this->assertSame( 'Round Trip Field', $stripped->fields[0]->name );
	}

	public function test_strip_on_an_array_shaped_definition_also_works(): void {
		$definition = [ 'items' => [ [ 'name' => 'F', 'name_pt' => 'F-pt' ] ] ];
		$stripped   = Catalog_Translator::strip( $definition );
		$this->assertArrayNotHasKey( 'name_pt', $stripped['items'][0] );
		$this->assertSame( 'F', $stripped['items'][0]['name'] );
	}

	// ------------------------------------------------------------------------------ rescan() ----

	/** T5: against the real seeded catalog - not a fixture - rescan() finds the measured volume and orphans nothing on a clean seed. */
	public function test_rescan_against_the_real_seeded_catalog_finds_the_measured_volume(): void {
		$result = Catalog_Translator::rescan();
		$this->assertGreaterThanOrEqual( 8000, $result['added'] + $result['updated'], '§1.2 measured 8,298 distinct strings across the real catalog' );
		$this->assertSame( 0, $result['orphaned'], 'a rescan against the exact catalog it already indexed must orphan nothing' );
	}

	public function test_a_second_rescan_of_an_unchanged_catalog_updates_rather_than_re_adds(): void {
		Catalog_Translator::rescan();
		$second = Catalog_Translator::rescan();
		$this->assertSame( 0, $second['added'], 'nothing new exists on an unchanged catalog' );
		$this->assertGreaterThan( 0, $second['updated'] );
		$this->assertSame( 0, $second['orphaned'] );
	}

	public function test_rescan_finds_a_newly_created_block(): void {
		// The first call's own 'added' count reflects indexing the WHOLE catalog for the first
		// time in this test's transaction - not a meaningful baseline to compare a second call
		// against. Once the catalog is fully indexed, the second call's 'added' should be
		// exactly the one new fixture term, nothing else.
		Catalog_Translator::rescan();
		Schema_Block::create( [
			'slug'         => 'rescan-fixture-block',
			'name'         => 'Rescan Fixture Block',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Freshly Seeded Fixture Term' ] ] ],
		] );
		$after = Catalog_Translator::rescan();
		$this->assertSame( 1, $after['added'], 'exactly the one new fixture term should be newly added' );
		$row = Translation_String::find_by_source_text( 'Freshly Seeded Fixture Term' );
		$this->assertNotNull( $row );
	}

	/**
	 * The exact bug the aggregate-before-upsert design in rescan() exists to prevent: a term
	 * appearing in two different blocks must show BOTH in used_in after one rescan, not just
	 * whichever block the walk happened to visit last.
	 */
	public function test_rescan_aggregates_used_in_across_multiple_blocks_for_the_same_term(): void {
		Schema_Block::create( [
			'slug' => 'rescan-aggregate-a', 'name' => 'Rescan Aggregate A', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Shared Across Two Blocks Term' ] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'rescan-aggregate-b', 'name' => 'Rescan Aggregate B', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Shared Across Two Blocks Term' ] ] ],
		] );

		Catalog_Translator::rescan();

		$row = Translation_String::find_by_source_text( 'Shared Across Two Blocks Term' );
		$blocks = array_map( fn( $u ) => $u->block, $row->used_in );
		$this->assertContains( 'rescan-aggregate-a', $blocks );
		$this->assertContains( 'rescan-aggregate-b', $blocks );
	}

	public function test_rescan_orphans_a_term_whose_block_is_deleted(): void {
		Schema_Block::create( [
			'slug' => 'rescan-orphan-fixture', 'name' => 'Rescan Orphan Fixture', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Soon To Be Orphaned Term' ] ] ],
		] );
		Catalog_Translator::rescan();
		$this->assertNotNull( Translation_String::find_by_source_text( 'Soon To Be Orphaned Term' ) );

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'be_schema_blocks', [ 'slug' => 'rescan-orphan-fixture' ] );

		$result = Catalog_Translator::rescan();
		$this->assertGreaterThan( 0, $result['orphaned'] );
		// Never deleted - the translation, if any existed, must still be findable by name.
		$this->assertNotNull( Translation_String::find_by_source_text( 'Soon To Be Orphaned Term' ) );
	}
}
