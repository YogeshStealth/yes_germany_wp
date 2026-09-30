<?php
/**
 * Plugin Name: YG: self-host the bottom bar's Font Awesome
 * Description: mobile-bottom-menu-for-wp hard-codes two render-blocking stylesheets from the
 *              use.fontawesome.com CDN (Font Awesome 5.13.0 all.css + v4-shims.css) on every
 *              page - a third-party DNS lookup and TLS handshake on the critical path, and files
 *              LiteSpeed cannot combine or minify. Elementor already ships the same library
 *              (5.15.3, a strict superset with identical class names) locally, so this swaps the
 *              two handles to Elementor's copy. Same CSS, same icons, same origin. If Elementor's
 *              files are ever missing, nothing is changed and the CDN copy loads as before.
 * Version:     1.0.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! defined( 'ELEMENTOR_ASSETS_PATH' ) || ! defined( 'ELEMENTOR_ASSETS_URL' ) ) {
			return;
		}
		$dir = ELEMENTOR_ASSETS_PATH . 'lib/font-awesome/css/';
		$url = ELEMENTOR_ASSETS_URL . 'lib/font-awesome/css/';
		if ( ! file_exists( $dir . 'all.min.css' ) || ! file_exists( $dir . 'v4-shims.min.css' ) ) {
			return;
		}

		$swap = array(
			'fa5'          => array( 'all.min.css', array() ),
			'fa5-v4-shims' => array( 'v4-shims.min.css', array( 'fa5' ) ),
		);
		foreach ( $swap as $handle => $file ) {
			if ( ! wp_style_is( $handle, 'enqueued' ) ) {
				continue;
			}
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
			wp_enqueue_style( $handle, $url . $file[0], $file[1], '5.15.3', 'all' );
		}
	},
	99
);
