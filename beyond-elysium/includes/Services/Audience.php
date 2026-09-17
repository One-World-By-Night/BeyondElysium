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
 * The one place a plot's or world object's "who can see this" is decided (1.1.0 §2.1-2.2).
 *
 * An audience is one of three values, resolved the same way regardless of entity type:
 *
 *   - `everyone`      Every member of the chronicle.
 *   - `storytellers`  Only holders of the entity's own manage capability - `be_manage_plots`
 *                      for a plot, `be_manage_world_objects` for an item or location. Never
 *                      decided here: the caller already knows this the same way every other
 *                      capability check in this codebase does, and passes it in as `$can_manage`
 *                      (`$can_manage` is caller-supplied rather than this class calling
 *                      `current_user_can()` itself - `St_Visibility`'s own precedent, keeping
 *                      every method here pure and testable without mocking WordPress globals).
 *   - `restricted`    The characters **connected** to the entity, plus every character
 *                      **matching its rules** - combined with OR, since "specific characters"
 *                      and "matching rules" are meant to compose (owner: "the Tremere, plus
 *                      Marcus"), not choose between each other.
 *
 * A player sees an entity when any of their own characters is in its audience. A Storyteller
 * (an entity's own `$can_manage`) always sees everything, at every audience value.
 *
 * Deliberately holds no cache of its own, like `St_Visibility` - a class-level static here
 * would reintroduce the exact bug class this project has already been burned by twice (a
 * static memo latching a stale result across PHPUnit test methods sharing one process,
 * `v0.21.28`/D40). `Query_Engine::resolve_audience_rules()` already memoizes the one call in
 * this class expensive enough to be worth it, scoped to its own request-lifetime cache.
 *
 * `$entity_type` is not part of the audience *value* - both plots and world objects use the
 * same three values - but connected-character lookups have to know which direction a
 * connection runs (a plot is the source of its character connections; a world object is the
 * target of them), so every method here takes it explicitly.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §2.1, §2.2
 */
class Audience {

	const EVERYONE     = 'everyone';
	const STORYTELLERS = 'storytellers';
	const RESTRICTED   = 'restricted';

	/** Every real audience value - what a write path validates against. */
	const VALUES = [ self::EVERYONE, self::STORYTELLERS, self::RESTRICTED ];

	/** Entity types this class knows how to resolve connections for. */
	const ENTITY_TYPES = [ 'plot', 'item', 'location', 'npc', 'secret', 'faction', 'position' ];

	/**
	 * Normalizes a stored or submitted audience value, falling back to `everyone` for
	 * anything unrecognized - an empty column on a row from before this migration, or a
	 * malformed value that should fail open to the *most* visible state, never the least,
	 * so a bug here cannot manufacture a new way to hide a Storyteller's own content from
	 * themselves or silently lock players out of something already public.
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
		// property_exists(), not a bare access: a synthetic test fixture (or any plot-shaped
		// object built without it) must not fatal just because this gate exists - it can only
		// ever matter when $entity->held is also set, which such a fixture never is either.
		$out_batch_ids = ( $entity_type === 'plot' && property_exists( $entity, 'game_id' ) )
			? Release_Batch::out_ids( (int) $entity->game_id )
			: [];
		$no_rule_memo  = null;
		return self::visible_to( $entity, $entity_type, $game_slug, $my_character_ids, $no_rule_memo, $out_batch_ids );
	}

	/**
	 * Narrows a list of entities to the ones `$wp_user_id` may see. Resolves the viewer's own
	 * characters once, and a repeated rule set once, not once per row: a Storyteller who set
	 * the same "Clan is Tremere" rule on several locations does not re-run that query per
	 * location. That memo lives on this call's own stack, never a class property - a static
	 * cache here reintroduces the exact bug `Query_Engine::resolve_audience_rules()`'s own
	 * docblock names (a stale result surviving past the one call, or the one test, that
	 * populated it).
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
	 * `filter()` for a world-object list that mixes object types - `World_Objects_Controller`'s
	 * own catalog list is never scoped to a single `object_type` unless the request asks for
	 * one (1.1.0 §2.5). Splits by `object_type`, applies `filter()` to `item` and `location`
	 * rows only, and passes every other type (rotes, boons) through untouched - Audience has no
	 * opinion on either, and this is the one place that distinction is made so no caller has to
	 * repeat it. Original relative order is preserved.
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
	 * The character IDs an entity's audience actually reaches, for every audience value -
	 * every active, non-NPC character in the chronicle for `everyone` (an inactive or NPC
	 * character has no player to reach); none for `storytellers` (a Storyteller's own access
	 * never routes through holding a character); everyone connected to a `restricted` entity
	 * OR matching its rules. Used by `visible_to()`'s own restricted branch, by the editor's
	 * live "N characters can see this" count (§2.7) through the REST audience-preview route,
	 * and by 1.1.0 §2.4's directed-entry validation - "the character picker offers only
	 * characters who can see the plot" is exactly this list.
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
	 * Whether `$wp_user_id` may see one plot entry (1.1.0 §2.4) - a narrower, author-aware
	 * sibling of `can_see()` for a plot's own timeline posts rather than the plot itself.
	 * `plot` (visible to whoever can see the parent plot - the caller establishes that
	 * separately, by checking the plot's own audience first) and `storytellers` behave like
	 * their entity-audience namesakes; `characters` is unique to entries - a Storyteller's
	 * post aimed at specific characters' players, stored as a literal id list rather than
	 * connections or rules, since a directed post names exactly who it's for and nothing
	 * about it should shift later the way a rule-matched audience can.
	 *
	 * **The author always sees their own entry**, at every audience value, including a
	 * private reply a Storyteller has not yet reviewed - the one respect in which this
	 * differs from every other `Audience` method, none of which have an author to except.
	 *
	 * @param object     $entry      A decoded plot_entries row - author_id, audience,
	 *                                audience_character_ids as a real array or null.
	 * @param int        $wp_user_id
	 * @param string     $game_slug
	 * @param bool       $can_manage
	 * @param int[]|null $out_batch_ids The game's currently-out release batch ids (§3.2),
	 *                                   precomputed by a caller looping over one plot's
	 *                                   entries. Null resolves it here instead, via the
	 *                                   entry's own parent plot - a small extra query, only
	 *                                   ever paid by a caller that didn't bother batching it.
	 * @param object|null $plot         The entry's parent plot, already loaded by the caller
	 *                                   (both real call sites have it already). Null resolves
	 *                                   it here instead, the same fallback `$out_batch_ids`
	 *                                   gets. Only ever read for a `rumor_level` entry, whose
	 *                                   `rumor_level_key`/`rumor_level_match` (§3.4) live on
	 *                                   the plot, never the entry.
	 * @return bool
	 */
	public static function can_see_entry( object $entry, int $wp_user_id, string $game_slug, bool $can_manage, ?array $out_batch_ids = null, ?object $plot = null ): bool {
		if ( $can_manage || (int) $entry->author_id === $wp_user_id ) {
			return true;
		}

		// A rumor level text (§3.4) carries no release state or audience of its own - it
		// follows its plot, narrowed further by trait rating - so it skips the ordinary
		// held/audience machinery below entirely rather than partly falling through it.
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

		// held/release_batch_id gate (§3.2) - the same rule as a plot's own, checked first:
		// a held entry with no batch, or a not-yet-out one, is a draft regardless of audience.
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
	 * The shared decision behind `can_see()` and `filter()`, taking the viewer's own
	 * character IDs already resolved so a list of many entities does not re-resolve them
	 * once per row.
	 *
	 * @param int[]                  $my_character_ids
	 * @param array<string,int[]>|null $rule_memo Present only when called from `filter()` -
	 *                                              a rule set already resolved for an earlier
	 *                                              row in the same list, keyed by its own JSON.
	 *                                              `can_see()`'s single-entity call omits it,
	 *                                              since there is nothing to share a memo with.
	 * @param int[] $out_batch_ids The game's currently-out release batch ids (§3.2) - resolved
	 *                              once by can_see()/filter(), never per row. Only ever
	 *                              non-empty when $entity_type is 'plot'; world objects carry
	 *                              no held/release_batch_id columns.
	 */
	private static function visible_to( object $entity, string $entity_type, string $game_slug, array $my_character_ids, ?array &$rule_memo = null, array $out_batch_ids = [] ): bool {
		// held/release_batch_id gate (§3.2), checked before the ordinary audience rule: a
		// held plot with no batch, or one whose batch is not yet out, is a draft - never
		// visible to a non-manager regardless of what its audience would otherwise allow.
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

		// A character connected to an item or location always sees it, whatever its audience -
		// the Storyteller gave it to them, and "Print My Items"/the character's own sheet must
		// keep working even when the object is otherwise storytellers-only (1.1.0 §2.5). Plots
		// get no such exception: a storytellers-only plot stays invisible even to a character
		// connected to it for some other, in-fiction reason (§2.1's own worked example). A
		// secret gets no exception either, by explicit design (§3.11): `storytellers` means
		// staff only, always - a revealed character only matters once the secret's own
		// audience is `restricted`, the same as every other entity's connections do.
		if ( ! in_array( $entity_type, [ 'plot', 'secret' ], true ) && ! empty( $my_character_ids )
			&& array_intersect( $my_character_ids, self::connected_character_ids( $entity, $entity_type ) ) ) {
			return true;
		}

		if ( $audience === self::STORYTELLERS ) {
			// $can_manage already returned true above for an actual Storyteller; a non-manager
			// never sees a storytellers-only entity through any character they hold, other than
			// the connected-item/location exception just above.
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
			// wp_json_encode() can return false for a value it cannot encode; $rules is always
			// either null or an already-decoded array here, so that never actually happens,
			// but the key still has to be a real string for array_key_exists().
			$key = (string) wp_json_encode( $rules );
			if ( ! array_key_exists( $key, $rule_memo ) ) {
				$rule_memo[ $key ] = Query_Engine::resolve_audience_rules( $game_slug, $rules );
			}
			$rule_ids = $rule_memo[ $key ];
		}

		return (bool) array_intersect( $my_character_ids, array_merge( $connected, $rule_ids ) );
	}

	/**
	 * The characters connected to an entity - the "specific characters" half of a
	 * `restricted` audience. Which side of a connection row that is depends on the entity
	 * type: a plot is always the source of its character connections (`apr_actor`, the
	 * owner; `plot_member`, an invited character - both count as "connected" for audience
	 * purposes, §2.3a), while a world object is always the target of them (a character
	 * *holds* an item or knows of a location).
	 *
	 * Public since 1.1.0 S5 (§3.5): a Storyteller's plot post notifies exactly this set's own
	 * players (never the whole chronicle of an `everyone`-audience plot, which
	 * visible_character_ids() would include) - the one existing caller of "connected, not
	 * merely visible" this codebase needed before a second one made this worth exposing.
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
			// Undirected (1.1.0 §3.7: "connected either way") - a character-to-character
			// connection has no fixed source/target side the way a plot's or world object's
			// own connections do, so both sides of for_entity()'s own OR are read, taking
			// whichever id isn't this NPC's own.
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
			// A faction's own "connected characters" (§3.10) come from a different table
			// entirely - be_faction_members, not be_connections - the same shape `secret`
			// already uses for a source with no generic connection to filter.
			return Faction_Member::character_ids_for_faction( (int) $entity->id );
		}

		if ( $entity_type === 'position' ) {
			// A position's only "connection" is its own current holder, if any - reused so
			// a holder always sees a position they hold (the item/location bypass below),
			// the same "the Storyteller gave it to them" reasoning applied to an office.
			return ! empty( $entity->character_id ) ? [ (int) $entity->character_id ] : [];
		}

		if ( $entity_type === 'secret' ) {
			// A secret's own "connected characters" (§3.11) come from a different table
			// entirely - be_secret_reveals, not be_connections - and each reveal carries its
			// own independent held/release_batch_id gate (§3.2), unlike every branch above.
			// Resolved from the secret's own game_id rather than threading an $out_batch_ids
			// param through can_see()/filter()/visible_character_ids(): this is the only
			// entity type whose connections need a release-batch lookup at all, so keeping it
			// local here is simpler than widening three public signatures for one caller.
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
