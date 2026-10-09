<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every email the plugin sends goes through `Core\Mailer`, which records it in the mail log. The only files that call it
 * are the two that decide who gets mail, each checking `Notifications::skip_reason()` or `Demo_Chronicle::is_demo()` first,
 * so a demo chronicle never emails anyone. A new sender anywhere else must be added here deliberately, not slip in.
 */
class NoUngatedMailTest extends TestCase {

	private const MAILER = 'includes/Core/Mailer.php';

	private const GATED_CALLERS = [
		'includes/Core/Notifications.php'      => 'skip_reason(',
		'includes/Services/Player_Invites.php' => 'Demo_Chronicle::is_demo(',
	];

	/**
	 * @return array<string,string> Relative path => contents of every PHP file under includes/.
	 */
	private function sources(): array {
		$root     = dirname( __DIR__, 2 ) . '/beyond-elysium';
		$sources  = [];
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes' ) );
		foreach ( $iterator as $file ) {
			if ( $file->isDir() || $file->getExtension() !== 'php' ) {
				continue;
			}
			$sources[ 'includes' . substr( $file->getPathname(), strlen( $root . '/includes' ) ) ] = (string) file_get_contents( $file->getPathname() );
		}
		return $sources;
	}

	public function test_wp_mail_is_called_only_by_the_mailer(): void {
		$offences = [];
		foreach ( $this->sources() as $relative => $contents ) {
			if ( $relative !== self::MAILER && preg_match( '/\bwp_mail\s*\(/', $contents ) ) {
				$offences[] = $relative;
			}
		}

		$this->assertSame(
			[],
			$offences,
			'wp_mail() found outside ' . self::MAILER . ': ' . implode( ', ', $offences ) . '. Send through Mailer::send() so the message is logged.'
		);
	}

	public function test_only_the_two_gated_files_send_through_the_mailer(): void {
		$offences = [];
		foreach ( $this->sources() as $relative => $contents ) {
			if ( ! isset( self::GATED_CALLERS[ $relative ] ) && $relative !== self::MAILER && preg_match( '/\bMailer::send\s*\(/', $contents ) ) {
				$offences[] = $relative;
			}
		}

		$this->assertSame(
			[],
			$offences,
			'Mailer::send() called from ' . implode( ', ', $offences ) . '. Route it through Notifications::skip_reason() (or Demo_Chronicle::is_demo() for a non-user recipient), then add the file here.'
		);
	}

	public function test_each_gated_file_checks_before_it_sends(): void {
		$sources = $this->sources();
		foreach ( self::GATED_CALLERS as $relative => $gate ) {
			$this->assertArrayHasKey( $relative, $sources, "{$relative} exists" );
			$this->assertStringContainsString( $gate, $sources[ $relative ], "{$relative} checks {$gate} before it sends" );
			$this->assertStringContainsString( 'Mailer::send(', $sources[ $relative ], "{$relative} sends through the mailer" );
		}
	}
}
