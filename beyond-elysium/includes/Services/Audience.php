<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Secret_Reveal;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a plot's or world object's "who can see this" is decided.
 */
class Audience {

	const EVERYONE     = 'everyone';
	const STORYTELLERS = 'storytellers';
	const RESTRICTED   = 'restricted';

	/**
	 * Every real audience value.
	 */
	const VALUES = [ self::EVERYONE, self::STORYTELLERS, self::RESTRICTED ];

	/**
	 * Entity types this class knows how to resolve connections for.
	 */
	const ENTITY_TYPES = [ 'plot', 'item', 'location', 'npc', 'secret', 'faction', 'position' ];

	/**
	 * Normalizes a stored or submitted audience value, falling back to `everyone` for anything unrecognized.
	 *
	 * @param mixed $value
	 */
	public static function normalize( $value ): string {
		return in_array( $value, self::VALUES, true ) ? $value : self::EVERYONE;
	}

	/**
	 * Whether `$wp_user_id` may see `$entity`, through any of their own characters.
	 *
	 * @param object $entity      A decoded plot or world-object row (`audience`,
	 *                             `audience_rules` as a real array or null).
	 * @param string $entity_type One of ENTITY_TYPES.
	 * @param int    $wp_user_id
	 * @param string $game_slug
	 * @param bool   $can_manage  Caller-resolved: `be_manage_plots` or `be_manage_world_objects`.
	 */
	public static function can_see( object $entity, string $entity_type, int $wp_user_id, string $game_slug, bool $can_manage ): bool {
		if ( $can_manage ) {
			return true;
		}

		$my_character_ids = self::character_ids_for_user( $wp_user_id, $game_slug );
		$out_batch_ids = ( $entity_type === 'plot' && property_exists( $entity, 'game_id' ) )
			? Release_Batch::out_ids( (int) $entity->game_id )
			: [];
		$no_rule_memo  = null;
		return self::visible_to( $entity, $entity_type, $game_slug, $my_character_ids, $no_rule_memo, $out_batch_ids );
	}

	/**
	 * Narrows a list of entities to the ones `$wp_user_id` may see.
	 *
	 * @param object[] $entities
	 * @param string   $entity_type One of ENTITY_TYPES.
	 * @param int      $wp_user_id
	 * @param string   $game_slug
	 * @param bool     $can_manage
	 * @return object[]
	 */
	public static function filter( array $entities, string $entity_type, int $wp_user_id, string $game_slug, bool $can_manage ): array {
		if ( $can_manage ) {
			return $entities;
		}

		$my_character_ids = self::character_ids_for_user( $wp_user_id, $game_slug );
		$rule_memo        = [];
		$first            = $entity_type === 'plot' && ! empty( $entities ) ? reset( $entities ) : null;
		$out_batch_ids    = ( $first !== null && property_exists( $first, 'game_id' ) )
			? Release_Batch::out_ids( (int) $first->game_id )
			: [];

		return array_values( array_filter(
			$entities,
			fn( $entity ) => self::visible_to( $entity, $entity_type, $game_slug, $my_character_ids, $rule_memo, $out_batch_ids )
		) );
	}

	/**
	 * `filter()` for a world-object list that mixes object types.
	 *
	 * @param object[] $objects    Decoded world_objects rows.
	 * @param int      $wp_user_id
	 * @param string   $game_slug
	 * @param bool     $can_manage
	 * @return object[]
	 */
	public static function filter_world_objects( array $objects, int $wp_user_id, string $game_slug, bool $can_manage ): array {
		if ( $can_manage ) {
			return $objects;
		}

		$visible_ids = [];
		foreach ( [ 'item', 'location' ] as $entity_type ) {
			$of_type = array_filter( $objects, static fn( $o ) => ( $o->object_type ?? '' ) === $entity_type );
			foreach ( self::filter( $of_type, $entity_type, $wp_user_id, $game_slug, false ) as $visible ) {
				$visible_ids[ (int) $visible->id ] = true;
			}
		}

		return array_values( array_filter( $objects, static function ( $o ) use ( $visible_ids ) {
			if ( ! in_array( $o->object_type ?? '', [ 'item', 'location' ], true ) ) {
				return true; // rote, boon - Audience has no opinion, so nothing here excludes it.
			}
			return isset( $visible_ids[ (int) $o->id ] );
		} ) );
	}

	/**
	 * The character IDs an entity's audience actually reaches, for every audience value.
	 *
	 * @param object $entity      A decoded plot or world-object row.
	 * @param string $entity_type One of ENTITY_TYPES.
	 * @param string $game_slug
	 * @return int[]
	 */
	public static function visible_character_ids( object $entity, string $entity_type, string $game_slug ): array {
		$audience = self::normalize( $entity->audience ?? self::EVERYONE );

		if ( $audience === self::EVERYONE ) {
			return array_map(
				static fn( $c ) => (int) $c->id,
				Character::all_for_game( $game_slug, [ 'status' => 'active', 'is_npc' => 0 ] )
			);
		}
		if ( $audience === self::STORYTELLERS ) {
			return [];
		}

		$connected = self::connected_character_ids( $entity, $entity_type );

		$rules    = $entity->audience_rules ?? null;
		$rule_ids = Query_Engine::resolve_audience_rules( $game_slug, is_array( $rules ) ? $rules : null );

		return array_values( array_unique( array_merge( $connected, $rule_ids ) ) );
	}

	/**
	 * Whether `$wp_user_id` may see one plot entry.
	 *
	 * @param object     $entry      A decoded plot_entries row - author_id, audience,
	 *                                audience_character_ids as a real array or null.
	 * @param int        $wp_user_id
	 * @param string     $game_slug
	 * @param bool       $can_manage
	 * @param int[]|null $out_batch_ids The game's currently-out release batch ids,
	 *                                   precomputed by a caller looping over one plot's
	 *                                   entries. Null resolves it here instead, via the
	 *                                   entry's own parent plot - a small extra query, only
	 *                                   ever paid by a caller that didn't bother batching it.
	 * @param object|null $plot         The entry's parent plot, already loaded by the caller
	 *                                   (both real call sites have it already). Null resolves
	 *                                   it here instead, the same fallback `$out_batch_ids`
	 *                                   gets. Only ever read for a `rumor_level` entry, whose
	 *                                   `rumor_level_key`/`rumor_level_match` live on
	 *                                   the plot, never the entry.
	 * @return bool
	 */
	public static function can_see_entry( object $entry, int $wp_user_id, string $game_slug, bool $can_manage, ?array $out_batch_ids = null, ?object $plot = null ): bool {
		if ( $can_manage || (int) $entry->author_id === $wp_user_id ) {
			return true;
		}

		// A rumor level text carries no release state or audience of its own.
		if ( $entry->entry_type === 'rumor_level' ) {
			$level = property_exists( $entry, 'level' ) && $entry->level !== null ? (int) $entry->level : 0;
			if ( $level <= 0 ) {
				return false;
			}
			if ( $plot === null ) {
				$plot_id = property_exists( $entry, 'plot_id' ) ? (int) $entry->plot_id : 0;
				$plot    = $plot_id ? Plot::find( $plot_id ) : null;
			}
			if ( ! $plot || empty( $plot->rumor_level_key ) || empty( $plot->rumor_level_match ) ) {
				return false;
			}
			$my_character_ids = self::character_ids_for_user( $wp_user_id, $game_slug );
			if ( empty( $my_character_ids ) ) {
				return false;
			}
			$eligible_ids = array_intersect( $my_character_ids, self::visible_character_ids( $plot, 'plot', $game_slug ) );
			foreach ( $eligible_ids as $character_id ) {
				$character = Character::find( $character_id );
				if ( $character && Query_Engine::trait_rating( $character, $plot->rumor_level_key, $plot->rumor_level_match ) >= $level ) {
					return true;
				}
			}
			return false;
		}

		if ( ! empty( $entry->held ) ) {
			$release_batch_id = ! empty( $entry->release_batch_id ) ? (int) $entry->release_batch_id : null;
			if ( $release_batch_id === null ) {
				return false;
			}
			if ( $out_batch_ids === null ) {
				$plot_id       = property_exists( $entry, 'plot_id' ) ? (int) $entry->plot_id : 0;
				$plot          = $plot_id ? Plot::find( $plot_id ) : null;
				$out_batch_ids = $plot ? Release_Batch::out_ids( (int) $plot->game_id ) : [];
			}
			if ( ! in_array( $release_batch_id, $out_batch_ids, true ) ) {
				return false;
			}
		}

		$audience = in_array( $entry->audience ?? null, Plot_Entry::AUDIENCE_VALUES, true )
			? $entry->audience
			: Plot_Entry::DEFAULT_AUDIENCE;

		if ( $audience === Plot_Entry::AUDIENCE_PLOT ) {
			return true;
		}
		if ( $audience === Plot_Entry::AUDIENCE_STORYTELLERS ) {
			return false;
		}

		$target_ids = is_array( $entry->audience_character_ids ?? null ) ? $entry->audience_character_ids : [];
		if ( empty( $target_ids ) ) {
			return false;
		}
		return (bool) array_intersect( self::character_ids_for_user( $wp_user_id, $game_slug ), $target_ids );
	}

	/**
	 * The shared decision behind `can_see()` and `filter()`, taking the viewer's own character IDs already resolved.
	 *
	 * @param int[]                  $my_character_ids
	 * @param array<string,int[]>|null $rule_memo Present only when called from `filter()` -
	 *                                              a rule set already resolved for an earlier
	 *                                              row in the same list, keyed by its own JSON.
	 *                                              `can_see()`'s single-entity call omits it,
	 *                                              since there is nothing to share a memo with.
	 * @param int[] $out_batch_ids The game's currently-out release batch ids - resolved
	 *                              once by can_see()/filter(), never per row. Only ever
	 *                              non-empty when $entity_type is 'plot'; world objects carry
	 *                              no held/release_batch_id columns.
	 */
	private static function visible_to( object $entity, string $entity_type, string $game_slug, array $my_character_ids, ?array &$rule_memo = null, array $out_batch_ids = [] ): bool {
		if ( $entity_type === 'plot' && ! empty( $entity->held ) ) {
			$release_batch_id = ! empty( $entity->release_batch_id ) ? (int) $entity->release_batch_id : null;
			if ( $release_batch_id === null || ! in_array( $release_batch_id, $out_batch_ids, true ) ) {
				return false;
			}
		}

		$audience = self::normalize( $entity->audience ?? self::EVERYONE );

		if ( $audience === self::EVERYONE ) {
			return true;
		}

		// A character connected to an item or location always sees it, whatever its audience.
		if ( ! in_array( $entity_type, [ 'plot', 'secret' ], true ) && ! empty( $my_character_ids )
			&& array_intersect( $my_character_ids, self::connected_character_ids( $entity, $entity_type ) ) ) {
			return true;
		}

		if ( $audience === self::STORYTELLERS ) {
			return false;
		}

		// restricted
		if ( empty( $my_character_ids ) ) {
			return false;
		}

		$connected = self::connected_character_ids( $entity, $entity_type );
		$rules     = is_array( $entity->audience_rules ?? null ) ? $entity->audience_rules : null;

		if ( $rule_memo === null ) {
			$rule_ids = Query_Engine::resolve_audience_rules( $game_slug, $rules );
		} else {
			$key = (string) wp_json_encode( $rules );
			if ( ! array_key_exists( $key, $rule_memo ) ) {
				$rule_memo[ $key ] = Query_Engine::resolve_audience_rules( $game_slug, $rules );
			}
			$rule_ids = $rule_memo[ $key ];
		}

		return (bool) array_intersect( $my_character_ids, array_merge( $connected, $rule_ids ) );
	}

	/**
	 * The characters connected to an entity.
	 *
	 * @param object $entity
	 * @param string $entity_type One of ENTITY_TYPES.
	 * @return int[]
	 */
	public static function connected_character_ids( object $entity, string $entity_type ): array {
		if ( $entity_type === 'plot' ) {
			$rows = Connection::for_source( 'plot', (int) $entity->id );
			return array_values( array_unique( array_map(
				static fn( $c ) => (int) $c->target_id,
				array_filter( $rows, static fn( $c ) => $c->target_type === 'character' )
			) ) );
		}

		if ( in_array( $entity_type, [ 'item', 'location' ], true ) ) {
			$rows = Connection::for_target( 'world_object', (int) $entity->id );
			return array_values( array_unique( array_map(
				static fn( $c ) => (int) $c->source_id,
				array_filter( $rows, static fn( $c ) => $c->source_type === 'character' )
			) ) );
		}

		if ( $entity_type === 'npc' ) {
			// Undirected ("connected either way").
			$rows = Connection::for_entity( 'character', (int) $entity->id );
			return array_values( array_unique( array_filter( array_map(
				static function ( $c ) use ( $entity ) {
					if ( $c->source_type === 'character' && $c->target_type === 'character' ) {
						return (int) $c->source_id === (int) $entity->id ? (int) $c->target_id : (int) $c->source_id;
					}
					return null;
				},
				$rows
			) ) ) );
		}

		if ( $entity_type === 'faction' ) {
			// A faction's own "connected characters" come from a different table entirely.
			return Faction_Member::character_ids_for_faction( (int) $entity->id );
		}

		if ( $entity_type === 'position' ) {
			// A position's only "connection" is its own current holder, if any.
			return ! empty( $entity->character_id ) ? [ (int) $entity->character_id ] : [];
		}

		if ( $entity_type === 'secret' ) {
			// A secret's own "connected characters" come from a different table entirely.
			return Secret_Reveal::visible_character_ids(
				(int) $entity->id,
				Release_Batch::out_ids( (int) $entity->game_id )
			);
		}

		return [];
	}

	/** @return int[] */
	private static function character_ids_for_user( int $wp_user_id, string $game_slug ): array {
		return array_map(
			static fn( $c ) => (int) $c->id,
			Character::find_for_user( $wp_user_id, $game_slug )
		);
	}
}
