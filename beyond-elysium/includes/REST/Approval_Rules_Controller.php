<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's approval rules.
 */
class Approval_Rules_Controller extends Base_Controller {

	protected $rest_base = 'approval-rules';

	/**
	 * Section types that can carry an approval rule at all.
	 */
	const APPLICABLE_SECTION_TYPES = [ 'trait_list', 'tiered_power', 'resource_pool', 'identity_field' ];

	/**
	 * Registers the approval rules routes: the game-scoped collection (list, create), a single rule by its opaque id
	 * (update, delete), and a small metadata route exposing the approval-level and reason-preset vocabulary the
	 * create/edit form offers.
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

		// Registered before the rule-id route.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/default', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_default_policy' ],
				'permission_callback' => $this->permission( 'be_manage_approval_rules' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_default_policy' ],
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
	 * Lists every approval rule currently set across this chronicle's trait_list and tiered_power blocks.
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

		// Sorted here, in PHP, over the small flattened list.
		usort( $rules, fn( $a, $b ) => [ $a['block_name'], $a['target_name'] ] <=> [ $b['block_name'], $b['target_name'] ] );

		return $this->success( $rules );
	}

	/**
	 * How many rules this chronicle has set itself: the rules its own forks carry.
	 *
	 * @param string $game_slug
	 * @return int
	 */
	public static function own_rule_count( string $game_slug ): int {
		$count = 0;
		foreach ( Schema_Block::forks_for_game_by_types( self::APPLICABLE_SECTION_TYPES, $game_slug ) as $block ) {
			$count += count( self::extract_rules( $block ) );
		}
		return $count;
	}

	/**
	 * Returns the chronicle's default approval policy.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_default_policy( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		return $this->success( [ 'auto_approve' => ( $game->settings->auto_approve ?? false ) === true ] );
	}

	/**
	 * Sets the chronicle's default approval policy.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_default_policy( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$auto = $request->get_param( 'auto_approve' );
		if ( ! is_bool( $auto ) ) {
			return $this->error( 'invalid_param', __( 'auto_approve must be true or false.', 'beyond-elysium' ), 400 );
		}

		$settings                 = $game->settings ? (array) $game->settings : [];
		$settings['auto_approve'] = $auto;
		if ( ! Game::update( $request['game_slug'], [ 'settings' => $settings ] ) ) {
			return $this->error( 'update_failed', __( 'Failed to save the default approval policy.', 'beyond-elysium' ), 500 );
		}

		return $this->success( [ 'auto_approve' => $auto ] );
	}

	/**
	 * Returns the fixed vocabulary the create/edit form offers: the approval levels Change_Engine enforces and the
	 * reason-tier presets.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_options() {
		return $this->success( [
			'approval_levels' => [ 'auto', 'st' ],
			'reason_presets'  => require BE_PLUGIN_DIR . 'includes/Database/approval-reason-presets.php',
		] );
	}

	/**
	 * Creates (sets) an approval rule on an existing catalog item, power, or power level.
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

		$saved = $this->save_rule( $target, $request );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return $this->success( self::find_rule( $saved, $target ), 201 );
	}

	/**
	 * Updates an existing approval rule, identified by the opaque id get_items() returned for it.
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

		$saved = $this->save_rule( $target, $request );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return $this->success( self::find_rule( $saved, $target ) );
	}

	/**
	 * Clears an approval rule back to unset: removes the item's approval and reason, the power's approval_override, or
	 * the level's reason, depending on the rule's target type.
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

		$current = Schema_Block::find_for_game( (string) $target['block_slug'], $request['game_slug'] );
		if ( ! $current ) {
			return $this->error( 'block_not_found', __( 'Schema block not found.', 'beyond-elysium' ), 404 );
		}
		if ( ! self::clear_target( self::copy_block( $current ), $target ) ) {
			return $this->error( 'target_not_found', __( 'That approval rule target no longer exists on this block.', 'beyond-elysium' ), 404 );
		}

		$unit  = Transaction::begin( 'be_approval_rule_clear' );
		$block = Schema_Block::find_or_create_fork_for_game( $target['block_slug'], $request['game_slug'] );
		if ( $block ) {
			self::clear_target( $block, $target );
		}
		if ( ! $block || ! Schema_Block::update( $target['block_slug'], [ 'definition' => $block->definition ], $request['game_slug'] ) ) {
			Transaction::rollback( $unit );
			return self::save_failed();
		}
		Transaction::commit( $unit );
		return $this->success( null, 204 );
	}

	// --- internals ---

	/**
	 * Writes a rule onto the chronicle's copy of its block, making the copy when the rule is valid, and returns the block
	 * as saved.
	 *
	 * @param array            $target
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function save_rule( array $target, \WP_REST_Request $request ) {
		$unit  = Transaction::begin( 'be_approval_rule_save' );
		$block = self::fork_if_valid( $target, $request );
		if ( ! is_wp_error( $block ) && ! Schema_Block::update( $target['block_slug'], [ 'definition' => $block->definition ], $request['game_slug'] ) ) {
			$block = self::save_failed();
		}
		if ( is_wp_error( $block ) ) {
			Transaction::rollback( $unit );
			return $block;
		}
		Transaction::commit( $unit );

		return Schema_Block::find_for_game( $target['block_slug'], $request['game_slug'] )
			?? $this->error( 'block_not_found', __( 'Schema block not found.', 'beyond-elysium' ), 404 );
	}

	/**
	 * @return \WP_Error
	 */
	private static function save_failed(): \WP_Error {
		return new \WP_Error( 'save_failed', __( 'The approval rule could not be saved. Nothing was changed.', 'beyond-elysium' ), [ 'status' => 500 ] );
	}

	/**
	 * Applies a rule to a copy of the chronicle's current block.
	 *
	 * @param array            $target
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error The fork with the rule applied, ready to save.
	 */
	private static function fork_if_valid( array $target, \WP_REST_Request $request ) {
		$current = Schema_Block::find_for_game( (string) $target['block_slug'], $request['game_slug'] );
		if ( ! $current ) {
			return new \WP_Error( 'block_not_found', __( 'Schema block not found.', 'beyond-elysium' ), [ 'status' => 404 ] );
		}

		$probe = self::apply_target( self::copy_block( $current ), $target, $request );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}

		$block = Schema_Block::find_or_create_fork_for_game( $target['block_slug'], $request['game_slug'] );
		if ( ! $block ) {
			return self::save_failed();
		}
		$result = self::apply_target( $block, $target, $request );
		return is_wp_error( $result ) ? $result : $block;
	}

	/**
	 * A deep copy of a decoded block row.
	 *
	 * @param object $block
	 * @return object
	 */
	private static function copy_block( $block ) {
		return json_decode( (string) wp_json_encode( $block ) );
	}

	/**
	 * Walks one block's definition and returns every item, power, or power level that currently carries an approval
	 * override or a reason, each shaped as a rule the client can display and address by its id.
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
				foreach ( $item->approval_by_value ?? [] as $range ) {
					$rules[] = self::rule_shape(
						$block,
						[ 'target_type' => 'item_range', 'target_name' => $item->name ?? '', 'extra' => [ $range->from ?? null, $range->to ?? null ] ],
						$range->approval ?? null,
						$range->reason ?? null
					);
				}
			}
		}

		if ( $block->section_type === 'tiered_power' ) {
			foreach ( $definition->powers ?? [] as $power ) {
				if ( ! empty( $power->approval_override ) ) {
					$rules[] = self::rule_shape( $block, [ 'target_type' => 'power', 'target_name' => $power->name ?? '' ], $power->approval_override ?? null, null );
				}
				foreach ( $power->levels ?? [] as $rung ) {
					if ( ! empty( $rung->reason ) || ! empty( $rung->approval ) ) {
						$rules[] = self::rule_shape(
							$block,
							[ 'target_type' => 'level', 'target_name' => $power->name ?? '', 'level' => $rung->level ?? null ],
							$rung->approval ?? null,
							$rung->reason ?? null
						);
					}
				}
			}
		}

		if ( $block->section_type === 'resource_pool' ) {
			foreach ( $definition->pools ?? [] as $pool ) {
				foreach ( $pool->approval_by_value ?? [] as $range ) {
					$rules[] = self::rule_shape(
						$block,
						[ 'target_type' => 'pool_range', 'target_name' => $pool->name ?? '', 'extra' => [ $range->from ?? null, $range->to ?? null ] ],
						$range->approval ?? null,
						$range->reason ?? null
					);
				}
			}
		}

		if ( $block->section_type === 'identity_field' ) {
			foreach ( $definition->fields ?? [] as $field ) {
				foreach ( (array) ( $field->approval_by_option ?? [] ) as $option => $entry ) {
					$rules[] = self::rule_shape(
						$block,
						[ 'target_type' => 'field_option', 'target_name' => $field->name ?? '', 'extra' => $option ],
						$entry->approval ?? null,
						$entry->reason ?? null
					);
				}
			}
		}

		return $rules;
	}

	/**
	 * Builds one rule's client-facing shape: its addressable id plus enough context (block, target, current values) to
	 * display and edit it.
	 *
	 * @param object     $block
	 * @param array      $target {target_type, target_name, level?, extra?}
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
			// item_range/pool_range: [from, to]. field_option: the option string.
			'extra'       => $target['extra'] ?? null,
			'approval'    => $approval,
			'reason'      => $reason,
		];
	}

	/**
	 * Re-derives one rule's current shape from a freshly saved block, for the response of a create or update.
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
	 * Encodes a block slug and target into the opaque id used to address one rule in the update and delete routes.
	 *
	 * @param string $block_slug
	 * @param array  $target {target_type, target_name, level?, extra?}
	 * @return string
	 */
	private static function encode_id( string $block_slug, array $target ): string {
		return rtrim( strtr( base64_encode( (string) wp_json_encode( [
			$block_slug,
			$target['target_type'],
			$target['target_name'],
			$target['level'] ?? null,
			$target['extra'] ?? null,
		] ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes an opaque rule id back into its block slug and target.
	 *
	 * @param string $id
	 * @return array|\WP_Error {block_slug, target_type, target_name, level, extra}
	 */
	private static function decode_id( string $id ) {
		$padded  = strtr( $id, '-_', '+/' );
		$padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );
		$decoded = json_decode( (string) base64_decode( $padded, true ), true );

		if ( ! is_array( $decoded ) || ! in_array( count( $decoded ), [ 4, 5 ], true ) ) {
			return new \WP_Error( 'invalid_id', __( 'That approval rule id is not valid.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		[ $block_slug, $target_type, $target_name, $level ] = $decoded;
		return [
			'block_slug'  => $block_slug,
			'target_type' => $target_type,
			'target_name' => $target_name,
			'level'       => $level,
			'extra'       => $decoded[4] ?? null,
		];
	}

	/**
	 * Target types addressing a range (a [from, to] pair as their `extra`).
	 */
	const RANGE_TARGET_TYPES = [ 'item_range', 'pool_range' ];

	/**
	 * Reads and validates a create request's target fields: which block, and which item, power, power level, value range,
	 * or field option within it.
	 *
	 * @param \WP_REST_Request $request
	 * @return array|\WP_Error {block_slug, target_type, target_name, level, extra}
	 */
	private static function parse_target( \WP_REST_Request $request ) {
		$block_slug  = (string) $request->get_param( 'block_slug' );
		$target_type = (string) $request->get_param( 'target_type' );
		$target_name = (string) $request->get_param( 'target_name' );
		$level       = $request->get_param( 'level' );

		if ( $block_slug === '' || $target_name === '' ) {
			return new \WP_Error( 'invalid_param', __( 'block_slug and target_name are required.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}
		$valid_types = [ 'item', 'power', 'level', 'item_range', 'pool_range', 'field_option' ];
		if ( ! in_array( $target_type, $valid_types, true ) ) {
			return new \WP_Error( 'invalid_param', __( 'target_type must be item, power, level, item_range, pool_range, or field_option.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}
		if ( $target_type === 'level' && $level === null ) {
			return new \WP_Error( 'invalid_param', __( 'A level target requires a level number.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$extra = null;
		if ( in_array( $target_type, self::RANGE_TARGET_TYPES, true ) ) {
			$from = $request->get_param( 'from' );
			$to   = $request->get_param( 'to' );
			if ( $from === null || $to === null ) {
				return new \WP_Error( 'invalid_param', __( 'A value-range target requires from and to.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			$extra = [ (int) $from, (int) $to ];
		}
		if ( $target_type === 'field_option' ) {
			$option = (string) $request->get_param( 'option' );
			if ( $option === '' ) {
				return new \WP_Error( 'invalid_param', __( 'A field_option target requires an option.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			$extra = $option;
		}

		return [
			'block_slug'  => $block_slug,
			'target_type' => $target_type,
			'target_name' => $target_name,
			'level'       => $level !== null ? (int) $level : null,
			'extra'       => $extra,
		];
	}

	/**
	 * Writes a rule's approval and/or reason fields onto the matched item, power, power level, value range, or field
	 * option within a block's (already-decoded) definition, mutating it in place.
	 *
	 * @param object            $block  Decoded block; its definition is mutated in place.
	 * @param array             $target {block_slug, target_type, target_name, level, extra}
	 * @param \WP_REST_Request  $request
	 * @return true|\WP_Error
	 */
	private static function apply_target( $block, array $target, \WP_REST_Request $request ) {
		$approval = $request->get_param( 'approval' );
		if ( $approval !== null && $approval !== '' && ! in_array( $approval, [ 'auto', 'st' ], true ) ) {
			return new \WP_Error( 'invalid_param', __( 'approval must be auto or st.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$reason = $request->get_param( 'reason' );
		if ( is_string( $reason ) ) {
			$reason = sanitize_textarea_field( $reason );
		}

		if ( $target['target_type'] === 'item' || $target['target_type'] === 'item_range' ) {
			if ( $block->section_type !== 'trait_list' ) {
				return new \WP_Error( 'invalid_target', __( 'An item target requires a trait_list block.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			foreach ( $block->definition->items ?? [] as $item ) {
				if ( $item->name !== $target['target_name'] ) {
					continue;
				}
				if ( $target['target_type'] === 'item' ) {
					if ( $approval !== null ) {
						$item->approval = $approval ?: null;
					}
					if ( $reason !== null ) {
						$item->reason = $reason ?: null;
					}
					return true;
				}
				// target_type === 'item_range': a per-value schedule entry on this item.
				if ( ! isset( $item->approval_by_value ) ) {
					$item->approval_by_value = [];
				}
				return self::apply_range( $item->approval_by_value, $target['extra'], $approval, $reason );
			}
			return new \WP_Error( 'target_not_found', __( 'No item with that name on this block.', 'beyond-elysium' ), [ 'status' => 404 ] );
		}

		if ( $target['target_type'] === 'pool_range' ) {
			if ( $block->section_type !== 'resource_pool' ) {
				return new \WP_Error( 'invalid_target', __( 'A pool target requires a resource_pool block.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			foreach ( $block->definition->pools ?? [] as $pool ) {
				if ( $pool->name !== $target['target_name'] ) {
					continue;
				}
				if ( ! isset( $pool->approval_by_value ) ) {
					$pool->approval_by_value = [];
				}
				return self::apply_range( $pool->approval_by_value, $target['extra'], $approval, $reason );
			}
			return new \WP_Error( 'target_not_found', __( 'No pool with that name on this block.', 'beyond-elysium' ), [ 'status' => 404 ] );
		}

		if ( $target['target_type'] === 'field_option' ) {
			if ( $block->section_type !== 'identity_field' ) {
				return new \WP_Error( 'invalid_target', __( 'A field target requires an identity_field block.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			foreach ( $block->definition->fields ?? [] as $field ) {
				if ( $field->name !== $target['target_name'] ) {
					continue;
				}
				if ( ! in_array( $target['extra'], $field->options ?? [], true ) ) {
					return new \WP_Error( 'target_not_found', __( 'That option no longer exists on this field.', 'beyond-elysium' ), [ 'status' => 404 ] );
				}
				if ( ! isset( $field->approval_by_option ) ) {
					$field->approval_by_option = new \stdClass();
				}
				$schedule = (array) $field->approval_by_option;
				$entry    = $schedule[ $target['extra'] ] ?? new \stdClass();
				if ( $approval !== null ) {
					$entry->approval = $approval ?: null;
				}
				if ( $reason !== null ) {
					$entry->reason = $reason ?: null;
				}
				$schedule[ $target['extra'] ]   = $entry;
				$field->approval_by_option      = (object) $schedule;
				return true;
			}
			return new \WP_Error( 'target_not_found', __( 'No field with that name on this block.', 'beyond-elysium' ), [ 'status' => 404 ] );
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
					if ( $approval !== null ) {
						$rung->approval = $approval ?: null;
					}
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
	 * Writes (creating if absent) one [from, to] range entry's approval and reason within a trait_list item's or
	 * resource_pool pool's own approval_by_value array, mutating it in place.
	 *
	 * @param array        $ranges Array of {from, to, approval, reason?} objects, mutated in place.
	 * @param array        $bounds [from, to]
	 * @param string|null  $approval
	 * @param string|null  $reason
	 * @return true
	 */
	private static function apply_range( array &$ranges, array $bounds, ?string $approval, ?string $reason ) {
		[ $from, $to ] = $bounds;
		foreach ( $ranges as $range ) {
			if ( ( $range->from ?? null ) === $from && ( $range->to ?? null ) === $to ) {
				if ( $approval !== null ) {
					$range->approval = $approval ?: null;
				}
				if ( $reason !== null ) {
					$range->reason = $reason ?: null;
				}
				return true;
			}
		}
		// No existing entry for this exact range - create one.
		$new_range           = new \stdClass();
		$new_range->from     = $from;
		$new_range->to       = $to;
		// No level chosen means a Storyteller decides.
		$new_range->approval = $approval ?: 'st';
		if ( $reason ) {
			$new_range->reason = $reason;
		}
		$ranges[] = $new_range;
		return true;
	}

	/**
	 * Clears a rule's override fields back to unset, the inverse of apply_target().
	 *
	 * @param object $block  Decoded block; its definition is mutated in place.
	 * @param array  $target {block_slug, target_type, target_name, level, extra}
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

		if ( $target['target_type'] === 'item_range' && $block->section_type === 'trait_list' ) {
			foreach ( $block->definition->items ?? [] as $item ) {
				if ( $item->name !== $target['target_name'] || ! isset( $item->approval_by_value ) ) {
					continue;
				}
				return self::clear_range( $item->approval_by_value, $target['extra'] );
			}
			return false;
		}

		if ( $target['target_type'] === 'pool_range' && $block->section_type === 'resource_pool' ) {
			foreach ( $block->definition->pools ?? [] as $pool ) {
				if ( $pool->name !== $target['target_name'] || ! isset( $pool->approval_by_value ) ) {
					continue;
				}
				return self::clear_range( $pool->approval_by_value, $target['extra'] );
			}
			return false;
		}

		if ( $target['target_type'] === 'field_option' && $block->section_type === 'identity_field' ) {
			foreach ( $block->definition->fields ?? [] as $field ) {
				if ( $field->name !== $target['target_name'] ) {
					continue;
				}
				$schedule = (array) ( $field->approval_by_option ?? [] );
				if ( ! array_key_exists( $target['extra'], $schedule ) ) {
					return false;
				}
				unset( $schedule[ $target['extra'] ] );
				$field->approval_by_option = (object) $schedule;
				return true;
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
						unset( $rung->reason, $rung->approval );
						return true;
					}
				}
				return false;
			}
		}

		return false;
	}

	/**
	 * Removes one [from, to] range entry from a trait_list item's or resource_pool pool's approval_by_value array,
	 * matched by its exact bounds.
	 *
	 * @param array $ranges Mutated in place.
	 * @param array $bounds [from, to]
	 * @return bool
	 */
	private static function clear_range( array &$ranges, array $bounds ): bool {
		[ $from, $to ] = $bounds;
		foreach ( $ranges as $i => $range ) {
			if ( ( $range->from ?? null ) === $from && ( $range->to ?? null ) === $to ) {
				unset( $ranges[ $i ] );
				$ranges = array_values( $ranges );
				return true;
			}
		}
		return false;
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
