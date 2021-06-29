<?php

require_once __DIR__ . '/../dependency-path-mapping.php';

class Test_Output extends PHPUnit\Framework\TestCase {
	function test_output() {
		var_dump( $_SERVER );
	}
}
