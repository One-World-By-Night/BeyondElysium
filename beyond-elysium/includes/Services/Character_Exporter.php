<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * Exports one Beyond Elysium character to a Grapevine `.gex` XML document
 * (GX-3). Walks the character's resolved schema blocks and routes each one
 * to the Grapevine list or scalar field it corresponds to, per
 * `gex-trait-list-map.php` (reused via `Trait_Mapper::classify_list()`,
 * never reimplemented) and `gex-identity-map.php` (inverted mechanically).
 * The container SHAPE - which scalars, trait lists, and tail fields a race
 * has, in what order - comes from `gv-exchange-shape.php` (GX-1); this class
 * never hardcodes a race's own field list.
 *
 * `bete` is a real, seeded creature stack with no `gex-identity-map.php`
 * entry of its own (it reuses werewolf/fera's schema blocks, per
 * `gex-trait-list-map.php`); its identity/resource export falls back to
 * `fera`'s map, since that is the stack it actually shares blocks with.
 * `hunter`/`various` have no BE creature stack at all (confirmed absent from
 * both `gex-identity-map.php` and `Creature_Stack`), so a character of
 * either would export identity/resources empty - not reachable in practice,
 * since no character of either stack can currently exist in this codebase.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-3, §4
 */
class Character_Exporter {

	/** `gex-identity-map.php` has no entry for `bete`; it reuses fera's blocks (gex-trait-list-map.php). */
	private const STACK_FALLBACK = [ 'bete' => 'fera' ];

	/**
	 * Exports one character. Returns the XML document text plus any
	 * degradation warnings (a trait list or field with nowhere real to
	 * come from) and every ASCII transliteration the writer had to make.
	 *
	 * When `verify` is requested, the canonical document (the same one this
	 * method would otherwise return, with the `id` field empty) is built
	 * once to compute its hash - a verification URL can't attest to a
	 * document that already contains that same URL - then a fresh
	 * `Attestation::issue()` mints a code, and the document is built again
	 * with that code's URL in the `id` field (§6.4: GV's own `ID` field,
	 * unused by BE's own importer, confirmed present on all 12 character
	 * classes) and, for XML, the optional `<verification>` child (§6.4:
	 * confirmed safe by the reference reader's missing `Case Else`).
	 *
	 * @param int   $character_id
	 * @param array $options `hide_st` (bool, default false), `as_transfer` (bool, default false, reserved for GX-8), `verify` (bool, default false).
	 * @return array{xml:string,warnings:array<int,string>,transliterations:array<int,string>}
	 * @throws \RuntimeException If the character does not exist.
	 */
	public static function export( int $character_id, array $options = [] ): array {
		$character = Character::find( $character_id );
		if ( ! $character ) {
			throw new \RuntimeException( "Character #{$character_id} not found." );
		}

		$hide_st = ! empty( $options['hide_st'] );

		if ( empty( $options['verify'] ) ) {
			return self::build( $character, $hide_st, null );
		}

		// Hashed with hide_st forced false regardless of the caller's own choice: `sheet_hash`
		// answers "has the underlying character changed" (currency - §6.5), a question the
		// ST-redaction toggle is orthogonal to. `Verify_Controller::still_matches()` always
		// re-exports with default options (hide_st false) to compare - if this hashed the
		// caller's real hide_st instead, every player-initiated verified export (hide_st true,
		// since a non-manager exporting their own sheet is the primary real-world case here)
		// would permanently fail to match itself, with nothing having actually changed.
		$canonical  = self::build( $character, false, null );
		$sheet_hash = hash( 'sha256', $canonical['xml'] );
		$attestation = Attestation::issue( $character, 'gex', $sheet_hash );
		// VerifyCharacter.tsx reads ?code= off the URL (same convention as every other
		// widget's own URL param, e.g. CharacterSheet.tsx's ?character_id=) - never a path
		// segment, since be-verify is a plain provisioned WP page, not a rewrite rule.
		$url = home_url( '/be-verify/?code=' . rawurlencode( $attestation->short_code ) );

		return self::build( $character, $hide_st, $url );
	}

	/**
	 * @param object      $character
	 * @param bool        $hide_st
	 * @param string|null $verification_url When given, written into the `id` scalar and an
	 *                                       optional `<verification>` child; when null, `id` is empty.
	 * @return array{xml:string,warnings:array<int,string>,transliterations:array<int,string>}
	 */
	private static function build( object $character, bool $hide_st, ?string $verification_url ): array {
		$game        = Game::find_by_slug( $character->owner_slug );
		$game_slug   = $game->slug ?? $character->owner_slug;
		$stack_slug  = (string) $character->stack_slug;
		$map_slug    = self::STACK_FALLBACK[ $stack_slug ] ?? $stack_slug;
		$race        = $stack_slug; // Every real BE creature stack slug matches a RACE_TYPE_MAP value exactly.
		$sheet       = is_array( $character->sheet_data ) ? $character->sheet_data : (array) ( $character->sheet_data ?? [] );
		$warnings    = [];

		$shape = GEX_Parser::shape( $race );

		$writer = new GEX_Xml_Writer();
		$writer->begin_tag( 'grapevine' )->write_attribute( 'version', '3.0' );
		$writer->begin_tag( $shape['xml_tag'] );

		$raw = self::build_raw_scalars( $character, $map_slug, $game_slug, $sheet );
		if ( $verification_url !== null ) {
			$raw['id'] = $verification_url;
		}

		// physical_max/social_max/mental_max are derived, never stored (field-map.php) -
		// computed the same way the reader backfills them, from live Physical/Social/Mental
		// trait counts, symmetric with GEX_Parser::backfill_pool_max() by construction.
		$physical = self::list_or_empty( $sheet, self::block_for( $stack_slug, 'Physical' ), 'Physical' );
		$social   = self::list_or_empty( $sheet, self::block_for( $stack_slug, 'Social' ), 'Social' );
		$mental   = self::list_or_empty( $sheet, self::block_for( $stack_slug, 'Mental' ), 'Mental' );
		[ $raw['physical_max'], $raw['social_max'], $raw['mental_max'] ] =
			GEX_Parser::backfill_pool_max( 0, $physical, $social, $mental );

		self::write_scalars( $writer, $shape['scalars'], $raw );

		self::write_experience( $writer, $character );

		foreach ( $shape['trait_lists'] as $spec ) {
			self::write_trait_list( $writer, $spec, $map_slug, $game_slug, $sheet, $character, $hide_st, $game, $warnings );
		}

		if ( $shape['boons'] ) {
			self::write_boons( $writer, $character );
		}

		foreach ( $shape['tail'] as $row ) {
			$text = (string) ( $character->{ $row['key'] } ?? '' );
			if ( $hide_st && $text !== '' ) {
				$text = St_Filter::strip_for_game( $text, $game->settings ?? null );
			}
			$writer->write_cdata_tag( $row['xml_cdata'], $text );
		}

		if ( $verification_url !== null ) {
			$writer->begin_tag( 'verification' )
				->write_attribute( 'url', $verification_url )
				->write_attribute( 'issued', gmdate( 'n/j/Y g:i:s A' ) )
				->end_tag();
		}

		$writer->end_tag(); // race tag
		$writer->end_tag(); // grapevine

		return [
			'xml'               => $writer->render(),
			'warnings'          => $warnings,
			'transliterations'  => $writer->transliterations(),
		];
	}

	/**
	 * Writes every scalar in shape order, resolving each row's omit rule
	 * (`xml_omit` against a literal, or `xml_omit_if` against another raw
	 * field's own already-resolved value) before handing it to the writer.
	 *
	 * @param GEX_Xml_Writer            $writer
	 * @param array<int,array<string,mixed>> $scalars
	 * @param array<string,mixed>       $raw
	 */
	private static function write_scalars( GEX_Xml_Writer $writer, array $scalars, array $raw ): void {
		foreach ( $scalars as $scalar ) {
			$value = $raw[ $scalar['key'] ] ?? self::default_for_type( $scalar['type'] );

			if ( isset( $scalar['xml_enum'] ) ) {
				$value = $scalar['xml_enum'][ $value ] ?? $scalar['xml_enum'][0];
			}

			$omit = null;
			if ( array_key_exists( 'xml_omit', $scalar ) ) {
				$omit = $scalar['xml_omit'];
			} elseif ( isset( $scalar['xml_omit_if'] ) ) {
				$omit = $raw[ $scalar['xml_omit_if'] ] ?? null;
			}

			$writer->write_attribute( $scalar['xml'], $value, $omit );
		}
	}

	/**
	 * @param string $type
	 * @return string|int|float|bool
	 */
	private static function default_for_type( string $type ) {
		return match ( $type ) {
			'int16', 'int32' => 0,
			'single'         => 0.0,
			'bool'           => false,
			default          => '',
		};
	}

	/**
	 * Builds the raw GV-shaped scalar map for one character: universal
	 * fields every race shares (read straight off the `characters` row),
	 * plus this stack's own identity/resource fields inverted mechanically
	 * from `gex-identity-map.php`. A stack absent from that map (no BE
	 * creature stack exists for it) simply contributes nothing further -
	 * the universal fields still export.
	 *
	 * @param object $character
	 * @param string $map_slug
	 * @param string $game_slug
	 * @param array  $sheet
	 * @return array<string,mixed>
	 */
	private static function build_raw_scalars( object $character, string $map_slug, string $game_slug, array $sheet ): array {
		$raw = [
			'name'          => (string) $character->name,
			'player'        => (string) ( $character->player_name ?? '' ),
			'status'        => (string) $character->status,
			'id'            => '',
			'narrator'      => (string) ( $character->narrator ?? '' ),
			'is_npc'        => (bool) $character->is_npc,
			'start_date'    => self::format_date( $character->start_date ?? null ),
			'last_modified' => self::format_date( $character->updated_at ?? null ),
		];

		$identity_map = require __DIR__ . '/gex-identity-map.php';
		$stack_map    = $identity_map[ $map_slug ] ?? null;
		if ( $stack_map === null ) {
			return $raw; // No BE creature stack for this race (hunter/various) - universal fields only.
		}

		if ( isset( $stack_map['identity'] ) ) {
			$block  = Schema_Block::find_for_game( $stack_map['identity']['block'], $game_slug );
			$fields = self::field_names( $block, 'fields' );
			$held   = (array) ( $sheet[ $stack_map['identity']['block'] ] ?? [] );
			foreach ( $stack_map['identity']['fields'] as $be_label => $raw_key ) {
				if ( ! in_array( $be_label, $fields, true ) && $fields !== [] ) {
					continue; // A chronicle fork removed this field - nothing to export.
				}
				$raw[ $raw_key ] = $held[ $be_label ] ?? '';
			}
		}

		foreach ( $stack_map['resources'] ?? [] as $resource_block ) {
			$held = (array) ( $sheet[ $resource_block['block'] ] ?? [] );
			foreach ( $resource_block['fields'] as $be_label => [ $perm_key, $temp_key ] ) {
				$value          = $held[ $be_label ] ?? [ 'permanent' => 0, 'temporary' => 0 ];
				$raw[ $perm_key ] = (int) ( $value['permanent'] ?? 0 );
				$raw[ $temp_key ] = (int) ( $value['temporary'] ?? $value['permanent'] ?? 0 );
			}
		}

		return $raw;
	}

	/**
	 * Enumerates the field/pool names a schema block's own definition
	 * declares, so a chronicle-forked block that removed a field exports
	 * nothing for it rather than a stale empty value. Returns an empty
	 * array (meaning "no restriction, export everything the identity map
	 * names") when the block itself can't be resolved, rather than
	 * silently dropping every field.
	 *
	 * @param object|null $block
	 * @param string      $list_key 'fields' for identity_field, 'pools' for resource_pool.
	 * @return array<int,string>
	 */
	private static function field_names( $block, string $list_key ): array {
		if ( ! $block || ! isset( $block->definition->{ $list_key } ) ) {
			return [];
		}
		return array_map( static fn( $f ) => $f->name, (array) $block->definition->{ $list_key } );
	}

	/**
	 * @param string|null $date A `Y-m-d` or `Y-m-d H:i:s` MySQL value, or null.
	 * @return string GV's own `n/j/Y g:i:s A` shape, or '' when there is nothing to format.
	 */
	private static function format_date( ?string $date ): string {
		if ( ! $date || $date === '0000-00-00' ) {
			return '';
		}
		$timestamp = strtotime( $date );
		return $timestamp === false ? '' : gmdate( 'n/j/Y g:i:s A', $timestamp );
	}

	/**
	 * Writes the `<experience>` block: current totals from the character's
	 * own denormalized columns (always authoritative, per Decision 014),
	 * then a real history reconstructed from `be_character_changes` -
	 * `ExperienceHistoryNode.PropogateChange()`'s own real propagation
	 * rules (`GV301Source/Code/ExperienceHistoryNode.cls:45-67`), not the
	 * export design doc's own uncited aside. That real source shows
	 * `ecEarned`/`ecDeducted` are the only two enum values that adjust BOTH
	 * `Earned` and `Unspent` by one relative delta - exactly Decision 014's
	 * "xp_adjust ... both adjusted by amount (can be negative)" - so
	 * `xp_adjust` maps here to `ecEarned`/`ecDeducted` by sign, not the
	 * `ecSetEarned`/`ecSetUnspent` the design doc informally suggested
	 * (those two are absolute single-pool sets and cannot reproduce a
	 * same-amount adjustment to both pools at once).
	 *
	 * @param GEX_Xml_Writer $writer
	 * @param object         $character
	 */
	private static function write_experience( GEX_Xml_Writer $writer, object $character ): void {
		$writer->begin_tag( 'experience' )
			->write_attribute( 'unspent', (int) $character->xp_unspent )
			->write_attribute( 'earned', (int) $character->xp_earned );

		global $wpdb;
		$table = \BeyondElysium\Database\Manager::table( 'character_changes' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT change_type, change_data, xp_cost, reason, notes, submitted_at FROM {$table} " .
				'WHERE character_id = %d AND status = %s ORDER BY submitted_at ASC, id ASC',
				$character->id,
				'approved'
			)
		);

		$earned = 0.0;
		$unspent = 0.0;

		foreach ( $rows as $row ) {
			$data   = json_decode( (string) $row->change_data, true ) ?: [];
			$amount = (float) ( $data['amount'] ?? 0 );
			$cost   = (float) $row->xp_cost;
			$reason = (string) ( $data['reason'] ?? $row->reason ?? $row->notes ?? '' );

			if ( $row->change_type === 'xp_earn' ) {
				$change = $amount;
				$earned += $change;
				$unspent += $change;
			} elseif ( $row->change_type === 'xp_adjust' ) {
				$change = $amount;
				$earned += $change;
				$unspent += $change;
			} elseif ( $cost > 0 ) {
				$change = $cost;
				$unspent -= $change;
			} elseif ( $row->change_type === 'import_note' ) {
				$change = 0.0;
			} else {
				continue; // A zero-cost, non-XP sheet change - no XP event to record.
			}

			$writer->begin_tag( 'entry' )
				->write_attribute( 'date', self::format_date( $row->submitted_at ) )
				->write_attribute( 'change', $change )
				->write_attribute( 'type', self::propagate_type( $row->change_type, $cost, $amount ) )
				->write_attribute( 'reason', $reason )
				->end_tag();
		}

		$writer->end_tag();
	}

	/**
	 * @param string $change_type
	 * @param float  $cost
	 * @param float  $amount
	 * @return int ExperienceChangeType (GV-SOURCEMAP.md: ecEarned=0, ecDeducted=1, ecSetEarned=2, ecSpent=3, ecUnspent=4, ecSetUnspent=5, ecComment=6).
	 */
	private static function propagate_type( string $change_type, float $cost, float $amount ): int {
		if ( $change_type === 'xp_earn' ) {
			return 0; // ecEarned
		}
		if ( $change_type === 'xp_adjust' ) {
			return $amount >= 0 ? 0 : 1; // ecEarned / ecDeducted, by sign - see write_experience()'s own doc comment
		}
		if ( $cost > 0 ) {
			return 3; // ecSpent
		}
		return 6; // ecComment
	}

	/**
	 * Routes one GV trait list to its real export source, via
	 * `Trait_Mapper::classify_list()` - the exact same shared+per-race
	 * outcome table the importer already uses, never reimplemented. The
	 * `<traitlist>` tag's own `abc`/`atomic`/`negative`/`display` attributes
	 * come from `$spec` - GX-1's shape table - never a hardcoded guess, since
	 * a real reader (including our own) uses `display` to decide how to
	 * render the list at all.
	 *
	 * @param GEX_Xml_Writer $writer
	 * @param array          $spec `gv-exchange-shape.php`'s own trait_lists row: name/abc/neg/atomic/display.
	 * @param string         $map_slug
	 * @param string         $game_slug
	 * @param array          $sheet
	 * @param object         $character
	 * @param bool           $hide_st
	 * @param object|null    $game
	 * @param array          $warnings
	 */
	private static function write_trait_list(
		GEX_Xml_Writer $writer, array $spec, string $map_slug,
		string $game_slug, array $sheet, object $character, bool $hide_st, $game, array &$warnings
	): void {
		$gv_list_name   = $spec['name'];
		$classification = Trait_Mapper::classify_list( $map_slug, $gv_list_name );
		$outcome        = $classification['outcome'];
		$settings       = $game->settings ?? null;

		$writer->begin_tag( 'traitlist' )
			->write_attribute( 'name', $gv_list_name )
			->write_attribute( 'abc', (bool) $spec['abc'] )
			->write_attribute( 'atomic', (bool) $spec['atomic'] )
			->write_attribute( 'negative', (bool) $spec['neg'], false )
			->write_attribute( 'display', (int) $spec['display'] );

		switch ( $outcome ) {
			case 'sheet_block':
				self::write_sheet_block_traits( $writer, $classification, $gv_list_name, $sheet, $game_slug, $hide_st, $settings );
				break;

			case 'world_object':
				self::write_world_object_traits( $writer, $character, $classification['object_type'], $hide_st, $settings );
				break;

			case 'preserve_as_note':
			case 'needs_design':
				if ( ! self::write_preserved_traits( $writer, $character, $gv_list_name, $hide_st, $settings ) ) {
					$warnings[] = "\"{$gv_list_name}\" has no live BE mapping and no import history to recover it from - exported empty.";
				}
				break;

			default:
				$warnings[] = "\"{$gv_list_name}\" ({$outcome}) is not yet exportable - exported empty.";
		}

		$writer->end_tag();
	}

	/**
	 * @param string $note
	 * @param bool   $hide_st
	 * @param mixed  $settings
	 * @return string
	 */
	private static function filter_note( string $note, bool $hide_st, $settings ): string {
		return $hide_st && $note !== '' ? St_Filter::strip_for_game( $note, $settings ) : $note;
	}

	/**
	 * @param GEX_Xml_Writer $writer
	 * @param array          $classification
	 * @param string         $gv_list_name
	 * @param array          $sheet
	 * @param string         $game_slug
	 * @param bool           $hide_st
	 * @param mixed          $settings
	 */
	private static function write_sheet_block_traits( GEX_Xml_Writer $writer, array $classification, string $gv_list_name, array $sheet, string $game_slug, bool $hide_st, $settings ): void {
		$block_slug = $classification['block_slug'];
		$held       = array_values( (array) ( $sheet[ $block_slug ] ?? [] ) );

		// Influences/Backgrounds split the same one BE block by each held item's
		// own catalog source (Query_Engine's own stack_relative_list mechanism,
		// field-map.php's filter_source - reused directly, never reimplemented).
		if ( in_array( $gv_list_name, [ 'Influences', 'Backgrounds' ], true ) ) {
			$sources = Backgrounds_Catalog::sources_for( $block_slug, $game_slug );
			$held    = array_values( array_filter( $held, static function ( $item ) use ( $sources, $gv_list_name ) {
				$source = $sources[ $item['name'] ?? '' ] ?? '';
				return $gv_list_name === 'Influences' ? $source === 'Influences' : $source !== 'Influences';
			} ) );
		}

		// Vampire's Disciplines/Rituals fold their blood-magic and combo sibling
		// blocks back in - the only two lists gex-trait-list-map.php marks with
		// blood_magic_block_slug/combo_block_slug.
		if ( isset( $classification['blood_magic_block_slug'] ) ) {
			foreach ( array_values( (array) ( $sheet[ $classification['blood_magic_block_slug'] ] ?? [] ) ) as $pick ) {
				if ( isset( $pick['tradition'], $pick['level'] ) ) {
					$held[] = [ 'name' => "{$pick['tradition']}: {$pick['name']}", 'count' => $pick['level'] ];
				}
			}
		}
		if ( isset( $classification['combo_block_slug'] ) ) {
			foreach ( array_values( (array) ( $sheet[ $classification['combo_block_slug'] ] ?? [] ) ) as $pick ) {
				$held[] = $pick;
			}
		}

		foreach ( $held as $entry ) {
			$writer->begin_tag( 'trait' )
				->write_attribute( 'name', (string) ( $entry['name'] ?? '' ) )
				->write_attribute( 'val', (string) ( $entry['count'] ?? $entry['level'] ?? 1 ), '1' )
				->write_attribute( 'note', self::filter_note( (string) ( $entry['note'] ?? '' ), $hide_st, $settings ), '' )
				->end_tag();
		}
	}

	/**
	 * Equipment/Locations: `be_connections` from this character to a
	 * `world_object` of the given type (gex-trait-list-map.php's own
	 * `world_object` outcome), one trait per connected item. A connected
	 * object carries no dot rating of its own - GV's own Equipment/Locations
	 * lists are possession lists, not tiered - so every entry writes `val="1"`
	 * (never omitted; a writer must not depend on a consumer implementing
	 * the omit rule for a list that has no real dot concept at all).
	 *
	 * @param GEX_Xml_Writer $writer
	 * @param object         $character
	 * @param string         $object_type
	 * @param bool           $hide_st
	 * @param mixed          $settings
	 */
	private static function write_world_object_traits( GEX_Xml_Writer $writer, object $character, string $object_type, bool $hide_st, $settings ): void {
		foreach ( Connection::for_source( 'character', (int) $character->id ) as $connection ) {
			if ( $connection->target_type !== 'world_object' ) {
				continue;
			}
			$object = World_Object::find( (int) $connection->target_id );
			if ( ! $object || $object->object_type !== $object_type ) {
				continue;
			}
			$writer->begin_tag( 'trait' )
				->write_attribute( 'name', (string) $object->name )
				->write_attribute( 'val', '1' )
				->write_attribute( 'note', self::filter_note( (string) ( $connection->notes ?? '' ), $hide_st, $settings ), '' )
				->end_tag();
		}
	}

	/**
	 * Backfills a `preserve_as_note`/`needs_design` list from the character's
	 * own most recent `import_note` change, if one exists - the only place
	 * this data has ever survived, since these lists have no live BE block
	 * (GX-3's own design: `Import_Controller::import_character()` already
	 * preserves exactly these lists into `change_data.raw_record.trait_lists`
	 * on import). That array is a plain sequential list, not keyed by GV
	 * list name (confirmed against the real write path), so this matches by
	 * each element's own `name` field.
	 *
	 * @param GEX_Xml_Writer $writer
	 * @param object         $character
	 * @param string         $gv_list_name
	 * @param bool           $hide_st
	 * @param mixed          $settings
	 * @return bool Whether a real list was found and written (false means the caller should warn).
	 */
	private static function write_preserved_traits( GEX_Xml_Writer $writer, object $character, string $gv_list_name, bool $hide_st, $settings ): bool {
		global $wpdb;
		$table = \BeyondElysium\Database\Manager::table( 'character_changes' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT change_data FROM {$table} WHERE character_id = %d AND change_type = %s ORDER BY submitted_at DESC, id DESC LIMIT 1",
				$character->id,
				'import_note'
			)
		);
		if ( ! $row ) {
			return false;
		}

		$data       = json_decode( (string) $row->change_data, true ) ?: [];
		$trait_lists = $data['raw_record']['trait_lists'] ?? [];
		$found      = null;
		foreach ( $trait_lists as $list ) {
			if ( ( $list['name'] ?? null ) === $gv_list_name ) {
				$found = $list;
				break;
			}
		}
		if ( $found === null ) {
			return false;
		}

		foreach ( $found['traits'] ?? [] as $trait ) {
			$writer->begin_tag( 'trait' )
				->write_attribute( 'name', (string) ( $trait['name'] ?? '' ) )
				->write_attribute( 'val', (string) ( $trait['total'] ?? '1' ), '1' )
				->write_attribute( 'note', self::filter_note( (string) ( $trait['note'] ?? '' ), $hide_st, $settings ), '' )
				->end_tag();
		}
		return true;
	}

	/**
	 * Writes this vampire's boon list. A boon is its own `world_object` row
	 * connected to both parties (`Boons_Controller`'s own model, reused
	 * directly): `owed_by` names the debtor, `owed_to` the creditor.
	 * `BoonClass`'s own field semantics - "whether it is owed or held" - read
	 * as `is_owed=true` meaning this character is the creditor (owed TO
	 * them); `is_owed=false` means this character is the debtor (they HOLD
	 * the debt). BE's boon model has no free-text `BoonType`/category field
	 * (only a numeric `boon_level`), so `boon_type` is rendered as a plain
	 * "Level {N}" label - a real, honest gap between the two shapes, not a
	 * hidden loss, since `terms` (BE's own closest analog to a description)
	 * is carried through unchanged.
	 *
	 * @param GEX_Xml_Writer $writer
	 * @param object         $character
	 */
	private static function write_boons( GEX_Xml_Writer $writer, object $character ): void {
		foreach ( Connection::for_target( 'character', (int) $character->id ) as $connection ) {
			if ( $connection->source_type !== 'world_object' || ! in_array( $connection->label, [ 'owed_by', 'owed_to' ], true ) ) {
				continue;
			}
			$boon = World_Object::find( (int) $connection->source_id );
			if ( ! $boon || $boon->object_type !== 'boon' ) {
				continue;
			}

			$is_owed  = $connection->label === 'owed_to'; // this character is the creditor
			$other_label = $is_owed ? 'owed_by' : 'owed_to';
			$other    = null;
			foreach ( Connection::for_source( 'world_object', (int) $boon->id ) as $c ) {
				if ( $c->label === $other_label && $c->target_type === 'character' ) {
					$other_character = Character::find( (int) $c->target_id );
					$other = $other_character->name ?? '';
					break;
				}
			}

			$properties = is_array( $boon->properties ) ? $boon->properties : (array) ( $boon->properties ?? [] );

			$writer->begin_tag( 'boon' )
				->write_attribute( 'type', 'Level ' . (string) ( $properties['boon_level'] ?? '?' ) )
				->write_attribute( 'partner', (string) ( $other ?? '' ) )
				->write_attribute( 'owed', $is_owed )
				->write_attribute( 'date', self::format_date( $properties['boon_date'] ?? null ) );
			$writer->write_cdata_tag( 'description', (string) ( $properties['terms'] ?? '' ) );
			$writer->end_tag();
		}
	}

	/**
	 * @param string $stack_slug
	 * @param string $gv_name
	 * @return string
	 */
	private static function block_for( string $stack_slug, string $gv_name ): string {
		$classification = Trait_Mapper::classify_list( $stack_slug, $gv_name );
		return $classification['block_slug'] ?? '';
	}

	/**
	 * @param array  $sheet
	 * @param string $block_slug
	 * @param string $name
	 * @return array{name:string,alphabetized:bool,atomic:bool,negative:bool,display:int,traits:array<int,array>}
	 */
	private static function list_or_empty( array $sheet, string $block_slug, string $name ): array {
		$held = array_values( (array) ( $sheet[ $block_slug ] ?? [] ) );
		return [
			'name' => $name, 'alphabetized' => false, 'atomic' => false, 'negative' => false, 'display' => 1,
			'traits' => array_map( static fn( $e ) => [ 'name' => $e['name'] ?? '', 'total' => (string) ( $e['count'] ?? 0 ), 'note' => '' ], $held ),
		];
	}
}
