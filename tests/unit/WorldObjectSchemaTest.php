<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Models\World_Object;
use PHPUnit\Framework\TestCase;

/**
 * Each `object_type`'s property schema accepts its own properties, rejects another
 * type's, and rejects unknown keys entirely (workflow-0.7.md Step 1f) - a typo'd
 * property that silently persists is a bug that surfaces months later in a report.
 *
 * @see BE_PROCESS/workflow-0.7.md Step 1
 */
class WorldObjectSchemaTest extends TestCase {

	public function test_all_four_types_are_registered(): void {
		$this->assertEqualsCanonicalizing( [ 'item', 'location', 'rote', 'boon' ], World_Object::valid_types() );
	}

	public function test_item_accepts_its_own_properties(): void {
		$error = World_Object::validate_properties( 'item', [
			'item_type'  => 'Weapon',
			'level'      => 3,
			'tempers'    => [ [ 'name' => 'Sharp', 'count' => 1 ] ],
		] );
		$this->assertNull( $error );
	}

	public function test_location_accepts_its_own_properties(): void {
		$error = World_Object::validate_properties( 'location', [
			'location_type' => 'Haven',
			'security'      => 'Warded',
			'gauntlet'      => 2,
			'links'         => [],
		] );
		$this->assertNull( $error );
	}

	public function test_rote_accepts_its_own_properties(): void {
		$error = World_Object::validate_properties( 'rote', [
			'level'   => 2,
			'spheres' => [ [ 'name' => 'Forces', 'count' => 2 ] ],
		] );
		$this->assertNull( $error );
	}

	public function test_boon_accepts_its_own_properties(): void {
		$error = World_Object::validate_properties( 'boon', [
			'boon_level' => 'major',
			'boon_date'  => '2026-02-01',
			'terms'      => 'Granted for defense of the Esbat.',
			'status'     => 'outstanding',
		] );
		$this->assertNull( $error );
	}

	public function test_another_types_properties_are_rejected(): void {
		// 'item_type' is not a location property.
		$error = World_Object::validate_properties( 'location', [ 'item_type' => 'Weapon' ] );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'item_type', $error );
	}

	public function test_unknown_property_key_is_rejected(): void {
		$error = World_Object::validate_properties( 'item', [ 'made_up_field' => 'x' ] );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'made_up_field', $error );
	}

	public function test_unknown_object_type_is_rejected(): void {
		$error = World_Object::validate_properties( 'vehicle', [] );
		$this->assertNotNull( $error );
	}

	public function test_int_property_rejects_non_numeric_value(): void {
		$error = World_Object::validate_properties( 'item', [ 'level' => 'high' ] );
		$this->assertNotNull( $error );
	}

	public function test_trait_list_property_rejects_non_array_value(): void {
		$error = World_Object::validate_properties( 'item', [ 'tempers' => 'Sharp' ] );
		$this->assertNotNull( $error );
	}

	public function test_date_property_rejects_unparsable_value(): void {
		$error = World_Object::validate_properties( 'boon', [ 'boon_date' => 'not-a-date' ] );
		$this->assertNotNull( $error );
	}

	public function test_empty_properties_are_always_valid(): void {
		$this->assertNull( World_Object::validate_properties( 'item', [] ) );
	}
}
