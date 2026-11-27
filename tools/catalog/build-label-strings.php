<?php
/**
 * Writes includes/Database/catalog-label-strings.php: every section label, creation-rule label, pool name, budget quota
 * label and template title the shipped catalog carries, each through translation.
 *
 * Usage: php tools/catalog/build-label-strings.php [--check]
 *
 * With --check nothing is written; the exit code says whether the committed file is what the catalog would produce.
 */

$root    = dirname( __DIR__, 2 ) . '/beyond-elysium';
$target  = $root . '/includes/Database/catalog-label-strings.php';
$words   = [];
$collect = static function ( $word ) use ( &$words ): void {
	if ( is_string( $word ) && trim( $word ) !== '' ) {
		$words[ $word ] = true;
	}
};

$walk = static function ( $node ) use ( &$walk, $collect ): void {
	if ( is_array( $node ) ) {
		if ( isset( $node['quotas'] ) && is_array( $node['quotas'] ) ) {
			foreach ( $node['quotas'] as $quota ) {
				$collect( $quota['label'] ?? null );
			}
		}
		foreach ( $node as $child ) {
			$walk( $child );
		}
	}
};

foreach ( glob( $root . '/data/catalog/stacks/*.json' ) ?: [] as $file ) {
	$document   = json_decode( (string) file_get_contents( $file ), true );
	$definition = $document['definition'] ?? [];
	foreach ( $definition['sections'] ?? [] as $section ) {
		$collect( $section['label'] ?? null );
	}
	foreach ( $definition['creation_rules']['steps'] ?? [] as $step ) {
		$collect( $step['label'] ?? null );
		$collect( $step['pool'] ?? null );
	}
	$walk( $definition['creation_rules'] ?? [] );
}

foreach ( glob( $root . '/data/catalog/templates/*.json' ) ?: [] as $file ) {
	$document = json_decode( (string) file_get_contents( $file ), true );
	foreach ( $document['definition']['sections'] ?? [] as $section ) {
		$collect( $section['title'] ?? null );
	}
}

$list = array_keys( $words );
sort( $list, SORT_STRING );

$escape = static fn( string $word ): string => "'" . addcslashes( $word, "'\\" ) . "'";

$out  = "<?php\n";
$out .= "/**\n";
$out .= " * The English names the catalog gives a section, a creation rule, a pool and a template title, each one through\n";
$out .= " * translation. Written by tools/catalog/build-label-strings.php.\n";
$out .= " *\n";
$out .= " * @return array<string,string> English name => the name in the site's language.\n";
$out .= " */\n\n";
$out .= "defined( 'ABSPATH' ) || exit;\n\n";
$out .= "return [\n\n";
foreach ( $list as $word ) {
	$out .= "\t" . $escape( $word ) . ' => __( ' . $escape( $word ) . ", 'beyond-elysium' ),\n";
}
$out .= "\n];\n";

if ( in_array( '--check', $argv, true ) ) {
	$current = is_file( $target ) ? (string) file_get_contents( $target ) : '';
	if ( $current === $out ) {
		echo count( $list ) . " names, file is current\n";
		exit( 0 );
	}
	fwrite( STDERR, "catalog-label-strings.php is out of date; run tools/catalog/build-label-strings.php\n" );
	exit( 1 );
}

file_put_contents( $target, $out );
echo count( $list ) . " names written\n";
