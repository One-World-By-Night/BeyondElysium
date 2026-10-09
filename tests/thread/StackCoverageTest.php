<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\REST\Import_Controller;
use BeyondElysium\Services\Catalog_Reader;
use PHPUnit\Framework\TestCase;

/**
 * Every Grapevine race either ships a real creature type, or is named in `Import_Controller::REFUSED_RACES` -
 * never neither, so a new race can never silently fall through the import hole.
 */
class StackCoverageTest extends TestCase {

	/** @var string[] */
	private static array $races;

	public static function setUpBeforeClass(): void {
		$shape = require BE_PLUGIN_ROOT . '/beyond-elysium/includes/Services/gv-exchange-shape.php';
		self::$races = array_keys( $shape );
	}

	public function test_every_race_ships_a_stack_or_is_refused(): void {
		foreach ( self::$races as $race ) {
			$ships   = file_exists( Catalog_Reader::DEFAULT_ROOT . "/stacks/{$race}.json" );
			$refused = in_array( $race, Import_Controller::REFUSED_RACES, true );
			$this->assertTrue(
				$ships || $refused,
				"\"{$race}\" has no stack JSON and is not in REFUSED_RACES - the import hole would swallow it silently"
			);
			$this->assertFalse(
				$ships && $refused,
				"\"{$race}\" ships a real stack and is also marked refused - REFUSED_RACES is stale"
			);
		}
	}

	public function test_refused_races_are_real_gv_races_with_no_stack(): void {
		$this->assertIsArray( Import_Controller::REFUSED_RACES, 'every real Grapevine race currently ships a stack, so this is empty rather than untyped' );
		foreach ( Import_Controller::REFUSED_RACES as $race ) {
			$this->assertContains( $race, self::$races, "\"{$race}\" in REFUSED_RACES is not even a real Grapevine race" );
			$this->assertFileDoesNotExist( Catalog_Reader::DEFAULT_ROOT . "/stacks/{$race}.json" );
		}
	}
}
