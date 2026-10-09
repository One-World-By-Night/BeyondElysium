<?php
/**
 * Sets TranslatePress up for the help pages on beyondelysium.com, once TranslatePress and TranslatePress Developer are
 * installed and active. Run it with `wp eval-file`.
 *
 *   - Portuguese (Brazil) is the second language, its pages under /pt/.
 *   - The language switcher stays hidden unless BE_TP_SWITCHER=1.
 *   - The Developer add-on's SEO Pack is switched on: it is what translates an image's alt text.
 *   - The licence key in the file BE_TP_LICENSE_FILE is saved and checked with translatepress.com, then the file is
 *     removed. Without the variable the licence is left alone.
 *
 * Running it again changes nothing.
 */

if ( ! class_exists( 'TRP_Translate_Press' ) ) {
	fwrite( STDERR, "TranslatePress is not active.\n" );
	return;
}
if ( ! class_exists( 'TRP_Handle_Included_Addons' ) ) {
	fwrite( STDERR, "TranslatePress Developer is not active.\n" );
	return;
}

$trp      = TRP_Translate_Press::get_trp_instance();
$settings = $trp->get_component( 'settings' );

// The settings screen registers its sanitizer on admin_init; saving from here needs the same one, so the tables exist.
register_setting( 'trp_settings', 'trp_settings', [ $settings, 'sanitize_settings' ] );

$current = (array) get_option( 'trp_settings', [] );
$wanted  = $current;
$wanted['translation-languages'] = [ 'en_US', 'pt_BR' ];
$wanted['publish-languages']     = [ 'en_US', 'pt_BR' ];
$wanted['url-slugs']             = [
	'en_US' => 'en',
	'pt_BR' => 'pt',
];
$wanted['trp-ls-floater']        = '1' === getenv( 'BE_TP_SWITCHER' ) ? 'yes' : 'no';

if ( $wanted === $current ) {
	echo "Settings already as wanted.\n";
} else {
	update_option( 'trp_settings', $wanted );
	$saved = (array) get_option( 'trp_settings', [] );
	if ( ! in_array( 'pt_BR', (array) $saved['translation-languages'], true ) ) {
		fwrite( STDERR, "TranslatePress did not keep Portuguese (Brazil).\n" );
		return;
	}
	echo 'Languages: ' . implode( ', ', $saved['translation-languages'] ) . '; /pt/ slug: ' . $saved['url-slugs']['pt_BR'] . '; switcher: ' . $saved['trp-ls-floater'] . "\n";
}

$switcher = $trp->get_component( 'language_switcher_tab' )->get_initial_config();
$floating = '1' === getenv( 'BE_TP_SWITCHER' );
if ( ( $switcher['floater']['enabled'] ?? null ) !== $floating ) {
	$switcher['floater']['enabled'] = $floating;
	update_option( 'trp_language_switcher_settings', $switcher );
	echo 'Floating language switcher: ' . ( $floating ? 'shown' : 'hidden' ) . "\n";
}

$add_ons = (array) get_option( 'trp_add_ons_settings', [] );
if ( empty( $add_ons['tp-add-on-seo-pack/tp-seo-pack.php'] ) ) {
	do_action( 'trp_add_ons_activate', 'tp-add-on-seo-pack/tp-seo-pack.php' );
	echo "SEO Pack switched on.\n";
} else {
	echo "SEO Pack already on.\n";
}

$license_file = getenv( 'BE_TP_LICENSE_FILE' );
if ( $license_file && is_readable( $license_file ) ) {
	$key = trim( (string) file_get_contents( $license_file ) );
	update_option( 'trp_license_key', sanitize_text_field( $key ) );
	$trp->get_component( 'plugin_updater' )->force_check_tp_api_key( 'true' );
	wp_delete_file( $license_file );
	echo 'Licence status: ' . ( (string) get_option( 'trp_license_status', '' ) ?: 'none' ) . "\n";
}
