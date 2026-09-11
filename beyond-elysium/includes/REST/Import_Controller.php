<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\GEX_Parser;
use BeyondElysium\Services\GEX_Xml_Parser;
use BeyondElysium\Services\GV_Binary_Reader;
use BeyondElysium\Services\Trait_Mapper;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for importing Grapevine exchange files into a game. Runs a
 * two-phase parse/commit flow: parse sniffs the file format, parses it with
 * the matching parser, classifies and resolves every character trait against
 * the schema catalog, and stores the result server-side keyed by a job id.
 * Commit re-derives that same result from the stored job, refuses to proceed
 * while any trait is unresolved or fuzzy-matched or any duplicate character
 * or world object is unaddressed, and then applies the import in a single
 * transaction: items, locations, and rotes become world object rows;
 * characters are created or, for an addressed duplicate, updated in place;
 * and each imported character receives an import_note change carrying the
 * source file, every fuzzy/custom trait, and the raw record for any data
 * the trait catalog does not cover. Re-committing an already-committed job
 * returns the original result rather than importing a second time.
 *
 * @see BE_PROCESS/workflow-0.8.md Step 6, Step 9
 */
class Import_Controller extends Base_Controller {

	protected $rest_base = 'import';

	/** Transient TTL for a parsed job, in seconds. */
	const JOB_TTL = HOUR_IN_SECONDS;

	/**
	 * Registers the REST routes for parsing an uploaded file, fetching a
	 * parsed job's preview, and committing a reviewed job. All routes are
	 * scoped to a game slug and require the be_import capability.
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
	 * Sniffs the uploaded file's format, parses it with the matching
	 * parser, builds a review preview, and stores the parsed job server-
	 * side keyed by a generated job id for a later commit call.
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
			// Full game file import is not yet supported.
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
	 * Applies a previously parsed and reviewed import job. Refuses to
	 * proceed while any trait remains fuzzy or unresolved, recomputed
	 * fresh from the stored job rather than trusted from the client, then
	 * creates every item, location, rote, and character in one
	 * transaction. Re-posting an already-committed job id returns the
	 * same stored result again rather than importing a second time.
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

		// Only the resolution choices come from the request; everything else is re-derived from the stored job.
		$resolutions = (array) ( $request['resolutions'] ?? [] );
		$parsed      = $job['parsed'];
		$preview     = self::build_preview( $parsed, $game->slug, (int) $game->id, $resolutions, (string) ( $job['format'] ?? 'GVBE' ) );

		$block = self::blocking_reason( $preview, $resolutions );
		if ( $block !== null ) {
			return $this->error( $block['code'], $block['message'], $block['status'] );
		}

		global $wpdb;
		$nested = (int) $wpdb->get_var( 'SELECT @@autocommit' ) === 0;
		$wpdb->query( $nested ? 'SAVEPOINT be_import_commit' : 'START TRANSACTION' );

		try {
			$result = self::apply_import( (int) $game->id, $game->slug, $parsed, (string) $job['source_file'], $resolutions );
		} catch ( \Throwable $e ) {
			$wpdb->query( $nested ? 'ROLLBACK TO SAVEPOINT be_import_commit' : 'ROLLBACK' );
			return $this->error( 'commit_failed', $e->getMessage(), 500 );
		}

		$wpdb->query( $nested ? 'RELEASE SAVEPOINT be_import_commit' : 'COMMIT' );

		$job['committed_result'] = $result;
		set_transient( $job_key, $job, self::JOB_TTL );

		return $this->success( $result );
	}

	/**
	 * Checks a freshly re-derived preview for anything that must be
	 * resolved before a commit may proceed: unresolved or flagged
	 * traits, and any real duplicate character or world object left
	 * unaddressed. Public and static so another controller can share the
	 * same rule. Returns a plain array rather than a WP_Error since this
	 * is callable outside an instance context.
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

		// A real duplicate character must have an explicit skip/overwrite/import_as_new choice.
		$duplicate_actions = (array) ( $resolutions['duplicates'] ?? [] );
		$unaddressed       = [];
		foreach ( $preview['duplicates'] as $dup ) {
			$action = $duplicate_actions[ $dup['character'] ] ?? null;
			if ( ! in_array( $action, [ 'skip', 'overwrite', 'import_as_new' ], true ) ) {
				$unaddressed[] = $dup['character'];
			}
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
	 * Applies a fully-resolved import job within a single transaction:
	 * creates every parsed item, location, and rote as a world object,
	 * then creates or updates each parsed character. Public so it can be
	 * reused directly once a caller has resolved which game to import
	 * into; the caller is responsible for wrapping this call in its own
	 * transaction.
	 *
	 * @param int                  $game_id
	 * @param string               $game_slug
	 * @param array<string,mixed>  $parsed
	 * @param string               $source_file
	 * @param array<string,mixed>  $resolutions Duplicate/trait resolution choices (Chunk 1 of the Import plan) - see commit()'s own doc comment.
	 * @return array<string,mixed>
	 */
	public static function apply_import( int $game_id, string $game_slug, array $parsed, string $source_file, array $resolutions ): array {
		$created = [ 'items' => [], 'locations' => [], 'rotes' => [], 'characters' => [] ];
		$world_object_actions = (array) ( $resolutions['world_objects'] ?? [] );

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
				$world_object_actions
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
				$world_object_actions
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
				$world_object_actions
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
			$existing  = ( $character['name'] ?? '' ) !== '' ? Character::find_by_name_in_game( $character['name'], $game_slug ) : null;
			$action    = $existing ? ( $duplicate_actions[ $char_name ] ?? '' ) : '';

			if ( $existing && $action === 'skip' ) {
				$created['characters'][] = [ 'id' => (int) $existing->id, 'name' => $char_name, 'action' => 'skipped' ];
				continue;
			}

			$created['characters'][] = self::import_character(
				$game_id,
				$game_slug,
				$character,
				$source_file,
				$player_emails_by_name,
				$trait_resolutions,
				$action === 'overwrite' ? $existing : null
			);
		}

		// Actions/plots/rumors/queries have no import destination; counted so they stay visible, not silently dropped.
		foreach ( [ 'actions', 'plots', 'rumors', 'queries' ] as $kind ) {
			if ( ! empty( $parsed[ $kind ] ) ) {
				$created[ "skipped_{$kind}" ] = count( $parsed[ $kind ] );
			}
		}

		return $created;
	}

	/**
	 * Creates one imported item, location, or rote, or, for a duplicate
	 * the ST chose to overwrite, updates the existing row in place
	 * instead. Matches an existing object by exact name within the game
	 * and object_type.
	 *
	 * @param int                  $game_id
	 * @param string               $object_type 'item'|'location'|'rote'.
	 * @param string               $name
	 * @param string|null          $description
	 * @param array<string,mixed>  $properties
	 * @param array<string,string> $duplicate_actions Keyed by "{object_type}:{name}" -> 'skip'|'overwrite'|'import_as_new'.
	 * @return array{id:int,name:string,action:string}
	 */
	private static function import_world_object( int $game_id, string $object_type, string $name, ?string $description, array $properties, array $duplicate_actions ): array {
		$existing = $name !== '' ? World_Object::find_by_name_in_game( $game_id, $object_type, $name ) : null;
		$action   = $existing ? ( $duplicate_actions[ "{$object_type}:{$name}" ] ?? '' ) : '';

		if ( $existing && $action === 'skip' ) {
			return [ 'id' => (int) $existing->id, 'name' => $name, 'action' => 'skipped' ];
		}

		if ( $existing && $action === 'overwrite' ) {
			$ok = World_Object::update( (int) $existing->id, [ 'description' => $description, 'properties' => $properties ] );
			if ( ! $ok ) {
				throw new \RuntimeException( "Failed to overwrite {$object_type} \"{$name}\"." );
			}
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
		return [ 'id' => (int) $id, 'name' => $name, 'action' => 'created' ];
	}

	/**
	 * Creates one imported character with its resolved trait_list traits,
	 * identity and resource fields, and XP totals, matching a player by
	 * email where possible. When $existing is given, updates that
	 * character in place instead of creating a new one. Records an
	 * import_note change on the character carrying the source file and
	 * everything the trait catalog did not resolve.
	 *
	 * @param int                    $game_id
	 * @param string                 $game_slug
	 * @param array<string,mixed>    $character
	 * @param string                 $source_file
	 * @param array<string,string>   $player_emails_by_name GV player name -> email (Step 6d).
	 * @param array<string,array<string,mixed>> $trait_resolutions Indexed by resolution_key() - see resolve_trait_for_import().
	 * @param object|null            $existing A real duplicate row to update in place instead of creating a new character (Chunk 1 of the Import plan) - null for a normal create.
	 * @return array<string,mixed>
	 */
	private static function import_character( int $game_id, string $game_slug, array $character, string $source_file, array $player_emails_by_name, array $trait_resolutions, ?object $existing ): array {
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

		foreach ( $character['trait_lists'] ?? [] as $list ) {
			$classification = Trait_Mapper::classify_list( $stack_slug, $list['name'] );
			if ( $classification['outcome'] !== 'sheet_block' ) {
				continue;
			}

			// Prefers this chronicle's own fork of the block when one exists.
			$block = Schema_Block::find_for_game( $classification['block_slug'], $game_slug );
			if ( ! $block || ! in_array( $block->section_type, [ 'trait_list', 'tiered_power' ], true ) ) {
				continue; // Neither shape this importer knows how to build sheet_data for.
			}

			$block_slug = $block->slug;

			foreach ( $list['traits'] as $trait ) {
				[ $result, $target_block ] = self::resolve_trait_for_import( $trait, $block, $classification, $char_name, $trait_resolutions, $game_slug );
				$target_slug = $target_block->slug;
				self::require_clean_resolution( $result, $trait['name'], $target_slug );

				if ( $block->section_type === 'tiered_power' ) {

					if ( $target_block === $block ) {
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
							// A numbered rung carries level; an Elder-and-above pick carries power_name instead, never both.
							$entry = isset( $result['power_name'] )
								? [ 'name' => $result['family'], 'power_name' => $result['power_name'] ]
								: [ 'name' => $result['family'], 'level' => $result['level'] ];
							// Carries a named tradition through onto the stored entry when the raw file states one.
							if ( isset( $result['tradition'] ) ) {
								$entry['tradition'] = $result['tradition'];
							}
						}
					} else {
						// Resolved against the combo/ritae sibling block instead, which is trait_list-shaped.
						$entry = [ 'name' => $result['matched_name'] ?? $trait['name'], 'count' => (int) $trait['total'] ];
						if ( $trait['note'] !== '' ) {
							$entry['note'] = $trait['note'];
						}
						if ( $result['outcome'] === 'custom' ) {
							$entry['custom'] = true;
							$fuzzy_or_custom[] = [ 'block' => $target_slug, 'name' => $trait['name'], 'reason' => 'custom' ];
						}
					}
				} else {
					$entry = [ 'name' => $result['matched_name'] ?? $trait['name'], 'count' => (int) $trait['total'] ];
					if ( $trait['note'] !== '' ) {
						$entry['note'] = $trait['note'];
					}
					if ( $result['outcome'] === 'custom' ) {
						$entry['custom'] = true;
						$fuzzy_or_custom[] = [ 'block' => $block_slug, 'name' => $trait['name'], 'reason' => 'custom' ];
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

		if ( $existing !== null ) {
			// Updates in place rather than delete-and-recreate, so existing connections survive; uuid/id and ownership are left untouched.
			Character::update_header( (int) $existing->id, [
				'name'       => $character['name'],
				'status'     => $status,
				'is_npc'     => (bool) ( $character['is_npc'] ?? false ),
				'narrator'   => $character['narrator'] ?? null,
				'start_date' => $character['start_date'] ?? null,
				'biography'  => $character['biography'] ?? null,
				'notes'      => $character['notes'] ?? null,
			] );
			Character::update_sheet_data( (int) $existing->id, $sheet_data );

			// update_xp() is delta-based, so the absolute imported totals are diffed against what's already stored.
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
				'wp_user_id'  => $wp_user_id,
				'player_name' => ( ! $wp_user_id && $player_name !== '' ) ? $player_name : null,
				// status is a free-text column, normalized to lowercase to match every other write path.
				'status'      => $status,
				'is_npc'      => (bool) ( $character['is_npc'] ?? false ),
				'narrator'    => $character['narrator'] ?? null,
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

		// Records one import_note change per character carrying the source file and raw data not resolved into sheet_data.
		$raw_record = $character;
		unset( $raw_record['trait_lists'] ); // Already resolved into sheet_data above.

		Change_Engine::submit(
			$character_id,
			[
				'change_type' => 'import_note',
				'category'    => 'import',
				'change_data' => [
					'source_file'      => $source_file,
					'imported_at'      => current_time( 'mysql' ),
					'fuzzy_or_custom'  => $fuzzy_or_custom,
					'raw_record'       => $raw_record,
					'action'           => $action,
				],
				'notes'       => $action === 'overwritten'
					? "Re-imported (overwritten) from {$source_file}."
					: "Imported from {$source_file}.",
			],
			get_current_user_id()
		);

		return [ 'id' => $character_id, 'name' => $character['name'], 'action' => $action ];
	}

	/**
	 * Converts a parsed LinkedTraitList into the flat [{name, count, note?}]
	 * shape a world object's trait_list-typed properties expect. Unlike a
	 * character's trait_list traits, these values are not resolved against
	 * a schema catalog.
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
	 * Resolves one trait against a tiered_power block, falling back to
	 * its combo/ritae sibling trait_list block when the primary
	 * resolution comes back unresolved. A held Combo Discipline or Ritae
	 * power appears as a flat entry in the same raw list as an ordinary
	 * power, distinguished only by matching a name in the sibling
	 * catalog.
	 *
	 * @param array<string,mixed> $trait
	 * @param object              $block          Decoded tiered_power Schema_Block.
	 * @param array<string,mixed> $classification `Trait_Mapper::classify_list()`'s result.
	 * @param string              $game_slug      workflow-0.9.md Step 0.5e-3 - prefers this
	 *                                             chronicle's own fork of the combo/ritae
	 *                                             sibling block, if it has customized it.
	 * @return array{0:array<string,mixed>,1:object} The resolution result and whichever
	 *                                                 block it actually resolved against.
	 */
	private static function resolve_tiered_power_with_combo_fallback( array $trait, $block, array $classification, string $game_slug = '' ): array {
		$result = Trait_Mapper::resolve_tiered_power_trait( $trait['name'], $trait['total'], $block );

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
	 * Resolves one classified trait, honoring an ST's explicit resolution
	 * override before falling through to normal resolution. Shared by the
	 * preview classification pass and the sheet-building pass so both
	 * agree on the same outcome for the same character/block/raw-name
	 * combination. The resolution key is always keyed on the originally
	 * classified block, never the combo/ritae sibling block a
	 * tiered_power resolution might fall back to instead.
	 *
	 * @param array<string,mixed>               $trait
	 * @param object                             $block          The originally classified block (tiered_power or trait_list).
	 * @param array<string,mixed>                $classification `Trait_Mapper::classify_list()`'s result.
	 * @param string                             $char_name
	 * @param array<string,array<string,mixed>>  $trait_resolutions Indexed by resolution_key() -> ['action' => 'apply_suggestion'|'skip', 'suggestion_name' => ?string].
	 * @param string                             $game_slug workflow-0.9.md Step 0.5e-3.
	 * @return array{0:array<string,mixed>,1:object}
	 */
	private static function resolve_trait_for_import( array $trait, $block, array $classification, string $char_name, array $trait_resolutions, string $game_slug = '' ): array {
		$is_tiered = $block->section_type === 'tiered_power';

		[ $result, $resolved_block ] = $is_tiered
			? self::resolve_tiered_power_with_combo_fallback( $trait, $block, $classification, $game_slug )
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
				// Reuses the known block slug from the override rather than re-reading $block->slug.
				self::add_to_trait_list_catalog( (string) $override['block'], $trait['name'], $game_slug );
			}
			// Reuses the known block slug rather than a second read off $block itself.
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
	 * Builds a synthetic 'custom' tiered-power resolution from a raw
	 * trait, for an ST's explicit keep_custom override. Splits the raw
	 * name into family and power name on the first colon. Also derives a
	 * numbered level (1-5) from the raw total when there is no tier note
	 * and the trait is not from a combo-shaped section, since a
	 * note-carrying total is a cost, not a level.
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
		$tier = $note !== '' ? $note : 'unmatched, imported as-is';

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
	 * Returns the standard numbered-rung cost/tier ladder for a
	 * tiered_power family's levels 1-5: costs 3/3/6/6/9 at tiers
	 * basic/basic/intermediate/intermediate/advanced. Level 6 and above
	 * (elder and beyond) has no numbered position and is not represented
	 * here.
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
	 * Normalizes a raw trait's tier note (written abbreviated, such as
	 * "int." or "adv.") to the numbered-ladder tier label used by the
	 * catalog, or null when the note is not a numbered-rung tier at all.
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
	 * Permanently adds a power name into a tiered_power block's catalog,
	 * for an ST who opts in via add_to_catalog rather than only
	 * recording it on one character's sheet. Requires be_manage_schemas,
	 * since this mutates data every future import and every character in
	 * the chronicle reads. Idempotent: an already-named slot is never
	 * overwritten, and a repeated call for the same power is a harmless
	 * no-op. A failure here is logged and swallowed rather than failing
	 * the character import.
	 *
	 * @param string $block_slug
	 * @param string $family     The family name, already split from the raw trait name.
	 * @param string $power_name
	 * @param string $raw_tier   The trait's own raw tier note - `"basic"`/`"int."`/`"adv."`/other.
	 * @param string $game_slug  workflow-0.9.md Step 0.5e - writes go to THIS chronicle's
	 *                            own fork of the block, never the shared global catalog
	 *                            (Decision 068's own original scope for this exact reason -
	 *                            an ST's addition must not leak into every other chronicle).
	 * @return void
	 */
	private static function add_to_discipline_catalog( string $block_slug, string $family, string $power_name, string $raw_tier, string $game_slug ): void {
		if ( ! current_user_can( 'be_manage_schemas' ) || $game_slug === '' ) {
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
				// No open matching slot; appends a new one rather than overwriting an existing name.
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
	 * Permanently adds a trait name into a trait_list block's catalog
	 * (Merits, Backgrounds, Abilities, and similar), for an ST who opts
	 * in via add_to_catalog. Writes to this chronicle's own fork of the
	 * block, never the shared global block, and is idempotent on a
	 * case-insensitive name match. A failure here is logged and
	 * swallowed rather than failing the character import.
	 *
	 * @param string $block_slug
	 * @param string $name
	 * @param string $game_slug
	 * @return void
	 */
	private static function add_to_trait_list_catalog( string $block_slug, string $name, string $game_slug ): void {
		if ( ! current_user_can( 'be_manage_schemas' ) || $game_slug === '' ) {
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
	 * Builds the composite key used to index and look up an ST's chosen
	 * resolution for one character's trait within one block, joining the
	 * three parts with a null byte to avoid collisions with real data.
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
	 * Indexes the traits half of a commit's resolutions request body by
	 * resolution_key() so the classification and import loops can look
	 * up an ST's chosen resolution in constant time. Skips any entry
	 * missing its character, block, or raw key.
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
	 * Returns a parsed character's name, or the literal string
	 * "(unnamed)" when the record has no name. Used consistently across
	 * preview and commit so a resolution key or duplicate lookup for a
	 * nameless record stays identical between the two.
	 *
	 * @param array<string,mixed> $character
	 * @return string
	 */
	private static function character_display_name( array $character ): string {
		return ( $character['name'] ?? '' ) !== '' ? $character['name'] : '(unnamed)';
	}

	/**
	 * Confirms a trait's resolution outcome is clean (exact, normalized,
	 * or custom) before it is written to a character's sheet_data,
	 * throwing when it is not. A non-clean outcome here means the
	 * underlying schema block changed between preview and commit.
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
	 * Loads and caches the identity/resource field mapping table from
	 * gex-identity-map.php, which maps each creature stack's raw
	 * Grapevine scalar fields onto its identity_field and resource_pool
	 * schema blocks.
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
	 * Populates a stack's identity_field and resource_pool blocks
	 * directly from the character's raw parsed scalars via the identity
	 * map. Unlike trait_list values, these are carried straight across
	 * from the raw record without catalog resolution.
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

		$map = self::identity_map()[ $stack_slug ] ?? null;
		if ( $map === null ) {
			return;
		}

		if ( isset( $map['identity'] ) && Schema_Block::find_by_slug( $map['identity']['block'] ) ) {
			$fields = [];
			foreach ( $map['identity']['fields'] as $be_field => $raw_key ) {
				$fields[ $be_field ] = $character[ $raw_key ] ?? null;
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
	 * Returns a previously parsed import job's stored preview by job id.
	 * Confirms the game exists and the job belongs to it, returning a
	 * 404 error when the job cannot be found or has expired.
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
	 * Builds the transient key used to store and retrieve one import
	 * job's parsed data and preview, namespaced by the job's id so
	 * concurrent imports never collide.
	 *
	 * @param string $job_id
	 * @return string
	 */
	private static function job_transient_key( string $job_id ): string {
		return 'be_import_job_' . $job_id;
	}

	/**
	 * Determines a file's format from its header: the literal string
	 * <?xml for an XML export, or one of the GVBE/GVBM/GVBG binary magic
	 * strings found at byte offset 2 (after their 2-byte length prefix).
	 * Returns 'unknown' when neither pattern matches.
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
	 * Builds the review preview structure from a parsed GEX structure:
	 * counts per section, every trait_list classified, and for
	 * sheet_block lists, every individual trait resolved into flagged/
	 * unresolved buckets, plus every character and world object that
	 * already exists in this chronicle by exact name, and any ST-chosen
	 * resolutions already applied. Public so it can be reused unchanged
	 * for a "create a new chronicle" preview, where the caller passes a
	 * slug/id matching no real game so nothing can collide.
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
				if ( $existing ) {
					$world_object_duplicates[] = [
						'type'        => $object_type,
						'name'        => $name,
						'existing_id' => (int) $existing->id,
					];
				}
			}
		}

		foreach ( $parsed['characters'] as $character ) {
			$stack_slug = $character['race'];
			$char_name  = self::character_display_name( $character );

			$existing = ( $character['name'] ?? '' ) !== '' ? Character::find_by_name_in_game( $character['name'], $game_slug ) : null;
			if ( $existing ) {
				$duplicates[] = [
					'character'     => $char_name,
					'existing_id'   => (int) $existing->id,
					'existing_uuid' => $existing->uuid,
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
					// Uses this chronicle's own fork, if any, so preview classification matches commit exactly.
					$block_cache[ $block_slug ] = Schema_Block::find_for_game( $block_slug, $game_slug );
				}
				$block = $block_cache[ $block_slug ];
				if ( ! $block ) {
					continue; // Declared mapping points at a block that doesn't exist in this install - nothing to resolve against.
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
		if ( ! empty( $parsed['characters'] ) ) {
			// Whether ST filtering was on at export time cannot be detected from the file itself.
			$warnings[] = 'If this file was exported with ST filtering on, hidden text has already been removed and cannot be recovered.';
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
	 * Suggests WP user matches for imported players not already matched
	 * by email, searching by display name instead. Only surfaces
	 * candidate matches; assigning a player to a WP user (or leaving
	 * them unassigned) is a decision made at commit time, not here.
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

			// Searches display_name, since a GV player name is not a WP username.
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
	 * Looks up a game by its slug and returns the game object, or a WP_Error
	 * with a 404 status when no game matches. Used by route callbacks to
	 * resolve the game_slug URL parameter before performing further work.
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
