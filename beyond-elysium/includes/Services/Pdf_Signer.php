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
	 * The site-wide secure-printing switch. Absent means off (1.0.1 C2).
	 *
	 * Site-wide rather than per-chronicle because the certificate itself is site-wide: a
	 * per-chronicle switch would imply per-chronicle certificates, which nobody asked for and
	 * which multiplies the one genuinely dangerous thing here, key handling.
	 */
	const OPT_IN_OPTION = 'be_secure_printing';

	/** Whether an administrator has switched secure printing on. Default off. */
	public static function opted_in(): bool {
		return (bool) get_option( self::OPT_IN_OPTION, false );
	}

	/**
	 * Whether a document should actually be signed - the question every writer asks, as
	 * opposed to `availability()`'s narrower "is a usable certificate configured".
	 *
	 * Deliberately a separate method rather than folding the option into `availability()`:
	 * the settings screen and the admin health notice need to tell "no certificate" apart
	 * from "certificate present, signing switched off", and a single merged answer cannot.
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function should_sign(): array {
		if ( ! self::opted_in() ) {
			return [ 'ok' => false, 'code' => 'secure_printing_off' ];
		}

		return self::availability();
	}

	/**
	 * Which of the three constants are defined, and whether the two file paths are readable -
	 * for the settings screen to say "you're set up" or name what's missing.
	 *
	 * Reports the passphrase constant only as defined or not. Its value is read on exactly one
	 * line of this class, in `configure()`, and never leaves it.
	 *
	 * @return array<string,array{defined:bool,readable:bool|null}>
	 */
	public static function constant_report(): array {
		$report = [];
		foreach ( [ 'BE_PDF_SIGNING_CERT', 'BE_PDF_SIGNING_KEY' ] as $name ) {
			$value           = self::const_string( $name );
			$report[ $name ] = [
				'defined'  => $value !== '',
				'readable' => $value !== '' ? is_readable( $value ) : null,
			];
		}
		// An empty passphrase is a legitimate configuration (an -nodes key), not a gap, so
		// this row is informational and never a failure on its own.
		$report['BE_PDF_SIGNING_PASSPHRASE'] = [
			'defined'  => defined( 'BE_PDF_SIGNING_PASSPHRASE' ),
			'readable' => null,
		];

		return $report;
	}

	/**
	 * Whether this host can mint a pair at all. The openssl *extension*, not the command-line
	 * binary: the binary needs shell access, which shared hosting commonly blocks, and that is
	 * the gap generation exists to close.
	 */
	public static function can_generate(): bool {
		return function_exists( 'openssl_pkey_new' )
			&& function_exists( 'openssl_csr_new' )
			&& function_exists( 'openssl_csr_sign' )
			&& function_exists( 'openssl_pkey_export' )
			&& function_exists( 'openssl_x509_export' );
	}

	/**
	 * Mints a self-signed certificate and an encrypted private key in memory, and returns
	 * them with the `wp-config.php` lines to paste.
	 *
	 * Writes nothing. Not the key, not the passphrase, not the certificate - not to the
	 * filesystem and not to the database. The caller hands the result to the administrator
	 * once and forgets it; there is deliberately no way to ask for it again.
	 *
	 * Self-signed is the point. A reader will report "signature valid, signer not trusted"
	 * rather than a green tick, which is what a chronicle's own attestation actually is.
	 *
	 * @param string $common_name Shown as the signer's name in a PDF reader.
	 * @param string $passphrase  Encrypts the exported key. Not retained.
	 * @param int    $days        Validity.
	 * @return array<string,string>|\WP_Error
	 */
	public static function generate( string $common_name, string $passphrase, int $days ) {
		if ( ! self::can_generate() ) {
			return new \WP_Error( 'openssl_unavailable', __( 'This server has no openssl extension.', 'beyond-elysium' ), [ 'status' => 501 ] );
		}

		$key = openssl_pkey_new( [
			'private_key_bits' => 4096,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );
		if ( $key === false ) {
			return new \WP_Error( 'keygen_failed', self::openssl_error(), [ 'status' => 500 ] );
		}

		$dn  = [ 'commonName' => $common_name !== '' ? $common_name : 'Beyond Elysium' ];
		$csr = openssl_csr_new( $dn, $key, [ 'digest_alg' => 'sha256' ] );
		if ( $csr === false ) {
			return new \WP_Error( 'csr_failed', self::openssl_error(), [ 'status' => 500 ] );
		}

		$x509 = openssl_csr_sign( $csr, null, $key, $days, [ 'digest_alg' => 'sha256' ] );
		if ( $x509 === false ) {
			return new \WP_Error( 'sign_failed', self::openssl_error(), [ 'status' => 500 ] );
		}

		$cert_pem = '';
		$key_pem  = '';
		if ( ! openssl_x509_export( $x509, $cert_pem ) || ! openssl_pkey_export( $key, $key_pem, $passphrase ) ) {
			return new \WP_Error( 'export_failed', self::openssl_error(), [ 'status' => 500 ] );
		}

		return [
			'certificate' => $cert_pem,
			'private_key' => $key_pem,
			'common_name' => $dn['commonName'],
			'expires'     => gmdate( 'Y-m-d', time() + ( $days * DAY_IN_SECONDS ) ),
		];
	}

	/** Drains openssl's error queue into one message, so a failure says something real. */
	private static function openssl_error(): string {
		$messages = [];
		while ( $message = openssl_error_string() ) { // phpcs:ignore
			$messages[] = $message;
		}

		return $messages === []
			? __( 'OpenSSL reported no reason.', 'beyond-elysium' )
			: implode( '; ', $messages );
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
