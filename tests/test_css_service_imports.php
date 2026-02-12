<?php

if ( ! defined( 'PAGE_OPTIMIZE_SKIP_SERVICE_REQUEST' ) ) {
	define( 'PAGE_OPTIMIZE_SKIP_SERVICE_REQUEST', true );
}

require_once dirname( __DIR__ ) . '/service.php';

class Test_CSS_Service_Imports extends WP_UnitTestCase {
	private $tmp_files = [];
	private $server_backup = [];

	public function set_up(): void {
		parent::set_up();

		$this->server_backup = array_intersect_key(
			$_SERVER,
			array_fill_keys( [ 'REQUEST_METHOD', 'REQUEST_URI' ], true )
		);
	}

	public function tear_down(): void {
		foreach ( [ 'REQUEST_METHOD', 'REQUEST_URI' ] as $key ) {
			if ( array_key_exists( $key, $this->server_backup ) ) {
				$_SERVER[ $key ] = $this->server_backup[ $key ];
			} else {
				unset( $_SERVER[ $key ] );
			}
		}

		foreach ( $this->tmp_files as $file ) {
			@unlink( $file );
		}
		$this->tmp_files = [];

		parent::tear_down();
	}

	private function make_content_css( string $filename, string $contents ): void {
		$fs_path = trailingslashit( WP_CONTENT_DIR ) . $filename;
		$result = file_put_contents( $fs_path, $contents );
		$this->assertNotFalse( $result, 'Failed to write temporary CSS fixture.' );
		$this->tmp_files[] = $fs_path;
	}

	/**
	 * The concat service should preserve @import rules in its output.
	 */
	public function test_service_preserves_import_rules(): void {
		$this->make_content_css( 'po-service-import-a.css', '.po-test{color:red;}' );
		$this->make_content_css( 'po-service-import-dep.css', '.po-test{color:blue;}' );
		$this->make_content_css( 'po-service-import-b.css', '@import "po-service-import-dep.css";' );

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-import-a.css';
		$uri_b = $content_path . 'po-service-import-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$this->assertStringContainsString( '@import', $content, 'Expected @import to be preserved in concatenated output.' );
		$this->assertStringContainsString( 'po-service-import-dep.css', $content, 'Expected @import to reference dependency.' );
	}

	/**
	 * The concat service should not split long @import URLs that contain semicolons.
	 */
	public function test_service_preserves_long_google_fonts_import_with_semicolons(): void {
		$this->make_content_css( 'po-service-import-long-a.css', '.po-import-long-a{color:red;}' );
		$this->make_content_css(
			'po-service-import-long-b.css',
			"@import url('https://fonts.googleapis.com/css2?family=Abel&family=Acme&family=Anonymous+Pro:ital,wght@0,400;0,700;1,400;1,700&family=Archivo+Narrow:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&display=swap');\n" .
			'.po-import-long-b{color:blue;}'
		);

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-import-long-a.css';
		$uri_b = $content_path . 'po-service-import-long-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$this->assertStringContainsString(
			"@import url('https://fonts.googleapis.com/css2?family=Abel&family=Acme&family=Anonymous+Pro:ital,wght@0,400;0,700;1,400;1,700&family=Archivo+Narrow:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&display=swap');",
			$content,
			'Expected long Google Fonts @import to remain intact.'
		);
		$this->assertStringNotContainsString(
			"wght@0,400;\n0,700;",
			$content,
			'Expected no newline split at semicolon inside Google Fonts URL.'
		);
	}

	/**
	 * The concat service should not treat "@import" substrings inside URL paths as at-rules.
	 */
	public function test_service_does_not_false_positive_import_substring_in_url_path(): void {
		$this->make_content_css( 'po-service-import-substring-a.css', '.po-import-substring-a{color:red;}' );
		$this->make_content_css(
			'po-service-import-substring-b.css',
			'.po-import-substring-b{background:url(/images/@import.png) no-repeat center;color:blue;}'
		);

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-import-substring-a.css';
		$uri_b = $content_path . 'po-service-import-substring-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$this->assertStringContainsString(
			'.po-import-substring-b{background:url(/images/@import.png) no-repeat center;color:blue;}',
			$content,
			'Expected declaration containing @import substring in URL path to remain unchanged.'
		);
		$this->assertNotSame(
			0,
			stripos( ltrim( $content ), '@import.png' ),
			'Expected @import substring from URL path not to be hoisted as an @import rule.'
		);
	}

	/**
	 * The concat service should still detect minified @import rules without whitespace.
	 */
	public function test_service_hoists_import_without_whitespace_after_keyword(): void {
		$this->make_content_css( 'po-service-import-nospace-a.css', '.po-import-nospace-a{color:red;}' );
		$this->make_content_css( 'po-service-import-nospace-dep.css', '.po-import-nospace-dep{color:blue;}' );
		$this->make_content_css(
			'po-service-import-nospace-b.css',
			'@import"po-service-import-nospace-dep.css";.po-import-nospace-b{color:blue;}'
		);

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-import-nospace-a.css';
		$uri_b = $content_path . 'po-service-import-nospace-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$expected_dep = '@import"' . $content_path . 'po-service-import-nospace-dep.css";';
		$this->assertStringContainsString(
			$expected_dep,
			$content,
			'Expected minified @import without whitespace to be detected and rewritten.'
		);
		$this->assertStringContainsString( '.po-import-nospace-b{color:blue;}', $content, 'Expected stylesheet body to remain present.' );
	}

	/**
	 * The concat service should support comments between @import and the path token.
	 */
	public function test_service_hoists_import_with_comment_after_keyword(): void {
		$this->make_content_css( 'po-service-import-comment-a.css', '.po-import-comment-a{color:red;}' );
		$this->make_content_css( 'po-service-import-comment-dep.css', '.po-import-comment-dep{color:blue;}' );
		$this->make_content_css(
			'po-service-import-comment-b.css',
			'@import/*keep*/"po-service-import-comment-dep.css";.po-import-comment-b{color:blue;}'
		);

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-import-comment-a.css';
		$uri_b = $content_path . 'po-service-import-comment-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$expected_dep = '@import/*keep*/"' . $content_path . 'po-service-import-comment-dep.css";';
		$this->assertStringContainsString(
			$expected_dep,
			$content,
			'Expected commented @import token to be hoisted and rewritten.'
		);
		$this->assertStringContainsString( '.po-import-comment-b{color:blue;}', $content, 'Expected stylesheet body to remain present.' );
	}

	/**
	 * The concat service should ignore @import-like tokens inside declaration blocks.
	 */
	public function test_service_ignores_import_keyword_inside_rule_body(): void {
		$this->make_content_css( 'po-service-import-body-a.css', '.po-import-body-a{color:red;}' );
		$this->make_content_css(
			'po-service-import-body-b.css',
			'.po-import-body-b{--po-token:@import "po-fake.css";color:blue;}'
		);

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-import-body-a.css';
		$uri_b = $content_path . 'po-service-import-body-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$this->assertStringContainsString(
			'.po-import-body-b{--po-token:@import "po-fake.css";color:blue;}',
			$content,
			'Expected @import-like token inside declaration body to remain untouched.'
		);
		$this->assertNotSame(
			0,
			stripos( ltrim( $content ), '@import "po-fake.css";' ),
			'Expected declaration-body token not to be hoisted as top-level @import.'
		);
	}

	/**
	 * The concat service should hoist @charset and remove it from the body output.
	 */
	public function test_service_hoists_charset_to_top(): void {
		$this->make_content_css(
			'po-service-charset-a.css',
			'@charset "UTF-8";' . "\n" . '.po-charset{color:red;}'
		);
		$this->make_content_css( 'po-service-charset-b.css', '.po-charset-b{color:blue;}' );

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-charset-a.css';
		$uri_b = $content_path . 'po-service-charset-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$this->assertSame( 0, strpos( $content, '@charset' ), 'Expected @charset to be the first rule in output.' );
		$this->assertSame( 1, substr_count( $content, '@charset' ), 'Expected @charset to appear only once in output.' );
	}

	/**
	 * The concat service should treat @charset detection as case-insensitive.
	 */
	public function test_service_hoists_charset_case_insensitive(): void {
		$this->make_content_css( 'po-service-charset-ci-a.css', '.po-charset-ci-a{color:red;}' );
		$this->make_content_css(
			'po-service-charset-ci-b.css',
			'@CHARSET "UTF-8";' . "\n" . '.po-charset-ci-b{color:blue;}'
		);

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-charset-ci-a.css';
		$uri_b = $content_path . 'po-service-charset-ci-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$this->assertSame( 0, stripos( $content, '@charset' ), 'Expected @charset to be hoisted even when uppercase.' );
		$this->assertSame( 1, substr_count( strtolower( $content ), '@charset' ), 'Expected @charset to appear only once in output.' );
	}

	/**
	 * The concat service should treat @import detection as case-insensitive.
	 */
	public function test_service_hoists_import_case_insensitive(): void {
		$this->make_content_css( 'po-service-import-ci-a.css', '.po-import-ci-a{color:red;}' );
		$this->make_content_css( 'po-service-import-ci-dep.css', '.po-import-ci-dep{color:blue;}' );
		$this->make_content_css(
			'po-service-import-ci-b.css',
			'@IMPORT "po-service-import-ci-dep.css";' . "\n" . '.po-import-ci-b{color:blue;}'
		);

		$content_path = parse_url( trailingslashit( WP_CONTENT_URL ), PHP_URL_PATH );
		if ( empty( $content_path ) ) {
			$content_path = '/wp-content/';
		}
		$content_path = trailingslashit( $content_path );

		$uri_a = $content_path . 'po-service-import-ci-a.css';
		$uri_b = $content_path . 'po-service-import-ci-b.css';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = "/_static/??{$uri_a},{$uri_b}?m=1";

		$output = page_optimize_build_output();
		$this->assertArrayHasKey( 'content', $output, 'Expected build output to include content.' );

		$content = $output['content'];
		$pos_import = stripos( $content, '@import' );
		$pos_rule = strpos( $content, '.po-import-ci-a' );

		$this->assertNotFalse( $pos_import, 'Expected @import to be preserved in output.' );
		$this->assertNotFalse( $pos_rule, 'Expected baseline rule to be present in output.' );
		$this->assertLessThan( $pos_rule, $pos_import, 'Expected @import to be hoisted above earlier rules even when uppercase.' );
	}
}
