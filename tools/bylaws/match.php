#!/usr/bin/env php
<?php
/**
 * Proposes catalog attachments for every extracted bylaw rule: an exact (case/whitespace-normalized) match between
 * a rule's subject and a real catalog trait_list item's name, across every creature's own copy of a list. Prints
 * the sure matches as a ready-to-review `attachments.json` shape; everything else is left for the Phase 8 curated
 * pass, named here as a count only.
 *
 * Usage: php tools/bylaws/match.php <extracted-rules.json>
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../../beyond-elysium/includes/Services/Catalog_Validator.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Cost_Engine.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/In_Type.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Creation_Tally.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Catalog_Reader.php';

use BeyondElysium\Services\Catalog_Reader;

function normalize( string $text ): string {
	$text = strtolower( $text );
	$text = str_replace( [ '’', '‘' ], "'", $text );
	$text = preg_replace( '/[^a-z0-9\' ]+/', ' ', $text ) ?? $text;
	$text = preg_replace( '/\s+/', ' ', $text ) ?? $text;
	return trim( $text );
}

/**
 * A block slug's family: the part after its creature-stack prefix is dropped, or the whole slug when it carries
 * none of the known prefixes (a shared, not-per-creature, list such as `met-backgrounds`).
 */
function family_of( string $block_slug, array $known_prefixes ): string {
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

$rules_path = $argv[1] ?? __DIR__ . '/extracted-rules.json';
$extracted  = json_decode( (string) file_get_contents( $rules_path ), true );
if ( ! is_array( $extracted ) || ! isset( $extracted['rules'] ) ) {
	fwrite( STDERR, "match.php: couldn't read rules from {$rules_path}\n" );
	exit( 1 );
}

$stack_root     = __DIR__ . '/../../beyond-elysium/data/catalog/stacks';
$known_prefixes = [];
foreach ( glob( $stack_root . '/*.json' ) ?: [] as $file ) {
	$known_prefixes[] = pathinfo( $file, PATHINFO_FILENAME );
}

$catalog = Catalog_Reader::load( __DIR__ . '/../../beyond-elysium/data/catalog' );

// Every trait_list item's name, indexed by its normalized form -> every {family, name, block_slug} it's held as.
$by_normalized = [];
foreach ( $catalog['blocks'] as $block_slug => $block ) {
	if ( ( $block['section_type'] ?? '' ) !== 'trait_list' ) {
		continue;
	}
	$family = family_of( $block_slug, $known_prefixes );
	foreach ( (array) ( $block['definition']['items'] ?? [] ) as $item ) {
		$name = (string) ( $item['name'] ?? '' );
		if ( $name === '' ) {
			continue;
		}
		$key = normalize( $name );
		$by_normalized[ $key ][ $family ][] = [ 'name' => $name, 'block_slug' => $block_slug ];
	}
}

$sure      = [];
$unmatched = 0;
foreach ( $extracted['rules'] as $rule ) {
	$key = normalize( (string) $rule['subject'] );
	if ( ! isset( $by_normalized[ $key ] ) ) {
		++$unmatched;
		continue;
	}
	foreach ( $by_normalized[ $key ] as $family => $entries ) {
		$sure[] = [
			'clause_id'    => $rule['clause_id'],
			'path'         => $rule['path'],
			'subject'      => $rule['subject'],
			'family'       => $family,
			'name'         => $entries[0]['name'],
			'matched_from' => count( $entries ) . ' block(s): ' . implode( ', ', array_column( $entries, 'block_slug' ) ),
		];
	}
}

fwrite( STDERR, 'match.php: ' . count( $extracted['rules'] ) . " rules, " . count( $sure ) . " sure (exact) matches, {$unmatched} unmatched\n" );

echo json_encode( $sure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
