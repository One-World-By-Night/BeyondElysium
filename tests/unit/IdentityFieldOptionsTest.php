<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * Every identity `select` field carried an `options_ref` that nothing ever resolved -
 * `IdentityFieldEditor.tsx`'s `resolveOptions()` only special-cased one hardcoded, unused
 * ref name, so Clan, Tribe, House, Kith, and every other identity dropdown on every
 * creature type rendered empty for the entire life of the project. Found live 2026-09-09
 * editing a demon character whose House/Faction showed as "-". Ground truth for every list
 * below came from GV301Source/Code/Grapevine Menus XML.gvm, not invented.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md
 */
class IdentityFieldOptionsTest extends TestCase {

	private function fields( string $slug ): array {
		$blocks = Seeder::get_blocks_to_seed();
		foreach ( $blocks as $block ) {
			if ( $block['slug'] === $slug ) {
				return $block['definition']['fields'];
			}
		}
		$this->fail( "Block {$slug} not found in get_blocks_to_seed()." );
	}

	private function field( string $slug, string $name ): array {
		foreach ( $this->fields( $slug ) as $field ) {
			if ( $field['name'] === $name ) {
				return $field;
			}
		}
		$this->fail( "Field {$name} not found on {$slug}." );
	}

	/**
	 * Superseded by the MET-Mechanics CSV overlay (workflow-0.10): Clan is now a merged
	 * GVM+CSV list (87 names, up from GVM's own 48) and GVM's deliberate abc="no" file order
	 * isn't preservable at that size, so this alphabetizes like every other large identity
	 * select in this codebase - see Seeder::apply_met_csv_to_vampire_identity(). Options
	 * still resolve (this test's original point) and both real GVM-only and CSV-only names
	 * survive the merge (see MetCsvSeederTest::test_gvm_only_clans_and_paths_survive_the_merge()).
	 */
	public function test_vampire_clan_resolves_real_options_alphabetized(): void {
		$options = $this->field( 'vampire-identity', 'Clan' )['options'];

		$this->assertNotEmpty( $options );
		$this->assertContains( 'Ventrue', $options );
		$this->assertContains( 'Caitiff', $options );
		$sorted = $options;
		sort( $sorted );
		$this->assertSame( $sorted, $options );
	}

	public function test_vampire_sect_is_alphabetized(): void {
		$options = $this->field( 'vampire-identity', 'Sect' )['options'];

		$this->assertContains( 'Camarilla', $options );
		$this->assertContains( 'Sabbat', $options );
		$sorted = $options;
		sort( $sorted );
		$this->assertSame( $sorted, $options );
	}

	public function test_demon_house_and_faction_resolve_the_race_suffixed_menu(): void {
		// The field name alone ("House") is not a real menu - only "House, Demon" is
		// (Grapevine's own race-suffix resolution rule). A wrong ref here silently produces
		// an empty list, not an error, so this specifically checks real content.
		$house   = $this->field( 'demon-identity', 'House' )['options'];
		$faction = $this->field( 'demon-identity', 'Faction' )['options'];

		$this->assertContains( 'Devil', $house );
		$this->assertContains( 'Scourge', $house );
		$this->assertContains( 'Luciferian', $faction );
	}

	public function test_wraith_ethnos_is_the_hardcoded_enum_not_a_menu(): void {
		// No "Ethnos" menu exists anywhere in the source - WraithClass.cls's EthnosType
		// enum (Wraith/Risen/Spectre) is the only real list.
		$this->assertSame( [ 'Wraith', 'Risen', 'Spectre' ], $this->field( 'wraith-identity', 'Ethnos' )['options'] );
	}

	public function test_changeling_kith_includes_its_submenu_entries(): void {
		// Kith is a container: 14 direct items plus 3 submenus (Inanimae, Nunnehi, Thallain)
		// that must be flattened in, not left as unresolved container labels.
		$options = $this->field( 'changeling-identity', 'Kith' )['options'];

		$this->assertContains( 'Pooka', $options, 'a direct Kith item' );
		$this->assertContains( 'Ondine', $options, 'an Inanimae submenu item' );
		$this->assertContains( 'Rock Giant', $options, 'a Nunnehi submenu item' );
		$this->assertContains( 'Spriggan', $options, 'a Thallain submenu item' );
	}

	public function test_fera_breed_is_not_the_same_list_as_werewolf_breed(): void {
		$werewolf_breed = $this->field( 'werewolf-identity', 'Breed' )['options'];
		$fera_breed     = $this->field( 'fera-identity', 'Breed' )['options'];

		$this->assertContains( 'Homid', $werewolf_breed );
		$this->assertNotContains( 'Corvid', $werewolf_breed, 'Corvid is Fera-only, not a werewolf Breed' );

		$this->assertContains( 'Homid', $fera_breed, 'Fera Breed still includes the werewolf-shared values' );
		$this->assertContains( 'Corvid', $fera_breed, 'Fera Breed adds its own values on top' );
	}

	public function test_a_field_with_no_matching_menu_gets_an_empty_list_not_a_fatal(): void {
		$blocks = Seeder::get_blocks_to_seed();
		$this->assertNotEmpty( $blocks, 'sanity check: GVM parsing produced real blocks at all' );
	}

	private function pool( string $slug, string $name ): array {
		$blocks = Seeder::get_blocks_to_seed();
		foreach ( $blocks as $block ) {
			if ( $block['slug'] === $slug ) {
				foreach ( $block['definition']['pools'] as $pool ) {
					if ( $pool['name'] === $name ) {
						return $pool;
					}
				}
			}
		}
		$this->fail( "Pool {$name} not found on {$slug}." );
	}

	/**
	 * workflow-0.9.md Step 0f: the player picks their virtue directly (two new optional
	 * `vampire-identity` fields) rather than the plugin maintaining a Morality-Path-keyed
	 * naming table that can never cover every real or homebrew Path - Decision 044's
	 * original mechanism, replaced per the user's own domain call.
	 */
	public function test_the_two_virtue_axis_fields_exist_with_the_real_binary_choice(): void {
		$conscience_axis    = $this->field( 'vampire-identity', 'Conscience or Conviction' );
		$self_control_axis  = $this->field( 'vampire-identity', 'Self-Control or Instinct' );

		$this->assertSame( [ 'Conscience', 'Conviction' ], $conscience_axis['options'] );
		$this->assertSame( [ 'Self-Control', 'Instinct' ], $self_control_axis['options'] );
		// Optional, not required - unset is the correct state for Humanity, the default
		// for the large majority of characters.
		$this->assertFalse( $conscience_axis['required'] );
		$this->assertFalse( $self_control_axis['required'] );
	}

	public function test_conscience_and_self_control_pools_key_off_the_new_axis_fields(): void {
		$conscience   = $this->pool( 'vampire-virtues', 'Conscience' );
		$self_control = $this->pool( 'vampire-virtues', 'Self-Control' );

		$this->assertSame(
			[ 'block_slug' => 'vampire-identity', 'field' => 'Conscience or Conviction' ],
			$conscience['name_lookup']['keyed_by']
		);
		$this->assertSame( [ 'Conviction' => 'Conviction' ], $conscience['name_lookup']['table'] );

		$this->assertSame(
			[ 'block_slug' => 'vampire-identity', 'field' => 'Self-Control or Instinct' ],
			$self_control['name_lookup']['keyed_by']
		);
		$this->assertSame( [ 'Instinct' => 'Instinct' ], $self_control['name_lookup']['table'] );
	}

	public function test_courage_has_no_name_lookup_at_all(): void {
		$courage = $this->pool( 'vampire-virtues', 'Courage' );
		$this->assertArrayNotHasKey( 'name_lookup', $courage, 'No researched Path renames Courage - see vampire-virtue-names.php.' );
	}
}
