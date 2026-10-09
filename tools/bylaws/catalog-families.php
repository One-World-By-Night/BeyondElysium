<?php
/**
 * The catalog lookups `build.php`, `export-csv.php` and `import-csv.php` each need: a strict family/name index
 * for validating an attachment, and a normalized-name index for suggesting one.
 *
 * Usage: require this file, then call be_bylaws_names_by_family() or be_bylaws_catalog_by_normalized_name().
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../../beyond-elysium/includes/Services/Catalog_Validator.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Cost_Engine.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/In_Type.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Creation_Tally.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Catalog_Reader.php';

use BeyondElysium\Services\Catalog_Reader;

/**
 * A block slug's bylaw family: its creature-stack prefix dropped, or the shared `met-` prefix dropped, or the
 * slug itself when neither prefix matches.
 *
 * @param string[] $known_prefixes
 */
function be_bylaws_family_of_block( string $block_slug, array $known_prefixes ): string {
	foreach ( $known_prefixes as $prefix ) {
		if ( str_starts_with( $block_slug, $prefix . '-' ) ) {
			return substr( $block_slug, strlen( $prefix ) + 1 );
		}
	}
	if ( str_starts_with( $block_slug, 'met-' ) ) {
		return substr( $block_slug, 4 );
	}
	return $block_slug;
}

/**
 * Every known creature-stack slug, read from the stack files on disk.
 *
 * @return string[]
 */
function be_bylaws_known_prefixes(): array {
	$stack_root     = __DIR__ . '/../../beyond-elysium/data/catalog/stacks';
	$known_prefixes = [];
	foreach ( glob( $stack_root . '/*.json' ) ?: [] as $file ) {
		$known_prefixes[] = pathinfo( $file, PATHINFO_FILENAME );
	}
	return $known_prefixes;
}

/**
 * Case/whitespace/punctuation-folded, for comparing a rule's subject against a catalog item's name.
 */
function be_bylaws_normalize( string $text ): string {
	$text = strtolower( $text );
	$text = str_replace( [ '’', '‘' ], "'", $text );
	$text = preg_replace( '/[^a-z0-9\' ]+/', ' ', $text ) ?? $text;
	$text = preg_replace( '/\s+/', ' ', $text ) ?? $text;
	return trim( $text );
}

/**
 * Every trait_list item's name, every tiered_power family's own name (a whole-power attachment, matching
 * `Bylaws::rules_for()`'s own "named none of levels/picks always matches" rule) and every resource_pool pool's
 * name, grouped by family, as a `{family: {name: true}}` lookup - what an attachment's `family`/`name` is
 * checked against.
 *
 * @return array<string,array<string,bool>>
 */
function be_bylaws_names_by_family(): array {
	$known_prefixes  = be_bylaws_known_prefixes();
	$catalog         = Catalog_Reader::load( __DIR__ . '/../../beyond-elysium/data/catalog' );
	$names_by_family = [];
	foreach ( $catalog['blocks'] as $block_slug => $block ) {
		$section_type = $block['section_type'] ?? '';
		$family       = be_bylaws_family_of_block( $block_slug, $known_prefixes );

		if ( $section_type === 'trait_list' ) {
			foreach ( (array) ( $block['definition']['items'] ?? [] ) as $item ) {
				if ( isset( $item['name'] ) ) {
					$names_by_family[ $family ][ (string) $item['name'] ] = true;
				}
			}
		} elseif ( $section_type === 'tiered_power' ) {
			foreach ( (array) ( $block['definition']['powers'] ?? [] ) as $power ) {
				if ( isset( $power['name'] ) ) {
					$names_by_family[ $family ][ (string) $power['name'] ] = true;
				}
			}
		} elseif ( $section_type === 'resource_pool' ) {
			foreach ( (array) ( $block['definition']['pools'] ?? [] ) as $pool ) {
				if ( isset( $pool['name'] ) ) {
					$names_by_family[ $family ][ (string) $pool['name'] ] = true;
				}
			}
		}
	}
	return $names_by_family;
}

/**
 * Every trait_list item's name, tiered_power family name and resource_pool pool name, with its own family,
 * indexed by the name's own normalized form - what a rule's subject is looked up against to suggest an
 * attachment.
 *
 * @return array<string,array<string,array<int,array{name:string,block_slug:string}>>>
 */
function be_bylaws_catalog_by_normalized_name(): array {
	$known_prefixes = be_bylaws_known_prefixes();
	$catalog        = Catalog_Reader::load( __DIR__ . '/../../beyond-elysium/data/catalog' );
	$by_normalized  = [];
	foreach ( $catalog['blocks'] as $block_slug => $block ) {
		$section_type = $block['section_type'] ?? '';
		$family       = be_bylaws_family_of_block( $block_slug, $known_prefixes );

		$entries = [];
		if ( $section_type === 'trait_list' ) {
			$entries = (array) ( $block['definition']['items'] ?? [] );
		} elseif ( $section_type === 'tiered_power' ) {
			$entries = (array) ( $block['definition']['powers'] ?? [] );
		} elseif ( $section_type === 'resource_pool' ) {
			$entries = (array) ( $block['definition']['pools'] ?? [] );
		}

		foreach ( $entries as $item ) {
			$name = (string) ( $item['name'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			$key = be_bylaws_normalize( $name );
			$by_normalized[ $key ][ $family ][] = [ 'name' => $name, 'block_slug' => $block_slug ];
		}
	}
	return $by_normalized;
}
