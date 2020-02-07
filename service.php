<?php

$http_concat_types = array(
	'css' => 'text/css',
	'js' => 'application/x-javascript'
);

function http_concat_service_request() {
	$output = http_concat_build_output();
	$etag  = '"' . md5( $output ) . '"';

	// TODO: Do we still need this x-http-concat header?
	header( 'x-http-concat: uncached' );
	header( 'Cache-Control: max-age=' . 31536000 );
	header( 'ETag: ' . $etag );

	echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- We need to trust this unfortunately.
	die();
}

function http_concat_build_output() {
	global $http_concat_types;
	ob_start( 'ob_gzhandler' );

	require_once( __DIR__ . '/cssmin/cssmin.php' );
	require_once( __DIR__ . '/utils.php' );

	/* Config */
	$concat_max_files = 150;
	$concat_unique = true;

	/* Main() */
	if ( ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ) ) )
		http_concat_status_exit( 400 );

	// /_static/??/foo/bar.css,/foo1/bar/baz.css?m=293847g
	// or
	// /_static/??-eJzTT8vP109KLNJLLi7W0QdyDEE8IK4CiVjn2hpZGluYmKcDABRMDPM=
	$args = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY );
	if ( ! $args || false === strpos( $args, '?' ) )
		http_concat_status_exit( 400 );

	$args = substr( $args, strpos( $args, '?' ) + 1 );

	// /foo/bar.css,/foo1/bar/baz.css?m=293847g
	// or
	// -eJzTT8vP109KLNJLLi7W0QdyDEE8IK4CiVjn2hpZGluYmKcDABRMDPM=
	if ( '-' == $args[0] ) {
		$args = @gzuncompress( base64_decode( substr( $args, 1 ) ) );

		// Invalid data, abort!
		if ( false === $args ) {
			http_concat_status_exit( 400 );
		}
	}

	// /foo/bar.css,/foo1/bar/baz.css?m=293847g
	$version_string_pos = strpos( $args, '?' );
	if ( false !== $version_string_pos )
		$args = substr( $args, 0, $version_string_pos );

	// /foo/bar.css,/foo1/bar/baz.css
	$args = explode( ',', $args );
	if ( ! $args )
		http_concat_status_exit( 400 );

	// array( '/foo/bar.css', '/foo1/bar/baz.css' )
	if ( 0 == count( $args ) || count( $args ) > $concat_max_files )
		http_concat_status_exit( 400 );

	// If we're in a subdirectory context, use that as the root.
	// We can't assume that the root serves the same content as the subdir.
	$subdir_path_prefix = '';
	$request_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$_static_index = strpos( $request_path, '/_static/' );
	if ( $_static_index > 0 ) {
		$subdir_path_prefix = substr( $request_path, 0, $_static_index );
	}
	unset( $request_path, $_static_index );

	$last_modified = 0;
	$pre_output = '';
	$output = '';

	$css_minify = new tubalmartin\CssMin\Minifier;

	foreach ( $args as $uri ) {
		$fullpath = http_concat_get_path( $uri );

		if ( ! file_exists( $fullpath ) )
			http_concat_status_exit( 404 );

		$mime_type = http_concat_get_mtype( $fullpath );
		if ( ! in_array( $mime_type, $http_concat_types ) )
			http_concat_status_exit( 400 );

		if ( $concat_unique ) {
			if ( ! isset( $last_mime_type ) )
				$last_mime_type = $mime_type;

			if ( $last_mime_type != $mime_type )
				http_concat_status_exit( 400 );
		}

		$stat = stat( $fullpath );
		if ( false === $stat ) {
			http_concat_status_exit( 500 );
		}

		if ( $stat['mtime'] > $last_modified )
			$last_modified = $stat['mtime'];

		$buf = file_get_contents( $fullpath );
		if ( false === $buf ) {
			http_concat_status_exit( 500 );
		}

		if ( 'text/css' == $mime_type ) {
			$dirpath = $subdir_path_prefix . dirname( $uri );

			// url(relative/path/to/file) -> url(/absolute/and/not/relative/path/to/file)
			$buf = Http_Concat_Utils::relative_path_replace( $buf, $dirpath );

			// AlphaImageLoader(...src='relative/path/to/file'...) -> AlphaImageLoader(...src='/absolute/path/to/file'...)
			$buf = preg_replace(
				'/(Microsoft.AlphaImageLoader\s*\([^\)]*src=(?:\'|")?)([^\/\'"\s\)](?:(?<!http:|https:).)*)\)/isU',
				'$1' . ( $dirpath == '/' ? '/' : $dirpath . '/' ) . '$2)',
				$buf
			);

			// The @charset rules must be on top of the output
			if ( 0 === strpos( $buf, '@charset' ) ) {
				preg_replace_callback(
					'/(?P<charset_rule>@charset\s+[\'"][^\'"]+[\'"];)/i',
					function ( $match ) {
						global $pre_output;

						if ( 0 === strpos( $pre_output, '@charset' ) )
							return '';

						$pre_output = $match[0] . "\n" . $pre_output;

						return '';
					},
					$buf
				);
			}

			// Move the @import rules on top of the concatenated output.
			// Only @charset rule are allowed before them.
			if ( false !== strpos( $buf, '@import' ) ) {
				$buf = preg_replace_callback(
					'/(?P<pre_path>@import\s+(?:url\s*\()?[\'"\s]*)(?P<path>[^\'"\s](?:https?:\/\/.+\/?)?.+?)(?P<post_path>[\'"\s\)]*;)/i',
					function ( $match ) use ( $dirpath ) {
						global $pre_output;

						if ( 0 !== strpos( $match['path'], 'http' ) && '/' != $match['path'][0] )
							$pre_output .= $match['pre_path'] . ( $dirpath == '/' ? '/' : $dirpath . '/' ) .
								$match['path'] . $match['post_path'] . "\n";
						else
							$pre_output .= $match[0] . "\n";

						return '';
					},
					$buf
				);
			}

			$buf = $css_minify->run( $buf );
		}

		if ( 'application/x-javascript' == $mime_type )
			$output .= "$buf;\n";
		else
			$output .= "$buf";
	}

	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
	header( 'Content-Length: ' . ( strlen( $pre_output ) + strlen( $output ) ) );
	header( "Content-Type: $mime_type" );

	echo $pre_output . $output;

	return ob_get_clean();
}

function http_concat_status_exit( $status ) {
	http_response_code( $status );
	exit;
}

function http_concat_get_mtype( $file ) {
	global $http_concat_types;

	$lastdot_pos = strrpos( $file, '.' );
	if ( false === $lastdot_pos )
		return false;

	$ext = substr( $file, $lastdot_pos + 1 );

	return isset( $http_concat_types[$ext] ) ? $http_concat_types[$ext] : false;
}

function http_concat_get_path( $uri ) {
	if ( ! strlen( $uri ) )
		http_concat_status_exit( 400 );

	if ( false !== strpos( $uri, '..' ) || false !== strpos( $uri, "\0" ) )
		http_concat_status_exit( 400 );

	if ( false !== strpos( $uri, '/wp-content/' ) && defined( 'WP_CONTENT_DIR' ) ) {
		$files_root = WP_CONTENT_DIR;
		$replace_count = 1;
		$uri = str_replace( '/wp-content/', '', $uri, $replace_count ); // Remove duplicate /wp-content/
	} else {
		$files_root = ABSPATH;
	}

	return $files_root . ( '/' != $uri[0] ? '/' : '' ) . $uri;
}
