<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Field_Registry;
use PHPUnit\Framework\TestCase;

/**
 * A Grapevine character key whose data the catalog now holds is never left unmapped, so the Query Tool can search it.
 */
class FieldMapCatalogCoverageTest extends TestCase {

	/**
	 * Keys that share a name with a catalog list or field and stay unmapped on purpose, each with the reason.
	 *
	 * @var array<string,string>
	 */
	private const LEFT_UNMAPPED = [
		'equipment' => "Grapevine's equipment is item records; the quick NPC's Equipment box is free text on one template.",
		'tempers'   => "Grapevine's tempers are a list; Various's tempers are resource pools.",
		'powers'    => "Each creature type keeps its powers in its own families; the Various list alone would hide every Discipline and Gift holder.",
	];

	/** @return string[] The creature type slugs, longest first. */
	private function stack_slugs(): array {
		$slugs = [];
		foreach ( glob( __DIR__ . '/../../beyond-elysium/data/catalog/stacks/*.json' ) ?: [] as $file ) {
			$slugs[] = basename( $file, '.json' );
		}
		usort( $slugs, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		return $slugs;
	}

	private static function key_of( string $name ): string {
		return (string) preg_replace( '/[^a-z]/', '', strtolower( $name ) );
	}

	/**
	 * Every character key a catalog block or identity field is named after, with where it lives.
	 *
	 * @return array<string,string[]>
	 */
	private function catalog_names(): array {
		$stacks = $this->stack_slugs();
		$found  = [];
		foreach ( glob( __DIR__ . '/../../beyond-elysium/data/catalog/blocks/*.json' ) ?: [] as $file ) {
			$slug   = basename( $file, '.json' );
			$family = $slug;
			foreach ( $stacks as $stack ) {
				if ( str_starts_with( $slug, $stack . '-' ) ) {
					$family = substr( $slug, strlen( $stack ) + 1 );
					break;
				}
			}
			$found[ self::key_of( $family ) ][] = $slug;

			$data = json_decode( (string) file_get_contents( $file ), true );
			foreach ( $data['definition']['fields'] ?? [] as $field ) {
				$found[ self::key_of( (string) ( $field['name'] ?? '' ) ) ][] = $slug . '.' . ( $field['name'] ?? '' );
			}
		}
		return $found;
	}

	public function test_no_key_named_like_a_catalog_list_or_field_is_left_unmapped(): void {
		$keys   = Field_Registry::for_inventory( 'char' );
		$map    = Field_Registry::map();
		$names  = $this->catalog_names();
		$stale  = [];
		foreach ( array_keys( $keys ) as $key ) {
			$unmapped = ( $map[ $key ]['source'] ?? 'unmapped' ) === 'unmapped';
			if ( $unmapped && isset( $names[ $key ] ) && ! isset( self::LEFT_UNMAPPED[ $key ] ) ) {
				$stale[ $key ] = $names[ $key ];
			}
		}

		ksort( $stale );
		$this->assertSame(
			[],
			array_map( static fn( array $where ): string => implode( ', ', array_unique( $where ) ), $stale ),
			'Grapevine keys the catalog holds data for but field-map.php leaves unmapped'
		);
	}

	public function test_a_key_left_unmapped_on_purpose_is_still_unmapped_and_still_matches_a_catalog_name(): void {
		$map   = Field_Registry::map();
		$names = $this->catalog_names();
		foreach ( self::LEFT_UNMAPPED as $key => $reason ) {
			$this->assertSame( 'unmapped', $map[ $key ]['source'] ?? null, "{$key} is mapped now; drop it from the list ({$reason})" );
			$this->assertArrayHasKey( $key, $names, "no catalog name matches {$key} any more; drop it from the list" );
		}
	}
}
