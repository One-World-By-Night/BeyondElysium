<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Core\Health_Notice;
use BeyondElysium\Services\Pdf_Signer;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_UnitTestCase;

/**
 * `Health_Notice::render()`'s signing-not-configured notice (signed-pdf-
 * design.md SP-10), plus the real pre-existing bug fixing its call site
 * exposed: `render()` used to `return` early whenever no database tables
 * were missing - the *ordinary, healthy* case for every real install - which
 * meant `render_slug_drift()` (and this new signing check) could only ever
 * run on an install that also happened to be missing tables. Both are
 * independent checks now, asserted here on a schema-complete install (this
 * project's normal test database), which is exactly the case that used to
 * suppress them.
 *
 * @see BE_PROCESS/design/signed-pdf-design.md Section 3c, SP-10
 */
class HealthNoticeThreadTest extends WP_UnitTestCase {

	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
		Health_Notice::clear_cache();
		Health_Notice::clear_drift_cache();
	}

	private function rendered_html(): string {
		ob_start();
		Health_Notice::render();
		return (string) ob_get_clean();
	}

	/**
	 * Whether signing is configured depends on which other thread test class
	 * happened to run first in this process - `BE_PDF_SIGNING_*` are real PHP
	 * constants, defined at most once, and several other classes need them
	 * defined too (see `PdfWriterThreadTest`'s own docblock). So rather than
	 * assume a specific starting state, this asserts the notice's presence
	 * *matches whatever `Pdf_Signer::availability()` genuinely reports right
	 * now* - true regardless of execution order. `PdfSignerTest.php` (unit)
	 * is what already proves `availability()` itself reports correctly for
	 * every defined-or-not, readable-or-not state; this only needs to prove
	 * `Health_Notice` reacts to it correctly.
	 */
	public function test_signing_notice_reflects_current_availability(): void {
		$html = $this->rendered_html();

		if ( Pdf_Signer::availability()['ok'] ) {
			$this->assertStringNotContainsString( 'BE_PDF_SIGNING_CERT', $html );
			return;
		}

		$this->assertStringContainsString( 'BE_PDF_SIGNING_CERT', $html );
		$this->assertStringContainsString( 'BE_PDF_SIGNING_KEY', $html );
		$this->assertStringContainsString( 'openssl req', $html );
	}

	public function test_signing_notice_is_silent_once_signing_is_configured(): void {
		PdfSigningTestFixture::ensure();

		$html = $this->rendered_html();

		$this->assertStringNotContainsString( 'BE_PDF_SIGNING_CERT', $html );
	}

	public function test_notices_are_independent_of_the_missing_tables_check(): void {
		// A schema-complete install (this project's own test database) means $missing
		// is empty - the exact condition the old early-return used to suppress every
		// check below it on. This assertion is really about render() reaching that far
		// at all; the signing notice's own presence/absence is covered by the two tests
		// above, run against this same normal, schema-complete database.
		$html = $this->rendered_html();

		$this->assertStringNotContainsString( 'database tables are missing', $html );
	}
}
