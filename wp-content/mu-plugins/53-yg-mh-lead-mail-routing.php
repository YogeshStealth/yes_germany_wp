<?php
/**
 * Plugin Name: YG: MH lead notification routing
 * Description: Submissions of the main enquiry form (CF7 139211) that originate from
 *              mh.yesgermany.com get their notification email sent to the Maharashtra team
 *              (the same recipient list the mh site's own August forms used), with a subject
 *              that names the campaign. Nothing else changes: the lead-capture row, the CRM
 *              push and the LMS push all run exactly as before, and submissions from the main
 *              site keep their existing recipient and subject.
 * Version:     1.0.1
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
 */
add_filter(
	'wpcf7_skip_mail',
	function ( $skip, $contact_form ) {
		if ( $contact_form instanceof WPCF7_ContactForm && (int) $contact_form->id() === yg_mh_mail_form_id() && ! yg_mh_mail_is_mh_submission() ) {
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
		return $components;
	},
	20,
	3
);
