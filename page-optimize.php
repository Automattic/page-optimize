<?php
/*
Plugin Name: Page Optimize
Plugin URI: https://wordpress.org/plugins/page-optimize/
Description: Optimizes JS and CSS for faster page load and render in the browser.
Author: Automattic
Version: 0.0.5
Author URI: http://automattic.com/
*/

// TODO: Copy tests from nginx-http-concat and/or write them

// TODO: Make concat URL dir configurable
if ( isset( $_SERVER['REQUEST_URI'] ) && '/_static/' === substr( $_SERVER['REQUEST_URI'], 0, 9 ) ) {
	require_once __DIR__ . '/service.php';
	page_optimize_service_request();
	exit;
}

function page_optimize_get_text_domain() {
	return 'page-optimize';
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
		return page_optimize_js_exclude_list_default();
	}

	return explode( ',', $exclude_string );
}

function page_optimize_js_exclude_list_default() {
	// WordPress core stuff, a lot of other plugins depend on it.
	return [ 'jquery', 'underscore', 'backbone' ];
}

function page_optimize_css_exclude_list() {
	$exclude_string = get_option( 'page_optimize-css-exclude' );
	if ( empty( $exclude_string ) ) {
		return page_optimize_css_exclude_list_default();
	}

	return explode( ',', $exclude_string );
}

function page_optimize_css_exclude_list_default() {
	// WordPress core stuff, a lot of other plugins depend on it.
	return [ 'admin-bar', 'dashicons' ];
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

function page_optimize_sanitize_exclude_field( $value ) {
	if ( empty( $value ) ) {
		return '';
	}

	$excluded_strings = explode( ',', sanitize_text_field( $value ) );
	$sanitized_values = [];
	foreach ( $excluded_strings as $excluded_string ) {
		if ( ! empty( $excluded_string ) ) {
			$sanitized_values[] = trim( $excluded_string );
		}
	}

	return implode( ',', $sanitized_values );
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/concat-css.php';
require_once __DIR__ . '/concat-js.php';

// Disable Jetpack photon-cdn for static JS/CSS
add_filter( 'jetpack_force_disable_site_accelerator', '__return_true' );
