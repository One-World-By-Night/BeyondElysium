<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Core\Health_Notice;
use BeyondElysium\Services\Pdf_Signer;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_UnitTestCase;

/**
 * `Health_Notice::render()`'s signing-not-configured notice, shown even when no database tables are missing.
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
	 * The signing notice shows exactly when signing is unavailable.
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
		// A schema-complete install (the test database) means $missing is empty.
		$html = $this->rendered_html();

		$this->assertStringNotContainsString( 'database tables are missing', $html );
	}

	public function test_the_site_export_notice_is_silent_on_a_single_site_install(): void {
		$html = $this->rendered_html();

		$this->assertStringNotContainsString( 'site-export mu-plugin', $html );
	}

	/**
	 * @group multisite
	 */
	public function test_the_site_export_notice_warns_a_super_admin_when_the_mu_plugin_is_absent(): void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Needs a multisite network (WP_MULTISITE=1).' );
		}
		grant_super_admin( $this->admin_id );

		$html = $this->rendered_html();

		if ( defined( 'BE_SITE_EXPORT_MU_VERSION' ) ) {
			$this->assertStringNotContainsString( 'site-export mu-plugin', $html );
			return;
		}
		$this->assertStringContainsString( 'site-export mu-plugin', $html );
		$this->assertStringContainsString( 'mu-plugin/be-site-export.php', $html );
	}
}
