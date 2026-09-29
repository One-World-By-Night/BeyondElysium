<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Display\Cross_Block_Ref;
use PHPUnit\Framework\TestCase;

/**
 * The Virtues a Dark Ages Road, and each of its paths, names on a vampire's sheet.
 */
class DarkAgesRoadVirtuesTest extends TestCase {

	/** @var array<string,object> */
	private static array $pools = [];

	public static function setUpBeforeClass(): void {
		foreach ( Seeder::get_blocks_to_seed() as $block ) {
			if ( $block['slug'] === 'vampire-virtues' ) {
				foreach ( $block['definition']['pools'] as $pool ) {
					self::$pools[ $pool['name'] ] = json_decode( (string) wp_json_encode( $pool ) );
				}
			}
		}
	}

	/**
	 * The two Virtue names a character on `$path` sees, with any explicit choice in `$identity`.
	 *
	 * @param array<string,string> $identity
	 * @return array{0:string,1:string}
	 */
	private function virtues( string $path, array $identity = [] ): array {
		$sheet = [ 'vampire-identity' => [ 'Morality Path' => $path ] + $identity ];
		return [
			Cross_Block_Ref::resolve_pool_name( self::$pools['Conscience'], $sheet ),
			Cross_Block_Ref::resolve_pool_name( self::$pools['Self-Control'], $sheet ),
		];
	}

	/** @return array<string,array{0:string,1:string,2:string}> */
	public static function roads(): array {
		return [
			'the Beast'        => [ 'Road of the Beast', 'Conviction', 'Instinct' ],
			'Heaven'           => [ 'Road of Heaven', 'Conscience', 'Self-Control' ],
			'Humanity'         => [ 'Road of Humanity', 'Conscience', 'Self-Control' ],
			'Kings'            => [ 'Road of Kings', 'Conviction', 'Self-Control' ],
			'Lilith'           => [ 'Road of Lilith', 'Conviction', 'Instinct' ],
			'Metamorphosis'    => [ 'Road of Metamorphosis', 'Conviction', 'Instinct' ],
			'Sin'              => [ 'Road of Sin', 'Conviction', 'Instinct' ],
			'Bones'            => [ 'Road of Bones', 'Conviction', 'Self-Control' ],
			'Yasa'             => [ 'Road of Yasa', 'Conviction', 'Self-Control' ],
			'Via Bestiae'      => [ 'Via Bestiae', 'Conviction', 'Instinct' ],
			'Via Caeli'        => [ 'Via Caeli', 'Conscience', 'Self-Control' ],
			'Via Humanitas'    => [ 'Via Humanitas', 'Conscience', 'Self-Control' ],
			'Via Regalis'      => [ 'Via Regalis', 'Conviction', 'Self-Control' ],
			'Via Mutationis'   => [ 'Via Mutationis', 'Conviction', 'Instinct' ],
			'Via Peccati'      => [ 'Via Peccati', 'Conviction', 'Instinct' ],
			'Via Ossium'       => [ 'Via Ossium', 'Conviction', 'Self-Control' ],
			'Via Yasaq'        => [ 'Via Yasaq', 'Conviction', 'Self-Control' ],
		];
	}

	/**
	 * @dataProvider roads
	 */
	public function test_each_road_names_its_two_virtues( string $road, string $first, string $second ): void {
		$this->assertSame( [ $first, $second ], $this->virtues( $road ) );
	}

	/** @return array<string,array{0:string,1:string,2:string}> */
	public static function paths(): array {
		return [
			'the Hunter'          => [ 'Path of the Hunter', 'Conviction', 'Instinct' ],
			'Journeys'            => [ 'Path of Journeys', 'Conviction', 'Self-Control' ],
			'Liberation'          => [ 'Path of Liberation', 'Conviction', 'Instinct' ],
			'Christ'              => [ 'Path of Christ', 'Conscience', 'Self-Control' ],
			'Derech Chaim'        => [ 'Derech Chaim', 'Conscience', 'Self-Control' ],
			'Life'                => [ 'Path of Life', 'Conscience', 'Self-Control' ],
			'the Prophet'         => [ 'Path of the Prophet', 'Conscience', 'Self-Control' ],
			'the Eightfold Wheel' => [ 'Path of the Eightfold Wheel', 'Conscience', 'Self-Control' ],
			'Watchful Gods'       => [ 'Path of Watchful Gods', 'Conscience', 'Self-Control' ],
			'Breath'              => [ 'Path of Breath', 'Conscience', 'Self-Control' ],
			'Community'           => [ 'Path of Community', 'Conscience', 'Self-Control' ],
			'Illumination'        => [ 'Path of Illumination', 'Conscience', 'Self-Control' ],
			'Chivalry'            => [ 'Path of Chivalry', 'Conscience', 'Self-Control' ],
			'Devaraja'            => [ 'Path of Devaraja', 'Conviction', 'Self-Control' ],
			'Daena'               => [ 'Path of Daena', 'Conviction', 'Self-Control' ],
			'Thorns'              => [ 'Path of Thorns', 'Conviction', 'Instinct' ],
			'Veils'               => [ 'Path of Veils', 'Conviction', 'Instinct' ],
			'Making'              => [ 'Path of Making', 'Conviction', 'Instinct' ],
			'Flesh'               => [ 'Path of Flesh', 'Conviction', 'Instinct' ],
			'Spirit'              => [ 'Path of Spirit', 'Conviction', 'Instinct' ],
			'Pleasure'            => [ 'Path of Pleasure', 'Conviction', 'Instinct' ],
			'the Devil'           => [ 'Path of the Devil', 'Conviction', 'Self-Control' ],
			'Screams'             => [ 'Path of Screams', 'Conviction', 'Instinct' ],
			'the Scholar'         => [ 'Path of the Scholar', 'Conviction', 'Self-Control' ],
		];
	}

	/**
	 * @dataProvider paths
	 */
	public function test_each_path_of_a_road_names_its_own_two_virtues( string $path, string $first, string $second ): void {
		$this->assertSame( [ $first, $second ], $this->virtues( $path ) );
	}

	public function test_a_road_is_read_the_way_players_type_it(): void {
		$this->assertSame( [ 'Conviction', 'Instinct' ], $this->virtues( 'Sin' ) );
		$this->assertSame( [ 'Conscience', 'Self-Control' ], $this->virtues( 'Heaven (Righteousness)' ) );
		$this->assertSame( [ 'Conscience', 'Self-Control' ], $this->virtues( 'Eightfold Wheel' ) );
		$this->assertSame( [ 'Conscience', 'Self-Control' ], $this->virtues( 'Watchful Gods' ) );
		$this->assertSame( [ 'Conviction', 'Self-Control' ], $this->virtues( 'The Road of Kings' ) );
	}

	public function test_a_road_from_another_book_names_both_virtues(): void {
		$this->assertSame( [ 'Conscience/Conviction', 'Self-Control/Instinct' ], $this->virtues( 'Road of Blood' ) );
	}

	public function test_a_chosen_virtue_wins_over_the_road(): void {
		$this->assertSame( [ 'Conscience', 'Self-Control' ], $this->virtues( 'Road of Sin', [ 'Conscience or Conviction' => 'Conscience', 'Self-Control or Instinct' => 'Self-Control' ] ) );
	}
}
