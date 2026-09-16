<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Sheet_Style;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Display\Change_Description;
use BeyondElysium\Services\Display\Cross_Block_Ref;
use BeyondElysium\Services\Display\Layout_Flow;
use BeyondElysium\Services\Display\Power_Display;
use BeyondElysium\Services\Display\Temper_Display;
use BeyondElysium\Services\Display\Trait_Display;
use BeyondElysium\Services\Display\Trait_Grouping;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a character down to a plain, presentation-neutral array: the same
 * walk the on-screen sheet (`CharacterSheet.tsx` + `BlockRenderer.tsx`) does,
 * done once server-side so a signed PDF renders identically instead of a
 * second, independently-derived interpretation of `sheet_data`
 * (signed-pdf-design.md Section 3a).
 *
 * No TCPDF reference anywhere in this file, and no PHP objects in its return
 * value beyond stdClass - `Pdf_Writer` (SP-7) turns this array into pages
 * without needing to know how any of it was produced. Every string under a
 * section's `rows`/`groups` is already final: the formatting happened in
 * `Services\Display\*`, exactly as the on-screen renderer would have called
 * them.
 *
 * `St_Visibility` is applied before any section is read, exactly like
 * `Characters_Controller`/`Templates_Controller` already apply it - this is a
 * second, direct reader of `Character::find()`, and skipping the extraction
 * here would be the fourth copy of the same rule (Section 3d).
 *
 * `$options` (all optional):
 *  - can_manage       bool  Caller-resolved capability check (never trust a
 *                           client-sent value here - same rule as D13/D22/D33).
 *                           An absent/false value hides Storyteller-only data,
 *                           same as St_Visibility's own default.
 *  - full_power_names bool  tiered_power sections render every named rung
 *                           instead of a single numeric total (Section 5
 *                           ruling 1 - the `full_power_names` route param).
 *  - background       bool  Include the character's biography as a prose entry.
 *  - notes            bool  Include the character's notes as a prose entry.
 *  - xp_history       bool  Include the approved-change XP history table.
 *
 * @see BE_PROCESS/signed-pdf-design.md Section 3a, SP-5
 */
class Sheet_Document {

	/**
	 * Resolves one document per character id, silently skipping an id that
	 * doesn't resolve to a real character or a real creature stack rather
	 * than failing the whole batch - the caller (Sheets_Controller, SP-9)
	 * already validated the ids it's passing in.
	 *
	 * @param int[]                $character_ids
	 * @param string               $game_slug
	 * @param array<string,mixed>  $options
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_characters( array $character_ids, string $game_slug, array $options = [] ): array {
		$game = Game::find_by_slug( $game_slug );
		if ( $game === null ) {
			return [];
		}

		$documents = [];
		foreach ( $character_ids as $character_id ) {
			$document = self::build( (int) $character_id, $game, $options );
			if ( $document !== null ) {
				$documents[] = $document;
			}
		}
		return $documents;
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>|null
	 */
	private static function build( int $character_id, object $game, array $options ): ?array {
		$character = Character::find( $character_id );
		if ( $character === null ) {
			return null;
		}

		$can_manage = ! empty( $options['can_manage'] );
		St_Visibility::filter_character( $character, $game, $can_manage );

		$resolved = Creature_Stack::resolve( $character->stack_slug, $game->slug );
		if ( $resolved === null ) {
			return null;
		}
		$stack  = $resolved['stack'];
		$blocks = $resolved['blocks'];

		$template_type = ! empty( $character->is_npc ) ? 'npc_full' : 'sheet_full';
		$template      = Template::resolve( $character->stack_slug, $template_type, (int) $game->id );
		$layout        = $template->layout ?? ( Layout_Generator::generate_for_stack( $character->stack_slug ) ?? [ 'sections' => [] ] );
		$layout        = St_Visibility::filter_layout( $layout, $can_manage, $game->slug );

		$sheet_data = is_array( $character->sheet_data ) ? $character->sheet_data : [];

		return [
			'title'  => $character->name,
			'header' => [
				[ 'Name', $character->name ],
				[ 'Type', $stack->name ],
				[ 'Status', $character->status ],
				[ 'Player', $character->player_name ?? '—' ],
				[ 'XP Earned', $character->xp_earned ],
				[ 'XP Unspent', $character->xp_unspent ],
			],
			'portrait_path'    => self::attachment_path( $character->image_id ?? null ),
			'style'            => self::build_style( $character_id ),
			'sections'         => self::build_sections( $layout['sections'] ?? [], $blocks, $sheet_data, $options ),
			'prose'            => self::build_prose( $character, $options ),
			'xp_history'       => ! empty( $options['xp_history'] ) ? self::build_xp_history( $character_id ) : [],
			'provenance_lines' => self::build_provenance( $character, $game ),
		];
	}

	/**
	 * Walks a layout's sections in flow order, formatting each one through
	 * the `Display\*` twin matching its block's `section_type` - the same
	 * dispatch `BlockRenderer.tsx` does on screen. A section naming a block
	 * that no longer exists is skipped (nothing to surface); a section whose
	 * block has a real but unrecognized `section_type` is still included,
	 * with no `rows`/`groups` key - `Pdf_Writer`'s own default branch
	 * (Section 3b) draws the visible marker from `section_type`/`block_slug`
	 * alone, matching `BlockRenderer.tsx`'s own default branch.
	 *
	 * @param array<int,array<string,mixed>> $sections
	 * @param array<string,object>           $blocks
	 * @param array<string,mixed>            $sheet_data
	 * @param array<string,mixed>            $options
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_sections( array $sections, array $blocks, array $sheet_data, array $options ): array {
		$out = [];

		foreach ( Layout_Flow::sorted_for_flow( $sections ) as $section ) {
			$slug  = $section['block_slug'] ?? '';
			$block = $blocks[ $slug ] ?? null;
			if ( $block === null ) {
				continue;
			}

			$definition   = is_object( $block->definition ?? null ) ? $block->definition : (object) [];
			$section_data = $sheet_data[ $slug ] ?? null;

			$entry = [
				'block_slug'   => $slug,
				'section_type' => $block->section_type,
				'title'        => self::section_title( $section, $sheet_data ),
				'column'       => (int) ( $section['column'] ?? 0 ),
				'order'        => (int) ( $section['order'] ?? 0 ),
				'span'         => Layout_Flow::span_for( $section['width'] ?? null ),
			];

			switch ( $block->section_type ) {
				case 'trait_list':
					$entry['groups'] = self::trait_list_groups( $section_data, $definition, $section );
					break;
				case 'tiered_power':
					$entry['rows'] = self::tiered_power_rows( $section_data, $definition, $options );
					break;
				case 'resource_pool':
					$entry['rows'] = self::resource_pool_rows( $section_data, $definition, $sheet_data );
					break;
				case 'identity_field':
					$entry['rows'] = self::identity_field_rows( $section_data, $definition );
					break;
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $section Raw layout section (plain array - `title`/`title_refs`).
	 * @param array<string,mixed> $sheet_data
	 */
	private static function section_title( array $section, array $sheet_data ): string {
		$title_refs = array_map(
			static fn( $ref ) => (object) $ref,
			array_values( (array) ( $section['title_refs'] ?? [] ) )
		);

		$section_obj = (object) [
			'title'      => (string) ( $section['title'] ?? '' ),
			'title_refs' => $title_refs,
		];

		return Cross_Block_Ref::resolve_section_title( $section_obj, $sheet_data );
	}

	/**
	 * @param mixed                $section_data Raw `sheet_data[block_slug]` value.
	 * @param array<string,mixed>  $section      Raw layout section (for its own `display` override).
	 * @return array<int,array{label:?string,rows:array<int,string>}>
	 */
	private static function trait_list_groups( mixed $section_data, object $definition, array $section ): array {
		$traits = Trait_Grouping::to_traits( $section_data );
		$mode   = Trait_Grouping::resolve_display( $section['display'] ?? null, $definition->display ?? null );
		$nested = Trait_Grouping::group_traits_by_field( $traits, $definition );

		$groups = [];

		if ( $nested !== null ) {
			foreach ( $nested as $group ) {
				foreach ( $group['subgroups'] as $subgroup ) {
					$label = $subgroup['subgroup'] !== null
						? $group['group'] . ' — ' . $subgroup['subgroup']
						: $group['group'];
					$items   = Trait_Grouping::sort_if_alphabetized( $subgroup['items'], $definition->alphabetize ?? null );
					$groups[] = [ 'label' => $label, 'rows' => self::render_traits( $items, $mode ) ];
				}
			}
			return $groups;
		}

		foreach ( Trait_Grouping::group_by_category( $traits, $definition ) as $group ) {
			$items    = Trait_Grouping::sort_if_alphabetized( $group['traits'], $definition->alphabetize ?? null );
			$groups[] = [ 'label' => $group['label'], 'rows' => self::render_traits( $items, $mode ) ];
		}

		return $groups;
	}

	/**
	 * `$traits` is `to_traits()`'s `{name,total,note}` output, having passed through
	 * `sort_if_alphabetized()` - typed loosely here (rather than repeating the fuller
	 * shape) because that method's own signature only guarantees the `name` key it
	 * actually reads; the other two survive the reorder untouched at runtime, and
	 * `display_trait()` reads both defensively via `??` regardless.
	 *
	 * @param array<int,array<string,mixed>> $traits
	 * @return string[]
	 */
	private static function render_traits( array $traits, string $mode ): array {
		return array_values( array_map(
			static fn( $trait ) => Trait_Display::display_trait( (object) $trait, $mode ),
			$traits
		) );
	}

	/**
	 * @param mixed                $section_data Raw `sheet_data[block_slug]` value - a held-power list.
	 * @param array<string,mixed>  $options
	 * @return string[]
	 */
	private static function tiered_power_rows( mixed $section_data, object $definition, array $options ): array {
		$held_list = is_array( $section_data ) ? $section_data : [];
		$mode      = ! empty( $options['full_power_names'] ) ? 'named' : 'numeric';

		$rows = [];
		foreach ( $held_list as $held ) {
			$held  = is_array( $held ) ? $held : [];
			$label = $mode === 'numeric'
				? Power_Display::numeric_label( $definition, $held )
				: implode( ', ', Power_Display::named_mode_rows( $definition, $held ) );
			$rows[] = Power_Display::with_tradition( $held, $label );
		}
		return $rows;
	}

	/**
	 * @param mixed                $section_data Raw `sheet_data[block_slug]` value - pool_name => {permanent,temporary}.
	 * @param array<string,mixed>  $sheet_data   The character's whole sheet_data, for cross-block pool-name lookups.
	 * @return string[]
	 */
	private static function resource_pool_rows( mixed $section_data, object $definition, array $sheet_data ): array {
		$pool_values = is_array( $section_data ) ? $section_data : [];

		$rows = [];
		foreach ( ( $definition->pools ?? [] ) as $pool ) {
			$value = $pool_values[ $pool->name ] ?? null;
			if ( is_array( $value ) ) {
				$permanent = (int) ( $value['permanent'] ?? 0 );
				$temporary = (int) ( $value['temporary'] ?? 0 );
			} else {
				$permanent = (int) ( $pool->default_start ?? 0 );
				$temporary = (int) ( $pool->default_start ?? 0 );
			}

			$name   = Cross_Block_Ref::resolve_pool_name( $pool, $sheet_data );
			$rows[] = $name . ': ' . Temper_Display::display( $permanent, $temporary );
		}
		return $rows;
	}

	/**
	 * @param mixed $section_data Raw `sheet_data[block_slug]` value - field_name => value.
	 * @return string[]
	 */
	private static function identity_field_rows( mixed $section_data, object $definition ): array {
		$values = is_array( $section_data ) ? $section_data : [];

		$rows = [];
		foreach ( ( $definition->fields ?? [] ) as $field ) {
			$value = $values[ $field->name ] ?? null;
			// A multiselect holds a list: its choices, joined as the on-screen sheet joins them -
			// never cast to the word "Array" (1.0.0-review F-078).
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$display = ( $value === null || $value === '' ) ? '—' : (string) $value;
			$rows[]  = $field->name . ': ' . $display;
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function build_prose( object $character, array $options ): array {
		$prose = [];
		if ( ! empty( $options['background'] ) ) {
			$prose[] = [ 'Background', (string) ( $character->biography ?? '' ) ];
		}
		if ( ! empty( $options['notes'] ) ) {
			$prose[] = [ 'Notes', (string) ( $character->notes ?? '' ) ];
		}
		return $prose;
	}

	/**
	 * Only `status = 'approved'` changes count as real history on a signed
	 * document - a still-pending change may yet be denied, and a formal
	 * record should not represent it as having already happened.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	private static function build_xp_history( int $character_id ): array {
		$changes = Change::for_character( $character_id, [ 'status' => 'approved', 'order' => 'ASC' ] );

		$rows = [];
		foreach ( $changes as $change ) {
			$change_data = (array) $change->change_data;
			$description = Change_Description::describe( $change->change_type, $change_data );
			$delta       = Change_Description::xp_delta( $change->change_type, $change_data, (float) ( $change->xp_cost ?? 0 ) );

			$rows[] = [
				substr( (string) $change->submitted_at, 0, 10 ),
				$description,
				$delta > 0 ? '+' . $delta : (string) $delta,
			];
		}
		return $rows;
	}

	/**
	 * @return array{0:string,1:string}
	 */
	private static function build_provenance( object $character, object $game ): array {
		return [
			(string) $character->uuid,
			sprintf( '%s · %s · Beyond Elysium %s', $game->slug, current_time( 'Y-m-d' ), BE_VERSION ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_style( int $character_id ): array {
		$style = Sheet_Style::for_character( $character_id );
		if ( $style === null ) {
			return [
				'font_family'           => null,
				'accent_color'          => null,
				'background_color'      => null,
				'text_color'            => null,
				'background_image_path' => null,
				'section_graphics'      => [],
			];
		}

		$graphics = [];
		foreach ( (array) ( $style->section_graphics ?? [] ) as $block_slug => $attachment_id ) {
			$path = self::attachment_path( $attachment_id );
			if ( $path !== null ) {
				$graphics[ $block_slug ] = $path;
			}
		}

		return [
			'font_family'           => $style->font_family ?? null,
			'accent_color'          => $style->accent_color ?? null,
			'background_color'      => $style->background_color ?? null,
			'text_color'            => $style->text_color ?? null,
			'background_image_path' => self::attachment_path( $style->background_image_id ?? null ),
			'section_graphics'      => $graphics,
		];
	}

	/**
	 * Resolves a WordPress attachment id to its real local filesystem path -
	 * `get_attached_file()`, not `wp_get_attachment_image_url()`: this document
	 * is drawn by TCPDF locally, never fetched by a browser, and every image
	 * reference in this catalog is a local attachment (Section 4b - TCPDF's
	 * curl dependency is a build-time-only platform check, never exercised at
	 * runtime here).
	 */
	private static function attachment_path( mixed $attachment_id ): ?string {
		$attachment_id = (int) ( $attachment_id ?? 0 );
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$path = get_attached_file( $attachment_id );
		return ( $path !== false && $path !== '' ) ? $path : null;
	}
}
