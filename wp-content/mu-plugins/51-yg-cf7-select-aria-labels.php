<?php
/**
 * Plugin Name: YG: aria-label on Contact Form 7 selects
 * Description: CF7's first_as_label option shows the first <option> as a visual placeholder but
 *              gives the <select> no accessible name, so Lighthouse's "select-name" audit fails on
 *              all four dropdowns in the eligibility form. This adds aria-label="<first option
 *              text>" to any CF7 <select> that has no accessible name yet. Output-only: the form
 *              definition, fields, validation, submission and appearance are untouched.
 * Version:     1.0.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wpcf7_form_elements',
	function ( $html ) {
		return preg_replace_callback(
			'/<select\b(?![^>]*\baria-label(?:ledby)?=)([^>]*)>(\s*<option[^>]*>)([^<]*)<\/option>/i',
			function ( $m ) {
				$label = trim( preg_replace( '/^-+\s*|\s*-+$/', '', trim( $m[3] ) ) );
				if ( '' === $label ) {
					return $m[0];
				}
				return '<select' . $m[1] . ' aria-label="' . esc_attr( $label ) . '">' . $m[2] . $m[3] . '</option>';
			},
			$html
		);
	},
	20
);
