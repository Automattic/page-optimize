<?php

// TODO: Test that concat exclusions are actually excluded
// TODO: Test how concat works with scripts with attached inline script

class Test_Js_Concat_Output extends PHPUnit\Framework\TestCase {
	function setUp() {
		// Disable warnings due to improperly formed content
		libxml_use_internal_errors(true);
	}

	function test_concat_order_with_defaults() {
		$this->run_test_to_assert_concat_order( 'http://127.0.0.1/' );
	}

	function test_concat_order_with_concat_exclusions() {
		$this->run_test_to_assert_concat_order( 'http://127.0.0.1/?exclude_from_js_concat=test-1' );
	}

	function run_test_to_assert_concat_order( $page_url ) {
		$response = wp_remote_request( $page_url );
		$this->assertFalse( is_wp_error( $response ), 'Request for WP default page failed' );

		$dom_document = new DOMDocument;
		$this->assertTrue( $dom_document->loadHTML( $response['body'] ) );

		$expected_script_handles = $this->get_expected_script_handles( $dom_document );
		$actual_script_handles = $this->get_actual_script_handles( $dom_document );
		$this->assertEquals( $expected_script_handles, $actual_script_handles );
	}

	function get_expected_script_handles( $dom_document ) {
		$done_items_node = $dom_document->getElementById( 'page-optimize-script-items' );
		$this->assertNotNull( $done_items_node, 'Cannot find expected scripts' );

		$done_items_json = html_entity_decode( $done_items_node->textContent );
		$done_items = json_decode( $done_items_json );

		$all_handle_info = array_merge( ...$done_items->head, ...$done_items->footer );

		// Handles without source may have inline script,
		// but the script itself will not be reflected in the DOM
		$handles_with_source = array();
		foreach ( $all_handle_info as $handle_info ) {
			if ( ! empty( $handle_info->source ) ) {
				$handles_with_source[] = $handle_info->handle;
			}
		}

		return $handles_with_source;
	}

	function get_actual_script_handles( $dom_document ) {
		$script_elements = $dom_document->getElementsByTagName( 'script' );
		$this->assertNotNull( $script_elements, 'Failed to query for script tags' );

		$script_handles = array();
		foreach ( $script_elements as $script ) {
			// Script tags for concatenated scripts list their handles in a data-handles attribute
			$data_handles_attr = $script->attributes->getNamedItem( 'data-handles' );
			if ( ! empty( $data_handles_attr ) ) {
				array_push( $script_handles, ...explode( ',', $data_handles_attr->value ) );
				continue;
			}

			// Script tags for individual, unconcatenated scripts have an id that includes the script handle
			$id_attr = $script->attributes->getNamedItem( 'id' );
			$id_match = array();
			if (
				! empty( $id_attr ) &&
				1 === preg_match( '/^(?<handle>.*)-js$/', $id_attr->value, $id_match )
			) {
				$script_handles[] = $id_match['handle'];
				continue;
			}
		}

		return $script_handles;
	}
}
