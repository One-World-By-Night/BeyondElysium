<?php

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'owc_asc_check_access' ) ) {
	function owc_asc_check_access( $client_id, $email, $role_path, $include_children = true ) {
		global $be_test_captured_role_paths, $be_test_asc_call_count;
		$be_test_captured_role_paths[] = $role_path;
		$be_test_asc_call_count        = ( $be_test_asc_call_count ?? 0 ) + 1;
		// Falls through to the capability+membership path.
		return false;
	}
}
