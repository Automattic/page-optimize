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
