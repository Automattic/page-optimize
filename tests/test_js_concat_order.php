<?php

require_once __DIR__ . '/class-js-concat-test-case.php';

/**
 * Tests for JavaScript concatenation ordering and grouping boundaries.
 *
 * Verify that the Page_Optimize_JS_Concat class preserves script
 * execution order and respects head/footer boundaries during concatenation.
 *
 */
class Test_JS_Concat_Order extends JS_Concat_Test_Case {
	public function test_head_and_footer_scripts_are_not_concatenated_together(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-head-a.js' );
		$b = $this->make_content_js( 'po-footer-b.js' );

		$scripts->add( 'a', $a, [], null, false );
		$scripts->add( 'b', $b, [], null, true );
		$scripts->add_data( 'b', 'group', 1 ); // Manually set "footer"

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'b' );

		$head_groups = $this->render_head_groups( $scripts );

		// Footer script should NOT appear in head did_items at all.
		$this->assertSame( [ [ 'a' ] ], $head_groups );
		$this->assertContains( 'b', $scripts->in_footer, 'Footer script should be collected into in_footer during head pass.' );

		$footer_groups = $this->render_footer_groups( $scripts );
		$this->assertSame( [ [ 'b' ] ], $footer_groups );
	}

	public function test_multiple_head_scripts_are_concatenated(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-head-a2.js' );
		$b = $this->make_content_js( 'po-head-b2.js' );

		$scripts->add( 'a', $a, [], null, false );
		$scripts->add( 'b', $b, [], null, false );

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'b' );

		$head_groups = $this->render_head_groups( $scripts );
		$this->assertSame( [ [ 'a', 'b' ] ], $head_groups );
	}

	public function test_multiple_footer_scripts_are_concatenated(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-footer-a3.js' );
		$b = $this->make_content_js( 'po-footer-b3.js' );

		$scripts->add( 'a', $a, [], null, true );
		$scripts->add( 'b', $b, [], null, true );
		$scripts->add_data( 'a', 'group', 1 ); // Manually set "footer"
		$scripts->add_data( 'b', 'group', 1 ); // Manually set "footer"

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'b' );

		// Must run head pass first so footer handles get collected.
		$this->render_head( $scripts );

		$footer_groups = $this->render_footer_groups( $scripts );
		$this->assertSame( [ [ 'a', 'b' ] ], $footer_groups );
	}

	public function test_mixed_head_and_footer_scripts_concatenate_within_groups(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-mix-a.js' );
		$b = $this->make_content_js( 'po-mix-b.js' );
		$c = $this->make_content_js( 'po-mix-c.js' );
		$d = $this->make_content_js( 'po-mix-d.js' );

		$scripts->add( 'a', $a, [], null, false );
		$scripts->add( 'b', $b, [], null, true );
		$scripts->add_data( 'b', 'group', 1 ); // Manually set "footer"
		$scripts->add( 'c', $c, [], null, false );
		$scripts->add( 'd', $d, [], null, true );
		$scripts->add_data( 'd', 'group', 1 ); // Manually set "footer"

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'b' );
		$scripts->enqueue( 'c' );
		$scripts->enqueue( 'd' );

		$head_groups = $this->render_head_groups( $scripts );
		$this->assertSame( [ [ 'a', 'c' ] ], $head_groups );

		$footer_groups = $this->render_footer_groups( $scripts );
		$this->assertSame( [ [ 'b', 'd' ] ], $footer_groups );
	}

	public function test_external_footer_script_does_not_break_head_concatenation(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-hf-a.js' );
		$b = $this->make_content_js( 'po-hf-b.js' );

		$scripts->add( 'a', $a, [], null, false );
		$scripts->add( 'ext', 'https://cdn.example.invalid/ext.js', [], null, true );
		$scripts->add_data( 'ext', 'group', 1 ); // Manually set "footer"
		$scripts->add( 'b', $b, [], null, false );

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'ext' );
		$scripts->enqueue( 'b' );

		$head_groups = $this->render_head_groups( $scripts );
		$this->assertSame( [ [ 'a', 'b' ] ], $head_groups );

		$footer_groups = $this->render_footer_groups( $scripts );
		$this->assertSame( [ [ 'ext' ] ], $footer_groups );
	}
}
