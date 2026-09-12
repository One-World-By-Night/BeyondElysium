<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Services\Game_File_Parser;
use BeyondElysium\Services\GV_Binary_Reader;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for importing a full Grapevine game file (binary `.gv3`).
 *
 * A separate, non-game-scoped route family from `Import_Controller`'s
 * game-scoped import routes, since creating a brand-new chronicle from a
 * game file has no existing game to scope a URL to. Every route requires
 * both `be_import` and `be_manage_games`, since creating a chronicle is a
 * games-level act as well as an import-level one.
 *
 * Reuses `Import_Controller`'s preview, apply, and format-detection logic
 * directly rather than duplicating trait resolution, duplicate detection, or
 * commit-blocking rules, since a parsed game file produces the same shape of
 * data as a parsed exchange file.
 *
 * The import target - creating a new chronicle or merging into an existing
 * one - is chosen at commit time rather than fixed by the URL, so the
 * preview can show what a merge would collide with before it is committed.
 * A merge treats the existing chronicle as the protected base and applies
 * the same skip/overwrite/import-as-new resolution used for a regular
 * import; several entity types the file may contain have no destination in
 * this plugin at all and are always skipped, surfaced as counts in the
 * preview rather than silently dropped.
 */
class Game_Import_Controller extends Base_Controller {

	protected $rest_base = 'import/game';

	/** Transient lifetime, in seconds, for a parsed import job. */
	const JOB_TTL = HOUR_IN_SECONDS;

	/**
	 * Registers the non-game-scoped import routes.
	 *
	 * Adds routes to parse an uploaded game file, fetch a parsed job's
	 * preview, and commit a reviewed job.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/parse', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'parse' ],
				'permission_callback' => $this->permission_all( [ 'be_import', 'be_manage_games' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<job_id>[a-z0-9\-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_job' ],
				'permission_callback' => $this->permission_all( [ 'be_import', 'be_manage_games' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<job_id>[a-z0-9\-]+)/commit', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'commit' ],
				'permission_callback' => $this->permission_all( [ 'be_import', 'be_manage_games' ] ),
			],
		] );
	}

	/**
	 * Detects the format of, parses, and previews an uploaded game file.
	 *
	 * Rejects anything that does not sniff as the binary GVBG format, then
	 * parses it and stores the result as a transient job. Since no merge
	 * target is chosen yet, the preview shows trait classification but
	 * reports no duplicates.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function parse( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return $this->error( 'invalid_param', __( 'A file upload is required.', 'beyond-elysium' ), 400 );
		}

		$path = $files['file']['tmp_name'];
		$data = @file_get_contents( $path );
		if ( $data === false || $data === '' ) {
			return $this->error( 'invalid_param', __( 'The uploaded file could not be read.', 'beyond-elysium' ), 400 );
		}

		$format = Import_Controller::sniff_format( $data );
		if ( $format !== 'GVBG' ) {
			return $this->error(
				'invalid_format',
				__( 'This route accepts a full Grapevine game file (.gv3, binary) - a .gex exchange file goes through the regular Import page instead.', 'beyond-elysium' ),
				400
			);
		}

		try {
			$parsed = Game_File_Parser::parse_binary( new GV_Binary_Reader( $data, $files['file']['name'] ?? 'upload.gv3' ) );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'parse_failed', $e->getMessage(), 400 );
		}

		$job_id  = wp_generate_uuid4();
		$preview = self::build_preview_response( $parsed, null );

		set_transient(
			self::job_transient_key( $job_id ),
			[
				'parsed'      => $parsed,
				'source_file' => $files['file']['name'] ?? 'upload.gv3',
			],
			self::JOB_TTL
		);

		return $this->success( array_merge( [ 'job_id' => $job_id ], $preview ) );
	}

	/**
	 * Fetches a previously parsed job's preview.
	 *
	 * Loads the stored job by ID and rebuilds its preview, optionally
	 * against a candidate merge target given by `?target=<game_slug>`, so
	 * the caller can see what would collide before committing to that
	 * target.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( $request ) {
		$job = get_transient( self::job_transient_key( (string) $request['job_id'] ) );
		if ( ! $job ) {
			return $this->error( 'not_found', __( 'No import job found with that id - it may have expired.', 'beyond-elysium' ), 404 );
		}

		$target_slug = trim( (string) ( $request['target'] ?? '' ) );
		$target      = $target_slug !== '' ? Game::find_by_slug( $target_slug ) : null;

		$preview = self::build_preview_response( $job['parsed'], $target );

		return $this->success( array_merge( [ 'job_id' => $request['job_id'] ], $preview ) );
	}

	/**
	 * Applies a previously parsed and reviewed game-file import job.
	 *
	 * Re-derives the preview fresh from the stored job rather than trusting
	 * any client-sent result, and refuses to commit while anything is
	 * unresolved or an unaddressed duplicate remains. Re-submitting an
	 * already-committed job ID returns the same stored result again rather
	 * than importing a second time.
	 *
	 * The request body's `target` selects either
	 * `{"action":"create_new","name"?:string}` or
	 * `{"action":"merge","game_slug":string}`. For `create_new`, the
	 * chronicle is created only after the blocking check passes, inside the
	 * same transaction as the import itself, so a failed or blocked attempt
	 * never leaves an empty chronicle behind.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function commit( $request ) {
		$job_key = self::job_transient_key( (string) $request['job_id'] );
		$job     = get_transient( $job_key );
		if ( ! $job ) {
			return $this->error( 'not_found', __( 'No import job found with that id - it may have expired.', 'beyond-elysium' ), 404 );
		}

		if ( isset( $job['committed_result'] ) ) {
			return $this->success( $job['committed_result'] );
		}

		$target      = (array) ( $request['target'] ?? [] );
		$resolutions = (array) ( $request['resolutions'] ?? [] );
		$parsed      = $job['parsed'];
		$action      = $target['action'] ?? '';

		if ( ! in_array( $action, [ 'create_new', 'merge' ], true ) ) {
			return $this->error(
				'invalid_target',
				__( 'A target is required: either {"action":"create_new"} or {"action":"merge","game_slug":"..."}.', 'beyond-elysium' ),
				400
			);
		}

		$merge_game = null;
		if ( $action === 'merge' ) {
			$merge_game = Game::find_by_slug( (string) ( $target['game_slug'] ?? '' ) );
			if ( ! $merge_game ) {
				return $this->error( 'game_not_found', __( 'The chronicle to merge into was not found.', 'beyond-elysium' ), 404 );
			}
		}

		// For create_new, duplicates are always empty; only trait classification is checked.
		$preview = $merge_game
			? Import_Controller::build_preview( $parsed, $merge_game->slug, (int) $merge_game->id, $resolutions, 'GVBG' )
			: Import_Controller::build_preview( $parsed, '', 0, $resolutions, 'GVBG' );

		$block = Import_Controller::blocking_reason( $preview, $resolutions );
		if ( $block !== null ) {
			return $this->error( $block['code'], $block['message'], $block['status'] );
		}

		global $wpdb;
		$nested = (int) $wpdb->get_var( 'SELECT @@autocommit' ) === 0;
		$wpdb->query( $nested ? 'SAVEPOINT be_game_import_commit' : 'START TRANSACTION' );

		try {
			if ( $merge_game ) {
				$game = $merge_game;
			} else {
				$name = trim( (string) ( $target['name'] ?? $parsed['chronicle_title'] ?? '' ) );
				if ( $name === '' ) {
					$name = 'Imported Chronicle';
				}
				// extended_health (the file's own 7-vs-10 health-level track choice) has no
				// BE resource_pool/UI to drive yet - stored so a real import file's value
				// survives rather than being silently discarded (0.99.2-workflow.md, "Imported
				// health levels are silently discarded"), not yet acted on anywhere.
				$new_game_id = Game::create( [
					'name'     => $name,
					'settings' => [ 'extended_health' => (bool) ( $parsed['extended_health'] ?? false ) ],
				] );
				if ( ! $new_game_id ) {
					throw new \RuntimeException( 'Could not create the new chronicle.' );
				}
				$game = Game::find( (int) $new_game_id );
			}

			$result = Import_Controller::apply_import( (int) $game->id, $game->slug, $parsed, (string) $job['source_file'], $resolutions );
		} catch ( \Throwable $e ) {
			$wpdb->query( $nested ? 'ROLLBACK TO SAVEPOINT be_game_import_commit' : 'ROLLBACK' );
			return $this->error( 'commit_failed', $e->getMessage(), 500 );
		}

		$wpdb->query( $nested ? 'RELEASE SAVEPOINT be_game_import_commit' : 'COMMIT' );

		$result['game'] = [
			'id'      => (int) $game->id,
			'slug'    => $game->slug,
			'name'    => $game->name,
			'created' => $action === 'create_new',
		];

		$job['committed_result'] = $result;
		set_transient( $job_key, $job, self::JOB_TTL );

		return $this->success( $result );
	}

	/**
	 * Builds the shared preview response used by `parse()` and `get_job()`.
	 *
	 * Delegates to `Import_Controller::build_preview()` for the core
	 * preview, then adds game-file-specific fields: the chronicle's title,
	 * the list of existing chronicles available as a merge target, and
	 * counts of entity types this import always skips.
	 *
	 * @param array<string,mixed> $parsed
	 * @param object|null         $target_game
	 * @return array<string,mixed>
	 */
	private static function build_preview_response( array $parsed, $target_game ): array {
		$game_slug = $target_game ? $target_game->slug : '';
		$game_id   = $target_game ? (int) $target_game->id : 0;

		$preview = Import_Controller::build_preview( $parsed, $game_slug, $game_id, [], 'GVBG' );

		$existing_games = array_map(
			static fn( $g ) => [ 'slug' => $g->slug, 'name' => $g->name ],
			Game::all()
		);

		return array_merge( $preview, [
			'chronicle_title' => $parsed['chronicle_title'],
			'existing_games'  => $existing_games,
			'skipped'         => [
				'queries'          => count( $parsed['queries'] ),
				'actions'          => count( $parsed['actions'] ),
				'plots'            => count( $parsed['plots'] ),
				'rumors'           => count( $parsed['rumors'] ),
				'xp_awards'        => count( $parsed['experience_awards'] ),
				'templates'        => count( $parsed['templates'] ),
				'calendar_entries' => count( $parsed['calendar']['entries'] ?? [] ),
				'apr_engine'       => $parsed['apr_engine'] !== null,
			],
		] );
	}

	/**
	 * Builds the transient key used to store a parsed import job.
	 *
	 * Prefixes the job ID so it cannot collide with other transients stored
	 * by the plugin.
	 *
	 * @param string $job_id
	 * @return string
	 */
	private static function job_transient_key( string $job_id ): string {
		return 'be_game_import_job_' . $job_id;
	}
}
