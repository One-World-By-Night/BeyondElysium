<?php

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'owc_asc_check_access' ) ) {
	/**
	 * Answers false unless a test lists the role path under the email in `$be_test_asc_paths_held`.
	 */
	function owc_asc_check_access( $client_id, $email, $role_path, $include_children = true ) {
		global $be_test_captured_role_paths, $be_test_asc_call_count, $be_test_asc_paths_held;
		$be_test_captured_role_paths[] = $role_path;
		$be_test_asc_call_count        = ( $be_test_asc_call_count ?? 0 ) + 1;
		return in_array( $role_path, $be_test_asc_paths_held[ $email ] ?? [], true );
	}
}
