<?php

require_once __DIR__ . '/class-css-concat-test-case.php';

class Test_CSS_Concat_Order extends CSS_Concat_Test_Case {
	/**
	 * When an external/CDN stylesheet appears between two local stylesheets, the concatenation must be split.
	 *
	 * Enqueue order: a (local) -> b (external CDN) -> c (local)
	 * Expected output: [a], [b], [c]  (three separate <link> tags)
	 *
	 * @group css-order-bug
	 *
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
	* When an external/CDN stylesheet is enqueued first, it must remain first in the output.
	*
	* Enqueue order: a (external CDN) -> b (local) -> c (local)
	* Expected output: [a], [b, c] or [a], [b], [c]  (a must be first)
	*
	* @group css-order-bug
	*/
	public function test_external_stylesheet_first_preserves_order(): void {
		$styles = $this->new_concat_styles();

		$b = $this->make_content_css( 'po-b.css' );
		$c = $this->make_content_css( 'po-c.css' );

		$styles->add( 'a', 'https://cdn.example.invalid/a.css', [], null, 'all' ); // External URL - can't be concatted
		$styles->add( 'b', $b, [], null, 'all' );
		$styles->add( 'c', $c, [], null, 'all' );

		$styles->enqueue( 'a' );
		$styles->enqueue( 'b' );
		$styles->enqueue( 'c' );

		$html    = $this->render( $styles );
		$groups  = $this->extract_handle_groups( $html );
		$handles = $this->flatten_groups( $groups );

		// External 'a' must be first, b and c must follow in order.
		$this->assertSame( [ 'a', 'b', 'c' ], $handles, 'External stylesheet enqueued first must remain first in output.' );

		// 'a' must be in its own group (not concatenated).
		$this->assertNotEmpty( $groups );
		$this->assertSame( [ 'a' ], $groups[0], 'External stylesheet should not be concatenated.' );
	}

	/**
	 * When stylesheets have different media attributes, concatenation must respect the original order.
	 *
	 * Enqueue order: a (media="all") -> b (media="screen") -> c (media="all")
	 * Expected output order: a, b, c (not a+c combined, then b)
	 *
	 * @group css-order-bug
	 *
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
	 *
	 * @group css-order-bug
	 *
	 **/
	public function test_inline_style_should_break_concat_boundary(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-ia.css' );
		$b = $this->make_content_css( 'po-ib.css' );

		$styles->add( 'a', $a, [], null, 'all' );
		$styles->add( 'b', $b, [], null, 'all' );

		// Inline CSS on "a" must not be moved after "b"'s rules due to concatenation.
		$styles->add_inline_style( 'a', '.po-inline-a{color:red;}' );

		$styles->enqueue( 'a' );
		$styles->enqueue( 'b' );

		$html   = $this->render( $styles );
		$groups = $this->extract_handle_groups( $html );

		// "a" and "b" must not be in the same concatenated <link>.
		$this->assertSame( [ [ 'a' ], [ 'b' ] ], $groups, 'Inline CSS should force a boundary (do not concat across inline styles).' );

		// Actually check that the inline style appears before "b" stylesheet.
		// Otherwise, we could split A and B into groups, but then do A,B,Inline, which is wrong.
		$this->assertNotFalse( strpos( $html, '.po-inline-a' ), 'Expected inline CSS to be printed.' );

		$pos_inline = strpos( $html, '.po-inline-a' );
		$pos_b      = strpos( $html, 'po-ib.css' );

		$this->assertNotFalse( $pos_b, 'Expected b stylesheet URL in output.' );
		$this->assertLessThan( $pos_b, $pos_inline, 'Inline CSS for a must appear before b stylesheet.' );
	}

	/**
	* After a non-concatenatable item breaks a run, subsequent local stylesheets
	* should still be concatenated together.
	*
	* This catches over-correction where fixing ordering bugs leads to giving up
	* on concatenation entirely after any boundary.
	*
	* Enqueue order: a (local) -> ext (external) -> b (local) -> c (local)
	* Expected: [a], [ext], [b, c]  (order preserved, b+c still concatenated)
	*
	* @group css-order-bug
	*/
	public function test_concatenation_resumes_after_nonconcat_boundary(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-resume-a.css' );
		$b = $this->make_content_css( 'po-resume-b.css' );
		$c = $this->make_content_css( 'po-resume-c.css' );

		$styles->add( 'a', $a, [], null, 'all' );
		$styles->add( 'ext', 'https://cdn.example.invalid/external.css', [], null, 'all' );
		$styles->add( 'b', $b, [], null, 'all' );
		$styles->add( 'c', $c, [], null, 'all' );

		$styles->enqueue( 'a' );
		$styles->enqueue( 'ext' );
		$styles->enqueue( 'b' );
		$styles->enqueue( 'c' );

		$html   = $this->render( $styles );
		$groups = $this->extract_handle_groups( $html );

		$handles = $this->flatten_groups( $groups );
		$this->assertSame( [ 'a', 'ext', 'b', 'c' ], $handles, 'Order must be preserved.' );

		// 'a' should be alone (before external boundary).
		$this->assertSame( [ 'a' ], $groups[0], 'First local stylesheet should be in its own group before external.' );

		// 'ext' should be alone (external, not concatenatable).
		$this->assertSame( [ 'ext' ], $groups[1], 'External stylesheet should be in its own group.' );

		// 'b' and 'c' should be concatenated together (resume after boundary).
		$this->assertSame( [ 'b', 'c' ], $groups[2], 'Concatenation should resume after external boundary.' );
	}

	/**
	* Stylesheets with the same media attribute should still be concatenated
	* within their run, even when different media types cause boundaries.
	*
	* This ensures the fix for media interleaving doesn't accidentally prevent
	* concatenation within same-media runs.
	*
	* Enqueue order: a (all) -> b (screen) -> c (screen) -> d (all)
	* Expected: [a], [b, c], [d]  (order preserved, b+c concatenated)
	*
	* @group css-order-bug
	*/
	public function test_same_media_stylesheets_concatenate_within_run(): void {
		$styles = $this->new_concat_styles();

		$a = $this->make_content_css( 'po-media-run-a.css' );
		$b = $this->make_content_css( 'po-media-run-b.css' );
		$c = $this->make_content_css( 'po-media-run-c.css' );
		$d = $this->make_content_css( 'po-media-run-d.css' );

		$styles->add( 'a', $a, [], null, 'all' );
		$styles->add( 'b', $b, [], null, 'screen' );
		$styles->add( 'c', $c, [], null, 'screen' );
		$styles->add( 'd', $d, [], null, 'all' );

		$styles->enqueue( 'a' );
		$styles->enqueue( 'b' );
		$styles->enqueue( 'c' );
		$styles->enqueue( 'd' );

		$html   = $this->render( $styles );
		$groups = $this->extract_handle_groups( $html );

		$handles = $this->flatten_groups( $groups );
		$this->assertSame( [ 'a', 'b', 'c', 'd' ], $handles, 'Order must be preserved across media boundaries.' );

		// 'a' alone (media=all, before screen run).
		$this->assertSame( [ 'a' ], $groups[0], 'First all-media stylesheet should be alone before screen run.' );

		// 'b' and 'c' concatenated (both media=screen).
		$this->assertSame( [ 'b', 'c' ], $groups[1], 'Same-media stylesheets should be concatenated within their run.' );

		// 'd' alone (media=all, after screen run).
		$this->assertSame( [ 'd' ], $groups[2], 'Final all-media stylesheet should be alone after screen run.' );
	}
}
