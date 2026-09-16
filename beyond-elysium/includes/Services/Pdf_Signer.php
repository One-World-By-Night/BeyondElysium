<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Configures TCPDF's document signature from three `wp-config.php`
 * constants - `BE_PDF_SIGNING_CERT`, `BE_PDF_SIGNING_KEY`,
 * `BE_PDF_SIGNING_PASSPHRASE` - never a WordPress option, since the
 * passphrase is the one secret this plugin holds (signed-pdf-design.md §3c).
 *
 * Never a *silent* unsigned PDF: `configure()` throws rather than no-op when
 * signing isn't available. An install with no certificate still prints
 * (1.0.0-review F-042, owner ruling 2026-09-14) - the writers check
 * `availability()`, skip `configure()`, and `mark_unsigned()` stamps every
 * page, so an unsigned copy can never pass for a signed one.
 *
 * `BE_PDF_SIGNING_PASSPHRASE` undefined or empty means "the key has no
 * passphrase" (an `-nodes`/`-noenc`-generated key), not a misconfiguration -
 * so an existing unencrypted key keeps working rather than being stranded.
 *
 * @see BE_PROCESS/design/signed-pdf-design.md §3c
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

	/**
	 * Stamps a red UNSIGNED notice into the top margin of every page. Call it
	 * after all content is drawn, so it reaches every page however many the
	 * content ran to, and only on a document `configure()` never touched.
	 *
	 * @param \TCPDF $pdf
	 */
	public static function mark_unsigned( \TCPDF $pdf ): void {
		$notice = __( 'UNSIGNED - printed without a signing certificate. Nothing proves this copy is unaltered.', 'beyond-elysium' );
		$left   = (float) $pdf->getMargins()['left'];

		for ( $page = 1, $pages = $pdf->getNumPages(); $page <= $pages; $page++ ) {
			$pdf->setPage( $page );
			$pdf->setAutoPageBreak( false );
			$pdf->setFont( 'dejavusans', 'B', 8 );
			$pdf->setTextColor( 176, 0, 32 );
			$pdf->setXY( $left, 5.0 );
			$pdf->Cell( 0, 5, $notice, 0, 0, 'C' );
		}

		$pdf->setTextColor( 0, 0, 0 );
		$pdf->lastPage();
	}
}
