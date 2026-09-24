<?php
/**
 * Merges every `wp i18n make-json` output for one locale into the two files WordPress's own
 * `load_script_textdomain()` looks for first.
 */

$dir     = $argv[1] ?? null;
$domain  = $argv[2] ?? null;
$handles = array_slice( $argv, 3 );

if ( ! $dir || ! $domain || ! $handles ) {
	fwrite( STDERR, "Usage: php bin/merge-translations.php <languages-dir> <domain> <handle> [<handle> ...]\n" );
	exit( 1 );
}

foreach ( glob( "$dir/$domain-*.po" ) as $po ) {
	$locale = basename( $po, '.po' );
	$locale = preg_replace( '/^' . preg_quote( $domain, '/' ) . '-/', '', $locale );

	$pattern = "$dir/$domain-$locale-*.json";
	$files   = array_filter( glob( $pattern ), static fn( $f ) => preg_match( '/-[0-9a-f]{32}\.json$/', $f ) );

	if ( ! $files ) {
		fwrite( STDERR, "merge-translations: no per-file JSON output found for $locale - run wp i18n make-json first\n" );
		continue;
	}

	$messages = [];
	$header   = null;
	foreach ( $files as $file ) {
		$decoded = json_decode( file_get_contents( $file ), true );
		foreach ( $decoded['locale_data']['messages'] ?? [] as $key => $value ) {
			if ( '' === $key ) {
				$header = $value;
				continue;
			}
			$messages[ $key ] = $value;
		}
	}

	$merged = [
		'translation-revision-date' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'generator'                 => 'bin/merge-translations.php',
		'domain'                    => 'messages',
		'locale_data'               => [
			'messages' => [ '' => $header ] + $messages,
		],
	];

	foreach ( $handles as $handle ) {
		$out = "$dir/$domain-$locale-$handle.json";
		file_put_contents( $out, json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		echo "wrote $out (" . count( $messages ) . " messages)\n";
	}
}
