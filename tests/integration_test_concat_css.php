<?php

// TODO: Test that concat exclusions are actually excluded
// TODO: Test how concat works with scripts with attached inline script

class Test_Css_Concat_Output extends PHPUnit\Framework\TestCase {
	function setUp() {
		// Disable warnings due to improperly formed content
		libxml_use_internal_errors(true);
	}

	function test_concat_default_output() {
		$this->run_test_to_assert_concat_order( 'http://127.0.0.1/' );
	}

	function test_concat_output_with_concat_exclusions() {
		$this->run_test_to_assert_concat_order( 'http://127.0.0.1/?exclude_from_css_concat=test-1,test-4' );
	}

	function run_test_to_assert_concat_order( $page_url ) {
		$response = wp_remote_request( $page_url );
		$this->assertFalse( is_wp_error( $response ), 'Request for WP default page failed' );

		$dom_document = new DOMDocument;
		$this->assertTrue( $dom_document->loadHTML( $response['body'] ) );

		$expected_style_handles = $this->get_expected_style_handles( $dom_document );
		$actual_style_handles = $this->get_actual_style_handles( $dom_document );
		$this->assertEquals( $expected_style_handles, $actual_style_handles );
	}

	function get_expected_style_handles( $dom_document ) {
		$done_items_node = $dom_document->getElementById( 'page-optimize-style-items' );
		$this->assertNotNull( $done_items_node, 'Cannot find expected styles' );

		$done_items_json = html_entity_decode( $done_items_node->textContent );
		$done_items = json_decode( $done_items_json );

		return array_merge( ...$done_items->head, ...$done_items->footer );
	}

	function get_actual_style_handles( $dom_document ) {
		$link_elements = $dom_document->getElementsByTagName( 'link' );
		$this->assertNotNull( $link_elements, 'Failed to query for link tags' );

		$style_links = array();
		for ( $i = 0; $i < $link_elements->length; $i++ ) {
			$dom_node = $link_elements->item( $i );
			$rel_attr = $dom_node->attributes->getNamedItem( 'rel' );
			if ( 'stylesheet' === $rel_attr->value ) {
				$style_links[] = $dom_node;
			}
		}

		$style_handles = array();
		foreach ( $style_links as $link ) {
			// Link tags for concatenated styles list their handles in a data-handles attribute
			$data_handles_attr = $link->attributes->getNamedItem( 'data-handles' );
			if ( ! empty( $data_handles_attr ) ) {
				array_push( $style_handles, ...explode( ',', $data_handles_attr->value ) );
				continue;
			}

			// Link tags for individual, unconcatenated styles have an id that includes the style handle
			$id_attr = $link->attributes->getNamedItem( 'id' );
			$id_match = array();
			if (
				! empty( $id_attr ) &&
				1 === preg_match( '/^(?<handle>.*)-css$/', $id_attr->value, $id_match )
			) {
				$style_handles[] = $id_match['handle'];
				continue;
			}
		}

		return $style_handles;
	}
}
