<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Configures TCPDF's document signature from three `wp-config.php`
 * constants - `BE_PDF_SIGNING_CERT`, `BE_PDF_SIGNING_KEY`,
 * `BE_PDF_SIGNING_PASSPHRASE` - never a WordPress option, since the
 * passphrase is the one secret this plugin holds (signed-pdf-design.md §3c).
 *
 * Failure here is hard and named, never a silent unsigned PDF (P1): an
 * unsigned document is not a degraded success, it is a wrong answer. Callers
 * check `availability()` before generating anything.
 *
 * `BE_PDF_SIGNING_PASSPHRASE` undefined or empty means "the key has no
 * passphrase" (an `-nodes`/`-noenc`-generated key), not a misconfiguration -
 * so an existing unencrypted key keeps working rather than being stranded.
 *
 * @see BE_PROCESS/signed-pdf-design.md §3c
 */
class Pdf_Signer {

	/**
	 * Reads a `wp-config.php`-only constant by name, or '' if undefined.
	 * Indirected through a variable name - rather than a bare constant
	 * token - so PHPStan never sees a literal `BE_PDF_SIGNING_*` fetch: this
	 * codebase never defines these constants (only each production host's
	 * own `wp-config.php` does), so a bare token is either an undefined-
	 * constant error, or, once stubbed for analysis, a compile-time-known
	 * value that makes every emptiness check below look unreachable. Both
	 * are analysis artifacts, not real conditions - the actual value is
	 * genuinely unknown until runtime.
	 */
	private static function const_string( string $name ): string {
		return defined( $name ) ? (string) constant( $name ) : '';
	}

	/**
	 * Reports whether signing is fully configured, without ever touching
	 * the passphrase - so an admin notice or a UI preflight can ask "is
	 * signing ready" without that secret entering another code path.
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function availability(): array {
		$cert = self::const_string( 'BE_PDF_SIGNING_CERT' );
		if ( $cert === '' ) {
			return [ 'ok' => false, 'code' => 'cert_not_configured' ];
		}
		if ( ! is_readable( $cert ) ) {
			return [ 'ok' => false, 'code' => 'cert_unreadable' ];
		}
		$key = self::const_string( 'BE_PDF_SIGNING_KEY' );
		if ( $key === '' ) {
			return [ 'ok' => false, 'code' => 'key_not_configured' ];
		}
		if ( ! is_readable( $key ) ) {
			return [ 'ok' => false, 'code' => 'key_unreadable' ];
		}

		return [ 'ok' => true, 'code' => 'ok' ];
	}

	/**
	 * Configures `$pdf`'s signature. Caller's responsibility, not this
	 * method's: call this between `new TCPDF(...)` and the first
	 * `AddPage()` - `setSignature()`'s own `/ByteRange` and `/Contents`
	 * placeholder are reserved during construction and back-filled at
	 * `Output()`, verified against TCPDF's own `examples/example_052.php`
	 * at tag 6.11.4 (§2a). Throws rather than silently no-op'ing when
	 * `availability()` would report failure, so a caller that skips the
	 * preflight check still cannot produce an unsigned PDF by accident.
	 *
	 * The passphrase is read here, on this one line, and nowhere else in
	 * this class: never assigned to a property, never returned, never
	 * logged, never included in any exception message.
	 *
	 * @param \TCPDF $pdf
	 * @param object $game Provides the signature's own `Name`/`Reason` metadata.
	 * @throws \RuntimeException When signing is not configured or the files are unreadable.
	 */
	public static function configure( \TCPDF $pdf, object $game ): void {
		$availability = self::availability();
		if ( ! $availability['ok'] ) {
			throw new \RuntimeException( 'PDF signing is not available: ' . $availability['code'] );
		}

		$passphrase = self::const_string( 'BE_PDF_SIGNING_PASSPHRASE' );

		$pdf->setSignature(
			'file://' . self::const_string( 'BE_PDF_SIGNING_CERT' ),
			'file://' . self::const_string( 'BE_PDF_SIGNING_KEY' ),
			$passphrase,
			'',
			2, // cert_type 2: annotations allowed, any content change invalidates the signature.
			[
				'Name'        => $game->name ?? '',
				'Location'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'Reason'      => 'Character sheet as generated ' . current_time( 'mysql' ),
				'ContactInfo' => home_url(),
			]
		);
	}
}
