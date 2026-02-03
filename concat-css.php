<?php

require_once __DIR__ . '/dependency-path-mapping.php';
require_once __DIR__ . '/utils.php';

if ( ! defined( 'ALLOW_GZIP_COMPRESSION' ) ) {
	define( 'ALLOW_GZIP_COMPRESSION', true );
}

class Page_Optimize_CSS_Concat extends WP_Styles {
	private $dependency_path_mapping;
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

		$this->dependency_path_mapping = new Page_Optimize_Dependency_Path_Mapping(
			apply_filters( 'page_optimize_site_url', $this->base_url )
		);
	}

	protected function has_inline_style( $handle ) {
		$after_output = $this->get_data( $handle, 'after' );
		if ( ! empty( $after_output ) ) {
			return true;
		}

		return false;
	}

	protected function css_has_import( $path ) {
		static $cache = array();

		if ( empty( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$key = $path;

		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}

		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			$cache[ $key ] = false;
			return false;
		}

		$found = false;
		$tail  = '';

		// Scan in chunks to keep memory bounded for large stylesheets.
		while ( ! feof( $fh ) ) {
			$chunk = fread( $fh, 32768 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$haystack = $tail . $chunk;
			if ( false !== stripos( $haystack, '@import' ) ) {
				$found = true;
				break;
			}

			// Keep a short overlap so "@import" across chunk boundaries isn't missed.
			$tail = substr( $haystack, -7 );
		}

		fclose( $fh );

		$cache[ $key ] = $found;
		return $found;
	}

	function do_items( $handles = false, $group = false ) {
		$handles = false === $handles ? $this->queue : (array) $handles;
		$stylesheets = array();
		$siteurl = apply_filters( 'page_optimize_site_url', $this->base_url );

		$this->all_deps( $handles );

		$concat_group = null;

		foreach ( $this->to_do as $key => $handle ) {
			$css_realpath = null;
			// 1a. Skip invalid dependencies.
			if ( ! isset( $this->registered[ $handle ] ) ) {
				unset( $this->to_do[ $key ] );
				continue;
			}

			$obj = $this->registered[ $handle ];

			// 1b. Skip virtual (src-less) dependencies.
			if ( empty( $obj->src ) ) {
				if ( null !== $concat_group ) {
					$stylesheets[] = $concat_group;
					$concat_group = null;
				}

				$stylesheets[] = array(
					'type'   => 'do_item',
					'handle' => $handle,
				);
				unset( $this->to_do[ $key ] );
				continue;
			}

			$obj->src = apply_filters( 'style_loader_src', $obj->src, $obj->handle );

			// Core is kind of broken and returns "true" for src of "colors" handle
			// http://core.trac.wordpress.org/attachment/ticket/16827/colors-hacked-fixed.diff
			// http://core.trac.wordpress.org/ticket/20729
			$css_url = $obj->src;
			if ( 'colors' == $obj->handle && true === $css_url ) {
				$css_url = wp_style_loader_src( $css_url, $obj->handle );
			}

			$css_url_parsed = parse_url( $css_url );
			$css_path = ( is_array( $css_url_parsed ) && isset( $css_url_parsed['path'] ) ) ? $css_url_parsed['path'] : '';
			$extra = $obj->extra;

			// 2. Determine if this stylesheet can be concatenated.

			// Don't concat by default
			$do_concat = false;

			// Only try to concat static css files
			if ( $css_path && false !== strpos( $css_path, '.css' ) ) {
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
			$is_internal_uri = $this->dependency_path_mapping->is_internal_uri( $css_url );
			if ( $do_concat && ! $is_internal_uri ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat CSS %s => External URL: %s -->\n", esc_html( $handle ), esc_url( $css_url ) );
				}
				$do_concat = false;
			}

			if ( $do_concat ) {
				// Resolve paths and concat styles that exist in the filesystem
				$css_realpath = $this->dependency_path_mapping->dependency_src_to_fs_path( $css_url );
				if ( false === $css_realpath ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						echo sprintf( "\n<!-- No Concat CSS %s => Invalid Path %s -->\n", esc_html( $handle ), esc_html( $css_realpath ) );
					}
					$do_concat = false;
				}
			}

			// Skip concating CSS from exclusion list
			$exclude_list = page_optimize_css_exclude_list();
			foreach ( $exclude_list as $exclude ) {
				if ( $do_concat && $handle === $exclude ) {
					$do_concat = false;
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						echo sprintf( "\n<!-- No Concat CSS %s => Excluded option -->\n", esc_html( $handle ) );
					}
				}
			}

			// Allow plugins to disable concatenation of certain stylesheets.
			$filtered_concat = apply_filters( 'css_do_concat', $do_concat, $handle );
			if ( $do_concat && ! $filtered_concat ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat CSS %s => Filtered `false` -->\n", esc_html( $handle ) );
				}
			}
			$do_concat = $filtered_concat;

			/**
			 * 3. Add to the stylesheets output list.
			 *
			 * We keep a running $concat_group that accumulates consecutive concat-eligible styles
			 * sharing the same media type. The group is finalized and appended to $stylesheets when:
			 *   - A non-concat style appears
			 *   - The media type changes
			 *   - An inline style is attached to the current style
			 * This makes sure output order matches registration order.
			 **/
			if ( true === $do_concat ) {
				$media = $obj->args;
				if ( empty( $media ) ) {
					$media = 'all';
				}

				// Media type changed - finish the current group.
				if ( null !== $concat_group && $concat_group['media'] !== $media ) {
					$stylesheets[] = $concat_group;
					$concat_group = null;
				}

				// If a non-first stylesheet in a group contains @import, the concat service hoists it,
				// reordering CSS. Split groups so @import stylesheets are always first in their group.
				if (
					null !== $concat_group
					&& ! empty( $css_realpath )
					&& $this->css_has_import( $css_realpath )
				) {
					$stylesheets[] = $concat_group;
					$concat_group = null;
				}

				// Start a new group if needed.
				if ( null === $concat_group ) {
					$concat_group = array(
						'type'    => 'concat',
						'media'   => $media,
						'paths'   => array(),
						'handles' => array(),
					);
				}

				// Add this stylesheet to the current group.
				$concat_group['paths'][] = $css_path;
				$concat_group['handles'][] = $handle;
				$this->done[] = $handle;

				// Inline styles must print right after their <link>, so break the group
				// because we can't concat directly anything after this CSS.
				if ( $this->has_inline_style( $handle ) ) {
					$stylesheets[] = $concat_group;
					$concat_group = null;
				}
			} else {
				// Non-concat item - finish any current open group to preserve order.
				if ( null !== $concat_group ) {
					$stylesheets[] = $concat_group;
					$concat_group = null;
				}
				// Add the non-concat item.
				$stylesheets[] = array(
					'type'   => 'do_item',
					'handle' => $handle,
				);
			}
			unset( $this->to_do[ $key ] );
		}

		if ( null !== $concat_group ) {
			$stylesheets[] = $concat_group;
		}

		foreach ( $stylesheets as $css_array ) {
			if ( 'do_item' === $css_array['type'] ) {
				if ( $this->do_item( $css_array['handle'], $group ) ) {
					$this->done[] = $css_array['handle'];
				}
			} elseif ( 'concat' === $css_array['type'] && isset( $css_array['paths'] ) ) {
				$media = $css_array['media'];
				$css = $css_array['paths'];
				$handles = $css_array['handles'];

				if ( count( $css ) > 1 ) {
					$fs_paths = array();
					foreach ( $css as $css_uri_path ) {
						$fs_paths[] = $this->dependency_path_mapping->uri_path_to_fs_path( $css_uri_path );
					}

					$mtime = max( array_map( 'filemtime', $fs_paths ) );
					if ( page_optimize_use_concat_base_dir() ) {
						$path_str = implode( ',', array_map( 'page_optimize_remove_concat_base_prefix', $fs_paths ) );
					} else {
						$path_str = implode( ',', $css );
					}
					$path_str = "$path_str?m=$mtime";

					if ( $this->allow_gzip_compression ) {
						$path_64 = base64_encode( gzcompress( $path_str ) );
						if ( strlen( $path_str ) > ( strlen( $path_64 ) + 1 ) ) {
							$path_str = '-' . $path_64;
						}
					}

					$href = $siteurl . "/_static/??" . $path_str;
				} else {
					$href = Page_Optimize_Utils::cache_bust_mtime( $css[0], $siteurl );
				}

				$css_id = "$media-css-" . md5( $href );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$tag = "<link data-handles='" . esc_attr( implode( ',', $handles ) ) . "' rel='stylesheet' id='$css_id' href='$href' type='text/css' media='$media' />\n";
				} else {
					$tag = "<link rel='stylesheet' id='$css_id' href='$href' type='text/css' media='$media' />\n";
				}

				$tag = apply_filters( 'page_optimize_style_loader_tag', $tag, $handles, $href, $media );

				if ( is_array( $handles ) && count( $handles ) === 1 ) {
					$tag = apply_filters( 'style_loader_tag', $tag, $handles[0], $href, $media );
				}

				echo $tag;
				array_map( array( $this, 'print_inline_style' ), $handles );
			}
		}

		return $this->done;
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

function page_optimize_css_concat_init() {
	global $wp_styles;

	$wp_styles = new Page_Optimize_CSS_Concat( $wp_styles );
	$wp_styles->allow_gzip_compression = ALLOW_GZIP_COMPRESSION;
}

if ( page_optimize_should_concat_css() ) {
	add_action( 'init', 'page_optimize_css_concat_init' );
}
