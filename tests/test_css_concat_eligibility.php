<?php

require_once __DIR__ . '/class-css-concat-test-case.php';

class Test_CSS_Concat_Eligibility extends CSS_Concat_Test_Case {
	/**
	* Verifies the basic success case: two local stylesheets with the same media type
	* should be combined into a single <link> tag.
	*
	* Enqueue: a (local, media="all") -> b (local, media="all")
	* Expected: [['a', 'b']]  (one <link> tag containing both)
	*
	* Also verifies the concatenated URL uses the /_static/?? combo endpoint
	* (the server-side mechanism that serves multiple files in one request).
	*/
	public function test_concats_two_internal_styles_same_media_into_one_link_group(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-two-a.css' );
		$b = $this->make_content_css( 'po-two-b.css' );

		$styles->add( 'a', $a, [], null, 'all' );
		$styles->add( 'b', $b, [], null, 'all' );

		$styles->enqueue( 'a' );
		$styles->enqueue( 'b' );

		$html   = $this->render( $styles );
		$groups = $this->extract_handle_groups( $html );

		$this->assertSame( [ [ 'a', 'b' ] ], $groups );
		$this->assertStringContainsString( '/_static/??', $html );
	}

	/**
	* When there's only one stylesheet, don't use the combo endpoint - just serve it
	* directly with a cache-busting timestamp (?m=...).
	*
	* The combo endpoint has overhead, so for a single file it's more efficient
	* to serve it directly.
	*/
	public function test_single_internal_style_is_not_served_via_static_combo_endpoint(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-single-a.css' );
		$styles->add( 'a', $a, [], null, 'all' );
		$styles->enqueue( 'a' );

		$html = $this->render( $styles );

		$this->assertStringNotContainsString( '/_static/??', $html );
		$this->assertStringContainsString( '?m=', $html );
	}

	/**
	* When the page is in RTL (right-to-left) mode and a stylesheet has RTL-specific
	* handling, it cannot be concatenated.
	*
	* WordPress can automatically swap in RTL versions of stylesheets (e.g.,
	* style.css -> style-rtl.css). This substitution happens at render time,
	* so these sheets need to stay separate.
	*/
	public function test_rtl_marked_styles_not_concatenated_when_text_direction_is_rtl(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-rtl-a.css' );
		$b = $this->make_content_css( 'po-rtl-b.css' );

		$styles->add( 'a', $a, [], null, 'all' );
		$styles->add( 'b', $b, [], null, 'all' );

		$styles->text_direction = 'rtl';
		$styles->registered['b']->extra['rtl'] = true;

		$styles->enqueue( 'a' );
		$styles->enqueue( 'b' );

		$html   = $this->render( $styles );
		$groups = $this->extract_handle_groups( $html );

		foreach ( $groups as $g ) {
			if ( in_array( 'b', $g, true ) ) {
				$this->assertCount( 1, $g, 'RTL stylesheet should not be concatenated when text_direction is rtl.' );
			}
		}
	}

	/**
	 * Admins can configure specific handles to be excluded from concatenation via the
	 * page_optimize-css-exclude option.
	 *
	 * Some stylesheets break when concatenated (e.g., they use @import or have
	 * relative URLs that break). This gives admins an escape hatch.
	 *
	 * Excluded handles must:
	 * 1. Not be concatenated with other handles (rendered in their own <link> tag)
	 * 2. Maintain their original enqueue order (not be pushed to the end)
	 *
	 * Enqueue order: a (excluded) -> b
	 * Expected output order: [a], [b] (a first, in its own tag)
	 * Bug: [b], [a] (excluded handle pushed to end)
	 *
	 * @group css-order-bug
	 */
	public function test_exclusion_list_prevents_concatenation_of_handle(): void {
		$old = get_option( 'page_optimize-css-exclude', null );
		update_option( 'page_optimize-css-exclude', 'a' );

		try {
			$styles = $this->new_concat_styles();

			$a = $this->make_content_css( 'po-ex-a.css' );
			$b = $this->make_content_css( 'po-ex-b.css' );

			$styles->add( 'a', $a, [], null, 'all' );
			$styles->add( 'b', $b, [], null, 'all' );

			$styles->enqueue( 'a' );
			$styles->enqueue( 'b' );

			$html   = $this->render( $styles );
			$groups = $this->extract_handle_groups( $html );

			foreach ( $groups as $g ) {
				if ( in_array( 'a', $g, true ) ) {
					$this->assertCount( 1, $g, 'Excluded handle should not be concatenated with others.' );
				}
			}
			$handles = $this->flatten_groups( $groups );
			$this->assertSame( [ 'a', 'b' ], $handles );
		} finally {
			// Restore option so this test doesn't affect other tests.
			if ( null === $old ) {
				delete_option( 'page_optimize-css-exclude' );
			} else {
				update_option( 'page_optimize-css-exclude', $old );
			}
		}
	}

	/**
	* Developers can programmatically exclude handles using the css_do_concat filter hook.
	*
	* This is the code-based equivalent of the exclusion list-plugins or themes can
	* hook in and prevent concatenation of specific stylesheets based on custom logic.
	*
	* Enqueue order: a (filtered to be excluded) -> b
	* Expected output order: [a], [b] (a first, in its own tag)
	* Bug: [b], [a] (excluded handle pushed to end)
	*
	* @group css-order-bug
	*/
	public function test_css_do_concat_filter_can_disable_concatenation(): void {
		$filter_callback = function( $do_concat, $handle ) {
			if ( 'a' === $handle ) {
				return false;
			}
			return $do_concat;
		};

		add_filter( 'css_do_concat', $filter_callback, 10, 2 );

		try {
			$styles = $this->new_concat_styles();

			$a = $this->make_content_css( 'po-filter-a.css' );
			$b = $this->make_content_css( 'po-filter-b.css' );

			$styles->add( 'a', $a, [], null, 'all' );
			$styles->add( 'b', $b, [], null, 'all' );

			$styles->enqueue( 'a' );
			$styles->enqueue( 'b' );

			$html   = $this->render( $styles );
			$groups = $this->extract_handle_groups( $html );

			foreach ( $groups as $g ) {
				if ( in_array( 'a', $g, true ) ) {
					$this->assertCount( 1, $g, 'css_do_concat filter should be able to prevent concatenation of a handle.' );
				}
			}
			$handles = $this->flatten_groups( $groups );
			$this->assertSame( [ 'a', 'b' ], $handles, 'Filtered handle must not be reordered behind concatenated output.' );
		} finally {
			remove_filter( 'css_do_concat', $filter_callback, 10 );
		}
	}
}
