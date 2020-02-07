<?php

// TODO: Fix textdomain for strings?

function page_optimize_settings_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( __( 'Performance Settings' ) ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'performance' );
			do_settings_sections( 'page-optimize' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

function page_optimize_settings_section() {
	_e( 'Concat JavaScript and CSS for lower number of requests, and faster site loading.' );
}

function page_optimize_settings_field_js( $args ) {
	?>
	<div>
		<label>
			<input type="checkbox" id="page_optimize-js" name="page_optimize-js" value="1" <?php checked( get_option( 'page_optimize-js' ) ); ?>>
			<?php echo esc_html( __( 'Concatenate scripts' ) ); ?>
		</label>
		<br>
		<label>
			<input type="input" id="page_optimize-js-exclude" name="page_optimize-js-exclude" value="<?php echo sanitize_text_field( get_option( 'page_optimize-js-exclude' ) ); ?>">
			<?php echo esc_html( __( 'Comma separated list of strings to exclude from concating.' ) ); ?>
		</label>

		<p><?php _e( 'JavaScript is grouped by the original script placement.' ); ?></p>
	</div>
	<?php
}

function page_optimize_settings_field_js_load_mode( $args ) {
	?>
	<div>
		<label>
			<input type="radio" name="page_optimize-load-mode" value="" <?php checked( '', get_option( 'page_optimize-load-mode' ), true ); ?>>
			<?php echo esc_html( __( 'None' ) ); ?>
		</label>
		<label>
			<input type="radio" name="page_optimize-load-mode" value="async" <?php checked( 'async', get_option( 'page_optimize-load-mode' ), true ); ?>>
			<?php echo esc_html( __( 'Async' ) ); ?>
		</label>
		<label>
			<input type="radio" name="page_optimize-load-mode" value="defer" <?php checked( 'defer', get_option( 'page_optimize-load-mode' ), true ); ?>>
			<?php echo esc_html( __( 'Defer' ) ); ?>
		</label>

		<p><?php _e( 'You can choose the execution mode of the concat JavaScript. This option might break your site, so use carefully.' ); ?></p>
	</div>
	<?php
}

function page_optimize_settings_field_css( $args ) {
	?>
	<div>
		<label>
			<input type="checkbox" id="page_optimize-css" name="page_optimize-css" value="1" <?php checked( get_option( 'page_optimize-css' ) ); ?>>
			<?php echo esc_html( __( 'Concatenate styles' ) ); ?>
		</label>

		<p><?php _e( 'CSS is grouped by the original placement CSS and minified.' ); ?></p>
	</div>
	<?php
}

function page_optimize_settings_add_menu() {
	add_options_page( __( 'Performance Settings' ), __( 'Performance' ), 'manage_options', 'page-optimize', 'page_optimize_settings_page' );
}

add_action( 'admin_menu', 'page_optimize_settings_add_menu' );

function page_optimize_settings_init() {
	register_setting( 'performance', 'page_optimize-js', array(
		'description' => __( 'JavaScript concatenation' ),
		'type' => 'boolean',
		'default' => false,
	) );
	register_setting( 'performance', 'page_optimize-load-mode', array(
		'description' => __( 'Non-critical script execution mode' ),
		'type' => 'string',
		'default' => 'none',
		'sanitize_callback' => 'page_optimize_sanitize_js_load_mode',
	) );
	register_setting( 'performance', 'page_optimize-js-exclude', array(
		'description' => __( 'Comma separated list of strings to exclude from concating' ),
		'type' => 'string',
		'default' => '',
		'sanitize_callback' => function ( $value ) {
			if ( empty( $value ) ) {
				return '';
			}

			$excluded_strings = explode( ',', $value );
			$sanitized_values = [];
			foreach ( $excluded_strings as $excluded_string ) {
				if ( ! empty( $excluded_string ) ) {
					$sanitized_values[] = trim( $excluded_string );
				}
			}

			return implode( ',', $sanitized_values );
		}
	) );
	register_setting( 'performance', 'page_optimize-css', array(
		'description' => __( 'CSS concatenation' ),
		'type' => 'boolean',
		'default' => false,
	) );

	add_settings_section(
		'page_optimize_settings_section',
		__( 'Page Optimization' ),
		'page_optimize_settings_section',
		'page-optimize'
	);
	add_settings_field(
		'page_optimize_js',
		__( 'JavaScript' ),
		'page_optimize_settings_field_js',
		'page-optimize',
		'page_optimize_settings_section'
	);
	add_settings_field(
		'page_optimize_js_load_mode',
		__( 'Non-critical script execution mode' ),
		'page_optimize_settings_field_js_load_mode',
		'page-optimize',
		'page_optimize_settings_section'
	);
	add_settings_field(
		'page_optimize_css',
		__( 'CSS' ),
		'page_optimize_settings_field_css',
		'page-optimize',
		'page_optimize_settings_section'
	);
}

add_action( 'admin_init', 'page_optimize_settings_init' );

function page_optimize_add_plugin_settings_link( $plugin_action_links, $plugin_file = null ) {
	$is_this_plugin = dirname( $plugin_file ) === basename( __DIR__ );
	if ( ! $is_this_plugin ) {
		return $plugin_action_links;
	}

	$settings_link = sprintf(
		'<a href="options-general.php?page=page-optimize">%s</a>',
		esc_html( __( 'Settings' ) )
	);
	array_unshift( $plugin_action_links, $settings_link );

	return $plugin_action_links;
}

add_filter( 'plugin_action_links', 'page_optimize_add_plugin_settings_link', 10, 2 );
