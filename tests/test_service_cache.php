<?php

/**
 * The concat service only writes cache entries for signed path lists.
 *
 * Each request runs in a subprocess so it can define its own cache directory and signing key.
 */
class Test_Service_Cache extends WP_UnitTestCase {
	const NONCE_KEY = 'test-nonce-key';

	private $root;
	private $cache_dir;

	public function set_up(): void {
		parent::set_up();

		$this->root = rtrim( sys_get_temp_dir(), '/\\' ) . '/po-service-cache-' . getmypid() . '-' . mt_rand();
		$this->cache_dir = $this->root . '/cache';
		mkdir( $this->root . '/wp-content', 0700, true );
		mkdir( $this->cache_dir, 0700 );

		file_put_contents( $this->root . '/wp-content/a.css', '.a{color:red}' );
		file_put_contents( $this->root . '/wp-content/b.css', '.b{color:blue}' );
	}

	public function tear_down(): void {
		foreach ( glob( $this->cache_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->cache_dir );
		unlink( $this->root . '/wp-content/a.css' );
		unlink( $this->root . '/wp-content/b.css' );
		rmdir( $this->root . '/wp-content' );
		rmdir( $this->root );

		parent::tear_down();
	}

	private function run_child( array $config ): array {
		$config['root'] = $this->root;
		$config += [ 'request_uri' => '/_static/' ];

		$command = escapeshellarg( PHP_BINARY ) . ' '
			. escapeshellarg( __DIR__ . '/service-request-runner.php' ) . ' '
			. escapeshellarg( json_encode( $config ) );

		$raw = shell_exec( $command );
		$result = json_decode( (string) $raw, true );
		$this->assertIsArray( $result, "Unexpected runner output: $raw" );

		return $result;
	}

	private function sign( string $path_str ): string {
		return $this->run_child( [ 'sign' => $path_str, 'nonce_key' => self::NONCE_KEY ] )['signed'];
	}

	/**
	 * Run a full service request with caching on.
	 */
	private function request( string $request_uri, array $config = [] ): array {
		$config += [ 'nonce_key' => self::NONCE_KEY ];
		$config['request_uri'] = $request_uri;
		$config['cache_dir'] = $this->cache_dir;

		$result = $this->run_child( $config );

		return array(
			'status' => $result['status'],
			'body' => isset( $result['body'] ) ? base64_decode( $result['body'] ) : null,
		);
	}

	private function gzip_uri( string $path_str ): string {
		return '/_static/??-' . base64_encode( gzcompress( $path_str ) );
	}

	private function cache_files(): array {
		return glob( $this->cache_dir . '/page-optimize-cache-[0-9a-f]*' );
	}

	public function test_signature_changes_with_the_path_list(): void {
		$signed = $this->sign( '/wp-content/a.css,/wp-content/b.css?m=1' );

		$this->assertMatchesRegularExpression( '/^\/wp-content\/a\.css,\/wp-content\/b\.css\?m=1-[0-9a-f]{20}$/', $signed );
		$this->assertNotSame( substr( $signed, -20 ), substr( $this->sign( '/wp-content/b.css,/wp-content/a.css?m=1' ), -20 ) );
		$this->assertNotSame( substr( $signed, -20 ), substr( $this->sign( '/wp-content/a.css,/wp-content/b.css?m=2' ), -20 ) );
	}

	public function test_signed_request_is_cached(): void {
		$signed = $this->sign( '/wp-content/a.css,/wp-content/b.css?m=1' );

		$result = $this->request( "/_static/??$signed" );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '.a{color:red}.b{color:blue}', $result['body'] );
		$this->assertCount( 1, $this->cache_files() );
	}

	public function test_unsigned_request_is_served_but_not_cached(): void {
		$result = $this->request( '/_static/??/wp-content/a.css,/wp-content/b.css?m=1' );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '.a{color:red}.b{color:blue}', $result['body'] );
		$this->assertCount( 0, $this->cache_files() );
	}

	public function test_request_with_a_bad_signature_is_served_but_not_cached(): void {
		$signed = $this->sign( '/wp-content/a.css,/wp-content/b.css?m=1' );
		$signature = substr( $signed, -21 );

		$requests = [
			// The signature of a different path list.
			"/_static/??/wp-content/b.css,/wp-content/a.css?m=1$signature",
			// Extra query arguments after the signature.
			"/_static/??$signed&junk=1",
		];
		foreach ( $requests as $request_uri ) {
			$result = $this->request( $request_uri );
			$this->assertSame( 200, $result['status'] );
		}

		$this->assertCount( 0, $this->cache_files() );
	}

	public function test_request_signed_with_another_key_is_not_cached(): void {
		$signed = $this->sign( '/wp-content/a.css,/wp-content/b.css?m=1' );

		$result = $this->request( "/_static/??$signed", [ 'nonce_key' => 'another-key' ] );
		$this->assertSame( 200, $result['status'] );
		$this->assertCount( 0, $this->cache_files() );
	}

	public function test_nothing_is_cached_without_a_signing_key(): void {
		$result = $this->request( '/_static/??/wp-content/a.css,/wp-content/b.css?m=1', [ 'nonce_key' => null ] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '.a{color:red}.b{color:blue}', $result['body'] );
		$this->assertCount( 0, $this->cache_files() );
	}

	public function test_spellings_of_one_signed_request_share_a_cache_entry(): void {
		$signed = $this->sign( '/wp-content/a.css,/wp-content/b.css?m=1' );

		$this->assertSame( 200, $this->request( "/_static/??$signed" )['status'] );
		$files = $this->cache_files();
		$this->assertCount( 1, $files );

		// Mark the entry so a cache hit is visible in the response body.
		file_put_contents( $files[0], 'cached' );

		$requests = [
			$this->gzip_uri( $signed ),
			"/_static/extra??$signed",
			"/_static/?junk?$signed",
		];
		foreach ( $requests as $request_uri ) {
			$result = $this->request( $request_uri );
			$this->assertSame( 200, $result['status'] );
			$this->assertSame( 'cached', $result['body'], "Expected a cache hit for $request_uri" );
		}

		$this->assertCount( 1, $this->cache_files() );
	}
}
