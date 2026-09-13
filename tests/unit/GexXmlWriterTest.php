<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GEX_Xml_Writer;
use PHPUnit\Framework\TestCase;

/**
 * GX-3: `GEX_Xml_Writer`, the PHP port of Grapevine's own `XMLWriterClass`.
 * Every behavior here is cited to `GV301Source/Code/XMLWriterClass.cls`.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-3, GX-10
 */
class GexXmlWriterTest extends TestCase {

	public function test_a_tag_with_no_children_self_closes(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'rote' )->write_attribute( 'name', 'Test' )->end_tag();

		$this->assertStringContainsString( '<rote name="Test"/>', $w->render() );
		$this->assertStringNotContainsString( '</rote>', $w->render() );
	}

	public function test_a_tag_with_a_child_opens_and_closes(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'grapevine' )->write_attribute( 'version', '2.399' );
		$w->begin_tag( 'rote' )->write_attribute( 'name', 'Test' )->end_tag();
		$w->end_tag();

		$xml = $w->render();
		$this->assertStringContainsString( '<grapevine version="2.399">', $xml );
		$this->assertStringContainsString( '</grapevine>', $xml );
	}

	public function test_an_attribute_equal_to_its_omit_value_is_never_written(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'trait' )
			->write_attribute( 'val', '1', '1' )
			->write_attribute( 'note', 'kept', '' )
			->end_tag();

		$xml = $w->render();
		$this->assertStringNotContainsString( 'val=', $xml );
		$this->assertStringContainsString( 'note="kept"', $xml );
	}

	public function test_an_attribute_not_equal_to_its_omit_value_is_written(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'trait' )->write_attribute( 'val', '3', '1' )->end_tag();

		$this->assertStringContainsString( 'val="3"', $w->render() );
	}

	public function test_a_boolean_true_writes_yes_and_false_writes_no(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'vampire' )
			->write_attribute( 'npc', true, false )
			->end_tag();
		$this->assertStringContainsString( 'npc="yes"', $w->render() );

		$w2 = new GEX_Xml_Writer();
		$w2->begin_tag( 'vampire' )->write_attribute( 'npc', false, false )->end_tag();
		// false === the omit value false, so it must be entirely absent, matching
		// VampireClass.cls:385's real WriteAttribute "npc", IsNPC, False.
		$this->assertStringNotContainsString( 'npc=', $w2->render() );
	}

	public function test_a_zero_omit_default_still_suppresses_a_literal_zero(): void {
		// WerewolfClass.cls's real WriteAttribute "notoriety", Notoriety, 0 - the
		// classic PHP falsy-comparison trap this must NOT fall into, since a real
		// notoriety of 0 is the overwhelmingly common case and must be omitted.
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'werewolf' )->write_attribute( 'notoriety', 0, 0 )->end_tag();
		$this->assertStringNotContainsString( 'notoriety=', $w->render() );

		$w2 = new GEX_Xml_Writer();
		$w2->begin_tag( 'werewolf' )->write_attribute( 'notoriety', 5, 0 )->end_tag();
		$this->assertStringContainsString( 'notoriety="5"', $w2->render() );
	}

	public function test_aurabonus_is_the_attribute_name_never_a_second_aura(): void {
		// The deliberate correction over VampireClass.cls:375-376's real duplicate-'aura'
		// write bug (gex-export-transfer-design.md §2d).
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'vampire' )
			->write_attribute( 'aura', 'Serene' )
			->write_attribute( 'aurabonus', '+2', '+0' )
			->end_tag();

		$xml = $w->render();
		$this->assertStringContainsString( 'aura="Serene"', $xml );
		$this->assertStringContainsString( 'aurabonus="+2"', $xml );
		// 'aurabonus=' itself contains the substring 'aura' but not 'aura=' (no equals
		// sign right after "aura") - counting occurrences of 'aura=' this way correctly
		// finds exactly one real `aura=` attribute plus zero stray duplicates, proving
		// the real Grapevine bug (two attributes both named literally "aura") isn't
		// reproduced.
		$this->assertSame( 1, substr_count( $xml, 'aura=' ) );
		$this->assertSame( 1, substr_count( $xml, 'aurabonus=' ) );
	}

	public function test_a_cdata_tag_is_skipped_entirely_when_the_data_is_empty(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'vampire' )->write_cdata_tag( 'biography', '' )->end_tag();

		$this->assertStringNotContainsString( 'biography', $w->render() );
	}

	public function test_a_cdata_tag_wraps_real_data(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'vampire' )->write_cdata_tag( 'notes', 'Some real notes.' )->end_tag();

		$this->assertStringContainsString( '<![CDATA[Some real notes.]]>', $w->render() );
	}

	public function test_a_literal_close_cdata_sequence_inside_the_data_is_broken_up(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'vampire' )->write_cdata_tag( 'notes', 'weird]]>data' )->end_tag();

		$xml = $w->render();
		$this->assertStringContainsString( 'weird]] >data', $xml );
		$this->assertStringNotContainsString( 'weird]]>data', $xml );
	}

	public function test_attribute_values_are_escaped_for_ampersand_lt_gt_and_quote(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'vampire' )->write_attribute( 'title', 'Fixer & "Bad" <One>' )->end_tag();

		$this->assertStringContainsString( 'title="Fixer &amp; &quot;Bad&quot; &lt;One&gt;"', $w->render() );
	}

	public function test_non_ascii_is_transliterated_and_reported(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'vampire' )->write_attribute( 'title', "Dominion of Shadow\u{2019}s Vigil" )->end_tag();

		$xml = $w->render();
		$this->assertStringContainsString( "Dominion of Shadow's Vigil", $xml );
		$this->assertStringNotContainsString( "\u{2019}", $xml );
		$this->assertNotEmpty( $w->transliterations() );
		$this->assertStringContainsString( 'Vigil', $w->transliterations()[0] );
	}

	public function test_pure_ascii_data_is_never_reported_as_transliterated(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'vampire' )->write_attribute( 'title', 'Plain ASCII Title' )->end_tag();
		$w->render();

		$this->assertSame( [], $w->transliterations() );
	}

	public function test_the_prolog_declares_iso_8859_1(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'grapevine' )->write_attribute( 'version', '2.399' )->end_tag();

		$this->assertStringStartsWith( '<?xml version="1.0" encoding="ISO-8859-1"?>', $w->render() );
	}

	public function test_rendering_with_no_tag_ever_opened_throws(): void {
		$this->expectException( \RuntimeException::class );
		( new GEX_Xml_Writer() )->render();
	}

	/**
	 * The real round-trip proof: a document this writer produces must be
	 * readable by our own reader with the values unchanged.
	 */
	public function test_a_written_document_parses_back_through_our_own_reader(): void {
		$w = new GEX_Xml_Writer();
		$w->begin_tag( 'grapevine' )->write_attribute( 'version', '2.399' );
		$w->begin_tag( 'hunter' );
		$w->write_attribute( 'name', 'Round Trip' );
		$w->write_attribute( 'creed', 'Defender' );
		$w->write_attribute( 'nature', 'Bravo' );
		$w->write_attribute( 'demeanor', 'Bravo' );
		$w->write_attribute( 'camp', '' );
		$w->write_attribute( 'handle', '' );
		$w->write_attribute( 'conviction', 3 );
		$w->write_attribute( 'willpower', 5 );
		$w->write_attribute( 'mercy', 0 );
		$w->write_attribute( 'vision', 0 );
		$w->write_attribute( 'zeal', 0 );
		$w->write_attribute( 'physicalmax', 5 );
		$w->write_attribute( 'socialmax', 5 );
		$w->write_attribute( 'mentalmax', 5 );
		$w->begin_tag( 'experience' )->write_attribute( 'unspent', 0 )->write_attribute( 'earned', 0 )->end_tag();
		$w->begin_tag( 'traitlist' )
			->write_attribute( 'name', 'Merits' )
			->write_attribute( 'abc', true )
			->write_attribute( 'atomic', true )
			->write_attribute( 'negative', false, false )
			->write_attribute( 'display', 4 );
		$w->begin_tag( 'trait' )->write_attribute( 'name', 'Common Sense' )->write_attribute( 'val', '1', '1' )->end_tag();
		$w->end_tag(); // traitlist
		$w->write_cdata_tag( 'notes', 'Exported by the round-trip test.' );
		$w->end_tag(); // hunter
		$w->end_tag(); // grapevine

		$data      = \BeyondElysium\Services\GEX_Xml_Parser::parse_string( $w->render() );
		$character = $data['characters'][0];

		$this->assertSame( 'hunter', $character['race'] );
		$this->assertSame( 'Round Trip', $character['name'] );
		$this->assertSame( 3, $character['conviction'] );
		$this->assertSame( 'Exported by the round-trip test.', $character['notes'] );
		$this->assertSame( '1', $character['trait_lists']['Merits']['traits'][0]['total'] );
	}
}
