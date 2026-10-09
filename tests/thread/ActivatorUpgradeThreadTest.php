<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Activator;
use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Template;
use WP_UnitTestCase;

/**
 * Activating the plugin on an install behind this version runs the same whole upgrade an update does, and records the
 * version only once that upgrade has run.
 */
class ActivatorUpgradeThreadTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'be_upgrade_error' );
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => 'be_upgrade_lock' ] );
		wp_cache_delete( 'be_upgrade_lock', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * The system Vampire full sheet, with its Health section's width.
	 *
	 * @return array{0:object,1:string}
	 */
	private function vampire_sheet(): array {
		$template = null;
		foreach ( Template::globals( [ 'stack_slug' => 'vampire', 'template_type' => 'sheet_full' ] ) as $candidate ) {
			if ( ! empty( $candidate->is_system ) ) {
				$template = $candidate;
			}
		}
		$this->assertNotNull( $template );
		$width = '';
		foreach ( $template->layout['sections'] ?? [] as $section ) {
			if ( str_ends_with( (string) ( $section['block_slug'] ?? '' ), '-health' ) ) {
				$width = (string) ( $section['width'] ?? '' );
			}
		}
		return [ $template, $width ];
	}

	public function test_activating_an_install_behind_this_version_runs_the_whole_upgrade(): void {
		[ $template ] = $this->vampire_sheet();
		$layout       = $template->layout;
		foreach ( $layout['sections'] as &$section ) {
			if ( str_ends_with( (string) ( $section['block_slug'] ?? '' ), '-health' ) ) {
				$section['width'] = 'half';
			}
		}
		unset( $section );
		Template::update( (int) $template->id, [ 'layout' => $layout ] );
		update_option( Schema::VERSION_OPTION, '1.4.0.2' );

		Activator::activate();

		[ , $width ] = $this->vampire_sheet();
		$this->assertSame( 'full', $width, 'activation skipped a step only the upgrade runs' );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_option( 'be_upgrade_error' ) );
	}
}
