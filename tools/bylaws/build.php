#!/usr/bin/env php
<?php
/**
 * Combines an extracted rules file and the reviewed `attachments.json` into the shipped
 * `data/bylaws/owbn-character-bylaws.json`. Refuses an attachment naming a clause, entry family or entry name the
 * inputs don't have - nothing is written on a refusal.
 *
 * Usage: php tools/bylaws/build.php <extracted-rules.json> <attachments.json> [output-path]
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../../beyond-elysium/includes/Services/Catalog_Validator.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Cost_Engine.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/In_Type.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Creation_Tally.php';
require_once __DIR__ . '/../../beyond-elysium/includes/Services/Catalog_Reader.php';
require_once __DIR__ . '/catalog-families.php';

function fail( string $message ): void {
	fwrite( STDERR, "build.php: {$message}\n" );
	exit( 1 );
}

$rules_path       = $argv[1] ?? null;
$attachments_path = $argv[2] ?? null;
$output_path      = $argv[3] ?? __DIR__ . '/../../beyond-elysium/data/bylaws/owbn-character-bylaws.json';

if ( ! $rules_path || ! $attachments_path ) {
	fail( 'usage: build.php <extracted-rules.json> <attachments.json> [output-path]' );
}

$extracted = json_decode( (string) file_get_contents( (string) $rules_path ), true );
if ( ! is_array( $extracted ) || ! isset( $extracted['rules'] ) || ! is_array( $extracted['rules'] ) ) {
	fail( "{$rules_path} is not a valid extracted-rules file" );
}

$attachments = json_decode( (string) file_get_contents( (string) $attachments_path ), true );
if ( ! is_array( $attachments ) ) {
	fail( "{$attachments_path} is not a valid attachments file" );
}

$rules_by_id = [];
foreach ( $extracted['rules'] as $rule ) {
	$rules_by_id[ (int) $rule['clause_id'] ] = $rule;
}

$names_by_family = be_bylaws_names_by_family();

foreach ( $attachments as $i => $attachment ) {
	$clause_id = (int) ( $attachment['clause_id'] ?? 0 );
	$family    = (string) ( $attachment['family'] ?? '' );
	$name      = (string) ( $attachment['name'] ?? '' );

	if ( ! isset( $rules_by_id[ $clause_id ] ) ) {
		fail( "attachments[{$i}]: clause {$clause_id} is not in {$rules_path}" );
	}
	if ( ! isset( $names_by_family[ $family ] ) ) {
		fail( "attachments[{$i}]: no catalog family \"{$family}\" (clause {$clause_id})" );
	}
	if ( ! isset( $names_by_family[ $family ][ $name ] ) ) {
		fail( "attachments[{$i}]: \"{$family}\" has no entry named \"{$name}\" (clause {$clause_id})" );
	}
}

$shipped = [
	'generated_at' => gmdate( 'c' ),
	'rules'        => $extracted['rules'],
	'attachments'  => $attachments,
];

$json = json_encode( $shipped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
if ( $json === false ) {
	fail( 'json_encode failed - ' . json_last_error_msg() );
}

if ( ! is_dir( dirname( (string) $output_path ) ) ) {
	mkdir( dirname( (string) $output_path ), 0755, true );
}
file_put_contents( (string) $output_path, $json . "\n" );

fwrite( STDERR, 'build.php: ' . count( $extracted['rules'] ) . ' rules, ' . count( $attachments ) . " attachments written to {$output_path}\n" );
