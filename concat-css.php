<?php
require_once( __DIR__ . '/utils.php' );

if ( ! defined( 'ALLOW_GZIP_COMPRESSION' ) ) {
	define( 'ALLOW_GZIP_COMPRESSION', true );
}

class Page_Optimize_CSS_Concat extends WP_Styles {
	private $old_styles;
	public $allow_gzip_compression;

	function __construct( $styles ) {
		if ( empty( $styles ) || ! ( $styles instanceof WP_Styles ) ) {
			$this->old_styles = new WP_Styles();
		} else {
			$this->old_styles = $styles;
		}

		// Unset all the object properties except our private copy of the styles object.
		// We have to unset everything so that the overload methods talk to $this->old_styles->whatever
		// instead of $this->whatever.
		foreach ( array_keys( get_object_vars( $this ) ) as $key ) {
			if ( 'old_styles' === $key ) {
				continue;
			}
			unset( $this->$key );
		}
	}

	function do_items( $handles = false, $group = false ) {
		$handles = false === $handles ? $this->queue : (array) $handles;
		$stylesheets = array();
		$siteurl = apply_filters( 'page_optimize_site_url', $this->base_url );

		$this->all_deps( $handles );

		$stylesheet_group_index = 0;
		// Merge CSS into a single file
		$concat_group = 'concat';
		$stylesheets[ $concat_group ] = array();

		foreach ( $this->to_do as $key => $handle ) {
			$obj = $this->registered[ $handle ];
			$obj->src = apply_filters( 'style_loader_src', $obj->src, $obj->handle );

			// Core is kind of broken and returns "true" for src of "colors" handle
			// http://core.trac.wordpress.org/attachment/ticket/16827/colors-hacked-fixed.diff
			// http://core.trac.wordpress.org/ticket/20729
			$css_url = $obj->src;
			if ( 'colors' == $obj->handle && true === $css_url ) {
				$css_url = wp_style_loader_src( $css_url, $obj->handle );
			}

			$css_url_parsed = parse_url( $obj->src );
			$extra = $obj->extra;

			// Don't concat by default
			$do_concat = false;

			// Only try to concat static css files
			if ( false !== strpos( $css_url_parsed['path'], '.css' ) ) {
				$do_concat = true;
			} else {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat CSS %s => Maybe Not Static File %s -->\n", esc_html( $handle ), esc_html( $obj->src ) );
				}
			}

			// Don't try to concat styles which are loaded conditionally (like IE stuff)
			if ( isset( $extra['conditional'] ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat CSS %s => Has Conditional -->\n", esc_html( $handle ) );
				}
				$do_concat = false;
			}

			// Don't concat rtl stuff for now until concat supports it correctly
			if ( $do_concat && 'rtl' === $this->text_direction && ! empty( $extra['rtl'] ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat CSS %s => Is RTL -->\n", esc_html( $handle ) );
				}
				$do_concat = false;
			}

			// Don't try to concat externally hosted scripts
			$is_internal_url = Page_Optimize_Utils::is_internal_url( $css_url, $siteurl );
			if ( $do_concat && ! $is_internal_url ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat CSS %s => External URL: %s -->\n", esc_html( $handle ), esc_url( $css_url ) );
				}
				$do_concat = false;
			}

			// Concat scripts that aren't outside ABSPATH
			if ( $do_concat ) {
				$css_realpath = Page_Optimize_Utils::realpath( $css_url, $siteurl );
				if ( ! $css_realpath || ! file_exists( $css_realpath ) ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						echo sprintf( "\n<!-- No Concat CSS %s => Invalid Path %s -->\n", esc_html( $handle ), esc_html( $css_realpath ) );
					}
					$do_concat = false;
				}
			}

			// Allow plugins to disable concatenation of certain stylesheets.
			if ( $do_concat && ! apply_filters( 'css_do_concat', $do_concat, $handle ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat CSS %s => Filtered `false` -->\n", esc_html( $handle ) );
				}
			}
			$do_concat = apply_filters( 'css_do_concat', $do_concat, $handle );

			// Skip concating CSS from exclusion list
			$exclude_list = page_optimize_css_exclude_list();
			foreach ( $exclude_list as $exclude ) {
				if ( $do_concat && false !== strpos( $handle, $exclude ) ) {
					$do_concat = false;
				}
			}

			if ( true === $do_concat ) {
				$media = $obj->args;
				if ( empty( $media ) ) {
					$media = 'all';
				}

				if ( ! isset( $stylesheets[ $concat_group ] ) || ( isset( $stylesheets[ $concat_group ] ) && ! is_array( $stylesheets[ $concat_group ] ) ) ) {
					$stylesheets[ $concat_group ] = array();
				}

				$stylesheets[ $concat_group ][ $media ][ $handle ] = $css_url_parsed['path'];
				$this->done[] = $handle;
			} else {
				$stylesheet_group_index ++;
				$stylesheets[ $stylesheet_group_index ]['noconcat'][] = $handle;
				$stylesheet_group_index ++;
			}
			unset( $this->to_do[ $key ] );
		}

		foreach ( $stylesheets as $idx => $stylesheets_group ) {
			foreach ( $stylesheets_group as $media => $css ) {
				if ( 'noconcat' == $media ) {
					foreach ( $css as $handle ) {
						if ( $this->do_item( $handle, $group ) ) {
							$this->done[] = $handle;
						}
					}
					continue;
				} elseif ( count( $css ) > 1 ) {
					$paths = array_map( function ( $url ) {
						$path = ABSPATH . $url;

						if ( ! file_exists( $path )
							&& false !== strpos( $url, '/wp-content/' )
							&& defined( 'WP_CONTENT_DIR' )
						) {
							$path = str_replace( '/wp-content', WP_CONTENT_DIR, $url );
						}

						return $path;
					}, $css );
					$mtime = max( array_map( 'filemtime', $paths ) );
					$path_str = implode( $css, ',' ) . "?m={$mtime}";

					if ( $this->allow_gzip_compression ) {
						$path_64 = base64_encode( gzcompress( $path_str ) );
						if ( strlen( $path_str ) > ( strlen( $path_64 ) + 1 ) ) {
							$path_str = '-' . $path_64;
						}
					}

					$href = $siteurl . "/_static/??" . $path_str;
				} else {
					$href = $this->cache_bust_mtime( $siteurl . current( $css ), $siteurl );
				}

				$handles = array_keys( $css );
				$css_id = "$media-css-" . md5( $href );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo apply_filters( 'page_optimize_style_loader_tag', "<link data-handles='" . esc_attr( implode( ',', $handles ) ) . "' rel='stylesheet' id='$css_id' href='$href' type='text/css' media='$media' />\n", $handles, $href, $media );
				} else {
					echo apply_filters( 'page_optimize_style_loader_tag', "<link rel='stylesheet' id='$css_id' href='$href' type='text/css' media='$media' />\n", $handles, $href, $media );
				}
				array_map( array( $this, 'print_inline_style' ), array_keys( $css ) );
			}
		}

		return $this->done;
	}

	function cache_bust_mtime( $url, $siteurl ) {
		if ( strpos( $url, '?m=' ) ) {
			return $url;
		}

		$parts = parse_url( $url );
		if ( ! isset( $parts['path'] ) || empty( $parts['path'] ) ) {
			return $url;
		}

		$file = Page_Optimize_Utils::realpath( $url, $siteurl );

		$mtime = false;
		if ( file_exists( $file ) ) {
			$mtime = filemtime( $file );
		}

		if ( ! $mtime ) {
			return $url;
		}

		if ( false === strpos( $url, '?' ) ) {
			$q = '';
		} else {
			list( $url, $q ) = explode( '?', $url, 2 );
			if ( strlen( $q ) ) {
				$q = '&amp;' . $q;
			}
		}

		return "$url?m={$mtime}g{$q}";
	}

	function __isset( $key ) {
		return isset( $this->old_styles->$key );
	}

	function __unset( $key ) {
		unset( $this->old_styles->$key );
	}

	function &__get( $key ) {
		return $this->old_styles->$key;
	}

	function __set( $key, $value ) {
		$this->old_styles->$key = $value;
	}
}

function css_concat_init() {
	global $wp_styles;

	$wp_styles = new Page_Optimize_CSS_Concat( $wp_styles );
	$wp_styles->allow_gzip_compression = ALLOW_GZIP_COMPRESSION;

	// Add a `move-styles` query param to print the styles after the title element
	if (
		isset( $_GET['move-styles'] ) && '0' !== $_GET['move-styles'] &&
		8 === has_action( 'wp_head', 'wp_print_styles' )
	) {
		error_log( 'Moving styles' );
		remove_action( 'wp_head', 'wp_print_styles', 8 );
		add_action( 'wp_head', 'wp_print_styles', 1 );
	}

	// Add a `move-styles-before-title` query param to print the styles before the title element.
	// I wouldn't expect this to have different performance than `move-styles`, but I tried this
	// after not seeing a performance improvement with `move-styles`.
	if (
		isset( $_GET['move-styles-before-title'] ) && '0' !== $_GET['move-styles-before-title'] &&
		8 === has_action( 'wp_head', 'wp_print_styles' )
	) {
		error_log( 'Moving styles before title' );
		remove_action( 'wp_head', 'wp_print_styles', 8 );
		add_action( 'wp_head', 'wp_print_styles', 1 );
		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		add_action( 'wp_head', '_wp_render_title_tag', 1 );
	}

	// Add a `defer-emoji` query param to move emoji detection after style printing
	if (
		isset( $_GET['defer-emoji'] ) && '0' !== $_GET['defer-emoji'] &&
		7 === has_action( 'wp_head', 'print_emoji_detection_script' ) &&
		8 === has_action( 'wp_head', 'wp_print_styles' )
	) {
		error_log( 'Moving emoji detection to print after styles' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		add_action( 'wp_head', 'print_emoji_detection_script', 8 );
	}
}

if ( page_optimize_should_concat_css() ) {
	add_action( 'init', 'css_concat_init' );
}
