<?php

require_once __DIR__ . '/class-css-concat-test-case.php';

/**
 * @group page-optimize
 */
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
	}
}
