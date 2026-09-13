<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * The one place Storyteller-only visibility is decided, extracted from three
 * duplicated call sites in `Characters_Controller` and one in
 * `Templates_Controller` (signed-pdf-design.md §3d). ST-only data is filtered
 * in four real operations, not one, and the lesson of `v0.21.28` was that
 * skipping any single one leaks: hiding a block from the resolved *layout*
 * still ships that block's real values inside `sheet_data`, and stripping
 * `sheet_data` still leaves `rp_notes`/`[ST]`-marked `biography`/`notes` text
 * exposed. A server-side PDF generator is a second, direct reader of
 * `Character::find()` - if it skipped this extraction and re-implemented the
 * four operations a fourth time, that fourth copy is exactly how a future
 * change to one copy leaves the other three (or five) stale.
 *
 * `$can_manage` is a caller-supplied boolean rather than this class calling
 * `current_user_can()` itself - keeps every method here pure and testable
 * without mocking WordPress global state, and the caller already knows the
 * answer in every real call site.
 *
 * Deliberately holds no cache of its own. An earlier draft memoized
 * `Schema_Block::storyteller_only_slugs()` in a static property to preserve
 * the "look it up once per page, not once per character" optimization the
 * three original call sites each had - but that optimization was always
 * safely scoped to a local variable inside one method call, never to a
 * class-level static, and a class-level static here reintroduces exactly
 * the bug class this project has already been burned by once (a static memo
 * latching a stale result across PHPUnit test methods that share one PHP
 * process, the same shape as the `v0.21.28` NPC-forms incident). The
 * `$hidden` parameters below let a caller that's about to loop over many
 * characters compute the list once and pass it through instead - the
 * original optimization, kept where it always actually belonged.
 *
 * @see BE_PROCESS/signed-pdf-design.md §3d
 */
class St_Visibility {

	/**
	 * Applies all four ST-only redactions to one character row in place:
	 * unsets `rp_notes` entirely, strips `[ST]...[/ST]`-marked text from
	 * `biography`/`notes`, and removes every Storyteller-only block's stored
	 * values from `sheet_data`. A manager is returned untouched.
	 *
	 * @param object        $character
	 * @param object|null   $game      Provides `settings` for `St_Filter`'s per-game markers.
	 * @param bool          $can_manage
	 * @param array<string>|null $hidden A caller-computed `storyteller_only_slugs()` result, for a
	 *                                    caller looping over many characters to avoid an N+1; a
	 *                                    single-character caller may omit it for a fresh lookup.
	 */
	public static function filter_character( object $character, ?object $game, bool $can_manage, ?array $hidden = null ): void {
		if ( $can_manage ) {
			return;
		}

		unset( $character->rp_notes );
		$character->biography = St_Filter::strip_for_game( (string) ( $character->biography ?? '' ), $game->settings ?? null );
		$character->notes     = St_Filter::strip_for_game( (string) ( $character->notes ?? '' ), $game->settings ?? null );
		self::strip_blocks( $character, $hidden ?? Schema_Block::storyteller_only_slugs() );
	}

	/**
	 * Removes every Storyteller-only block's stored values from a
	 * character's `sheet_data`. Dropping the section from a resolved
	 * template layout (`filter_layout()`) does not cover this on its own -
	 * the block's own data would still ship inside the character payload.
	 *
	 * @param object        $character
	 * @param array<string> $hidden
	 */
	private static function strip_blocks( object $character, array $hidden ): void {
		if ( ! is_array( $character->sheet_data ?? null ) ) {
			return;
		}

		foreach ( $hidden as $slug ) {
			unset( $character->sheet_data[ $slug ] );
		}
	}

	/**
	 * Removes every Storyteller-only section from a resolved template
	 * layout - the layout-side half of the same visibility rule
	 * `filter_character()` applies to the data side. A manager, or a
	 * layout with no `sections` array, is returned untouched.
	 *
	 * @param array<string,mixed> $layout
	 * @param bool                $can_manage
	 * @param array<string>|null  $hidden A caller-computed `storyteller_only_slugs()` result,
	 *                                     for a caller resolving many layouts to avoid an N+1;
	 *                                     omit for a fresh lookup. Also lets this be exercised
	 *                                     as a pure unit test, since `storyteller_only_slugs()`
	 *                                     itself reads `$wpdb`.
	 * @return array<string,mixed>
	 */
	public static function filter_layout( array $layout, bool $can_manage, ?array $hidden = null ): array {
		if ( $can_manage || ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) ) {
			return $layout;
		}

		$hidden = $hidden ?? Schema_Block::storyteller_only_slugs();
		if ( empty( $hidden ) ) {
			return $layout;
		}

		// array_values keeps sections a JSON array rather than a keyed object.
		$layout['sections'] = array_values( array_filter(
			$layout['sections'],
			static fn( $section ) => ! in_array( $section['block_slug'] ?? '', $hidden, true )
		) );

		return $layout;
	}
}
