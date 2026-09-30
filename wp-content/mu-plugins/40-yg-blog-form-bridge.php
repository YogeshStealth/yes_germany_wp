<?php
/**
 * Plugin Name: YG Blog Form Bridge
 * Description: Read-only endpoint the blog install (public_html/blog) uses to render the main site's enquiry form and keep its branch mapping in sync. Adds nothing to the main site's own pages and changes no existing behaviour.
 * Version:     1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The form blog submissions are recorded against.
 *
 * Deliberately the site-wide Enquiry Form, so mail, branch mapping and lead
 * capture behave exactly as they do on the main site. Point the filter at a
 * dedicated "Blog Enquiry" copy if blog leads should be filterable by form
 * name in the leads panel; nothing else needs to change.
 */
function yg_blog_form_id() {
	return (int) apply_filters( 'yg_blog_form_id', 139211 );
}

add_action( 'rest_api_init', function () {
	register_rest_route(
		'yg/v1',
		'/blog-form',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => 'yg_blog_form_bridge',
		)
	);
} );

/**
 * Hand the blog the rendered form rather than a field list.
 *
 * The blog then cannot drift from whatever the form actually is - field names,
 * select options, placeholders and CF7's own hidden fields all come across as
 * they are. Everything returned here is already public: the branch mapping is
 * localised into every front-end page of the main site, and the form markup is
 * on every page that embeds it.
 */
function yg_blog_form_bridge() {
	$form_id = yg_blog_form_id();

	if ( ! shortcode_exists( 'contact-form-7' ) || ! get_post( $form_id ) ) {
		return new WP_Error(
			'yg_blog_form_unavailable',
			'The enquiry form is not available.',
			array( 'status' => 503 )
		);
	}

	$html = do_shortcode(
		sprintf( '[contact-form-7 id="%d" title="%s"]', $form_id, esc_attr( get_the_title( $form_id ) ) )
	);

	$branch_assets = plugins_url( 'assets/', WP_PLUGIN_DIR . '/branch-selection-for-cf7/branch-selection-for-cf7.php' );

	return rest_ensure_response(
		array(
			'form_id'   => $form_id,
			'endpoint'  => rest_url( sprintf( 'contact-form-7/v1/contact-forms/%d/feedback', $form_id ) ),
			'html'      => $html,
			'branches'  => get_option( 'branch_selection_cf7_data' ),
			'thank_you' => home_url( '/thank-you/' ),

			/*
			 * The CRM endpoint, for installs that must push it from the browser.
			 * Outbound 8443 is refused from this server, so the push cannot be
			 * made server-side from anywhere on this host - which is why the
			 * main site does it in the visitor's browser and mh must too.
			 */
			'crm_url'   => function_exists( 'yg_lead_api_url' ) ? yg_lead_api_url() : '',
			'assets'    => array(
				'branch_js' => $branch_assets . 'branch-selection.js',
				'ui_js'     => $branch_assets . 'frontend-ui.js',
				'form_css'  => $branch_assets . 'frontend-style.css',
				'cf7_css'   => plugins_url( 'includes/css/styles.css', WP_PLUGIN_DIR . '/contact-form-7/wp-contact-form-7.php' ),

				/*
				 * The searchable State / Branch dropdown. It lives in the popup
				 * plugin because that is where it was first needed, but popup.js
				 * enhances inline CF7 forms before it looks for the popup overlay
				 * and returns when there is none - so an install with no popup
				 * gets the dropdowns and nothing else. popup.css is scoped to
				 * .yg- classes and #yg-form-popup-overlay, so none of it applies
				 * where the overlay is absent.
				 */
			'select_js'  => plugins_url( 'assets/js/popup.js', WP_PLUGIN_DIR . '/yg-form-popup-v2/yg-form-popup-v2.php' ),
			'select_css' => plugins_url( 'assets/css/popup.css', WP_PLUGIN_DIR . '/yg-form-popup-v2/yg-form-popup-v2.php' ),
			),
		)
	);
}

/**
 * Which other YES Germany installs may post this form from a browser.
 *
 * The blog lives under www.yesgermany.com, so its submissions are same-origin
 * and never needed this. mh.yesgermany.com is a different origin, so without an
 * Access-Control-Allow-Origin header the browser blocks the page from reading
 * CF7's reply - the lead is created but the visitor sees a failure and submits
 * again. Listing the origin here is enough: WordPress's own
 * rest_send_cors_headers() reads this list and sends the header, including the
 * Vary: Origin that keeps the response cacheable.
 *
 * Deliberately an allowlist of our own hosts, not a wildcard. Nothing is
 * granted that a visitor on that site could not already do by hand.
 */
function yg_form_bridge_origins() {
	return (array) apply_filters(
		'yg_form_bridge_origins',
		array(
			'https://mh.yesgermany.com',
		)
	);
}

add_filter( 'allowed_http_origins', function ( $origins ) {
	return array_values( array_unique( array_merge( (array) $origins, yg_form_bridge_origins() ) ) );
} );
