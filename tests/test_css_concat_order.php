<?php
// tests/test_css_concat_order.php

/**
 * @group page-optimize
 */
class Test_CSS_Concat_Order extends WP_UnitTestCase {
	private $tmp_files = [];

	public function set_up(): void {
		parent::set_up();

		require_once dirname( __DIR__ ) . '/concat-css.php';

		add_filter( 'page_optimize_style_loader_tag', [ $this, 'inject_data_handles_into_page_optimize_tag' ], 10, 4 );
		add_filter( 'style_loader_tag', [ $this, 'inject_data_handles_into_core_tag' ], 10, 4 );
	}

	public function tear_down(): void {
		remove_filter( 'page_optimize_style_loader_tag', [ $this, 'inject_data_handles_into_page_optimize_tag' ], 10 );
		remove_filter( 'style_loader_tag', [ $this, 'inject_data_handles_into_core_tag' ], 10 );

		foreach ( $this->tmp_files as $file ) {
			@unlink( $file );
		}
		$this->tmp_files = [];

		parent::tear_down();
	}

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

	private function make_content_css( string $filename, string $contents = '/* test */' ): string {
		$fs = trailingslashit( WP_CONTENT_DIR ) . $filename;
		$result = file_put_contents( $fs, $contents );
		$this->assertNotFalse( $result, 'Failed to write temporary CSS fixture.' );
		$this->tmp_files[] = $fs;

		return content_url( $filename );
	}

	private function new_concat_styles(): Page_Optimize_CSS_Concat {
		$core = new WP_Styles();
		$core->base_url = untrailingslashit( site_url() );

		$concat = new Page_Optimize_CSS_Concat( $core );
		// Disable compression so URLs remain human-readable in test output.
		$concat->allow_gzip_compression = false;

		return $concat;
	}

	private function render( WP_Styles $styles ): string {
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
	private function extract_handle_groups( string $html ): array {
		preg_match_all( '/data-handles=["\']([^"\']+)["\']/', $html, $m );
		$groups = [];
		foreach ( $m[1] as $list ) {
			$handles = array_values( array_filter( array_map( 'trim', explode( ',', $list ) ) ) );
			$groups[] = $handles;
		}
		return $groups;
	}

	private function flatten_groups( array $groups ): array {
		$out = [];
		foreach ( $groups as $g ) {
			foreach ( $g as $h ) {
				$out[] = $h;
			}
		}
		return $out;
	}

	/**
	 * When an external/CDN stylesheet appears between two local stylesheets, the concatenation must be split.
	 *
	 * Enqueue order: a (local) -> b (external CDN) -> c (local)
	 * Expected output: [a], [b], [c]  (three separate <link> tags)
	 **/
	public function test_nonconcat_item_breaks_concat_run_and_preserves_order(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-a.css' );
		$c = $this->make_content_css( 'po-c.css' );

		$styles->add( 'a', $a, [], null, 'all' );
		$styles->add( 'b', 'https://cdn.example.invalid/b.css', [], null, 'all' ); // External URL - can't be concatted
		$styles->add( 'c', $c, [], null, 'all' );

		$styles->enqueue( 'a' );
		$styles->enqueue( 'b' );
		$styles->enqueue( 'c' );

		$html   = $this->render( $styles );
		$groups = $this->extract_handle_groups( $html );

		$this->assertSame( [ [ 'a' ], [ 'b' ], [ 'c' ] ], $groups, 'Expected external stylesheet to break concat and keep order.' );
	}

	/**
	 * When stylesheets have different media attributes, concatenation must respect the original order.
	 *
	 * Enqueue order: a (media="all") -> b (media="screen") -> c (media="all")
	 * Expected output order: a, b, c (not a+c combined, then b)
	 **/
	public function test_media_interleaving_must_not_reorder_handles(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-ma.css' );
		$b = $this->make_content_css( 'po-mb.css' );
		$c = $this->make_content_css( 'po-mc.css' );

		$styles->add( 'a', $a, [], null, 'all' );
		$styles->add( 'b', $b, [], null, 'screen' );
		$styles->add( 'c', $c, [], null, 'all' );

		$styles->enqueue( 'a' );
		$styles->enqueue( 'b' );
		$styles->enqueue( 'c' );

		$html    = $this->render( $styles );
		$groups  = $this->extract_handle_groups( $html );
		$handles = $this->flatten_groups( $groups );

		// Handles must appear in the same order they were enqueued.
		$this->assertSame( [ 'a', 'b', 'c' ], $handles, 'Media interleaving should not cause reordering (split concat runs on media changes).' );
	}

	/**
	 * When a stylesheet has inline CSS added via add_inline_style(), it can't be concatenated with
	 * subsequent stylesheets.
	 *
	 * Enqueue: a (with inline style after it) -> b
	 * Expected output: [a], [b]  (two separate tags, not combined)
	 **/
	public function test_inline_style_should_break_concat_boundary(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-ia.css' );
		$b = $this->make_content_css( 'po-ib.css' );

		$styles->add( 'a', $a, [], null, 'all' );
		$styles->add( 'b', $b, [], null, 'all' );

		// Inline CSS on "a" must not be moved after "b"’s rules due to concatenation.
		$styles->add_inline_style( 'a', '.po-inline-a{color:red;}' );

		$styles->enqueue( 'a' );
		$styles->enqueue( 'b' );

		$html   = $this->render( $styles );
		$groups = $this->extract_handle_groups( $html );

		// "a" and "b" must not be in the same concatenated <link>.
		$this->assertSame( [ [ 'a' ], [ 'b' ] ], $groups, 'Inline CSS should force a boundary (do not concat across inline styles).' );
	}
}
