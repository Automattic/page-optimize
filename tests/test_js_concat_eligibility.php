<?php

require_once __DIR__ . '/class-js-concat-test-case.php';

/**
 * Tests for JavaScript concatenation eligibility and boundary conditions.
 *
 * These tests verify that the Page_Optimize_JS_Concat class correctly determines
 * which scripts can be concatenated together and properly handles cases where
 * concatenation must be disabled or split.
 */
class Test_JS_Concat_Eligibility extends JS_Concat_Test_Case {

	/**
	 * Verifies the basic success case: two local scripts should be combined
	 * into a single concatenated <script> tag.
	 *
	 * Also verifies the concatenated URL uses the /_static/?? combo endpoint
	 * (the server-side mechanism that serves multiple files in one request).
	 */
	public function test_concats_two_internal_scripts_into_one_group(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-a.js' );
		$b = $this->make_content_js( 'po-b.js' );

		$scripts->add( 'a', $a, [], null, false );
		$scripts->add( 'b', $b, [], null, false );

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'b' );

		$html   = $this->render( $scripts );
		$groups = $this->extract_handle_groups_from_did_items();

		$this->assertSame( [ [ 'a', 'b' ] ], $groups );
		$this->assertStringContainsString( '/_static/??', $html );
	}

	/**
	 * When an external/CDN script appears between two local scripts, the
	 * concatenation must be split to preserve execution order.
	 *
	 * Enqueue order: a (local) -> b (external CDN) -> c (local)
	 * Expected output: [a], [b], [c]  (three separate <script> tags)
	 *
	 * This also detects accidental "pull all concat-eligible into one bucket"
	 * behavior-if a and c were grouped together, b would execute after both.
	 */
	public function test_external_script_breaks_concat_run_and_preserves_order(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-a2.js' );
		$c = $this->make_content_js( 'po-c2.js' );

		$scripts->add( 'a', $a, [], null, false );
		$scripts->add( 'b', 'https://cdn.example.invalid/b.js', [], null, false );
		$scripts->add( 'c', $c, [], null, false );

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'b' );
		$scripts->enqueue( 'c' );

		$this->render( $scripts );
		$groups = $this->extract_handle_groups_from_did_items();

		$this->assertSame( [ [ 'a' ], [ 'b' ], [ 'c' ] ], $groups );
	}

	/**
	 * When a script has inline JavaScript added via add_inline_script(), it
	 * cannot be concatenated with subsequent scripts.
	 *
	 * WordPress's add_inline_script() injects code immediately before/after
	 * the <script> tag. If scripts were concatenated, the inline code would
	 * execute at the wrong time relative to other scripts.
	 */
	public function test_inline_script_breaks_concat_boundary(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-ia.js' );
		$b = $this->make_content_js( 'po-ib.js' );

		$scripts->add( 'a', $a, [], null, false );
		$scripts->add( 'b', $b, [], null, false );

		$scripts->add_inline_script( 'a', 'window.__po_inline_a = true;', 'after' );

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'b' );

		$this->render( $scripts );
		$groups = $this->extract_handle_groups_from_did_items();

		$this->assertSame( [ [ 'a' ], [ 'b' ] ], $groups );
	}

	/**
	 * Files starting with "use strict"; cannot be safely concatenated.
	 *
	 * JavaScript's strict mode directive only applies to the file/function scope
	 * it appears in. When scripts are concatenated, a "use strict" at the top of
	 * one file would incorrectly apply strict mode to all preceding concatenated
	 * code, potentially breaking scripts that rely on non-strict behavior.
	 *
	 * This is a classic "looks safe but isn't" rule that's easy to regress.
	 */
	public function test_strict_mode_file_is_not_concatenated(): void {
		$scripts = $this->new_concat_scripts();

		$a = $this->make_content_js( 'po-sa.js', "console.log('a');" );
		$b = $this->make_content_js( 'po-sb.js', "'use strict';\nconsole.log('b');" );
		$c = $this->make_content_js( 'po-sc.js', "console.log('c');" );

		$scripts->add( 'a', $a, [], null, false );
		$scripts->add( 'b', $b, [], null, false );
		$scripts->add( 'c', $c, [], null, false );

		$scripts->enqueue( 'a' );
		$scripts->enqueue( 'b' );
		$scripts->enqueue( 'c' );

		$this->render( $scripts );
		$groups = $this->extract_handle_groups_from_did_items();

		$this->assertSame( [ [ 'a' ], [ 'b' ], [ 'c' ] ], $groups );
	}

	/**
	* Handles in the exclusion list are not concatenated, even if they
	* point to local files that would otherwise be eligible.
	*/
	public function test_exclusion_list_prevents_concatenation_of_handle(): void {
		$old = get_option( 'page_optimize-js-exclude', null );
		update_option( 'page_optimize-js-exclude', 'a' );

		try {
			$scripts = $this->new_concat_scripts();

			$a = $this->make_content_js( 'po-ex-a.js' );
			$b = $this->make_content_js( 'po-ex-b.js' );

			$scripts->add( 'a', $a, [], null, false );
			$scripts->add( 'b', $b, [], null, false );

			$scripts->enqueue( 'a' );
			$scripts->enqueue( 'b' );

			$this->render( $scripts );
			$groups = $this->extract_handle_groups_from_did_items();

			$this->assertSame( [ [ 'a' ], [ 'b' ] ], $groups );
		} finally {
			if ( null === $old ) {
				delete_option( 'page_optimize-js-exclude' );
			} else {
				update_option( 'page_optimize-js-exclude', $old );
			}
		}
	}

	/**
	* When the page_optimize-load-mode option is set to 'async', concatenated
	* script tags should include the async attribute.
	*
	* Async/defer loading changes script execution timing, which can cause
	* subtle breakage if the attribute is missing or incorrectly applied.
	**/
	public function test_load_mode_async_is_added_to_concat_script_tag(): void {
		$old = get_option( 'page_optimize-load-mode', null );
		update_option( 'page_optimize-load-mode', 'async' );

		try {
			$scripts = $this->new_concat_scripts();

			$a = $this->make_content_js( 'po-la.js' );
			$b = $this->make_content_js( 'po-lb.js' );

			$scripts->add( 'a', $a, [], null, false );
			$scripts->add( 'b', $b, [], null, false );

			$scripts->enqueue( 'a' );
			$scripts->enqueue( 'b' );

			$html = $this->render( $scripts );

			$this->assertStringContainsString( ' async', $html );
		} finally {
			if ( null === $old ) {
				delete_option( 'page_optimize-load-mode' );
			} else {
				update_option( 'page_optimize-load-mode', $old );
			}
		}
	}

	/**
	* Invalid load mode values should be sanitized to an empty string.
	*
	* This ensures the sanitizer is actually being called in the render path
	* and protects against unexpected output if the option contains garbage.
	*/
	public function test_invalid_load_mode_is_sanitized_and_not_rendered(): void {
		$old = get_option( 'page_optimize-load-mode', null );
		update_option( 'page_optimize-load-mode', 'not-a-real-mode' );

		try {
			$scripts = $this->new_concat_scripts();

			$a = $this->make_content_js( 'po-san-a.js' );
			$b = $this->make_content_js( 'po-san-b.js' );

			$scripts->add( 'a', $a, [], null, false );
			$scripts->add( 'b', $b, [], null, false );

			$scripts->enqueue( 'a' );
			$scripts->enqueue( 'b' );

			$html = $this->render( $scripts );

			$this->assertStringNotContainsString( 'not-a-real-mode', $html );
			// Also verify neither async nor defer appear (since the sanitized value is empty).
			$this->assertStringNotContainsString( ' async', $html );
			$this->assertStringNotContainsString( ' defer', $html );
		} finally {
			if ( null === $old ) {
				delete_option( 'page_optimize-load-mode' );
			} else {
				update_option( 'page_optimize-load-mode', $old );
			}
		}
	}
}
