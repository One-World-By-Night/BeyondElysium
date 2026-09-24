<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Comments describe what the code is. No PHP, Python or shell comment names a version, decision, finding, plan step,
 * process document or date; the rules are shared with the JavaScript-side test in `src/lib/commentReferences.test.ts`.
 */
class CommentsDescribeTheCodeTest extends TestCase {

	private const SKIP_DIRS = [ 'vendor', 'build', 'node_modules', 'dist', '.git', '__pycache__', 'languages' ];

	/**
	 * Extension-less scripts in `bin/`, by the language their comments are written in.
	 */
	private const BIN_SCRIPTS = [
		'bump-version'       => 'shell',
		'check-translations' => 'shell',
		'dist'               => 'shell',
		'verify'             => 'shell',
		'validate-catalog'   => 'php',
	];

	public function test_no_comment_names_a_version_decision_finding_step_document_or_date(): void {
		$rules    = $this->rules();
		$offences = [];
		$scanned  = 0;

		foreach ( $this->files() as $path => $language ) {
			$source = (string) file_get_contents( $path );

			foreach ( $this->comments( $source, $language ) as [ $line, $text ] ) {
				if ( false !== strpos( $text, 'Plugin Name:' ) ) {
					continue;
				}

				foreach ( $rules as $rule ) {
					if ( preg_match( '~' . $rule['pattern'] . '~' . $rule['flags'], $text, $match ) ) {
						$offences[] = $this->relative( $path ) . ":{$line}: {$rule['description']} - \"{$match[0]}\"";
					}
				}
			}

			++$scanned;
		}

		$this->assertGreaterThan( 500, $scanned, 'the scan found the code tree' );
		$this->assertSame(
			[],
			$offences,
			"Comments say what the code is. These name a version, decision, finding, step, process document or date:\n" . implode( "\n", $offences )
		);
	}

	/**
	 * @return array<int,array{name:string,description:string,pattern:string,flags:string}>
	 */
	private function rules(): array {
		$decoded = json_decode( (string) file_get_contents( BE_TESTS_DIR . '/support/comment-reference-rules.json' ), true );

		return $decoded['rules'];
	}

	/**
	 * Every PHP, Python and shell source file, mapped to its language: the code tree, and the private repository's
	 * scripts when this runs inside it.
	 *
	 * @return array<string,string>
	 */
	private function files(): array {
		$found = [];

		$this->collect( BE_PLUGIN_PATH, [ 'php' => 'php' ], $found );
		$this->collect( BE_TESTS_DIR, [ 'php' => 'php' ], $found );
		$this->collect( BE_PLUGIN_ROOT . '/tools', [ 'py' => 'python', 'sh' => 'shell' ], $found );
		$this->collect( BE_PLUGIN_ROOT . '/bin', [ 'php' => 'php' ], $found );

		foreach ( self::BIN_SCRIPTS as $name => $language ) {
			$found[ BE_PLUGIN_ROOT . '/bin/' . $name ] = $language;
		}

		if ( is_dir( BE_REPO_ROOT . '/BE_PROCESS' ) ) {
			$this->collect( BE_REPO_ROOT . '/BE_PROCESS', [ 'php' => 'php', 'py' => 'python', 'sh' => 'shell' ], $found );

			foreach ( [ 'deployUpdate.sh', 'publish-public.sh' ] as $script ) {
				if ( is_file( BE_REPO_ROOT . '/' . $script ) ) {
					$found[ BE_REPO_ROOT . '/' . $script ] = 'shell';
				}
			}
		}

		ksort( $found );

		return $found;
	}

	/**
	 * @param array<string,string> $languages Extension => language.
	 * @param array<string,string> $found
	 */
	private function collect( string $root, array $languages, array &$found ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			foreach ( self::SKIP_DIRS as $dir ) {
				if ( false !== strpos( $path, "/{$dir}/" ) ) {
					continue 2;
				}
			}

			$extension = strtolower( $file->getExtension() );

			if ( isset( $languages[ $extension ] ) ) {
				$found[ $path ] = $languages[ $extension ];
			}
		}
	}

	/**
	 * @return array<int,array{0:int,1:string}> Line and text of every comment.
	 */
	private function comments( string $source, string $language ): array {
		if ( 'php' === $language ) {
			return $this->php_comments( $source );
		}

		return 'python' === $language ? $this->python_comments( $source ) : $this->shell_comments( $source );
	}

	/**
	 * @return array<int,array{0:int,1:string}>
	 */
	private function php_comments( string $source ): array {
		$out = [];

		foreach ( \PhpToken::tokenize( $source ) as $token ) {
			if ( $token->is( [ T_COMMENT, T_DOC_COMMENT ] ) ) {
				$out[] = [ $token->line, $token->text ];
			}
		}

		return $out;
	}

	/**
	 * `#` comments, and triple-quoted strings, which in these scripts are docstrings.
	 *
	 * @return array<int,array{0:int,1:string}>
	 */
	private function python_comments( string $source ): array {
		$out    = [];
		$length = strlen( $source );
		$line   = 1;
		$i      = 0;

		while ( $i < $length ) {
			$char = $source[ $i ];

			if ( "\n" === $char ) {
				++$line;
				++$i;
			} elseif ( '#' === $char ) {
				$end   = strpos( $source, "\n", $i );
				$end   = false === $end ? $length : $end;
				$out[] = [ $line, substr( $source, $i, $end - $i ) ];
				$i     = $end;
			} elseif ( '"' === $char || "'" === $char ) {
				$quote      = substr( $source, $i, 3 ) === str_repeat( $char, 3 ) ? str_repeat( $char, 3 ) : $char;
				$triple     = strlen( $quote ) === 3;
				$start_line = $line;
				$i         += strlen( $quote );
				$body_start = $i;

				while ( $i < $length && substr( $source, $i, strlen( $quote ) ) !== $quote ) {
					if ( '\\' === $source[ $i ] ) {
						$line += "\n" === ( $source[ $i + 1 ] ?? '' ) ? 1 : 0;
						$i    += 2;
						continue;
					}

					if ( "\n" === $source[ $i ] ) {
						if ( ! $triple ) {
							break;
						}
						++$line;
					}

					++$i;
				}

				if ( $triple ) {
					$out[] = [ $start_line, substr( $source, $body_start, $i - $body_start ) ];
				}

				$i += strlen( $quote );
			} else {
				++$i;
			}
		}

		return $out;
	}

	/**
	 * A `#` that starts a word outside quotes, and the rest of its line.
	 *
	 * @return array<int,array{0:int,1:string}>
	 */
	private function shell_comments( string $source ): array {
		$out = [];

		foreach ( explode( "\n", $source ) as $index => $text ) {
			if ( 0 === $index && 0 === strpos( $text, '#!' ) ) {
				continue;
			}

			$single = false;
			$double = false;
			$length = strlen( $text );

			for ( $i = 0; $i < $length; $i++ ) {
				$char = $text[ $i ];

				if ( '\\' === $char && ! $single ) {
					++$i;
				} elseif ( "'" === $char && ! $double ) {
					$single = ! $single;
				} elseif ( '"' === $char && ! $single ) {
					$double = ! $double;
				} elseif ( '#' === $char && ! $single && ! $double && ( 0 === $i || ' ' === $text[ $i - 1 ] || "\t" === $text[ $i - 1 ] ) ) {
					$out[] = [ $index + 1, substr( $text, $i ) ];
					break;
				}
			}
		}

		return $out;
	}

	private function relative( string $path ): string {
		foreach ( [ BE_PLUGIN_ROOT, BE_REPO_ROOT ] as $root ) {
			if ( 0 === strpos( $path, $root . '/' ) ) {
				return substr( $path, strlen( $root ) + 1 );
			}
		}

		return $path;
	}
}
