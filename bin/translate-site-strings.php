<?php
/**
 * Writes Portuguese (Brazil) translations of a site's own strings into TranslatePress's dictionary as human-reviewed
 * rows. Run it with `wp eval-file`.
 *
 *   BE_TP_STRINGS   a JSON list of {"original": "...", "translated": "..."}, default /tmp/site-strings.json. An entry
 *                   with a "domain" is a string a plugin or theme prints through WordPress's translation functions
 *                   (BetterDocs's "Listen", for one); one without is text on a page.
 *   BE_TP_DRY_RUN   set to 1 to report what would change and write nothing
 *
 * Page text TranslatePress has not seen yet is added. A plugin string it has not seen is reported and left. Page text
 * is written in one database transaction, and running the script again changes nothing.
 */

if ( ! class_exists( 'TRP_Translate_Press' ) ) {
	fwrite( STDERR, "TranslatePress is not active.\n" );
	return;
}

$language = 'pt_BR';
$settings = (array) get_option( 'trp_settings', [] );
if ( ! in_array( $language, (array) ( $settings['translation-languages'] ?? [] ), true ) ) {
	fwrite( STDERR, "TranslatePress does not translate into {$language}.\n" );
	return;
}

$file = getenv( 'BE_TP_STRINGS' ) ?: '/tmp/site-strings.json';
$list = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
if ( ! is_array( $list ) ) {
	fwrite( STDERR, "Could not read a list of strings from {$file}.\n" );
	return;
}
foreach ( $list as $i => $row ) {
	if ( ! isset( $row['original'], $row['translated'] ) || '' === trim( (string) $row['translated'] ) ) {
		fwrite( STDERR, 'Row ' . $i . " has no original or no translation.\n" );
		return;
	}
}
$plugin_strings = array_values( array_filter( $list, static fn( $row ) => ! empty( $row['domain'] ) ) );
$rows           = array_values( array_filter( $list, static fn( $row ) => empty( $row['domain'] ) ) );

$dry   = '1' === getenv( 'BE_TP_DRY_RUN' );
$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
$human = $query->get_constant_human_reviewed();

global $wpdb;

/**
 * Writes the page text rows, adding the ones TranslatePress has not seen.
 *
 * @param array<int,array<string,string>> $rows
 */
$write_page_text = static function ( array $rows ) use ( $wpdb, $query, $language, $human, $dry ): void {
	$table    = $query->get_table_name( $language );
	$existing = [];
	foreach ( array_chunk( array_column( $rows, 'original' ), 100 ) as $chunk ) {
		$marks = implode( ',', array_fill( 0, count( $chunk ), 'BINARY %s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original, translated, status, block_type FROM `{$table}` WHERE BINARY original IN ({$marks})", $chunk ), ARRAY_A ) as $row ) {
			$existing[ $row['original'] ] = $row;
		}
	}

	$insert    = [];
	$changes   = [];
	$unchanged = 0;
	foreach ( $rows as $row ) {
		$have = $existing[ $row['original'] ] ?? null;
		if ( null === $have ) {
			$insert[]  = $row['original'];
			$changes[] = $row;
		} elseif ( $have['translated'] === $row['translated'] && (int) $have['status'] === $human ) {
			++$unchanged;
		} else {
			$changes[] = $row;
		}
	}

	$updated = count( $changes ) - count( $insert );
	echo count( $rows ) . ' page texts: ' . count( $insert ) . " new, {$updated} to change, {$unchanged} already as wanted.\n";
	if ( $dry || ! $changes ) {
		return;
	}

	$wpdb->query( 'START TRANSACTION' );
	if ( $insert ) {
		$query->insert_strings( $insert, $language, 0 );
	}
	$ids = [];
	foreach ( array_chunk( array_column( $changes, 'original' ), 100 ) as $chunk ) {
		$marks = implode( ',', array_fill( 0, count( $chunk ), 'BINARY %s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original, block_type FROM `{$table}` WHERE BINARY original IN ({$marks})", $chunk ), ARRAY_A ) as $row ) {
			$ids[ $row['original'] ] = $row;
		}
	}
	$update = [];
	foreach ( $changes as $row ) {
		if ( ! isset( $ids[ $row['original'] ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			fwrite( STDERR, 'TranslatePress did not keep: ' . mb_substr( $row['original'], 0, 80 ) . "\n" );
			return;
		}
		$update[] = [
			'id'         => (int) $ids[ $row['original'] ]['id'],
			'original'   => $row['original'],
			'translated' => $row['translated'],
			'status'     => $human,
			'block_type' => (int) $ids[ $row['original'] ]['block_type'],
		];
	}
	if ( ! $query->update_strings( $update, $language, [ 'id', 'translated', 'status', 'block_type' ] ) ) {
		$wpdb->query( 'ROLLBACK' );
		fwrite( STDERR, "TranslatePress refused the translations.\n" );
		return;
	}
	$wpdb->query( 'COMMIT' );
	echo 'Written: ' . count( $insert ) . " added, {$updated} updated.\n";
};

/**
 * Writes the plugin and theme strings TranslatePress already lists.
 *
 * @param array<int,array<string,string>> $strings
 */
$write_plugin_strings = static function ( array $strings ) use ( $wpdb, $query, $language, $human, $dry ): void {
	$originals = $query->get_table_name_for_gettext_original_strings();
	$table     = $query->get_gettext_table_name( $language );
	$update    = [];
	$have      = 0;
	$unknown   = [];
	foreach ( $strings as $row ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_row( $wpdb->prepare( "SELECT t.id, t.translated, t.status, t.plural_form, o.context FROM `{$originals}` o JOIN `{$table}` t ON t.original_id = o.id WHERE BINARY o.original = %s AND o.domain = %s LIMIT 1", $row['original'], $row['domain'] ), ARRAY_A );
		if ( ! $found ) {
			$unknown[] = $row['domain'] . ': ' . $row['original'];
		} elseif ( $found['translated'] === $row['translated'] && (int) $found['status'] === $human ) {
			++$have;
		} else {
			$update[] = [
				'id'          => (int) $found['id'],
				'original'    => $row['original'],
				'translated'  => $row['translated'],
				'domain'      => $row['domain'],
				'status'      => $human,
				'plural_form' => (int) $found['plural_form'],
				'context'     => $found['context'],
			];
		}
	}
	echo count( $strings ) . ' plugin strings: ' . count( $update ) . " to change, {$have} already as wanted, " . count( $unknown ) . " not known to TranslatePress yet.\n";
	foreach ( $unknown as $line ) {
		echo "  not known yet: {$line}\n";
	}
	if ( $dry || ! $update ) {
		return;
	}
	$query->get_query_component( 'gettext_insert_update' )->update_gettext_strings( $update, $language, [ 'id', 'translated', 'status' ] );
	$query->remove_possible_duplicates( $update, $language, 'gettext' );
	echo 'Written: ' . count( $update ) . " plugin strings updated.\n";
};

if ( $rows ) {
	$write_page_text( $rows );
}
if ( $plugin_strings ) {
	$write_plugin_strings( $plugin_strings );
}
echo $dry ? "Dry run: nothing was written.\n" : "Done.\n";
