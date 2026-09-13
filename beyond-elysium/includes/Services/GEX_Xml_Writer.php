<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Generic, low-level GEX XML tag builder - a PHP port of Grapevine's own
 * `XMLWriterClass` (GV301Source/Code/XMLWriterClass.cls), the tool real
 * Grapevine uses to write every `.gex`/`.gv3` XML document.
 *
 * Builds an in-memory tree (rather than replicating `XMLWriterClass`'s own
 * streaming print-as-you-go state machine byte for byte) and serializes it
 * once `render()` is called. This is a deliberate implementation choice, not
 * a shortcut: the two approaches are only required to agree on the parts
 * that matter to a reader - a tag with no children self-closes with `/>`,
 * an omitted attribute never appears at all, CDATA content is escaped the
 * same way - not on incidental whitespace between elements, which no XML
 * parser (including our own `GEX_Xml_Parser`) treats as meaningful
 * (`gex-export-transfer-design.md` G6: correctness of the emitted shape
 * outranks byte-for-byte fidelity to the reference implementation).
 *
 * Real, deliberate divergences from `XMLWriterClass`, both decided in
 * `gex-export-transfer-design.md`:
 *   - The XML prolog declares `encoding="ISO-8859-1"` - the reference
 *     declares nothing at all (§2f).
 *   - Every non-ASCII character is transliterated to its nearest ASCII
 *     equivalent rather than written as UTF-8 or a numeric character
 *     reference - `XMLReaderClass.FormatFromXML` expands only five named
 *     entities and no numeric references at all, so a `&#8217;` this writer
 *     emitted would reach a real Grapevine desktop as the literal seven-byte
 *     string `&#8217;` (§2f). Every substitution is reported back via
 *     `transliterations()` so the exporting Storyteller can see what changed.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-3
 */
class GEX_Xml_Writer {

	/** @var object{tag:string,attrs:array<int,array{0:string,1:string}>,children:array<int,object|string>}|null */
	private $root = null;

	/** @var array<int,object> Open-tag stack; the last element is the tag currently being written into. */
	private array $stack = [];

	/** @var array<int,string> Human-readable "before -> after" record of every ASCII substitution made. */
	private array $transliterations = [];

	/**
	 * Starts a new tag as a child of whichever tag is currently open (or as
	 * the document's root, if none is). Matches `XMLWriterClass::BeginTag()`.
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
	 * Returns the tag currently being written into. Only ever called after
	 * confirming (or knowing by construction) that `$this->stack` is
	 * non-empty; throws rather than returning a nullable type if that
	 * invariant is ever violated by a caller writing an attribute or child
	 * before any tag has been opened.
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
	 * Closes the tag most recently opened by `begin_tag()`. Matches
	 * `XMLWriterClass::EndTag()` - self-closing versus open/close is decided
	 * automatically at render time by whether the tag ever gained a child.
	 *
	 * @return $this
	 */
	public function end_tag(): self {
		array_pop( $this->stack );
		return $this;
	}

	/**
	 * Adds an attribute to the tag currently being written, unless its value
	 * equals `$omit` - in which case nothing is written at all, matching
	 * `XMLWriterClass::WriteAttribute()`'s real omit-by-default rule
	 * (`gex-export-transfer-design.md` §2c). The omit comparison is strict
	 * (`===`) and happens against the raw, unconverted value, exactly as
	 * VB6's own `If Value <> Omit` compares before any `CStr()`.
	 *
	 * A boolean value is written as the string `"yes"`/`"no"`. Escaping
	 * (`&`/`<`/`>`/`"`) happens at render time, not here, so a value can be
	 * safely written and re-read without double-escaping.
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
	 * Writes a whole CDATA-wrapped tag in one call, but only if `$data` is
	 * non-empty - matching `XMLWriterClass::WriteCDataTag()`, which skips
	 * the tag entirely for an empty string rather than writing an empty
	 * element. A literal `]]>` inside the data is broken up (`]] >`) so it
	 * cannot prematurely close the CDATA section, exactly as the reference
	 * does in `WriteStringData()`.
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
	 * Returns every "before -> after" ASCII substitution made so far, for
	 * surfacing to the exporting Storyteller (§2f). Empty when the source
	 * character's data was already pure ASCII.
	 *
	 * @return array<int,string>
	 */
	public function transliterations(): array {
		return $this->transliterations;
	}

	/**
	 * Serializes the built tree to a complete XML document, prolog included.
	 * Two spaces of indentation per nesting level, matching
	 * `XMLWriterClass`'s own `Spaces` constant - cosmetic only, since no
	 * consumer of this format is whitespace-sensitive.
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
	 * `XMLWriterClass::FormatForXML()`'s four replacements, `&` first so a
	 * literal ampersand in the source is not itself escaped a second time by
	 * one of the later replacements.
	 *
	 * @param string $s
	 * @return string
	 */
	private function escape_attribute( string $s ): string {
		return str_replace( [ '&', '<', '>', '"' ], [ '&amp;', '&lt;', '&gt;', '&quot;' ], $s );
	}

	/**
	 * Transliterates a string to pure ASCII, recording every substitution.
	 * `iconv()`'s `//TRANSLIT` suffix maps common non-ASCII characters (smart
	 * quotes, em/en dash, accented Latin letters) to a plain-ASCII
	 * approximation; a character with no reasonable approximation is
	 * dropped rather than left as a `?` placeholder `iconv()` would
	 * otherwise substitute, since a stray `?` inside a trait name is more
	 * misleading than its absence.
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
