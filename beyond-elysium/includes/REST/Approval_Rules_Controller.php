<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's approval rules.
 *
 * An approval rule is not a row of its own: it is the existing approval/
 * reason fields already carried on a trait_list item or a tiered_power
 * power/level (see Change_Engine::resolve_approval_level(), which reads
 * exactly these fields at submission time). This controller is the
 * dedicated management surface for them - listing every rule currently set
 * across a chronicle's blocks in one place, and creating, editing, or
 * clearing one without hand-editing the owning block's full definition.
 *
 * Writing a rule forks the block for this chronicle on first edit, via the
 * same Schema_Block::find_or_create_fork_for_game() the Schema Blocks admin
 * page already uses - the global catalog is never mutated by this route.
 */
class Approval_Rules_Controller extends Base_Controller {

	protected $rest_base = 'approval-rules';

	/** Section types that can carry an approval rule at all. */
	const APPLICABLE_SECTION_TYPES = [ 'trait_list', 'tiered_power' ];

	/**
	 * Registers the approval rules routes: the game-scoped collection
	 * (list, create), a single rule by its opaque id (update, delete), and
	 * a small metadata route exposing the approval-level and reason-preset
	 * vocabulary the create/edit form offers.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_approval_rules' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_approval_rules' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/options', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_options' ],
				'permission_callback' => $this->permission( 'be_manage_approval_rules' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<id>[A-Za-z0-9_=-]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_approval_rules' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_approval_rules' ),
			],
		] );
	}

	/**
	 * Lists every approval rule currently set across this chronicle's
	 * trait_list and tiered_power blocks - this chronicle's own fork where
	 * one exists, the shared global block otherwise. Only entries that
	 * actually carry an approval override or a reason are returned; an
	 * ordinary catalog item with neither is not a "rule".
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$rules = [];
		foreach ( Schema_Block::all_for_game_by_types( self::APPLICABLE_SECTION_TYPES, $request['game_slug'] ) as $block ) {
			array_push( $rules, ...self::extract_rules( $block ) );
		}

		// Sorted here, in PHP, over the small flattened list - not at the database layer,
		// which is exactly what overflows the sort buffer against the raw block rows.
		usort( $rules, fn( $a, $b ) => [ $a['block_name'], $a['target_name'] ] <=> [ $b['block_name'], $b['target_name'] ] );

		return $this->success( $rules );
	}

	/**
	 * Returns the fixed vocabulary the create/edit form offers: the
	 * approval levels Change_Engine actually enforces, and the reason-tier
	 * presets defined in approval-reason-presets.php.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_options() {
		return $this->success( [
			'approval_levels' => [ 'auto', 'st', 'coordinator' ],
			'reason_presets'  => require BE_PLUGIN_DIR . 'includes/Database/approval-reason-presets.php',
		] );
	}

	/**
	 * Creates (sets) an approval rule on an existing catalog item, power,
	 * or power level. Forks the block for this chronicle if it has not
	 * already been forked, then writes the rule's fields onto the matched
	 * target and saves.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$target = self::parse_target( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$block = Schema_Block::find_or_create_fork_for_game( $target['block_slug'], $request['game_slug'] );
		if ( ! $block ) {
			return $this->error( 'block_not_found', __( 'Schema block not found.', 'beyond-elysium' ), 404 );
		}

		$result = self::apply_target( $block, $target, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Schema_Block::update( $target['block_slug'], [ 'definition' => $block->definition ], $request['game_slug'] );

		$saved = Schema_Block::find_for_game( $target['block_slug'], $request['game_slug'] );
		return $this->success( self::find_rule( $saved, $target ), 201 );
	}

	/**
	 * Updates an existing approval rule, identified by the opaque id
	 * get_items() returned for it. Re-decodes the id back into its block
	 * and target, forks the block for this chronicle if needed, and
	 * overwrites the rule's approval/reason fields.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$target = self::decode_id( $request['id'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$block = Schema_Block::find_or_create_fork_for_game( $target['block_slug'], $request['game_slug'] );
		if ( ! $block ) {
			return $this->error( 'block_not_found', __( 'Schema block not found.', 'beyond-elysium' ), 404 );
		}

		$result = self::apply_target( $block, $target, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Schema_Block::update( $target['block_slug'], [ 'definition' => $block->definition ], $request['game_slug'] );

		$saved = Schema_Block::find_for_game( $target['block_slug'], $request['game_slug'] );
		return $this->success( self::find_rule( $saved, $target ) );
	}

	/**
	 * Clears an approval rule back to unset: removes the item's approval
	 * and reason, the power's approval_override, or the level's reason,
	 * depending on the rule's target type. The catalog entry itself is
	 * never removed, only the override fields on it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$target = self::decode_id( $request['id'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$block = Schema_Block::find_or_create_fork_for_game( $target['block_slug'], $request['game_slug'] );
		if ( ! $block ) {
			return $this->error( 'block_not_found', __( 'Schema block not found.', 'beyond-elysium' ), 404 );
		}

		$cleared = self::clear_target( $block, $target );
		if ( ! $cleared ) {
			return $this->error( 'target_not_found', __( 'That approval rule target no longer exists on this block.', 'beyond-elysium' ), 404 );
		}

		Schema_Block::update( $target['block_slug'], [ 'definition' => $block->definition ], $request['game_slug'] );
		return $this->success( null, 204 );
	}

	// --- internals ---

	/**
	 * Walks one block's definition and returns every item, power, or power
	 * level that currently carries an approval override or a reason, each
	 * shaped as a rule the client can display and address by its id.
	 *
	 * @param object $block Decoded schema block row.
	 * @return array[]
	 */
	private static function extract_rules( $block ): array {
		$rules      = [];
		$definition = $block->definition;

		if ( $block->section_type === 'trait_list' ) {
			foreach ( $definition->items ?? [] as $item ) {
				if ( ! empty( $item->approval ) || ! empty( $item->reason ) ) {
					$rules[] = self::rule_shape( $block, [ 'target_type' => 'item', 'target_name' => $item->name ?? '' ], $item->approval ?? null, $item->reason ?? null );
				}
			}
		}

		if ( $block->section_type === 'tiered_power' ) {
			foreach ( $definition->powers ?? [] as $power ) {
				if ( ! empty( $power->approval_override ) ) {
					$rules[] = self::rule_shape( $block, [ 'target_type' => 'power', 'target_name' => $power->name ?? '' ], $power->approval_override ?? null, null );
				}
				foreach ( $power->levels ?? [] as $rung ) {
					if ( ! empty( $rung->reason ) ) {
						$rules[] = self::rule_shape(
							$block,
							[ 'target_type' => 'level', 'target_name' => $power->name ?? '', 'level' => $rung->level ?? null ],
							null,
							$rung->reason ?? null
						);
					}
				}
			}
		}

		return $rules;
	}

	/**
	 * Builds one rule's client-facing shape: its addressable id plus enough
	 * context (block, target, current values) to display and edit it.
	 *
	 * @param object     $block
	 * @param array      $target {target_type, target_name, level?}
	 * @param string|null $approval
	 * @param string|null $reason
	 * @return array
	 */
	private static function rule_shape( $block, array $target, ?string $approval, ?string $reason ): array {
		return [
			'id'          => self::encode_id( $block->slug, $target ),
			'block_slug'  => $block->slug,
			'block_name'  => $block->name,
			'target_type' => $target['target_type'],
			'target_name' => $target['target_name'],
			'level'       => $target['level'] ?? null,
			'approval'    => $approval,
			'reason'      => $reason,
		];
	}

	/**
	 * Re-derives one rule's current shape from a freshly saved block, for
	 * the response of a create or update - avoids trusting the request
	 * body back to the client as if it were confirmed stored state.
	 *
	 * @param object $block
	 * @param array  $target
	 * @return array|null
	 */
	private static function find_rule( $block, array $target ): ?array {
		foreach ( self::extract_rules( $block ) as $rule ) {
			if ( $rule['id'] === self::encode_id( $target['block_slug'], $target ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Encodes a block slug and target into the opaque id used to address
	 * one rule in the update/delete routes. Plain base64 of a small JSON
	 * array - not a security boundary, just a stable, collision-free key
	 * for a target that has no row id of its own.
	 *
	 * @param string $block_slug
	 * @param array  $target {target_type, target_name, level?}
	 * @return string
	 */
	private static function encode_id( string $block_slug, array $target ): string {
		return rtrim( strtr( base64_encode( wp_json_encode( [
			$block_slug,
			$target['target_type'],
			$target['target_name'],
			$target['level'] ?? null,
		] ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes an opaque rule id back into its block slug and target.
	 * Returns a 400 error for a malformed id rather than letting a decode
	 * failure surface as a confusing 404 further down.
	 *
	 * @param string $id
	 * @return array|\WP_Error {block_slug, target_type, target_name, level}
	 */
	private static function decode_id( string $id ) {
		$padded  = strtr( $id, '-_', '+/' );
		$padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );
		$decoded = json_decode( (string) base64_decode( $padded, true ), true );

		if ( ! is_array( $decoded ) || count( $decoded ) !== 4 ) {
			return new \WP_Error( 'invalid_id', __( 'That approval rule id is not valid.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		[ $block_slug, $target_type, $target_name, $level ] = $decoded;
		return [
			'block_slug'  => $block_slug,
			'target_type' => $target_type,
			'target_name' => $target_name,
			'level'       => $level,
		];
	}

	/**
	 * Reads and validates a create request's target fields: which block,
	 * and which item, power, or power level within it. Existence of the
	 * named target on the block is checked later, once the block (and its
	 * chronicle fork) has been resolved.
	 *
	 * @param \WP_REST_Request $request
	 * @return array|\WP_Error {block_slug, target_type, target_name, level}
	 */
	private static function parse_target( \WP_REST_Request $request ) {
		$block_slug  = (string) $request->get_param( 'block_slug' );
		$target_type = (string) $request->get_param( 'target_type' );
		$target_name = (string) $request->get_param( 'target_name' );
		$level       = $request->get_param( 'level' );

		if ( $block_slug === '' || $target_name === '' ) {
			return new \WP_Error( 'invalid_param', __( 'block_slug and target_name are required.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $target_type, [ 'item', 'power', 'level' ], true ) ) {
			return new \WP_Error( 'invalid_param', __( 'target_type must be item, power, or level.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}
		if ( $target_type === 'level' && $level === null ) {
			return new \WP_Error( 'invalid_param', __( 'A level target requires a level number.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		return [
			'block_slug'  => $block_slug,
			'target_type' => $target_type,
			'target_name' => $target_name,
			'level'       => $level !== null ? (int) $level : null,
		];
	}

	/**
	 * Writes a rule's approval and/or reason fields onto the matched item,
	 * power, or power level within a block's (already-decoded) definition,
	 * mutating it in place. Validates the target type against the block's
	 * actual section_type and that the named target really exists on it.
	 *
	 * @param object            $block  Decoded block; its definition is mutated in place.
	 * @param array             $target {block_slug, target_type, target_name, level}
	 * @param \WP_REST_Request  $request
	 * @return true|\WP_Error
	 */
	private static function apply_target( $block, array $target, \WP_REST_Request $request ) {
		$approval = $request->get_param( 'approval' );
		if ( $approval !== null && $approval !== '' && ! in_array( $approval, [ 'auto', 'st', 'coordinator' ], true ) ) {
			return new \WP_Error( 'invalid_param', __( 'approval must be auto, st, or coordinator.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$reason = $request->get_param( 'reason' );
		if ( is_string( $reason ) ) {
			$reason = sanitize_textarea_field( $reason );
		}

		if ( $target['target_type'] === 'item' ) {
			if ( $block->section_type !== 'trait_list' ) {
				return new \WP_Error( 'invalid_target', __( 'An item target requires a trait_list block.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			foreach ( $block->definition->items ?? [] as $item ) {
				if ( $item->name === $target['target_name'] ) {
					if ( $approval !== null ) {
						$item->approval = $approval ?: null;
					}
					if ( $reason !== null ) {
						$item->reason = $reason ?: null;
					}
					return true;
				}
			}
			return new \WP_Error( 'target_not_found', __( 'No item with that name on this block.', 'beyond-elysium' ), [ 'status' => 404 ] );
		}

		if ( $block->section_type !== 'tiered_power' ) {
			return new \WP_Error( 'invalid_target', __( 'A power or level target requires a tiered_power block.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		foreach ( $block->definition->powers ?? [] as $power ) {
			if ( $power->name !== $target['target_name'] ) {
				continue;
			}

			if ( $target['target_type'] === 'power' ) {
				if ( $approval !== null ) {
					$power->approval_override = $approval ?: null;
				}
				return true;
			}

			// target_type === 'level'.
			foreach ( $power->levels ?? [] as $rung ) {
				if ( ( $rung->level ?? null ) === $target['level'] ) {
					if ( $reason !== null ) {
						$rung->reason = $reason ?: null;
					}
					return true;
				}
			}
			return new \WP_Error( 'target_not_found', __( 'No level with that number on this power.', 'beyond-elysium' ), [ 'status' => 404 ] );
		}

		return new \WP_Error( 'target_not_found', __( 'No power with that name on this block.', 'beyond-elysium' ), [ 'status' => 404 ] );
	}

	/**
	 * Clears a rule's override fields back to unset, the inverse of
	 * apply_target(). Returns false when the target no longer exists on
	 * the block rather than throwing, since a delete against an
	 * already-gone target is not itself an error worth surfacing loudly.
	 *
	 * @param object $block  Decoded block; its definition is mutated in place.
	 * @param array  $target {block_slug, target_type, target_name, level}
	 * @return bool
	 */
	private static function clear_target( $block, array $target ): bool {
		if ( $target['target_type'] === 'item' && $block->section_type === 'trait_list' ) {
			foreach ( $block->definition->items ?? [] as $item ) {
				if ( $item->name === $target['target_name'] ) {
					unset( $item->approval, $item->reason );
					return true;
				}
			}
			return false;
		}

		if ( $block->section_type === 'tiered_power' ) {
			foreach ( $block->definition->powers ?? [] as $power ) {
				if ( $power->name !== $target['target_name'] ) {
					continue;
				}
				if ( $target['target_type'] === 'power' ) {
					unset( $power->approval_override );
					return true;
				}
				foreach ( $power->levels ?? [] as $rung ) {
					if ( ( $rung->level ?? null ) === $target['level'] ) {
						unset( $rung->reason );
						return true;
					}
				}
				return false;
			}
		}

		return false;
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
