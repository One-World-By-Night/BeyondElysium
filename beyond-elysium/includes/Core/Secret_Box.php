<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts short secrets for storage, keyed from the site's own auth salt.
 */
class Secret_Box {

	/**
	 * Encrypts a plaintext value for storage.
	 *
	 * @param string $plaintext
	 * @return string
	 */
	public static function encrypt( string $plaintext ): string {
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( ! $iv_length ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( $iv_length );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( $ciphertext === false ) {
			return '';
		}
		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypts a value produced by encrypt().
	 *
	 * @param string $encoded
	 * @return string|null
	 */
	public static function decrypt( string $encoded ): ?string {
		if ( $encoded === '' ) {
			return null;
		}
		$raw       = base64_decode( $encoded, true );
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( $raw === false || ! $iv_length || strlen( $raw ) <= $iv_length ) {
			return null;
		}
		$iv         = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $plaintext === false ? null : $plaintext;
	}
}
