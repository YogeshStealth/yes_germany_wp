<?php
/**
 * Plugin Name: YG: MH lead notification routing
 * Description: Submissions of the main enquiry form (CF7 139211) that originate from
 *              mh.yesgermany.com get their notification email sent to the Maharashtra team
 *              (the same recipient list the mh site's own August forms used), with a subject
 *              that names the campaign. Meta (Facebook / Instagram) leads from mh are not
 *              emailed at all. Nothing else changes: the lead-capture row, the CRM push and
 *              the LMS push all run exactly as before for every lead.
 * Version:     1.3.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

function yg_mh_mail_form_id() {
	return (int) apply_filters( 'yg_mh_mail_form_id', 139211 );
}

/**
 * The Maharashtra team's addresses.
 *
 * Set in wp-config.php as a comma-separated YG_MH_MAIL_RECIPIENTS, not here:
 * the repository is public. When unset, the form's own recipient is left as it
 * is and only the subject changes.
 */
function yg_mh_mail_recipients() {
	$list = defined( 'YG_MH_MAIL_RECIPIENTS' ) ? (string) YG_MH_MAIL_RECIPIENTS : '';
	return (array) apply_filters(
		'yg_mh_mail_recipients',
		array_values( array_filter( array_map( 'trim', explode( ',', $list ) ) ) )
	);
}

/**
 * True when the current submission came from mh.yesgermany.com. Reuses the LMS push's
 * origin check (Origin header first, Referer as fallback) so both features agree on what
 * counts as an mh lead; falls back to the same logic if that plugin is ever removed.
 */
function yg_mh_mail_is_mh_submission() {
	if ( function_exists( 'yg_lms_is_forwarded_origin' ) ) {
		return (bool) yg_lms_is_forwarded_origin();
	}
	foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) && 0 === strpos( (string) wp_unslash( $_SERVER[ $key ] ), 'https://mh.yesgermany.com' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * True when an mh submission came from a Meta (Facebook / Instagram) ad.
 *
 * The mh form posts `source` as the landing URL's utm_source when there is one,
 * otherwise the page's own label. Values seen on real leads, Sep 2026: "ig",
 * "fb" and "facebook mh" are Meta; "Google ads MH" is not.
 *
 * @param array|null $posted Posted form data; read from the current submission when null.
 */
function yg_mh_mail_is_meta_lead( $posted = null ) {
	if ( null === $posted ) {
		$posted = array();
		if ( class_exists( 'WPCF7_Submission' ) && ( $submission = WPCF7_Submission::get_instance() ) ) {
			$posted = (array) $submission->get_posted_data();
		}
	}
	foreach ( array( 'source', 'utm_source' ) as $field ) {
		$value = isset( $posted[ $field ] ) ? $posted[ $field ] : '';
		if ( is_array( $value ) ) {
			$value = implode( ' ', $value );
		}
		if ( preg_match( '/\b(fb|ig|meta|facebook|instagram|messenger|threads)\b/i', (string) $value ) ) {
			return true;
		}
	}
	return false;
}

function yg_mh_mail_subject() {
	$source = '';
	if ( class_exists( 'WPCF7_Submission' ) && ( $submission = WPCF7_Submission::get_instance() ) ) {
		$source = (string) $submission->get_posted_data( 'source' );
	}
	if ( false !== stripos( $source, 'facebook' ) ) {
		return 'From MH YES Germany Facebook';
	}
	return 'From MH YES Germany Google Ads';
}

/**
 * Main-site (www.yesgermany.com) submissions of this form send no notification email at
 * all - agreed with the client 29 Sep 2026: those leads live in the lead-capture list,
 * the CRM and the LMS, which all still run (they hook wpcf7_before_send_mail, which CF7
 * fires before it consults this filter). Skipping mail keeps the visitor's success
 * message and thank-you redirect intact; only submissions from mh.yesgermany.com are
 * still emailed, to the recipients above.
 *
 * Meta leads from mh are not emailed either - agreed with the client 30 Sep 2026:
 * they go to the lead-capture list, the CRM and the LMS only. mh leads from
 * Google Ads are still emailed.
 */
add_filter(
	'wpcf7_skip_mail',
	function ( $skip, $contact_form ) {
		if ( ! $contact_form instanceof WPCF7_ContactForm || (int) $contact_form->id() !== yg_mh_mail_form_id() ) {
			return $skip;
		}
		if ( ! yg_mh_mail_is_mh_submission() || yg_mh_mail_is_meta_lead() ) {
			return true;
		}
		return $skip;
	},
	20,
	2
);

add_filter(
	'wpcf7_mail_components',
	function ( $components, $contact_form, $mail ) {
		if ( ! $contact_form instanceof WPCF7_ContactForm || (int) $contact_form->id() !== yg_mh_mail_form_id() ) {
			return $components;
		}
		if ( is_object( $mail ) && method_exists( $mail, 'name' ) && 'mail' !== $mail->name() ) {
			return $components;
		}
		if ( ! yg_mh_mail_is_mh_submission() ) {
			return $components;
		}
		$recipients = yg_mh_mail_recipients();
		if ( $recipients ) {
			$components['recipient'] = implode( ', ', $recipients );
		}
		$components['subject']   = yg_mh_mail_subject();
		$components['body']      = yg_mh_mail_add_ad_details( (string) $components['body'] );
		return $components;
	},
	20,
	3
);

/**
 * Add the ad click details to an mh lead email (1.3.0, 2026-10-14).
 *
 * Several Maharashtra ads carry no UTM tags, so the email's utm_ lines were
 * empty and nobody could tell which campaign a lead came from. The mh form
 * (yg-mh-enquiry 1.4.0) now sends Google's campaign ID and click ID, Facebook's
 * click ID and the visitor's first landing page; they are listed here. The
 * Google campaign ID can be searched in Google Ads to find the campaign.
 *
 * @param string $body Mail body as composed by CF7 (HTML).
 * @return string
 */
function yg_mh_mail_add_ad_details( $body ) {
	if ( ! class_exists( 'WPCF7_Submission' ) || ! ( $submission = WPCF7_Submission::get_instance() ) ) {
		return $body;
	}
	$posted = (array) $submission->get_posted_data();
	$get    = function ( $key ) use ( $posted ) {
		$v = isset( $posted[ $key ] ) ? $posted[ $key ] : '';
		return trim( (string) ( is_array( $v ) ? reset( $v ) : $v ) );
	};
	$rows = array(
		'Google Ads campaign ID'      => $get( 'yg_gad_campaignid' ),
		'Google Ads click ID'         => $get( 'yg_gclid' ),
		'Facebook / Instagram click ID' => $get( 'yg_fbclid' ),
		'First landing page'          => $get( 'yg_first_landing' ),
		'Came to the site from'       => $get( 'yg_first_referrer' ),
		'Enquiry page'                => $get( 'yg_page_url' ),
	);
	$lines = array();
	foreach ( $rows as $label => $value ) {
		if ( '' !== $value ) {
			$lines[] = esc_html( $label ) . ': ' . esc_html( $value );
		}
	}
	if ( ! $rows['Google Ads campaign ID'] && ! $rows['Google Ads click ID'] && ! $rows['Facebook / Instagram click ID'] && '' === $get( 'utm_source' ) ) {
		array_unshift( $lines, 'No campaign or ad click information came with this visitor.' );
	}
	if ( ! $lines ) {
		return $body;
	}
	$block = '<p>--- Ad click ---<br>' . implode( '<br>', $lines ) . '</p>';
	$pos   = stripos( $body, '</body>' );
	return false === $pos ? $body . $block : substr( $body, 0, $pos ) . $block . substr( $body, $pos );
}
