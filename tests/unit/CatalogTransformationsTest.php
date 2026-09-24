<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The catalog's rulings, asserted against the declared files under `data/catalog/blocks/`.
 */
class CatalogTransformationsTest extends TestCase {

	private const BLOCKS = BE_PLUGIN_PATH . '/data/catalog/blocks/';

	/** @return array<string,mixed> */
	private function block( string $slug ): array {
		$path = self::BLOCKS . $slug . '.json';
		$this->assertFileExists( $path );
		$data = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $data, "{$slug}.json does not decode" );
		return $data;
	}

	/**
	 * A block's families keyed by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function powers( string $slug ): array {
		return $this->index( $this->block( $slug )['definition']['powers'] );
	}

	/** @return array<string,array<string,mixed>> */
	private function index( array $families ): array {
		$this->assertTrue( array_is_list( $families ), 'definition.powers must be a list of family objects' );
		$keyed = [];
		foreach ( $families as $family ) {
			$keyed[ (string) $family['name'] ] = $family;
		}
		return $keyed;
	}

	/** @return string[] */
	private function rung_names( array $family ): array {
		return array_map( static fn( $level ) => $level['power_name'], $family['levels'] );
	}

	/** @return array<string,array<string,mixed>> Every family carrying a rung named $power, keyed "slug:family". */
	private function families_holding( string $power, array $slugs ): array {
		$found = [];
		foreach ( $slugs as $slug ) {
			foreach ( $this->powers( $slug ) as $name => $family ) {
				if ( in_array( $power, $this->rung_names( $family ), true ) ) {
					$found[ "{$slug}:{$name}" ] = $family;
				}
			}
		}
		return $found;
	}

	/**
	 * T-T4, as ruled.
	 */
	public function test_path_of_blood_s_curse_is_one_path_and_path_of_curses_is_another(): void {
		$bm = $this->powers( 'vampire-blood-magic' );

		$this->assertArrayHasKey( "Path of Blood's Curse", $bm );
		$this->assertSame(
			[ 'Ravages of the Beast', 'Weight of the Sun', 'Abated Tooth', 'Treacherous Bonds', 'The Withering of Ages' ],
			$this->rung_names( $bm["Path of Blood's Curse"] )
		);
		$this->assertSame(
			[ 'Stigmatize', 'Malady', 'Pariah', 'Corrupt Body', 'Fall From Grace' ],
			$this->rung_names( $bm['Path of Curses'] )
		);

		foreach ( [ 'vampire-blood-magic', 'vampire-disciplines' ] as $slug ) {
			foreach ( $this->powers( $slug ) as $name => $family ) {
				$rungs = $this->rung_names( $family );
				$this->assertFalse(
					in_array( 'Ravages of the Beast', $rungs, true ) && in_array( 'Stigmatize', $rungs, true ),
					"{$slug}:{$name} holds both paths again - D67 re-fused"
				);
			}
		}

		// B: three copies became one, and the other two names still resolve to it.
		$this->assertCount( 1, $this->families_holding( 'Ravages of the Beast', [ 'vampire-blood-magic', 'vampire-disciplines' ] ) );
		$this->assertContains( "Blood's Curse", $bm["Path of Blood's Curse"]['aliases'] );
		$this->assertContains(
			[ 'block' => 'vampire-disciplines', 'name' => "Path of the Blood's Curse" ],
			$bm["Path of Blood's Curse"]['moved_from']
		);
		$this->assertArrayNotHasKey( "Blood's Curse", $bm );
	}

	/**
	 * T-T5: Valeren's duplicate opening rungs are gone and Healer/Warrior still stand alone.
	 */
	public function test_valeren_loses_its_duplicate_rungs_and_keeps_its_two_paths(): void {
		$vd = $this->powers( 'vampire-disciplines' );

		$this->assertSame(
			[ 'Sense Vitality', 'Anesthetic Touch', 'Burning Touch', 'Ending the Watch', 'Vengeance of Samiel' ],
			$this->rung_names( $vd['Valeren'] )
		);
		// The misspellings survive only as aliases.
		$this->assertSame( [ 'Sense Vitaility' ], $vd['Valeren']['levels'][0]['aliases'] );
		$this->assertSame( [ 'Anethetic Touch' ], $vd['Valeren']['levels'][1]['aliases'] );

		$this->assertArrayHasKey( 'Healer', $vd );
		$this->assertArrayHasKey( 'Warrior', $vd );
		$this->assertCount( 5, $vd['Healer']['levels'] );
		$this->assertCount( 5, $vd['Warrior']['levels'] );
	}

	public function test_misfiled_paths_leave_the_discipline_block_and_are_recorded_where_they_land(): void {
		$vd    = $this->powers( 'vampire-disciplines' );
		$bm    = $this->powers( 'vampire-blood-magic' );
		$moved = [
			'Creo Ignem'             => 'Lure of Flames',
			'Creo Materia'           => 'Path of Conjuring',
			'Creo Motus'             => 'Movement of the Mind',
			'Rego Motus'             => 'Movement of the Mind',
			'Creo Tempestas'         => 'Weather Control',
			'Rego Tempestas'         => 'Weather Control',
			'Perdo Materia'          => 'Hands of Destruction',
			'Rego Elementum'         => 'Elemental Mastery',
			'Rego Vitae'             => 'Path of Blood',
			'Path of Shadowcrafting' => 'Path of Shadow Crafting',
			'Path of the Levinbolt'  => 'Path of Levinbolt',
			'Technomancy'            => 'Path of Technomancy',
			'Awakening of the Steel' => 'Awakening the Steel',
			'Cenotaph Path'          => 'Cenotaph',
		];
		foreach ( $moved as $old => $new ) {
			$this->assertArrayNotHasKey( $old, $vd, "{$old} is still a Discipline" );
			$this->assertArrayHasKey( $new, $bm );
			$this->assertContains( [ 'block' => 'vampire-disciplines', 'name' => $old ], $bm[ $new ]['moved_from'] );
		}
	}

	/**
	 * The edition rule: a 'dark ages' or '2nd ed.' ladder-rank level leaves the base family for its variant file, and the
	 * variant family says where it came from.
	 */
	public function test_edition_printings_live_in_their_variant_files_with_their_origin(): void {
		$base = $this->powers( 'vampire-disciplines' );
		$this->assertNotContains( 'Feral Speech', $this->rung_names( $base['Animalism'] ) );

		$dark = $this->block( 'darkages-vampire_disciplines' );
		$this->assertSame( 'vampire-disciplines', $dark['variant']['of'] );
		$this->assertSame( 'dark-ages', $dark['variant']['id'] );
		$family = $this->index( $dark['definition']['powers'] )['Animalism (Dark Ages)'];
		$this->assertSame( 'Animalism', $family['split_from'] );
		$this->assertSame(
			[ "Feral Speech", "Noah's Call", 'Cowing the Beast', 'Ride the Wild Mind', 'Drawing Out the Beast' ],
			$this->rung_names( $family )
		);

		foreach ( glob( self::BLOCKS . '*.json' ) ?: [] as $path ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ! isset( $data['variant'] ) ) {
				continue;
			}
			$this->assertFileExists( self::BLOCKS . $data['variant']['of'] . '.json', basename( $path ) . ' varies a base that does not exist' );
			if ( ( $data['section_type'] ?? '' ) !== 'tiered_power' ) {
				continue;
			}
			$base_powers = $this->powers( $data['variant']['of'] );
			foreach ( $this->index( $data['definition']['powers'] ) as $name => $family ) {
				if ( isset( $family['split_from'] ) ) {
					$this->assertArrayHasKey( $family['split_from'], $base_powers, basename( $path ) . ": {$name} splits from a family the base does not have" );
				}
			}
		}
	}

	/**
	 * An alias must never collide with a live name in the same family.
	 */
	public function test_no_alias_shadows_a_live_rung_or_family(): void {
		foreach ( glob( self::BLOCKS . '*.json' ) ?: [] as $path ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ( $data['section_type'] ?? '' ) !== 'tiered_power' ) {
				continue;
			}
			$families = $this->index( $data['definition']['powers'] );
			foreach ( $families as $name => $family ) {
				foreach ( $family['aliases'] ?? [] as $alias ) {
					$this->assertArrayNotHasKey( $alias, $families, basename( $path ) . ": family alias {$alias} is also a live family" );
				}
				$rungs = $this->rung_names( $family );
				foreach ( $family['levels'] as $level ) {
					foreach ( $level['aliases'] ?? [] as $alias ) {
						$this->assertNotContains( $alias, $rungs, basename( $path ) . ": {$name} rung alias {$alias} is also a live rung" );
					}
				}
			}
		}
	}

	/**
	 * Book costs, per `_meta`.
	 */
	public function test_book_costs_are_declared(): void {
		$this->assertSame( [ 'basic' => 4, 'intermediate' => 8, 'advanced' => 12 ], $this->block( 'mage-spheres' )['definition']['_meta']['costs'] );
		$this->assertSame( [ 'basic' => '+1', 'intermediate' => '+2', 'advanced' => '+3' ], $this->block( 'mage-spheres' )['definition']['_meta']['out_of_type'] );
		$this->assertSame( [ 'basic' => 4, 'intermediate' => 7, 'advanced' => 10 ], $this->block( 'kueijin-disciplines' )['definition']['_meta']['costs'] );
		$this->assertNull( $this->block( 'kueijin-disciplines' )['definition']['_meta']['out_of_type'] );
		$this->assertSame(
			[ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9, 'elder' => 12, 'master' => 15, 'ascended' => 18, 'methuselah' => 21 ],
			$this->block( 'vampire-disciplines' )['definition']['_meta']['costs']
		);
	}

	public function test_kuei_jin_owner_rulings_are_in_the_file(): void {
		$kj = $this->powers( 'kueijin-disciplines' );
		$this->assertArrayNotHasKey( 'Black Wind', $kj );
		foreach ( [ "Black Wind: Hell's Howling Typhoon", 'Black Wind: Ten Thousand Steps', 'Black Wind: Tiger Slashing Heaven' ] as $aspect ) {
			$this->assertArrayHasKey( $aspect, $kj );
			$this->assertSame( 'Black Wind', $kj[ $aspect ]['split_from'] );
		}
		$this->assertSame( [ 'Master Flow', 'Adjust Balance', 'Shift the Balance', 'Chi Interrupt', 'Chi Mastery' ], $this->rung_names( $kj['Equilibrium'] ) );
		$this->assertCount( 14, $kj['Demon Shintai']['options'] );
	}
}
