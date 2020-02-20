<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die();
}

// JS
delete_option( 'page_optimize-js' );
delete_option( 'page_optimize-load-mode' );
delete_option( 'page_optimize-js-exclude' );
// CSS
delete_option( 'page_optimize-css' );
delete_option( 'page_optimize-css-exclude' );
