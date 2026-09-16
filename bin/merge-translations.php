<?php
/**
 * Merges every `wp i18n make-json` output for one locale into the two files
 * WordPress's own `load_script_textdomain()` looks for FIRST, before it ever
 * falls back to hashing a script's registered `src` against the per-source-file
 * catalog `make-json` produces by default: `{domain}-{locale}-{handle}.json`.
 *
 * `make-json` splits translations one file per PHP/JS source file the string was
 * found in (each hashed by that file's own path), matching how WordPress core's
 * own block-editor registers one script handle per file. This plugin instead
 * loads almost everything through two lazily-`import()`-ed webpack bundles
 * (`beyond-elysium`, `beyond-elysium-admin`) that are never registered as their
 * own per-chunk WordPress script handles - `wp_set_script_translations()` can
 * only ever attach the ONE hashed file matching the *registered* handle's own
 * entry script, so every string living in any other chunk silently never
 * reaches the browser. Found live, checking F-017's "look at real screens"
 * step: System Config's own tab labels (Games, Schema Blocks, ...) stayed in
 * English under pt_BR while strings baked into the shared main chunk (the
 * AI Assist button, the credits footer) translated correctly.
 *
 * The fix needs no webpack or runtime change: writing ALL strings out again
 * under the plain handle-named filename makes WordPress's own first-choice
 * lookup find the complete catalog directly, with the hashed per-file catalogs
 * left in place underneath as the (now unreachable, harmless) default output.
 *
 * Usage: php bin/merge-translations.php <languages-dir> <domain> <handle> [<handle> ...]
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
