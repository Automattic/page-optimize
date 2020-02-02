<?php
/*
Plugin Name: HTTP Concat
Plugin URI: http://wp-plugins.org/#
Description: Concatenates JS and CSS
Author: Automattic
Version: 0.01
Author URI: http://automattic.com/
 */

// TODO: Add plugin settings page
// TODO: Concat cache cleanup
// TODO: Confirm JS type should still be application/x-javascript. I believe we may have changed it recently.
// TODO: Copy tests from nginx-http-concat and/or write them
// TODO: hoverintent.min.js isn't processed properly

// TODO: Make concat URL dir configurable
// phpcs:disable WordPress.VIP.SuperGlobalInputUsage.AccessDetected, WordPress.Security.ValidatedSanitizedInput, WordPress.VIP.FileSystemWritesDisallow, WordPress.VIP.RestrictedFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode
if ( isset( $_SERVER['REQUEST_URI'] ) && '/_static/' === substr( $_SERVER['REQUEST_URI'], 0, 9 ) ) {
	require_once __DIR__ . '/service.php';
	http_concat_service_request();
} else {
	require_once __DIR__ . '/concat-css.php';
	require_once __DIR__ . '/concat-js.php';
}
