<?php
/**
 * Plugin Name: YG Performance: logo, hero pictures and fonts load first
 * Description: Stops the top of the page from appearing in pieces.
 *
 *              Recorded on the homepage (19 Oct 2026, phone on 4G): the logo was an empty
 *              box until about 9 s, the hero pictures popped in last, and the text jumped
 *              from a system font to Poppins at 4-7 s. Causes:
 *              - LiteSpeed lazy-loads every image, the logo and the hero pictures too, so
 *                they waited for its lazy-load script;
 *              - Poppins came from Google through LiteSpeed's font loader, which fetched
 *                every weight of the font before the text settled.
 *              So:
 *              - the logo and the homepage hero pictures are kept out of LiteSpeed's lazy
 *                loading. The hero pictures keep the browser's own loading="lazy", so the
 *                visible one loads at once and the copy hidden on this device (Elementor
 *                has a desktop and a mobile hero) is not downloaded;
 *              - fonts are served from this site (Elementor > Settings > Performance >
 *                "Load Google Fonts Locally", switched on 19 Oct 2026), and the Poppins
 *                files the top of the page needs are preloaded.
 *              To revert, remove this file and clear the page cache.
 * Version:     1.0.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

/** The site logo: in every page header. Matched on the image URL. */
const YG_ABOVE_FOLD_LOGO = '/logo-3-1.';

/**
 * Homepage hero pictures: desktop (student) and mobile (India map).
 * Plugin 62 skips its own hero guess on pages that have one of these.
 */
const YG_ABOVE_FOLD_HERO_IMAGES = array( '/Study-In-Germany-', '/Map-rcnrlhpqlzuyk' );

/** Poppins weights used at the top of the page (latin, upright). */
const YG_ABOVE_FOLD_FONT_WEIGHTS = array( '400', '700', '900' );

add_filter(
	'litespeed_media_lazy_img_excludes',
	function ( $excludes ) {
		return array_merge( (array) $excludes, array( YG_ABOVE_FOLD_LOGO ), YG_ABOVE_FOLD_HERO_IMAGES );
	}
);

/**
 * Preload the Poppins files Elementor saved locally.
 *
 * Their names carry a hash Elementor picks when it downloads the font, so they
 * are read from its stylesheet rather than written here.
 */
add_action(
	'wp_head',
	function () {
		if ( ! get_option( 'elementor_local_google_fonts' ) ) {
			return;
		}
		$file = wp_upload_dir()['basedir'] . '/elementor/google-fonts/css/poppins.css';
		$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! preg_match_all( '#/\* latin \*/\s*@font-face\s*\{(.*?)\}#s', $css, $faces ) ) {
			return;
		}
		foreach ( $faces[1] as $face ) {
			if ( ! preg_match( '#font-style:\s*normal#', $face )
				|| ! preg_match( '#font-weight:\s*(\d+)#', $face, $weight )
				|| ! in_array( $weight[1], YG_ABOVE_FOLD_FONT_WEIGHTS, true )
				|| ! preg_match( '#url\(([^)]+\.woff2)\)#', $face, $url ) ) {
				continue;
			}
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( trim( $url[1], '\'"' ) ) );
		}
	},
	1
);
