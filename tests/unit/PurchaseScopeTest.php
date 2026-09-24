<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Purchase_Scope;
use PHPUnit\Framework\TestCase;

/**
 * The pure half of `Purchase_Scope`: which block slugs belong to which switch, and what a write or a stored value
 * counts as.
 */
class PurchaseScopeTest extends TestCase {

	public function test_a_block_slug_belongs_to_the_family_its_name_ends_in(): void {
		$this->assertSame( 'abilities', Purchase_Scope::suffix_of( 'vampire-abilities' ) );
		$this->assertSame( 'backgrounds', Purchase_Scope::suffix_of( 'mage-backgrounds' ) );
		$this->assertSame( 'merits', Purchase_Scope::suffix_of( 'fera-merits' ) );
		$this->assertSame( 'flaws', Purchase_Scope::suffix_of( 'wraith-flaws' ) );
		$this->assertNull( Purchase_Scope::suffix_of( 'vampire-disciplines' ) );
		$this->assertNull( Purchase_Scope::suffix_of( 'abilities' ), 'a bare word is not a family member' );
	}

	public function test_merits_and_flaws_share_one_switch_and_the_others_have_their_own(): void {
		$this->assertSame( 'abilities', Purchase_Scope::area_of( 'abilities' ) );
		$this->assertSame( 'backgrounds', Purchase_Scope::area_of( 'backgrounds' ) );
		$this->assertSame( 'merits_flaws', Purchase_Scope::area_of( 'merits' ) );
		$this->assertSame( 'merits_flaws', Purchase_Scope::area_of( 'flaws' ) );
		$this->assertNull( Purchase_Scope::area_of( 'health' ) );
	}

	public function test_a_write_must_be_known_switches_with_a_plain_on_or_off(): void {
		$this->assertSame( [ 'abilities' => true ], Purchase_Scope::sanitize( [ 'abilities' => true ] ) );
		$this->assertSame( [ 'backgrounds' => false, 'merits_flaws' => true ], Purchase_Scope::sanitize( [ 'backgrounds' => 0, 'merits_flaws' => '1' ] ) );
		$this->assertSame( [ 'abilities' => false ], Purchase_Scope::sanitize( (object) [ 'abilities' => 'false' ] ) );

		$this->assertNull( Purchase_Scope::sanitize( [] ), 'nothing to switch' );
		$this->assertNull( Purchase_Scope::sanitize( 'abilities' ) );
		$this->assertNull( Purchase_Scope::sanitize( [ 'combat' => true ] ), 'an unknown area is refused, not stored' );
		$this->assertNull( Purchase_Scope::sanitize( [ 'abilities' => 'maybe' ] ) );
		$this->assertNull( Purchase_Scope::sanitize( [ 'abilities' => [ true ] ] ) );
	}

	public function test_a_stored_value_reads_as_off_unless_it_says_on(): void {
		$off = [ 'abilities' => false, 'backgrounds' => false, 'merits_flaws' => false ];

		$this->assertSame( $off, Purchase_Scope::normalize( null ) );
		$this->assertSame( $off, Purchase_Scope::normalize( 'garbage' ) );
		$this->assertSame( [ 'abilities' => true, 'backgrounds' => false, 'merits_flaws' => false ], Purchase_Scope::normalize( (object) [ 'abilities' => true, 'combat' => true ] ) );
		$this->assertSame( $off, Purchase_Scope::normalize( [ 'abilities' => '0', 'backgrounds' => 'no', 'merits_flaws' => null ] ) );
	}
}
