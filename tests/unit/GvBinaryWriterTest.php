<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GV_Binary_Reader;
use BeyondElysium\Services\GV_Binary_Writer;
use PHPUnit\Framework\TestCase;

/**
 * GX-5: `GV_Binary_Writer` - every primitive round-tripped through the real
 * `GV_Binary_Reader`, proving the writer is a genuine byte-for-byte inverse
 * rather than merely "close enough."
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-5
 */
class GvBinaryWriterTest extends TestCase {

	private function reader( GV_Binary_Writer $writer ): GV_Binary_Reader {
		return new GV_Binary_Reader( $writer->bytes(), 'test' );
	}

	public function test_int16_round_trips_positive_and_negative(): void {
		$w = ( new GV_Binary_Writer() )->int16( 12345 )->int16( -12345 )->int16( 0 )->int16( 32767 )->int16( -32768 );
		$r = $this->reader( $w );

		$this->assertSame( 12345, $r->int16() );
		$this->assertSame( -12345, $r->int16() );
		$this->assertSame( 0, $r->int16() );
		$this->assertSame( 32767, $r->int16() );
		$this->assertSame( -32768, $r->int16() );
		$this->assertTrue( $r->eof() );
	}

	public function test_int32_round_trips_positive_and_negative(): void {
		$w = ( new GV_Binary_Writer() )->int32( 2000000000 )->int32( -2000000000 )->int32( 0 );
		$r = $this->reader( $w );

		$this->assertSame( 2000000000, $r->int32() );
		$this->assertSame( -2000000000, $r->int32() );
		$this->assertSame( 0, $r->int32() );
	}

	public function test_double_round_trips(): void {
		$w = ( new GV_Binary_Writer() )->double( 3.14159265358979 )->double( -273.15 )->double( 0.0 );
		$r = $this->reader( $w );

		$this->assertEqualsWithDelta( 3.14159265358979, $r->double(), 0.0000000001 );
		$this->assertEqualsWithDelta( -273.15, $r->double(), 0.0000000001 );
		$this->assertSame( 0.0, $r->double() );
	}

	public function test_single_round_trips_at_single_precision(): void {
		$w = ( new GV_Binary_Writer() )->single( 3.5 )->single( -1.25 );
		$r = $this->reader( $w );

		$this->assertEqualsWithDelta( 3.5, $r->single(), 0.0001 );
		$this->assertEqualsWithDelta( -1.25, $r->single(), 0.0001 );
	}

	public function test_bool_round_trips_as_vb6_minus_one_and_zero(): void {
		$w = ( new GV_Binary_Writer() )->bool( true )->bool( false );
		$r = $this->reader( $w );

		$this->assertTrue( $r->bool() );
		$this->assertFalse( $r->bool() );
	}

	public function test_string_round_trips_including_empty(): void {
		$w = ( new GV_Binary_Writer() )->string( 'Hitchens' )->string( '' )->string( 'a "quoted" name' );
		$r = $this->reader( $w );

		$this->assertSame( 'Hitchens', $r->string() );
		$this->assertSame( '', $r->string() );
		$this->assertSame( 'a "quoted" name', $r->string() );
	}

	public function test_string_truncates_at_32767_bytes_rather_than_wrapping_the_length_prefix(): void {
		$huge = str_repeat( 'x', 40000 );
		$w    = new GV_Binary_Writer();
		$w->string( $huge );

		$r = $this->reader( $w );
		$this->assertSame( 32767, strlen( $r->string() ) );
	}

	public function test_string_transliterates_non_latin1_characters_rather_than_corrupting_the_length_prefix(): void {
		$w = ( new GV_Binary_Writer() )->string( 'Café Müller — a dash' );
		$r = $this->reader( $w );

		// Whatever survives round-trips cleanly - the point is no exception and no desync,
		// not preserving characters ISO-8859-1 can't represent in the first place.
		$this->assertIsString( $r->string() );
		$this->assertTrue( $r->eof() );
	}

	public function test_date_round_trips_a_real_date(): void {
		$w = ( new GV_Binary_Writer() )->date( '2026-09-12 15:30:00' );
		$r = $this->reader( $w );

		$this->assertSame( '2026-09-12 15:30:00', $r->date() );
	}

	public function test_date_writes_zero_for_null(): void {
		$w = ( new GV_Binary_Writer() )->date( null );
		$r = $this->reader( $w );

		$this->assertNull( $r->date() );
	}

	public function test_date_writes_zero_for_empty_string(): void {
		$w = ( new GV_Binary_Writer() )->date( '' );
		$r = $this->reader( $w );

		$this->assertNull( $r->date() );
	}

	public function test_date_round_trips_midnight_exactly(): void {
		$w = ( new GV_Binary_Writer() )->date( '2026-01-01 00:00:00' );
		$r = $this->reader( $w );

		$this->assertSame( '2026-01-01 00:00:00', $r->date() );
	}

	public function test_a_mixed_sequence_of_primitives_stays_in_sync(): void {
		$w = new GV_Binary_Writer();
		$w->string( 'Sabbat' )->int16( 5 )->bool( true )->double( 1.5 )->int32( -1 )->date( '2020-06-15 08:00:00' )->string( 'end' );

		$r = $this->reader( $w );
		$this->assertSame( 'Sabbat', $r->string() );
		$this->assertSame( 5, $r->int16() );
		$this->assertTrue( $r->bool() );
		$this->assertSame( 1.5, $r->double() );
		$this->assertSame( -1, $r->int32() );
		$this->assertSame( '2020-06-15 08:00:00', $r->date() );
		$this->assertSame( 'end', $r->string() );
		$this->assertTrue( $r->eof() );
	}
}
