<?php
/**
 * Signatures for concat URLs.
 *
 * The link builders sign the path list they emit. The concat service only writes
 * a cache entry for a signed path list, so the cache holds only URLs this site generated.
 * Unsigned URLs (for example, ones stored in a page cache before signing existed) are
 * still served, just not cached.
 */

function page_optimize_cache_signing_key() {
	$secret = '';
	foreach ( array( 'NONCE_KEY', 'NONCE_SALT' ) as $name ) {
		if ( defined( $name ) && 'put your unique phrase here' !== constant( $name ) ) {
			$secret .= constant( $name );
		}
	}

	// WordPress stores generated salts as site options when wp-config.php doesn't define them.
	if ( '' === $secret && function_exists( 'get_site_option' ) ) {
		$secret = (string) get_site_option( 'nonce_key' ) . (string) get_site_option( 'nonce_salt' );
	}

	return '' === $secret ? false : hash_hmac( 'sha256', 'page-optimize-cache', $secret );
}

function page_optimize_cache_signature( $path_str ) {
	$key = page_optimize_cache_signing_key();
	if ( false === $key ) {
		return false;
	}

	return substr( hash_hmac( 'sha256', $path_str, $key ), 0, 20 );
}

/**
 * Append a signature to a path list such as "/a.css,/b.css?m=123", giving "/a.css,/b.css?m=123-<signature>".
 *
 * The signature goes inside the m= value rather than in a separate query argument,
 * so query string normalization (for example, sorting arguments at a CDN) can't split it off.
 */
function page_optimize_sign_path_str( $path_str ) {
	$signature = page_optimize_cache_signature( $path_str );
	if ( false === $signature ) {
		return $path_str;
	}

	return "$path_str-$signature";
}

/**
 * Whether a decoded path list such as "/a.css,/b.css?m=123-<signature>" carries a valid signature.
 */
function page_optimize_verify_path_str( $path_str ) {
	if ( strlen( $path_str ) <= 21 || '-' !== $path_str[ strlen( $path_str ) - 21 ] ) {
		return false;
	}

	$expected = page_optimize_cache_signature( substr( $path_str, 0, -21 ) );
	if ( false === $expected ) {
		return false;
	}

	return hash_equals( $expected, substr( $path_str, -20 ) );
}
