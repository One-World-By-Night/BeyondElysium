<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `code/` is exactly what goes to the public repository.
 */
class NoToolAttributionTest extends TestCase {

	/**
	 * Generated, vendored, or binary.
	 */
	private const SKIP_DIRS = [ 'vendor', 'build', 'node_modules', 'dist', '.git', 'languages' ];

	private const SKIP_EXTENSIONS = [ 'zip', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'ico', 'pdf', 'mo', 'po', 'gvm', 'gex', 'gv3' ];

	/**
	 * Phrases that assert authorship by a tool.
	 */
	private const FORBIDDEN = [
		'/co-authored-by:\s*claude/i',
		'/co-authored-by:\s*.*anthropic/i',
		'/\b(generated|written|authored|created|built|produced)\s+(by|with|using)\s+(claude|chatgpt|copilot|cursor|an?\s+(ai|llm))\b/i',
		'/\bclaude\s+code\b/i',
		'/\bwith\s+(the\s+)?(help|assistance)\s+of\s+(claude|an?\s+ai|anthropic)\b/i',
		'/\bai[- ]generated\b/i',
		'/\b(powered|assisted)\s+by\s+(claude|anthropic)\b/i',
	];

	public function test_no_file_in_the_code_tree_credits_a_coding_tool(): void {
		$offences = [];

		foreach ( $this->files() as $file ) {
			$contents = (string) file_get_contents( $file );
			foreach ( self::FORBIDDEN as $pattern ) {
				if ( preg_match( $pattern, $contents, $match ) ) {
					$relative   = str_replace( BE_PLUGIN_ROOT . '/', '', $file );
					$offences[] = "{$relative}: \"{$match[0]}\"";
				}
			}
		}

		$this->assertSame(
			[],
			$offences,
			"code/ goes public verbatim - these credit a coding tool:\n" . implode( "\n", $offences )
		);
	}

	/**
	 * Every readable text file under the code root, skipping generated and vendored trees.
	 *
	 * @return string[]
	 */
	private function files(): array {
		$found    = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( BE_PLUGIN_ROOT, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( $path === __FILE__ ) {
				continue;
			}

			foreach ( self::SKIP_DIRS as $dir ) {
				if ( strpos( $path, "/{$dir}/" ) !== false ) {
					continue 2;
				}
			}

			if ( in_array( strtolower( $file->getExtension() ), self::SKIP_EXTENSIONS, true ) ) {
				continue;
			}

			$found[] = $path;
		}

		return $found;
	}
}
