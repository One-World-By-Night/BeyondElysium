<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use BeyondElysium\Services\Name_Key;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for catalog term translation (1.2.0). Site-wide, not
 * chronicle-scoped - translations are site data, one install one language
 * (Decision 106). Every route is gated on `be_manage_translations`, never
 * `be_manage_schemas` - see that capability's own registration comment for
 * why the two are deliberately independent.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §6
 */
class Translations_Controller extends Base_Controller {

	protected $rest_base = 'translations';

	/** §6's own explicit cap - the default 100 a plain `get_pagination()` call would apply is
	 * exactly the D38/D52 defect class this screen exists to not repeat, against 8,298 rows. */
	private const MAX_PER_PAGE = 500;

	/**
	 * Registers all ten routes. Two of §6's own literal names - "stats" and "import" - are
	 * deliberately NOT used as the final path segment here: this plugin already has a
	 * `(?P<game_slug>[a-z0-9\-]+)/stats` route (Game_Stats_Controller) and a
	 * `(?P<game_slug>[a-z0-9\-]+)/import` route (Import_Controller), and WordPress's route
	 * matcher tries registered patterns in order - "translations" itself satisfies
	 * `[a-z0-9\-]+`, so `/translations/stats` collided with the FIRST-registered pattern and
	 * 404'd as "game_not_found" (confirmed live, not theorized). Renamed to `/progress` and
	 * `/import-csv`, neither of which exists anywhere else in this plugin's route table
	 * (checked directly against every registered `{game_slug}/X` pattern before picking
	 * them) - correctness by construction, not by depending on Plugin.php's own controller
	 * registration order staying exactly as it is today.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/progress', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/locales', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_locales' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/bulk', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/export', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/import-csv', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'import' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/rescan', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rescan' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_translations' ),
			],
		] );

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_csv_bytes' ], 10, 4 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$locale = (string) $request->get_param( 'locale' );
		if ( '' === $locale ) {
			return $this->error( 'missing_param', __( 'locale is required.', 'beyond-elysium' ), 400 );
		}

		$filters    = $this->filters_from_request( $request );
		$pagination = $this->get_pagination( $request, self::MAX_PER_PAGE );

		$items = Translation_String::list_for_review( $locale, $filters, $pagination['per_page'], $pagination['offset'] );
		$total = Translation_String::count_for_review( $locale, $filters );

		$response = $this->success( array_values( $items ) );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * §6: "Per-locale totals and per-block breakdown for the progress display."
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_stats( $request ) {
		$locale = (string) $request->get_param( 'locale' );
		if ( '' === $locale ) {
			return $this->error( 'missing_param', __( 'locale is required.', 'beyond-elysium' ), 400 );
		}

		global $wpdb;
		$strings_table      = Manager::table( 'translation_strings' );
		$translations_table = Manager::table( 'translations' );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$strings_table}" );

		$by_status = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS n FROM {$translations_table}
			WHERE locale = %s AND context IS NULL AND translation <> ''
			GROUP BY status",
			$locale
		) );

		$status_counts = array_fill_keys( Translation::STATUSES, 0 );
		$translated    = 0;
		foreach ( $by_status as $row ) {
			if ( isset( $status_counts[ $row->status ] ) ) {
				$status_counts[ $row->status ] = (int) $row->n;
			}
			$translated += (int) $row->n;
		}

		return $this->success( [
			'locale'        => $locale,
			'total'         => $total,
			'translated'    => $translated,
			'untranslated'  => $total - $translated,
			'by_status'     => $status_counts,
			'by_block'      => $this->stats_by_block( $locale ),
		] );
	}

	/**
	 * Every distinct block a translatable term appears in, with a term count and how many of
	 * those terms carry a real translation for $locale. Walked in PHP rather than a JSON_TABLE
	 * query - "cheap" per §6's own framing already assumes a full-table read is fine at this
	 * row count, and this stays portable without depending on a specific MySQL JSON feature.
	 *
	 * @param string $locale
	 * @return array<string,array{total:int,translated:int}>
	 */
	private function stats_by_block( string $locale ): array {
		global $wpdb;
		$strings_table      = Manager::table( 'translation_strings' );
		$translations_table = Manager::table( 'translations' );

		$rows = $wpdb->get_results(
			"SELECT s.used_in, (t.translation IS NOT NULL AND t.translation <> '') AS has_pt
			FROM {$strings_table} s
			LEFT JOIN {$translations_table} t
				ON t.string_id = s.id AND t.locale = " . $wpdb->prepare( '%s', $locale ) . ' AND t.context IS NULL'
		);

		$by_block = [];
		foreach ( $rows as $row ) {
			$used_in = json_decode( (string) $row->used_in, true );
			if ( ! is_array( $used_in ) ) {
				continue;
			}
			$blocks_seen = [];
			foreach ( $used_in as $usage ) {
				$block = $usage['block'] ?? null;
				if ( ! is_string( $block ) || '' === $block || isset( $blocks_seen[ $block ] ) ) {
					continue; // Count a block once per term, even if the term appears in it twice.
				}
				$blocks_seen[ $block ] = true;
				$by_block[ $block ]    ??= [ 'total' => 0, 'translated' => 0 ];
				++$by_block[ $block ]['total'];
				if ( $row->has_pt ) {
					++$by_block[ $block ]['translated'];
				}
			}
		}

		ksort( $by_block );
		return $by_block;
	}

	/**
	 * §6: "Locales that have rows, plus the locales WordPress has installed."
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_locales( $request ) {
		global $wpdb;
		$translations_table = Manager::table( 'translations' );
		$with_rows           = $wpdb->get_col( "SELECT DISTINCT locale FROM {$translations_table} ORDER BY locale" );
		$installed            = array_values( array_unique( array_merge( [ 'en_US' ], get_available_languages() ) ) );

		return $this->success( [
			'with_rows' => $with_rows,
			'installed' => $installed,
		] );
	}

	/**
	 * Create or replace one translation (§6): upsert on (string_id, locale, context). Accepts
	 * either string_id or source_text - a translator naming a brand-new term (one rescan()
	 * has not indexed yet) gets a string row created for them rather than a 404.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$locale      = (string) $request->get_param( 'locale' );
		$translation = $request->get_param( 'translation' );
		if ( '' === $locale || null === $translation ) {
			return $this->error( 'missing_param', __( 'locale and translation are required.', 'beyond-elysium' ), 400 );
		}

		$string_id   = $request->get_param( 'string_id' );
		$source_text = $request->get_param( 'source_text' );
		if ( ! $string_id && ! $source_text ) {
			return $this->error( 'missing_param', __( 'Either string_id or source_text is required.', 'beyond-elysium' ), 400 );
		}

		$string_row = $string_id ? Translation_String::find( (int) $string_id ) : Translation_String::find_by_source_text( (string) $source_text );
		if ( ! $string_row && $source_text ) {
			$new_id     = Translation_String::create( [ 'source_text' => (string) $source_text ] );
			$string_row = $new_id ? Translation_String::find( (int) $new_id ) : null;
		}
		if ( ! $string_row ) {
			return $this->error( 'not_found', __( 'That catalog term could not be found.', 'beyond-elysium' ), 404 );
		}

		$context = $request->get_param( 'context' );
		$status  = $request->get_param( 'status' ) ?: 'draft';

		$id = Translation::upsert(
			(int) $string_row->id,
			$locale,
			(string) $translation,
			$context ? (string) $context : null,
			(string) $status,
			get_current_user_id()
		);
		if ( false === $id ) {
			return $this->error( 'save_failed', __( 'The translation could not be saved.', 'beyond-elysium' ), 500 );
		}

		Catalog_Translator::bust_cache();
		return $this->success( Translation::find( (int) $id ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$id       = (int) $request['id'];
		$existing = Translation::find( $id );
		if ( ! $existing ) {
			return $this->error( 'not_found', __( 'Translation not found.', 'beyond-elysium' ), 404 );
		}

		$data = [ 'updated_by' => get_current_user_id() ];
		foreach ( [ 'translation', 'status' ] as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		if ( ! Translation::update( $id, $data ) ) {
			return $this->error( 'save_failed', __( 'The translation could not be saved.', 'beyond-elysium' ), 500 );
		}

		Catalog_Translator::bust_cache();
		return $this->success( Translation::find( $id ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$id       = (int) $request['id'];
		$existing = Translation::find( $id );
		if ( ! $existing ) {
			return $this->error( 'not_found', __( 'Translation not found.', 'beyond-elysium' ), 404 );
		}

		Translation::delete( $id );
		Catalog_Translator::bust_cache();
		return $this->success( [ 'deleted' => true ] );
	}

	/**
	 * §6: "{ locale, rows: [{source_text, translation, status}] }. Backs CSV import and bulk
	 * approve." Every row is independent - one bad row is skipped and counted, never aborts
	 * the rest of the batch.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( $request ) {
		$locale = (string) $request->get_param( 'locale' );
		$rows   = $request->get_param( 'rows' );
		if ( '' === $locale || ! is_array( $rows ) ) {
			return $this->error( 'missing_param', __( 'locale and rows are required.', 'beyond-elysium' ), 400 );
		}

		$result = $this->apply_bulk_rows( $locale, $rows, get_current_user_id() );
		Catalog_Translator::bust_cache();
		return $this->success( $result );
	}

	/**
	 * The write side shared by bulk() and import()'s non-dry-run pass - kept as one method so
	 * the two can never silently diverge in what counts as "updated" vs. "skipped" (T12).
	 *
	 * @param string   $locale
	 * @param array    $rows
	 * @param int|null $updated_by
	 * @return array{updated:int,skipped:int}
	 */
	private function apply_bulk_rows( string $locale, array $rows, ?int $updated_by ): array {
		$updated = 0;
		$skipped = 0;

		foreach ( $rows as $row ) {
			$source_text = is_array( $row ) ? ( $row['source_text'] ?? null ) : null;
			$translation = is_array( $row ) ? ( $row['translation'] ?? null ) : null;
			$status      = is_array( $row ) ? ( $row['status'] ?? 'draft' ) : 'draft';

			if ( ! is_string( $source_text ) || '' === $source_text || ! is_string( $translation ) ) {
				++$skipped;
				continue;
			}

			$string_row = Translation_String::find_by_source_text( $source_text );
			if ( ! $string_row ) {
				$new_id     = Translation_String::create( [ 'source_text' => $source_text ] );
				$string_row = $new_id ? Translation_String::find( (int) $new_id ) : null;
			}
			if ( ! $string_row ) {
				++$skipped;
				continue;
			}

			$id = Translation::upsert( (int) $string_row->id, $locale, $translation, null, (string) $status, $updated_by );
			if ( false === $id ) {
				++$skipped;
				continue;
			}
			++$updated;
		}

		return [ 'updated' => $updated, 'skipped' => $skipped ];
	}

	/**
	 * §6: CSV download honouring the same filters as the list, text/csv, UTF-8 BOM.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export( $request ) {
		$locale = (string) $request->get_param( 'locale' );
		if ( '' === $locale ) {
			return $this->error( 'missing_param', __( 'locale is required.', 'beyond-elysium' ), 400 );
		}

		$filters = $this->filters_from_request( $request );
		$total   = Translation_String::count_for_review( $locale, $filters );
		$rows    = Translation_String::list_for_review( $locale, $filters, max( 1, $total ), 0 );

		$buffer = "\xEF\xBB\xBF"; // UTF-8 BOM, per §6.
		$handle = fopen( 'php://temp', 'w+' );
		if ( ! $handle ) {
			return $this->error( 'export_failed', __( 'The export could not be generated.', 'beyond-elysium' ), 500 );
		}
		fputcsv( $handle, [ 'source_text', 'translation', 'status' ] );
		foreach ( $rows as $row ) {
			fputcsv( $handle, [ $row->source_text, $row->translation ?? '', $row->status ?? '' ] );
		}
		rewind( $handle );
		$buffer .= (string) stream_get_contents( $handle );
		fclose( $handle );

		return $this->success( [
			'bytes'    => $buffer,
			'filename' => 'translations-' . $locale . '.csv',
		] );
	}

	/**
	 * §6: multipart CSV. dry_run=1 reports counts and a sample without writing (T12).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		$locale = (string) $request->get_param( 'locale' );
		if ( '' === $locale ) {
			return $this->error( 'missing_param', __( 'locale is required.', 'beyond-elysium' ), 400 );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return $this->error( 'invalid_param', __( 'A file upload is required.', 'beyond-elysium' ), 400 );
		}
		$contents = @file_get_contents( $files['file']['tmp_name'] );
		if ( false === $contents || '' === $contents ) {
			return $this->error( 'invalid_param', __( 'The uploaded file could not be read.', 'beyond-elysium' ), 400 );
		}

		$rows = $this->parse_csv( $contents );
		$dry_run = filter_var( $request->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );

		$added      = 0;
		$updated    = 0;
		$unchanged  = 0;
		$unmatched  = 0;
		$conflicts  = 0;
		$sample     = [];

		// A conflict is the FILE disagreeing with itself - two rows for the same term with two
		// different translations - not the file disagreeing with what's already in the
		// database, which is an ordinary update (correcting an existing translation is the
		// whole point of importing). This matches §8's own migration use of "conflict" for an
		// internally-inconsistent source, not a source that disagrees with a prior state.
		$seen_in_file = [];

		foreach ( $rows as $row ) {
			$source_text = trim( (string) ( $row['source_text'] ?? '' ) );
			$translation = (string) ( $row['translation'] ?? '' );
			if ( '' === $source_text ) {
				continue;
			}

			$key = Name_Key::for( $source_text );
			if ( isset( $seen_in_file[ $key ] ) && $seen_in_file[ $key ] !== $translation ) {
				++$conflicts;
				if ( count( $sample ) < 20 ) {
					$sample[] = [ 'source_text' => $source_text, 'outcome' => 'conflict', 'existing' => $seen_in_file[ $key ], 'incoming' => $translation ];
				}
				continue; // First value in the file wins, matching the migration's own rule.
			}
			$seen_in_file[ $key ] = $translation;

			$string_row = Translation_String::find_by_source_text( $source_text );
			if ( ! $string_row ) {
				++$unmatched;
				if ( count( $sample ) < 20 ) {
					$sample[] = [ 'source_text' => $source_text, 'outcome' => 'unmatched' ];
				}
				continue;
			}

			$existing = Translation::find_one( (int) $string_row->id, $locale, null );
			if ( $existing && $existing->translation === $translation ) {
				++$unchanged;
				continue;
			}

			$existing ? ++$updated : ++$added;
			if ( count( $sample ) < 20 ) {
				$sample[] = [ 'source_text' => $source_text, 'outcome' => $existing ? 'updated' : 'added' ];
			}

			if ( ! $dry_run ) {
				Translation::upsert( (int) $string_row->id, $locale, $translation, null, 'draft', get_current_user_id() );
			}
		}

		if ( ! $dry_run ) {
			Catalog_Translator::bust_cache();
		}

		return $this->success( [
			'added'     => $added,
			'updated'   => $updated,
			'unchanged' => $unchanged,
			'unmatched' => $unmatched,
			'conflicts' => $conflicts,
			'sample'    => $sample,
			'dry_run'   => $dry_run,
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function rescan( $request ) {
		$result = Catalog_Translator::rescan();
		Catalog_Translator::bust_cache();
		return $this->success( $result );
	}

	/**
	 * The shared status/block/search/has_translation filter set list()/export() both read from
	 * the same request shape (§6's own "CSV download honouring the same filters as the list").
	 *
	 * @param \WP_REST_Request $request
	 * @return array
	 */
	private function filters_from_request( $request ): array {
		$filters = [];
		foreach ( [ 'status', 'block', 'search' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}
		$has_translation = $request->get_param( 'has_translation' );
		if ( null !== $has_translation && '' !== $has_translation ) {
			$filters['has_translation'] = filter_var( $has_translation, FILTER_VALIDATE_BOOLEAN );
		}
		return $filters;
	}

	/**
	 * A minimal, dependency-free CSV reader matching export()'s own three-column shape
	 * (source_text, translation, status) - the header row is required and used to map columns
	 * by name rather than position, so a hand-edited file with reordered columns still works.
	 *
	 * @param string $contents
	 * @return array<int,array<string,string>>
	 */
	private function parse_csv( string $contents ): array {
		$handle = fopen( 'php://temp', 'w+' );
		if ( ! $handle ) {
			return [];
		}
		fwrite( $handle, $contents );
		rewind( $handle );

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return [];
		}
		$header = array_map( 'trim', $header );

		$rows = [];
		while ( is_array( $line = fgetcsv( $handle ) ) ) {
			if ( count( $line ) !== count( $header ) ) {
				continue;
			}
			$rows[] = array_combine( $header, $line );
		}
		fclose( $handle );
		return $rows;
	}

	/**
	 * Serves export()'s raw CSV bytes instead of JSON-encoding them, matched by callback
	 * identity - the same pattern Sheets_Controller::serve_pdf_bytes() already established for
	 * signed PDFs.
	 *
	 * @param bool              $served
	 * @param mixed             $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 * @return bool
	 */
	public function serve_csv_bytes( $served, $result, $request, $server ) {
		$attributes = $request->get_attributes();
		if ( ( $attributes['callback'] ?? null ) !== [ $this, 'export' ] ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['bytes'], $data['filename'] ) ) {
			return $served;
		}

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $data['filename'] . '"' );
		echo $data['bytes']; // phpcs:ignore -- raw CSV bytes, not HTML output.
		return true;
	}
}
