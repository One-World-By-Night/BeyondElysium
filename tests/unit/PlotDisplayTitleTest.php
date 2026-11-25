<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Models\Character;
use PHPUnit\Framework\TestCase;

/**
 * A character's own plot reads "<name>'s plot" to a person, and every other title reads as written.
 */
class PlotDisplayTitleTest extends TestCase {

	public function test_a_characters_own_plot_reads_without_its_id(): void {
		$this->assertSame( "Detective Rosa Alvarez's plot", Character::plot_display_title( 'Detective Rosa Alvarez [3219] Plot' ) );
	}

	public function test_it_is_the_title_the_plot_is_stored_under(): void {
		$this->assertSame( "Raine's plot", Character::plot_display_title( Character::plot_title( 'Raine', 301 ) ) );
	}

	public function test_brackets_in_the_name_stay(): void {
		$this->assertSame( "Raine [the Younger]'s plot", Character::plot_display_title( 'Raine [the Younger] [301] Plot' ) );
	}

	public function test_a_title_a_storyteller_wrote_reads_as_written(): void {
		$this->assertSame( 'The Siege of Boston', Character::plot_display_title( 'The Siege of Boston' ) );
		$this->assertSame( 'Raine [301] Plot twist', Character::plot_display_title( 'Raine [301] Plot twist' ) );
		$this->assertSame( 'Raine [abc] Plot', Character::plot_display_title( 'Raine [abc] Plot' ) );
		$this->assertSame( '[301] Plot', Character::plot_display_title( '[301] Plot' ) );
		$this->assertSame( '', Character::plot_display_title( '' ) );
	}
}
