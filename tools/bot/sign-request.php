#!/usr/bin/env php
<?php
/**
 * Makes a signed call to Beyond Elysium's bot routes from the command line, the way a bot would, for the browser
 * pass and the pre-deploy traces until the bot itself exists.
 *
 * Usage:
 *   php tools/bot/sign-request.php --site=URL --connection=ID --secret=BASE64URL --method=METHOD --route=ROUTE
 *     [--user=DISCORD_USER_ID] [--query=a=b&c=d] [--body=JSON]
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../../beyond-elysium/includes/Core/Bot_Signing.php';

use BeyondElysium\Core\Bot_Signing;

/**
 * @param array<int,string> $argv
 * @return array<string,string>
 */
function parse_args( array $argv ): array {
	$args = [];
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( ! str_starts_with( $arg, '--' ) ) {
			continue;
		}
		$pair = substr( $arg, 2 );
		$eq   = strpos( $pair, '=' );
		if ( $eq === false ) {
			$args[ $pair ] = '';
		} else {
			$args[ substr( $pair, 0, $eq ) ] = substr( $pair, $eq + 1 );
		}
	}
	return $args;
}

$args = parse_args( $argv );

foreach ( [ 'site', 'connection', 'secret', 'method', 'route' ] as $required ) {
	if ( ! isset( $args[ $required ] ) || $args[ $required ] === '' ) {
		fwrite( STDERR, "sign-request.php: --{$required} is required\n" );
		exit( 1 );
	}
}

$site            = rtrim( $args['site'], '/' );
$connection_id   = $args['connection'];
$secret          = Bot_Signing::base64url_decode( $args['secret'] );
$method          = strtoupper( $args['method'] );
$route           = $args['route'];
$discord_user_id = $args['user'] ?? '';
$body            = $args['body'] ?? '';

parse_str( $args['query'] ?? '', $query );
/** @var array<string,string> $query */

$canonical_query = Bot_Signing::canonical_query( $query );
if ( $canonical_query === null ) {
	fwrite( STDERR, "sign-request.php: an array-valued query parameter is refused\n" );
	exit( 1 );
}

$timestamp = (string) time();
$nonce     = bin2hex( random_bytes( 16 ) );

$canonical = Bot_Signing::canonical_string(
	$method,
	$route,
	$canonical_query,
	$timestamp,
	$nonce,
	$connection_id,
	$discord_user_id,
	$body
);

$signature = Bot_Signing::sign( $canonical, (string) $secret );

$url = $site . $route;
if ( $canonical_query !== '' ) {
	$url .= '?' . $canonical_query;
}

$headers = [
	'X-BE-Bot-Connection: ' . $connection_id,
	'X-BE-Bot-Timestamp: ' . $timestamp,
	'X-BE-Bot-Nonce: ' . $nonce,
	'X-BE-Bot-Signature: ' . $signature,
];
if ( $discord_user_id !== '' ) {
	$headers[] = 'X-BE-Bot-User: ' . $discord_user_id;
}
if ( $body !== '' ) {
	$headers[] = 'Content-Type: application/json';
}

$ch = curl_init( $url );
curl_setopt_array( $ch, [
	CURLOPT_CUSTOMREQUEST  => $method,
	CURLOPT_HTTPHEADER     => $headers,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HEADER         => false,
] );
if ( $body !== '' ) {
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
}

$response = curl_exec( $ch );
$status   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
$error    = curl_error( $ch );
curl_close( $ch );

if ( $response === false ) {
	fwrite( STDERR, "sign-request.php: request failed: {$error}\n" );
	exit( 1 );
}

fwrite( STDOUT, "HTTP {$status}\n" );
$decoded = json_decode( (string) $response, true );
fwrite( STDOUT, $decoded !== null ? json_encode( $decoded, JSON_PRETTY_PRINT ) . "\n" : $response . "\n" );

exit( $status >= 200 && $status < 300 ? 0 : 1 );
