<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a player's own held-entry order on a `player_order`-flagged trait_list or tiered_power block
 * (Blood Magic, Rituals).
 */
class Character_Order_Controller extends Base_Controller {

	protected $rest_base = 'order';

	/**
	 * A player may reorder their own character's flagged sections.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<id>\d+)/order/(?P<block_slug>[a-z0-9\-]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'save_order' ],
				'permission_callback' => $this->permission_any( [ 'be_manage_characters', 'be_edit_own_characters' ] ),
			],
		] );
	}

	/**
	 * Body: `{ order: number[], names: string[] }`.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_order( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character = Character::find( (int) $request['id'] );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		if ( ! $can_manage && (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'ownership_denied', __( 'You do not have permission to edit this character.', 'beyond-elysium' ), 403 );
		}

		$block_slug = (string) $request['block_slug'];
		$block      = Schema_Block::find_for_game( $block_slug, $request['game_slug'] );
		if ( ! $block || empty( $block->definition->player_order ) ) {
			return $this->error( 'not_player_order', __( 'This section does not support a custom order.', 'beyond-elysium' ), 400 );
		}

		$order = $request->get_param( 'order' );
		$names = $request->get_param( 'names' );
		if ( ! is_array( $order ) || ! is_array( $names ) || count( $order ) !== count( $names ) ) {
			return $this->error( 'invalid_order', __( 'order and names must be equal-length arrays.', 'beyond-elysium' ), 400 );
		}

		$savepoint = Transaction::begin( 'be_character_order' );
		Character::lock( (int) $character->id );
		$locked = Character::find( (int) $character->id );
		if ( ! $locked ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$sheet   = is_array( $locked->sheet_data ) ? $locked->sheet_data : [];
		$current = is_array( $sheet[ $block_slug ] ?? null ) ? $sheet[ $block_slug ] : [];

		if ( ! self::is_valid_reorder( $current, $order, $names ) ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'sheet_changed', __( 'This list changed since you loaded it - reload and try again.', 'beyond-elysium' ), 409 );
		}

		$sheet[ $block_slug ] = array_values( array_map( static fn( int $i ) => $current[ $i ], $order ) );

		if ( ! Character::update_sheet_data( (int) $character->id, $sheet ) ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'save_failed', __( 'Could not save the new order.', 'beyond-elysium' ), 500 );
		}

		Transaction::commit( $savepoint );

		return $this->success( [ 'order' => $sheet[ $block_slug ] ] );
	}

	/**
	 * `$order` must be a permutation of `$current`'s own indexes (same length, every index 0..n-1 present exactly once)
	 * and `$names[$k]` must equal the name actually held at `$current[$order[$k]]` for every position.
	 *
	 * @param array<int,array<string,mixed>> $current
	 * @param array<int,mixed>               $order
	 * @param array<int,mixed>               $names
	 */
	private static function is_valid_reorder( array $current, array $order, array $names ): bool {
		$count = count( $current );
		if ( count( $order ) !== $count ) {
			return false;
		}

		foreach ( $order as $index ) {
			if ( ! is_int( $index ) ) {
				return false;
			}
		}

		$sorted = $order;
		sort( $sorted );
		if ( $count > 0 && $sorted !== range( 0, $count - 1 ) ) {
			return false;
		}

		foreach ( $order as $k => $index ) {
			if ( ( $current[ $index ]['name'] ?? null ) !== ( $names[ $k ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/** @see Export_Controller::resolve_game() - duplicated per project convention. */
	protected function resolve_game( string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
