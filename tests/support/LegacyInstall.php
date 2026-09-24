<?php

namespace BeyondElysium\Tests\Support;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Services\Catalog_Translator;
use BeyondElysium\Services\Retired_Blocks;

/**
 * Puts the test install in the state of a site that has not moved to the per-creature catalog: no switch marker, the
 * three shared blocks (`met-abilities`, `met-merits`, `met-flaws`) present, and every creature stack and default
 * sheet template naming them.
 */
class LegacyInstall {

	/**
	 * Kind of list => the shared block that held it.
	 */
	private const SHARED = [
		'abilities' => 'met-abilities',
		'merits'    => 'met-merits',
		'flaws'     => 'met-flaws',
	];

	/**
	 * Entries the shared lists spelled differently from the per-creature lists, by kind.
	 */
	private const LEGACY_SPELLINGS = [
		'abilities' => [ 'Fortune-telling', 'Meditiation' ],
		'flaws'     => [ 'Light Sensitive' ],
	];

	/**
	 * Sections a declared stack carries that the shared layout never had, and the one block that stood in for them.
	 */
	private const STAND_INS = [
		'demon'  => [
			'sections' => [ 'demon-evocations', 'demon-rituals' ],
			'block'    => [ 'block_slug' => 'demon-lores', 'label' => 'Lores', 'display_order' => 60, 'required' => true ],
		],
		'mortal' => [
			'sections' => [
				'mortal-psychic', 'mortal-hedge-magic', 'mortal-hedge-magic-formulae', 'mortal-theurgy', 'mortal-martial-arts',
				'mortal-fomori', 'mortal-bioenhancements', 'vampire-disciplines', 'werewolf-gifts', 'fera-gifts',
				'changeling-arts', 'changeling-realms',
			],
			'block'    => [ 'block_slug' => 'mortal-numina', 'label' => 'Numina', 'display_order' => 60, 'required' => false ],
		],
	];

	/**
	 * Puts the install in place.
	 */
	public static function put_in_place(): void {
		delete_option( Catalog_Cutover::OPTION );
		delete_option( 'be_catalog_cutover_record' );
		Catalog_Reader::reset_cache();

		self::shared_blocks();
		self::stand_in_blocks();
		self::write_stacks();
		self::write_templates();
	}

	/**
	 * Brings the install back to the state a new one starts.
	 */
	public static function restore(): void {
		global $wpdb;

		update_option( Catalog_Cutover::OPTION, 'declared' );
		Catalog_Reader::reset_cache();
		Seeder::seed_schema_blocks();
		Seeder::seed_creature_stacks();
		Catalog_Cutover::rewrite_templates();
		Schema::repair_stale_default_layouts();
		Schema::repair_stale_npc_layouts();
		Retired_Blocks::drop_from_system_templates();
		Schema::complete_full_sheet_templates();
		Retired_Blocks::remove_unused();
		delete_option( Seeder::DEMO_SEEDED_OPTION );
		Seeder::seed_demo_characters( true );

		$wpdb->query( 'TRUNCATE TABLE ' . Manager::table( 'translations' ) );
		$wpdb->query( 'TRUNCATE TABLE ' . Manager::table( 'translation_strings' ) );
		Catalog_Translator::rescan();
		Schema::migrate_catalog_translations_to_table();

		$wpdb->query( 'COMMIT' );
	}

	/**
	 * The block a stack listed a section on before the switch, or the section's own block when it never moved.
	 */
	public static function legacy_slug( string $stack, string $slug ): string {
		$reverse = array_flip( Catalog_Reader::replacement_maps()[ $stack ] ?? [] );
		return $reverse[ $slug ] ?? $slug;
	}

	/**
	 * Each shared block holds every entry the per-creature blocks of its kind hold, first spelling winning.
	 */
	public static function shared_blocks(): void {
		$declared = Catalog_Reader::blocks_to_seed();

		foreach ( self::SHARED as $kind => $slug ) {
			if ( Schema_Block::find_by_slug( $slug ) ) {
				continue;
			}

			$items = [];
			foreach ( $declared as $block_slug => $block ) {
				if ( substr( $block_slug, -( strlen( $kind ) + 1 ) ) !== "-{$kind}" ) {
					continue;
				}
				foreach ( (array) ( $block['definition']['items'] ?? [] ) as $item ) {
					$items[ $item['name'] ] ??= $item;
				}
			}

			// The spellings the shared lists carried that the per-creature lists correct.
			foreach ( self::LEGACY_SPELLINGS[ $kind ] ?? [] as $spelling ) {
				$items[ $spelling ] ??= [ 'name' => $spelling, 'cost' => '1' ];
			}

			Schema_Block::create( [
				'slug'         => $slug,
				'name'         => ucfirst( $kind ),
				'section_type' => 'trait_list',
				'is_system'    => 1,
				'definition'   => [
					'items'          => array_values( $items ),
					'atomic'         => $kind !== 'abilities',
					'negative'       => $kind === 'flaws',
					'alphabetize'    => true,
					'allow_custom'   => true,
					'allow_multiples' => false,
				],
			] );
		}
	}

	/**
	 * The two blocks that stood in for Demon's Lores and Mortal's Numina, empty.
	 */
	public static function stand_in_blocks(): void {
		foreach ( [ 'demon-lores' => 'Demon Lores', 'mortal-numina' => 'Mortal Numina' ] as $slug => $name ) {
			if ( ! Schema_Block::find_by_slug( $slug ) ) {
				Schema_Block::create( [ 'slug' => $slug, 'name' => $name, 'section_type' => 'tiered_power', 'is_system' => 1, 'definition' => [ 'powers' => [] ] ] );
			}
		}
	}

	private static function write_stacks(): void {
		foreach ( Catalog_Reader::stacks_to_seed() as $slug => $stack ) {
			$definition = $stack['stack_definition'];
			$definition['sections'] = self::legacy_sections( $slug, $definition['sections'] );
			Creature_Stack::update( $slug, [ 'stack_definition' => $definition ] );
		}
	}

	private static function write_templates(): void {
		foreach ( Template::globals() as $template ) {
			if ( empty( $template->is_system ) || (string) $template->template_type === 'npc_quick' ) {
				continue;
			}
			$layout             = $template->layout;
			$layout['sections'] = self::legacy_sections( (string) $template->stack_slug, $layout['sections'] ?? [] );
			Template::update( (int) $template->id, [ 'layout' => $layout ] );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $sections
	 * @return array<int,array<string,mixed>>
	 */
	private static function legacy_sections( string $stack, array $sections ): array {
		$stand_in = self::STAND_INS[ $stack ] ?? null;
		$out      = [];
		$placed   = false;

		foreach ( $sections as $section ) {
			if ( $stand_in && in_array( $section['block_slug'], $stand_in['sections'], true ) ) {
				if ( ! $placed ) {
					$out[]  = $stand_in['block'] + array_intersect_key( $section, [ 'width' => 1, 'display' => 1, 'collapsed' => 1, 'column' => 1, 'order' => 1 ] );
					$placed = true;
				}
				continue;
			}
			$section['block_slug'] = self::legacy_slug( $stack, (string) $section['block_slug'] );
			foreach ( (array) ( $section['title_refs'] ?? [] ) as $i => $ref ) {
				$section['title_refs'][ $i ]['block_slug'] = self::legacy_slug( $stack, (string) $ref['block_slug'] );
			}
			$out[] = $section;
		}

		return $out;
	}
}
