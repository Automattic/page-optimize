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

	function run_test( $label, $site_uri_path, $site_dir, $content_uri_path, $content_dir, $plugin_uri_path, $plugin_dir ) {
		$host = 'https://example.com';
		$site_url = "{$host}{$site_uri_path}";
		$content_url = "{$host}{$content_uri_path}";
		$plugin_url = "{$host}{$plugin_uri_path}";

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
		$this->assertEquals( "$content_dir/exists", $dpm->uri_path_to_fs_path( "$content_uri_path/exists" ), "$label: Cannot find file based on content URI path" );
		$this->assertFalse( $dpm->uri_path_to_fs_path( "$content_uri_path/nonexistent" ), "$label: Should have failed for nonexistent file based on content URI path" );
		$this->assertEquals( "$plugin_dir/exists", $dpm->uri_path_to_fs_path( "$plugin_uri_path/exists" ), "$label: Cannot find file based on plugin URI path" );
		$this->assertFalse( $dpm->uri_path_to_fs_path( "$plugin_uri_path/nonexistent" ), "$label: Should have failed for nonexistent file based on plugin URI path" );
	}

	function test_nested_site_content_plugin_dirs_and_urls() {
		$site_uri_path = '/subdir';
		$site_dir = '/site';
		$content_uri_path = "{$site_uri_path}/wp-content";
		$content_dir = "$site_dir/content";
		$plugin_uri_path = "{$content_uri_path}/plugins";
		$plugin_dir = "$content_dir/plugins";

		$this->run_test(
			'Nested site->content->plugin dirs and URLs',
			$site_uri_path,
			$site_dir,
			$content_uri_path,
			$content_dir,
			$plugin_uri_path,
			$plugin_dir
		);
	}

	function test_nested_site_content_plugin_dirs_and_separate_content_plugin_url() {
		$site_uri_path = '/subdir';
		$site_dir = '/site';
		$content_uri_path = "/wp-content";
		$content_dir = "$site_dir/content";
		$plugin_uri_path = "/plugins";
		$plugin_dir = "$content_dir/plugins";

		$this->run_test(
			'Nested site->content->plugin dirs and separate content and plugin URLs',
			$site_uri_path,
			$site_dir,
			$content_uri_path,
			$content_dir,
			$plugin_uri_path,
			$plugin_dir
		);
	}

	function test_nested_site_content_plugin_urls_and_separate_content_plugin_dirs() {
		$site_uri_path = '/subdir';
		$site_dir = '/site';
		$content_uri_path = "{$site_uri_path}/wp-content";
		$content_dir = "/content";
		$plugin_uri_path = "{$content_uri_path}/plugins";
		$plugin_dir = "/plugins";

		$this->run_test(
			'Nested site->content->plugin URLs and separate content and plugin dirs',
			$site_uri_path,
			$site_dir,
			$content_uri_path,
			$content_dir,
			$plugin_uri_path,
			$plugin_dir
		);
	}

	// TODO: Separate content path when plugin URL has same host as site URL. Exist/non-exist
	// TODO: Separate content path content URL with site URL host. Exist/non-exist
	// TODO: Site root path. Exist/non-exist
	// TODO: Plugin path under content path dir but separate from site path. Same host as site URL.
	// TODO: Content path descended from site path
	// TODO: Plugin URL with different host than site URL
	// TODO: Content URL with different host than site URL
	// TODO: Relative URLs
}
