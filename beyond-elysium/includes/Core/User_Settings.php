<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Beyond Elysium's per-user settings fields to WordPress's own
 * user-profile screen (`profile.php`/`user-edit.php`). Reads and writes
 * both a sheet-customization grant checkbox and a change-notification
 * opt-out checkbox as user meta, and additively grants the
 * be_customize_sheet capability to any user whose checkbox is set.
 */
class User_Settings {

	/** User meta key. '1' when granted, absent/empty otherwise. */
	const CUSTOMIZE_SHEET_META = 'be_customize_sheet';

	/** User meta key. '1' when this user has opted out of change approved/rejected mail; absent/empty means notifications are on. */
	const NOTIFICATIONS_OPT_OUT_META = 'be_notifications_opt_out';

	/**
	 * Hooks this class's field rendering and saving onto WordPress's
	 * profile screens (show/edit_user_profile,
	 * personal_options_update/edit_user_profile_update), and
	 * grant_from_meta() onto user_has_cap.
	 */
	public static function register(): void {
		add_action( 'show_user_profile', [ self::class, 'render_fields' ] );
		add_action( 'edit_user_profile', [ self::class, 'render_fields' ] );
		add_action( 'personal_options_update', [ self::class, 'save_fields' ] );
		add_action( 'edit_user_profile_update', [ self::class, 'save_fields' ] );
		add_filter( 'user_has_cap', [ self::class, 'grant_from_meta' ], 10, 4 );
	}

	/**
	 * Renders this plugin's fields on the user-profile screen: a sheet-
	 * customization checkbox visible to users who can manage_options, and
	 * a notification opt-out checkbox visible to anyone who can edit this
	 * profile. Renders nothing if neither applies to the current viewer.
	 *
	 * @param \WP_User $user The profile being viewed - not necessarily the current user.
	 */
	public static function render_fields( \WP_User $user ): void {
		// The two checkboxes are shown independently, gated on their own capability checks.
		$can_grant_customize = current_user_can( 'manage_options' );
		$can_set_notifications = current_user_can( 'edit_user', $user->ID );

		if ( ! $can_grant_customize && ! $can_set_notifications ) {
			return;
		}

		$customize_checked    = get_user_meta( $user->ID, self::CUSTOMIZE_SHEET_META, true ) === '1';
		$notifications_opt_out = get_user_meta( $user->ID, self::NOTIFICATIONS_OPT_OUT_META, true ) === '1';
		?>
		<h2><?php esc_html_e( 'Beyond Elysium', 'beyond-elysium' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php if ( $can_grant_customize ) : ?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( self::CUSTOMIZE_SHEET_META ); ?>">
						<?php esc_html_e( 'Sheet Customization', 'beyond-elysium' ); ?>
					</label>
				</th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( self::CUSTOMIZE_SHEET_META ); ?>"
							id="<?php echo esc_attr( self::CUSTOMIZE_SHEET_META ); ?>"
							value="1"
							<?php checked( $customize_checked ); ?>
						/>
						<?php esc_html_e( 'Allow this user to customize their own character sheets (font, colors, background image, section graphics)', 'beyond-elysium' ); ?>
					</label>
				</td>
			</tr>
			<?php endif; ?>
			<?php if ( $can_set_notifications ) : ?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( self::NOTIFICATIONS_OPT_OUT_META ); ?>">
						<?php esc_html_e( 'Change Notifications', 'beyond-elysium' ); ?>
					</label>
				</th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( self::NOTIFICATIONS_OPT_OUT_META ); ?>"
							id="<?php echo esc_attr( self::NOTIFICATIONS_OPT_OUT_META ); ?>"
							value="1"
							<?php checked( $notifications_opt_out ); ?>
						/>
						<?php esc_html_e( 'Do not email me when a storyteller approves or rejects one of my submitted character changes', 'beyond-elysium' ); ?>
					</label>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Saves this plugin's profile fields from $_POST. Requires edit_user
	 * capability on the target profile. Only saves the sheet-customization
	 * grant when the current user can manage_options; always saves the
	 * notification opt-out.
	 *
	 * @param int $user_id The profile being saved.
	 */
	public static function save_fields( int $user_id ): void {
		// edit_user is required to change anything on this screen at all.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Only a manage_options user can grant sheet customization.
		if ( current_user_can( 'manage_options' ) ) {
			$granted = isset( $_POST[ self::CUSTOMIZE_SHEET_META ] ) && $_POST[ self::CUSTOMIZE_SHEET_META ] === '1';
			update_user_meta( $user_id, self::CUSTOMIZE_SHEET_META, $granted ? '1' : '' );
		}

		$opted_out = isset( $_POST[ self::NOTIFICATIONS_OPT_OUT_META ] ) && $_POST[ self::NOTIFICATIONS_OPT_OUT_META ] === '1';
		update_user_meta( $user_id, self::NOTIFICATIONS_OPT_OUT_META, $opted_out ? '1' : '' );
	}

	/**
	 * Adds be_customize_sheet to a user's capabilities when their user
	 * meta flag is set, regardless of their role's own grants. Only ever
	 * adds the capability; never removes one a role already granted.
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array<int,mixed>   $args
	 * @param \WP_User           $user
	 * @return array<string,bool>
	 */
	public static function grant_from_meta( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( in_array( 'be_customize_sheet', $caps, true )
			&& get_user_meta( $user->ID, self::CUSTOMIZE_SHEET_META, true ) === '1'
		) {
			$allcaps['be_customize_sheet'] = true;
		}
		return $allcaps;
	}
}
