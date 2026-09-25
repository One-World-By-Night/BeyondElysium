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
use BeyondElysium\Services\Display\Trait_Display;
use BeyondElysium\Services\Display\Trait_Grouping;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a character down to a plain, presentation-neutral array: the same walk the on-screen sheet
 * (`CharacterSheet.tsx` + `BlockRenderer.tsx`) does, done once server-side.
 */
class Sheet_Document {

	/**
	 * The displays that print a trait as its name and multiplier beside a row of empty rings.
	 */
	private const RING_MODES = [ 'multiplier', 'multiplier_dot', 'dot', 'dot_separate', 'simple_dots' ];

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

		[ $header_pairs, $sections ] = self::header_and_body( $layout['sections'] ?? [], $blocks, $sheet_data, [], $resolved['stack'] ?? null );

		return [
			'title'  => sprintf( '%s - Casting Brief', $display_name ),
			'header' => array_merge(
				[
					[ 'Character', $character->name ],
					[ 'Also known as', $display_name !== $character->name ? $display_name : '—' ],
					[ 'Session', (string) $session->game_date ],
					[ 'Time', (string) ( $session->start_time ?? '—' ) ],
					[ 'Place', (string) ( $session->place ?? '—' ) ],
				],
				$header_pairs
			),
			'portrait_path'    => null,
			'style'            => [],
			'sections'         => $sections,
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

		[ $header_pairs, $sections ] = self::header_and_body( $layout['sections'] ?? [], $blocks, $sheet_data, $options, $stack );

		return [
			'title'            => $character->name,
			'subtitle'         => $stack->name,
			'header'           => array_merge( self::core_pairs( $character ), $header_pairs ),
			'portrait_path'    => self::attachment_path( $character->image_id ?? null ),
			'style'            => self::build_style( $character_id ),
			'sections'         => $sections,
			'prose'            => self::build_prose( $character, $options ),
			'xp_history'       => ! empty( $options['xp_history'] ) ? self::build_xp_history( $character_id ) : [],
			'provenance_lines' => self::build_provenance( $character, $game ),
		];
	}

	/**
	 * The header's first pairs: when the sheet was printed and last changed, the character's status and player, and their
	 * experience.
	 *
	 * @param object $character
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function core_pairs( object $character ): array {
		$pairs = [ [ 'Printed', current_time( 'Y-m-d' ) ] ];
		if ( ! empty( $character->updated_at ) ) {
			$pairs[] = [ 'Last modified', substr( (string) $character->updated_at, 0, 10 ) ];
		}
		$pairs[] = [ 'Status', ucfirst( (string) $character->status ) ];
		if ( ! empty( $character->player_name ) ) {
			$pairs[] = [ 'Player', (string) $character->player_name ];
		}
		$pairs[] = [ 'XP Earned', (string) $character->xp_earned ];
		$pairs[] = [ 'XP Unspent', (string) $character->xp_unspent ];
		return $pairs;
	}

	/**
	 * Splits a layout into the pairs the header prints and the sections the body prints. Short identity fields and plain
	 * resource pools go to the header; the body shows each attribute block with its negative half and leaves out a
	 * section with nothing in it.
	 *
	 * @param array<int,array<string,mixed>> $layout_sections
	 * @param array<string,object>           $blocks
	 * @param array<string,mixed>            $sheet_data
	 * @param array<string,mixed>            $options
	 * @param object|null                    $stack The character's creature stack, for its attribute pairs.
	 * @return array{0:array<int,array{0:string,1:string}>,1:array<int,array<string,mixed>>}
	 */
	private static function header_and_body( array $layout_sections, array $blocks, array $sheet_data, array $options, ?object $stack ): array {
		$header_sections = [];
		$body_sections   = [];
		foreach ( $layout_sections as $section ) {
			$block = $blocks[ $section['block_slug'] ?? '' ] ?? null;
			if ( $block !== null && self::belongs_in_header( $block ) ) {
				$header_sections[] = $section;
			} else {
				$body_sections[] = $section;
			}
		}

		$pairs = [];
		foreach ( Layout_Flow::sorted_for_flow( $header_sections ) as $section ) {
			$slug  = (string) $section['block_slug'];
			$pairs = array_merge( $pairs, self::header_pairs_for( $blocks[ $slug ], $sheet_data[ $slug ] ?? null, $sheet_data ) );
		}

		$sections = self::build_sections( $body_sections, $blocks, $sheet_data, $options );
		$sections = self::pair_negative_halves( $sections, $stack );
		$sections = array_values( array_filter( $sections, [ self::class, 'has_content' ] ) );

		return [ $pairs, $sections ];
	}

	/**
	 * Whether a block prints in the header: an identity block with no long-text field, or a resource block whose pools
	 * are not named from another field.
	 */
	private static function belongs_in_header( object $block ): bool {
		$definition = is_object( $block->definition ?? null ) ? $block->definition : (object) [];

		if ( $block->section_type === 'identity_field' ) {
			foreach ( (array) ( $definition->fields ?? [] ) as $field ) {
				if ( ( $field->field_type ?? '' ) === 'textarea' ) {
					return false;
				}
			}
			return true;
		}

		if ( $block->section_type === 'resource_pool' ) {
			foreach ( (array) ( $definition->pools ?? [] ) as $pool ) {
				if ( ! empty( $pool->name_lookup ) ) {
					return false;
				}
			}
			return true;
		}

		return false;
	}

	/**
	 * One header block's label and value pairs: each identity field that holds a value, or each pool's permanent rating
	 * with that rating again as its third element, for the writer to draw as rings.
	 *
	 * @param object              $block
	 * @param mixed               $section_data Raw `sheet_data[block_slug]` value.
	 * @param array<string,mixed> $sheet_data   The character's whole sheet_data, for cross-block pool names.
	 * @return array<int,array{0:string,1:string,2?:int}>
	 */
	private static function header_pairs_for( object $block, mixed $section_data, array $sheet_data ): array {
		$definition = is_object( $block->definition ?? null ) ? $block->definition : (object) [];
		$values     = is_array( $section_data ) ? $section_data : [];
		$pairs      = [];

		if ( $block->section_type === 'identity_field' ) {
			foreach ( (array) ( $definition->fields ?? [] ) as $field ) {
				$value = $values[ $field->name ] ?? null;
				if ( is_array( $value ) ) {
					$value = implode( ', ', array_map( 'strval', $value ) );
				}
				if ( $value === null || $value === '' ) {
					continue;
				}
				$pairs[] = [ (string) $field->name, (string) $value ];
			}
			return $pairs;
		}

		foreach ( (array) ( $definition->pools ?? [] ) as $pool ) {
			$value     = $values[ $pool->name ] ?? null;
			$permanent = is_array( $value ) ? (int) ( $value['permanent'] ?? 0 ) : (int) ( $pool->default_start ?? 0 );
			$pairs[]   = [ Cross_Block_Ref::resolve_pool_name( $pool, $sheet_data ), 'x' . $permanent, $permanent ];
		}
		return $pairs;
	}

	/**
	 * Folds each attribute block's negative half into it, under a "Negative" label, titles the pair with the creature
	 * stack's own label for it and marks it for the attribute band.
	 *
	 * @param array<int,array<string,mixed>> $sections
	 * @param object|null                    $stack
	 * @return array<int,array<string,mixed>>
	 */
	private static function pair_negative_halves( array $sections, ?object $stack ): array {
		$pairs = [];
		foreach ( (array) ( $stack->stack_definition->sections ?? [] ) as $stack_section ) {
			$positive = (string) ( $stack_section->block_slug ?? '' );
			$negative = (string) ( $stack_section->negative_block_slug ?? '' );
			if ( $positive !== '' && $negative !== '' ) {
				$pairs[ $positive ] = [ $negative, (string) ( $stack_section->label ?? '' ) ];
			}
		}
		if ( $pairs === [] ) {
			return $sections;
		}

		$index = [];
		foreach ( $sections as $i => $entry ) {
			$index[ $entry['block_slug'] ] = $i;
		}

		foreach ( $pairs as $positive => [ $negative, $label ] ) {
			if ( ! isset( $index[ $positive ] ) ) {
				continue;
			}
			$p                      = $index[ $positive ];
			$sections[ $p ]['band'] = true;

			if ( $label !== '' ) {
				$title = (string) $sections[ $p ]['title'];
				$at    = strrpos( $title, " \u{00B7} " );
				$sections[ $p ]['title'] = $label . ( $at !== false ? substr( $title, $at ) : '' );
			}

			if ( isset( $index[ $negative ] ) ) {
				$n    = $index[ $negative ];
				$rows = [];
				foreach ( (array) ( $sections[ $n ]['groups'] ?? [] ) as $group ) {
					foreach ( (array) ( $group['rows'] ?? [] ) as $row ) {
						$rows[] = $row;
					}
				}
				if ( $rows !== [] ) {
					$sections[ $p ]['groups'][] = [ 'label' => 'Negative', 'rows' => $rows ];
				}
				$sections[ $n ] = null;
			}
		}

		return array_values( array_filter( $sections ) );
	}

	/**
	 * Whether a body section has anything to print: a held entry, or an identity field with a value.
	 *
	 * @param array<string,mixed> $entry
	 */
	private static function has_content( array $entry ): bool {
		if ( isset( $entry['groups'] ) ) {
			foreach ( (array) $entry['groups'] as $group ) {
				if ( ! empty( $group['rows'] ) ) {
					return true;
				}
			}
			return false;
		}

		if ( isset( $entry['rows'] ) ) {
			if ( ( $entry['section_type'] ?? '' ) === 'identity_field' ) {
				foreach ( (array) $entry['rows'] as $row ) {
					if ( ! is_string( $row ) || ! str_ends_with( $row, ': —' ) ) {
						return true;
					}
				}
				return false;
			}
			return $entry['rows'] !== [];
		}

		return true;
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
						$total = Trait_Grouping::section_count( Trait_Grouping::to_traits( $section_data ), ! empty( $definition->count_is_cost ) );
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
	 * @return array<int,array{label:?string,rows:array<int,string|array{text:string,indent:int,circles:int}>}>
	 */
	private static function trait_list_groups( mixed $section_data, object $definition, array $section, array $options = [] ): array {
		// A count_is_cost block's stored total is a flat XP cost, labelled as a price; `show_cost` only chooses whether it appears.
		$mode = Trait_Grouping::resolve_mode(
			$definition,
			$section['display'] ?? null,
			array_key_exists( 'show_cost', $options ) ? (bool) $options['show_cost'] : null
		);
		$traits = $mode === 'points'
			? Trait_Grouping::to_point_traits( $section_data, $definition )
			: self::held_traits( $section_data );

		// A rated line prints its multiplier beside empty rings; a negative block, or one that declares `print_rings` false, prints none.
		$rated = in_array( $mode, self::RING_MODES, true );
		$rings = $rated && empty( $definition->negative ) && ( $definition->print_rings ?? true ) !== false;
		$mode  = $rated ? 'multiplier' : $mode;

		// A player_order block renders in stored array order, with no alphabetizing or grouping.
		$catalog_items = $definition->items ?? [];

		if ( ! empty( $definition->player_order ) ) {
			return [ [ 'label' => null, 'rows' => self::render_traits( $traits, $mode, $catalog_items, $rings ) ] ];
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
					$groups[] = [ 'label' => $label, 'rows' => self::render_traits( $items, $mode, $catalog_items, $rings ) ];
				}
			}
		} else {
			foreach ( Trait_Grouping::group_by_category( $traits, $definition ) as $group ) {
				$items    = Trait_Grouping::sort_if_alphabetized( $group['traits'], $definition->alphabetize ?? null );
				$groups[] = [ 'label' => $group['label'], 'rows' => self::render_traits( $items, $mode, $catalog_items, $rings ) ];
			}
		}

		// A lone group needs no label.
		if ( count( $groups ) === 1 ) {
			$groups[0]['label'] = null;
		}

		return $groups;
	}

	/**
	 * A trait_list block's held entries as `to_traits()` returns them, each carrying its own `specialization` too.
	 *
	 * @param mixed $section_data Raw `sheet_data[block_slug]` value.
	 * @return array<int,array<string,mixed>>
	 */
	private static function held_traits( mixed $section_data ): array {
		$traits = Trait_Grouping::to_traits( $section_data );
		foreach ( $traits as $index => $trait ) {
			$specialization = is_array( $section_data ) && is_array( $section_data[ $index ] ?? null )
				? ( $section_data[ $index ]['specialization'] ?? null )
				: null;
			if ( is_string( $specialization ) && $specialization !== '' ) {
				$traits[ $index ]['specialization'] = $specialization;
			}
		}
		return $traits;
	}

	/**
	 * `$traits` is `held_traits()`'s output, having passed through `sort_if_alphabetized()`. With `$rings`, a row is the
	 * trait's text and its rating, for the writer to draw as that many empty rings; without, it is the text alone.
	 *
	 * @param array<int,array<string,mixed>> $traits
	 * @param array<int,object>              $catalog_items
	 * @return array<int,string|array{text:string,indent:int,circles:int}>
	 */
	private static function render_traits( array $traits, string $mode, array $catalog_items = [], bool $rings = false ): array {
		$by_name = [];
		foreach ( $catalog_items as $item ) {
			if ( isset( $item->name ) ) {
				$by_name[ $item->name ] = $item;
			}
		}
		$use_pt = self::use_portuguese();

		$rows = [];
		foreach ( $traits as $trait ) {
			$item = $by_name[ $trait['name'] ] ?? null;

			if ( $use_pt && ! empty( $item->name_pt ) ) {
				$trait['name'] = $item->name_pt;
			}

			// A trait whose catalog entry lists its specializations (Lore) names the one held as part of its own name.
			$specialization = (string) ( $trait['specialization'] ?? '' );
			if ( $rings && $specialization !== '' && ! empty( $item->specializations ) ) {
				$trait['name'] .= ': ' . $specialization;
				$trait['note']  = ltrim( substr( (string) ( $trait['note'] ?? '' ), strlen( $specialization ) ), ', ' );
			}

			$has_total = Trait_Display::has_total( $trait['total'] ?? null );
			$text      = Trait_Display::display_trait( (object) $trait, $mode === 'multiplier' && ! $has_total ? 'note_only' : $mode );

			$rows[] = $rings
				? [ 'text' => $text, 'indent' => 0, 'circles' => $has_total ? max( 0, Trait_Display::parse_total( $trait['total'] ) ) : 0 ]
				: $text;
		}
		return $rows;
	}

	/**
	 * Whether the site's own locale (one install, one language - never a per-user preference) is Portuguese (Brazil), the
	 * server-side twin of `src/lib/localizeName.ts`'s `isPortugueseLocale()`.
	 */
	private static function use_portuguese(): bool {
		return get_locale() === 'pt_BR';
	}

	/**
	 * One line per held power; with full power names on, the power's own named levels follow it as indented rows.
	 *
	 * @param mixed                $section_data Raw `sheet_data[block_slug]` value - a held-power list.
	 * @param array<string,mixed>  $options
	 * @return array<int,string|array{text:string,indent:int}>
	 */
	private static function tiered_power_rows( mixed $section_data, object $definition, array $options ): array {
		$held_list = is_array( $section_data ) ? $section_data : [];
		$named     = ! empty( $options['full_power_names'] );

		$use_pt = self::use_portuguese();

		$rows = [];
		foreach ( $held_list as $held ) {
			$held   = is_array( $held ) ? $held : [];
			$rows[] = Power_Display::with_tradition( $held, Power_Display::numeric_label( $definition, $held, $use_pt ) );
			if ( ! $named ) {
				continue;
			}
			foreach ( Power_Display::named_mode_rows( $definition, $held, $use_pt ) as $power_name ) {
				$rows[] = [ 'text' => $power_name, 'indent' => 1 ];
			}
		}
		return $rows;
	}

	/**
	 * One ringed line per pool: its name and permanent rating, for the writer to draw as that many empty rings.
	 *
	 * @param mixed                $section_data Raw `sheet_data[block_slug]` value - pool_name => {permanent,temporary}.
	 * @param array<string,mixed>  $sheet_data   The character's whole sheet_data, for cross-block pool-name lookups.
	 * @return array<int,array{text:string,indent:int,circles:int}>
	 */
	private static function resource_pool_rows( mixed $section_data, object $definition, array $sheet_data ): array {
		$pool_values = is_array( $section_data ) ? $section_data : [];

		$rows = [];
		foreach ( ( $definition->pools ?? [] ) as $pool ) {
			$value     = $pool_values[ $pool->name ] ?? null;
			$permanent = is_array( $value ) ? (int) ( $value['permanent'] ?? 0 ) : (int) ( $pool->default_start ?? 0 );

			$rows[] = [
				'text'    => Cross_Block_Ref::resolve_pool_name( $pool, $sheet_data ) . ' x' . $permanent,
				'indent'  => 0,
				'circles' => $permanent,
			];
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
