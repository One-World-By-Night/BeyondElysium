<?php
/**
 * Chronicle role -> capability grants, scoped to a single game.
 *
 * @return array<string,string[]> role => capabilities granted within that one game.
 */

defined( 'ABSPATH' ) || exit;

$hst = array_values( array_diff( \BeyondElysium\Core\Capabilities::all(), [ 'be_manage_games' ] ) );
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
	// No be_run_queries: the Query Tool reads whole sheets and is a Storyteller's.
	'narrator' => [
		'be_manage_plots',
		'be_view_characters',
		'be_submit_actions',
		'be_view_reports',
		'be_manage_sessions',
	],
	// A fifth role: runs the boon ledger only.
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
