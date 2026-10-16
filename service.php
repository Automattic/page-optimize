<?php

require_once __DIR__ . '/cache-signature.php';

$page_optimize_types = array(
	'css' => 'text/css',
	'js' => 'application/javascript'
);

if ( ! defined( 'PAGE_OPTIMIZE_CONCAT_MAX_BYTES' ) ) {
	define( 'PAGE_OPTIMIZE_CONCAT_MAX_BYTES', 8 * 1024 * 1024 );
}

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

	// Only cache path lists this site signed. Key on the decoded list, so equivalent URLs share one entry.
	if ( $use_cache ) {
		$path_str = page_optimize_get_request_path_str();
		if ( page_optimize_verify_path_str( $path_str ) ) {
			$cache_key = md5( page_optimize_get_subdir_path_prefix() . '|' . $path_str );
		} else {
			$use_cache = false;
		}
	}

	if ( $use_cache ) {
		$cache_file = PAGE_OPTIMIZE_CACHE_DIR . "/page-optimize-cache-$cache_key";
		$cache_file_meta = PAGE_OPTIMIZE_CACHE_DIR . "/page-optimize-cache-meta-$cache_key";

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

			header( 'X-Page-Optimize: cached' );
			header( 'Cache-Control: max-age=' . 31536000 );
			header( 'ETag: ' . $etag );

			echo file_get_contents( $cache_file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- We need to trust this unfortunately.
			die();
		}
	}

	$output = page_optimize_build_output();
	$content = $output['content'];
	$headers = $output['headers'];

	foreach( $headers as $header ) {
		header( $header );
	}
	header( 'X-Page-Optimize: uncached' );
	header( 'Cache-Control: max-age=' . 31536000 );
	header( 'ETag: "' . md5( $content ) . '"' );

	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- We need to trust this unfortunately.

	if ( $use_cache ) {
		file_put_contents( $cache_file, $content );
		file_put_contents( $cache_file_meta, json_encode( array( 'headers' => $headers ) ) );
	}

	die();
}

/**
 * Get the decoded path list from the request.
 *
 * /_static/??/foo/bar.css,/foo1/bar/baz.css?m=293847g
 * or
 * /_static/??-eJzTT8vP109KLNJLLi7W0QdyDEE8IK4CiVjn2hpZGluYmKcDABRMDPM=
 *
 * Both return "/foo/bar.css,/foo1/bar/baz.css?m=293847g".
 */
function page_optimize_get_request_path_str() {
	$gzip_max_length = 256 * 1024;

	if ( ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ) ) ) {
		page_optimize_status_exit( 400 );
	}

	$args = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY );
	if ( ! $args || false === strpos( $args, '?' ) ) {
		page_optimize_status_exit( 400 );
	}

	$args = substr( $args, strpos( $args, '?' ) + 1 );

	if ( '-' == $args[0] ) {
		$args = @gzuncompress( base64_decode( substr( $args, 1 ) ), $gzip_max_length );

		// Invalid data, abort!
		if ( false === $args ) {
			page_optimize_status_exit( 400 );
		}
	}

	return $args;
}

/**
 * If we're in a subdirectory context, use that as the root.
 * We can't assume that the root serves the same content as the subdir.
 */
function page_optimize_get_subdir_path_prefix() {
	$request_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$_static_index = strpos( $request_path, '/_static/' );
	if ( $_static_index > 0 ) {
		return substr( $request_path, 0, $_static_index );
	}

	return '';
}

function page_optimize_build_output() {
	global $page_optimize_types;

	require_once __DIR__ . '/cssmin/cssmin.php';

	/* Config */
	$concat_max_files = 300;
	$concat_max_copies = 2;
	$concat_unique = true;

	/* Main() */
	$args = page_optimize_get_request_path_str();

	// /foo/bar.css,/foo1/bar/baz.css?m=293847g
	$version_string_pos = strpos( $args, '?' );
	if ( false !== $version_string_pos ) {
		$args = substr( $args, 0, $version_string_pos );
	}

	// /foo/bar.css,/foo1/bar/baz.css
	// Split no further than one past the file limit.
	$args = explode( ',', $args, $concat_max_files + 1 );
	if ( ! $args ) {
		page_optimize_status_exit( 400 );
	}

	// array( '/foo/bar.css', '/foo1/bar/baz.css' )
	if ( 0 == count( $args ) || count( $args ) > $concat_max_files ) {
		page_optimize_status_exit( 400 );
	}

	$subdir_path_prefix = page_optimize_get_subdir_path_prefix();

	$last_modified = 0;
	$pre_output = '';
	$output = '';
	$total_bytes = 0;
	$file_copies = array();

	$should_minify_css = defined( 'PAGE_OPTIMIZE_CSS_MINIFY' ) && ! empty( PAGE_OPTIMIZE_CSS_MINIFY );

	if ( $should_minify_css ) {
		$css_minify = new tubalmartin\CssMin\Minifier;
	}

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

		// Count copies per file, not per path string.
		$file_id = $stat['ino'] ? $stat['dev'] . ':' . $stat['ino'] : realpath( $fullpath );
		$file_copies[ $file_id ] = isset( $file_copies[ $file_id ] ) ? $file_copies[ $file_id ] + 1 : 1;
		if ( $file_copies[ $file_id ] > $concat_max_copies ) {
			page_optimize_status_exit( 400 );
		}

		// Check the size before reading the file.
		$file_size = $stat['size'];
		if ( $file_size > PAGE_OPTIMIZE_CONCAT_MAX_BYTES - $total_bytes ) {
			page_optimize_status_exit( 400 );
		}
		$total_bytes += $file_size;

		$buf = file_get_contents( $fullpath );
		if ( false === $buf ) {
			page_optimize_status_exit( 500 );
		}

		if ( 'text/css' == $mime_type ) {
			// Normalize repeated slashes in the rewrite prefix.
			$dirpath = '/' . ltrim( preg_replace( '#/{2,}#', '/', $subdir_path_prefix . dirname( $uri ) ), '/' );

			// url(relative/path/to/file) -> url(/absolute/and/not/relative/path/to/file)
			$buf = page_optimize_relative_path_replace( $buf, $dirpath );

			// AlphaImageLoader(...src='relative/path/to/file'...) -> AlphaImageLoader(...src='/absolute/path/to/file'...)
			$buf = preg_replace(
				'/(Microsoft.AlphaImageLoader\s*\([^\)]*src=(?:\'|")?)([^\/\'"\s\)](?:(?<!http:|https:).)*)\)/isU',
				'$1' . ( $dirpath == '/' ? '/' : $dirpath . '/' ) . '$2)',
				$buf
			);

			// The @charset rules must be on top of the output
			if ( 0 === stripos( $buf, '@charset' ) ) {
				$buf = preg_replace_callback(
					'/(?P<charset_rule>@charset\s+[\'"][^\'"]+[\'"];)/i',
					function ( $match ) use ( &$pre_output ) {

						if ( 0 === stripos( (string) $pre_output, '@charset' ) ) {
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
			if ( false !== stripos( $buf, '@import' ) ) {
				// Regex-based hoisting breaks on URLs containing semicolons (like Google Fonts)
				$buf = page_optimize_hoist_css_import_rules( $buf, $dirpath, $pre_output );
			}

			if ( $should_minify_css ) {
				$buf = $css_minify->run( $buf );
			}
		}

		// CSS rewriting can grow a file, so also cap the size of the output built so far.
		$buf_size = strlen( $buf ) + ( $page_optimize_types['js'] === $mime_type ? 2 : 0 );
		if ( $buf_size > PAGE_OPTIMIZE_CONCAT_MAX_BYTES - strlen( $pre_output ) - strlen( $output ) ) {
			page_optimize_status_exit( 400 );
		}

		if ( $page_optimize_types['js'] === $mime_type ) {
			$output .= "$buf;\n";
		} else {
			$output .= "$buf";
		}
	}

	$headers = array(
		'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT',
		'Content-Length: ' . ( strlen( $pre_output ) + strlen( $output ) ),
		"Content-Type: $mime_type",
	);

	return array(
		'headers' => $headers,
		'content' => $pre_output . $output,
	);
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

function page_optimize_relative_path_replace( $buf, $dirpath ) {
	// url(relative/path/to/file) -> url(/absolute/and/not/relative/path/to/file)
	$buf = preg_replace(
		'/(:?\s*url\s*\()\s*(?:\'|")?\s*([^\/\'"\s\)](?:(?<!data:|http:|https:|[\(\'"]#|%23).)*)[\'"\s]*\)/isU',
		'$1' . ( $dirpath == '/' ? '/' : $dirpath . '/' ) . '$2)',
		$buf
	);

	return $buf;
}

/**
 * Hoist CSS @import rules from a stylesheet body.
 *
 * @param string $buf Stylesheet contents.
 * @param string $dirpath Directory path used for resolving relative imports.
 * @param string $pre_output Buffer for hoisted rules.
 *
 * @return string Stylesheet body without hoisted @import rules.
 */
function page_optimize_hoist_css_import_rules( $buf, $dirpath, &$pre_output ) {
	$offset = 0;
	$new_buf = '';
	$new_pre_output = '';

	while ( true ) {
		$import_pos = page_optimize_css_find_next_import_position( $buf, $offset );
		if ( false === $import_pos ) {
			break;
		}

		$rule_end_pos = page_optimize_css_find_import_rule_end( $buf, $import_pos );
		if ( false === $rule_end_pos ) {
			// Parsing failed; keep original buffer unchanged.
			return $buf;
		}

		$new_buf .= substr( $buf, $offset, $import_pos - $offset );
		$rule = substr( $buf, $import_pos, $rule_end_pos - $import_pos + 1 );

		$new_pre_output .= page_optimize_css_rewrite_import_rule_path( $rule, $dirpath ) . "\n";
		$offset = $rule_end_pos + 1;
	}

	$new_buf .= substr( $buf, $offset );
	$pre_output .= $new_pre_output;

	return $new_buf;
}

/**
 * Find the next @import position outside quoted strings and block comments.
 *
 * @param string $css Source CSS.
 * @param int    $offset Search offset.
 *
 * @return int|false
 */
function page_optimize_css_find_next_import_position( $css, $offset = 0 ) {
	$length = strlen( $css );
	$quote = '';
	$brace_depth = 0;

	for ( $i = $offset; $i < $length; $i++ ) {
		$char = $css[ $i ];

		if ( '' !== $quote ) {
			if ( '\\' === $char && $i + 1 < $length ) {
				$i++;
				continue;
			}

			if ( $quote === $char ) {
				$quote = '';
			}

			continue;
		}

		if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
			$comment_end = strpos( $css, '*/', $i + 2 );
			if ( false === $comment_end ) {
				return false;
			}

			$i = $comment_end + 1;
			continue;
		}

		if ( '\'' === $char || '"' === $char ) {
			$quote = $char;
			continue;
		}

		if ( '{' === $char ) {
			$brace_depth++;
			continue;
		}

		if ( '}' === $char ) {
			if ( $brace_depth > 0 ) {
				$brace_depth--;
			}
			continue;
		}

		if ( $brace_depth > 0 ) {
			continue;
		}

		if ( '@' !== $char ) {
			continue;
		}

		if ( 0 !== strncasecmp( substr( $css, $i, 7 ), '@import', 7 ) ) {
			continue;
		}

		$next_char_pos = $i + 7;
		if ( $next_char_pos < $length && page_optimize_css_is_identifier_char( $css[ $next_char_pos ] ) ) {
			continue;
		}

		if ( ! page_optimize_css_is_valid_import_next_char( $css, $next_char_pos, $length ) ) {
			continue;
		}

		return $i;
	}

	return false;
}

/**
 * Find the semicolon that terminates an @import rule.
 *
 * @param string $css CSS source.
 * @param int    $import_pos Position of the @import token.
 *
 * @return int|false
 */
function page_optimize_css_find_import_rule_end( $css, $import_pos ) {
	$length = strlen( $css );
	$quote = '';
	$paren_depth = 0;

	for ( $i = $import_pos; $i < $length; $i++ ) {
		$char = $css[ $i ];

		if ( '' !== $quote ) {
			if ( '\\' === $char && $i + 1 < $length ) {
				$i++;
				continue;
			}

			if ( $quote === $char ) {
				$quote = '';
			}

			continue;
		}

		if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
			$comment_end = strpos( $css, '*/', $i + 2 );
			if ( false === $comment_end ) {
				return false;
			}

			$i = $comment_end + 1;
			continue;
		}

		if ( '\'' === $char || '"' === $char ) {
			$quote = $char;
			continue;
		}

		if ( '(' === $char ) {
			$paren_depth++;
			continue;
		}

		if ( ')' === $char ) {
			if ( $paren_depth > 0 ) {
				$paren_depth--;
			}
			continue;
		}

		if ( ';' === $char && 0 === $paren_depth ) {
			return $i;
		}

		if ( '{' === $char && 0 === $paren_depth ) {
			return false;
		}
	}

	return false;
}

/**
 * Rewrite relative @import paths using the current file directory.
 *
 * @param string $rule Complete @import rule including terminating semicolon.
 * @param string $dirpath Directory path used for resolving relative imports.
 *
 * @return string
 */
function page_optimize_css_rewrite_import_rule_path( $rule, $dirpath ) {
	$length = strlen( $rule );
	$import_pos = stripos( $rule, '@import' );
	if ( false === $import_pos ) {
		return $rule;
	}

	$cursor = $import_pos + 7; // strlen('@import').
	$cursor = page_optimize_css_skip_whitespace_and_comments( $rule, $cursor, $length );
	if ( false === $cursor ) {
		return $rule;
	}

	if ( $cursor >= $length ) {
		return $rule;
	}

	$path_pos = null;
	$path_len = null;

	// Parse either @import "..." or @import url(...)
	if ( '\'' === $rule[ $cursor ] || '"' === $rule[ $cursor ] ) {
		$quote = $rule[ $cursor ];
		$path_pos = $cursor + 1;
		$cursor++;

		while ( $cursor < $length ) {
			if ( '\\' === $rule[ $cursor ] && $cursor + 1 < $length ) {
				$cursor += 2;
				continue;
			}

			if ( $quote === $rule[ $cursor ] ) {
				$path_len = $cursor - $path_pos;
				break;
			}

			$cursor++;
		}
	} elseif ( 0 === strncasecmp( substr( $rule, $cursor, 3 ), 'url', 3 ) ) {
		$cursor += 3;

		$cursor = page_optimize_css_skip_whitespace_and_comments( $rule, $cursor, $length );
		if ( false === $cursor ) {
			return $rule;
		}

		if ( $cursor >= $length || '(' !== $rule[ $cursor ] ) {
			return $rule;
		}

		$cursor++;
		$cursor = page_optimize_css_skip_whitespace_and_comments( $rule, $cursor, $length );
		if ( false === $cursor ) {
			return $rule;
		}

		if ( $cursor >= $length ) {
			return $rule;
		}

		if ( '\'' === $rule[ $cursor ] || '"' === $rule[ $cursor ] ) {
			$quote = $rule[ $cursor ];
			$path_pos = $cursor + 1;
			$cursor++;

			while ( $cursor < $length ) {
				if ( '\\' === $rule[ $cursor ] && $cursor + 1 < $length ) {
					$cursor += 2;
					continue;
				}

				if ( $quote === $rule[ $cursor ] ) {
					$path_len = $cursor - $path_pos;
					$cursor++;
					break;
				}

				$cursor++;
			}

			if ( null === $path_len ) {
				return $rule;
			}

			$cursor = page_optimize_css_skip_whitespace_and_comments( $rule, $cursor, $length );
			if ( false === $cursor ) {
				return $rule;
			}

			if ( $cursor >= $length || ')' !== $rule[ $cursor ] ) {
				return $rule;
			}
		} else {
			$path_pos = $cursor;
			while ( $cursor < $length && ')' !== $rule[ $cursor ] ) {
				$cursor++;
			}

			if ( $cursor >= $length ) {
				return $rule;
			}

			$path_end = $cursor;
			while ( $path_end > $path_pos && ctype_space( $rule[ $path_end - 1 ] ) ) {
				$path_end--;
			}

			$path_len = $path_end - $path_pos;
		}
	}

	if ( null === $path_pos || null === $path_len || $path_len <= 0 ) {
		return $rule;
	}

	$path = substr( $rule, $path_pos, $path_len );
	if ( '' === $path || page_optimize_css_import_path_is_absolute( $path ) ) {
		return $rule;
	}

	$new_path = ( '/' === $dirpath ? '/' : $dirpath . '/' ) . $path;

	return substr( $rule, 0, $path_pos ) . $new_path . substr( $rule, $path_pos + $path_len );
}

/**
 * Determine whether the given @import path is absolute.
 *
 * @param string $path Import path.
 *
 * @return bool
 */
function page_optimize_css_import_path_is_absolute( $path ) {
	if ( '' === $path ) {
		return true;
	}

	if ( '/' === $path[0] ) {
		return true;
	}

	return 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $path );
}

/**
 * Determine whether a character can appear in a CSS identifier.
 *
 * @param string $char Single character.
 *
 * @return bool
 */
function page_optimize_css_is_identifier_char( $char ) {
	return ctype_alnum( $char ) || '_' === $char || '-' === $char;
}

/**
 * Determine whether the next token after "@import" can start a valid rule.
 *
 * @param string $css Source CSS.
 * @param int    $next_char_pos Position immediately after "@import".
 * @param int    $length CSS length.
 *
 * @return bool
 */
function page_optimize_css_is_valid_import_next_char( $css, $next_char_pos, $length ) {
	if ( $next_char_pos >= $length ) {
		return false;
	}

	$next_char = $css[ $next_char_pos ];
	if ( ctype_space( $next_char ) || '\'' === $next_char || '"' === $next_char ) {
		return true;
	}

	return '/' === $next_char && $next_char_pos + 1 < $length && '*' === $css[ $next_char_pos + 1 ];
}

/**
 * Skip CSS whitespace and block comments.
 *
 * @param string $css CSS source.
 * @param int    $cursor Start offset.
 * @param int    $length CSS length.
 *
 * @return int|false Next offset or false on malformed comment.
 */
function page_optimize_css_skip_whitespace_and_comments( $css, $cursor, $length ) {
	while ( $cursor < $length ) {
		$char = $css[ $cursor ];

		if ( ctype_space( $char ) ) {
			$cursor++;
			continue;
		}

		if ( '/' === $char && $cursor + 1 < $length && '*' === $css[ $cursor + 1 ] ) {
			$comment_end = strpos( $css, '*/', $cursor + 2 );
			if ( false === $comment_end ) {
				return false;
			}

			$cursor = $comment_end + 2;
			continue;
		}

		break;
	}

	return $cursor;
}

function page_optimize_get_path( $uri ) {
	static $dependency_path_mapping;

	if ( ! strlen( $uri ) ) {
		page_optimize_status_exit( 400 );
	}

	if ( false !== strpos( $uri, '..' ) || false !== strpos( $uri, "\0" ) ) {
		page_optimize_status_exit( 400 );
	}

	if ( defined( 'PAGE_OPTIMIZE_CONCAT_BASE_DIR' ) ) {
		$path = realpath( PAGE_OPTIMIZE_CONCAT_BASE_DIR . "/$uri" );

		if ( false === $path ) {
			$path = realpath( PAGE_OPTIMIZE_ABSPATH . "/$uri" );
		}
	} else {
		if ( empty( $dependency_path_mapping ) ) {
			require_once __DIR__ . '/dependency-path-mapping.php';
			$dependency_path_mapping = new Page_Optimize_Dependency_Path_Mapping();
		}
		$path = $dependency_path_mapping->uri_path_to_fs_path( $uri );
	}

	if ( false === $path ) {
		page_optimize_status_exit( 404 );
	}

	return $path;
}

if ( ! defined( 'PAGE_OPTIMIZE_SKIP_SERVICE_REQUEST' ) ) {
	page_optimize_service_request();
}
