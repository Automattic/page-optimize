<?php

if ( ! defined( 'PAGE_OPTIMIZE_SKIP_SERVICE_REQUEST' ) ) {
	define( 'PAGE_OPTIMIZE_SKIP_SERVICE_REQUEST', true );
}

require_once dirname( __DIR__ ) . '/service.php';

/**
 * Limits on the work a single /_static/ concat request can do.
 *
 * Each request runs in a subprocess because rejections call exit.
 */
class Test_Service_Limits extends WP_UnitTestCase {
	private $root;

	public function set_up(): void {
		parent::set_up();

		$this->root = rtrim( sys_get_temp_dir(), '/\\' ) . '/po-service-limits-' . getmypid() . '-' . mt_rand();
		mkdir( $this->root . '/wp-content', 0700, true );
	}

	public function tear_down(): void {
		$this->remove_dir( $this->root );

		parent::tear_down();
	}

	private function remove_dir( string $dir ): void {
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = "$dir/$entry";
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Write a fixture under wp-content and return its URI path.
	 */
	private function make_file( string $filename, string $contents ): string {
		$dir = dirname( "{$this->root}/wp-content/$filename" );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		$this->assertNotFalse( file_put_contents( "{$this->root}/wp-content/$filename", $contents ) );

		return "/wp-content/$filename";
	}

	private function gzip_uri( string $path_str ): string {
		return '/_static/??-' . base64_encode( gzcompress( $path_str ) );
	}

	/**
	 * Run a request in a subprocess and return array( 'status' => int|null, 'body' => string|null ).
	 */
	private function request( string $request_uri, array $config = [] ): array {
		$config['root'] = $this->root;
		$config['request_uri'] = $request_uri;

		$command = escapeshellarg( PHP_BINARY ) . ' '
			. escapeshellarg( __DIR__ . '/service-request-runner.php' ) . ' '
			. escapeshellarg( json_encode( $config ) );

		$raw = shell_exec( $command );
		$result = json_decode( (string) $raw, true );
		$this->assertIsArray( $result, "Unexpected runner output: $raw" );

		return array(
			'status' => $result['status'],
			'body' => isset( $result['body'] ) ? base64_decode( $result['body'] ) : null,
		);
	}

	private function assertRejected( array $result ): void {
		$this->assertSame( 400, $result['status'] );
		$this->assertNull( $result['body'] );
	}

	public function test_max_bytes_defaults_to_8_mib(): void {
		$this->assertSame( 8 * 1024 * 1024, PAGE_OPTIMIZE_CONCAT_MAX_BYTES );
	}

	public function test_two_distinct_files_concatenate_plain_and_gzip(): void {
		$a = $this->make_file( 'a.css', '.a{color:red}' );
		$b = $this->make_file( 'b.css', '.b{color:blue}' );

		$plain = $this->request( "/_static/??$a,$b?m=1" );
		$this->assertSame( 200, $plain['status'] );
		$this->assertSame( '.a{color:red}.b{color:blue}', $plain['body'] );

		$gzip = $this->request( $this->gzip_uri( "$a,$b?m=1" ) );
		$this->assertSame( 200, $gzip['status'] );
		$this->assertSame( $plain['body'], $gzip['body'] );
	}

	public function test_same_js_file_twice_is_allowed(): void {
		$a = $this->make_file( 'a.js', 'var a = 1' );
		$b = $this->make_file( 'b.js', 'var b = 2' );

		$result = $this->request( "/_static/??$a,$b,$a?m=1" );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( "var a = 1;\nvar b = 2;\nvar a = 1;\n", $result['body'] );
	}

	public function test_same_css_file_twice_is_allowed(): void {
		$a = $this->make_file( 'a.css', '.a{color:red}' );

		$result = $this->request( "/_static/??$a,$a?m=1" );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '.a{color:red}.a{color:red}', $result['body'] );
	}

	public function test_same_file_three_times_is_rejected(): void {
		$a = $this->make_file( 'a.css', '.a{color:red}' );

		$this->assertRejected( $this->request( "/_static/??$a,$a,$a?m=1" ) );
		$this->assertRejected( $this->request( $this->gzip_uri( "$a,$a,$a?m=1" ) ) );
	}

	public function test_same_file_three_times_is_rejected_with_repeated_slashes(): void {
		$this->make_file( 'a.css', '.a{color:red}' );

		// The production path mapper doesn't normalize these, so they are three different path strings.
		$this->assertRejected( $this->request( '/_static/??/wp-content/a.css,/wp-content//a.css,/wp-content///a.css?m=1' ) );
	}

	public function test_same_file_three_times_is_rejected_through_a_symlink(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() is not available.' );
		}

		$a = $this->make_file( 'a.css', '.a{color:red}' );
		$this->assertTrue( symlink( "{$this->root}/wp-content/a.css", "{$this->root}/wp-content/link.css" ) );

		$result = $this->request( "/_static/??$a,$a,/wp-content/link.css?m=1", [ 'use_base_dir' => true ] );
		$this->assertRejected( $result );
	}

	public function test_files_over_the_byte_cap_are_rejected(): void {
		$a = $this->make_file( 'a.css', str_repeat( 'a', 100 ) );
		$b = $this->make_file( 'b.css', str_repeat( 'b', 100 ) );
		$c = $this->make_file( 'c.css', str_repeat( 'c', 100 ) );

		$this->assertRejected( $this->request( "/_static/??$a,$b,$c?m=1", [ 'max_bytes' => 250 ] ) );

		$result = $this->request( "/_static/??$a,$b?m=1", [ 'max_bytes' => 250 ] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( str_repeat( 'a', 100 ) . str_repeat( 'b', 100 ), $result['body'] );

		// Exactly at the cap is allowed.
		$result = $this->request( "/_static/??$a,$b?m=1", [ 'max_bytes' => 200 ] );
		$this->assertSame( 200, $result['status'] );
	}

	public function test_single_file_over_the_byte_cap_is_rejected(): void {
		$small = $this->make_file( 'small.css', '.s{}' );
		$big = $this->make_file( 'big.css', str_repeat( 'b', 300 ) );

		$this->assertRejected( $this->request( "/_static/??$small,$big?m=1", [ 'max_bytes' => 250 ] ) );
	}

	public function test_css_output_over_the_byte_cap_is_rejected(): void {
		// 100 bytes on disk; rewriting each url() adds "/wp-content/sub/" (16 bytes), for 164 bytes of output.
		$a = $this->make_file( 'sub/a.css', str_repeat( '.x{background:url(i.png)}', 4 ) );
		$b = $this->make_file( 'b.css', '' );

		$this->assertRejected( $this->request( "/_static/??$a,$b?m=1", [ 'max_bytes' => 163 ] ) );

		$result = $this->request( "/_static/??$a,$b?m=1", [ 'max_bytes' => 164 ] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( str_repeat( '.x{background:url(/wp-content/sub/i.png)}', 4 ), $result['body'] );
	}

	public function test_js_separators_count_toward_the_byte_cap(): void {
		$a = $this->make_file( 'a.js', str_repeat( 'a', 100 ) );
		$b = $this->make_file( 'b.js', str_repeat( 'b', 100 ) );

		// 200 bytes on disk, 204 bytes of output with the ";\n" after each file.
		$this->assertRejected( $this->request( "/_static/??$a,$b?m=1", [ 'max_bytes' => 203 ] ) );

		$result = $this->request( "/_static/??$a,$b?m=1", [ 'max_bytes' => 204 ] );
		$this->assertSame( 200, $result['status'] );
	}

	public function test_repeated_slashes_are_normalized_in_rewritten_urls(): void {
		$a = $this->make_file( 'sub/a.css', '.x{background:url(i.png)}@import "dep.css";' );
		$b = $this->make_file( 'b.css', '.b{}' );

		$expected = $this->request( "/_static/??$a,$b?m=1" );
		$this->assertSame( 200, $expected['status'] );
		$this->assertStringContainsString( 'url(/wp-content/sub/i.png)', $expected['body'] );
		$this->assertStringContainsString( '"/wp-content/sub/dep.css"', $expected['body'] );

		// Stay under macOS's 1024-byte PATH_MAX so the file still resolves when tests run locally.
		$slashed = '/wp-content' . str_repeat( '/', 500 ) . 'sub/a.css';
		$result = $this->request( $this->gzip_uri( "$slashed,$b?m=1" ) );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( $expected['body'], $result['body'] );
	}

	public function test_empty_file_is_allowed(): void {
		$a = $this->make_file( 'a.css', '.a{}' );
		$empty = $this->make_file( 'empty.css', '' );

		$result = $this->request( "/_static/??$a,$empty?m=1", [ 'max_bytes' => 4 ] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '.a{}', $result['body'] );
	}

	public function test_gzip_list_expanding_past_256_kib_is_rejected(): void {
		$a = $this->make_file( 'a.css', '.a{}' );
		$b = $this->make_file( 'b.css', '.b{}' );

		// The version string is stripped before paths are read, so without the gzip limit this would succeed.
		$path_str = "$a,$b?m=" . str_repeat( '1', 300 * 1024 );
		$this->assertRejected( $this->request( $this->gzip_uri( $path_str ) ) );
	}

	public function test_file_count_limit(): void {
		$paths = [];
		for ( $i = 0; $i < 301; $i++ ) {
			$paths[] = $this->make_file( "f$i.js", "f$i()" );
		}

		$result = $this->request( $this->gzip_uri( implode( ',', array_slice( $paths, 0, 300 ) ) . '?m=1' ) );
		$this->assertSame( 200, $result['status'] );
		$this->assertStringEndsWith( "f299();\n", $result['body'] );

		$this->assertRejected( $this->request( $this->gzip_uri( implode( ',', $paths ) . '?m=1' ) ) );
	}
}
