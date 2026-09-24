<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WordPress matches an installed plugin against wordpress.org by its slug.
 */
class PluginHeaderTest extends TestCase {

	private function header( string $field ): ?string {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/beyond-elysium/beyond-elysium.php' );
		return preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $source, $m ) ? trim( $m[1] ) : null;
	}

	public function test_the_plugin_opts_out_of_wordpress_org_updates(): void {
		$this->assertSame( 'false', $this->header( 'Update URI' ) );
	}

	public function test_readme_stable_tag_matches_the_plugin_version(): void {
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/beyond-elysium/readme.txt' );

		$this->assertStringStartsWith( '=== Beyond Elysium ===', $readme );
		$this->assertMatchesRegularExpression( '/^Stable tag: ' . preg_quote( (string) $this->header( 'Version' ), '/' ) . '$/m', $readme );
	}

	public function test_the_requirements_production_depends_on_are_declared(): void {
		$this->assertSame( '8.2', $this->header( 'Requires PHP' ) );
		$this->assertSame( 'elementor', $this->header( 'Requires Plugins' ) );
	}
}
