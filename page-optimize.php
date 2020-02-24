<?php
/*
Plugin Name: Page Optimize
Plugin URI: https://wordpress.org/plugins/page-optimize/
Description: Optimizes JS and CSS for faster page load and render in the browser.
Author: Automattic
Version: 0.3.2
Author URI: http://automattic.com/
*/

// Default cache directory
if ( ! defined( 'PAGE_OPTIMIZE_CACHE_DIR' ) ) {
	define( 'PAGE_OPTIMIZE_CACHE_DIR', WP_CONTENT_DIR . '/cache/page_optimize' );
}

if ( ! defined( 'PAGE_OPTIMIZE_ABSPATH' ) ) {
	define( 'PAGE_OPTIMIZE_ABSPATH', ABSPATH );
}

define( 'PAGE_OPTIMIZE_CRON_CACHE_CLEANUP_JOB', 'page_optimize_cron_cache_cleanup' );

// TODO: Copy tests from nginx-http-concat and/or write them

// TODO: Make concat URL dir configurable
if ( isset( $_SERVER['REQUEST_URI'] ) && '/_static/' === substr( $_SERVER['REQUEST_URI'], 0, 9 ) ) {
	require_once __DIR__ . '/service.php';
	page_optimize_service_request();
	exit;
}

function page_optimize_cache_cleanup( $file_age = DAY_IN_SECONDS ) {
	if ( ! is_dir( PAGE_OPTIMIZE_CACHE_DIR ) ) {
		return;
	}

	// Grab all files in the cache directory
	$cache_files = glob( PAGE_OPTIMIZE_CACHE_DIR . '/page-optimize-cache-*' );

	// Cleanup all files older than 24 hours
	foreach ( $cache_files as $cache_file ) {
		if ( ! is_file( $cache_file ) ) {
			continue;
		}

		if ( ( time() - $file_age ) > filemtime( $cache_file ) ) {
			unlink( $cache_file );
		}
	}
}
add_action( PAGE_OPTIMIZE_CRON_CACHE_CLEANUP_JOB, 'page_optimize_cache_cleanup' );

// Unschedule cache cleanup, and purge cache directory
function page_optimize_deactivate() {
	page_optimize_cache_cleanup( 0 /* max file age */ );

	wp_clear_scheduled_hook( PAGE_OPTIMIZE_CRON_CACHE_CLEANUP_JOB );
}
register_deactivation_hook( __FILE__, 'page_optimize_deactivate' );

function page_optimize_uninstall() {
	// Run cleanup on uninstall. You can uninstall an active plugin w/o deactivation.
	page_optimize_deactivate();

	// JS
	delete_option( 'page_optimize-js' );
	delete_option( 'page_optimize-load-mode' );
	delete_option( 'page_optimize-js-exclude' );
	// CSS
	delete_option( 'page_optimize-css' );
	delete_option( 'page_optimize-css-exclude' );

}
register_uninstall_hook( __FILE__, 'page_optimize_uninstall' );

function page_optimize_get_text_domain() {
	return 'page-optimize';
}

function page_optimize_should_concat_js() {
	// Support query param for easy testing
	if ( isset( $_GET['concat-js'] ) ) {
		return $_GET['concat-js'] !== '0';
	}

	return !! get_option( 'page_optimize-js', page_optimize_js_default() );
}

// TODO: Support JS load mode regardless of whether concat is enabled
function page_optimize_load_mode_js() {
	// Support query param for easy testing
	if ( ! empty( $_GET['load-mode-js'] ) ) {
		$load_mode = page_optimize_sanitize_js_load_mode( $_GET['load-mode-js'] );
	} else {
		$load_mode = page_optimize_sanitize_js_load_mode( get_option( 'page_optimize-load-mode', page_optimize_js_load_mode_default() ) );
	}

	return $load_mode;
}

function page_optimize_should_concat_css() {
	// Support query param for easy testing
	if ( isset( $_GET['concat-css'] ) ) {
		return $_GET['concat-css'] !== '0';
	}

	return !! get_option( 'page_optimize-css', page_optimize_css_default() );
}

function page_optimize_js_default() {
	return true;
}

function page_optimize_css_default() {
	return true;
}

function page_optimize_js_load_mode_default() {
	return '';
}

function page_optimize_js_exclude_list() {
	$exclude_list = get_option( 'page_optimize-js-exclude' );
	if ( false === $exclude_list ) {
		// Use the default since the option is not set
		return page_optimize_js_exclude_list_default();
	}
	if ( '' === $exclude_list ) {
		return [];
	}

	return explode( ',', $exclude_list );
}

function page_optimize_js_exclude_list_default() {
	// WordPress core stuff, a lot of other plugins depend on it.
	return [ 'jquery', 'jquery-core', 'underscore', 'backbone' ];
}

function page_optimize_css_exclude_list() {
	$exclude_list = get_option( 'page_optimize-css-exclude' );
	if ( false === $exclude_list ) {
		// Use the default since the option is not set
		return page_optimize_css_exclude_list_default();
	}
	if ( '' === $exclude_list ) {
		return [];
	}

	return explode( ',', $exclude_list );
}

function page_optimize_css_exclude_list_default() {
	// WordPress core stuff
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

/**
 * Determines whether a string starts with another string.
 */
function page_optimize_starts_with( $prefix, $str ) {
	$prefix_length = strlen( $prefix );
	if ( strlen( $str ) < $prefix_length ) {
		return false;
	}

	return substr( $str, 0, $prefix_length ) === $prefix;
}

/**
 * Answers whether the plugin should provide concat resource URIs
 * that are relative to a common ancestor directory. Assuming a common ancestor
 * allows us to skip resolving resource URIs to filesystem paths later on.
 */
function page_optimize_has_concat_base_dir() {
	return defined( 'PAGE_OPTIMIZE_CONCAT_BASE_DIR' ) && file_exists( PAGE_OPTIMIZE_CONCAT_BASE_DIR );
}

/**
 * Get a filesystem path relative to a configured base path for resources
 * that will be concatenated. Assuming a common ancestor allows us to skip
 * resolving resource URIs to filesystem paths later on.
 */
function page_optimize_remove_concat_base_prefix( $original_fs_path ) {
	if ( page_optimize_has_concat_base_dir() ) {
		$prefix = trailingslashit( PAGE_OPTIMIZE_CONCAT_BASE_DIR );
		if ( page_optimize_starts_with( $prefix, $original_fs_path ) ) {
			$new_path = substr( $original_fs_path, strlen( $prefix ) );
		}
	}

	if ( empty( $new_path ) ) {
		$new_path = '/page-optimize-resource-outside-base-path/' . basename( $original_fs_path );
	}
	return $new_path;
}

function page_optimize_init() {
	// Bail if we're in customizer
	global $wp_customize;
	if ( isset( $wp_customize ) ) {
		return;
	}

	// Schedule cache cleanup on init
	if( ! wp_next_scheduled( PAGE_OPTIMIZE_CRON_CACHE_CLEANUP_JOB ) ) {
		wp_schedule_event( time(), 'daily', PAGE_OPTIMIZE_CRON_CACHE_CLEANUP_JOB );
	}

	require_once __DIR__ . '/settings.php';
	require_once __DIR__ . '/concat-css.php';
	require_once __DIR__ . '/concat-js.php';

	// Disable Jetpack photon-cdn for static JS/CSS
	add_filter( 'jetpack_force_disable_site_accelerator', '__return_true' );
}
add_action( 'plugins_loaded', 'page_optimize_init' );
