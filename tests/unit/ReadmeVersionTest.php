<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The README is the front page of the public repository, and it names the release and the zip to download.
 */
class ReadmeVersionTest extends TestCase {

	private string $readme;
	private string $version;

	protected function setUp(): void {
		$this->readme = (string) file_get_contents( be_reference_path( 'README.md' ) );
		$header       = (string) file_get_contents( BE_PLUGIN_PATH . '/beyond-elysium.php' );
		$this->assertSame( 1, preg_match( '/^ \* Version:\s*(\S+)/m', $header, $found ), 'plugin header has no Version line' );
		$this->version = $found[1];
	}

	public function test_the_status_line_names_the_current_version(): void {
		$this->assertSame( 1, preg_match( '/^\*\*`v([0-9][0-9.]*)`/m', $this->readme, $found ), 'README has no **`vX.Y.Z`** status line' );
		$this->assertSame( $this->version, $found[1], 'README status line is not the plugin version' );
	}

	public function test_every_zip_it_names_is_the_current_version(): void {
		$this->assertGreaterThan( 0, preg_match_all( '/beyond-elysium-([0-9][0-9.]*)\.zip/', $this->readme, $found ), 'README names no zip' );
		foreach ( $found[1] as $named ) {
			$this->assertSame( $this->version, $named, 'README names a zip that is not the current version' );
		}
	}
}
