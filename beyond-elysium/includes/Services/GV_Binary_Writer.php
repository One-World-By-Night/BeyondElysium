<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Byte-level writer for Grapevine's VB6 binary file formats.
 *
 * @see BeyondElysium\Services\GV_Binary_Reader
 */
class GV_Binary_Writer {

	/** @var string Accumulated output bytes. */
	private string $data = '';

	/**
	 * Writes a VB6 Integer field: 2 bytes, little-endian, signed.
	 *
	 * @param int $value
	 * @return $this
	 */
	public function int16( int $value ): self {
		$this->data .= pack( 'v', $value < 0 ? $value + 65536 : $value );
		return $this;
	}

	/**
	 * Writes a VB6 Long field: 4 bytes, little-endian, signed.
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
	 * Writes a VB6 Single field: 4 bytes, IEEE 754 little-endian, half the width of `double()`.
	 *
	 * @param float $value
	 * @return $this
	 */
	public function single( float $value ): self {
		$this->data .= pack( 'g', $value );
		return $this;
	}

	/**
	 * Writes a VB6 Boolean field: 2 bytes, `-1` for true and `0` for false.
	 *
	 * @param bool $value
	 * @return $this
	 */
	public function bool( bool $value ): self {
		return $this->int16( $value ? -1 : 0 );
	}

	/**
	 * Writes a length-prefixed string field: a 16-bit length prefix followed by that many ISO-8859-1 bytes, converted
	 * string given.
	 *
	 * @param string $value
	 * @return $this
	 */
	public function string( string $value ): self {
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
	 * Writes a VB6 Date field: an OLE Automation double, epoch 1899-12-30, `0.0` for null (Grapevine's own "unset"
	 * value).
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
