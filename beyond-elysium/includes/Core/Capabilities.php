<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Defines Beyond Elysium's custom WordPress capabilities and the roles that receive each one by default.
 */
class Capabilities {

	/**
	 * All custom capabilities and the roles that receive them by default.
	 */
	private const CAPS = [
		'be_manage_games'        => [ 'administrator' ],
		// Schema blocks and templates: an HST edits their own chronicle's copies.
		'be_manage_schemas'      => [ 'administrator', 'editor' ],
		'be_manage_templates'    => [ 'administrator', 'editor' ],
		// Chronicle Setup: an HST saves their own chronicle's creature types, sub-faction restrictions and approval policy.
		'be_manage_chronicle_setup' => [ 'administrator', 'editor' ],
		'be_manage_characters'   => [ 'administrator', 'editor' ],
		// Deleting characters, separate from editing them.
		'be_delete_characters'   => [ 'administrator', 'editor' ],
		'be_edit_own_characters' => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
		'be_view_characters'     => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
		'be_manage_plots'        => [ 'administrator', 'editor' ],
		'be_submit_actions'      => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
		'be_manage_world_objects'=> [ 'administrator', 'editor' ],
		'be_manage_connections'  => [ 'administrator', 'editor' ],
		'be_run_queries'         => [ 'administrator', 'editor' ],
		// Chronicle-scoped like be_manage_characters, not a site-wide catalog capability.
		'be_import'              => [ 'administrator', 'editor' ],
		// Grants sheet cosmetic overrides: fonts, colors, background, section graphics.
		'be_customize_sheet'     => [ 'administrator', 'editor' ],
		// Chronicle-scoped like be_manage_characters: create/edit/delete approval overrides.
		'be_manage_approval_rules' => [ 'administrator', 'editor' ],
		// Boons only, narrower than be_manage_world_objects.
		'be_manage_boons'          => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
		// Action allocation and rumor generation settings.
		'be_manage_apr'            => [ 'administrator', 'editor' ],
		// Viewing and printing reports, including a player's Sign-In Sheet.
		'be_view_reports'          => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
		// Game nights and attendance.
		'be_manage_sessions'       => [ 'administrator', 'editor' ],
		// Factions: sects, coteries, packs, courts and their positions.
		'be_manage_factions'       => [ 'administrator', 'editor' ],
		// Catalog term translations, site-wide.
		'be_manage_translations'  => [ 'administrator', 'editor' ],
	];

	/**
	 * Returns every custom capability this plugin defines, as a plain list with the per-role grants stripped off.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::CAPS );
	}

	/**
	 * Adds every capability in CAPS to each of its listed roles, via WP_Role::add_cap().
	 */
	public static function register(): void {
		foreach ( self::CAPS as $cap => $role_names ) {
			foreach ( $role_names as $role_name ) {
				$role = get_role( $role_name );
				if ( $role ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Removes every capability in CAPS from each of its listed roles, via WP_Role::remove_cap().
	 */
	public static function unregister(): void {
		foreach ( self::CAPS as $cap => $role_names ) {
			foreach ( $role_names as $role_name ) {
				$role = get_role( $role_name );
				if ( $role ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
