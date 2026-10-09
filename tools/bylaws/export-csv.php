#!/usr/bin/env php
<?php
/**
 * Exports every rule in an extracted-rules file as a reviewable CSV: one row per rule, times its existing
 * attachments where any are already shipped (a rule with several carries one row each). `suggested_family`/
 * `suggested_name` are a read-only exact normalized-name catalog hint, never a decision - the editable `family`/
 * `name` stay blank until a reviewer copies a suggestion across or writes their own, so an unedited round-trip
 * through `import-csv.php` ships only what was already shipped. `count_from`/`count_to` narrow a trait_list or
 * resource_pool attachment to a range (blank either side reads as unbounded); `levels` (semicolon-separated
 * numbers, e.g. `1;2`) narrows a tiered_power attachment to specific numbered rungs; `elder_plus` (`yes` or
 * blank) narrows it to an Elder-and-above pick instead. A reviewer leaves `family`/`name` blank for "no
 * attachment" and fills `reason`/`note` either way.
 *
 * Usage: php tools/bylaws/export-csv.php <extracted-rules.json> <attachments.json> [output.csv]
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/catalog-families.php';

function fail( string $message ): void {
	fwrite( STDERR, "export-csv.php: {$message}\n" );
	exit( 1 );
}

$rules_path       = $argv[1] ?? null;
$attachments_path = $argv[2] ?? null;
$output_path      = $argv[3] ?? __DIR__ . '/attachments-review.csv';

if ( ! $rules_path || ! $attachments_path ) {
	fail( 'usage: export-csv.php <extracted-rules.json> <attachments.json> [output.csv]' );
}

$extracted = json_decode( (string) file_get_contents( (string) $rules_path ), true );
if ( ! is_array( $extracted ) || ! isset( $extracted['rules'] ) || ! is_array( $extracted['rules'] ) ) {
	fail( "{$rules_path} is not a valid extracted-rules file" );
}

$attachments = json_decode( (string) file_get_contents( (string) $attachments_path ), true );
if ( ! is_array( $attachments ) ) {
	fail( "{$attachments_path} is not a valid attachments file" );
}

$attachments_by_clause = [];
foreach ( $attachments as $attachment ) {
	$attachments_by_clause[ (int) ( $attachment['clause_id'] ?? 0 ) ][] = $attachment;
}

$by_normalized = be_bylaws_catalog_by_normalized_name();

$handle = fopen( (string) $output_path, 'w' );
if ( $handle === false ) {
	fail( "could not open {$output_path} for writing" );
}

$columns = [ 'clause_id', 'path', 'subject', 'pc', 'npc', 'coordinators', 'modified', 'suggested_family', 'suggested_name', 'family', 'name', 'count_from', 'count_to', 'levels', 'elder_plus', 'reason', 'note' ];
fputcsv( $handle, $columns );

$rows      = 0;
$suggested = 0;
foreach ( $extracted['rules'] as $rule ) {
	$clause_id = (int) ( $rule['clause_id'] ?? 0 );
	$subject   = (string) ( $rule['subject'] ?? '' );
	$context   = [
		$clause_id,
		(string) ( $rule['path'] ?? '' ),
		$subject,
		(string) ( $rule['pc'] ?? '' ),
		(string) ( $rule['npc'] ?? '' ),
		implode( '; ', (array) ( $rule['coordinators'] ?? [] ) ),
		(string) ( $rule['modified'] ?? '' ),
	];

	$candidates        = $by_normalized[ be_bylaws_normalize( $subject ) ] ?? [];
	$suggested_family  = '';
	$suggested_name    = '';
	if ( $candidates !== [] ) {
		$suggested_family = (string) array_key_first( $candidates );
		$suggested_name   = (string) $candidates[ $suggested_family ][0]['name'];
		++$suggested;
	}

	$existing = $attachments_by_clause[ $clause_id ] ?? [];
	if ( $existing !== [] ) {
		foreach ( $existing as $attachment ) {
			$range  = is_array( $attachment['count_range'] ?? null ) ? $attachment['count_range'] : [];
			$levels = is_array( $attachment['levels'] ?? null ) ? $attachment['levels'] : [];
			$picks  = is_array( $attachment['picks'] ?? null ) ? $attachment['picks'] : [];
			fputcsv( $handle, array_merge( $context, [
				$suggested_family,
				$suggested_name,
				(string) ( $attachment['family'] ?? '' ),
				(string) ( $attachment['name'] ?? '' ),
				isset( $range['from'] ) ? (string) $range['from'] : '',
				isset( $range['to'] ) ? (string) $range['to'] : '',
				implode( ';', $levels ),
				in_array( 'all', $picks, true ) ? 'yes' : '',
				'',
				(string) ( $attachment['note'] ?? '' ),
			] ) );
			++$rows;
		}
		continue;
	}

	fputcsv( $handle, array_merge( $context, [ $suggested_family, $suggested_name, '', '', '', '', '', '', '', '' ] ) );
	++$rows;
}

fclose( $handle );
fwrite( STDERR, "export-csv.php: {$rows} rows ({$suggested} suggested, unreviewed) written to {$output_path}\n" );
