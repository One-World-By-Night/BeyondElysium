<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `Documents/` is the public repository's copy of the plugin's own guides and help pages, "unedited", in English and in
 * each translation.
 */
class DocumentsMirrorTest extends TestCase {

	private const GUIDES = [ 'st-guide', 'admin-guide', 'player-guide', 'rest-api' ];

	/**
	 * Each tree of documents: the English one at the top of `docs/`, then one folder per translation.
	 */
	private const TREES = [ '', 'pt_BR/' ];

	private function words( string $path ): string {
		return (string) preg_replace( '/\s+/', '', (string) file_get_contents( $path ) );
	}

	public function test_every_guide_is_mirrored_unedited(): void {
		foreach ( self::TREES as $tree ) {
			foreach ( self::GUIDES as $guide ) {
				$source = BE_PLUGIN_PATH . "/docs/{$tree}{$guide}.md";
				if ( '' !== $tree && ! file_exists( $source ) ) {
					continue;
				}
				$mirror = be_reference_path( "Documents/{$tree}{$guide}.md" );
				$this->assertFileExists( $mirror, "Documents/{$tree}{$guide}.md is missing" );
				$this->assertSame( $this->words( $source ), $this->words( $mirror ), "Documents/{$tree}{$guide}.md differs from the plugin's copy" );
			}
		}
	}

	public function test_every_help_page_is_mirrored_unedited_and_nothing_extra_is(): void {
		foreach ( self::TREES as $tree ) {
			$source = glob( BE_PLUGIN_PATH . "/docs/{$tree}help/*.md" ) ?: [];
			if ( '' === $tree ) {
				$this->assertNotEmpty( $source );
			}

			foreach ( $source as $page ) {
				$name   = basename( $page );
				$mirror = be_reference_path( "Documents/{$tree}help/{$name}" );
				$this->assertFileExists( $mirror, "Documents/{$tree}help/{$name} is missing" );
				$this->assertSame( $this->words( $page ), $this->words( $mirror ), "Documents/{$tree}help/{$name} differs from the plugin's copy" );
			}

			$mirrored = array_map( 'basename', glob( be_reference_path( "Documents/{$tree}help" ) . '/*.md' ) ?: [] );
			$original = array_map( 'basename', $source );
			$this->assertSame( [], array_values( array_diff( $mirrored, $original ) ), "Documents/{$tree}help holds a page the plugin no longer has" );
		}
	}

	public function test_no_guide_is_mirrored_that_the_plugin_no_longer_has(): void {
		foreach ( self::TREES as $tree ) {
			$mirrored = array_map( 'basename', glob( be_reference_path( "Documents/{$tree}" ) . '*.md' ) ?: [] );
			$original = array_map( 'basename', glob( BE_PLUGIN_PATH . "/docs/{$tree}*.md" ) ?: [] );
			$this->assertSame( [], array_values( array_diff( $mirrored, $original ) ), "Documents/{$tree} holds a guide the plugin no longer has" );
		}
	}
}
