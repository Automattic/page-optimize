<?php

require_once( __DIR__ . '/utils.php' );

if ( ! defined( 'ALLOW_GZIP_COMPRESSION' ) )
	define( 'ALLOW_GZIP_COMPRESSION', true );

class Http_Concat_JS_Concat extends WP_Scripts {
	private $old_scripts;
	public $allow_gzip_compression;

	function __construct( $scripts ) {
		if ( empty( $scripts ) || ! ( $scripts instanceof WP_Scripts ) ) {
			$this->old_scripts = new WP_Scripts();
		} else {
			$this->old_scripts = $scripts;
		}

		// Unset all the object properties except our private copy of the scripts object.
		// We have to unset everything so that the overload methods talk to $this->old_scripts->whatever
		// instead of $this->whatever.
		foreach ( array_keys( get_object_vars( $this ) ) as $key ) {
			if ( 'old_scripts' === $key ) {
				continue;
			}
			unset( $this->$key );
		}
	}

	protected function has_inline_content( $handle ) {
		$before_output = $this->get_data( $handle, 'before' );
		if ( ! empty( $before_output ) ) {
			return true;
		}

		$after_output = $this->get_data( $handle, 'after' );
		if ( ! empty( $after_output ) ) {
			return true;
		}

		// JavaScript translations
		$has_translations = ! empty( $this->registered[ $handle ]->textdomain );
		if ( $has_translations ) {
			return true;
		}

		return false;
	}

	function do_items( $handles = false, $group = false ) {
		$handles = false === $handles ? $this->queue : (array) $handles;
		$javascripts= array();
		$siteurl = apply_filters( 'page_optimize_site_url', $this->base_url );

		$this->all_deps( $handles );
		$level = 0;

		$total_count = count( $this->to_do );
		$using_strict = false;
		foreach( $this->to_do as $key => $handle ) {
			$script_is_strict = false;
			if ( in_array( $handle, $this->done ) || !isset( $this->registered[$handle] ) )
				continue;

			if ( 0 === $group && $this->groups[$handle] > 0 ) {
				$this->in_footer[] = $handle;
				unset( $this->to_do[$key] );
				continue;
			}

			if ( ! $this->registered[$handle]->src ) { // Defines a group.
				// if there are localized items, echo them
				$this->print_extra_script( $handle );
				$this->done[] = $handle;
				continue;
			}

			if ( false === $group && in_array( $handle, $this->in_footer, true ) )
				$this->in_footer = array_diff( $this->in_footer, (array) $handle );

			$obj = $this->registered[$handle];
			$js_url = $obj->src;
			$js_url_parsed = parse_url( $js_url );
			$extra = $obj->extra;

			// Don't concat by default
			$do_concat = false;

			// Only try to concat static js files
			if ( false !== strpos( $js_url_parsed['path'], '.js' ) ) {
				$do_concat = page_optimize_should_concat_js();
			} else {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat JS %s => Maybe Not Static File %s -->\n", esc_html( $handle ), esc_html( $obj->src ) );
				}
			}

			// Don't try to concat externally hosted scripts
			$is_internal_url = Http_Concat_Utils::is_internal_url( $js_url, $siteurl );
			if ( $do_concat && ! $is_internal_url ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat JS %s => External URL: %s -->\n", esc_html( $handle ), esc_url( $js_url ) );
				}
				$do_concat = false;
			}

			// Concat and canonicalize the paths only for
			// existing scripts that aren't outside ABSPATH
			if ( $do_concat ) {
				$js_realpath = Http_Concat_Utils::realpath( $js_url, $siteurl );
				if ( ! $js_realpath || ! file_exists( $js_realpath ) ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						echo sprintf( "\n<!-- No Concat JS %s => Invalid Path %s -->\n", esc_html( $handle ), esc_html( $js_realpath ) );
					}
					$do_concat = false;
				}
			}

			if ( $do_concat && $this->has_inline_content( $handle ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat JS %s => Has Inline Content -->\n", esc_html( $handle ) );
				}
				$do_concat = false;
			}

			// Skip core scripts that use Strict Mode
			if ( $do_concat && ( 'react' === $handle || 'react-dom' === $handle ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat JS %s => Has Strict Mode (Core) -->\n", esc_html( $handle ) );
				}
				$do_concat = false;
				$script_is_strict = true;
			} else if ( $do_concat && preg_match_all( '/^[\',"]use strict[\',"];/Uims', file_get_contents( $js_realpath ), $matches ) ) {
				// Skip third-party scripts that use Strict Mode
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat JS %s => Has Strict Mode (Third-Party) -->\n", esc_html( $handle ) );
				}
				$do_concat = false;
				$script_is_strict = true;
			} else {
				$script_is_strict = false;
			}

			// Don't concat jquery
			if ( $do_concat && false !== strpos( $handle, 'jquery' ) ) {
				$do_concat = false;
			}

			// Allow plugins to disable concatenation of certain scripts.
			if ( $do_concat && ! apply_filters( 'js_do_concat', $do_concat, $handle ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					echo sprintf( "\n<!-- No Concat JS %s => Filtered `false` -->\n", esc_html( $handle ) );
				}
			}
			$do_concat = apply_filters( 'js_do_concat', $do_concat, $handle );

			if ( true === $do_concat ) {
				if ( !isset( $javascripts[$level] ) )
					$javascripts[$level]['type'] = 'concat';

				$javascripts[$level]['paths'][] = $js_url_parsed['path'];
				$javascripts[$level]['handles'][] = $handle;

			} else {
				$level++;
				$javascripts[$level]['type'] = 'do_item';
				$javascripts[$level]['handle'] = $handle;
				$level++;
			}
			unset( $this->to_do[$key] );

			if ( $using_strict !== $script_is_strict ) {
				if ( $script_is_strict ) {
					$using_strict = true;
					$strict_count = 0;
				} else {
					$using_strict = false;
				}
			}

			if ( $script_is_strict ) {
				$strict_count++;
			}
		}

		if ( empty( $javascripts ) )
			return $this->done;

		foreach ( $javascripts as $js_array ) {
			if ( 'do_item' == $js_array['type'] ) {
				if ( $this->do_item( $js_array['handle'], $group ) )
					$this->done[] = $js_array['handle'];
			} else if ( 'concat' == $js_array['type'] ) {
				array_map( array( $this, 'print_extra_script' ), $js_array['handles'] );

				if ( isset( $js_array['paths'] ) && count( $js_array['paths'] ) > 1) {
					$paths = array_map( function( $url ) {
						$path = ABSPATH . $url;

						if ( ! file_exists( $path )
							&& false !== strpos( $url, '/wp-content/' )
							&& defined( 'WP_CONTENT_DIR' )
						) {
							$count = 1; // Only variables can be passed by reference.
							$path  = str_replace( '/wp-content', WP_CONTENT_DIR, $url, $count );
						}

						return $path;
					}, $js_array['paths'] );
					$mtime = max( array_map( 'filemtime', $paths ) );
					$path_str = implode( $js_array['paths'], ',' ) . "?m=${mtime}j";

					if ( $this->allow_gzip_compression ) {
						$path_64 = base64_encode( gzcompress( $path_str ) );
						if ( strlen( $path_str ) > ( strlen( $path_64 ) + 1 ) )
							$path_str = '-' . $path_64;
					}

					$href = $siteurl . "/_static/??" . $path_str;
				} elseif ( isset( $js_array['paths'] ) && is_array( $js_array['paths'] ) ) {
					$href = $this->cache_bust_mtime( $siteurl . $js_array['paths'][0], $siteurl );
				}

				$this->done = array_merge( $this->done, $js_array['handles'] );

				// Print before/after scripts from wp_inline_scripts() and concatenated script tag
				if ( isset( $js_array['extras']['before'] ) ) {
					foreach ( $js_array['extras']['before'] as $inline_before ) {
						echo $inline_before;
					}
				}

				if ( isset( $href ) ) {
					$handles = implode( ',', $js_array['handles'] );

					$load_mode = '';
					if ( page_optimize_should_defer_noncritcal_js() ) {
						$load_mode = "defer";

						// Stuff to skip loading JS async/defer
						if ( is_admin() ) {
							$load_mode = '';
						}
						if ( false !== strpos( $handles, 'jquery' ) ) {
							$load_mode = '';
						}
						if ( false !== strpos( $handles, 'a8c_' ) ) {
							$load_mode = '';
						}
						if ( false !== strpos( $handles, 'backbone' ) ) {
							$load_mode = '';
						}
						if ( false !== strpos( $handles, 'underscore' ) ) {
							$load_mode = '';
						}
					}

					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						echo "<script data-handles='" . esc_attr( $handles ) . "' $load_mode type='text/javascript' src='$href'></script>\n";
					} else {
						echo "<script type='text/javascript' $load_mode src='$href'></script>\n";
					}
				}

				if ( isset( $js_array['extras']['after'] ) ) {
					foreach ( $js_array['extras']['after'] as $inline_after ) {
						echo $inline_after;
					}
				}
			}
		}

		do_action( 'js_concat_did_items', $javascripts );
		return $this->done;
	}

	function cache_bust_mtime( $url, $siteurl ) {
		if ( strpos( $url, '?m=' ) )
			return $url;

		$parts = parse_url( $url );
		if ( ! isset( $parts['path'] ) || empty( $parts['path'] ) )
			return $url;

		$file = Http_Concat_Utils::realpath( $url, $siteurl );

		$mtime = false;
		if ( file_exists( $file ) )
			$mtime = filemtime( $file );

		if ( ! $mtime )
			return $url;

		if ( false === strpos( $url, '?' ) ) {
			$q = '';
		} else {
			list( $url, $q ) = explode( '?', $url, 2 );
			if ( strlen( $q ) )
				$q = '&amp;' . $q;
		}

		return "$url?m={$mtime}g{$q}";
	}

	function __isset( $key ) {
		return isset( $this->old_scripts->$key );
	}

	function __unset( $key ) {
		unset( $this->old_scripts->$key );
	}

	function &__get( $key ) {
		return $this->old_scripts->$key;
	}

	function __set( $key, $value ) {
		$this->old_scripts->$key = $value;
	}
}

function js_concat_init() {
	global $wp_scripts;

	$wp_scripts = new Http_Concat_JS_Concat( $wp_scripts );
	$wp_scripts->allow_gzip_compression = ALLOW_GZIP_COMPRESSION;
}

if ( page_optimize_should_concat_js() || page_optimize_should_defer_noncritcal_js() ) {
	add_action( 'init', 'js_concat_init' );
}