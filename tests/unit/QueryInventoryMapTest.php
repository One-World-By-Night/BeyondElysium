<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Field_Registry;
use BeyondElysium\Services\Query_Engine;
use PHPUnit\Framework\TestCase;

/**
 * Proves query-inventories.php's 42 entries against two independent
 * authorities - world-object-schemas.php (the properties column's real
 * shape) and Field_Registry::for_inventory() (qkdata.gvd's own key counts)
 * - before Query_Engine ever reads a single one of them. A key set that
 * silently drifted from either authority would produce a plausible-looking
 * query that reads a property nothing writes, or a field list that omits a
 * real key.
 *
 * @see BE_PROCESS/query-beyond-characters-design.md §6, QB-3
 */
class QueryInventoryMapTest extends TestCase {

	private const WORLD_OBJECT_TYPE_BY_INVENTORY = [ 'item' => 'item', 'loc' => 'location', 'rote' => 'rote' ];

	public function test_char_inventory_declares_no_fields_of_its_own(): void {
		$this->assertNull( Field_Registry::inventory( 'char' )['fields'] );
	}

	/**
	 * Every `properties` entry names a key that exists in that object_type's
	 * real schema - mirrors FieldRegistryTest::assert_json_entry_resolves()'s
	 * own "every mapped key resolves to something real" discipline.
	 */
	public function test_every_properties_entry_names_a_real_schema_property(): void {
		foreach ( self::WORLD_OBJECT_TYPE_BY_INVENTORY as $inventory => $object_type ) {
			$schema = World_Object::schemas()[ $object_type ];
			foreach ( Field_Registry::inventory( $inventory )['fields'] as $key => $entry ) {
				if ( $entry['source'] !== 'properties' ) {
					continue;
				}
				$this->assertArrayHasKey(
					$entry['property'],
					$schema,
					"{$inventory}.{$key}: property '{$entry['property']}' is not declared on the '{$object_type}' schema"
				);
			}
		}
	}

	/**
	 * Every `column` entry names a real be_world_objects column - mirrors
	 * known_character_columns() in FieldRegistryTest.
	 */
	public function test_every_column_entry_names_a_real_world_object_column(): void {
		foreach ( self::WORLD_OBJECT_TYPE_BY_INVENTORY as $inventory => $object_type ) {
			foreach ( Field_Registry::inventory( $inventory )['fields'] as $key => $entry ) {
				if ( $entry['source'] !== 'column' ) {
					continue;
				}
				$this->assertContains(
					$entry['column'],
					self::known_world_object_columns(),
					"{$inventory}.{$key}: '{$entry['column']}' is not a real be_world_objects column"
				);
			}
		}
	}

	/**
	 * Each inventory's key set equals Field_Registry::for_inventory()'s
	 * exactly, in both directions, so a qkdata re-read cannot silently
	 * desync from the map - the same 1:1 discipline
	 * FieldRegistryTest::test_every_registry_key_has_a_map_entry() applies
	 * to the char map.
	 */
	public function test_key_sets_match_the_registry_exactly(): void {
		foreach ( array_keys( self::WORLD_OBJECT_TYPE_BY_INVENTORY ) as $inventory ) {
			$registry_keys = array_keys( Field_Registry::for_inventory( $inventory ) );
			$map_keys      = array_keys( Field_Registry::inventory( $inventory )['fields'] );

			sort( $registry_keys );
			sort( $map_keys );

			$this->assertSame( $registry_keys, $map_keys, "{$inventory}: map keys must equal the registry's for_inventory() keys exactly" );
		}
	}

	public function test_the_42_entry_counts_match_the_design_doc(): void {
		$this->assertCount( 16, Field_Registry::inventory( 'item' )['fields'] );
		$this->assertCount( 18, Field_Registry::inventory( 'loc' )['fields'] );
		$this->assertCount( 8, Field_Registry::inventory( 'rote' )['fields'] );
	}

	/**
	 * A list-typed key whose registry type is NOT overridden must resolve to
	 * a property the schema declares trait_list - the assertion that catches
	 * §4d's TypeError class (a string read where evaluate_list() expects an
	 * array) before resolve_value() can ever produce one.
	 */
	public function test_every_unoverridden_list_key_resolves_to_a_trait_list_property(): void {
		foreach ( self::WORLD_OBJECT_TYPE_BY_INVENTORY as $inventory => $object_type ) {
			$schema  = World_Object::schemas()[ $object_type ];
			$registry = Field_Registry::for_inventory( $inventory );

			foreach ( Field_Registry::inventory( $inventory )['fields'] as $key => $entry ) {
				if ( $entry['source'] !== 'properties' || ( $registry[ $key ]['type'] ?? null ) !== 'list' ) {
					continue;
				}
				if ( isset( $entry['type'] ) ) {
					// A declared override (item.powers) is deliberately exempt - that's the point of it.
					continue;
				}
				$this->assertSame(
					'trait_list',
					$schema[ $entry['property'] ] ?? null,
					"{$inventory}.{$key}: registry type is 'list' with no override, so its property must be schema type 'trait_list'"
				);
			}
		}
	}

	/**
	 * item.powers is qkdata's one key whose registry type ('list') its real
	 * storage cannot hold (ItemClass.cls:131 returns a String) - the
	 * override that keeps it out of the assertion above must actually be
	 * present, not merely assumed exempt.
	 */
	public function test_item_powers_carries_the_field_type_override(): void {
		$entry = Field_Registry::inventory( 'item' )['fields']['powers'];
		$this->assertSame( 'field', $entry['type'] );
		$this->assertSame( 'field', Field_Registry::type_for( 'powers', 'item' ) );
		$this->assertSame( 'list', Field_Registry::type_for( 'powers' ), 'the char inventory has no "powers" mapped, and the registry\'s own type for it is unaffected by the item override' );
	}

	/**
	 * atomic is true for exactly loc.links and rote.spheres - transcribed
	 * from LocationClass.cls:301 and RoteClass.cls:255 - and explicitly
	 * false (not merely absent) for every item list, per ItemClass.cls:
	 * 312-315.
	 */
	public function test_atomic_matches_the_vb6_ground_truth_exactly(): void {
		$this->assertTrue( Field_Registry::inventory( 'loc' )['fields']['links']['atomic'] );
		$this->assertTrue( Field_Registry::inventory( 'rote' )['fields']['spheres']['atomic'] );

		foreach ( [ 'abilities', 'negatives', 'availability' ] as $item_list_key ) {
			$this->assertFalse(
				Field_Registry::inventory( 'item' )['fields'][ $item_list_key ]['atomic'],
				"item.{$item_list_key} must be explicitly atomic=false (ItemClass.cls:312-315), not merely absent"
			);
		}
	}

	/**
	 * The two known key collisions this feature exists to prove a flat map
	 * cannot express: 'notes' means the description column for a world
	 * object (never the notes column, which be_world_objects does not
	 * have), and 'type' means a different property per inventory.
	 */
	public function test_the_documented_key_collisions_resolve_as_designed(): void {
		$this->assertSame( 'description', Field_Registry::inventory( 'item' )['fields']['notes']['column'] );
		$this->assertSame( 'description', Field_Registry::inventory( 'loc' )['fields']['notes']['column'] );

		$this->assertSame( 'item_type', Field_Registry::inventory( 'item' )['fields']['type']['property'] );
		$this->assertSame( 'location_type', Field_Registry::inventory( 'loc' )['fields']['type']['property'] );
	}

	/**
	 * A rote's description is never the be_world_objects.description column
	 * (Import_Controller.php never writes it for a rote) - it must read the
	 * properties.description key instead, per §6.5.
	 */
	public function test_rote_description_reads_the_property_not_the_column(): void {
		$entry = Field_Registry::inventory( 'rote' )['fields']['description'];
		$this->assertSame( 'properties', $entry['source'] );
		$this->assertSame( 'description', $entry['property'] );
	}

	// -------------------------------------------------------------------------
	// resolve_value() against hand-built world-object rows - no database, the
	// properties/column/derived arms never touch one for a non-char inventory.
	// -------------------------------------------------------------------------

	private function item_row( array $properties = [] ): object {
		return (object) [
			'name'        => 'Test Fetish',
			'description' => 'A carved bone whistle.',
			'properties'  => array_merge( [ 'item_type' => 'Fetish', 'level' => 3 ], $properties ),
		];
	}

	public function test_resolve_value_reads_a_column_on_a_world_object_row(): void {
		$result = Query_Engine::resolve_value( $this->item_row(), 'notes', 'item' );
		$this->assertSame( 'A carved bone whistle.', $result['value'] );
	}

	public function test_resolve_value_reads_a_property_on_a_world_object_row(): void {
		$result = Query_Engine::resolve_value( $this->item_row(), 'type', 'item' );
		$this->assertSame( 'Fetish', $result['value'] );
		$this->assertSame( 'field', $result['type'] );
	}

	public function test_resolve_value_returns_a_trait_list_property_unnormalized(): void {
		$held   = [ [ 'name' => 'Fortitude', 'count' => 2 ] ];
		$result = Query_Engine::resolve_value( $this->item_row( [ 'abilities' => $held ] ), 'abilities', 'item' );
		$this->assertSame( $held, $result['value'], 'a trait_list property is already {name,count,note?} - no normalize_list() reshaping applies to it' );
	}

	public function test_resolve_value_returns_null_not_empty_string_for_an_absent_property(): void {
		$result = Query_Engine::resolve_value( $this->item_row(), 'bonus', 'item' );
		$this->assertNull( $result['value'] );
	}

	public function test_resolve_value_reports_atomic_true_for_links_and_spheres(): void {
		$loc_result  = Query_Engine::resolve_value( (object) [ 'name' => 'Test Loc', 'description' => '', 'properties' => [ 'links' => [] ] ], 'links', 'loc' );
		$rote_result = Query_Engine::resolve_value( (object) [ 'name' => 'Test Rote', 'properties' => [ 'spheres' => [] ] ], 'spheres', 'rote' );

		$this->assertTrue( $loc_result['atomic'] );
		$this->assertTrue( $rote_result['atomic'] );
	}

	public function test_resolve_value_reports_atomic_false_for_item_abilities(): void {
		$result = Query_Engine::resolve_value( $this->item_row( [ 'abilities' => [] ] ), 'abilities', 'item' );
		$this->assertFalse( $result['atomic'] );
	}

	private static function known_world_object_columns(): array {
		// Mirrors the be_world_objects CREATE TABLE in Database\Schema::install().
		return [
			'id', 'game_id', 'object_type', 'name', 'description', 'rarity', 'cost',
			'limitations', 'properties', 'created_by', 'created_at', 'updated_at',
		];
	}
}
