<?php

defined( 'ABSPATH' ) || exit;

/**
 * owbn-core's Toolkit connection settings, answered from `$be_test_oat`: a config per blog id, or one under '*' for
 * every blog. Nothing set reads as a local-mode site with no address and no key.
 */
if ( ! function_exists( 'owc_get_mode' ) ) {
	function owc_get_mode( $type ) {
		global $be_test_oat;
		$config = $be_test_oat[ get_current_blog_id() ] ?? $be_test_oat['*'] ?? [];
		return $config['mode'] ?? 'local';
	}
}
if ( ! function_exists( 'owc_get_remote_base' ) ) {
	function owc_get_remote_base( $type = '' ) {
		global $be_test_oat;
		$config = $be_test_oat[ get_current_blog_id() ] ?? $be_test_oat['*'] ?? [];
		return $config['base'] ?? '';
	}
}
if ( ! function_exists( 'owc_get_remote_key' ) ) {
	function owc_get_remote_key( $type = '' ) {
		global $be_test_oat;
		$config = $be_test_oat[ get_current_blog_id() ] ?? $be_test_oat['*'] ?? [];
		return $config['key'] ?? '';
	}
}
if ( ! defined( 'OWC_PLAYER_ID_META_KEY' ) ) {
	define( 'OWC_PLAYER_ID_META_KEY', 'player_id' );
}
if ( ! function_exists( 'owc_get_coordinators' ) ) {
	/**
	 * The coordinator offices owbn-core lists, from `$be_test_coordinators`.
	 */
	function owc_get_coordinators() {
		global $be_test_coordinators;
		return $be_test_coordinators ?? [];
	}
}
