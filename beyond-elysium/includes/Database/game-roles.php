<?php
/**
 * Chronicle role -> capability grants, scoped to a single game.
 *
 * Maps each in-game role (hst, ast, narrator, player) to the list of
 * capabilities it holds within that one game's membership (be_game_members),
 * checked by Authorization::check_request(). HST and AST both derive from
 * Capabilities::all(), excluding be_manage_games, which this map never
 * grants at any role.
 *
 * @return array<string,string[]> role => capabilities granted within that one game.
 */

defined( 'ABSPATH' ) || exit;

$hst = array_values( array_diff( \BeyondElysium\Core\Capabilities::all(), [ 'be_manage_games' ] ) );
// Owner ruling, 1.0.0-checklist.md item 27 (2026-09-15), narrower than the 2026-09-11 "AST can
// do everything but delete and edit game" model: an AST loses Chronicle Setup's own Approval
// Rules section, the chronicle's own schema blocks AND templates (both halves of GS-1's
// "catalog and templates" fork), and deleting a character - keeps everything else, including
// import, transfers, and the bulk XP/status/reset operations.
$ast = array_values( array_diff( $hst, [
	'be_manage_approval_rules',
	'be_manage_schemas',
	'be_manage_templates',
	'be_manage_chronicle_setup',
	'be_delete_characters',
] ) );

return [
	'hst'      => $hst,
	'ast'      => $ast,
	// No be_run_queries: the Query Tool reads whole sheets and is a Storyteller's (owner ruling,
	// 1.0.0-review F-041). Rumor targeting runs its own queries under be_manage_plots.
	'narrator' => [
		'be_manage_plots',
		'be_view_characters',
		'be_submit_actions',
		'be_view_reports',
	],
	// A fifth role (BE_PROCESS/0.99.2-workflow.md): runs the boon ledger only - no
	// Storyteller powers over characters, plots, or the rest of the world-object catalog.
	// be_view_characters is included deliberately: a Harpy needs to look up who owes whom.
	'boons'    => [
		'be_manage_boons',
		'be_view_characters',
		'be_view_reports',
	],
	'player'   => [
		'be_view_characters',
		'be_edit_own_characters',
		'be_submit_actions',
		'be_view_reports',
	],
];
