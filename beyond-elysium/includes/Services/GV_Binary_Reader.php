<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Byte-level reader for Grapevine's VB6 binary file formats.
 *
 * Fields are packed back to back with no alignment or padding, in the exact
 * order each VB6 class serializes them. Strings carry a signed 16-bit
 * little-endian length prefix followed by ISO-8859-1 bytes.
 *
 * Primitive sizes:
 *   Integer  2 bytes, signed
 *   Long     4 bytes, signed
 *   Single   4 bytes, IEEE 754 little-endian
 *   Boolean  2 bytes (0 or -1)
 *   Double   8 bytes, IEEE 754 little-endian
 *   Date     8 bytes, OLE Automation double, epoch 1899-12-30
 *   Enum     4 bytes, same width as Long
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "File Formats"
 * @see BE_PROCESS/workflow-0.8.md Step 1
 */
class GV_Binary_Reader {

	/** @var string Raw file contents. */
	private $data;

	/** @var int Current byte offset. */
	private $pos = 0;

	/** @var string Label used in error messages. */
	private $label;

	/**
	 * Creates a reader positioned at the start of the given byte buffer.
	 * Stores the raw data and a label used to identify the source in error
	 * messages, and initializes the read position to zero.
	 *
	 * @param string $data  Raw bytes.
	 * @param string $label Source name, for error messages.
	 */
	public function __construct( string $data, string $label = 'buffer' ) {
		$this->data  = $data;
		$this->label = $label;
	}

	/**
	 * Creates a reader by loading the full contents of a file from disk.
	 * Reads the file at the given path into memory and labels the
	 * resulting reader with the file's base name for use in error
	 * messages.
	 *
	 * @param string $path Absolute path.
	 * @return self
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function from_file( string $path ): self {
		$data = @file_get_contents( $path );

		if ( $data === false ) {
			throw new \RuntimeException( 'Cannot read ' . $path );
		}

		return new self( $data, basename( $path ) );
	}

	/**
	 * Checks that the buffer has enough remaining bytes for an upcoming
	 * read. Compares the requested byte count against what is left between
	 * the current position and the end of the buffer, and throws when the
	 * read would run past the end of the data.
	 *
	 * @param int $bytes How many bytes the caller is about to consume.
	 * @throws \RuntimeException When there are not enough bytes left.
	 */
	private function need( int $bytes ): void {
		if ( $this->pos + $bytes > strlen( $this->data ) ) {
			throw new \RuntimeException(
				sprintf(
					'%s: truncated at byte %d, needed %d more (file is %d bytes)',
					$this->label,
					$this->pos,
					$bytes,
					strlen( $this->data )
				)
			);
		}
	}

	/**
	 * Reads a VB6 Integer field: 2 bytes, little-endian, signed. Unpacks
	 * the next two bytes as an unsigned short, converts values above the
	 * signed range to their negative equivalent, and advances the read
	 * position by 2 bytes.
	 *
	 * @return int
	 */
	public function int16(): int {
		$this->need( 2 );
		$value      = unpack( 'v', substr( $this->data, $this->pos, 2 ) )[1];
		$this->pos += 2;

		return $value > 32767 ? $value - 65536 : $value;
	}

	/**
	 * Reads a VB6 Long field: 4 bytes, little-endian, signed. Also used
	 * for enum values, which VB6 stores at the same width. Unpacks the
	 * next four bytes, converts values above the signed range to their
	 * negative equivalent, and advances the read position by 4 bytes.
	 *
	 * @return int
	 */
	public function int32(): int {
		$this->need( 4 );
		$value      = unpack( 'V', substr( $this->data, $this->pos, 4 ) )[1];
		$this->pos += 4;

		return $value > 2147483647 ? $value - 4294967296 : $value;
	}

	/**
	 * Reads a VB6 Double field: 8 bytes, IEEE 754 little-endian. Unpacks
	 * the next eight bytes as a double-precision float and advances the
	 * read position by 8 bytes.
	 *
	 * @return float
	 */
	public function double(): float {
		$this->need( 8 );
		$value      = unpack( 'e', substr( $this->data, $this->pos, 8 ) )[1];
		$this->pos += 8;

		return $value;
	}

	/**
	 * Reads a VB6 Single field: 4 bytes, IEEE 754 little-endian. Unpacks
	 * the next four bytes as a single-precision float and advances the
	 * read position by 4 bytes, half the width of `double()`.
	 *
	 * @return float
	 */
	public function single(): float {
		$this->need( 4 );
		$value      = unpack( 'g', substr( $this->data, $this->pos, 4 ) )[1];
		$this->pos += 4;

		return $value;
	}

	/**
	 * Reads a VB6 Boolean field: 2 bytes, where 0 is false and -1 is
	 * true. Delegates to `int16()` to consume the bytes and advance the
	 * read position, then converts the result to a PHP bool.
	 *
	 * @return bool
	 */
	public function bool(): bool {
		return $this->int16() !== 0;
	}

	/**
	 * Reads a length-prefixed string field and converts it to UTF-8.
	 * Reads a 16-bit length prefix, then that many bytes from the buffer,
	 * and converts them from ISO-8859-1, the encoding Grapevine writes,
	 * to UTF-8.
	 *
	 * @return string
	 */
	public function string(): string {
		$length = $this->int16();

		if ( $length < 0 ) {
			throw new \RuntimeException(
				sprintf( '%s: negative string length %d at byte %d', $this->label, $length, $this->pos - 2 )
			);
		}

		$this->need( $length );
		$raw        = substr( $this->data, $this->pos, $length );
		$this->pos += $length;

		return mb_convert_encoding( $raw, 'UTF-8', 'ISO-8859-1' );
	}

	/**
	 * Reads a VB6 Date field and converts it to a formatted timestamp
	 * string. Reads an OLE Automation double with an epoch of 1899-12-30,
	 * where the fractional part is the time of day, and formats the
	 * result as `Y-m-d H:i:s`. Negative values are treated as a
	 * sign-magnitude fraction rather than a continuous scale.
	 *
	 * @return string|null 'Y-m-d H:i:s', or null for the zero value Grapevine uses as "unset".
	 */
	public function date(): ?string {
		$serial = $this->double();

		if ( $serial === 0.0 ) {
			return null;
		}

		$days     = (int) $serial;
		$fraction = abs( $serial - $days );

		$timestamp = ( $days - 25569 ) * 86400 + (int) round( $fraction * 86400 );

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Returns the next bytes from the buffer without advancing the read
	 * position. Reads a substring starting at the current offset, leaving
	 * the position unchanged so a subsequent real read sees the same
	 * bytes.
	 *
	 * @param int $bytes How many.
	 * @return string
	 */
	public function peek( int $bytes ): string {
		return substr( $this->data, $this->pos, $bytes );
	}

	/**
	 * Returns the current read position within the buffer, as a byte
	 * offset from the start of the data. Reflects the total number of
	 * bytes consumed so far by prior reads.
	 *
	 * @return int
	 */
	public function tell(): int {
		return $this->pos;
	}

	/**
	 * Returns the total size of the underlying buffer in bytes, regardless
	 * of how much of it has been read so far. Reflects the full length of
	 * the data the reader was constructed with.
	 *
	 * @return int
	 */
	public function size(): int {
		return strlen( $this->data );
	}

	/**
	 * Returns whether the read position has reached the end of the
	 * buffer, meaning every byte has been consumed. Compares the current
	 * offset against the total length of the buffer.
	 *
	 * @return bool
	 */
	public function eof(): bool {
		return $this->pos >= strlen( $this->data );
	}
}
