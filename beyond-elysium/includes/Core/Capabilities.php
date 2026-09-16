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
		// GS-1 (guided-chronicle-setup-design.md §10 Q1, owner ruling 2026-09-13): an HST is
		// a WordPress editor and needs both, so they can fork their own chronicle's catalog
		// and templates through the setup checklist - v0.21.20's be_import precedent exactly.
		// Both-or-neither with the game-scoped write routes added to Schema_Blocks_Controller
		// in the same release: granting this site-wide alone, with no chronicle-scoped second
		// layer to narrow it, would let any editor on the site edit the global catalog every
		// chronicle shares (Authorization::check_request() only reaches the be_game_members
		// layer when the route itself carries a game_slug URL param).
		'be_manage_schemas'      => [ 'administrator', 'editor' ],
		'be_manage_templates'    => [ 'administrator', 'editor' ],
		// Owner ruling, 1.0.0-checklist.md item 18 (2026-09-15): an HST saves their own
		// chronicle's creature types, sub-faction restrictions, and new-character approval
		// policy - previously be_manage_games only, unreachable by anyone but a site
		// administrator. AST excluded (item 27, game-roles.php).
		'be_manage_chronicle_setup' => [ 'administrator', 'editor' ],
		'be_manage_characters'   => [ 'administrator', 'editor' ],
		// Split from be_manage_characters (owner ruling, 1.0.0-checklist.md item 27): an AST
		// keeps every other character power - edit, bulk XP/status/resets - but not this one.
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
		// A fifth chronicle role, `boons` (BE_PROCESS/releases/0.99.2-workflow.md), needs a
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
		// Same breadth as be_view_characters: a player printing a Sign-In Sheet at a
		// live game is an ordinary use, not a Storyteller-only one. Row-level
		// visibility (NPC hiding, [ST]-marked text) is enforced inside
		// Report_Document/Query_Engine, not by narrowing this grant.
		'be_view_reports'          => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
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
