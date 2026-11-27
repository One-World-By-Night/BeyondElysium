<?php

namespace BeyondElysium\Services;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Database\Fork_Merge;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * The one place Storyteller-only visibility is decided.
 */
class St_Visibility {

	/**
	 * Applies all four ST-only redactions to one character row in place: unsets `rp_notes` and the Archivist Toolkit
	 * link entirely, strips
	 * `[ST]...[/ST]`-marked text from `biography`/`notes`, and removes every Storyteller-only block's stored values from
	 * `sheet_data`.
	 *
	 * @param object        $character
	 * @param object|null   $game      Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool          $can_manage
	 * @param array<string>|null $hidden A caller-computed `storyteller_only_slugs()` result, for a caller looping over
	 *                                   many characters; a single-character caller may omit it.
	 */
	public static function filter_character( object $character, ?object $game, bool $can_manage, ?array $hidden = null ): void {
		if ( $can_manage ) {
			return;
		}

		unset( $character->rp_notes, $character->oat_character_uuid );
		$character->biography = St_Filter::strip_html_for_game( (string) ( $character->biography ?? '' ), $game->settings ?? null );
		$character->notes     = St_Filter::strip_html_for_game( (string) ( $character->notes ?? '' ), $game->settings ?? null );
		if ( property_exists( $character, 'public_description' ) && $character->public_description !== null ) {
			$character->public_description = St_Filter::strip_html_for_game( (string) $character->public_description, $game->settings ?? null );
		}
		self::strip_blocks( $character, $hidden ?? Schema_Block::storyteller_only_slugs( (string) ( $game->slug ?? '' ) ) );
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from an NPC public-profile projection's own `public_description`.
	 *
	 * @param object      $profile
	 * @param object|null $game
	 * @param bool        $can_manage
	 */
	public static function filter_npc_profile( object $profile, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}
		if ( property_exists( $profile, 'public_description' ) ) {
			$profile->public_description = St_Filter::strip_html_for_game( (string) ( $profile->public_description ?? '' ), $game->settings ?? null );
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a plot in place.
	 *
	 * @param object      $plot       A decoded plot row.
	 * @param object|null $game       Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 */
	public static function filter_plot( object $plot, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		$settings = $game->settings ?? null;
		foreach ( [ 'description', 'cliffhanger', 'resolution_details', 'resolution_impact' ] as $column ) {
			if ( isset( $plot->$column ) && is_string( $plot->$column ) ) {
				$plot->$column = St_Filter::strip_html_for_game( $plot->$column, $settings );
			}
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a faction's `description` and `goals` in place.
	 *
	 * @param object      $faction
	 * @param object|null $game       Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 */
	public static function filter_faction( object $faction, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		$settings = $game->settings ?? null;
		foreach ( [ 'description', 'goals' ] as $column ) {
			if ( isset( $faction->$column ) && is_string( $faction->$column ) ) {
				$faction->$column = St_Filter::strip_html_for_game( $faction->$column, $settings );
			}
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a plot entry's `content` in place.
	 *
	 * @param object      $entry      A decoded plot-entry row.
	 * @param object|null $game       Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 */
	public static function filter_entry( object $entry, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		if ( isset( $entry->content ) && is_string( $entry->content ) ) {
			$entry->content = St_Filter::strip_html_for_game( $entry->content, $game->settings ?? null );
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a game session's own `notes` in place, for anyone who isn't a Storyteller of
	 * the chronicle.
	 *
	 * @param object      $session
	 * @param object|null $game       Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 */
	public static function filter_session( object $session, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		if ( isset( $session->notes ) && is_string( $session->notes ) ) {
			$session->notes = St_Filter::strip_html_for_game( $session->notes, $game->settings ?? null );
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from an NPC casting's own `brief` in place.
	 *
	 * @param object      $casting
	 * @param object|null $game
	 * @param bool        $can_manage
	 */
	public static function filter_casting( object $casting, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}
		if ( isset( $casting->brief ) && is_string( $casting->brief ) ) {
			$casting->brief = St_Filter::strip_html_for_game( $casting->brief, $game->settings ?? null );
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a secret's own `content` in place.
	 *
	 * @param object      $secret
	 * @param object|null $game
	 * @param bool        $can_manage
	 */
	public static function filter_secret( object $secret, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}
		if ( isset( $secret->content ) && is_string( $secret->content ) ) {
			$secret->content = St_Filter::strip_html_for_game( $secret->content, $game->settings ?? null );
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from an after-game report's three own fields in place.
	 *
	 * @param object    $report
	 * @param object|null $game
	 * @param bool      $can_manage
	 * @return void
	 */
	public static function filter_report( object $report, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}
		foreach ( [ 'did', 'wants', 'to_staff' ] as $field ) {
			if ( isset( $report->$field ) && is_string( $report->$field ) ) {
				$report->$field = St_Filter::strip_html_for_game( $report->$field, $game->settings ?? null );
			}
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a chronicle's own `description` in place, for anyone who isn't a Storyteller
	 * of it.
	 *
	 * @param object $game       A decoded game row, also its own settings source.
	 * @param bool   $can_manage
	 */
	public static function filter_game( object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		if ( isset( $game->description ) && is_string( $game->description ) ) {
			$game->description = St_Filter::strip_html_for_game( $game->description, $game->settings ?? null );
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a connection's `notes` in place.
	 *
	 * @param object      $connection A decoded connection row.
	 * @param object|null $game       Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 */
	public static function filter_connection( object $connection, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		if ( isset( $connection->notes ) && is_string( $connection->notes ) ) {
			$connection->notes = St_Filter::strip_for_game( $connection->notes, $game->settings ?? null );
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from one change record in place.
	 *
	 * @param object      $change     A change row.
	 * @param object|null $game       Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 */
	public static function filter_change( object $change, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		$settings = $game->settings ?? null;
		foreach ( [ 'notes', 'review_notes', 'reason' ] as $column ) {
			if ( isset( $change->$column ) && is_string( $change->$column ) ) {
				$change->$column = St_Filter::strip_for_game( $change->$column, $settings );
			}
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a submission's `answer_note` in place.
	 *
	 * @param object      $submission A submission row.
	 * @param object|null $game       Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 */
	/**
	 * Strips `[ST]...[/ST]`-marked text from a join request's own message in place. `note` never reaches this method -
	 * it is read only through the join-requests review route, which is manager-only by itself.
	 *
	 * @param object      $join_request A decoded `be_join_requests` row.
	 * @param object|null $game         Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 * @return void
	 */
	public static function filter_join_request( object $join_request, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		if ( isset( $join_request->message ) && is_string( $join_request->message ) ) {
			$join_request->message = St_Filter::strip_for_game( $join_request->message, $game->settings ?? null );
		}
	}

	public static function filter_submission( object $submission, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		if ( isset( $submission->answer_note ) && is_string( $submission->answer_note ) ) {
			$submission->answer_note = St_Filter::strip_for_game( $submission->answer_note, $game->settings ?? null );
		}
	}

	/**
	 * Strips `[ST]...[/ST]`-marked text from a world object in place.
	 *
	 * @param object      $object     A decoded world-object row (`properties` an array).
	 * @param object|null $game       Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool        $can_manage
	 */
	public static function filter_world_object( object $object, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		$settings = $game->settings ?? null;
		foreach ( [ 'description', 'limitations' ] as $column ) {
			if ( isset( $object->$column ) && is_string( $object->$column ) ) {
				$object->$column = St_Filter::strip_html_for_game( $object->$column, $settings );
			}
		}

		if ( ! is_array( $object->properties ?? null ) ) {
			return;
		}
		$schema = World_Object::schemas()[ (string) ( $object->object_type ?? '' ) ] ?? [];
		foreach ( $object->properties as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			// A 'text' property is rich HTML like description/limitations.
			if ( ( $schema[ $key ] ?? null ) === 'text' ) {
				$object->properties[ $key ] = St_Filter::strip_html_for_game( $value, $settings );
			} elseif ( ( $schema[ $key ] ?? null ) === 'string' ) {
				$object->properties[ $key ] = St_Filter::strip_for_game( $value, $settings );
			}
		}
	}

	/**
	 * Filters blocks for whoever is asking about a chronicle named in a query string: a Storyteller there, or whoever
	 * holds the catalog capability there or on the site, gets them whole.
	 *
	 * @param iterable<object> $blocks
	 * @param string           $game_slug The chronicle the blocks are read for, or an empty string for the site's own.
	 */
	public static function filter_catalog_blocks_for_viewer( iterable $blocks, string $game_slug ): void {
		if ( Authorization::can_in_chronicle( 'be_manage_characters', $game_slug ) || Authorization::can_in_chronicle( 'be_manage_schemas', $game_slug ) ) {
			return;
		}

		$game = $game_slug === '' ? null : Game::find_by_slug( $game_slug );
		foreach ( $blocks as $block ) {
			self::filter_catalog_block( $block, $game, false );
		}
	}

	/**
	 * Takes the Storyteller-only text out of one schema block in place: every marked passage in its definition and its
	 * recorded changes, and, for a Storyteller-only block, every catalog description.
	 *
	 * @param object      $block
	 * @param object|null $game       Provides `settings` for the per-game markers.
	 * @param bool        $can_manage
	 */
	public static function filter_catalog_block( object $block, ?object $game, bool $can_manage ): void {
		if ( $can_manage ) {
			return;
		}

		$settings = $game->settings ?? null;
		$start    = (string) ( $settings->st_comment_start ?? '' );
		$end      = (string) ( $settings->st_comment_end ?? '' );
		$markers  = [ $start !== '' ? $start : '[ST]', $end !== '' ? $end : '[/ST]' ];
		$only     = ! empty( $block->storyteller_only );

		if ( isset( $block->definition ) ) {
			$block->definition = self::scrub_catalog_node( $block->definition, $markers, $only );
		}
		if ( ! isset( $block->fork_changes ) ) {
			return;
		}
		$block->fork_changes = $only
			? (object) Fork_Merge::NO_CHANGES
			: self::scrub_catalog_node( $block->fork_changes, $markers, false );
	}

	/**
	 * One value of a catalog block with its marked passages removed, walking arrays and objects.
	 *
	 * @param mixed                $node
	 * @param array{0:string,1:string} $markers
	 * @param bool                 $drop_descriptions Remove every description object, as a Storyteller-only block does.
	 * @return mixed
	 */
	private static function scrub_catalog_node( $node, array $markers, bool $drop_descriptions ) {
		if ( is_string( $node ) ) {
			return str_contains( $node, $markers[0] ) ? St_Filter::strip( $node, $markers[0], $markers[1] ) : $node;
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $value ) {
				$node[ $key ] = self::scrub_catalog_node( $value, $markers, $drop_descriptions );
			}
			return $node;
		}
		if ( is_object( $node ) ) {
			foreach ( get_object_vars( $node ) as $key => $value ) {
				if ( $drop_descriptions && $key === 'description' && ( is_object( $value ) || is_array( $value ) ) ) {
					unset( $node->$key );
					continue;
				}
				$node->$key = self::scrub_catalog_node( $value, $markers, $drop_descriptions );
			}
		}
		return $node;
	}

	/**
	 * Removes every Storyteller-only block's stored values from a character's `sheet_data`.
	 *
	 * @param object        $character
	 * @param array<string> $hidden
	 */
	private static function strip_blocks( object $character, array $hidden ): void {
		if ( ! is_array( $character->sheet_data ?? null ) ) {
			return;
		}

		$character->sheet_data = self::filter_sheet_data_blocks( $character->sheet_data, $hidden );
	}

	/**
	 * Removes every listed block's stored values from a `sheet_data` array, minus any named in `$allow_blocks`.
	 *
	 * @param array<string,mixed> $sheet_data
	 * @param array<string>       $hidden
	 * @param array<string>       $allow_blocks
	 * @return array<string,mixed>
	 */
	public static function filter_sheet_data_blocks( array $sheet_data, array $hidden, array $allow_blocks = [] ): array {
		foreach ( array_diff( $hidden, $allow_blocks ) as $slug ) {
			unset( $sheet_data[ $slug ] );
		}
		return $sheet_data;
	}

	/**
	 * Removes every Storyteller-only section from a resolved template layout.
	 *
	 * @param array<string,mixed> $layout
	 * @param bool                $can_manage
	 * @param string              $game_slug The chronicle the layout is shown in - a block is
	 *                                        Storyteller-only per chronicle.
	 * @param array<string>|null  $hidden A caller-computed `storyteller_only_slugs()` result,
	 *                                     for a caller resolving many layouts; omit for a fresh lookup.
	 * @param array<string>       $allow_blocks A Storyteller-only block to keep visible anyway -
	 *                                     the NPC casting brief's own carve-out,
	 *                                     used only there; every other caller leaves this empty.
	 * @return array<string,mixed>
	 */
	public static function filter_layout( array $layout, bool $can_manage, string $game_slug, ?array $hidden = null, array $allow_blocks = [] ): array {
		if ( $can_manage || ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) ) {
			return $layout;
		}

		$hidden = array_diff( $hidden ?? Schema_Block::storyteller_only_slugs( $game_slug ), $allow_blocks );
		if ( empty( $hidden ) ) {
			return $layout;
		}

		// array_values keeps sections a JSON array.
		$layout['sections'] = array_values( array_filter(
			$layout['sections'],
			static fn( $section ) => ! in_array( $section['block_slug'] ?? '', $hidden, true )
		) );

		return $layout;
	}
}
