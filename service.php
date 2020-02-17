<?php

require_once __DIR__ . '/dependency-path-mapping.php';

$page_optimize_types = array(
	'css' => 'text/css',
	'js' => 'application/javascript'
);

// TODO: Provide a settings button to clear the cache
// TODO: Should we provide a default at all? Is this a reasonable default?

function page_optimize_service_request() {
	$use_cache = defined( 'PAGE_OPTIMIZE_CACHE_DIR' ) && ! empty( PAGE_OPTIMIZE_CACHE_DIR );
	if ( $use_cache && ! is_dir( PAGE_OPTIMIZE_CACHE_DIR ) && ! mkdir( PAGE_OPTIMIZE_CACHE_DIR, 0775, true ) ) {
		$use_cache = false;
		error_log( sprintf(
			/* translators: a filesystem path to a directory */
			__( "Disabling page-optimize cache. Unable to create cache directory '%s'.", page_optimize_get_text_domain() ),
			PAGE_OPTIMIZE_CACHE_DIR
		) );
	}
	if ( $use_cache && ( ! is_dir( PAGE_OPTIMIZE_CACHE_DIR ) || ! is_writable( PAGE_OPTIMIZE_CACHE_DIR ) || ! is_executable( PAGE_OPTIMIZE_CACHE_DIR ) ) ) {
		$use_cache = false;
		error_log( sprintf(
			/* translators: a filesystem path to a directory */
			__( "Disabling page-optimize cache. Unable to write to cache directory '%s'.", page_optimize_get_text_domain() ),
			PAGE_OPTIMIZE_CACHE_DIR
		) );
	}

	if ( $use_cache ) {
		$request_uri_hash = md5( $_SERVER['REQUEST_URI'] );
		$cache_file = PAGE_OPTIMIZE_CACHE_DIR . "/page-optimize-cache-$request_uri_hash";
		$cache_file_meta = PAGE_OPTIMIZE_CACHE_DIR . "/page-optimize-cache-meta-$request_uri_hash";

		if ( file_exists( $cache_file ) ) {
			if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
				if ( strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) < filemtime( $cache_file ) ) {
					header( 'HTTP/1.1 304 Not Modified' );
					exit;
				}
			}

			if ( file_exists( $cache_file_meta ) ) {
				$meta = json_decode( file_get_contents( $cache_file_meta ) );
				if ( null !== $meta && isset( $meta->headers ) ) {
					foreach ( $meta->headers as $header ) {
						header( $header );
					}
				}
			}

			$etag = '"' . md5( file_get_contents( $cache_file ) ) . '"';

			ob_start( 'ob_gzhandler' );
			header( 'X-Page-Optimize: cached' );
			header( 'Cache-Control: max-age=' . 31536000 );
			header( 'ETag: ' . $etag );

			echo file_get_contents( $cache_file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- We need to trust this unfortunately.
			$output = ob_get_clean();
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- We need to trust this unfortunately.
			die();
		}
	}

	$output = page_optimize_build_output();
	// TODO: It seems insecure to indiscriminately cache and send headers. Cache only specific headers.
	$meta   = array( 'headers' => headers_list() );

	if ( $use_cache ) {
		file_put_contents( $cache_file, $output );
		file_put_contents( $cache_file_meta, json_encode( $meta ) );
	}

	header( 'X-Page-Optimize: uncached' );
	header( 'Cache-Control: max-age=' . 31536000 );
	header( 'ETag: "' . md5( $output ) . '"' );

	echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- We need to trust this unfortunately.

	die();
}

function page_optimize_build_output() {
	global $page_optimize_types;
	ob_start( 'ob_gzhandler' );

	require_once( __DIR__ . '/cssmin/cssmin.php' );
	require_once( __DIR__ . '/utils.php' );

	/* Config */
	$concat_max_files = 150;
	$concat_unique = true;

	/* Main() */
	if ( ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ) ) ) {
		page_optimize_status_exit( 400 );
	}

	// /_static/??/foo/bar.css,/foo1/bar/baz.css?m=293847g
	// or
	// /_static/??-eJzTT8vP109KLNJLLi7W0QdyDEE8IK4CiVjn2hpZGluYmKcDABRMDPM=
	$args = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY );
	if ( ! $args || false === strpos( $args, '?' ) ) {
		page_optimize_status_exit( 400 );
	}

	$args = substr( $args, strpos( $args, '?' ) + 1 );

	// /foo/bar.css,/foo1/bar/baz.css?m=293847g
	// or
	// -eJzTT8vP109KLNJLLi7W0QdyDEE8IK4CiVjn2hpZGluYmKcDABRMDPM=
	if ( '-' == $args[0] ) {
		$args = @gzuncompress( base64_decode( substr( $args, 1 ) ) );

		// Invalid data, abort!
		if ( false === $args ) {
			page_optimize_status_exit( 400 );
		}
	}

	// /foo/bar.css,/foo1/bar/baz.css?m=293847g
	$version_string_pos = strpos( $args, '?' );
	if ( false !== $version_string_pos ) {
		$args = substr( $args, 0, $version_string_pos );
	}

	// /foo/bar.css,/foo1/bar/baz.css
	$args = explode( ',', $args );
	if ( ! $args ) {
		page_optimize_status_exit( 400 );
	}

	// array( '/foo/bar.css', '/foo1/bar/baz.css' )
	if ( 0 == count( $args ) || count( $args ) > $concat_max_files ) {
		page_optimize_status_exit( 400 );
	}

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
		$fullpath = page_optimize_get_path( $uri );

		if ( ! file_exists( $fullpath ) ) {
			page_optimize_status_exit( 404 );
		}

		$mime_type = page_optimize_get_mime_type( $fullpath );
		if ( ! in_array( $mime_type, $page_optimize_types ) ) {
			page_optimize_status_exit( 400 );
		}

		if ( $concat_unique ) {
			if ( ! isset( $last_mime_type ) ) {
				$last_mime_type = $mime_type;
			}

			if ( $last_mime_type != $mime_type ) {
				page_optimize_status_exit( 400 );
			}
		}

		$stat = stat( $fullpath );
		if ( false === $stat ) {
			page_optimize_status_exit( 500 );
		}

		if ( $stat['mtime'] > $last_modified ) {
			$last_modified = $stat['mtime'];
		}

		$buf = file_get_contents( $fullpath );
		if ( false === $buf ) {
			page_optimize_status_exit( 500 );
		}

		if ( 'text/css' == $mime_type ) {
			$dirpath = $subdir_path_prefix . dirname( $uri );

			// url(relative/path/to/file) -> url(/absolute/and/not/relative/path/to/file)
			$buf = Page_Optimize_Utils::relative_path_replace( $buf, $dirpath );

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

						if ( 0 === strpos( $pre_output, '@charset' ) ) {
							return '';
						}

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

						if ( 0 !== strpos( $match['path'], 'http' ) && '/' != $match['path'][0] ) {
							$pre_output .= $match['pre_path'] . ( $dirpath == '/' ? '/' : $dirpath . '/' ) .
										   $match['path'] . $match['post_path'] . "\n";
						} else {
							$pre_output .= $match[0] . "\n";
						}

						return '';
					},
					$buf
				);
			}

			$buf = $css_minify->run( $buf );
		}

		if ( $page_optimize_types['js'] === $mime_type ) {
			$output .= "$buf;\n";
		} else {
			$output .= "$buf";
		}
	}

	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
	header( 'Content-Length: ' . ( strlen( $pre_output ) + strlen( $output ) ) );
	header( "Content-Type: $mime_type" );

	echo $pre_output . $output;

	return ob_get_clean();
}

function page_optimize_status_exit( $status ) {
	http_response_code( $status );
	exit;
}

function page_optimize_get_mime_type( $file ) {
	global $page_optimize_types;

	$lastdot_pos = strrpos( $file, '.' );
	if ( false === $lastdot_pos ) {
		return false;
	}

	$ext = substr( $file, $lastdot_pos + 1 );

	return isset( $page_optimize_types[ $ext ] ) ? $page_optimize_types[ $ext ] : false;
}

function page_optimize_get_path( $uri ) {
	static $dependency_path_mapping;
	if ( empty( $dependency_path_mapping ) ) {
		$dependency_path_mapping = new Page_Optimize_Dependency_Path_Mapping();
	}

	if ( ! strlen( $uri ) ) {
		page_optimize_status_exit( 400 );
	}

	if ( false !== strpos( $uri, '..' ) || false !== strpos( $uri, "\0" ) ) {
		page_optimize_status_exit( 400 );
	}

	$path = $dependency_path_mapping->uri_path_to_fs_path( $uri );
	if ( false === $path ) {
		page_optimize_status_exit( 404 );
	}
	return $path;
}
