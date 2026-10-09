<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Checks on another chronicle's site address before this site fetches it.
 */
class Remote_Site {

	/**
	 * Whether a URL's host is, or resolves to, a link-local, unspecified or carrier-grade shared address, IPv4 or
	 * IPv6, or the URL has no host at all.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function is_link_local( string $url ): bool {
		$host = parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || $host === '' ) {
			return true;
		}
		$host = trim( $host, '[]' );

		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false ) {
			return stripos( $host, 'fe8' ) === 0 || stripos( $host, 'fe9' ) === 0
				|| stripos( $host, 'fea' ) === 0 || stripos( $host, 'feb' ) === 0 || $host === '::';
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false ? $host : gethostbyname( $host );
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) === false ) {
			return false;
		}
		$parts = array_map( 'intval', explode( '.', $ip ) );
		return $parts[0] === 0
			|| ( $parts[0] === 169 && $parts[1] === 254 )
			|| ( $parts[0] === 100 && $parts[1] >= 64 && $parts[1] <= 127 );
	}
}
