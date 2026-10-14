<?php
/**
 * Plugin Name: YG Fix: one closing body tag per page
 * Description: About 155 published pages and posts carry a whole pasted HTML document in
 *              their content, so the rendered page has two or three closing body tags.
 *              LiteSpeed Cache (optimize.cls.php, finalize()) puts its footer scripts in
 *              front of every one of them with a plain str_replace. With "Load JS Delayed"
 *              on, that is two or three copies of the delayed-JS loader. Each copy
 *              redefines litespeed_load_delayed_js_force, so the first copy can never
 *              remove its own listeners: every scroll, tap and mouse move reloads the
 *              delayed scripts and re-fires DOMContentLiteSpeedLoaded, Contact Form 7
 *              re-initialises and calls its refill endpoint each time. One visitor made
 *              1,756 such calls in 16 minutes on 2026-09-30 and the host answered 508.
 *
 *              This drops every closing body tag except the last one before LiteSpeed
 *              optimises the page, so its footer scripts go in exactly once. Browsers
 *              already ignore a closing body tag in the middle of a page, so nothing
 *              changes on screen. If this file is removed, pages render as before.
 * Version:     1.0.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'litespeed_buffer_before',
	function ( $buffer ) {
		if ( ! is_string( $buffer ) || substr_count( strtolower( $buffer ), '</body>' ) < 2 ) {
			return $buffer;
		}

		$last = strripos( $buffer, '</body>' );
		$head = str_ireplace( '</body>', '', substr( $buffer, 0, $last ) );

		return $head . substr( $buffer, $last );
	}
);
