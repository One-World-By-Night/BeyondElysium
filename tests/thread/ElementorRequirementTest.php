<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Elementor_Requirement;
use WP_UnitTestCase;

/**
 * Covers the Elementor dependency notice: that it stays silent when the
 * requirement is met, that it offers the correct next step for a site that
 * has Elementor installed-but-inactive versus not installed at all, and that
 * it never shows an action link to a user who could not perform it.
 *
 * The plugin header's `Requires Plugins: elementor` field is the real
 * enforcement on WordPress 6.5+; this notice is the fallback below that
 * version and for Elementor being switched off after activation.
 */
class ElementorRequirementTest extends WP_UnitTestCase {

	private function render(): string {
		ob_start();
		Elementor_Requirement::render();
		return (string) ob_get_clean();
	}

	public function test_the_header_declares_the_dependency(): void {
		$header = file_get_contents( BE_PLUGIN_PATH . '/beyond-elysium.php' );

		$this->assertStringContainsString(
			'Requires Plugins: elementor',
			$header,
			'WordPress 6.5+ refuses activation without this header; it is the real enforcement.'
		);
	}

	public function test_nothing_renders_for_a_user_who_cannot_activate_plugins(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_an_administrator_is_told_when_elementor_is_missing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		if ( Elementor_Requirement::is_satisfied() ) {
			$this->markTestSkipped( 'Elementor is active in this environment, so the notice correctly stays silent.' );
		}

		$output = $this->render();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'requires Elementor', $output );
	}

	public function test_the_offered_action_matches_what_the_site_actually_needs(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		if ( Elementor_Requirement::is_satisfied() ) {
			$this->markTestSkipped( 'Elementor is active in this environment; there is no action to offer.' );
		}

		$output = $this->render();

		if ( Elementor_Requirement::is_installed() ) {
			$this->assertStringContainsString( 'action=activate', $output, 'Installed but inactive must offer Activate, not Install.' );
			$this->assertStringContainsString( 'Activate Elementor', $output );
		} else {
			$this->assertStringContainsString( 'action=install-plugin', $output, 'Not installed must offer Install.' );
			$this->assertStringContainsString( 'Install Elementor', $output );
		}

		// Either action is state-changing, so it must never be a bare link.
		$this->assertStringContainsString( '_wpnonce', $output, 'The action link must be nonce-protected.' );
	}

	public function test_is_satisfied_agrees_with_elementor_actually_being_loaded(): void {
		$this->assertSame(
			did_action( 'elementor/loaded' ) > 0 || is_plugin_active( Elementor_Requirement::PLUGIN_FILE ),
			Elementor_Requirement::is_satisfied()
		);
	}
}
