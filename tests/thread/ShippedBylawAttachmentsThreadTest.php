<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Bylaws;
use WP_UnitTestCase;

/**
 * Every attachment in the real shipped `owbn-character-bylaws.json` resolves to a real catalog entry, on a
 * fresh install's own seeded data - not a synthetic fixture.
 */
class ShippedBylawAttachmentsThreadTest extends WP_UnitTestCase {

	/**
	 * Every trait_list item's name, tiered_power family name and resource_pool pool name, grouped by the
	 * attachment family `Bylaws::family_of_block()` derives for that block.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function names_by_family(): array {
		$names_by_family = [];
		foreach ( Schema_Block::all() as $block ) {
			$family = Bylaws::family_of_block( $block->slug );
			$entries = [];
			if ( $block->section_type === 'trait_list' ) {
				$entries = $block->definition->items ?? [];
			} elseif ( $block->section_type === 'tiered_power' ) {
				$entries = $block->definition->powers ?? [];
			} elseif ( $block->section_type === 'resource_pool' ) {
				$entries = $block->definition->pools ?? [];
			}
			foreach ( $entries as $entry ) {
				if ( isset( $entry->name ) ) {
					$names_by_family[ $family ][ $entry->name ] = true;
				}
			}
		}
		return $names_by_family;
	}

	public function test_every_shipped_attachment_resolves_to_a_real_seeded_catalog_entry(): void {
		$shipped         = Bylaws::shipped();
		$names_by_family = $this->names_by_family();
		$missing         = [];

		foreach ( $shipped['attachments'] as $attachment ) {
			$family = (string) ( $attachment['family'] ?? '' );
			$name   = (string) ( $attachment['name'] ?? '' );
			if ( ! isset( $names_by_family[ $family ][ $name ] ) ) {
				$missing[] = "clause {$attachment['clause_id']}: \"{$family}\" has no entry named \"{$name}\"";
			}
		}

		$this->assertSame( [], $missing, implode( "\n", $missing ) );
	}

	public function test_the_shipped_file_is_not_empty(): void {
		$shipped = Bylaws::shipped();
		$this->assertNotEmpty( $shipped['rules'] );
		$this->assertNotEmpty( $shipped['attachments'] );
	}
}
