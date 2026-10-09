<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every Portuguese (Brazil) guide and help page has an English original and the same shape: the same blocks in the same
 * order, with the same list items, table cells, code blocks and link targets, so the two pair block by block.
 */
class DocsTranslationTest extends TestCase {

	private const LANGUAGE = 'pt_BR';

	/**
	 * Every Markdown file under one documentation tree, as a path relative to that tree.
	 *
	 * @return string[]
	 */
	private function files( string $root ): array {
		$found = [];
		foreach ( glob( $root . '/*.md' ) ?: [] as $path ) {
			$found[] = basename( $path );
		}
		foreach ( glob( $root . '/help/*.md' ) ?: [] as $path ) {
			$found[] = 'help/' . basename( $path );
		}
		sort( $found );
		return $found;
	}

	/**
	 * A document's blocks, in order, each as a short signature: a heading by level, a paragraph, a list by its item count, a
	 * table by its columns and rows, a code block, a quotation.
	 *
	 * @return string[]
	 */
	private function blocks( string $markdown ): array {
		$lines  = explode( "\n", str_replace( "\r\n", "\n", $markdown ) );
		$blocks = [];
		$count  = count( $lines );
		$i      = 0;
		while ( $i < $count ) {
			$line = $lines[ $i ];
			if ( trim( $line ) === '' ) {
				++$i;
				continue;
			}
			if ( preg_match( '/^\s*```/', $line ) ) {
				++$i;
				while ( $i < $count && ! preg_match( '/^\s*```/', $lines[ $i ] ) ) {
					++$i;
				}
				++$i;
				$blocks[] = 'code';
				continue;
			}
			if ( preg_match( '/^(#{1,6})\s/', $line, $heading ) ) {
				$blocks[] = 'h' . strlen( $heading[1] );
				++$i;
				continue;
			}
			if ( preg_match( '/^\s*\|/', $line ) ) {
				$rows    = 0;
				$columns = substr_count( preg_replace( '/\\\\\|/', '', trim( $line ) ), '|' ) - 1;
				while ( $i < $count && preg_match( '/^\s*\|/', $lines[ $i ] ) ) {
					++$rows;
					++$i;
				}
				$blocks[] = "table {$columns}x{$rows}";
				continue;
			}
			if ( preg_match( '/^\s*([-*+]|\d+[.)])\s/', $line ) ) {
				$items = 0;
				while ( $i < $count ) {
					$current = $lines[ $i ];
					if ( preg_match( '/^\s*([-*+]|\d+[.)])\s/', $current ) ) {
						++$items;
						++$i;
						continue;
					}
					if ( trim( $current ) !== '' && preg_match( '/^\s{2,}\S/', $current ) ) {
						++$i;
						continue;
					}
					// A blank line belongs to the list only when more of it follows.
					$next = $i + 1;
					while ( $next < $count && trim( $lines[ $next ] ) === '' ) {
						++$next;
					}
					if ( trim( $current ) === '' && $next < $count && ( preg_match( '/^\s*([-*+]|\d+[.)])\s/', $lines[ $next ] ) || preg_match( '/^\s{2,}\S/', $lines[ $next ] ) ) ) {
						$i = $next;
						continue;
					}
					break;
				}
				$blocks[] = "list {$items}";
				continue;
			}
			if ( preg_match( '/^\s*>/', $line ) ) {
				while ( $i < $count && preg_match( '/^\s*>/', $lines[ $i ] ) ) {
					++$i;
				}
				$blocks[] = 'quote';
				continue;
			}
			while ( $i < $count && trim( $lines[ $i ] ) !== '' && ! preg_match( '/^(#{1,6}\s|\s*```|\s*\|)/', $lines[ $i ] ) ) {
				++$i;
			}
			$blocks[] = 'paragraph';
		}
		return $blocks;
	}

	/**
	 * Every link target in a document, outside code blocks, with the section anchor taken off.
	 *
	 * @return string[]
	 */
	private function targets( string $markdown ): array {
		$markdown = (string) preg_replace( '/```.*?```/s', '', $markdown );
		preg_match_all( '/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $markdown, $found );
		return array_map(
			static fn( string $target ): string => preg_match( '/^[a-z][a-z0-9+.-]*:/i', $target )
				? $target
				: ( '' !== explode( '#', $target )[0] ? explode( '#', $target )[0] : '#' ),
			$found[1]
		);
	}

	public function test_every_translation_has_an_english_original(): void {
		foreach ( $this->files( BE_PLUGIN_PATH . '/docs/' . self::LANGUAGE ) as $file ) {
			$this->assertFileExists( BE_PLUGIN_PATH . '/docs/' . $file, "docs/" . self::LANGUAGE . "/{$file} has no English original" );
		}
	}

	public function test_every_english_document_has_a_translation(): void {
		foreach ( $this->files( BE_PLUGIN_PATH . '/docs' ) as $file ) {
			$this->assertFileExists( BE_PLUGIN_PATH . '/docs/' . self::LANGUAGE . '/' . $file, "docs/{$file} has no translation" );
		}
	}

	public function test_every_translation_keeps_the_shape_of_its_original(): void {
		foreach ( $this->files( BE_PLUGIN_PATH . '/docs/' . self::LANGUAGE ) as $file ) {
			$original   = (string) file_get_contents( BE_PLUGIN_PATH . '/docs/' . $file );
			$translated = (string) file_get_contents( BE_PLUGIN_PATH . '/docs/' . self::LANGUAGE . '/' . $file );

			$this->assertSame( $this->blocks( $original ), $this->blocks( $translated ), "docs/" . self::LANGUAGE . "/{$file} differs in shape from its original" );
			$this->assertSame( $this->targets( $original ), $this->targets( $translated ), "docs/" . self::LANGUAGE . "/{$file} links somewhere its original does not" );
		}
	}

	public function test_the_shape_check_catches_a_changed_block(): void {
		$original = "# Title\n\nA paragraph.\n\n- one\n- two\n\n| A | B |\n|---|---|\n| 1 | 2 |\n";

		$this->assertSame( [ 'h1', 'paragraph', 'list 2', 'table 2x3' ], $this->blocks( $original ) );
		$this->assertNotSame( $this->blocks( $original ), $this->blocks( "# Title\n\nA paragraph.\n\n- one\n\n| A | B |\n|---|---|\n| 1 | 2 |\n" ) );
		$this->assertNotSame( $this->blocks( $original ), $this->blocks( "# Title\n\nA paragraph.\n\nMore.\n\n- one\n- two\n\n| A | B |\n|---|---|\n| 1 | 2 |\n" ) );
		$this->assertNotSame( $this->targets( 'See [x](a.md#one).' ), $this->targets( 'See [x](b.md#one).' ) );
		$this->assertSame( $this->targets( 'See [x](a.md#one).' ), $this->targets( 'Veja [x](a.md#um).' ) );
	}

	public function test_the_english_documents_parse_into_blocks(): void {
		foreach ( $this->files( BE_PLUGIN_PATH . '/docs' ) as $file ) {
			$this->assertNotEmpty( $this->blocks( (string) file_get_contents( BE_PLUGIN_PATH . '/docs/' . $file ) ), $file );
		}
	}
}
