<?php
/**
 * Creates or updates one BetterDocs "docs" post per entry in /tmp/help-docs.json (produced by bin/sync-help-docs.js),
 * all under a "Help" doc_category created on first run; idempotent by slug.
 */

$json_path = '/tmp/help-docs.json';
if ( ! file_exists( $json_path ) ) {
	fwrite( STDERR, "Missing $json_path - scp it over first (bin/sync-help-docs.js's own output).\n" );
	exit( 1 );
}

$docs = json_decode( file_get_contents( $json_path ), true );
if ( ! is_array( $docs ) ) {
	fwrite( STDERR, "Could not parse $json_path as JSON.\n" );
	exit( 1 );
}

$author_id = 2; // be-admin, the same author the 4 existing top-level guide docs use.

$category = get_term_by( 'slug', 'help', 'doc_category' );
if ( ! $category ) {
	$result = wp_insert_term( 'Help', 'doc_category', [ 'slug' => 'help' ] );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, 'Failed to create Help category: ' . $result->get_error_message() . "\n" );
		exit( 1 );
	}
	$category_id = $result['term_id'];
	echo "Created 'Help' doc_category (term_id $category_id)\n";
} else {
	$category_id = $category->term_id;
}

$created = 0;
$updated = 0;
$failed  = [];

foreach ( $docs as $doc ) {
	$slug    = $doc['slug'];
	$title   = $doc['title'] ?? $slug;
	$content = $doc['content'] ?? '';

	$existing = get_page_by_path( $slug, OBJECT, 'docs' );

	$postarr = [
		'post_type'    => 'docs',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_author'  => $author_id,
	];

	if ( $existing ) {
		$postarr['ID'] = $existing->ID;
		$post_id       = wp_update_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			$failed[] = "$slug: " . $post_id->get_error_message();
			continue;
		}
		++$updated;
	} else {
		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			$failed[] = "$slug: " . $post_id->get_error_message();
			continue;
		}
		++$created;
	}

	wp_set_object_terms( $post_id, [ $category_id ], 'doc_category' );
}

echo "Created: $created, Updated: $updated, Failed: " . count( $failed ) . "\n";
foreach ( $failed as $f ) {
	echo "  FAILED: $f\n";
}
