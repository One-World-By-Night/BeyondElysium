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
$ast = $hst;

return [
	'hst'      => $hst,
	'ast'      => $ast,
	'narrator' => [
		'be_manage_plots',
		'be_run_queries',
		'be_view_characters',
		'be_submit_actions',
	],
	'player'   => [
		'be_view_characters',
		'be_edit_own_characters',
		'be_submit_actions',
	],
];
