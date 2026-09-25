<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Power_Display;
use PHPUnit\Framework\TestCase;

/**
 * `Power_Display::level_qualifier()`, the twin of `src/lib/levelQualifier.ts`: whatever a level's note says beyond its
 * tier word.
 */
class LevelQualifierTest extends TestCase {

	/**
	 * @return array<string,array{0:?string,1:?string}>
	 */
	public static function notes(): array {
		return [
			'a tier alone'                => [ 'basic', null ],
			'an abbreviated tier alone'   => [ 'int.', null ],
			'a parenthesised tradition'   => [ 'Basic (Sabbat)', 'Sabbat' ],
			'a trailing qualifier'        => [ 'int. ritual', 'ritual' ],
			'a comma-separated qualifier' => [ 'adv., wyld west', 'wyld west' ],
			'no tier word'                => [ 'legend', null ],
			'a tier inside a longer word' => [ 'winter pack', null ],
			'a hyphenated tier'           => [ 'master-level working', null ],
			'a real tier after a phrase'  => [ 'Winter pack, basic', 'Winter pack' ],
			'no note'                     => [ null, null ],
		];
	}

	/**
	 * @dataProvider notes
	 */
	public function test_level_qualifier_reads_what_the_note_says_beyond_its_tier( ?string $note, ?string $expected ): void {
		$this->assertSame( $expected, Power_Display::level_qualifier( $note ) );
	}
}
