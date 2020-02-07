<?php
/*
Plugin Name: Page Optimize
Plugin URI: http://wp-plugins.org/#
Description: Optimizes JS and CSS for faster page load and render in the browser.
Author: Automattic
Version: 0.02
Author URI: http://automattic.com/
*/

// TODO: How to disable Jetpack static file optimizations for JS and CSS? Will probably need to handle outside of this plugin
// TODO: Confirm JS type should still be application/x-javascript. I believe we may have changed it recently.
// TODO: Copy tests from nginx-http-concat and/or write them

// TODO: Make concat URL dir configurable
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

// TODO: Support JS load mode regardless of whether concat is enabled
function page_optimize_load_mode_js() {
	// Support query param for easy testing
	if ( ! empty( $_GET['load-mode-js'] ) ) {
		$load_mode = page_optimize_sanitize_js_load_mode( $_GET['load-mode-js'] );
	} else {
		$load_mode = page_optimize_sanitize_js_load_mode( get_option( 'page_optimize-load-mode' ) );
	}

	return $load_mode;
}

function page_optimize_should_concat_css() {
	// Support query param for easy testing
	if ( isset( $_GET['concat-css'] ) ) {
		return $_GET['concat-css'] !== '0';
	}

	return !! get_option( 'page_optimize-css' );
}

function page_optimize_js_exclude_list() {
	$exclude_string = get_option( 'page_optimize-js-exclude' );
	if ( empty( $exclude_string ) ) {
		return [];
	}

	return explode( ',', $exclude_string );
}

function page_optimize_sanitize_js_load_mode( $value ) {
	switch ( $value ) {
		case 'async':
		case 'defer':
			break;
		default:
			$value = '';
			break;
	}

	return $value;
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/concat-css.php';
require_once __DIR__ . '/concat-js.php';
