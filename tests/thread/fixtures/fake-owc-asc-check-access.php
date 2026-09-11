<?php
// Global scope, deliberately - not namespaced: Authorization::check_asc_role_path() calls
// the unqualified owc_asc_check_access(), which PHP resolves against the global namespace
// when no same-named function exists in Authorization's own namespace (BeyondElysium\Core).
// This is exactly how the real accessSchema-client/owbn-core plugins define it - neither is
// installed in this test environment (PLATFORM.md), so ChronicleScopedAuthorizationTest's
// role-path-normalization test stubs it to capture the exact role_path it was called with.
//
// A separate file, not inline in the test: a bracketed `namespace { ... }` block cannot be
// mixed with the test file's own unbracketed `namespace BeyondElysium\Tests\Thread;`.

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'owc_asc_check_access' ) ) {
	function owc_asc_check_access( $client_id, $email, $role_path, $include_children = true ) {
		global $be_test_captured_role_paths, $be_test_asc_call_count;
		$be_test_captured_role_paths[] = $role_path;
		$be_test_asc_call_count        = ( $be_test_asc_call_count ?? 0 ) + 1;
		// Falls through to the capability+membership path - fine, this stub exists only to
		// inspect what was asked for. Every granting role gets tried in turn when this
		// always denies (Authorization::check_request()'s own loop), which is exactly why
		// this captures every call rather than just one.
		return false;
	}
}
