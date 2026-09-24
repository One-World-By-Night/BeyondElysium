<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Maintenance;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Secret_Reveal;
use BeyondElysium\Services\Release_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's release batches.
 */
class Release_Batches_Controller extends Base_Controller {

	protected $rest_base = 'release-batches';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/release-batches', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/release-batches/release-now', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'release_now_single' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/release-batches/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/release-batches/(?P<id>\d+)/items', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_batch_items' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/release-batches/(?P<id>\d+)/items/(?P<type>[a-z]+)/(?P<item_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/release-batches/(?P<id>\d+)/release-now', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'release_now' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );
	}

	/**
	 * Lists a chronicle's release batches, newest created first, optionally narrowed to one status.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$status = $request->get_param( 'status' ) ?: null;
		if ( $status !== null && ! in_array( $status, Release_Batch::STATUSES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'status must be one of: %s.', 'beyond-elysium' ), implode( ', ', Release_Batch::STATUSES ) ), 400 );
		}

		$batches = array_map( [ $this, 'prepare_batch' ], Release_Batch::for_game( (int) $game->id, $status ) );
		return $this->success( $batches );
	}

	/**
	 * Creates a release batch: scheduled when release_at is given, draft.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$name = $request->get_param( 'name' );
		if ( empty( $name ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}

		$id = Release_Batch::create( [
			'game_id'    => (int) $game->id,
			'name'       => sanitize_text_field( $name ),
			'release_at' => $request->get_param( 'release_at' ) ?: null,
			'created_by' => get_current_user_id(),
		] );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create this release batch.', 'beyond-elysium' ), 500 );
		}

		$batch = Release_Batch::find( (int) $id );
		if ( ! $batch ) {
			throw new \RuntimeException( 'Release_Batches_Controller::create_item() failed to read back its own insert.' );
		}
		self::sync_single_event( $batch );
		return $this->success( $this->prepare_batch( $batch ), 201 );
	}

	/**
	 * Updates a draft or scheduled batch's name, release_at, and/or status.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$batch = $this->resolve_batch( $request );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		if ( $batch->status === 'released' ) {
			return $this->error( 'batch_released', __( 'A released batch is final and can no longer be changed.', 'beyond-elysium' ), 409 );
		}

		$data = [];
		if ( $request->get_param( 'name' ) !== null ) {
			$data['name'] = sanitize_text_field( $request->get_param( 'name' ) );
		}
		if ( $request->has_param( 'release_at' ) ) {
			$data['release_at'] = $request->get_param( 'release_at' ) ?: null;
		}
		if ( $request->get_param( 'status' ) !== null ) {
			$status = $request->get_param( 'status' );
			if ( ! in_array( $status, [ 'draft', 'scheduled' ], true ) ) {
				return $this->error( 'invalid_param', __( 'status must be draft or scheduled - releasing a batch is a separate action.', 'beyond-elysium' ), 400 );
			}
			$data['status'] = $status;
		}

		$new_status     = $data['status'] ?? $batch->status;
		$new_release_at = array_key_exists( 'release_at', $data ) ? $data['release_at'] : $batch->release_at;
		if ( $new_status === 'scheduled' && empty( $new_release_at ) ) {
			return $this->error( 'invalid_param', __( 'A scheduled batch needs a release_at.', 'beyond-elysium' ), 400 );
		}
		if ( $new_status === 'draft' && $batch->status === 'scheduled' && Release_Batch::is_out_row( $batch, current_time( 'mysql' ) ) ) {
			return $this->error( 'already_out', __( 'This batch is already out and can no longer be unscheduled.', 'beyond-elysium' ), 409 );
		}

		if ( ! Release_Batch::update( (int) $batch->id, $data ) && ! empty( $data ) ) {
			return $this->error( 'update_failed', __( 'Failed to update this release batch.', 'beyond-elysium' ), 500 );
		}

		$updated = Release_Batch::find( (int) $batch->id );
		if ( ! $updated ) {
			return $this->error( 'not_found', __( 'Release batch not found in this game.', 'beyond-elysium' ), 404 );
		}
		self::sync_single_event( $updated );
		return $this->success( $this->prepare_batch( $updated ) );
	}

	/**
	 * Deletes a draft or scheduled batch, returning its items to draft.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$batch = $this->resolve_batch( $request );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		if ( $batch->status === 'released' ) {
			return $this->error( 'batch_released', __( 'A released batch is final and cannot be deleted.', 'beyond-elysium' ), 409 );
		}

		Release_Batch::delete( (int) $batch->id );
		wp_clear_scheduled_hook( Maintenance::RELEASE_SINGLE_HOOK, [ (int) $batch->id ] );
		return $this->success( null, 204 );
	}

	/**
	 * Lists a batch's held items: rumors (held plots), downtime answers (held entries, with their parent plot's title),
	 * and reveals (held secret_reveals, with the secret's own title and the character's name).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_batch_items( $request ) {
		$batch = $this->resolve_batch( $request );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		$rumors = array_map( static fn( $plot ) => [
			'type'  => 'plot',
			'id'    => (int) $plot->id,
			'title' => $plot->title,
		], Plot::for_release_batch( (int) $batch->id ) );

		$entries = array_map( static function ( $entry ) {
			$plot = Plot::find( (int) $entry->plot_id );
			return [
				'type'       => 'entry',
				'id'         => (int) $entry->id,
				'plot_id'    => (int) $entry->plot_id,
				'plot_title' => $plot ? $plot->title : '',
				'content'    => wp_trim_words( wp_strip_all_tags( (string) $entry->content ), 20 ),
			];
		}, Plot_Entry::for_release_batch( (int) $batch->id ) );

		$reveals = array_map( static function ( $reveal ) {
			$secret    = Secret::find( (int) $reveal->secret_id );
			$character = Character::find( (int) $reveal->character_id );
			return [
				'type'           => 'reveal',
				'id'             => (int) $reveal->id,
				'secret_id'      => (int) $reveal->secret_id,
				'secret_title'   => $secret ? $secret->title : '',
				'character_id'   => (int) $reveal->character_id,
				'character_name' => $character ? $character->name : '',
			];
		}, Secret_Reveal::for_release_batch( (int) $batch->id ) );

		return $this->success( [
			'rumors'  => array_values( $rumors ),
			'entries' => array_values( $entries ),
			'reveals' => array_values( $reveals ),
		] );
	}

	/**
	 * Adds a plot or entry to a batch: sets held = 1 and this batch's id on it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_item( $request ) {
		$batch = $this->resolve_batch( $request );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		if ( $batch->status === 'released' ) {
			return $this->error( 'batch_released', __( 'A released batch is final; items can no longer be added to it.', 'beyond-elysium' ), 409 );
		}

		$type = (string) $request->get_param( 'type' );
		$id   = (int) $request->get_param( 'id' );

		if ( $type === 'plot' ) {
			$plot = Plot::find( $id );
			if ( ! $plot || (int) $plot->game_id !== (int) $batch->game_id ) {
				return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
			}
			Plot::update( $id, [ 'held' => true, 'release_batch_id' => (int) $batch->id ] );
		} elseif ( $type === 'entry' ) {
			$entry = Plot_Entry::find( $id );
			$plot  = $entry ? Plot::find( (int) $entry->plot_id ) : null;
			if ( ! $entry || ! $plot || (int) $plot->game_id !== (int) $batch->game_id ) {
				return $this->error( 'not_found', __( 'Entry not found in this game.', 'beyond-elysium' ), 404 );
			}
			Plot_Entry::update( $id, [ 'held' => true, 'release_batch_id' => (int) $batch->id ] );
		} elseif ( $type === 'reveal' ) {
			$reveal = Secret_Reveal::find( $id );
			$secret = $reveal ? Secret::find( (int) $reveal->secret_id ) : null;
			if ( ! $reveal || ! $secret || (int) $secret->game_id !== (int) $batch->game_id ) {
				return $this->error( 'not_found', __( 'Reveal not found in this game.', 'beyond-elysium' ), 404 );
			}
			Secret_Reveal::update( $id, [ 'held' => true, 'release_batch_id' => (int) $batch->id ] );
		} else {
			return $this->error( 'invalid_param', __( 'type must be one of: plot, entry, reveal.', 'beyond-elysium' ), 400 );
		}

		return $this->success( null, 204 );
	}

	/**
	 * Removes one item from a batch, returning it to draft (held stays 1, release_batch_id clears).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_item( $request ) {
		$batch = $this->resolve_batch( $request );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		$type = (string) $request['type'];
		$id   = (int) $request['item_id'];

		if ( $type === 'plot' ) {
			$plot = Plot::find( $id );
			if ( ! $plot || (int) ( $plot->release_batch_id ?? 0 ) !== (int) $batch->id ) {
				return $this->error( 'not_found', __( 'This plot is not in this release batch.', 'beyond-elysium' ), 404 );
			}
			Plot::update( $id, [ 'release_batch_id' => null ] );
		} elseif ( $type === 'entry' ) {
			$entry = Plot_Entry::find( $id );
			if ( ! $entry || (int) ( $entry->release_batch_id ?? 0 ) !== (int) $batch->id ) {
				return $this->error( 'not_found', __( 'This entry is not in this release batch.', 'beyond-elysium' ), 404 );
			}
			Plot_Entry::update( $id, [ 'release_batch_id' => null ] );
		} elseif ( $type === 'reveal' ) {
			$reveal = Secret_Reveal::find( $id );
			if ( ! $reveal || (int) ( $reveal->release_batch_id ?? 0 ) !== (int) $batch->id ) {
				return $this->error( 'not_found', __( 'This reveal is not in this release batch.', 'beyond-elysium' ), 404 );
			}
			Secret_Reveal::update( $id, [ 'release_batch_id' => null ] );
		} else {
			return $this->error( 'invalid_param', __( 'type must be one of: plot, entry, reveal.', 'beyond-elysium' ), 400 );
		}

		return $this->success( null, 204 );
	}

	/**
	 * Releases one existing batch immediately.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function release_now( $request ) {
		$batch = $this->resolve_batch( $request );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		if ( $batch->status === 'released' ) {
			return $this->error( 'batch_released', __( 'This batch has already been released.', 'beyond-elysium' ), 409 );
		}

		wp_clear_scheduled_hook( Maintenance::RELEASE_SINGLE_HOOK, [ (int) $batch->id ] );
		Release_Engine::release( (int) $batch->id );

		$released = Release_Batch::find( (int) $batch->id );
		if ( ! $released ) {
			throw new \RuntimeException( 'Release_Batches_Controller::release_now() failed to read back the batch it just released.' );
		}
		return $this->success( $this->prepare_batch( $released ) );
	}

	/**
	 * Creates a batch, fills it with the given items, and releases it, all in one request.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function release_now_single( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$items = (array) $request->get_param( 'items' );
		if ( empty( $items ) ) {
			return $this->error( 'invalid_param', __( 'items must name at least one plot or entry.', 'beyond-elysium' ), 400 );
		}

		$name = $request->get_param( 'name' ) ?: sprintf(
			/* translators: %s: the current site date and time */
			__( 'Release now - %s', 'beyond-elysium' ),
			current_time( 'mysql' )
		);

		$savepoint = Transaction::begin( 'be_release_now_single' );

		$batch_id = Release_Batch::create( [
			'game_id'    => (int) $game->id,
			'name'       => sanitize_text_field( $name ),
			'created_by' => get_current_user_id(),
		] );
		if ( ! $batch_id ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'create_failed', __( 'Failed to create this release batch.', 'beyond-elysium' ), 500 );
		}

		foreach ( $items as $item ) {
			$type = is_array( $item ) ? (string) ( $item['type'] ?? '' ) : '';
			$id   = is_array( $item ) ? (int) ( $item['id'] ?? 0 ) : 0;

			if ( $type === 'plot' ) {
				$plot = Plot::find( $id );
				if ( ! $plot || (int) $plot->game_id !== (int) $game->id ) {
					Transaction::rollback( $savepoint );
					return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
				}
				Plot::update( $id, [ 'held' => true, 'release_batch_id' => (int) $batch_id ] );
			} elseif ( $type === 'entry' ) {
				$entry = Plot_Entry::find( $id );
				$plot  = $entry ? Plot::find( (int) $entry->plot_id ) : null;
				if ( ! $entry || ! $plot || (int) $plot->game_id !== (int) $game->id ) {
					Transaction::rollback( $savepoint );
					return $this->error( 'not_found', __( 'Entry not found in this game.', 'beyond-elysium' ), 404 );
				}
				Plot_Entry::update( $id, [ 'held' => true, 'release_batch_id' => (int) $batch_id ] );
			} elseif ( $type === 'reveal' ) {
				$reveal = Secret_Reveal::find( $id );
				$secret = $reveal ? Secret::find( (int) $reveal->secret_id ) : null;
				if ( ! $reveal || ! $secret || (int) $secret->game_id !== (int) $game->id ) {
					Transaction::rollback( $savepoint );
					return $this->error( 'not_found', __( 'Reveal not found in this game.', 'beyond-elysium' ), 404 );
				}
				Secret_Reveal::update( $id, [ 'held' => true, 'release_batch_id' => (int) $batch_id ] );
			} else {
				Transaction::rollback( $savepoint );
				return $this->error( 'invalid_param', __( 'Each item needs type (plot, entry, or reveal) and id.', 'beyond-elysium' ), 400 );
			}
		}

		Transaction::commit( $savepoint );

		Release_Engine::release( (int) $batch_id );

		$released = Release_Batch::find( (int) $batch_id );
		if ( ! $released ) {
			throw new \RuntimeException( 'Release_Batches_Controller::release_now_single() failed to read back the batch it just released.' );
		}
		return $this->success( $this->prepare_batch( $released ) );
	}

	/**
	 * Adds rumor_count/entry_count to a batch for the list/detail response.
	 *
	 * @param object $batch
	 * @return object
	 */
	private function prepare_batch( $batch ) {
		$batch->rumor_count  = count( Plot::for_release_batch( (int) $batch->id ) );
		$batch->entry_count  = count( Plot_Entry::for_release_batch( (int) $batch->id ) );
		$batch->reveal_count = count( Secret_Reveal::for_release_batch( (int) $batch->id ) );
		return $batch;
	}

	/**
	 * (Re)schedules a batch's single release event to match its current status/release_at, clearing any older event
	 * first.
	 *
	 * @param object $batch
	 * @return void
	 */
	private static function sync_single_event( $batch ): void {
		$batch_id = property_exists( $batch, 'id' ) ? (int) $batch->id : 0;
		wp_clear_scheduled_hook( Maintenance::RELEASE_SINGLE_HOOK, [ $batch_id ] );
		if ( $batch->status !== 'scheduled' || empty( $batch->release_at ) ) {
			return;
		}

		$timestamp = strtotime( get_gmt_from_date( (string) $batch->release_at, 'Y-m-d H:i:s' ) . ' GMT' );
		if ( $timestamp ) {
			wp_schedule_single_event( $timestamp, Maintenance::RELEASE_SINGLE_HOOK, [ $batch_id ] );
		}
	}

	/**
	 * Resolves a batch named by the URL's {id}, confirming it belongs to the game named in the URL.
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_batch( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$batch = Release_Batch::find( (int) $request->get_url_params()['id'] );
		if ( ! $batch || (int) $batch->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Release batch not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $batch;
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error with a 404 status when no game matches.
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
