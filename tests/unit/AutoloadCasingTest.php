<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every class file's path matches its namespace and class name exactly, as PSR-4 resolution on a case-sensitive host
 * requires.
 */
class AutoloadCasingTest extends TestCase {

	/**
	 * Namespace prefix mapped by composer.json.
	 */
	private const PREFIX = 'BeyondElysium\\';

	/**
	 * Directory that prefix maps to, relative to the repo root.
	 */
	private const BASE = 'beyond-elysium/includes';

	/**
	 * Every plugin class must live at the exact PSR-4 path, matching case.
	 */
	public function test_every_class_resolves_with_exact_casing(): void {
		$base    = BE_PLUGIN_ROOT . '/' . self::BASE;
		$classes = $this->discover_classes( $base );

		$this->assertGreaterThan(
			15,
			count( $classes ),
			'Expected to discover the plugin classes; the scan found almost nothing, so it is probably looking in the wrong place.'
		);

		$mismatches = [];
		foreach ( $classes as $fqcn => $file ) {
			$relative = str_replace( '\\', '/', substr( $fqcn, strlen( self::PREFIX ) ) ) . '.php';
			if ( ! $this->path_exists_exact( $base, $relative ) ) {
				$mismatches[] = sprintf(
					'%s expects %s/%s (declared in %s)',
					$fqcn,
					self::BASE,
					$relative,
					str_replace( BE_PLUGIN_ROOT . '/', '', $file )
				);
			}
		}

		$this->assertSame(
			[],
			$mismatches,
			"PSR-4 casing mismatch — these WILL fatal on a case-sensitive filesystem:\n  "
				. implode( "\n  ", $mismatches )
		);
	}

	/**
	 * Walk a relative path segment by segment, requiring a byte-exact directory entry.
	 *
	 * @param string $root     Absolute directory to start from.
	 * @param string $relative Relative path using forward slashes.
	 * @return bool
	 */
	private function path_exists_exact( string $root, string $relative ): bool {
		$current = $root;

		foreach ( explode( '/', $relative ) as $segment ) {
			$entries = @scandir( $current );
			if ( $entries === false || ! in_array( $segment, $entries, true ) ) {
				return false;
			}
			$current .= '/' . $segment;
		}

		return true;
	}

	/**
	 * Find every BeyondElysium class, interface and trait under a directory.
	 *
	 * @param string $base Absolute directory.
	 * @return array<string,string> Fully-qualified name => declaring file.
	 */
	private function discover_classes( string $base ): array {
		$found    = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() );

			if ( ! preg_match( '/^namespace\s+([^;]+);/m', $source, $ns ) ) {
				continue;
			}
			if ( ! preg_match( '/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $source, $cl ) ) {
				continue;
			}

			$fqcn = trim( $ns[1] ) . '\\' . $cl[1];

			if ( strpos( $fqcn, self::PREFIX ) === 0 ) {
				$found[ $fqcn ] = $file->getPathname();
			}
		}

		return $found;
	}

	/**
	 * The guard itself must detect a mismatch, or it proves nothing.
	 */
	public function test_the_check_actually_detects_a_mismatch(): void {
		$tmp = sys_get_temp_dir() . '/be-casing-' . uniqid( '', true );
		mkdir( $tmp . '/core', 0777, true );
		file_put_contents( $tmp . '/core/Plugin.php', "<?php\nnamespace BeyondElysium\\Core;\nclass Plugin {}\n" );

		try {
			$this->assertFalse(
				$this->path_exists_exact( $tmp, 'Core/Plugin.php' ),
				'The byte-exact check failed to detect a case mismatch — it is worthless.'
			);

			$this->assertTrue(
				$this->path_exists_exact( $tmp, 'core/Plugin.php' ),
				'The byte-exact check rejected a correct path.'
			);
		} finally {
			@unlink( $tmp . '/core/Plugin.php' );
			@rmdir( $tmp . '/core' );
			@rmdir( $tmp );
		}
	}
}
