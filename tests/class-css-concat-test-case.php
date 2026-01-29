<?php
/**
 * Base test case for CSS concatenation tests.
 *
 * Provides shared setup, teardown, and helper methods for testing the
 * Page_Optimize_CSS_Concat class. Subclasses only need to define test methods.
 */
abstract class CSS_Concat_Test_Case extends WP_UnitTestCase {
	/** @var string[] Temporary CSS files created during tests, cleaned up in tear_down(). */
	protected $tmp_files = [];

	public function set_up(): void {
		parent::set_up();
		require_once dirname( __DIR__ ) . '/concat-css.php';

		// Hook into both the plugin's and WordPress core's style_loader_tag filters
		// so we can inject data-handles attributes for test assertions.
		add_filter( 'page_optimize_style_loader_tag', [ $this, 'inject_data_handles_into_page_optimize_tag' ], 10, 4 );
		add_filter( 'style_loader_tag', [ $this, 'inject_data_handles_into_core_tag' ], 10, 4 );
	}

	public function tear_down(): void {
		remove_filter( 'page_optimize_style_loader_tag', [ $this, 'inject_data_handles_into_page_optimize_tag' ], 10 );
		remove_filter( 'style_loader_tag', [ $this, 'inject_data_handles_into_core_tag' ], 10 );

		// Clean up any temporary CSS files created during the test.
		foreach ( $this->tmp_files as $file ) {
			@unlink( $file );
		}
		$this->tmp_files = [];

		parent::tear_down();
	}

	/**
	 * Filter callback for concatenated <link> tags.
	 *
	 * Injects a data-handles attribute containing a comma-separated list of
	 * all handles included in the concatenated tag (e.g., data-handles="a,b,c").
	 */
	public function inject_data_handles_into_page_optimize_tag( $tag, $handles, $href, $media ) {
		$list = is_array( $handles ) ? implode( ',', $handles ) : (string) $handles;

		if ( false === strpos( $tag, 'data-handles=' ) ) {
			$tag = preg_replace(
				'/<link\s/i',
				'<link data-handles="' . esc_attr( $list ) . '" ',
				$tag,
				1
			);
		}
		return $tag;
	}

	/**
	 * Filter callback for non-concatenated <link> tags (WordPress core).
	 *
	 * Injects a data-handles attribute containing the single handle
	 * (e.g., data-handles="a").
	 */
	public function inject_data_handles_into_core_tag( $tag, $handle, $href, $media ) {
		if ( false === strpos( $tag, 'data-handles=' ) ) {
			$tag = preg_replace(
				'/<link\s/i',
				'<link data-handles="' . esc_attr( $handle ) . '" ',
				$tag,
				1
			);
		}
		return $tag;
	}

	/**
	 * Creates a temporary CSS file in wp-content and returns its URL.
	 *
	 * Files are automatically cleaned up in tear_down().
	 */
	protected function make_content_css( string $filename, string $contents = '/* test */' ): string {
		$fs     = trailingslashit( WP_CONTENT_DIR ) . $filename;
		$result = file_put_contents( $fs, $contents );
		$this->assertNotFalse( $result, 'Failed to write temporary CSS fixture.' );
		$this->tmp_files[] = $fs;

		return content_url( $filename );
	}

	/**
	 * Creates a fresh Page_Optimize_CSS_Concat instance for testing.
	 */
	protected function new_concat_styles(): Page_Optimize_CSS_Concat {
		$core           = new WP_Styles();
		$core->base_url = untrailingslashit( site_url() );

		$concat = new Page_Optimize_CSS_Concat( $core );
		// Disable compression so URLs remain human-readable in test output.
		$concat->allow_gzip_compression = false;

		return $concat;
	}

	/**
	 * Renders all enqueued styles and returns the generated HTML.
	 */
	protected function render( WP_Styles $styles ): string {
		ob_start();
		$styles->do_items();
		return (string) ob_get_clean();
	}

	/**
	 * Parses rendered HTML and returns an array of handle groups in document order.
	 *
	 * Each group represents one <link> tag. A concatenated tag will have multiple
	 * handles (e.g., ['a', 'b']), while a regular tag will have one (e.g., ['a']).
	 */
	protected function extract_handle_groups( string $html ): array {
		preg_match_all( '/data-handles=["\']([^"\']+)["\']/', $html, $m );
		$groups = [];
		foreach ( $m[1] as $list ) {
			$handles = array_values( array_filter( array_map( 'trim', explode( ',', $list ) ) ) );
			$groups[] = $handles;
		}
		return $groups;
	}

	/**
	 * Flattens an array of handle groups into a single ordered list of handles.
	 *
	 * Useful for asserting overall handle order regardless of how they're grouped.
	 */
	protected function flatten_groups( array $groups ): array {
		$out = [];
		foreach ( $groups as $g ) {
			foreach ( $g as $h ) {
				$out[] = $h;
			}
		}
		return $out;
	}
}
