<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\User_Settings;
use WP_UnitTestCase;

/**
 * Decision 041: `be_customize_sheet` granted per-user (WP user meta, toggled on the
 * standard profile.php/user-edit.php screen), additive to the role-based grant in
 * `Capabilities::CAPS` - never a replacement for it.
 */
class UserSettingsTest extends WP_UnitTestCase {

	public function test_a_subscriber_with_the_meta_flag_gets_the_capability(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$this->assertFalse( current_user_can( 'be_customize_sheet' ), 'A bare subscriber must not have it by default.' );

		update_user_meta( $user_id, User_Settings::CUSTOMIZE_SHEET_META, '1' );
		// Capabilities are cached on the WP_User object - re-fetch the current user so
		// the filter is re-evaluated against the fresh meta value.
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'be_customize_sheet' ), 'The per-user grant must be additive, not require a role change.' );
	}

	public function test_the_meta_flag_never_grants_anything_else(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, User_Settings::CUSTOMIZE_SHEET_META, '1' );
		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can( 'be_manage_characters' ), 'The grant must be scoped to exactly this one capability.' );
	}

	public function test_an_editor_still_has_it_via_role_with_no_meta_set(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'be_customize_sheet' ), 'The role-based grant (Capabilities::CAPS) must be unaffected by this addition.' );
	}

	public function test_only_an_admin_can_save_the_field_for_another_user(): void {
		$target_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$_POST[ User_Settings::CUSTOMIZE_SHEET_META ] = '1';
		User_Settings::save_fields( $target_id );
		unset( $_POST[ User_Settings::CUSTOMIZE_SHEET_META ] );

		$this->assertSame(
			'',
			get_user_meta( $target_id, User_Settings::CUSTOMIZE_SHEET_META, true ),
			'An editor (not manage_options) must not be able to grant this to someone else.'
		);
	}

	public function test_an_administrator_can_save_the_field_for_another_user(): void {
		$target_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$_POST[ User_Settings::CUSTOMIZE_SHEET_META ] = '1';
		User_Settings::save_fields( $target_id );
		unset( $_POST[ User_Settings::CUSTOMIZE_SHEET_META ] );

		$this->assertSame( '1', get_user_meta( $target_id, User_Settings::CUSTOMIZE_SHEET_META, true ) );
	}
}
