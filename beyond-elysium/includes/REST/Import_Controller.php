<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Character_Diff;
use BeyondElysium\Services\GEX_Parser;
use BeyondElysium\Services\GEX_Xml_Parser;
use BeyondElysium\Services\GV_Binary_Reader;
use BeyondElysium\Services\Not_Exportable_Exception;
use BeyondElysium\Services\Purchase_Scope;
use BeyondElysium\Services\Trait_Mapper;
use BeyondElysium\Utils\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for importing Grapevine exchange files into a game.
 */
class Import_Controller extends Base_Controller {

	protected $rest_base = 'import';

	/**
	 * Transient TTL for a parsed job, in seconds.
	 */
	const JOB_TTL = HOUR_IN_SECONDS;

	/**
	 * Seconds after which a commit lock is stale.
	 */
	const COMMIT_LOCK_TTL = 600;

	/**
	 * Registers the REST routes for parsing an uploaded file, fetching a parsed job's preview, and committing a reviewed
	 * job.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/import/parse', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'parse' ],
				'permission_callback' => $this->permission( 'be_import' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/import/(?P<job_id>[a-z0-9\-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_job' ],
				'permission_callback' => $this->permission( 'be_import' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/import/(?P<job_id>[a-z0-9\-]+)/commit', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'commit' ],
				'permission_callback' => $this->permission( 'be_import' ),
			],
		] );
	}

	/**
	 * Sniffs the uploaded file's format, parses it with the matching parser, builds a review preview, and stores the
	 * parsed job server-side keyed by a generated job id for a later commit call.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function parse( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$files = $request->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return $this->error( 'invalid_param', __( 'A file upload is required.', 'beyond-elysium' ), 400 );
		}

		$path = $files['file']['tmp_name'];
		$data = @file_get_contents( $path );
		if ( $data === false || $data === '' ) {
			return $this->error( 'invalid_param', __( 'The uploaded file could not be read.', 'beyond-elysium' ), 400 );
		}

		$format = self::sniff_format( $data );

		if ( $format === 'GVBG' ) {
			// Full game file import is not supported here.
			return $this->error(
				'unsupported_format',
				__( 'Full game file import (.gv3) is not yet supported - export a .gex exchange file instead.', 'beyond-elysium' ),
				400
			);
		}

		if ( ! in_array( $format, [ 'GVBE', 'XML' ], true ) ) {
			return $this->error( 'invalid_format', __( 'This file is not a recognized Grapevine exchange file.', 'beyond-elysium' ), 400 );
		}

		try {
			$parsed = $format === 'XML'
				? GEX_Xml_Parser::parse_string( $data )
				: GEX_Parser::parse_binary( new GV_Binary_Reader( $data, $files['file']['name'] ?? 'upload.gex' ) );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'parse_failed', $e->getMessage(), 400 );
		}

		$job_id  = wp_generate_uuid4();
		$preview = self::build_preview( $parsed, $request['game_slug'], (int) $game->id, [], $format );

		set_transient(
			self::job_transient_key( $job_id ),
			[
				'game_id'     => (int) $game->id,
				'parsed'      => $parsed,
				'preview'     => $preview,
				'source_file' => $files['file']['name'] ?? 'upload.gex',
				'format'      => $format,
			],
			self::JOB_TTL
		);

		return $this->success( array_merge( [ 'job_id' => $job_id ], $preview ) );
	}

	/**
	 * Applies a previously parsed and reviewed import job: refuses to proceed while any trait remains fuzzy or
	 * unresolved, then creates every item, location, rote and character in one transaction.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function commit( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$job_key = self::job_transient_key( (string) $request['job_id'] );
		$job     = get_transient( $job_key );
		if ( ! $job || (int) $job['game_id'] !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'No import job found with that id - it may have expired.', 'beyond-elysium' ), 404 );
		}

		if ( isset( $job['committed_result'] ) ) {
			return $this->success( $job['committed_result'] );
		}

		// Held while the import runs.
		$lock = self::commit_lock_name( (string) $request['job_id'] );
		if ( ! Option_Lock::claim( $lock, self::COMMIT_LOCK_TTL ) ) {
			return self::commit_in_progress_error();
		}

		try {
			$job = get_transient( $job_key );
			if ( ! $job ) {
				return $this->error( 'not_found', __( 'No import job found with that id - it may have expired.', 'beyond-elysium' ), 404 );
			}
			if ( isset( $job['committed_result'] ) ) {
				return $this->success( $job['committed_result'] );
			}

			// Only the resolution choices come from the request; everything else is re-derived from the stored job.
			$resolutions = (array) ( $request['resolutions'] ?? [] );
			$parsed      = $job['parsed'];
			$preview     = self::build_preview( $parsed, $game->slug, (int) $game->id, $resolutions, (string) ( $job['format'] ?? 'GVBE' ) );

			$block = self::blocking_reason( $preview, $resolutions );
			if ( $block !== null ) {
				return $this->error( $block['code'], $block['message'], $block['status'] );
			}

			$savepoint = Transaction::begin( 'be_import_commit' );

			try {
				$result = self::apply_import( (int) $game->id, $game->slug, $parsed, (string) $job['source_file'], $resolutions );
			} catch ( \Throwable $e ) {
				Transaction::rollback( $savepoint );
				return $this->error( 'commit_failed', $e->getMessage(), 500 );
			}

			Transaction::commit( $savepoint );

			$job['committed_result'] = $result;
			set_transient( $job_key, $job, self::JOB_TTL );

			return $this->success( $result );
		} finally {
			Option_Lock::release( $lock );
		}
	}

	/**
	 * The lock a running commit of one import job holds.
	 *
	 * @param string $job_id
	 * @return string
	 */
	public static function commit_lock_name( string $job_id ): string {
		return 'be_import_commit_' . $job_id;
	}

	/**
	 * The refusal for a commit that overlaps another commit of the same job.
	 *
	 * @return \WP_Error
	 */
	public static function commit_in_progress_error(): \WP_Error {
		return new \WP_Error( 'commit_in_progress', __( 'This import is already being committed. Wait a moment, then open the job again to see its result.', 'beyond-elysium' ), [ 'status' => 409 ] );
	}

	/**
	 * Checks a freshly re-derived preview for anything that must be resolved before a commit may proceed: unresolved or
	 * flagged traits, and any real duplicate character or world object left unaddressed.
	 *
	 * @param array<string,mixed> $preview
	 * @param array<string,mixed> $resolutions
	 * @return array{code:string,message:string,status:int}|null Null when nothing blocks a commit.
	 */
	public static function blocking_reason( array $preview, array $resolutions ): ?array {
		$blocking = count( $preview['unresolved'] ) + count( $preview['flagged_traits'] );
		if ( $blocking > 0 ) {
			return [
				'code'    => 'unresolved_traits',
				'message' => sprintf(
					__( '%d trait(s) still need review before this import can be committed - %d unresolved, %d fuzzy-matched. Re-fetch this job to see them.', 'beyond-elysium' ),
					$blocking,
					count( $preview['unresolved'] ),
					count( $preview['flagged_traits'] )
				),
				'status'  => 409,
			];
		}

		$duplicate_actions = (array) ( $resolutions['duplicates'] ?? [] );
		$unaddressed       = [];
		$elsewhere         = [];
		foreach ( $preview['duplicates'] as $dup ) {
			$action = $duplicate_actions[ $dup['character'] ] ?? null;
			if ( ( $dup['matched_by'] ?? null ) === 'uuid_elsewhere' && $action === 'overwrite' ) {
				$elsewhere[] = $dup['character'];
			} elseif ( ! in_array( $action, [ 'skip', 'overwrite', 'import_as_new' ], true ) ) {
				$unaddressed[] = $dup['character'];
			}
		}
		if ( ! empty( $elsewhere ) ) {
			return [
				'code'    => 'character_in_another_chronicle',
				'message' => sprintf(
					/* translators: %s: comma-separated character names */
					__( 'These characters already exist in another chronicle on this site, so they cannot be overwritten from here - skip them or import them as new characters: %s.', 'beyond-elysium' ),
					implode( ', ', $elsewhere )
				),
				'status'  => 409,
			];
		}
		if ( ! empty( $unaddressed ) ) {
			return [
				'code'    => 'unresolved_duplicates',
				'message' => sprintf(
					__( '%d character(s) already exist in this chronicle and need a decision before this import can be committed: %s.', 'beyond-elysium' ),
					count( $unaddressed ),
					implode( ', ', $unaddressed )
				),
				'status'  => 409,
			];
		}

		// Same rule, extended to items, locations, and rotes.
		$world_object_actions = (array) ( $resolutions['world_objects'] ?? [] );
		$unaddressed_objects  = [];
		foreach ( $preview['world_object_duplicates'] as $dup ) {
			$key    = "{$dup['type']}:{$dup['name']}";
			$action = $world_object_actions[ $key ] ?? null;
			if ( ! in_array( $action, [ 'skip', 'overwrite', 'import_as_new' ], true ) ) {
				$unaddressed_objects[] = "{$dup['type']} \"{$dup['name']}\"";
			}
		}
		if ( ! empty( $unaddressed_objects ) ) {
			return [
				'code'    => 'unresolved_duplicates',
				'message' => sprintf(
					__( '%d item(s)/location(s)/rote(s) already exist in this chronicle and need a decision before this import can be committed: %s.', 'beyond-elysium' ),
					count( $unaddressed_objects ),
					implode( ', ', $unaddressed_objects )
				),
				'status'  => 409,
			];
		}

		return null;
	}

	/**
	 * Applies a fully-resolved import job within a single transaction: creates every parsed item, location, and rote as a
	 * world object.
	 *
	 * @param int                  $game_id
	 * @param string               $game_slug
	 * @param array<string,mixed>  $parsed
	 * @param string               $source_file
	 * @param array<string,mixed>  $resolutions Duplicate/trait resolution choices (Chunk 1 of the Import plan) - see commit()'s own doc comment.
	 * @param array<string,mixed> $options `submitted_by` (int): the file came from a
	 *                              player's own submission, not a Storyteller's upload - every
	 *                              character created or overwritten is forced to that account
	 *                              (never NPC, never a bare player_name, always active), since
	 *                              accepting a submission is itself the Storyteller's approval.
	 * @return array<string,mixed>
	 */
	public static function apply_import( int $game_id, string $game_slug, array $parsed, string $source_file, array $resolutions, array $options = [] ): array {
		$created = [ 'items' => [], 'locations' => [], 'rotes' => [], 'characters' => [] ];
		$world_object_actions = (array) ( $resolutions['world_objects'] ?? [] );
		// Rows this commit has already written.
		$written_objects    = [];
		$written_characters = [];

		foreach ( $parsed['items'] as $item ) {
			$created['items'][] = self::import_world_object(
				$game_id, 'item', $item['name'],
				// The item's free-text Notes field maps to the world object's description column.
				$item['notes'] !== '' ? $item['notes'] : null,
				[
					'item_type'      => $item['item_type'],
					'item_subtype'   => $item['item_subtype'],
					'level'          => $item['level'],
					'bonus'          => $item['bonus'],
					'damage_type'    => $item['damage_type'],
					'damage_amount'  => $item['damage_amount'],
					'concealability' => $item['concealability'],
					'powers'         => $item['powers'],
					'appearance'     => $item['appearance'],
					'tempers'        => self::trait_list_to_properties( $item['temper_list'] ),
					'negatives'      => self::trait_list_to_properties( $item['negative_list'] ),
					'abilities'      => self::trait_list_to_properties( $item['ability_list'] ),
					'availability'   => self::trait_list_to_properties( $item['availability'] ),
				],
				$world_object_actions,
				$written_objects
			);
		}

		foreach ( $parsed['locations'] as $loc ) {
			$created['locations'][] = self::import_world_object(
				$game_id, 'location', $loc['name'],
				$loc['notes'] !== '' ? $loc['notes'] : null,
				[
					'location_type'    => $loc['loc_type'],
					'level'            => $loc['level'],
					'owner'            => $loc['owner'],
					'where'            => $loc['where'],
					'appearance'       => $loc['appearance'],
					'access'           => $loc['access'],
					'security'         => $loc['security'],
					'security_traits'  => $loc['sec_traits'],
					'security_retests' => $loc['sec_retests'],
					'gauntlet'         => $loc['gauntlet'],
					'umbra'            => $loc['umbra'],
					'affinity'         => $loc['affinity'],
					'totem'            => $loc['totem'],
					'links'            => $loc['link_list'] ? self::trait_list_to_properties( $loc['link_list'] ) : [],
				],
				$world_object_actions,
				$written_objects
			);
		}

		foreach ( $parsed['rotes'] as $rote ) {
			$created['rotes'][] = self::import_world_object(
				$game_id, 'rote', $rote['name'], null,
				[
					'level'       => $rote['level'],
					'duration'    => $rote['duration'],
					'description' => $rote['description'],
					'grades'      => $rote['grades'],
					'spheres'     => self::trait_list_to_properties( $rote['sphere_list'] ),
				],
				$world_object_actions,
				$written_objects
			);
		}

		// Maps each player's GV name to their email, built once for lookup by every character.
		$player_emails_by_name = [];
		foreach ( $parsed['players'] as $player ) {
			if ( ( $player['name'] ?? '' ) !== '' && ( $player['email'] ?? '' ) !== '' ) {
				$player_emails_by_name[ $player['name'] ] = $player['email'];
			}
		}

		$duplicate_actions = (array) ( $resolutions['duplicates'] ?? [] );
		$trait_resolutions = self::index_trait_resolutions( (array) ( $resolutions['traits'] ?? [] ) );

		foreach ( $parsed['characters'] as $character ) {
			$char_name = self::character_display_name( $character );
			$match     = self::match_existing_character( $character, $game_slug );
			if ( $match['existing'] && isset( $written_characters[ (int) $match['existing']->id ] ) ) {
				// Already written by an earlier entry in this file: never overwritten a second time.
				$match = [ 'existing' => null, 'matched_by' => null ];
				unset( $character['uuid'] );
			}
			$existing  = $match['existing'];
			// Every match - uuid or name - applies the Storyteller's own choice (blocking_reason()).
			$action = $existing ? ( $duplicate_actions[ $char_name ] ?? '' ) : '';

			if ( $existing && $action === 'skip' ) {
				$created['characters'][] = [
					// Another chronicle's row id is not this chronicle's to report.
					'id'     => $match['matched_by'] === 'uuid_elsewhere' ? 0 : (int) $existing->id,
					'name'   => $char_name,
					'action' => 'skipped',
				];
				continue;
			}
			if ( $existing && $action === 'overwrite' && $match['matched_by'] === 'uuid_elsewhere' ) {
				throw new \RuntimeException( "\"{$char_name}\" belongs to another chronicle and cannot be overwritten from this one." );
			}
			if ( $existing && $action === 'import_as_new' && $match['matched_by'] !== 'name' ) {
				unset( $character['uuid'] );
			}

			$imported = self::import_character(
				$game_id,
				$game_slug,
				$character,
				$source_file,
				$player_emails_by_name,
				$trait_resolutions,
				$action === 'overwrite' ? $existing : null,
				isset( $options['submitted_by'] ) ? (int) $options['submitted_by'] : null
			);
			foreach ( $imported['added_to_catalog'] as $object ) {
				$created[ $object['type'] === 'item' ? 'items' : 'locations' ][] = [ 'id' => $object['id'], 'name' => $object['name'], 'action' => 'created' ];
			}
			unset( $imported['added_to_catalog'] );
			$written_characters[ (int) $imported['id'] ] = true;
			$created['characters'][] = $imported;
		}

		// Actions/plots/rumors/queries have no import destination.
		foreach ( [ 'actions', 'plots', 'rumors', 'queries' ] as $kind ) {
			if ( ! empty( $parsed[ $kind ] ) ) {
				$created[ "skipped_{$kind}" ] = count( $parsed[ $kind ] );
			}
		}

		if ( ! empty( $parsed['calendar']['entries'] ) ) {
			$imported_dates = 0;
			$skipped_dates  = 0;
			foreach ( $parsed['calendar']['entries'] as $entry ) {
				// date part only - Game_Session::game_date is a date column, not a datetime one.
				$game_date = substr( (string) ( $entry['date'] ?? '' ), 0, 10 );
				if ( $game_date === '' || Game_Session::find_by_date( $game_id, $game_date ) ) {
					++$skipped_dates;
					continue;
				}
				Game_Session::create( [
					'game_id'    => $game_id,
					'game_date'  => $game_date,
					'start_time' => ( $entry['time'] ?? '' ) !== '' ? $entry['time'] : null,
					'place'      => ( $entry['place'] ?? '' ) !== '' ? $entry['place'] : null,
					'notes'      => ( $entry['notes'] ?? '' ) !== '' ? $entry['notes'] : null,
					'created_by' => isset( $options['submitted_by'] ) ? (int) $options['submitted_by'] : get_current_user_id(),
				] );
				++$imported_dates;
			}
			$created['calendar_entries'] = [ 'imported' => $imported_dates, 'skipped' => $skipped_dates ];
		}

		return $created;
	}

	/**
	 * Creates one imported item, location, or rote, or, for a duplicate the ST chose to overwrite, updates the existing
	 * row in place instead.
	 *
	 * @param int                  $game_id
	 * @param string               $object_type 'item'|'location'|'rote'.
	 * @param string               $name
	 * @param string|null          $description
	 * @param array<string,mixed>  $properties
	 * @param array<string,string> $duplicate_actions Keyed by "{object_type}:{name}" -> 'skip'|'overwrite'|'import_as_new'.
	 * @param array<int,bool>      $written Ids this commit already wrote; updated in place.
	 * @return array{id:int,name:string,action:string}
	 */
	private static function import_world_object( int $game_id, string $object_type, string $name, ?string $description, array $properties, array $duplicate_actions, array &$written = [] ): array {
		$existing = $name !== '' ? World_Object::find_by_name_in_game( $game_id, $object_type, $name ) : null;
		if ( $existing && isset( $written[ (int) $existing->id ] ) ) {
			$existing = null;
		}
		$action = $existing ? ( $duplicate_actions[ "{$object_type}:{$name}" ] ?? '' ) : '';

		if ( $existing && $action === 'skip' ) {
			return [ 'id' => (int) $existing->id, 'name' => $name, 'action' => 'skipped' ];
		}

		if ( $existing && $action === 'overwrite' ) {
			$ok = World_Object::update( (int) $existing->id, [ 'description' => $description, 'properties' => $properties ] );
			if ( ! $ok ) {
				throw new \RuntimeException( "Failed to overwrite {$object_type} \"{$name}\"." );
			}
			$written[ (int) $existing->id ] = true;
			return [ 'id' => (int) $existing->id, 'name' => $name, 'action' => 'overwritten' ];
		}

		$id = World_Object::create( [
			'game_id'     => $game_id,
			'object_type' => $object_type,
			'name'        => $name,
			'description' => $description,
			'properties'  => $properties,
		] );
		if ( $id === false ) {
			throw new \RuntimeException( "Failed to import {$object_type} \"{$name}\"." );
		}
		$written[ (int) $id ] = true;
		return [ 'id' => (int) $id, 'name' => $name, 'action' => 'created' ];
	}

	/**
	 * Finds the local character a parsed record refers to, if any.
	 *
	 * @param array<string,mixed> $character
	 * @param string              $game_slug
	 * @return array{existing:object|null,matched_by:string|null} `matched_by` is 'uuid', 'uuid_elsewhere', 'name', or null when nothing matched.
	 */
	private static function match_existing_character( array $character, string $game_slug ): array {
		$uuid = ( ! empty( $character['uuid'] ) && Uuid::is_valid( (string) $character['uuid'] ) )
			? strtolower( (string) $character['uuid'] )
			: null;

		if ( $uuid !== null ) {
			$existing = Character::find_by_uuid( $uuid );
			if ( $existing !== null ) {
				$here = $existing->owner_type === 'chronicle' && $existing->owner_slug === $game_slug;
				return [ 'existing' => $existing, 'matched_by' => $here ? 'uuid' : 'uuid_elsewhere' ];
			}
		}

		$existing = ( $character['name'] ?? '' ) !== '' ? Character::find_by_name_in_game( $character['name'], $game_slug ) : null;
		return [ 'existing' => $existing, 'matched_by' => $existing !== null ? 'name' : null ];
	}

	/**
	 * Creates one imported character with its resolved trait_list traits, identity and resource fields, and XP totals,
	 * matching a player by email where possible. When $existing is given, updates that character in place instead.
	 *
	 * @param int                    $game_id
	 * @param string                 $game_slug
	 * @param array<string,mixed>    $character
	 * @param string                 $source_file
	 * @param array<string,string>   $player_emails_by_name GV player name -> email.
	 * @param array<string,array<string,mixed>> $trait_resolutions Indexed by resolution_key() - see resolve_trait_for_import().
	 * @param object|null            $existing A real duplicate row to update in place instead of creating a new character.
	 * @param int|null               $submitted_by Set when the file came from this player's own submission: the character
	 *                               always belongs to them, is never an NPC or a bare player_name, and starts active.
	 * @return array<string,mixed>
	 */
	private static function import_character( int $game_id, string $game_slug, array $character, string $source_file, array $player_emails_by_name, array $trait_resolutions, ?object $existing, ?int $submitted_by = null ): array {
		$stack_slug = $character['race'];
		$sheet_data = [];
		$fuzzy_or_custom = [];
		$char_name  = self::character_display_name( $character );

		// Converts plain-text paragraphs to HTML before sanitizing, matching every other write path.
		foreach ( [ 'biography', 'notes' ] as $field ) {
			if ( ! empty( $character[ $field ] ) ) {
				$character[ $field ] = wp_kses_post( wpautop( (string) $character[ $field ] ) );
			}
		}

		$preserved_lists = [];
		$held_objects    = [];
		foreach ( $character['trait_lists'] ?? [] as $list ) {
			$classification = Trait_Mapper::classify_list( $stack_slug, $list['name'] );
			if ( in_array( $classification['outcome'], [ 'preserve_as_note', 'needs_design' ], true ) ) {
				$preserved_lists[] = $list;
			}
			if ( $classification['outcome'] === 'world_object' ) {
				$held_objects[] = [ 'type' => $classification['object_type'], 'traits' => $list['traits'] ];
			}
			if ( $classification['outcome'] !== 'sheet_block' ) {
				continue;
			}

			$block = Purchase_Scope::widen( Schema_Block::find_for_game( $classification['block_slug'], $game_slug ), $game_slug );
			if ( ! $block || ! in_array( $block->section_type, [ 'trait_list', 'tiered_power' ], true ) ) {
				continue; // Neither shape this importer knows how to build sheet_data for.
			}

			$block_slug = $block->slug;

			foreach ( $list['traits'] as $trait ) {
				[ $result, $target_block ] = self::resolve_trait_for_import( $trait, $block, $classification, $char_name, $trait_resolutions, $game_slug );
				$target_slug = $target_block->slug;
				self::require_clean_resolution( $result, $trait['name'], $target_slug );

				if ( $target_block->section_type === 'tiered_power' ) {
					if ( $result['outcome'] === 'custom' ) {
						// level is set only when a real numbered holding was derived from the raw value.
						$entry = [
							'name'       => $result['family'],
							'power_name' => $result['power_name'],
							'tier'       => $result['tier'],
							'custom'     => true,
						];
						if ( isset( $result['level'] ) ) {
							$entry['level'] = $result['level'];
						}
						$fuzzy_or_custom[] = [ 'block' => $target_slug, 'name' => $trait['name'], 'reason' => 'custom' ];
					} else {
						// A numbered rung carries level.
						$entry = isset( $result['power_name'] )
							? [ 'name' => $result['family'], 'power_name' => $result['power_name'] ]
							: [ 'name' => $result['family'], 'level' => $result['level'] ];
						// Carries a named tradition through onto the stored entry when the raw file states one.
						if ( isset( $result['tradition'] ) ) {
							$entry['tradition'] = $result['tradition'];
						}
					}
				} else {
					$entry = [ 'name' => $result['matched_name'] ?? $trait['name'], 'count' => (int) $trait['total'] ];
					if ( $trait['note'] !== '' ) {
						$entry['note'] = $trait['note'];
					}
					if ( $result['outcome'] === 'custom' ) {
						$entry['custom'] = true;
						$fuzzy_or_custom[] = [ 'block' => $target_slug, 'name' => $trait['name'], 'reason' => 'custom' ];
					}
				}

				$sheet_data[ $target_slug ]   = $sheet_data[ $target_slug ] ?? [];
				$sheet_data[ $target_slug ][] = $entry;
			}
		}

		self::apply_identity_and_resources( $sheet_data, $stack_slug, $character );

		$player_name  = $character['player'] ?? '';
		$player_email = $player_emails_by_name[ $player_name ] ?? '';
		$wp_user_id   = null;
		if ( $player_email !== '' ) {
			$user       = get_user_by( 'email', $player_email );
			$wp_user_id = $user ? $user->ID : null;
		}

		$experience = $character['experience'] ?? [ 'earned' => 0, 'unspent' => 0 ];
		$status     = ! empty( $character['status'] ) ? strtolower( $character['status'] ) : 'active';
		$is_npc     = (bool) ( $character['is_npc'] ?? false );
		$narrator   = $character['narrator'] ?? null;

		if ( $submitted_by !== null ) {
			// Never trusts the file's own player/NPC/narrator/status claims for a player-sent submission.
			$wp_user_id  = $submitted_by;
			$player_name = '';
			$status      = 'active';
			$is_npc      = false;
			$narrator    = null;
		}

		if ( $existing !== null ) {
			Character::update_header( (int) $existing->id, [
				'name'       => $character['name'],
				'status'     => $status,
				'is_npc'     => $is_npc,
				'narrator'   => $narrator,
				'start_date' => $character['start_date'] ?? null,
				'biography'  => $character['biography'] ?? null,
				'notes'      => $character['notes'] ?? null,
			] );
			// Replaces only the blocks this document fills in.
			$kept = array_diff_key(
				json_decode( (string) wp_json_encode( $existing->sheet_data ), true ) ?: [],
				array_flip( self::carried_blocks( $stack_slug, $character ) )
			);
			Character::update_sheet_data( (int) $existing->id, array_merge( $kept, $sheet_data ) );

			// update_xp() is delta-based.
			Character::update_xp(
				(int) $existing->id,
				(int) round( $experience['earned'] ) - (int) $existing->xp_earned,
				(int) round( $experience['unspent'] ) - (int) $existing->xp_unspent
			);

			$character_id = (int) $existing->id;
			$action       = 'overwritten';
		} else {
			$character_id = Character::create( [
				'name'        => $character['name'],
				'stack_slug'  => $stack_slug,
				'owner_type'  => 'chronicle',
				'owner_slug'  => $game_slug,
				// A transfer-marked export carries the character's real, permanent uuid.
				'uuid'        => $character['uuid'] ?? null,
				'wp_user_id'  => $wp_user_id,
				'player_name' => ( ! $wp_user_id && $player_name !== '' ) ? $player_name : null,
				// status is a free-text column, normalized to lowercase to match every other write path.
				'status'      => $status,
				'is_npc'      => $is_npc,
				'narrator'    => $narrator,
				'start_date'  => $character['start_date'] ?? null,
				'biography'   => $character['biography'] ?? null,
				'notes'       => $character['notes'] ?? null,
				'sheet_data'  => $sheet_data,
			] );

			if ( ! $character_id ) {
				throw new \RuntimeException( "Failed to create imported character \"{$character['name']}\"." );
			}

			Character::update_xp( $character_id, (int) round( $experience['earned'] ), (int) round( $experience['unspent'] ) );
			$action = 'created';
		}

		$added_to_catalog = self::connect_held_world_objects( $game_id, $character_id, $held_objects );

		// Records one import_note change per character with the source file and the unresolved data.
		$raw_record = $character;
		if ( $preserved_lists ) {
			$raw_record['trait_lists'] = $preserved_lists;
		} else {
			unset( $raw_record['trait_lists'] );
		}

		$change_data = [
			'source_file'      => $source_file,
			'imported_at'      => current_time( 'mysql' ),
			'fuzzy_or_custom'  => $fuzzy_or_custom,
			'raw_record'       => $raw_record,
			'action'           => $action,
		];

		if ( $submitted_by !== null ) {
			$change_data['submitted_by'] = $submitted_by;
			$sender = get_userdata( $submitted_by );
			$sender_name = $sender ? $sender->display_name : "#{$submitted_by}";
			$notes = $action === 'overwritten'
				? "Re-sent by {$sender_name} as {$source_file}, replacing the sheet here."
				: "Sent in by {$sender_name} as {$source_file}.";
		} else {
			$notes = $action === 'overwritten'
				? "Re-imported (overwritten) from {$source_file}."
				: "Imported from {$source_file}.";
		}

		Change_Engine::submit(
			$character_id,
			[
				'change_type' => 'import_note',
				'category'    => 'import',
				'change_data' => $change_data,
				'notes'       => $notes,
			],
			get_current_user_id()
		);

		return [ 'id' => $character_id, 'name' => $character['name'], 'action' => $action, 'added_to_catalog' => $added_to_catalog ];
	}

	/**
	 * Connects an imported character to the items and locations its file says it holds.
	 *
	 * @param int                                             $game_id
	 * @param int                                             $character_id
	 * @param array<int,array{type:string,traits:array<int,array<string,mixed>>}> $held_objects
	 * @return array<int,array{id:int,name:string,type:string}> Catalog entries this added.
	 */
	private static function connect_held_world_objects( int $game_id, int $character_id, array $held_objects ): array {
		$added = [];
		foreach ( $held_objects as $list ) {
			foreach ( $list['traits'] as $trait ) {
				$name = trim( (string) ( $trait['name'] ?? '' ) );
				if ( $name === '' ) {
					continue;
				}

				$object = World_Object::find_by_name_in_game( $game_id, $list['type'], $name );
				if ( $object ) {
					$object_id = (int) $object->id;
				} else {
					$object_id = World_Object::create( [ 'game_id' => $game_id, 'object_type' => $list['type'], 'name' => $name ] );
					if ( $object_id === false ) {
						throw new \RuntimeException( "Failed to add {$list['type']} \"{$name}\" to the catalog." );
					}
					$object_id = (int) $object_id;
					$added[]   = [ 'id' => $object_id, 'name' => $name, 'type' => $list['type'] ];
				}

				$note       = trim( (string) ( $trait['note'] ?? '' ) );
				$connection = Connection::create( [
					'game_id'     => $game_id,
					'source_type' => 'character',
					'source_id'   => $character_id,
					'target_type' => 'world_object',
					'target_id'   => $object_id,
					'notes'       => $note !== '' ? sanitize_textarea_field( $note ) : null,
				] );
				if ( $connection === false ) {
					throw new \RuntimeException( "Failed to connect \"{$name}\" to the imported character." );
				}
			}
		}
		return $added;
	}

	/**
	 * Converts a parsed LinkedTraitList into the flat [{name, count, note?}] shape a world object's trait_list-typed
	 * properties expect.
	 *
	 * @param array<string,mixed> $trait_list
	 * @return array<int,array<string,mixed>>
	 */
	private static function trait_list_to_properties( array $trait_list ): array {
		$out = [];
		foreach ( $trait_list['traits'] ?? [] as $trait ) {
			$entry = [ 'name' => $trait['name'], 'count' => (int) $trait['total'] ];
			if ( $trait['note'] !== '' ) {
				$entry['note'] = $trait['note'];
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Resolves one trait against a tiered_power block, falling back to a blood-magic sibling tiered_power block and then a
	 * combo/ritae sibling trait_list block when the primary resolution comes back unresolved.
	 *
	 * @param array<string,mixed> $trait
	 * @param object              $block          Decoded tiered_power Schema_Block.
	 * @param array<string,mixed> $classification `Trait_Mapper::classify_list()`'s result.
	 * @param string              $game_slug      Prefers this chronicle's own fork of a sibling block, if it has one.
	 * @return array{0:array<string,mixed>,1:object} The resolution result and whichever block it actually resolved against.
	 */
	private static function resolve_tiered_power_with_fallbacks( array $trait, $block, array $classification, string $game_slug = '' ): array {
		$result = Trait_Mapper::resolve_tiered_power_trait( $trait['name'], $trait['total'], $block );

		if ( $result['outcome'] === 'unresolved' && isset( $classification['blood_magic_block_slug'] ) ) {
			$blood_magic_block = Schema_Block::find_for_game( $classification['blood_magic_block_slug'], $game_slug );
			if ( $blood_magic_block ) {
				$blood_magic_result = Trait_Mapper::resolve_tiered_power_trait( $trait['name'], $trait['total'], $blood_magic_block );
				if ( in_array( $blood_magic_result['outcome'], [ 'exact', 'normalized' ], true ) ) {
					return [ $blood_magic_result, $blood_magic_block ];
				}
			}
		}

		if ( $result['outcome'] === 'unresolved' && isset( $classification['combo_block_slug'] ) ) {
			$combo_block = Schema_Block::find_for_game( $classification['combo_block_slug'], $game_slug );
			if ( $combo_block ) {
				$combo_result = Trait_Mapper::resolve_trait( $trait['name'], [ $combo_block ] );
				// A 'custom' outcome is excluded here; only a real, named match in the sibling catalog counts.
				if ( in_array( $combo_result['outcome'], [ 'exact', 'normalized' ], true ) ) {
					return [ $combo_result, $combo_block ];
				}
			}
		}

		return [ $result, $block ];
	}

	/**
	 * Resolves one classified trait, honoring an ST's explicit resolution override before falling through to normal
	 * resolution. Shared by the preview classification pass and the sheet-building pass.
	 *
	 * @param array<string,mixed>               $trait
	 * @param object                             $block          The originally classified block (tiered_power or trait_list).
	 * @param array<string,mixed>                $classification `Trait_Mapper::classify_list()`'s result.
	 * @param string                             $char_name
	 * @param array<string,array<string,mixed>>  $trait_resolutions Indexed by resolution_key() -> ['action' => 'apply_suggestion'|'skip', 'suggestion_name' => ?string].
	 * @param string                             $game_slug Prefers this chronicle's own fork of a sibling block, if it has one.
	 * @return array{0:array<string,mixed>,1:object}
	 */
	private static function resolve_trait_for_import( array $trait, $block, array $classification, string $char_name, array $trait_resolutions, string $game_slug = '' ): array {
		$is_tiered = $block->section_type === 'tiered_power';

		[ $result, $resolved_block ] = $is_tiered
			? self::resolve_tiered_power_with_fallbacks( $trait, $block, $classification, $game_slug )
			: [ Trait_Mapper::resolve_trait( $trait['name'], [ $block ] ), $block ];

		if ( ! in_array( $result['outcome'], [ 'fuzzy', 'unresolved', 'ambiguous' ], true ) ) {
			return [ $result, $resolved_block ];
		}

		$override = $trait_resolutions[ self::resolution_key( $char_name, $block->slug, $trait['name'] ) ] ?? null;
		if ( ! $override ) {
			return [ $result, $resolved_block ];
		}

		$action = $override['action'] ?? '';

		// keep_custom lets an ST keep an unmatched tiered_power trait as a custom entry.
		if ( $action === 'keep_custom' && $is_tiered ) {
			$custom = self::custom_tiered_power_result( $trait );

			// add_to_catalog opts in to also teaching the block's catalog this power, not just the sheet.
			if ( ! empty( $override['add_to_catalog'] ) ) {
				self::add_to_discipline_catalog( $block->slug, $custom['family'], $custom['power_name'], (string) ( $trait['note'] ?? '' ), $game_slug );
			}

			return [ $custom, $block ];
		}

		// keep_custom on a trait_list block builds a custom-outcome entry directly, respecting allow_custom.
		if ( $action === 'keep_custom' && ! empty( $block->definition->allow_custom ) ) {
			// Writes to this chronicle's own fork, never the shared global block.
			if ( ! empty( $override['add_to_catalog'] ) ) {
				self::add_to_trait_list_catalog( (string) $override['block'], $trait['name'], $game_slug );
			}
			return [ [ 'outcome' => 'custom', 'block_slug' => (string) $override['block'] ], $block ];
		}

		if ( $action !== 'apply_suggestion' || empty( $override['suggestion_name'] ) ) {
			return [ $result, $resolved_block ];
		}

		$chosen  = (string) $override['suggestion_name'];
		$applied = $is_tiered
			? Trait_Mapper::resolve_chosen( $trait['name'], (string) $trait['total'], $chosen, $block, [] )
			: Trait_Mapper::resolve_chosen( $trait['name'], (string) ( $trait['total'] ?? '' ), $chosen, null, [ $block ] );

		return [ $applied, $block ];
	}

	/**
	 * Builds a synthetic 'custom' tiered-power resolution from a raw trait, for an ST's explicit keep_custom override.
	 *
	 * @param array<string,mixed> $trait
	 * @return array{outcome:string,family:string,power_name:string,tier:string,level?:int}
	 */
	private static function custom_tiered_power_result( array $trait ): array {
		$raw = (string) $trait['name'];

		if ( preg_match( '/^([^:]+):\s*(.+)$/', $raw, $m ) ) {
			$family     = trim( $m[1] );
			$power_name = trim( $m[2] );
		} else {
			$family     = $raw;
			$power_name = $raw;
		}

		$note = (string) ( $trait['note'] ?? '' );
		$tier = $note !== '' ? $note : '***';

		$result = [
			'outcome'    => 'custom',
			'family'     => $family,
			'power_name' => $power_name,
			'tier'       => $tier,
		];

		// A combo-shaped section never derives a level from its raw total, which is a cost, not a level.
		$section          = (string) ( $trait['section'] ?? '' );
		$is_combo_section = $section !== ''
			&& ( stripos( $section, 'combo' ) !== false || stripos( $section, 'combination' ) !== false );

		if ( $note === '' && ! $is_combo_section ) {
			$raw_total = (string) ( $trait['total'] ?? '' );
			if ( ctype_digit( $raw_total ) && (int) $raw_total >= 1 && (int) $raw_total <= 5 ) {
				$result['level'] = (int) $raw_total;
			}
		}

		return $result;
	}

	/**
	 * Returns the standard numbered-rung cost/tier ladder for a tiered_power family's levels 1-5: costs 3/3/6/6/9 at
	 * tiers basic/basic/intermediate/intermediate/advanced.
	 *
	 * @return array<int,array{level:int,tier:string,cost:string,power_name:string}>
	 */
	private static function met_numbered_ladder(): array {
		return [
			[ 'level' => 1, 'tier' => 'basic', 'cost' => '3', 'power_name' => '' ],
			[ 'level' => 2, 'tier' => 'basic', 'cost' => '3', 'power_name' => '' ],
			[ 'level' => 3, 'tier' => 'intermediate', 'cost' => '6', 'power_name' => '' ],
			[ 'level' => 4, 'tier' => 'intermediate', 'cost' => '6', 'power_name' => '' ],
			[ 'level' => 5, 'tier' => 'advanced', 'cost' => '9', 'power_name' => '' ],
		];
	}

	/**
	 * Normalizes a raw trait's tier note (written abbreviated, such as "int." or "adv.") to the numbered-ladder tier
	 * label used by the catalog, or null when the note is not a numbered-rung tier at all.
	 *
	 * @param string $note
	 * @return string|null
	 */
	private static function normalize_numbered_tier( string $note ): ?string {
		return match ( strtolower( trim( $note ) ) ) {
			'basic' => 'basic',
			'int.', 'intermediate' => 'intermediate',
			'adv.', 'advanced' => 'advanced',
			default => null,
		};
	}

	/**
	 * Permanently adds a power name into a tiered_power block's catalog, for an ST who opts in via add_to_catalog. Requires
	 * be_manage_schemas. An already-named slot is never overwritten; a failure is logged and does not fail the import.
	 *
	 * @param string $block_slug
	 * @param string $family     The family name, already split from the raw trait name.
	 * @param string $power_name
	 * @param string $raw_tier   The trait's own raw tier note - `"basic"`/`"int."`/`"adv."`/other.
	 * @param string $game_slug  Writes go to this chronicle's own fork of the block, never the shared global catalog.
	 * @return void
	 */
	private static function add_to_discipline_catalog( string $block_slug, string $family, string $power_name, string $raw_tier, string $game_slug ): void {
		if ( ! \BeyondElysium\Core\Authorization::can( 'be_manage_schemas' ) || $game_slug === '' ) {
			return;
		}

		try {
			$block = Schema_Block::find_or_create_fork_for_game( $block_slug, $game_slug );
			if ( ! $block || $block->section_type !== 'tiered_power' ) {
				return;
			}

			$definition = $block->definition;
			$powers     = is_array( $definition->powers ?? null ) ? $definition->powers : [];

			$target_power = null;
			foreach ( $powers as $power ) {
				if ( strcasecmp( (string) ( $power->name ?? '' ), $family ) === 0 ) {
					$target_power = $power;
					break;
				}
			}

			$numbered_tier = self::normalize_numbered_tier( $raw_tier );

			if ( $target_power === null ) {
				$target_power = (object) [ 'name' => $family, 'levels' => [] ];
				if ( $numbered_tier !== null ) {
					foreach ( self::met_numbered_ladder() as $rung ) {
						$target_power->levels[] = (object) $rung;
					}
				}
				$powers[] = $target_power;
			}

			$filled = false;
			foreach ( $target_power->levels as $level ) {
				$already_named = ( $level->power_name ?? '' ) !== '';
				// A numbered rung matches by tier label; an elder+ pick matches any still-unnamed elder+ slot.
				$matches = $numbered_tier !== null
					? ( isset( $level->level ) && strtolower( (string) ( $level->tier ?? '' ) ) === $numbered_tier )
					: ! isset( $level->level );

				if ( ! $already_named && $matches ) {
					$level->power_name = $power_name;
					$filled = true;
					break;
				}
			}

			if ( ! $filled ) {
				// No open matching slot; appends a new one.
				$target_power->levels[] = (object) ( $numbered_tier !== null
					? [ 'tier' => $numbered_tier, 'cost' => '', 'power_name' => $power_name ]
					: [ 'tier' => $raw_tier !== '' ? $raw_tier : 'elder', 'power_name' => $power_name ] );
			}

			$definition->powers = $powers;
			Schema_Block::update( $block_slug, [ 'definition' => $definition ], $game_slug );
		} catch ( \Throwable $e ) {
			error_log( "Beyond Elysium: failed to add \"{$family}: {$power_name}\" to {$block_slug}'s catalog: " . $e->getMessage() );
		}
	}

	/**
	 * Permanently adds a trait name into a trait_list block's catalog (Merits, Backgrounds, Abilities, and similar), for
	 * an ST who opts in via add_to_catalog.
	 *
	 * @param string $block_slug
	 * @param string $name
	 * @param string $game_slug
	 * @return void
	 */
	private static function add_to_trait_list_catalog( string $block_slug, string $name, string $game_slug ): void {
		if ( ! \BeyondElysium\Core\Authorization::can( 'be_manage_schemas' ) || $game_slug === '' ) {
			return;
		}

		try {
			$block = Schema_Block::find_or_create_fork_for_game( $block_slug, $game_slug );
			if ( ! $block || $block->section_type !== 'trait_list' ) {
				return;
			}

			$definition = $block->definition;
			$items      = is_array( $definition->items ?? null ) ? $definition->items : [];

			foreach ( $items as $item ) {
				if ( strcasecmp( (string) ( $item->name ?? '' ), $name ) === 0 ) {
					return; // Already in this chronicle's catalog - nothing to do.
				}
			}

			$items[]            = (object) [ 'name' => $name ];
			$definition->items  = $items;
			Schema_Block::update( $block_slug, [ 'definition' => $definition ], $game_slug );
		} catch ( \Throwable $e ) {
			error_log( "Beyond Elysium: failed to add \"{$name}\" to {$block_slug}'s catalog: " . $e->getMessage() );
		}
	}

	/**
	 * Builds the composite key that indexes an ST's chosen resolution for one character's trait within one block.
	 *
	 * @param string $char_name
	 * @param string $block_slug
	 * @param string $raw_name
	 * @return string
	 */
	private static function resolution_key( string $char_name, string $block_slug, string $raw_name ): string {
		return $char_name . "\0" . $block_slug . "\0" . $raw_name;
	}

	/**
	 * Indexes the traits half of a commit's resolutions request body by resolution_key().
	 *
	 * @param array<int,array<string,mixed>> $traits Each: character, block, raw, action, suggestion_name?.
	 * @return array<string,array<string,mixed>>
	 */
	private static function index_trait_resolutions( array $traits ): array {
		$indexed = [];
		foreach ( $traits as $t ) {
			if ( ! is_array( $t ) || ! isset( $t['character'], $t['block'], $t['raw'] ) ) {
				continue;
			}
			$indexed[ self::resolution_key( (string) $t['character'], (string) $t['block'], (string) $t['raw'] ) ] = $t;
		}
		return $indexed;
	}

	/**
	 * Returns a parsed character's name, or the literal string "(unnamed)" when the record has no name.
	 *
	 * @param array<string,mixed> $character
	 * @return string
	 */
	private static function character_display_name( array $character ): string {
		return ( $character['name'] ?? '' ) !== '' ? $character['name'] : '(unnamed)';
	}

	/**
	 * Confirms a trait's resolution outcome is clean (exact, normalized, or custom) before it is written to a character's
	 * sheet_data, throwing when it is not.
	 *
	 * @param array<string,mixed> $result
	 * @param string              $raw_name
	 * @param string              $block_slug
	 */
	private static function require_clean_resolution( array $result, string $raw_name, string $block_slug ): void {
		$clean = [ 'exact', 'normalized', 'custom' ];
		if ( ! in_array( $result['outcome'], $clean, true ) ) {
			throw new \RuntimeException(
				"\"{$raw_name}\" in \"{$block_slug}\" no longer resolves cleanly - re-parse and review this import again."
			);
		}
	}

	/** @var array<string,array<string,mixed>>|null Cached gex-identity-map.php contents. */
	private static $identity_map = null;

	/**
	 * Loads and caches the identity/resource field mapping table from gex-identity-map.php.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function identity_map(): array {
		if ( self::$identity_map === null ) {
			self::$identity_map = require __DIR__ . '/../Services/gex-identity-map.php';
		}
		return self::$identity_map;
	}

	/**
	 * The sheet blocks an exchange document of this stack fills in: every block its race's trait lists map to (with the
	 * blood-magic and combo siblings those lists fold in), its identity and resource blocks, and Nature/Demeanor when
	 * this document carries them.
	 *
	 * @param string              $stack_slug
	 * @param array<string,mixed> $character The parsed document character.
	 * @return array<int,string>
	 */
	private static function carried_blocks( string $stack_slug, array $character ): array {
		$race = GEX_Parser::exchange_race( $stack_slug );
		if ( ! GEX_Parser::has_shape( $race ) ) {
			return [];
		}
		$shape  = GEX_Parser::shape( $race );
		$blocks = [];
		foreach ( $shape['trait_lists'] as $list ) {
			$classification = Trait_Mapper::classify_list( $stack_slug, $list['name'] );
			if ( $classification['outcome'] === 'sheet_block' ) {
				foreach ( [ 'block_slug', 'blood_magic_block_slug', 'combo_block_slug' ] as $key ) {
					if ( isset( $classification[ $key ] ) ) {
						$blocks[] = $classification[ $key ];
					}
				}
			}
		}

		$map = self::identity_map()[ $stack_slug ] ?? self::identity_map()[ $race ] ?? [];
		if ( isset( $map['identity']['block'] ) ) {
			$blocks[] = $map['identity']['block'];
		}
		foreach ( $map['resources'] ?? [] as $resource_block ) {
			$blocks[] = $resource_block['block'];
		}
		if ( array_key_exists( 'nature', $character ) && array_key_exists( 'demeanor', $character ) ) {
			$blocks[] = 'met-archetypes';
		}

		return array_values( array_unique( $blocks ) );
	}

	/**
	 * The identity/resource sheet_data a parsed character's raw scalars would populate for its stack.
	 *
	 * @param string              $stack_slug
	 * @param array<string,mixed> $character
	 * @return array<string,mixed>
	 */
	public static function identity_sheet_data( string $stack_slug, array $character ): array {
		$sheet_data = [];
		self::apply_identity_and_resources( $sheet_data, $stack_slug, $character );
		return $sheet_data;
	}

	/**
	 * Populates a stack's identity_field and resource_pool blocks directly from the character's raw parsed scalars via
	 * the identity map.
	 *
	 * @param array<string,mixed> $sheet_data Mutated in place.
	 * @param string              $stack_slug
	 * @param array<string,mixed> $character
	 */
	private static function apply_identity_and_resources( array &$sheet_data, string $stack_slug, array $character ): void {
		// met-archetypes (Nature/Demeanor) is shared verbatim by every stack that carries these keys.
		if ( array_key_exists( 'nature', $character ) && array_key_exists( 'demeanor', $character )
			&& Schema_Block::find_by_slug( 'met-archetypes' )
		) {
			$sheet_data['met-archetypes'] = array_merge(
				$sheet_data['met-archetypes'] ?? [],
				[ 'Nature' => $character['nature'], 'Demeanor' => $character['demeanor'] ]
			);
		}

		$race = GEX_Parser::exchange_race( $stack_slug );
		$map  = self::identity_map()[ $stack_slug ] ?? self::identity_map()[ $race ] ?? null;
		if ( $map === null ) {
			return;
		}

		$identity_block = isset( $map['identity'] ) ? Schema_Block::find_by_slug( $map['identity']['block'] ) : null;
		if ( $identity_block ) {
			$enums = [];
			foreach ( GEX_Parser::has_shape( $race ) ? GEX_Parser::shape( $race )['scalars'] : [] as $scalar ) {
				if ( isset( $scalar['xml_enum'] ) ) {
					$enums[ $scalar['key'] ] = $scalar['xml_enum'];
				}
			}
			$types = [];
			foreach ( (array) ( $identity_block->definition->fields ?? [] ) as $field ) {
				$types[ $field->name ] = $field->field_type ?? '';
			}

			$fields = [];
			foreach ( $map['identity']['fields'] as $be_field => $raw_key ) {
				$value = $character[ $raw_key ] ?? null;
				// Parsers hand an enum field back as its index.
				if ( isset( $enums[ $raw_key ] ) && is_int( $value ) ) {
					$value = $enums[ $raw_key ][ $value ] ?? $value;
				}
				if ( ( $types[ $be_field ] ?? '' ) === 'number' && is_numeric( $value ) ) {
					$value = $value + 0;
				}
				$fields[ $be_field ] = $value;
			}
			$sheet_data[ $map['identity']['block'] ] = array_merge( $sheet_data[ $map['identity']['block'] ] ?? [], $fields );
		}

		foreach ( $map['resources'] ?? [] as $resource_block ) {
			if ( ! Schema_Block::find_by_slug( $resource_block['block'] ) ) {
				continue;
			}
			$fields = [];
			foreach ( $resource_block['fields'] as $pool_name => [ $perm_key, $temp_key ] ) {
				$fields[ $pool_name ] = [
					'permanent' => (float) ( $character[ $perm_key ] ?? 0 ),
					'temporary' => (float) ( $character[ $temp_key ] ?? 0 ),
				];
			}
			$sheet_data[ $resource_block['block'] ] = array_merge( $sheet_data[ $resource_block['block'] ] ?? [], $fields );
		}
	}

	/**
	 * Returns a previously parsed import job's stored preview by job id, or a 404 when the job cannot be found or has
	 * expired.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$job = get_transient( self::job_transient_key( (string) $request['job_id'] ) );
		if ( ! $job || (int) $job['game_id'] !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'No import job found with that id - it may have expired.', 'beyond-elysium' ), 404 );
		}

		return $this->success( array_merge( [ 'job_id' => $request['job_id'] ], $job['preview'] ) );
	}

	/**
	 * Builds the transient key used to store one import job's parsed data and preview.
	 *
	 * @param string $job_id
	 * @return string
	 */
	private static function job_transient_key( string $job_id ): string {
		return 'be_import_job_' . $job_id;
	}

	/**
	 * Determines a file's format from its header: the literal string <?xml for an XML export, or one of the
	 * GVBE/GVBM/GVBG binary magic strings found at byte offset 2 (after their 2-byte length prefix).
	 *
	 * @param string $data
	 * @return string 'GVBE'|'GVBM'|'GVBG'|'XML'|'unknown'
	 */
	public static function sniff_format( string $data ): string {
		if ( str_starts_with( $data, '<?xml' ) ) {
			return 'XML';
		}

		$magic = substr( $data, 2, 4 );
		if ( in_array( $magic, [ 'GVBE', 'GVBM', 'GVBG' ], true ) ) {
			return $magic;
		}

		return 'unknown';
	}

	/**
	 * Builds the review preview structure from a parsed GEX structure: counts per section, every trait_list classified,
	 * every trait of each sheet_block list resolved into flagged and unresolved buckets, and every character and world
	 * object already in this chronicle by exact name.
	 *
	 * @param array<string,mixed> $parsed
	 * @param string              $game_slug
	 * @param int                 $game_id     Needed alongside `$game_slug` because `World_Object::find_by_name_in_game()` is scoped by id, not slug (unlike `Character`'s equivalent).
	 * @param array<string,mixed> $resolutions Optional - `parse()`'s first preview has none yet; `commit()` passes what the client just chose.
	 * @param string              $format      'GVBE'|'XML'|'GVBG' - purely informational (the response's own `format` field); every parser produces the identical `$parsed` shape, so nothing else here branches on it.
	 * @return array<string,mixed>
	 */
	public static function build_preview( array $parsed, string $game_slug, int $game_id, array $resolutions = [], string $format = 'GVBE' ): array {
		$counts = [
			'players'    => count( $parsed['players'] ),
			'characters' => count( $parsed['characters'] ),
			'queries'    => count( $parsed['queries'] ),
			'items'      => count( $parsed['items'] ),
			'rotes'      => count( $parsed['rotes'] ),
			'locations'  => count( $parsed['locations'] ),
			'actions'    => count( $parsed['actions'] ),
			'plots'      => count( $parsed['plots'] ),
			'rumors'     => count( $parsed['rumors'] ),
		];

		$flagged               = [];
		$unresolved            = [];
		$duplicates            = [];
		$world_object_duplicates = [];
		// How many entries in the file share each matched name.
		$character_repeats = [];
		$object_repeats    = [];
		$block_cache            = [];
		$trait_resolutions      = self::index_trait_resolutions( (array) ( $resolutions['traits'] ?? [] ) );

		// Matched the same way as characters: by exact name within the game and object_type.
		foreach ( [ 'items' => 'item', 'locations' => 'location', 'rotes' => 'rote' ] as $parsed_key => $object_type ) {
			foreach ( $parsed[ $parsed_key ] as $entry ) {
				$name = $entry['name'] ?? '';
				if ( $name === '' ) {
					continue;
				}
				$existing = World_Object::find_by_name_in_game( $game_id, $object_type, $name );
				$key      = "{$object_type}:{$name}";
				if ( $existing && ! isset( $object_repeats[ $key ] ) ) {
					$world_object_duplicates[] = [
						'type'        => $object_type,
						'name'        => $name,
						'existing_id' => (int) $existing->id,
					];
				}
				if ( $existing ) {
					$object_repeats[ $key ] = ( $object_repeats[ $key ] ?? 0 ) + 1;
				}
			}
		}

		foreach ( $parsed['characters'] as $character ) {
			$stack_slug = $character['race'];
			$char_name  = self::character_display_name( $character );

			$match    = self::match_existing_character( $character, $game_slug );
			$existing = $match['existing'];
			if ( $existing ) {
				$character_repeats[ $char_name ] = ( $character_repeats[ $char_name ] ?? 0 ) + 1;
			}
			if ( $existing && $character_repeats[ $char_name ] === 1 ) {
				$elsewhere    = $match['matched_by'] === 'uuid_elsewhere';
				$duplicates[] = [
					'character'     => $char_name,
					// Another chronicle's character: its row id is not this Storyteller's to see.
					'existing_id'   => $elsewhere ? 0 : (int) $existing->id,
					'existing_uuid' => $existing->uuid,
					// 'uuid': the same character, already here; 'uuid_elsewhere': the same character in another chronicle; 'name': a possible namesake.
					'matched_by'    => $match['matched_by'],
				];
			}

			// Trait lists are nested under trait_lists, keyed by GV list name.
			foreach ( $character['trait_lists'] ?? [] as $value ) {
				if ( ! is_array( $value ) || ! isset( $value['traits'] ) || ! isset( $value['name'] ) ) {
					continue; // Not a parsed trait_list field.
				}

				$classification = Trait_Mapper::classify_list( $stack_slug, $value['name'] );

				if ( $classification['outcome'] !== 'sheet_block' ) {
					continue; // world_object / discard_derived / preserve_as_note / needs_design - not a per-trait review item.
				}

				$block_slug = $classification['block_slug'];
				if ( ! array_key_exists( $block_slug, $block_cache ) ) {
					// Uses this chronicle's own fork, if any, and its wider purchase list.
					$block_cache[ $block_slug ] = Purchase_Scope::widen( Schema_Block::find_for_game( $block_slug, $game_slug ), $game_slug );
				}
				$block = $block_cache[ $block_slug ];
				if ( ! $block ) {
					continue;
				}

				foreach ( $value['traits'] as $trait ) {
					[ $result ] = self::resolve_trait_for_import( $trait, $block, $classification, $char_name, $trait_resolutions, $game_slug );

					if ( $result['outcome'] === 'fuzzy' ) {
						$flagged[] = [
							'character'   => $char_name,
							'block'       => $block_slug,
							'raw'         => $trait['name'],
							'suggestions' => $result['suggestions'],
							'reason'      => 'fuzzy_match',
						];
					} elseif ( $result['outcome'] === 'unresolved' ) {
						$unresolved[] = [
							'character' => $char_name,
							'block'     => $block_slug,
							'raw'       => $trait['name'],
						];
					} elseif ( $result['outcome'] === 'ambiguous' ) {
						$unresolved[] = [
							'character' => $char_name,
							'block'     => $block_slug,
							'raw'       => $trait['name'],
							'reason'    => 'ambiguous',
						];
					}
				}
			}
		}

		$warnings = [];
		$repeated_names = [];
		foreach ( $character_repeats as $name => $count ) {
			$repeated_names[] = [ (string) $name, $count ];
		}
		foreach ( $object_repeats as $key => $count ) {
			$repeated_names[] = [ substr( (string) $key, strpos( (string) $key, ':' ) + 1 ), $count ];
		}
		foreach ( $repeated_names as [ $name, $count ] ) {
			if ( $count > 1 ) {
				$warnings[] = sprintf(
					/* translators: 1: character, item, location, or rote name, 2: how many entries in the file carry it */
					__( '%1$s appears %2$d times in this file. Overwrite replaces the one already here with the first and imports the rest separately; Skip skips them all.', 'beyond-elysium' ),
					$name,
					$count
				);
			}
		}
		if ( ! empty( $parsed['characters'] ) ) {
			$warnings[] = __( 'If this file was exported with ST filtering on, hidden text has already been removed and cannot be recovered.', 'beyond-elysium' );
		}

		// Items and locations a character holds that neither this chronicle's catalog nor the file itself defines.
		$defined_in_file = [
			'item'     => array_column( $parsed['items'] ?? [], 'name' ),
			'location' => array_column( $parsed['locations'] ?? [], 'name' ),
		];
		foreach ( $parsed['characters'] as $character ) {
			$new_entries = [];
			foreach ( $character['trait_lists'] ?? [] as $list ) {
				if ( ! is_array( $list ) || ! isset( $list['traits'], $list['name'] ) ) {
					continue;
				}
				$classification = Trait_Mapper::classify_list( (string) $character['race'], (string) $list['name'] );
				if ( $classification['outcome'] !== 'world_object' ) {
					continue;
				}
				foreach ( $list['traits'] as $trait ) {
					$name = trim( (string) ( $trait['name'] ?? '' ) );
					if ( $name !== '' && ! in_array( $name, $defined_in_file[ $classification['object_type'] ], true )
						&& ! World_Object::find_by_name_in_game( $game_id, $classification['object_type'], $name ) ) {
						$new_entries[] = $name;
					}
				}
			}
			if ( $new_entries ) {
				$warnings[] = sprintf(
					/* translators: 1: character name, 2: comma-separated item and location names */
					__( '%1$s holds items or locations this chronicle\'s catalog does not have yet; importing adds them by name: %2$s.', 'beyond-elysium' ),
					self::character_display_name( $character ),
					implode( ', ', array_unique( $new_entries ) )
				);
			}
		}

		return [
			'format'                   => $format,
			'version'                  => $parsed['version'],
			'counts'                   => $counts,
			'players_needing_match'    => self::match_players( $parsed['players'] ),
			'flagged_traits'           => $flagged,
			'unresolved'               => $unresolved,
			'duplicates'               => $duplicates,
			'world_object_duplicates'  => $world_object_duplicates,
			'warnings'                 => $warnings,
		];
	}

	/**
	 * Adds `changes` to each duplicate matched in this chronicle: what differs between the sheet already here and the one
	 * arriving, for a Storyteller deciding whether to overwrite it.
	 *
	 * @param array<int,array<string,mixed>> $duplicates `build_preview()`'s `duplicates`.
	 * @param array<string,mixed>            $parsed
	 * @param string                         $game_slug
	 * @return array<int,array<string,mixed>>
	 */
	public static function with_changes( array $duplicates, array $parsed, string $game_slug ): array {
		foreach ( $parsed['characters'] as $character ) {
			$match = self::match_existing_character( $character, $game_slug );
			if ( ! $match['existing'] || $match['matched_by'] === 'uuid_elsewhere' ) {
				continue;
			}
			$name = self::character_display_name( $character );
			foreach ( $duplicates as $index => $duplicate ) {
				if ( $duplicate['character'] === $name && ! isset( $duplicate['changes'] ) ) {
					try {
						$duplicates[ $index ]['changes'] = Character_Diff::against( $character, $match['existing'] );
					} catch ( Not_Exportable_Exception ) {
						// The sheet already here has no exchange shape to compare against.
					}
					break;
				}
			}
		}
		return $duplicates;
	}

	/**
	 * Suggests WP user matches for imported players not already matched by email, searching by display name instead.
	 *
	 * @param array<int,array<string,mixed>> $players
	 * @return array<int,array<string,mixed>>
	 */
	private static function match_players( array $players ): array {
		$needing_match = [];

		foreach ( $players as $player ) {
			$email = $player['email'] ?? '';
			$user  = $email ? get_user_by( 'email', $email ) : false;

			if ( $user ) {
				continue; // Matched by email - nothing to flag.
			}

			$name_matches = $player['name']
				? get_users( [
					'search'         => $player['name'],
					'search_columns' => [ 'display_name' ],
					'number'         => 3,
				] )
				: [];

			$needing_match[] = [
				'gv_name'      => $player['name'] ?? '',
				'gv_email'     => $email,
				'suggestions'  => array_map(
					static fn( $u ) => [ 'wp_user_id' => $u->ID, 'display_name' => $u->display_name ],
					$name_matches
				),
			];
		}

		return $needing_match;
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error with a 404 status when no game matches.
	 *
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	private function resolve_game( string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
