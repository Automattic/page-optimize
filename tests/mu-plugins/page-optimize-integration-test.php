<?php

// Make sure script and style concat emits debug comments and element attributes
add_filter( 'page_optimize_script_debug', '__return_true' );
add_filter( 'page_optimize_style_debug', '__return_true' );

$page_optimize_done_script_items = array(
	'head' => array(),
	'footer' => array(),
);

$page_optimize_done_style_items = array(
	'head' => array(),
	'footer' => array(),
);

// Collect processed scripts so we can later expose them for integration tests
add_action(
	'page_optimize_doing_script_items', function ( $handles, $group ) {
		global $wp_scripts;
		global $page_optimize_done_script_items;
		$group_key = 1 === $group ? 'footer' : 'head';

		$handle_data = array();
		foreach ( $handles as $handle ) {
			$handle_data[] = array(
				'handle' => $handle,
				'source' => $wp_scripts->registered[ $handle ]->src,
				'has_inline_script_before' => ! empty ( $wp_scripts->get_data( $handle, 'before' ) ),
				'has_inline_script_after' => ! empty( $wp_scripts->get_data( $handle, 'after' ) ),
			);
		}

		$page_optimize_done_script_items[ $group_key ][] = $handle_data;
	},
	10,
	2
);

// Collect processed styles so we can later expose them for integration tests
add_action(
	'page_optimize_doing_style_items', function ( $handles, $group ) {
		global $page_optimize_done_style_items;
		$group_key = 1 === $group ? 'footer' : 'head';
		$page_optimize_done_style_items[ $group_key ][] = array_values( $handles );
	},
	10,
	2
);

// Expose processed scripts and styles in page content
// so integration tests can assert based on them
add_action(
	'wp_footer',
	function () {
		global $page_optimize_done_script_items;
		global $page_optimize_done_style_items;

		$json_script_items = wp_json_encode(
			$page_optimize_done_script_items,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<script id="page-optimize-script-items" type="application/json">
			<?php echo esc_html( $json_script_items ); ?>
		</script>
		<?php

		$json_style_items = wp_json_encode(
			$page_optimize_done_style_items,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<script id="page-optimize-style-items" type="application/json">
			<?php echo esc_html( $json_style_items ); ?>
		</script>
		<?php
	},
	999
);

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_script( 'test-1', plugins_url( 'resources/c0.css', __FILE__ ) );
	wp_enqueue_script( 'test-2', plugins_url( 'resources/c1.css', __FILE__ ) );
	wp_enqueue_script( 'test-3-with-inline', plugins_url( 'resources/c2.css', __FILE__ ) );
	wp_add_inline_script( 'test-3-with-inline', '/* inline style */' );
	wp_enqueue_script( 'test-4', plugins_url( 'resources/c3.css', __FILE__ ) );
	wp_enqueue_script( 'test-5-with-inline', plugins_url( 'resources/c4.css', __FILE__ ) );
	wp_add_inline_script( 'test-5-with-inline', '/* inline style */' );
	wp_enqueue_script( 'test-6', plugins_url( 'resources/c5.css', __FILE__ ) );

	wp_enqueue_style( 'test-1', plugins_url( 'resources/c0.css', __FILE__ ) );
	wp_enqueue_style( 'test-2', plugins_url( 'resources/c1.css', __FILE__ ) );
	wp_enqueue_style( 'test-3-with-inline', plugins_url( 'resources/c2.css', __FILE__ ) );
	wp_add_inline_style( 'test-3-with-inline', '/* inline style */' );
	wp_enqueue_style( 'test-4', plugins_url( 'resources/c3.css', __FILE__ ) );
	wp_enqueue_style( 'test-5-with-inline', plugins_url( 'resources/c4.css', __FILE__ ) );
	wp_add_inline_style( 'test-5-with-inline', '/* inline style */' );
	wp_enqueue_style( 'test-6', plugins_url( 'resources/c5.css', __FILE__ ) );
} );

if ( ! empty( $_GET['exclude_from_js_concat'] ) ) {
	add_filter( 'pre_option_page_optimize-js-exclude', function () {
		return $_GET['exclude_from_js_concat'];
	} );
}

if ( ! empty( $_GET['exclude_from_css_concat'] ) ) {
	add_filter( 'pre_option_page_optimize-css-exclude', function () {
		return $_GET['exclude_from_css_concat'];
	} );
}
