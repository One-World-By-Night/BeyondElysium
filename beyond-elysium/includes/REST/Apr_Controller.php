<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Backgrounds_Catalog;
use BeyondElysium\Services\Background_Ledger;
use BeyondElysium\Services\Rumor_Generator;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's Action & Rumor configuration and its
 * background-use ledger - one controller because they are one feature
 * (BE_PROCESS/background-ledger-apr-design.md): the ledger tracks what a
 * background use spends, the settings decide what a background grants to
 * spend in the first place.
 */
class Apr_Controller extends Base_Controller {

	protected $rest_base = 'apr-settings';

	/**
	 * Registers the settings routes (get/update the chronicle's thirteen APR
	 * knobs, and the fork-aware background-name picker) and the ledger
	 * routes (spendable backgrounds, recording/editing/clearing a use).
	 * Non-numeric ledger path segments are registered before the numeric
	 * `{id}` route so they are never shadowed by it, the same ordering
	 * `Plots_Controller` documents for `/my/plots`.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => $this->permission( 'be_manage_apr' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => $this->permission( 'be_manage_apr' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/backgrounds', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_background_options' ],
				'permission_callback' => $this->permission( 'be_manage_apr' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/background-uses/clear-date', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'clear_date' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/spendable', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_spendable' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/background-uses/clear', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'clear_for_character' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/background-uses', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_background_uses' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'record_background_use' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_characters' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/background-uses/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_background_use' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_characters', 'be_manage_plots' ] ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_background_use' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_characters' ] ),
			],
		] );
	}

	// --- Settings ---

	/**
	 * Returns the chronicle's full thirteen-knob Action & Rumor
	 * configuration - stored values merged with Beyond Elysium's own
	 * defaults for anything the chronicle has not configured yet. Never
	 * labeled "Grapevine default" (§1.8): the client shows that distinction
	 * only via get_background_options()/the Restore Grapevine defaults action.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_settings( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		return $this->success( array_merge(
			Action_Allocator::apr_config( $game ),
			Rumor_Generator::rumor_config( $game )
		) );
	}

	/**
	 * Updates any subset of the chronicle's thirteen APR knobs. Reads the
	 * game's current settings, replaces only the keys present in the
	 * request within the nested `apr` object, and writes the whole settings
	 * object back - never a from-scratch payload, so untouched keys (the
	 * other action knobs, the rumor toggles, extended_health) survive
	 * (§3.6/§5.6). Validates every field; an invalid value rejects the
	 * whole request rather than silently dropping it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$incoming = $request->get_param( 'apr' );
		if ( ! is_array( $incoming ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: apr.', 'beyond-elysium' ), 400 );
		}

		$validated = self::validate_apr_settings( $incoming, $request['game_slug'] );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Read-modify-write: the whole `settings` object is preserved, only its `apr`
		// key's touched sub-fields change. Game::update() itself replaces `settings`
		// wholesale and stays that way for every OTHER caller - the merge lives here.
		$settings         = $game->settings ? (array) $game->settings : [];
		$current_apr      = isset( $settings['apr'] ) ? (array) $settings['apr'] : [];
		$settings['apr']  = array_merge( $current_apr, $validated );

		$ok = Game::update( $request['game_slug'], [ 'settings' => $settings ] );
		if ( ! $ok ) {
			return $this->error( 'update_failed', __( 'Failed to update Action & Rumor settings.', 'beyond-elysium' ), 500 );
		}

		$updated = Game::find_by_slug( $request['game_slug'] );
		return $this->success( array_merge(
			Action_Allocator::apr_config( $updated ),
			Rumor_Generator::rumor_config( $updated )
		) );
	}

	/**
	 * Returns the fork-aware union of every background/influence name across
	 * this chronicle's creature stacks, for the background_actions picker
	 * and for validating a save against real catalog names rather than
	 * Grapevine's own unvalidated free-text InputBox (§1.10/§5.6).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_background_options( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		return $this->success( Backgrounds_Catalog::union_names( $request['game_slug'] ) );
	}

	// --- Ledger ---

	/**
	 * Returns the backgrounds a character currently holds, each annotated
	 * with its live budget when the character's most recent allocation
	 * granted it one. Ownership-filtered exactly like a character's own
	 * data - the same allocation privacy rule as Plots_Controller (§3.4/§5.8).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_spendable( $request ) {
		$check = $this->resolve_character_for_read( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return $this->success( Background_Ledger::spendable_for( (int) $request['character_id'] ) );
	}

	/**
	 * Returns a character's recorded background uses for one game date.
	 * Ownership-filtered the same way get_spendable() is.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_background_uses( $request ) {
		$check = $this->resolve_character_for_read( $request );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$game_date = (string) $request->get_param( 'game_date' );
		if ( $game_date === '' ) {
			return $this->error( 'invalid_param', __( 'game_date is required.', 'beyond-elysium' ), 400 );
		}

		return $this->success( Background_Ledger::for_character_date( (int) $request['character_id'], $game_date ) );
	}

	/**
	 * Records one background use. A caller without be_manage_characters may
	 * only record against their own character.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function record_background_use( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['character_id'] );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		if ( ! current_user_can( 'be_manage_characters' ) ) {
			if ( ! Authorization::check( 'be_submit_actions' ) || (int) $character->wp_user_id !== get_current_user_id() ) {
				return $this->error( 'ownership_denied', __( 'You may only record a use for your own character.', 'beyond-elysium' ), 403 );
			}
		}

		$game_date = (string) $request->get_param( 'game_date' );
		if ( $game_date === '' ) {
			return $this->error( 'invalid_param', __( 'game_date is required.', 'beyond-elysium' ), 400 );
		}

		$result = Background_Ledger::record( (int) $character->id, $game_date, [
			'name' => $request->get_param( 'name' ),
			'cost' => $request->get_param( 'cost' ),
			'text' => $request->get_param( 'text' ),
		] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success( $result, 201 );
	}

	/**
	 * Edits a ledger entry's text, result, or cost. Setting `result` is ST
	 * adjudication and requires be_manage_plots; setting `text`/`cost` is
	 * available to be_manage_characters, or to the owning player via
	 * be_submit_actions while no result has been recorded yet - the same
	 * "not yet locked" shape Entries_Controller applies to a plain action
	 * entry once an ST has responded (§5.7).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_background_use( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$entry = $this->find_ledger_entry( (int) $request['id'] );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		$wants_result = $request->get_param( 'result' ) !== null;
		$fields       = [];

		if ( $wants_result ) {
			if ( ! current_user_can( 'be_manage_plots' ) ) {
				return $this->error( 'forbidden', __( 'Only a Storyteller may record the result of a background use.', 'beyond-elysium' ), 403 );
			}
			$fields['result'] = $request->get_param( 'result' );
		}

		if ( $request->get_param( 'text' ) !== null || $request->get_param( 'cost' ) !== null ) {
			if ( ! current_user_can( 'be_manage_characters' ) ) {
				$owns_it = Authorization::check( 'be_submit_actions' ) && $this->owns_ledger_entry( $entry );
				if ( ! $owns_it || ( $entry['result'] ?? '' ) !== '' ) {
					return $this->error( 'ownership_denied', __( 'You may only edit your own not-yet-adjudicated background use.', 'beyond-elysium' ), 403 );
				}
			}
			if ( $request->get_param( 'text' ) !== null ) {
				$fields['text'] = $request->get_param( 'text' );
			}
			if ( $request->get_param( 'cost' ) !== null ) {
				$fields['cost'] = $request->get_param( 'cost' );
			}
		}

		if ( empty( $fields ) ) {
			return $this->error( 'invalid_param', __( 'Nothing to update.', 'beyond-elysium' ), 400 );
		}

		if ( ! Background_Ledger::update_entry( (int) $request['id'], $fields ) ) {
			return $this->error( 'update_failed', __( 'Failed to update this background use.', 'beyond-elysium' ), 500 );
		}

		return $this->success( array_merge( $entry, $fields ) );
	}

	/**
	 * Deletes one ledger entry - Grapevine's "Clear this use". Available to
	 * be_manage_characters, or to the owning player via be_submit_actions
	 * while no result has been recorded yet.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_background_use( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$entry = $this->find_ledger_entry( (int) $request['id'] );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		if ( ! current_user_can( 'be_manage_characters' ) ) {
			$owns_it = Authorization::check( 'be_submit_actions' ) && $this->owns_ledger_entry( $entry );
			if ( ! $owns_it || ( $entry['result'] ?? '' ) !== '' ) {
				return $this->error( 'ownership_denied', __( 'You may only clear your own not-yet-adjudicated background use.', 'beyond-elysium' ), 403 );
			}
		}

		Background_Ledger::clear_entry( (int) $request['id'] );
		return $this->success( null, 204 );
	}

	/**
	 * Clears every ledger entry for one character, optionally bounded to a
	 * date range - honest about its scope in both directions, unlike
	 * Grapevine's own version of this operation (§1.4).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function clear_for_character( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['character_id'] );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$count = Background_Ledger::clear_for_character(
			(int) $character->id,
			$request->get_param( 'from' ) ?: null,
			$request->get_param( 'to' ) ?: null
		);
		return $this->success( [ 'cleared' => $count ] );
	}

	/**
	 * Clears every ledger entry for one game date across the whole
	 * chronicle - Grapevine's "Clear all for this Date".
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function clear_date( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$game_date = (string) $request->get_param( 'game_date' );
		if ( $game_date === '' ) {
			return $this->error( 'invalid_param', __( 'game_date is required.', 'beyond-elysium' ), 400 );
		}

		$count = Background_Ledger::clear_for_date( (int) $game->id, $game_date );
		return $this->success( [ 'cleared' => $count ] );
	}

	// --- internals ---

	/**
	 * Resolves the game and character named in a request, and checks the
	 * ownership rule a read-only ledger route needs: a manager sees any
	 * character, anyone else only their own
	 * (BE_PROCESS/background-ledger-apr-design.md §3.4/§5.8).
	 *
	 * @param \WP_REST_Request $request
	 * @return true|\WP_Error
	 */
	private function resolve_character_for_read( \WP_REST_Request $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['character_id'] );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		if ( ! current_user_can( 'be_manage_characters' ) && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You may only view your own character\'s background uses.', 'beyond-elysium' ), 403 );
		}

		return true;
	}

	/**
	 * Looks up a ledger entry by its plot-entry id, returning a 404 when it
	 * does not exist or is not a ledger-managed entry.
	 *
	 * @param int $entry_id
	 * @return array|\WP_Error
	 */
	private function find_ledger_entry( int $entry_id ) {
		$row = Plot_Entry::find( $entry_id );
		if ( $row ) {
			foreach ( Background_Ledger::entries_for_plot( (int) $row->plot_id ) as $entry ) {
				if ( (int) $entry['id'] === $entry_id ) {
					return $entry;
				}
			}
		}
		return $this->error( 'not_found', __( 'Background use not found.', 'beyond-elysium' ), 404 );
	}

	/**
	 * Reports whether the current user owns the character a ledger entry
	 * belongs to, for the "is this my own not-yet-adjudicated entry" checks
	 * update_background_use() and delete_background_use() both apply.
	 *
	 * @param array $entry
	 * @return bool
	 */
	private function owns_ledger_entry( array $entry ): bool {
		$character = Character::find( (int) ( $entry['character_id'] ?? 0 ) );
		return $character && (int) $character->wp_user_id === get_current_user_id();
	}

	/**
	 * Validates an incoming (possibly partial) `apr` settings payload
	 * against §5.6's rules. Returns the validated, normalized subset on
	 * success - only the keys actually present in the request, so a
	 * partial PUT never re-writes an untouched key back to a default.
	 *
	 * @param array  $incoming
	 * @param string $game_slug
	 * @return array|\WP_Error
	 */
	private static function validate_apr_settings( array $incoming, string $game_slug ) {
		$validated = [];

		if ( array_key_exists( 'personal_actions', $incoming ) ) {
			$value = (int) $incoming['personal_actions'];
			if ( $value < 0 || $value > 100 ) {
				return new \WP_Error( 'invalid_param', __( 'personal_actions must be between 0 and 100.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			$validated['personal_actions'] = $value;
		}

		foreach ( [
			'carry_unused', 'add_common',
			'public_rumors', 'personal_rumors', 'race_rumors', 'group_rumors',
			'subgroup_rumors', 'influence_rumors', 'previous_rumors', 'copy_previous',
		] as $bool_field ) {
			if ( array_key_exists( $bool_field, $incoming ) ) {
				$validated[ $bool_field ] = (bool) $incoming[ $bool_field ];
			}
		}

		if ( array_key_exists( 'background_actions', $incoming ) ) {
			if ( ! is_array( $incoming['background_actions'] ) ) {
				return new \WP_Error( 'invalid_param', __( 'background_actions must be a list of names.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			$known = array_column( Backgrounds_Catalog::union_names( $game_slug ), null, 'name' );
			$names = [];
			foreach ( $incoming['background_actions'] as $name ) {
				$name = (string) $name;
				if ( ! isset( $known[ $name ] ) ) {
					return new \WP_Error(
						'invalid_param',
						sprintf(
							/* translators: %s: the unrecognized background name. */
							__( '"%s" is not a background or influence name in this chronicle\'s catalog.', 'beyond-elysium' ),
							$name
						),
						[ 'status' => 400 ]
					);
				}
				$names[] = $name;
			}
			$validated['background_actions'] = $names;
		}

		if ( array_key_exists( 'actions_per_level', $incoming ) ) {
			if ( ! is_array( $incoming['actions_per_level'] ) ) {
				return new \WP_Error( 'invalid_param', __( 'actions_per_level must be an object.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			$levels = [];
			foreach ( $incoming['actions_per_level'] as $level => $value ) {
				$level_int = (int) $level;
				if ( (string) $level_int !== (string) $level || $level_int < 1 || $level_int > 20 ) {
					return new \WP_Error( 'invalid_param', __( 'actions_per_level keys must be levels 1 through 20.', 'beyond-elysium' ), [ 'status' => 400 ] );
				}
				$value_int = (int) $value;
				if ( $value_int < 0 || $value_int > 999 ) {
					return new \WP_Error( 'invalid_param', __( 'actions_per_level values must be between 0 and 999.', 'beyond-elysium' ), [ 'status' => 400 ] );
				}
				$levels[ (string) $level_int ] = $value_int;
			}
			$validated['actions_per_level'] = $levels;
		}

		return $validated;
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a
	 * WP_Error with a 404 status when no game matches.
	 *
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function resolve_game( string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
