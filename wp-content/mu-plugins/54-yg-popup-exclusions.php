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
 *   german-language-course,   requested 6 Oct 2026, same reason: each has its
 *   german-classes-in-chennai, own "Book Free Demo Class" form.
 *   german-language,
 *   german-language-course-in-delhi
 *   technical-university-of-munich-in-germany   requested 7 Oct 2026; the page
 *                             has the Enquiry Form in its hero.
 *
 * Also, without listing them: every German-language page built from the
 * hand-made demo-class designs - the pages mu-plugin 56 gives a 16px base. Each
 * carries its own "Book Free Demo Class" form, so the popup would stack a
 * second form over it (Chandigarh, Bangalore, Mumbai, Pune, Hyderabad and
 * /german-language-classes/ were the first caught this way, 7 Oct 2026).
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
		$pages[] = 'german-language-course';
		$pages[] = 'german-classes-in-chennai';
		$pages[] = 'german-language';
		$pages[] = 'german-language-course-in-delhi';
		$pages[] = 'technical-university-of-munich-in-germany';

		// German pages in the demo-class designs (see mu-plugin 56).
		if ( function_exists( 'yg_rem_base_applies' ) && yg_rem_base_applies() ) {
			$pages[] = (int) get_queried_object_id();
		}
		return array_values( array_unique( $pages ) );
	}
);
