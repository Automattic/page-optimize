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
	// TODO: Add description
}

function page_optimize_settings_field_js( $args ) {
	// TODO: Add additional explanations
	?>
	<div>
		<label>
			<input type="checkbox" id="page_optimize-js" name="page_optimize-js" value="1" <?php checked( get_option( 'page_optimize-js' ) ); ?>>
			<?php echo esc_html( __( 'Concatenate scripts' ) ); ?>
		</label>
	</div>
	<div>
		<label>
			<input type="checkbox" id="page_optimize-js-defer" name="page_optimize-js-defer" value="1" <?php checked( get_option( 'page_optimize-js-defer' ) ); ?>>
			<?php echo esc_html( __( 'Defer execution of non-critical scripts' ) ); ?>
		</label>
	</div>
	<?php
}

function page_optimize_settings_field_css( $args ) {
	?>
	<label>
		<input type="checkbox" id="page_optimize-css" name="page_optimize-css" value="1" <?php checked( get_option( 'page_optimize-css' ) ); ?>>
		<?php echo esc_html( __( 'Concatenate styles' ) ); ?>
	</label>
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
	register_setting( 'performance', 'page_optimize-js-defer', array(
		'description' => __( 'Async/Defer non-critical scripts' ),
		'type' => 'boolean',
		'default' => false,
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
