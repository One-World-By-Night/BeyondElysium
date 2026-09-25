<?php

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'owc_asc_grant_role' ) ) {
	function owc_asc_grant_role( $client_id, $email, $role_path ) {
		$GLOBALS['be_test_asc_calls'][] = [ 'grant', $email, $role_path ];
		return $GLOBALS['be_test_asc_grant_result'] ?? [ 'email' => $email, 'granted' => true ];
	}
}

if ( ! function_exists( 'owc_asc_revoke_role' ) ) {
	function owc_asc_revoke_role( $client_id, $email, $role_path ) {
		$GLOBALS['be_test_asc_calls'][] = [ 'revoke', $email, $role_path ];
		return [ 'email' => $email, 'revoked' => true ];
	}
}

if ( ! function_exists( 'owc_asc_refresh_user_roles_safe' ) ) {
	function owc_asc_refresh_user_roles_safe( $user_id ) {
		$GLOBALS['be_test_asc_calls'][] = [ 'refresh', (int) $user_id, null ];
		return true;
	}
}

if ( ! function_exists( 'owc_asc_get_user_roles' ) ) {
	function owc_asc_get_user_roles( $client_id, $email ) {
		return [ 'roles' => $GLOBALS['be_test_asc_user_roles'][ $email ] ?? [] ];
	}
}
