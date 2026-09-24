<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `Documents/` is the public repository's copy of the plugin's own guides and help pages, "unedited".
 * It is wrapped to a fixed column where the plugin's copy is one line per paragraph, so the comparison
 * ignores whitespace and nothing else. A page that changes in the plugin and not here fails this, which
 * is how a stale mirror gets caught before it is published rather than after.
 */
class DocumentsMirrorTest extends TestCase {

	private const GUIDES = [ 'st-guide', 'admin-guide', 'player-guide', 'rest-api' ];

	private function words( string $path ): string {
		return (string) preg_replace( '/\s+/', '', (string) file_get_contents( $path ) );
	}

	public function test_every_guide_is_mirrored_unedited(): void {
		foreach ( self::GUIDES as $guide ) {
			$mirror = be_reference_path( "Documents/{$guide}.md" );
			$this->assertFileExists( $mirror, "Documents/{$guide}.md is missing" );
			$this->assertSame( $this->words( BE_PLUGIN_PATH . "/docs/{$guide}.md" ), $this->words( $mirror ), "Documents/{$guide}.md differs from the plugin's copy" );
		}
	}

	public function test_every_help_page_is_mirrored_unedited_and_nothing_extra_is(): void {
		$source = glob( BE_PLUGIN_PATH . '/docs/help/*.md' ) ?: [];
		$this->assertNotEmpty( $source );

		foreach ( $source as $page ) {
			$name   = basename( $page );
			$mirror = be_reference_path( "Documents/help/{$name}" );
			$this->assertFileExists( $mirror, "Documents/help/{$name} is missing" );
			$this->assertSame( $this->words( $page ), $this->words( $mirror ), "Documents/help/{$name} differs from the plugin's copy" );
		}

		$mirrored = array_map( 'basename', glob( be_reference_path( 'Documents/help' ) . '/*.md' ) ?: [] );
		$original = array_map( 'basename', $source );
		$this->assertSame( [], array_values( array_diff( $mirrored, $original ) ), 'Documents/help holds a page the plugin no longer has' );
	}
}
