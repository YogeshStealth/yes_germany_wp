<?php
/**
 * Plugin Name: YG: pages without the form popup
 * Description: Adds pages to yg-form-popup-v2's "no popup" list, so the popup's
 *              script, CSS and overlay are not output there at all.
 *
 * Why this exists
 * ---------------
 * yg-form-popup-v2 lives only on the server, not in this repository, and its
 * exclusion lists are hard-coded in the plugin. It exposes them through the
 * yg_form_popup_no_popup_pages filter, so pages are added here instead of by
 * editing the plugin in place.
 *
 * A CSS hide is not enough: the popup locks page scrolling while open
 * (body.yg-form-popup-open) and buttons such as the bottom bar's "Enquire Now"
 * open it, so a hidden popup would leave those buttons dead.
 *
 *   german-language-enquiry   requested 1 Oct 2026; the page has its own
 *                             demo-class form, so the popup stacked a second
 *                             form over it.
 *
 * Remove a slug to bring the popup back on that page.
 *
 * @package YesGermany
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'yg_form_popup_no_popup_pages',
	function ( $pages ) {
		$pages   = (array) $pages;
		$pages[] = 'german-language-enquiry';
		return array_values( array_unique( $pages ) );
	}
);
