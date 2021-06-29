<?php

require_once __DIR__ . '/../dependency-path-mapping.php';

class Test_Output extends PHPUnit\Framework\TestCase {
	function test_concat_output() {
		var_dump( $_SERVER );
		echo "Testing HTTP request...\n";
		var_export( wp_remote_request( 'http://127.0.0.1/' ), true );
	}
}
