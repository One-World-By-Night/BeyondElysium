<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Generic, low-level GEX XML tag builder.
 */
class GEX_Xml_Writer {

	/** @var object{tag:string,attrs:array<int,array{0:string,1:string}>,children:array<int,object|string>}|null */
	private $root = null;

	/** @var array<int,object> Open-tag stack; the last element is the tag currently being written into. */
	private array $stack = [];

	/** @var array<int,string> Human-readable "before -> after" record of every ASCII substitution made. */
	private array $transliterations = [];

	/**
	 * Starts a new tag as a child of whichever tag is currently open (or as the document's root, if none is).
	 *
	 * @param string $tag
	 * @return $this
	 */
	public function begin_tag( string $tag ): self {
		$node = (object) [ 'tag' => $tag, 'attrs' => [], 'children' => [] ];

		if ( $this->stack ) {
			$this->current_node()->children[] = $node;
		} else {
			$this->root = $node;
		}

		$this->stack[] = $node;
		return $this;
	}

	/**
	 * Returns the tag currently being written into.
	 *
	 * @return object
	 */
	private function current_node(): object {
		if ( ! $this->stack ) {
			throw new \RuntimeException( 'GEX_Xml_Writer: no tag is currently open.' );
		}
		return $this->stack[ count( $this->stack ) - 1 ];
	}

	/**
	 * Closes the tag most recently opened by `begin_tag()`.
	 *
	 * @return $this
	 */
	public function end_tag(): self {
		array_pop( $this->stack );
		return $this;
	}

	/**
	 * Adds an attribute to the tag currently being written, unless its value equals `$omit`.
	 *
	 * @param string     $name
	 * @param string|int|float|bool $value
	 * @param string|int|float|bool|null $omit Value that suppresses the attribute entirely, or null for no omit rule.
	 * @return $this
	 */
	public function write_attribute( string $name, $value, $omit = null ): self {
		if ( $omit !== null && $value === $omit ) {
			return $this;
		}

		if ( is_bool( $value ) ) {
			$string = $value ? 'yes' : 'no';
		} elseif ( is_int( $value ) || is_float( $value ) ) {
			$string = (string) $value;
		} else {
			$string = $this->ascii( (string) $value );
		}

		$this->current_node()->attrs[] = [ $name, $string ];
		return $this;
	}

	/**
	 * Writes a whole CDATA-wrapped tag in one call.
	 *
	 * @param string $tag
	 * @param string $data
	 * @return $this
	 */
	public function write_cdata_tag( string $tag, string $data ): self {
		if ( $data === '' ) {
			return $this;
		}

		$this->begin_tag( $tag );
		$this->current_node()->children[] = str_replace( ']]>', ']] >', $this->ascii( $data ) );
		$this->end_tag();

		return $this;
	}

	/**
	 * Returns every "before -> after" ASCII substitution made so far, for surfacing to the exporting Storyteller.
	 *
	 * @return array<int,string>
	 */
	public function transliterations(): array {
		return $this->transliterations;
	}

	/**
	 * Serializes the built tree to a complete XML document, prolog included.
	 *
	 * @return string
	 * @throws \RuntimeException If no tag was ever opened.
	 */
	public function render(): string {
		if ( $this->root === null ) {
			throw new \RuntimeException( 'GEX_Xml_Writer::render() called with no tag ever opened.' );
		}

		return '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n" . $this->render_node( $this->root, 0 );
	}

	/**
	 * @param object $node
	 * @param int    $indent
	 * @return string
	 */
	private function render_node( object $node, int $indent ): string {
		$pad = str_repeat( ' ', $indent );

		$attributes = '';
		foreach ( $node->attrs as [ $name, $value ] ) {
			$attributes .= ' ' . $name . '="' . $this->escape_attribute( $value ) . '"';
		}

		if ( ! $node->children ) {
			return $pad . '<' . $node->tag . $attributes . "/>\n";
		}

		$xml = $pad . '<' . $node->tag . $attributes . ">\n";
		foreach ( $node->children as $child ) {
			$xml .= is_string( $child )
				? str_repeat( ' ', $indent + 2 ) . '<![CDATA[' . $child . "]]>\n"
				: $this->render_node( $child, $indent + 2 );
		}
		$xml .= $pad . '</' . $node->tag . ">\n";

		return $xml;
	}

	/**
	 * `XMLWriterClass::FormatForXML()`'s four replacements, `&` first.
	 *
	 * @param string $s
	 * @return string
	 */
	private function escape_attribute( string $s ): string {
		return str_replace( [ '&', '<', '>', '"' ], [ '&amp;', '&lt;', '&gt;', '&quot;' ], $s );
	}

	/**
	 * Transliterates a string to pure ASCII, recording every substitution.
	 *
	 * @param string $s
	 * @return string
	 */
	private function ascii( string $s ): string {
		if ( $s === '' || mb_check_encoding( $s, 'ASCII' ) ) {
			return $s;
		}

		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s );
		if ( $converted === false ) {
			$converted = preg_replace( '/[^\x00-\x7F]/', '', $s ) ?? '';
		}

		if ( $converted !== $s ) {
			$this->transliterations[] = "\"{$s}\" -> \"{$converted}\"";
		}

		return $converted;
	}
}
