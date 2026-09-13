<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Byte-level writer for Grapevine's VB6 binary file formats - the exact
 * inverse of `GV_Binary_Reader`, one primitive-writing method per
 * primitive-reading method there, same field widths and byte order.
 *
 * @see BeyondElysium\Services\GV_Binary_Reader
 * @see BE_PROCESS/gex-export-transfer-design.md GX-5
 */
class GV_Binary_Writer {

	/** @var string Accumulated output bytes. */
	private string $data = '';

	/**
	 * Writes a VB6 Integer field: 2 bytes, little-endian, signed. A negative
	 * value is converted to its unsigned 16-bit two's-complement bit
	 * pattern before packing, the exact inverse of `int16()`'s
	 * above-32767-means-negative conversion.
	 *
	 * @param int $value
	 * @return $this
	 */
	public function int16( int $value ): self {
		$this->data .= pack( 'v', $value < 0 ? $value + 65536 : $value );
		return $this;
	}

	/**
	 * Writes a VB6 Long field: 4 bytes, little-endian, signed - also used
	 * for enum values, which VB6 stores at the same width.
	 *
	 * @param int $value
	 * @return $this
	 */
	public function int32( int $value ): self {
		$this->data .= pack( 'V', $value < 0 ? $value + 4294967296 : $value );
		return $this;
	}

	/**
	 * Writes a VB6 Double field: 8 bytes, IEEE 754 little-endian.
	 *
	 * @param float $value
	 * @return $this
	 */
	public function double( float $value ): self {
		$this->data .= pack( 'e', $value );
		return $this;
	}

	/**
	 * Writes a VB6 Single field: 4 bytes, IEEE 754 little-endian, half the
	 * width of `double()`.
	 *
	 * @param float $value
	 * @return $this
	 */
	public function single( float $value ): self {
		$this->data .= pack( 'g', $value );
		return $this;
	}

	/**
	 * Writes a VB6 Boolean field: 2 bytes, `-1` for true and `0` for false -
	 * VB6's own convention, matching what `bool()` reads back.
	 *
	 * @param bool $value
	 * @return $this
	 */
	public function bool( bool $value ): self {
		return $this->int16( $value ? -1 : 0 );
	}

	/**
	 * Writes a length-prefixed string field: a 16-bit length prefix
	 * followed by that many ISO-8859-1 bytes, converted from the UTF-8
	 * string given. Truncated at 32767 bytes (`PutStrB`'s own signed-Integer
	 * length limit) - a string this long has never occurred in any real
	 * Grapevine file this project holds, but the reference writer itself
	 * would silently corrupt one, so truncating cleanly here (rather than
	 * letting `int16()` wrap a too-large length into a bogus negative
	 * prefix) is strictly safer than reproducing that failure mode.
	 * Un-encodable characters are dropped, not substituted, matching
	 * `GEX_Xml_Writer::ascii()`'s own reasoning for the sibling XML path.
	 *
	 * @param string $value
	 * @return $this
	 */
	public function string( string $value ): self {
		// iconv, not mb_convert_encoding(), matching GEX_Xml_Writer::ascii()'s own established
		// approach - //TRANSLIT approximates what it can, //IGNORE drops what it can't, and
		// iconv's real false-on-failure return (unlike mb_convert_encoding()'s narrower one)
		// is worth guarding against directly rather than assuming it can never happen.
		$encoded = @iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value );
		if ( $encoded === false ) {
			$encoded = preg_replace( '/[^\x00-\xFF]/', '', $value ) ?? '';
		}
		if ( strlen( $encoded ) > 32767 ) {
			$encoded = substr( $encoded, 0, 32767 );
		}

		$this->int16( strlen( $encoded ) );
		$this->data .= $encoded;
		return $this;
	}

	/**
	 * Writes a VB6 Date field: an OLE Automation double, epoch 1899-12-30,
	 * `0.0` for null (Grapevine's own "unset" value) - the exact inverse of
	 * `date()`. A date before the epoch encodes as a negative integer part
	 * with the fractional part still counting forward from that day's own
	 * midnight (sign-magnitude, not a continuous scale) - the same
	 * convention `date()`'s own docblock documents for reading one back;
	 * unreached by any real Beyond Elysium data (every stored date is well
	 * after 1899) but implemented for genuine round-trip fidelity rather
	 * than left to silently produce the wrong day for a hypothetical one.
	 *
	 * @param string|null $value 'Y-m-d H:i:s' (or anything `strtotime()` accepts), or null.
	 * @return $this
	 */
	public function date( ?string $value ): self {
		if ( $value === null || $value === '' ) {
			return $this->double( 0.0 );
		}

		$timestamp = strtotime( $value );
		if ( $timestamp === false ) {
			return $this->double( 0.0 );
		}

		$days_since_epoch    = floor( $timestamp / 86400 );
		$seconds_into_day    = $timestamp - ( $days_since_epoch * 86400 );
		$ole_days            = $days_since_epoch + 25569;
		$fraction            = $seconds_into_day / 86400;

		return $this->double( $ole_days + $fraction );
	}

	/**
	 * Returns every byte written so far.
	 *
	 * @return string
	 */
	public function bytes(): string {
		return $this->data;
	}
}
