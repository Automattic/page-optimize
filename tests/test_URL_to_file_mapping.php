<?php

require_once __DIR__ . '/../dependency-path-mapping.php';

class Test_URL_To_File_Mapping extends PHPUnit\Framework\TestCase {
	// TODO: URL without host is based on ABSPATH
	// TODO: Separate plugin URL with site URL host. Exist/non-exist
	// TODO: Separate content URL with site URL host. Exist/non-exist
	// TODO: Site root URL. Exist/non-exist
	// TODO: Plugin URL under content URL dir but separate from site URL. Same host as site URL.
	// TODO: Plugin URL under content URL which is under site URL. Same host as site URL.
	// TODO: Content URL descended from site URL
	// TODO: Plugin URL with different host than site URL
	// TODO: Content URL with different host than site URL
	// TODO: Relative URLs
}

class Test_URI_Path_To_File_Mapping extends PHPUnit\Framework\TestCase {

	// TODO: Test URI and FS paths with and without trailing slashes

	function run_test(
		$label,
		$site_host,
		$site_uri_path,
		$site_dir,
		$content_host,
		$content_uri_path,
		$content_dir,
		$plugin_host,
		$plugin_uri_path,
		$plugin_dir
	) {
		$site_url = "{$site_host}{$site_uri_path}";
		$content_url = "{$content_host}{$content_uri_path}";
		$plugin_url = "{$plugin_host}{$plugin_uri_path}";

		$root = __DIR__ . '/data/url-to-file-mapping';
		$site_dir = "{$root}{$site_dir}";
		$content_dir = "{$root}{$content_dir}";
		$plugin_dir = "{$root}{$plugin_dir}";

		$dpm = new Page_Optimize_Dependency_Path_Mapping(
			$site_url,
			$site_dir,
			$content_url,
			$content_dir,
			$plugin_url,
			$plugin_dir
		);

		$this->assertEquals( "$site_dir/exists", $dpm->uri_path_to_fs_path( "$site_uri_path/exists" ), "$label: Cannot find file based on site URI path" );
		$this->assertFalse( $dpm->uri_path_to_fs_path( "$site_uri_path/nonexistent" ), "$label: Should have failed for nonexistent file based on site URI path" );

		$actual_content_path = $dpm->uri_path_to_fs_path( "$content_uri_path/exists" );
		if ( 0 === strpos( $content_url, $site_url ) ) {
			// Content is under site URL. We expect this path to resolve.
			$this->assertEquals( "$content_dir/exists", $actual_content_path, "$label: Cannot find file based on content URI path" );
		} else {
			// Content is not under site URL. We expect a resolution failure.
			$this->assertFalse( $actual_content_path, "$label: Should have failed for content URI path outside of site URL" );
		}
		$this->assertFalse( $dpm->uri_path_to_fs_path( "$content_uri_path/nonexistent" ), "$label: Should have failed for nonexistent file based on content URI path" );

		$actual_plugin_path = $dpm->uri_path_to_fs_path( "$plugin_uri_path/exists" );
		if ( 0 === strpos( $plugin_url, $site_url ) ) {
			// Plugins are under site URL. We expect this path to resolve.
			$this->assertEquals( "$plugin_dir/exists", $actual_plugin_path, "$label: Cannot find file based on plugin URI path" );
		} else {
			// Plugins are not under site URL. We expect a resolution failure.
			$this->assertFalse( $actual_plugin_path, "$label: Should have failed for plugin URI path outside of site URL" );
		}
		$this->assertFalse( $dpm->uri_path_to_fs_path( "$plugin_uri_path/nonexistent" ), "$label: Should have failed for nonexistent file based on plugin URI path" );
	}

	function test_nested_site_content_plugin_dirs() {
		$site_uri_path = '/subdir';
		$site_dir = '/site';
		$content_uri_path = "{$site_uri_path}/wp-content";
		$content_dir = "$site_dir/content";
		$plugin_uri_path = "{$content_uri_path}/plugins";
		$plugin_dir = "$content_dir/plugins";

		$this->run_test(
			'Nested site->content->plugin dirs',
			'https://example.com',
			$site_uri_path,
			$site_dir,
			'https://example.com',
			$content_uri_path,
			$content_dir,
			'https://example.com',
			$plugin_uri_path,
			$plugin_dir
		);
	}

	function test_completely_separate_content_and_plugin_dirs() {
		$site_uri_path = '/subdir';
		$site_dir = '/site';
		$content_uri_path = "{$site_uri_path}/wp-content";
		$content_dir = "/content";
		$plugin_uri_path = "{$content_uri_path}/plugins";
		$plugin_dir = "/plugins";

		$this->run_test(
			'Content and plugin dirs separate from ABSPATH and each other',
			'https://example.com',
			$site_uri_path,
			$site_dir,
			'https://example.com',
			$content_uri_path,
			$content_dir,
			'https://example.com',
			$plugin_uri_path,
			$plugin_dir
		);
	}

	function test_nested_content_and_plugin_dirs_separate_from_site_dir() {
		$site_uri_path = '/subdir';
		$site_dir = '/site';
		$content_uri_path = "{$site_uri_path}/wp-content";
		$content_dir = "/content";
		$plugin_uri_path = "{$content_uri_path}/plugins";
		$plugin_dir = "$content_dir/plugins";

		$this->run_test(
			'Nested content->plugin dirs, separate from ABSPATH',
			'https://example.com',
			$site_uri_path,
			$site_dir,
			'https://example.com',
			$content_uri_path,
			$content_dir,
			'https://example.com',
			$plugin_uri_path,
			$plugin_dir
		);
	}

	function test_content_and_plugin_urls_not_nested_under_site_url() {
		$site_uri_path = '/subdir';
		$site_dir = '/site';
		$content_uri_path = "/wp-content";
		$content_dir = "$site_dir/content";
		$plugin_uri_path = "/plugins";
		$plugin_dir = "$content_dir/plugins";

		$this->run_test(
			'Content and plugin URLs have same host but are not under the site URL',
			'https://example.com',
			$site_uri_path,
			$site_dir,
			'https://example.com',
			$content_uri_path,
			$content_dir,
			'https://example.com',
			$plugin_uri_path,
			$plugin_dir
		);
	}

	function test_content_and_plugin_urls_with_different_host() {
		$site_uri_path = '/subdir';
		$site_dir = '/site';
		$content_uri_path = "{$site_uri_path}/wp-content";
		$content_dir = "$site_dir/content";
		$plugin_uri_path = "{$content_uri_path}/plugins";
		$plugin_dir = "$content_dir/plugins";

		$this->run_test(
			'Content and plugin URLs have different host from site URL',
			'https://example.com',
			$site_uri_path,
			$site_dir,
			'https://example.com:1234',
			$content_uri_path,
			$content_dir,
			'https://example.com:4321',
			$plugin_uri_path,
			$plugin_dir
		);
	}
}
