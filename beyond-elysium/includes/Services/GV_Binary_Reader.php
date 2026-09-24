<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Byte-level reader for Grapevine's VB6 binary file formats.
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
	 * Checks that the buffer has enough remaining bytes for an upcoming read.
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
	 * Unpacks the next `$bytes` bytes with one `unpack()` format code and advances the read position past them.
	 *
	 * @param string $format
	 * @param int    $bytes
	 * @return int|float
	 * @throws \RuntimeException When there are not enough bytes left, or they don't unpack.
	 */
	private function unpack_next( string $format, int $bytes ) {
		$this->need( $bytes );
		$values = unpack( $format, substr( $this->data, $this->pos, $bytes ) );
		if ( $values === false ) {
			throw new \RuntimeException( sprintf( '%s: unreadable value at byte %d', $this->label, $this->pos ) );
		}
		$this->pos += $bytes;

		return $values[1];
	}

	/**
	 * Reads a VB6 Integer field: 2 bytes, little-endian, signed.
	 *
	 * @return int
	 */
	public function int16(): int {
		$value = (int) $this->unpack_next( 'v', 2 );

		return $value > 32767 ? $value - 65536 : $value;
	}

	/**
	 * Reads a VB6 Long field: 4 bytes, little-endian, signed.
	 *
	 * @return int
	 */
	public function int32(): int {
		$value = (int) $this->unpack_next( 'V', 4 );

		return $value > 2147483647 ? $value - 4294967296 : $value;
	}

	/**
	 * Reads a VB6 Double field: 8 bytes, IEEE 754 little-endian.
	 *
	 * @return float
	 */
	public function double(): float {
		return (float) $this->unpack_next( 'e', 8 );
	}

	/**
	 * Reads a VB6 Single field: 4 bytes, IEEE 754 little-endian.
	 *
	 * @return float
	 */
	public function single(): float {
		return (float) $this->unpack_next( 'g', 4 );
	}

	/**
	 * Reads a VB6 Boolean field: 2 bytes, where 0 is false and -1 is true.
	 *
	 * @return bool
	 */
	public function bool(): bool {
		return $this->int16() !== 0;
	}

	/**
	 * Reads a length-prefixed string field and converts it to UTF-8.
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
	 * Reads a VB6 Date field and converts it to a formatted timestamp string.
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
	 * Returns the next bytes from the buffer without advancing the read position.
	 *
	 * @param int $bytes How many.
	 * @return string
	 */
	public function peek( int $bytes ): string {
		return substr( $this->data, $this->pos, $bytes );
	}

	/**
	 * Returns the current read position within the buffer, as a byte offset from the start of the data.
	 *
	 * @return int
	 */
	public function tell(): int {
		return $this->pos;
	}

	/**
	 * Returns the total size of the underlying buffer in bytes, regardless of how much of it has been read so far.
	 *
	 * @return int
	 */
	public function size(): int {
		return strlen( $this->data );
	}

	/**
	 * Returns whether the read position has reached the end of the buffer, meaning every byte has been consumed.
	 *
	 * @return bool
	 */
	public function eof(): bool {
		return $this->pos >= strlen( $this->data );
	}
}
