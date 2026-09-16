<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * `Seeder::seed_schema_blocks()` replaces a system block's entire `definition`
 * on every call (R5 - the catalog stays in sync with GVM/CSV source data on
 * every plugin version bump). `description` is the one field on a catalog
 * item that source data never populates - it exists purely because a site
 * admin typed a house rule or page reference into it - so without
 * `Seeder::preserve_admin_descriptions()`, a real reseed would silently
 * destroy every admin-written note in the entire global catalog the next
 * time this plugin updates. This is the regression guard proving it doesn't,
 * against a real system block and a real reseed, not a constructed fixture.
 */
class PreserveAdminDescriptionsThreadTest extends WP_UnitTestCase {

	public function test_a_trait_list_items_description_survives_a_real_reseed(): void {
		$block = Schema_Block::find_by_slug( 'met-abilities' );
		$this->assertSame( 1, (int) $block->is_system, 'met-abilities must be a real system block for this test to mean anything' );

		$definition = json_decode( wp_json_encode( $block->definition ), true );
		$found      = false;
		foreach ( $definition['items'] as &$item ) {
			if ( $item['name'] === 'Occult' ) {
				$item['description'] = [ 'reference' => '<p>House rule via test</p>' ];
				$found                = true;
			}
		}
		unset( $item );
		$this->assertTrue( $found, 'Occult must exist in the real met-abilities catalog for this test to mean anything' );

		Schema_Block::update( 'met-abilities', [ 'definition' => $definition ] );

		// The real reseed - unconditional inside seed_schema_blocks() itself,
		// exactly what a plugin version bump triggers via maybe_upgrade().
		Seeder::seed_schema_blocks();

		$reseeded = Schema_Block::find_by_slug( 'met-abilities' );
		$occult   = null;
		foreach ( $reseeded->definition->items as $item ) {
			if ( $item->name === 'Occult' ) {
				$occult = $item;
			}
		}
		$this->assertNotNull( $occult );
		$this->assertSame( '<p>House rule via test</p>', $occult->description->reference ?? null );
	}

	public function test_a_tiered_power_familys_and_levels_descriptions_both_survive_a_real_reseed(): void {
		$block = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$this->assertSame( 1, (int) $block->is_system );

		$definition   = json_decode( wp_json_encode( $block->definition ), true );
		$found_family = false;
		$found_level  = false;
		foreach ( $definition['powers'] as &$power ) {
			if ( $power['name'] === 'Celerity' ) {
				$power['description'] = [ 'description' => '<p>Family house rule</p>' ];
				$found_family          = true;
				foreach ( $power['levels'] as &$level ) {
					if ( $level['power_name'] === 'Alacrity' ) {
						$level['description'] = [ 'source' => '<p>Level house rule</p>' ];
						$found_level            = true;
					}
				}
				unset( $level );
			}
		}
		unset( $power );
		$this->assertTrue( $found_family, 'Celerity must exist in the real vampire-disciplines catalog for this test to mean anything' );
		$this->assertTrue( $found_level, 'Alacrity must exist under Celerity for this test to mean anything' );

		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $definition ] );

		Seeder::seed_schema_blocks();

		$reseeded = Schema_Block::find_by_slug( 'vampire-disciplines' );
		$celerity = null;
		foreach ( $reseeded->definition->powers as $power ) {
			if ( $power->name === 'Celerity' ) {
				$celerity = $power;
			}
		}
		$this->assertNotNull( $celerity );
		$this->assertSame( '<p>Family house rule</p>', $celerity->description->description ?? null );

		$alacrity = null;
		foreach ( $celerity->levels as $level ) {
			if ( $level->power_name === 'Alacrity' ) {
				$alacrity = $level;
			}
		}
		$this->assertNotNull( $alacrity );
		$this->assertSame( '<p>Level house rule</p>', $alacrity->description->source ?? null );
	}

	/**
	 * The one deliberate limitation: a renamed source item loses its old note
	 * rather than the reseed guessing which new item it belongs to now
	 * (Decision 043's tie rule, applied to a rename). Proven directly with a
	 * throwaway custom (is_system = 0) block reseeded manually through the
	 * same preserve/update pair a real system block goes through, since a
	 * real GVM item can't be renamed out from under this test.
	 */
	public function test_a_renamed_item_does_not_carry_its_old_description_forward(): void {
		$method = new \ReflectionMethod( Seeder::class, 'preserve_admin_edits' );
		$method->setAccessible( true );

		$old_definition = json_decode( wp_json_encode( [
			'items' => [ [ 'name' => 'Old Name', 'description' => [ 'reference' => '<p>Note</p>' ] ] ],
		] ) );

		$new_block = [
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'New Name' ] ] ],
		];

		$result = $method->invoke( null, $new_block, $old_definition );

		$this->assertArrayNotHasKey( 'description', $result['definition']['items'][0] );
	}
}
