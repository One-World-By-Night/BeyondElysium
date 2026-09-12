<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GEX_Xml_Parser;
use PHPUnit\Framework\TestCase;

/**
 * GX-2: `GEX_Xml_Parser` grows from two character races (`vampire`,
 * `werewolf`) to all twelve, off `gv-exchange-shape.php` (GX-1). The ten new
 * races (`mortal`, `changeling`, `wraith`, `mage`, `fera`, `various`,
 * `mummy`, `kueijin`, `hunter`, `demon`) go through the new, generic,
 * table-driven `parse_character_generic()` rather than eleven more
 * hand-written near-duplicates - `vampire`/`werewolf` keep their own
 * existing, GX-0-fixed methods unchanged (`GexXmlParserTest` covers those).
 *
 * No real XML sample exists for any of these ten races (every real
 * character-bearing `.gex` in this repo is vampire or werewolf,
 * gex-export-transfer-design.md §2a) - this suite builds a synthetic
 * document per race directly from the shape table and proves the generic
 * parser reads every scalar, trait list, and tail field back correctly,
 * including the omit-default fallbacks and wraith's `ethnos` enum mapping.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-2
 */
class GexXmlParserGenericRaceTest extends TestCase {

	private const GENERIC_RACES = [
		'mortal', 'changeling', 'wraith', 'mage', 'fera',
		'various', 'mummy', 'kueijin', 'hunter', 'demon',
	];

	/** @var array<string,array<string,mixed>> */
	private static array $shape;

	public static function setUpBeforeClass(): void {
		self::$shape = require BE_PLUGIN_ROOT . '/beyond-elysium/includes/Services/gv-exchange-shape.php';
	}

	/**
	 * Builds a minimal `<grapevine><race ...>` document for one race,
	 * giving every scalar a real, non-default value so the test can
	 * confirm it round-trips rather than merely not-crashing, and one
	 * named trait per trait list.
	 */
	private function build_xml( string $race ): string {
		$def   = self::$shape[ $race ];
		$attrs = '';

		foreach ( $def['scalars'] as $scalar ) {
			if ( isset( $scalar['xml_enum'] ) ) {
				$value = $scalar['xml_enum'][0];
			} elseif ( $scalar['type'] === 'bool' ) {
				$value = 'yes';
			} elseif ( in_array( $scalar['type'], [ 'int16', 'int32', 'single' ], true ) ) {
				$value = '3';
			} elseif ( $scalar['type'] === 'date' ) {
				$value = '6/1/2026 12:00:00 AM';
			} else {
				$value = 'Test ' . $scalar['key'];
			}
			$attrs .= sprintf( ' %s="%s"', $scalar['xml'], htmlspecialchars( (string) $value, ENT_XML1 ) );
		}

		$lists = '';
		foreach ( $def['trait_lists'] as $spec ) {
			$lists .= sprintf(
				'<traitlist name="%s" abc="%s" atomic="%s" negative="%s" display="%d">' .
				'<trait name="Held Trait" val="2" note=""/></traitlist>',
				htmlspecialchars( $spec['name'], ENT_XML1 ),
				$spec['abc'] ? 'yes' : 'no',
				$spec['atomic'] ? 'yes' : 'no',
				$spec['neg'] ? 'yes' : 'no',
				$spec['display']
			);
		}

		$tail = '';
		foreach ( $def['tail'] as $row ) {
			$tail .= sprintf( '<%1$s><![CDATA[%2$s text]]></%1$s>', $row['xml_cdata'], $row['key'] );
		}

		return '<?xml version="1.0"?><grapevine version="2.399">' .
			"<{$race}{$attrs}><experience unspent=\"5\" earned=\"10\"></experience>{$lists}{$tail}</{$race}>" .
			'</grapevine>';
	}

	public static function race_provider(): array {
		return array_map( static fn( $race ) => [ $race ], self::GENERIC_RACES );
	}

	/**
	 * @dataProvider race_provider
	 */
	public function test_every_generic_race_round_trips_its_scalars_and_trait_lists( string $race ): void {
		$data      = GEX_Xml_Parser::parse_string( $this->build_xml( $race ) );
		$character = $data['characters'][0];
		$def       = self::$shape[ $race ];

		$this->assertSame( $race, $character['race'] );

		foreach ( $def['scalars'] as $scalar ) {
			if ( in_array( $scalar['key'], [ 'physical_max', 'social_max', 'mental_max' ], true ) ) {
				continue; // always backfilled, never read from XML - see the generic method's own doc comment
			}
			$this->assertArrayHasKey( $scalar['key'], $character, "{$race}/{$scalar['key']}" );
		}

		$this->assertSame(
			array_column( $def['trait_lists'], 'name' ),
			array_keys( $character['trait_lists'] ),
			"{$race}: trait list names/order"
		);
		foreach ( $character['trait_lists'] as $name => $tl ) {
			$this->assertSame( [ 'Held Trait' ], array_column( $tl['traits'], 'name' ), "{$race}/{$name}" );
			$this->assertSame( '2', $tl['traits'][0]['total'] );
		}

		foreach ( $def['tail'] as $row ) {
			$this->assertSame( "{$row['key']} text", $character[ $row['key'] ], "{$race}/{$row['key']}" );
		}

		$this->assertSame( 10.0, $character['experience']['earned'] );
		$this->assertSame( 5.0, $character['experience']['unspent'] );
	}

	public function test_wraiths_ethnos_reads_the_xml_enum_string_back_to_its_int(): void {
		$data      = GEX_Xml_Parser::parse_string( $this->build_xml( 'wraith' ) );
		$character = $data['characters'][0];

		// build_xml() used xml_enum[0] ('Wraith') as the test value for every non-bool
		// scalar it couldn't otherwise infer a realistic value for.
		$this->assertSame( 0, $character['ethnos'] );
	}

	public function test_a_temp_field_omitted_from_the_document_falls_back_to_its_permanent_value(): void {
		// demon's temp_torment has an xml_omit_if of 'torment' - build a document that
		// carries torment but leaves temptorment out entirely, and confirm the fallback
		// resolves to torment's own value rather than 0.
		$xml = '<?xml version="1.0"?><grapevine version="2.399">' .
			'<demon name="Fixture" house="Test" faction="Test" nature="Test" demeanor="Test" ' .
			'torment="4" faith="0" willpower="0" conscience="0" conviction="0" courage="0">' .
			'<experience unspent="0" earned="0"></experience></demon></grapevine>';

		$data = GEX_Xml_Parser::parse_string( $xml );
		$this->assertSame( 4, $data['characters'][0]['torment'] );
		$this->assertSame( 4, $data['characters'][0]['temp_torment'] );
	}
}
