<?php

// TODO: Fix textdomain for strings?

function http_concat_settings_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( __( 'Performance Settings' ) ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'performance' );
			do_settings_sections( 'http-concat-performance' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

function http_concat_settings_section() {
	// TODO: Add description
}

function http_concat_settings_field_js( $args ) {
	// TODO: Add additional explanations
?>
	<div>
		<label>
			<input type="checkbox" id="http_concat-js" name="http_concat-js" value="1" <?php checked( get_option( 'http_concat-js' ) ); ?>>
			<?php echo esc_html( __( 'Concatenate scripts' ) ); ?>
		</label>
	</div>
	<div>
		<label>
			<input type="checkbox" id="http_concat-js-defer" name="http_concat-js-defer" value="1" <?php checked( get_option( 'http_concat-js-defer' ) ); ?>>
			<?php echo esc_html( __( 'Defer execution of non-critical scripts' ) ); ?>
		</label>
	</div>
<?php
}

function http_concat_settings_field_css( $args ) {
?>
	<label>
		<input type="checkbox" id="http_concat-css" name="http_concat-css" value="1" <?php checked( get_option( 'http_concat-css' ) ); ?>>
		<?php echo esc_html( __( 'Concatenate styles' ) ); ?>
	</label>
<?php
}

function http_concat_settings_add_menu() {
	add_options_page( __( 'Performance Settings' ), __( 'Performance' ), 'manage_options', 'http-concat-performance', 'http_concat_settings_page' );
}
add_action( 'admin_menu', 'http_concat_settings_add_menu' );

function http_concat_settings_init() {
	register_setting( 'performance', 'http_concat-js', array(
		'description' => __( 'JavaScript concatenation' ),
		'type'        => 'boolean',
		'default'     => false,
	) );
	register_setting( 'performance', 'http_concat-js-defer', array(
		'description' => __( 'Defer non-critical scripts' ),
		'type'        => 'boolean',
		'default'     => false,
	) );
	register_setting( 'performance', 'http_concat-css', array(
		'description' => __( 'CSS concatenation' ),
		'type'        => 'boolean',
		'default'     => false,
	) );

	add_settings_section(
		'http_concat_settings_section',
		'http-concat',
		'http_concat_settings_section',
		'http-concat-performance',
	);
	add_settings_field(
		'http_concat_js',
		__( 'JavaScript' ),
		'http_concat_settings_field_js',
		'http-concat-performance',
		'http_concat_settings_section',
	);
	add_settings_field(
		'http_concat_css',
		__( 'CSS' ),
		'http_concat_settings_field_css',
		'http-concat-performance',
		'http_concat_settings_section',
	);
}
add_action( 'admin_init', 'http_concat_settings_init' );

function http_concat_add_plugin_settings_link( $plugin_action_links, $plugin_file = null ) {
	$is_this_plugin = dirname( $plugin_file ) === basename( __DIR__ );
	if ( ! $is_this_plugin ){
		return $plugin_action_links;
	}

	$settings_link = sprintf(
		'<a href="options-general.php?page=http-concat-performance">%s</a>',
		esc_html( __( 'Settings' ) )
	);
	array_unshift( $plugin_action_links, $settings_link );

	return $plugin_action_links;
}
add_filter( 'plugin_action_links', 'http_concat_add_plugin_settings_link', 10, 2 );
