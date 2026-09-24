<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * Exports one Beyond Elysium character to a Grapevine `.gex` XML document.
 */
class Character_Exporter {

	/**
	 * Exports one character.
	 *
	 * @param int   $character_id
	 * @param array $options `hide_st` (bool, default false), `verify` (bool, default false),
	 *                       `as_transfer` (bool, default false) - a transfer document
	 *                       always carries verification (the receiving chronicle's callback
	 *                       has nothing to check without it) and its `<verification>` element
	 *                       additionally carries `character_uuid`, which `GEX_Xml_Parser`
	 *                       reads back on the receiving side to identify a returning
	 *                       or already-known character with certainty rather than by name.
	 * @return array{xml:string,warnings:array<int,string>,transliterations:array<int,string>,attestation_id?:int,short_code?:string} A verified or transfer export also carries the attestation it issued.
	 * @throws \RuntimeException If the character does not exist.
	 * @throws Not_Exportable_Exception If its creature type has no Grapevine equivalent.
	 */
	public static function export( int $character_id, array $options = [] ): array {
		$character = Character::find( $character_id );
		if ( ! $character ) {
			throw new \RuntimeException( "Character #{$character_id} not found." );
		}

		$hide_st      = ! empty( $options['hide_st'] );
		$as_transfer  = ! empty( $options['as_transfer'] );
		$needs_verify = $as_transfer || ! empty( $options['verify'] );

		if ( ! $needs_verify ) {
			return self::build( $character, $hide_st, null, null );
		}

		// Hashed with hide_st forced false.
		$canonical   = self::build( $character, false, null, null );
		$sheet_hash  = hash( 'sha256', $canonical['xml'] );

		// A redacted export hands over different bytes than the unredacted one sheet_hash covers.
		$document_hash = $hide_st ? hash( 'sha256', self::build( $character, true, null, null )['xml'] ) : null;

		// A transfer's code lasts as long as its offer may wait.
		$attestation = Attestation::issue(
			$character,
			$as_transfer ? 'transfer' : 'gex',
			$sheet_hash,
			$as_transfer ? gmdate( 'Y-m-d H:i:s', time() + Transfer::OFFER_TTL_DAYS * DAY_IN_SECONDS ) : null,
			$document_hash
		);
		$url = home_url( '/be-verify/?code=' . rawurlencode( $attestation->short_code ) );

		$document = self::build( $character, $hide_st, $url, $as_transfer ? $character->uuid : null );

		$document['attestation_id'] = (int) $attestation->id;
		$document['short_code']     = (string) $attestation->short_code;
		return $document;
	}

	/**
	 * Reconstructs the canonical (no verification URL, no `<verification>` element) document from an `as_transfer`
	 * payload someone else sent us.
	 *
	 * @param string $xml
	 * @return string
	 */
	public static function canonicalize_transfer_payload( string $xml ): string {
		$xml = (string) preg_replace( '/ id="[^"]*"/', ' id=""', $xml, 1 );
		return (string) preg_replace( '/^ {4}<verification\b[^>]*\/>\r?\n/m', '', $xml, 1 );
	}

	/**
	 * @param object      $character
	 * @param bool        $hide_st
	 * @param string|null $verification_url When given, written into the `id` scalar and an
	 *                                       optional `<verification>` child; when null, `id` is empty.
	 * @param string|null $transfer_uuid     When given (only for an `as_transfer` export), written
	 *                                       as the `<verification>` element's own `character_uuid`
	 *                                       attribute, alongside `$verification_url`.
	 * @return array{xml:string,warnings:array<int,string>,transliterations:array<int,string>}
	 */
	private static function build( object $character, bool $hide_st, ?string $verification_url, ?string $transfer_uuid ): array {
		$game        = Game::find_by_slug( $character->owner_slug );
		if ( $hide_st ) {
			// A copy for a non-Storyteller carries no Storyteller-only block or `[ST]` text.
			$character = clone $character;
			St_Visibility::filter_character( $character, $game, false );
		}
		$game_slug   = $game->slug ?? $character->owner_slug;
		$stack_slug  = (string) $character->stack_slug;
		$race        = GEX_Parser::exchange_race( $stack_slug );
		$map_slug    = $race;
		$sheet       = is_array( $character->sheet_data ) ? $character->sheet_data : (array) ( $character->sheet_data ?? [] );
		$warnings    = [];

		if ( ! GEX_Parser::has_shape( $race ) ) {
			throw new Not_Exportable_Exception(
				__( 'This character\'s creature type has no Grapevine equivalent, so it cannot be exported or transferred.', 'beyond-elysium' )
			);
		}
		$shape = GEX_Parser::shape( $race );

		$writer = new GEX_Xml_Writer();
		$writer->begin_tag( 'grapevine' )->write_attribute( 'version', '3.0' );
		$writer->begin_tag( $shape['xml_tag'] );

		$raw = self::build_raw_scalars( $character, $map_slug, $game_slug, $sheet );
		if ( $verification_url !== null ) {
			$raw['id'] = $verification_url;
		}

		// physical_max/social_max/mental_max are derived.
		$physical = self::list_or_empty( $sheet, self::block_for( $stack_slug, 'Physical' ), 'Physical' );
		$social   = self::list_or_empty( $sheet, self::block_for( $stack_slug, 'Social' ), 'Social' );
		$mental   = self::list_or_empty( $sheet, self::block_for( $stack_slug, 'Mental' ), 'Mental' );
		[ $raw['physical_max'], $raw['social_max'], $raw['mental_max'] ] =
			GEX_Parser::backfill_pool_max( 0, $physical, $social, $mental );

		self::write_scalars( $writer, $shape['scalars'], $raw );

		// Beyond Elysium's own attributes.
		if ( $race !== $stack_slug ) {
			$writer->write_attribute( 'bestack', $stack_slug );
		}
		if ( ! in_array( 'nature', array_column( $shape['scalars'], 'key' ), true ) && $raw['nature'] . $raw['demeanor'] !== '' ) {
			$writer->write_attribute( 'benature', $raw['nature'] )->write_attribute( 'bedemeanor', $raw['demeanor'] );
		}

		self::write_experience( $writer, $character );

		foreach ( $shape['trait_lists'] as $spec ) {
			self::write_trait_list( $writer, $spec, $map_slug, $game_slug, $sheet, $character, $hide_st, $game, $warnings );
		}

		if ( $shape['boons'] ) {
			self::write_boons( $writer, $character, $hide_st, $game );
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
				->write_attribute( 'issued', gmdate( 'n/j/Y g:i:s A' ) );
			if ( $transfer_uuid !== null ) {
				$writer->write_attribute( 'character_uuid', $transfer_uuid );
			}
			$writer->end_tag();
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
	 * Writes every scalar in shape order, resolving each row's omit rule (`xml_omit` against a literal, or `xml_omit_if`
	 * against another raw field's own already-resolved value) before handing it to the writer.
	 *
	 * @param GEX_Xml_Writer            $writer
	 * @param array<int,array<string,mixed>> $scalars
	 * @param array<string,mixed>       $raw
	 */
	private static function write_scalars( GEX_Xml_Writer $writer, array $scalars, array $raw ): void {
		foreach ( $scalars as $scalar ) {
			$value = $raw[ $scalar['key'] ] ?? self::default_for_type( $scalar['type'] );

			if ( isset( $scalar['xml_enum'] ) ) {
				// A sheet stores the label ("Spectre") the XML writes.
				$value = in_array( $value, $scalar['xml_enum'], true ) ? $value : ( $scalar['xml_enum'][ $value ] ?? $scalar['xml_enum'][0] );
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
	 * Builds the raw GV-shaped scalar map for one character: universal fields every race shares (read straight off the
	 * `characters` row), plus this stack's own identity/resource fields inverted mechanically from
	 * `gex-identity-map.php`.
	 *
	 * @param object $character
	 * @param string $map_slug
	 * @param string $game_slug
	 * @param array  $sheet
	 * @return array<string,mixed>
	 */
	private static function build_raw_scalars( object $character, string $map_slug, string $game_slug, array $sheet ): array {
		// Nature and Demeanor live in the shared met-archetypes block.
		$archetypes = (array) ( $sheet['met-archetypes'] ?? [] );
		$raw        = [
			'name'          => (string) $character->name,
			'nature'        => (string) ( $archetypes['Nature'] ?? '' ),
			'demeanor'      => (string) ( $archetypes['Demeanor'] ?? '' ),
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
	 * Enumerates the field/pool names a schema block's own definition declares.
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
	 * Writes the `<experience>` block: current totals from the character's own denormalized columns (always
	 * authoritative).
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
	 * Maps a change type, cost and amount to the Grapevine experience change type.
	 *
	 * @param string $change_type
	 * @param float  $cost
	 * @param float  $amount
	 * @return int ExperienceChangeType (.md: ecEarned=0, ecDeducted=1, ecSetEarned=2, ecSpent=3, ecUnspent=4, ecSetUnspent=5, ecComment=6).
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
	 * Routes one GV trait list to its real export source, via `Trait_Mapper::classify_list()`.
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

		if ( in_array( $gv_list_name, [ 'Influences', 'Backgrounds' ], true ) ) {
			$sources = Backgrounds_Catalog::sources_for( $block_slug, $game_slug );
			$held    = array_values( array_filter( $held, static function ( $item ) use ( $sources, $gv_list_name ) {
				$source = $sources[ $item['name'] ?? '' ] ?? '';
				return $gv_list_name === 'Influences' ? $source === 'Influences' : $source !== 'Influences';
			} ) );
		}

		// Vampire's Disciplines/Rituals fold their blood-magic and combo sibling blocks back.
		if ( isset( $classification['blood_magic_block_slug'] ) ) {
			foreach ( array_values( (array) ( $sheet[ $classification['blood_magic_block_slug'] ] ?? [] ) ) as $pick ) {
				if ( ! isset( $pick['name'], $pick['level'] ) ) {
					continue;
				}
				// A path imported with no tradition named is still held.
				$held[] = [
					'name'  => isset( $pick['tradition'] ) ? "{$pick['tradition']}: {$pick['name']}" : (string) $pick['name'],
					'count' => $pick['level'],
				];
			}
		}
		if ( isset( $classification['combo_block_slug'] ) ) {
			foreach ( array_values( (array) ( $sheet[ $classification['combo_block_slug'] ] ?? [] ) ) as $pick ) {
				$held[] = $pick;
			}
		}

		foreach ( $held as $entry ) {
			$name = isset( $entry['power_name'] ) && $entry['power_name'] !== ''
				? "{$entry['name']}: {$entry['power_name']}"
				: (string) ( $entry['name'] ?? '' );
			$writer->begin_tag( 'trait' )
				->write_attribute( 'name', $name )
				->write_attribute( 'val', (string) ( $entry['count'] ?? $entry['level'] ?? 1 ), '1' )
				->write_attribute( 'note', self::filter_note( (string) ( $entry['note'] ?? '' ), $hide_st, $settings ), '' )
				->end_tag();
		}
	}

	/**
	 * Equipment/Locations: `be_connections` from this character to a `world_object` of the given type
	 * (gex-trait-list-map.php's own `world_object` outcome), one trait per connected item.
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
	 * Backfills a `preserve_as_note`/`needs_design` list from the character's own most recent `import_note` change, if
	 * one exists.
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
	 * Writes this vampire's boon list.
	 *
	 * @param GEX_Xml_Writer $writer
	 * @param object         $character
	 * @param bool           $hide_st
	 * @param object|null    $game
	 */
	private static function write_boons( GEX_Xml_Writer $writer, object $character, bool $hide_st, $game ): void {
		foreach ( Connection::for_target( 'character', (int) $character->id ) as $connection ) {
			if ( $connection->source_type !== 'world_object' || ! in_array( $connection->label, [ 'owed_by', 'owed_to' ], true ) ) {
				continue;
			}
			$boon = World_Object::find( (int) $connection->source_id );
			if ( ! $boon || $boon->object_type !== 'boon' ) {
				continue;
			}
			St_Visibility::filter_world_object( $boon, $game, ! $hide_st );

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
