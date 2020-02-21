<?php

class Page_Optimize_Utils {
	public static function relative_path_replace( $buf, $dirpath ) {
		// url(relative/path/to/file) -> url(/absolute/and/not/relative/path/to/file)
		$buf = preg_replace(
			'/(:?\s*url\s*\()\s*(?:\'|")?\s*([^\/\'"\s\)](?:(?<!data:|http:|https:|[\(\'"]#|%23).)*)[\'"\s]*\)/isU',
			'$1' . ( $dirpath == '/' ? '/' : $dirpath . '/' ) . '$2)',
			$buf
		);

		return $buf;
	}
}
