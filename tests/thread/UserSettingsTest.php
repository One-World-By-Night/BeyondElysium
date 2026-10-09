<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\User_Settings;
use WP_UnitTestCase;

/**
 * `be_customize_sheet` granted per-user (WP user meta, toggled on the standard profile.php/user-edit.php screen),
 * additive to the role-based grant in `Capabilities::CAPS`.
 */
class UserSettingsTest extends WP_UnitTestCase {

	public function test_a_subscriber_with_the_meta_flag_gets_the_capability(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$this->assertFalse( current_user_can( 'be_customize_sheet' ), 'A bare subscriber must not have it by default.' );

		update_user_meta( $user_id, User_Settings::CUSTOMIZE_SHEET_META, '1' );
		// Capabilities are cached on the WP_User object.
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'be_customize_sheet' ), 'The per-user grant must be additive, not require a role change.' );
	}

	public function test_the_meta_flag_also_grants_upload_files(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$this->assertFalse( current_user_can( 'upload_files' ), 'A bare subscriber must not have it by default.' );

		update_user_meta( $user_id, User_Settings::CUSTOMIZE_SHEET_META, '1' );
		wp_set_current_user( $user_id );

		$this->assertTrue(
			current_user_can( 'upload_files' ),
			'be_customize_sheet implies picking a background image through the native media library, which a bare subscriber has no other way to reach.'
		);
	}

	public function test_the_meta_flag_grants_nothing_beyond_the_two(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, User_Settings::CUSTOMIZE_SHEET_META, '1' );
		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can( 'be_manage_characters' ), 'The grant must be scoped to exactly these two capabilities.' );
		$this->assertFalse( current_user_can( 'edit_others_posts' ), 'The grant must never imply a real editor-tier account.' );
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

	// -------------------------------------------------------------------------
	// The native media library, once upload_files reaches a non-staff account.
	// -------------------------------------------------------------------------

	public function test_a_granted_players_own_media_query_is_restricted_to_their_own_uploads(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, User_Settings::CUSTOMIZE_SHEET_META, '1' );
		wp_set_current_user( $user_id );

		$args = User_Settings::restrict_media_query_for_non_staff( [] );

		$this->assertSame( $user_id, $args['author'] );
	}

	public function test_the_media_pickers_own_query_is_restricted_for_a_granted_player(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, User_Settings::CUSTOMIZE_SHEET_META, '1' );
		wp_set_current_user( $user_id );

		$args = apply_filters( 'ajax_query_attachments_args', [ 'post_type' => 'attachment', 'post_status' => 'inherit' ] );

		$this->assertSame( $user_id, $args['author'] );
	}

	public function test_the_media_pickers_own_query_is_left_alone_for_an_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$args = apply_filters( 'ajax_query_attachments_args', [ 'post_type' => 'attachment', 'post_status' => 'inherit' ] );

		$this->assertArrayNotHasKey( 'author', $args );
	}

	public function test_a_real_editors_media_query_is_left_unrestricted(): void {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$args = User_Settings::restrict_media_query_for_non_staff( [ 'post_status' => 'inherit' ] );

		$this->assertArrayNotHasKey( 'author', $args );
		$this->assertSame( [ 'post_status' => 'inherit' ], $args );
	}

	public function test_an_administrators_media_query_is_left_unrestricted(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$args = User_Settings::restrict_media_query_for_non_staff( [] );

		$this->assertArrayNotHasKey( 'author', $args );
	}

	public function test_a_bare_subscribers_media_query_is_restricted_even_without_the_grant(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$args = User_Settings::restrict_media_query_for_non_staff( [] );

		$this->assertSame( $user_id, $args['author'] );
	}
}
