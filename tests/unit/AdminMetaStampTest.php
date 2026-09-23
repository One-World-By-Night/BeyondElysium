<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * 1.2.10 E5 - an administrator's own `_meta` edits survive a reseed, and **nothing else does**.
 *
 * Owner ruling, 2026-09-22: *"per-key stamp - record which keys the admin set."* The two
 * alternatives both fail silently, which is why this exists rather than something simpler:
 * adding `_meta` to `ADMIN_OWNED_BLOCK_KEYS` is **inert**, because that rule only fires when
 * the fresh seed lacks the key and `meta_for()` emits `_meta` on every run; and keeping a
 * stored `_meta` wholesale is **harmful**, because every install has one after its first seed,
 * so 1.3.0's Wraith (D69) and Mage (D70) cost corrections could never reach an installed site.
 *
 * The test that matters most here is therefore not "the edit survives" but
 * `test_an_untouched_key_still_takes_the_new_seeded_value()` - the one that proves corrections
 * still arrive.
 */
class AdminMetaStampTest extends TestCase {

	/** @return array<string,mixed> */
	private function meta( array $overrides = [] ): array {
		return array_merge( [
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			'costs'  => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9 ],
		], $overrides );
	}

	/** Runs a save the way Schema_Blocks_Controller::update_item() does. */
	private function save( array $stored_meta, array $incoming_meta ): array {
		$stored   = (object) [ '_meta' => $stored_meta, 'powers' => [] ];
		$incoming = [ '_meta' => $incoming_meta, 'powers' => [] ];
		$result   = Seeder::mark_admin_additions( $stored, $incoming );
		return $result['_meta'];
	}

	// --- stamping, at save time ------------------------------------------------

	public function test_a_changed_cost_is_stamped_by_path_not_by_whole_map(): void {
		$after = $this->save(
			$this->meta(),
			$this->meta( [ 'costs' => [ 'basic' => 5, 'intermediate' => 6, 'advanced' => 9 ] ] )
		);

		$this->assertSame( [ 'costs.basic' ], $after['_admin_set'] );
	}

	public function test_an_unchanged_save_stamps_nothing(): void {
		$after = $this->save( $this->meta(), $this->meta() );

		$this->assertArrayNotHasKey( '_admin_set', $after, 'saving without editing must not claim an edit' );
	}

	public function test_an_existing_stamp_survives_a_later_unrelated_save(): void {
		$stored = $this->meta( [ '_admin_set' => [ 'costs.basic' ], 'costs' => [ 'basic' => 5, 'intermediate' => 6, 'advanced' => 9 ] ] );
		$after  = $this->save( $stored, $this->meta( [ 'costs' => [ 'basic' => 5, 'intermediate' => 6, 'advanced' => 9 ] ] ) );

		$this->assertSame( [ 'costs.basic' ], $after['_admin_set'] );
	}

	public function test_an_ordered_list_stamps_whole_rather_than_per_element(): void {
		// `ranks` is an ordered vocabulary - one changed element changes what the rest mean.
		$after = $this->save( $this->meta(), $this->meta( [ 'ranks' => [ 'basic', 'intermediate', 'advanced', 'elder' ] ] ) );

		$this->assertSame( [ 'ranks' ], $after['_admin_set'] );
	}

	public function test_adding_a_key_that_did_not_exist_counts_as_an_edit(): void {
		$after = $this->save( $this->meta(), $this->meta( [ 'untiered' => [ 'cost_per_level' => 2 ] ] ) );

		$this->assertSame( [ 'untiered.cost_per_level' ], $after['_admin_set'] );
	}

	// --- applying, at reseed time ----------------------------------------------

	public function test_a_stamped_value_survives_a_reseed(): void {
		$stored = $this->meta( [ '_admin_set' => [ 'costs.basic' ], 'costs' => [ 'basic' => 5, 'intermediate' => 6, 'advanced' => 9 ] ] );
		$fresh  = $this->meta();

		$merged = Seeder::apply_admin_meta( $fresh, $stored );

		$this->assertSame( 5, $merged['costs']['basic'], "the chronicle's own house rate is kept" );
	}

	/**
	 * **The one that matters.** A key the administrator never touched must take whatever the
	 * seeder now says - otherwise a catalog correction can never reach an installed site,
	 * which is the exact failure the wholesale alternative would have shipped.
	 */
	public function test_an_untouched_key_still_takes_the_new_seeded_value(): void {
		$stored = $this->meta( [ '_admin_set' => [ 'costs.basic' ], 'costs' => [ 'basic' => 5, 'intermediate' => 6, 'advanced' => 9 ] ] );
		// 1.3.0 corrects advanced from 9 to 12 while the admin's basic house rule stands.
		$fresh = $this->meta( [ 'costs' => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 12 ] ] );

		$merged = Seeder::apply_admin_meta( $fresh, $stored );

		$this->assertSame( 5, $merged['costs']['basic'], "the admin's own key is still theirs" );
		$this->assertSame( 12, $merged['costs']['advanced'], 'the correction they never touched still arrives' );
	}

	public function test_a_block_with_no_stamp_takes_the_fresh_meta_entirely(): void {
		$merged = Seeder::apply_admin_meta( $this->meta( [ 'costs' => [ 'basic' => 4 ] ] ), $this->meta() );

		$this->assertSame( [ 'basic' => 4 ], $merged['costs'] );
		$this->assertArrayNotHasKey( '_admin_set', $merged );
	}

	public function test_the_stamp_list_itself_survives_the_reseed(): void {
		$stored = $this->meta( [ '_admin_set' => [ 'costs.basic' ], 'costs' => [ 'basic' => 5 ] ] );

		$merged = Seeder::apply_admin_meta( $this->meta(), $stored );

		$this->assertSame( [ 'costs.basic' ], $merged['_admin_set'], 'a surviving edit must stay marked, or the next reseed forgets it' );
	}

	public function test_a_whole_key_stamp_survives_a_reseed(): void {
		$stored = $this->meta( [ '_admin_set' => [ 'ranks' ], 'ranks' => [ 'basic', 'intermediate', 'advanced', 'elder' ] ] );

		$merged = Seeder::apply_admin_meta( $this->meta(), $stored );

		$this->assertSame( [ 'basic', 'intermediate', 'advanced', 'elder' ], $merged['ranks'] );
	}

	/** An administrator clearing a value is an edit too - it must not be re-seeded back in. */
	public function test_a_cleared_value_stays_cleared(): void {
		$stored = [ '_admin_set' => [ 'out_of_type.basic' ], 'ranks' => [ 'basic' ], 'ladder' => [ 'basic' => 1 ], 'costs' => [ 'basic' => 3 ] ];
		$fresh  = [ 'ranks' => [ 'basic' ], 'ladder' => [ 'basic' => 1 ], 'costs' => [ 'basic' => 3 ], 'out_of_type' => [ 'basic' => '+1' ] ];

		$merged = Seeder::apply_admin_meta( $fresh, $stored );

		$this->assertArrayNotHasKey( 'basic', $merged['out_of_type'] ?? [] );
	}
}
