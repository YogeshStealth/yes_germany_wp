<?php
/**
 * Plugin Name: YG GTM oaiq Cleanup (temporary)
 * Description: Strips a stray, unwrapped JavaScript call that Google Tag Manager injects as literal visible text at the foot of every page - a Custom HTML tag missing its <script> wrapper (see the yg-gtm-oaiq-leak memory note). This is a visible-symptom patch, not the fix: the tag itself still needs correcting inside GTM container GTM-PM6GKCL. Delete this file once that is done.
 * Version:     1.0.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove the leaked text node, once GTM has had a chance to inject it.
 *
 * The leak is client-side (GTM writes it into the DOM after the page loads),
 * so there is nothing to filter or escape server-side - printing anything here
 * would run before GTM's own script does. Instead this runs late in the
 * footer and cleans up after the fact: it finds any direct text-node child of
 * <body> that starts with "oaiq(" - the exact, narrow shape of the broken
 * call - and empties it. Nothing else on the page is touched; a real oaiq(...)
 * call that is correctly inside a <script> tag is a script's textContent, not
 * a text node of <body>, so it is never matched.
 */
add_action(
	'wp_footer',
	function () {
		if ( is_admin() ) {
			return;
		}
		?>
		<script id="yg-gtm-oaiq-cleanup">
		( function () {
			function strip() {
				var body = document.body;
				if ( ! body ) { return; }
				for ( var node = body.firstChild; node; node = node.nextSibling ) {
					if ( node.nodeType === 3 && /^\s*oaiq\s*\(/.test( node.textContent ) ) {
						node.textContent = '';
					}
				}
			}
			// GTM can inject after this script runs, so check now and again
			// shortly after load rather than once.
			strip();
			document.addEventListener( 'DOMContentLoaded', strip );
			window.addEventListener( 'load', function () {
				strip();
				setTimeout( strip, 1500 );
			} );
		} )();
		</script>
		<?php
	},
	999
);
