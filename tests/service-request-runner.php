<?php
/**
 * Runs one concat service request in a fresh process.
 *
 * page_optimize_status_exit() calls exit, so rejected requests can't be tested in-process.
 * Prints JSON: {"status":200,"body":"<base64>"} on success, or {"status":<code>} on exit.
 *
 * Usage: php service-request-runner.php '<json config>'
 *   root          Fixture site root (ABSPATH). Must contain wp-content/.
 *   request_uri   The /_static/ request URI.
 *   max_bytes     Optional PAGE_OPTIMIZE_CONCAT_MAX_BYTES value.
 *   use_base_dir  Optional. Resolve paths with PAGE_OPTIMIZE_CONCAT_BASE_DIR (realpath)
 *                 instead of the production Page_Optimize_Dependency_Path_Mapping.
 *   nonce_key     Optional NONCE_KEY value, the secret for URL signatures.
 *   cache_dir     Optional. Run the full page_optimize_service_request() with this
 *                 PAGE_OPTIMIZE_CACHE_DIR instead of only page_optimize_build_output().
 *   sign          Optional. Print {"signed":"..."} for this path list and exit.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$config = json_decode( $argv[1], true );

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $config['request_uri'];

define( 'PAGE_OPTIMIZE_SKIP_SERVICE_REQUEST', true );
define( 'PAGE_OPTIMIZE_ABSPATH', $config['root'] );

if ( isset( $config['max_bytes'] ) ) {
	define( 'PAGE_OPTIMIZE_CONCAT_MAX_BYTES', $config['max_bytes'] );
}

if ( isset( $config['nonce_key'] ) ) {
	define( 'NONCE_KEY', $config['nonce_key'] );
}

if ( isset( $config['cache_dir'] ) ) {
	define( 'PAGE_OPTIMIZE_CACHE_DIR', $config['cache_dir'] );
}

if ( ! empty( $config['use_base_dir'] ) ) {
	define( 'PAGE_OPTIMIZE_CONCAT_BASE_DIR', $config['root'] );
} else {
	// Minimal WordPress stand-ins for Page_Optimize_Dependency_Path_Mapping.
	define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );
	define( 'WP_CONTENT_DIR', $config['root'] . '/wp-content' );
	define( 'WP_PLUGIN_URL', 'http://example.org/wp-content/plugins' );
	define( 'WP_PLUGIN_DIR', $config['root'] . '/wp-content/plugins' );

	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}

	function is_multisite() {
		return false;
	}

	function get_site_url() {
		return 'http://example.org';
	}

	function page_optimize_starts_with( $prefix, $str ) {
		return 0 === strncmp( $str, $prefix, strlen( $prefix ) );
	}
}

if ( isset( $config['sign'] ) ) {
	require dirname( __DIR__ ) . '/cache-signature.php';
	echo json_encode( array( 'signed' => page_optimize_sign_path_str( $config['sign'] ) ) );
	exit;
}

$finished = false;
register_shutdown_function( function () use ( &$finished, $config ) {
	if ( $finished ) {
		return;
	}

	$status = http_response_code();
	if ( isset( $config['cache_dir'] ) && ob_get_level() > 0 ) {
		// page_optimize_service_request() ends with die(), so a response is collected here.
		$body = ob_get_clean();
		if ( false === $status ) {
			echo json_encode( array( 'status' => 200, 'body' => base64_encode( $body ) ) );
			return;
		}
	}

	echo json_encode( array( 'status' => false === $status ? null : $status ) );
} );

require dirname( __DIR__ ) . '/service.php';

if ( isset( $config['cache_dir'] ) ) {
	ob_start();
	page_optimize_service_request();
}

$output = page_optimize_build_output();
$finished = true;
echo json_encode( array(
	'status' => 200,
	'body' => base64_encode( $output['content'] ),
) );
