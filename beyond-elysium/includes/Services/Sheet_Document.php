<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Npc_Casting;
use BeyondElysium\Models\Schema_Block;
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
 * Resolves a character down to a plain, presentation-neutral array: the same walk the on-screen sheet
 * (`CharacterSheet.tsx` + `BlockRenderer.tsx`) does, done once server-side.
 */
class Sheet_Document {

	/**
	 * Resolves one document per character id, silently skipping an id that doesn't resolve to a real character or a real
	 * creature stack.
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
	 * Resolves an NPC's own template type, respecting `npc_detail`.
	 *
	 * @param object $character
	 * @return string|null
	 */
	private static function npc_template_type( object $character ): ?string {
		if ( empty( $character->is_npc ) ) {
			return null;
		}
		return ( $character->npc_detail ?? 'full' ) === 'quick' ? 'npc_quick' : 'npc_full';
	}

	/**
	 * Resolves one NPC casting into the same presentation-neutral shape `for_characters()` produces, for the casting
	 * brief screen and its PDF.
	 *
	 * @param int    $casting_id
	 * @param string $game_slug
	 * @return array<string,mixed>|null Null when the casting, its character, or its session no
	 *                                  longer resolves.
	 */
	public static function for_casting( int $casting_id, string $game_slug ): ?array {
		$game = Game::find_by_slug( $game_slug );
		if ( $game === null ) {
			return null;
		}

		$casting = Npc_Casting::find( $casting_id );
		if ( $casting === null || (int) $casting->game_id !== (int) $game->id ) {
			return null;
		}

		$session = Game_Session::find( (int) $casting->session_id );
		if ( $session === null ) {
			return null;
		}

		$character = Character::find( (int) $casting->character_id );
		if ( $character === null ) {
			return null;
		}

		// Always the cast player's own view of the brief text too.
		St_Visibility::filter_casting( $casting, $game, false );

		// sheet_data is filtered directly, so npc-roleplaying-notes can be kept.
		$resolved = Creature_Stack::resolve( $character->stack_slug, $game->slug );
		if ( $resolved === null ) {
			return null;
		}
		$blocks = $resolved['blocks'];

		$template_type = self::npc_template_type( $character ) ?? 'npc_full';
		$template      = Template::resolve( $character->stack_slug, $template_type, (int) $game->id );
		$layout        = $template->layout ?? ( Layout_Generator::generate_for_stack( $character->stack_slug ) ?? [ 'sections' => [] ] );
		$layout        = St_Visibility::filter_layout( $layout, false, $game->slug, null, [ 'npc-roleplaying-notes' ] );

		$sheet_data = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$sheet_data = St_Visibility::filter_sheet_data_blocks(
			$sheet_data,
			Schema_Block::storyteller_only_slugs( $game->slug ),
			[ 'npc-roleplaying-notes' ]
		);

		$display_name = ( $character->public_name ?? '' ) !== '' ? (string) $character->public_name : $character->name;

		return [
			'title'  => sprintf( '%s - Casting Brief', $display_name ),
			'header' => [
				[ 'Character', $character->name ],
				[ 'Also known as', $display_name !== $character->name ? $display_name : '—' ],
				[ 'Session', (string) $session->game_date ],
				[ 'Time', (string) ( $session->start_time ?? '—' ) ],
				[ 'Place', (string) ( $session->place ?? '—' ) ],
			],
			'portrait_path'    => null,
			'style'            => [],
			'sections'         => self::build_sections( $layout['sections'] ?? [], $blocks, $sheet_data, [] ),
			'prose'            => ! empty( $casting->brief ) ? [ [ 'Brief for this game', (string) $casting->brief ] ] : [],
			'xp_history'       => [],
			'provenance_lines' => [
				(string) $character->uuid,
				sprintf( '%s · %s · Beyond Elysium %s', $game->slug, current_time( 'Y-m-d' ), BE_VERSION ),
			],
		];
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

		$template_type = self::npc_template_type( $character ) ?? 'sheet_full';
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
	 * Walks a layout's sections in flow order, formatting each one through the `Display\*` twin matching its block's
	 * `section_type`.
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
					$entry['groups'] = self::trait_list_groups( $section_data, $definition, $section, $options );
					// A non-atomic section whose held entries all carry a numeric count shows its total after the title.
					if ( empty( $definition->atomic ) ) {
						$total = Trait_Grouping::section_total( Trait_Grouping::to_traits( $section_data ) );
						if ( $total !== null ) {
							$entry['title'] .= " \u{00B7} {$total}";
						}
					}
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
	 * Groups a trait_list section's held entries for the document.
	 *
	 * @param mixed                $section_data Raw `sheet_data[block_slug]` value.
	 * @param array<string,mixed>  $section      Raw layout section (for its own `display` override).
	 * @param array<string,mixed> $options Document-level options (`show_cost`).
	 * @return array<int,array{label:?string,rows:array<int,string>}>
	 */
	private static function trait_list_groups( mixed $section_data, object $definition, array $section, array $options = [] ): array {
		$traits = Trait_Grouping::to_traits( $section_data );
		// A count_is_cost block's stored total is a flat XP cost, labelled as a price; `show_cost` only chooses whether it appears.
		$mode = Trait_Grouping::resolve_mode(
			$definition,
			$section['display'] ?? null,
			array_key_exists( 'show_cost', $options ) ? (bool) $options['show_cost'] : null
		);

		// A player_order block renders in stored array order, with no alphabetizing or grouping.
		$catalog_items = $definition->items ?? [];

		if ( ! empty( $definition->player_order ) ) {
			return [ [ 'label' => null, 'rows' => self::render_traits( $traits, $mode, $catalog_items ) ] ];
		}

		$nested = Trait_Grouping::group_traits_by_field( $traits, $definition );

		$groups = [];

		if ( $nested !== null ) {
			foreach ( $nested as $group ) {
				foreach ( $group['subgroups'] as $subgroup ) {
					$label = $subgroup['subgroup'] !== null
						? $group['group'] . ' — ' . $subgroup['subgroup']
						: $group['group'];
					$items   = Trait_Grouping::sort_if_alphabetized( $subgroup['items'], $definition->alphabetize ?? null );
					$groups[] = [ 'label' => $label, 'rows' => self::render_traits( $items, $mode, $catalog_items ) ];
				}
			}
			return $groups;
		}

		foreach ( Trait_Grouping::group_by_category( $traits, $definition ) as $group ) {
			$items    = Trait_Grouping::sort_if_alphabetized( $group['traits'], $definition->alphabetize ?? null );
			$groups[] = [ 'label' => $group['label'], 'rows' => self::render_traits( $items, $mode, $catalog_items ) ];
		}

		return $groups;
	}

	/**
	 * `$traits` is `to_traits()`'s `{name,total,note}` output, having passed through `sort_if_alphabetized()`.
	 *
	 * @param array<int,array<string,mixed>> $traits
	 * @param array<int,object>              $catalog_items
	 * @return string[]
	 */
	private static function render_traits( array $traits, string $mode, array $catalog_items = [] ): array {
		if ( self::use_portuguese() ) {
			$by_name = [];
			foreach ( $catalog_items as $item ) {
				if ( isset( $item->name ) ) {
					$by_name[ $item->name ] = $item;
				}
			}
			$traits = array_map(
				static function ( $trait ) use ( $by_name ) {
					$name_pt = $by_name[ $trait['name'] ]->name_pt ?? null;
					if ( ! empty( $name_pt ) ) {
						$trait['name'] = $name_pt;
					}
					return $trait;
				},
				$traits
			);
		}

		return array_values( array_map(
			static fn( $trait ) => Trait_Display::display_trait( (object) $trait, $mode ),
			$traits
		) );
	}

	/**
	 * Whether the site's own locale (one install, one language - never a per-user preference) is Portuguese (Brazil), the
	 * server-side twin of `src/lib/localizeName.ts`'s `isPortugueseLocale()`.
	 */
	private static function use_portuguese(): bool {
		return get_locale() === 'pt_BR';
	}

	/**
	 * @param mixed                $section_data Raw `sheet_data[block_slug]` value - a held-power list.
	 * @param array<string,mixed>  $options
	 * @return string[]
	 */
	private static function tiered_power_rows( mixed $section_data, object $definition, array $options ): array {
		$held_list = is_array( $section_data ) ? $section_data : [];
		$mode      = ! empty( $options['full_power_names'] ) ? 'named' : 'numeric';

		$use_pt = self::use_portuguese();

		$rows = [];
		foreach ( $held_list as $held ) {
			$held  = is_array( $held ) ? $held : [];
			$label = $mode === 'numeric'
				? Power_Display::numeric_label( $definition, $held, $use_pt )
				: implode( ', ', Power_Display::named_mode_rows( $definition, $held, $use_pt ) );
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
			// A multiselect holds a list: its choices, joined as the on-screen sheet joins them.
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			// A textarea field is rich text; its markup is flattened to plain text, with block ends as line breaks.
			if ( ( $field->field_type ?? '' ) === 'textarea' && is_string( $value ) && $value !== '' ) {
				$broken = preg_replace( '#<br\s*/?>|</(?:p|div|li|h[1-6]|tr)>#i', "\n", $value );
				$value  = trim( html_entity_decode(
					wp_strip_all_tags( $broken ?? $value ),
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				) );
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
	 * Only `status = 'approved'` changes count as real history on a signed document.
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
	 * Resolves a WordPress attachment id to its real local filesystem path.
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
