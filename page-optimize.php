<?php
/*
Plugin Name: Page Optimize
Plugin URI: http://wp-plugins.org/#
Description: Optimizes JS and CSS for faster page load and render in the browser
Author: Automattic
Version: 0.01
Author URI: http://automattic.com/
 */

// TODO: How to disable Jetpack static file optimizations for JS and CSS? Will probably need to handle outside of this plugin
// TODO: Confirm JS type should still be application/x-javascript. I believe we may have changed it recently.
// TODO: Copy tests from nginx-http-concat and/or write them

// TODO: Make concat URL dir configurable
// phpcs:disable WordPress.VIP.SuperGlobalInputUsage.AccessDetected, WordPress.Security.ValidatedSanitizedInput, WordPress.VIP.FileSystemWritesDisallow, WordPress.VIP.RestrictedFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode
if ( isset( $_SERVER['REQUEST_URI'] ) && '/_static/' === substr( $_SERVER['REQUEST_URI'], 0, 9 ) ) {
	require_once __DIR__ . '/service.php';
	page_optimize_service_request();
	exit;
}

function page_optimize_should_concat_js() {
	// Support query param for easy testing
	if ( isset( $_GET['concat-js'] ) ) {
		return $_GET['concat-js'] !== '0';
	}
	return !! get_option( 'page_optimize-js' );
}

// TODO: Support deferred scripts regardless of whether concat is enabled
function page_optimize_should_defer_noncritcal_js() {
	// Support query param for easy testing
	if ( isset( $_GET['defer-js'] ) ) {
		return $_GET['defer-js'] !== '0';
	}
	return !! get_option( 'page_optimize-js-defer' );
}

function page_optimize_should_concat_css() {
	// Support query param for easy testing
	if ( isset( $_GET['concat-css'] ) ) {
		return $_GET['concat-css'] !== '0';
	}
	return !! get_option( 'page_optimize-css' );
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/concat-css.php';
require_once __DIR__ . '/concat-js.php';
