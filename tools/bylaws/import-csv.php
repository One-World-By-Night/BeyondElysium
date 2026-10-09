#!/usr/bin/env php
<?php
/**
 * Reads a reviewed CSV (the shape `export-csv.php` writes) back into an `attachments.json`-shaped array. A row
 * with both `family` and `name` blank means "no attachment" and is skipped; any other row needs both filled and
 * is checked against the real catalog and the real rule set, the same way `build.php` checks `attachments.json`
 * itself. `count_from`/`count_to` become a `count_range`; `levels` (semicolon-separated numbers) and `elder_plus`
 * (`yes` or blank) become `levels`/`picks`, the tiered_power-level qualifiers `Bylaws::rules_for()` reads.
 * Reports what would change and writes nothing unless `--apply` is given and every row is valid.
 *
 * Usage: php tools/bylaws/import-csv.php <reviewed.csv> <extracted-rules.json> <attachments.json> [output.json] [--apply]
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/catalog-families.php';

function fail( string $message ): void {
	fwrite( STDERR, "import-csv.php: {$message}\n" );
	exit( 1 );
}

$apply      = false;
$positional = [];
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( $arg === '--apply' ) {
		$apply = true;
		continue;
	}
	$positional[] = $arg;
}
[ $csv_path, $rules_path, $attachments_path, $output_path ] = array_pad( $positional, 4, null );
$output_path = $output_path ?? $attachments_path;

if ( ! $csv_path || ! $rules_path || ! $attachments_path ) {
	fail( 'usage: import-csv.php <reviewed.csv> <extracted-rules.json> <attachments.json> [output.json] [--apply]' );
}

$extracted = json_decode( (string) file_get_contents( (string) $rules_path ), true );
if ( ! is_array( $extracted ) || ! isset( $extracted['rules'] ) || ! is_array( $extracted['rules'] ) ) {
	fail( "{$rules_path} is not a valid extracted-rules file" );
}
$rules_by_id = [];
foreach ( $extracted['rules'] as $rule ) {
	$rules_by_id[ (int) $rule['clause_id'] ] = true;
}

$current_attachments = json_decode( (string) file_get_contents( (string) $attachments_path ), true );
if ( ! is_array( $current_attachments ) ) {
	fail( "{$attachments_path} is not a valid attachments file" );
}

$names_by_family = be_bylaws_names_by_family();

$handle = fopen( (string) $csv_path, 'r' );
if ( $handle === false ) {
	fail( "could not open {$csv_path}" );
}
$header = fgetcsv( $handle );
if ( $header === false ) {
	fail( "{$csv_path} has no header row" );
}

function signature( array $attachment ): string {
	$range  = is_array( $attachment['count_range'] ?? null ) ? $attachment['count_range'] : [];
	$levels = is_array( $attachment['levels'] ?? null ) ? $attachment['levels'] : [];
	$picks  = is_array( $attachment['picks'] ?? null ) ? $attachment['picks'] : [];
	return implode( '|', [
		(string) ( $attachment['clause_id'] ?? '' ),
		(string) ( $attachment['family'] ?? '' ),
		(string) ( $attachment['name'] ?? '' ),
		(string) ( $range['from'] ?? '' ),
		(string) ( $range['to'] ?? '' ),
		implode( ',', $levels ),
		implode( ',', $picks ),
	] );
}

$new_attachments = [];
$seen_signatures  = [];
$invalid          = [];
$row_number       = 1;
while ( ( $row = fgetcsv( $handle ) ) !== false ) {
	++$row_number;
	if ( count( $row ) === 1 && trim( (string) $row[0] ) === '' ) {
		continue;
	}
	$by_column = array_combine( $header, array_pad( $row, count( $header ), '' ) );
	if ( $by_column === false ) {
		$invalid[] = "row {$row_number}: column count doesn't match the header";
		continue;
	}

	$clause_id  = (int) trim( (string) ( $by_column['clause_id'] ?? '' ) );
	$family     = trim( (string) ( $by_column['family'] ?? '' ) );
	$name       = trim( (string) ( $by_column['name'] ?? '' ) );
	$count_from = trim( (string) ( $by_column['count_from'] ?? '' ) );
	$count_to   = trim( (string) ( $by_column['count_to'] ?? '' ) );
	$levels_raw = trim( (string) ( $by_column['levels'] ?? '' ) );
	$elder_plus = trim( (string) ( $by_column['elder_plus'] ?? '' ) ) !== '';

	if ( $family === '' && $name === '' ) {
		continue;
	}
	if ( $family === '' || $name === '' ) {
		$invalid[] = "row {$row_number}: clause {$clause_id} - family and name must both be filled, or both left blank";
		continue;
	}
	if ( ! isset( $rules_by_id[ $clause_id ] ) ) {
		$invalid[] = "row {$row_number}: clause {$clause_id} is not in {$rules_path}";
		continue;
	}
	if ( ! isset( $names_by_family[ $family ] ) ) {
		$invalid[] = "row {$row_number}: clause {$clause_id} - no catalog family \"{$family}\"";
		continue;
	}
	if ( ! isset( $names_by_family[ $family ][ $name ] ) ) {
		$invalid[] = "row {$row_number}: clause {$clause_id} - \"{$family}\" has no entry named \"{$name}\"";
		continue;
	}

	$attachment = [ 'clause_id' => $clause_id, 'family' => $family, 'name' => $name ];
	if ( $levels_raw !== '' ) {
		$attachment['levels'] = array_values( array_filter( array_map( 'intval', explode( ';', $levels_raw ) ) ) );
	}
	if ( $elder_plus ) {
		$attachment['picks'] = [ 'all' ];
	}
	if ( $count_from !== '' || $count_to !== '' ) {
		$attachment['count_range'] = [
			'from' => $count_from !== '' ? (int) $count_from : 0,
			'to'   => $count_to !== '' ? (int) $count_to : PHP_INT_MAX,
		];
	}

	$sig = signature( $attachment );
	if ( isset( $seen_signatures[ $sig ] ) ) {
		continue;
	}
	$seen_signatures[ $sig ] = true;
	$new_attachments[]       = $attachment;
}
fclose( $handle );

$old_signatures = array_map( 'signature', $current_attachments );
$new_signatures = array_map( 'signature', $new_attachments );

$added     = array_values( array_filter( $new_attachments, static fn( $a ) => ! in_array( signature( $a ), $old_signatures, true ) ) );
$removed   = array_values( array_filter( $current_attachments, static fn( $a ) => ! in_array( signature( $a ), $new_signatures, true ) ) );
$unchanged = count( $new_attachments ) - count( $added );

$sample = static fn( array $rows ) => array_slice( array_map(
	static fn( $a ) => sprintf( 'clause %d: %s / %s', (int) ( $a['clause_id'] ?? 0 ), (string) ( $a['family'] ?? '' ), (string) ( $a['name'] ?? '' ) ),
	$rows
), 0, 5 );

fwrite( STDERR, "import-csv.php: {$csv_path}\n" );
fwrite( STDERR, '  rows read: ' . ( $row_number - 1 ) . "\n" );
fwrite( STDERR, '  attachments: ' . count( $current_attachments ) . " current -> " . count( $new_attachments ) . " proposed\n" );
fwrite( STDERR, '  added: ' . count( $added ) . "\n" );
foreach ( $sample( $added ) as $line ) {
	fwrite( STDERR, "    + {$line}\n" );
}
fwrite( STDERR, '  removed: ' . count( $removed ) . "\n" );
foreach ( $sample( $removed ) as $line ) {
	fwrite( STDERR, "    - {$line}\n" );
}
fwrite( STDERR, "  unchanged: {$unchanged}\n" );
fwrite( STDERR, '  invalid: ' . count( $invalid ) . "\n" );
foreach ( $invalid as $line ) {
	fwrite( STDERR, "    ! {$line}\n" );
}

if ( ! $apply ) {
	fwrite( STDERR, "import-csv.php: dry run - nothing written. Pass --apply to write {$output_path}.\n" );
	exit( $invalid === [] ? 0 : 1 );
}

if ( $invalid !== [] ) {
	fail( 'refusing to write - fix the invalid rows above first' );
}

$json = json_encode( $new_attachments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( $json === false ) {
	fail( 'json_encode failed - ' . json_last_error_msg() );
}
file_put_contents( (string) $output_path, $json . "\n" );
fwrite( STDERR, 'import-csv.php: ' . count( $new_attachments ) . " attachments written to {$output_path}\n" );
