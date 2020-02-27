=== page-optimize ===
Contributors: aidvu, bpayton
Tags: performance
Requires at least: 5.3
Tested up to: 5.3
Requires PHP: 7.2
Stable tag: 0.4.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Optimize pages for faster load and render in the browser.

== Description ==

This plugin supports a few features that may improve the performance of page loading and rendering in the browser:

* Concatenate CSS
* Concatenate JavaScript
* Execution timing of non-critical scripts

Notes:
* Changing script execution timing can be risky and will not work well for all sites.

== Installation ==

This plugin uses sensible defaults so it can operate without configuration, but there are a number of constants you may use for a custom configuration.

= PAGE_OPTIMIZE_CACHE_DIR =

Page Optimize caches concatenated scripts and styles by default, and this constant controls where the cache files are stored. The default directory is `cache/page_optimize` under your site's `wp-content` folder.

To change the cache location, set this constant to the absolute filesystem path of that location.

To disable caching, set this constant to `false`. Please note that disabling Page Optimize caching may negatively impact performance unless you are caching elsewhere.

== Testing ==

To test features without enabling them for the entire site, you may append query params to a WordPress post or page URL. For example, to test enabling JavaScript concatenation for `https://example.com/blog/`, you can use the URL `https://example.com/blog/?concat-js=1`.

Supported query params:

* `concat-css` controls CSS concatenation. Values: `1` for ON and `0` for OFF.
* `concat-js` controls JavaScript concatenation. Values: `1` for ON and `0` for OFF.
* `load-mode-js` controls how non-critical JavaScript are loaded. Values: 'defer' for [deferred](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script#attr-defer), 'async' for [async loading](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script#attr-async), any other value indicates the feature should be disabled.

== Changelog ==

Initial release. No changes yet. :)
