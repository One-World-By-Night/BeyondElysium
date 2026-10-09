<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Beyond Elysium's per-user settings fields to WordPress's own user-profile screen
 * (`profile.php`/`user-edit.php`).
 */
class User_Settings {

	/**
	 * User meta key.
	 */
	const CUSTOMIZE_SHEET_META = 'be_customize_sheet';

	/**
	 * User meta key.
	 */
	const NOTIFICATIONS_OPT_OUT_META = 'be_notifications_opt_out';

	/**
	 * User meta key.
	 */
	const PLOT_NOTIFY_META = 'be_plot_notify';

	/** @var string[] Valid PLOT_NOTIFY_META values. */
	const PLOT_NOTIFY_VALUES = [ 'immediate', 'daily', 'off' ];

	/**
	 * Hooks the profile-screen field rendering and saving onto WordPress, grant_from_meta() onto user_has_cap, and the
	 * media picker's query restriction.
	 */
	public static function register(): void {
		add_action( 'show_user_profile', [ self::class, 'render_fields' ] );
		add_action( 'edit_user_profile', [ self::class, 'render_fields' ] );
		add_action( 'personal_options_update', [ self::class, 'save_fields' ] );
		add_action( 'edit_user_profile_update', [ self::class, 'save_fields' ] );
		add_filter( 'user_has_cap', [ self::class, 'grant_from_meta' ], 10, 4 );
		add_filter( 'ajax_query_attachments_args', [ self::class, 'restrict_media_query_for_non_staff' ], 10, 1 );
	}

	/**
	 * Renders this plugin's fields on the user-profile screen: a sheet-customization checkbox visible to users who can
	 * manage_options, and a notification opt-out checkbox visible to anyone who can edit this profile.
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
		$plot_notify = get_user_meta( $user->ID, self::PLOT_NOTIFY_META, true );
		if ( ! in_array( $plot_notify, self::PLOT_NOTIFY_VALUES, true ) ) {
			$plot_notify = 'immediate';
		}
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
			<?php if ( $can_set_notifications ) : ?>
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Plot posts and new things your characters can see', 'beyond-elysium' ); ?>
				</th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">
							<?php esc_html_e( 'Plot posts and new things your characters can see', 'beyond-elysium' ); ?>
						</legend>
						<label>
							<input
								type="radio"
								name="<?php echo esc_attr( self::PLOT_NOTIFY_META ); ?>"
								value="immediate"
								<?php checked( $plot_notify, 'immediate' ); ?>
							/>
							<?php esc_html_e( 'Immediately', 'beyond-elysium' ); ?>
						</label><br />
						<label>
							<input
								type="radio"
								name="<?php echo esc_attr( self::PLOT_NOTIFY_META ); ?>"
								value="daily"
								<?php checked( $plot_notify, 'daily' ); ?>
							/>
							<?php esc_html_e( 'Daily digest', 'beyond-elysium' ); ?>
						</label><br />
						<label>
							<input
								type="radio"
								name="<?php echo esc_attr( self::PLOT_NOTIFY_META ); ?>"
								value="off"
								<?php checked( $plot_notify, 'off' ); ?>
							/>
							<?php esc_html_e( 'Off', 'beyond-elysium' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When a plot you can see gets a new post, or a plot, item, location or secret newly becomes something one of your characters can see - who posted or what changed, with a link. Never the post or content itself.', 'beyond-elysium' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Saves this plugin's profile fields from $_POST.
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

		$plot_notify = isset( $_POST[ self::PLOT_NOTIFY_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::PLOT_NOTIFY_META ] ) ) : 'immediate';
		if ( ! in_array( $plot_notify, self::PLOT_NOTIFY_VALUES, true ) ) {
			$plot_notify = 'immediate';
		}
		update_user_meta( $user_id, self::PLOT_NOTIFY_META, $plot_notify );
	}

	/**
	 * Adds be_customize_sheet to a user's capabilities when their user meta flag is set, regardless of their role's
	 * own grants. Also grants upload_files alongside it, for the sheet style editor's background-image picker.
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array<int,mixed>   $args
	 * @param \WP_User           $user
	 * @return array<string,bool>
	 */
	public static function grant_from_meta( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( get_user_meta( $user->ID, self::CUSTOMIZE_SHEET_META, true ) !== '1' ) {
			return $allcaps;
		}
		if ( in_array( 'be_customize_sheet', $caps, true ) ) {
			$allcaps['be_customize_sheet'] = true;
		}
		if ( in_array( 'upload_files', $caps, true ) ) {
			$allcaps['upload_files'] = true;
		}
		return $allcaps;
	}

	/**
	 * Restricts the media picker's library query to the viewer's own uploads for anyone without
	 * `edit_others_posts`, such as a player holding upload_files through the grant above.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public static function restrict_media_query_for_non_staff( array $args ): array {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return $args;
		}
		$args['author'] = get_current_user_id();
		return $args;
	}
}
