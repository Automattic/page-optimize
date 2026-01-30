<?php

abstract class JS_Concat_Test_Case extends WP_UnitTestCase {
	protected $tmp_files = [];
	protected $did_items = null;

	public function set_up(): void {
		parent::set_up();
		require_once dirname( __DIR__ ) . '/concat-js.php';

		add_action( 'js_concat_did_items', [ $this, 'capture_did_items' ], 10, 1 );
	}

	public function tear_down(): void {
		remove_action( 'js_concat_did_items', [ $this, 'capture_did_items' ], 10 );

		foreach ( $this->tmp_files as $file ) {
			@unlink( $file );
		}
		$this->tmp_files = [];

		parent::tear_down();
	}

	public function capture_did_items( $items ): void {
		$this->did_items = $items;
	}

	protected function make_content_js( string $filename, string $contents = 'console.log("test");' ): string {
		$fs = trailingslashit( WP_CONTENT_DIR ) . $filename;
		$result = file_put_contents( $fs, $contents );
		$this->assertNotFalse( $result, 'Failed to write temporary JS fixture.' );
		$this->tmp_files[] = $fs;

		return content_url( $filename );
	}

	protected function new_concat_scripts(): Page_Optimize_JS_Concat {
		$core           = new WP_Scripts();
		$core->base_url = untrailingslashit( site_url() );

		$concat = new Page_Optimize_JS_Concat( $core );
		$concat->allow_gzip_compression = false;

		return $concat;
	}

	protected function render( WP_Scripts $scripts, $group = false ): string {
		$this->did_items = null;
		ob_start();
		$scripts->do_items( false, $group );
		return (string) ob_get_clean();
	}

	protected function render_head( WP_Scripts $scripts ): string {
		$this->did_items = null;
		ob_start();
		$scripts->do_items( false, 0 ); // head pass
		return (string) ob_get_clean();
	}

	protected function render_footer( WP_Scripts $scripts ): string {
		$this->did_items = null;
		ob_start();

		// This matches core behavior: footer pass processes only what head collected.
		if ( method_exists( $scripts, 'do_footer_items' ) ) {
			$scripts->do_footer_items();
		} else {
			$scripts->do_items( $scripts->in_footer, 1 );
		}

		return (string) ob_get_clean();
	}

	protected function render_head_groups( WP_Scripts $scripts ): array {
		$h = $this->render_head( $scripts );
		return $this->extract_handle_groups_from_did_items();
	}

	protected function render_footer_groups( WP_Scripts $scripts ): array {
		$this->render_footer( $scripts );
		return $this->extract_handle_groups_from_did_items();
	}

	protected function extract_handle_groups_from_did_items(): array {
		$this->assertNotNull( $this->did_items, 'Expected js_concat_did_items to fire and capture items.' );

		$groups = [];
		foreach ( $this->did_items as $item ) {
			if ( ( $item['type'] ?? null ) === 'concat' ) {
				$groups[] = $item['handles'] ?? [];
			} elseif ( ( $item['type'] ?? null ) === 'do_item' ) {
				$groups[] = [ $item['handle'] ];
			}
		}
		return $groups;
	}

	protected function flatten_groups( array $groups ): array {
		$out = [];
		foreach ( $groups as $g ) {
			foreach ( $g as $h ) {
				$out[] = $h;
			}
		}
		return $out;
	}
}

