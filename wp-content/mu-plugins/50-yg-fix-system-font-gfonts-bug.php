<?php
/**
 * Plugin Name: YG Fix: broken Google Fonts request for "System Font" typography
 * Description: Zakra's Customind typography module (inc/customizer/customind/core/utils.php,
 *              get_google_fonts_url_by_ids()) only skips building a Google Fonts request when
 *              a typography control's font-family is exactly 'default' or 'inherit'. When a
 *              control is set to the theme's own "System Font" option, its value is the literal
 *              system font stack ("-apple-system, BlinkMacSystemFont, ...") - the function
 *              doesn't recognise that string, so it sends the entire stack to Google Fonts as
 *              if it were one font name. That request is inherently invalid and returns nothing
 *              usable; it exists on every single page load and is render-blocking. Currently set
 *              on zakra_heading_typography specifically (confirmed via theme_mods).
 * Version:     1.0.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'customind:typography:value',
	function ( $value ) {
		if ( is_array( $value )
			&& ! empty( $value['font-family'] )
			&& false !== strpos( strtolower( $value['font-family'] ), 'apple-system' )
		) {
			$value['font-family'] = 'default';
		}
		return $value;
	}
);
