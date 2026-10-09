#!/usr/bin/env php
<?php
/**
 * Pulls every Character Bylaw rule from council.owbn.net's own public REST API and writes it to a plain JSON file:
 * `{ "fetched_at": "...", "rules": [ {clause_id, path, subject, pc, npc, coordinators, modified}, ... ] }`.
 *
 * Usage: php tools/bylaws/extract.php [output-path]
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../../beyond-elysium/includes/Services/Bylaw_Source.php';

use BeyondElysium\Services\Bylaw_Source;

const COUNCIL_ENDPOINT = 'https://council.owbn.net/wp-json/wp/v2/bylaw_clause';
const PER_PAGE          = 100;

function fetch_page( int $page ): array {
	$url = COUNCIL_ENDPOINT . '?per_page=' . PER_PAGE . '&page=' . $page
		. '&_fields=id,slug,link,content,modified';
	$ch  = curl_init( $url );
	curl_setopt_array( $ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_USERAGENT      => 'BeyondElysium bylaw extractor',
	] );
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( $body === false || $code !== 200 ) {
		fwrite( STDERR, "extract.php: page {$page} failed (HTTP {$code})\n" );
		exit( 1 );
	}

	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		fwrite( STDERR, "extract.php: page {$page} did not decode as JSON\n" );
		exit( 1 );
	}

	return $decoded;
}

$all_clauses = [];
$page        = 1;
while ( true ) {
	$batch = fetch_page( $page );
	if ( $batch === [] ) {
		break;
	}
	$all_clauses = array_merge( $all_clauses, $batch );
	fwrite( STDERR, "extract.php: page {$page}, " . count( $batch ) . " clauses (" . count( $all_clauses ) . " total)\n" );
	if ( count( $batch ) < PER_PAGE ) {
		break;
	}
	++$page;
}

$rules = Bylaw_Source::build_rules( $all_clauses );
usort( $rules, static fn( $a, $b ) => strnatcasecmp( $a['path'], $b['path'] ) );

$output = [
	'fetched_at'    => gmdate( 'c' ),
	'clauses_seen'  => count( $all_clauses ),
	'rules'         => $rules,
];

$path = $argv[1] ?? __DIR__ . '/extracted-rules.json';
$json = json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
if ( $json === false ) {
	fwrite( STDERR, 'extract.php: json_encode failed - ' . json_last_error_msg() . "\n" );
	exit( 1 );
}
file_put_contents( $path, $json . "\n" );

fwrite( STDERR, "extract.php: " . count( $all_clauses ) . " clauses seen, " . count( $rules ) . " Character Bylaw rules written to {$path}\n" );
