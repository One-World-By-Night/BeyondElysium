<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Defines Beyond Elysium's custom WordPress capabilities and the roles
 * that receive each one by default. Registers those capabilities onto
 * roles on activation and strips them back off on deactivation. The
 * capability list here is also the source other components (such as the
 * accessSchema role map) derive their own capability lists from.
 */
class Capabilities {

	/**
	 * All custom capabilities and the roles that receive them by default.
	 */
	private const CAPS = [
		'be_manage_games'        => [ 'administrator' ],
		'be_manage_schemas'      => [ 'administrator' ],
		'be_manage_templates'    => [ 'administrator' ],
		'be_manage_characters'   => [ 'administrator', 'editor' ],
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
		// A fifth chronicle role, `boons` (BE_PROCESS/0.99.2-workflow.md), needs a
		// narrower capability than be_manage_world_objects, which also covers items,
		// locations and rotes - granting that would hand a Harpy everything, not just boons.
		// Authorization::check_request() gates on the SITE-WIDE grant here first, then
		// narrows further by the game-scoped role - so this must include every WP role a
		// Harpy could plausibly be (a plain player, not necessarily WP staff), the same
		// breadth be_view_characters/be_edit_own_characters/be_submit_actions already use.
		// v0.21.20's be_import fix is the exact precedent: a game-scoped role grant alone
		// is never enough on its own, both layers are required.
		'be_manage_boons'          => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
		// Chronicle-scoped like be_manage_approval_rules: edits the chronicle's own
		// action-allocation and rumor-generation configuration. be_manage_games is
		// excluded from every chronicle role (see game-roles.php), so it cannot gate this.
		'be_manage_apr'            => [ 'administrator', 'editor' ],
	];

	/**
	 * Returns every custom capability this plugin defines, as a plain list
	 * with the per-role grants stripped off. Used by game-roles.php to
	 * build the chronicle-scoped role grants from the same source list.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::CAPS );
	}

	/**
	 * Adds every capability in CAPS to each of its listed roles, via
	 * WP_Role::add_cap(). Skips any role name that does not resolve to a
	 * real WP_Role.
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
	 * Removes every capability in CAPS from each of its listed roles, via
	 * WP_Role::remove_cap(). Skips any role name that does not resolve to
	 * a real WP_Role.
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
