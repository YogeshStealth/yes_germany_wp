<?php
/**
 * Plugin Name: YG Lead LMS Auto-Backfill
 * Description: Resends to the LMS any www lead whose delivery was never confirmed (no successful CRM or LMS answer in the Lead API log). Run by the system cron every 6 hours via `wp yg-lms-backfill`. Production only.
 * Version:     1.0.0
 * Author:      Stealth Digital
 *
 * Why
 * ---
 * Leads reach the CRM from the visitor's browser (outbound 8443 is blocked on
 * this host, see 45-yg-lead-crm-push.php), so a visitor who leaves early or a
 * phone that freezes can leave a lead with no confirmed delivery. On
 * 2026-10-10, 155 such leads were resent by hand; this does the same on a
 * schedule, so nothing waits for someone to notice.
 *
 * What counts as delivered
 * ------------------------
 * A row in the Lead API log (mu-plugin 57) with ok = 1 on channel crm or lms:
 * a 2xx answer that carried the system's own lead ID. The CRM feeds the LMS, so
 * a confirmed CRM lead is not resent. An LMS answer of "Interaction created"
 * (person already in the LMS, enquiry added to their record) also has a
 * lead_id and counts as delivered.
 *
 * Which leads
 * -----------
 * - www.yesgermany.com only: mh leads are email-only (mu-plugin 58) and Dubai
 *   leads go through the relay (55), not this lead list.
 * - newer than YG_LMS_BACKFILL_START_ID: everything up to it was checked
 *   against the LMS by the PPC team and resent by hand on 2026-10-10.
 * - at least an hour old, so normal delivery has finished first.
 * - fewer than YG_LMS_BACKFILL_MAX_TRIES earlier attempts by this job.
 *
 * Every attempt is stored in the Lead API log with transport "auto-backfill",
 * so Tools > Lead API responses shows what was sent and what the LMS said.
 *
 * Cron (yesgermanycom's crontab):
 *   17 0,6,12,18 * * * cd ~/public_html && ~/bin/wp yg-lms-backfill --skip-themes >> ~/yg-cron/lms-backfill.log 2>&1
 *
 * Run by hand: `wp yg-lms-backfill --dry-run` lists what would be sent.
 */

defined( 'ABSPATH' ) || exit;

const YG_LMS_BACKFILL_START_ID  = 1791;
const YG_LMS_BACKFILL_MIN_AGE   = 3600;
const YG_LMS_BACKFILL_MAX_TRIES = 4;
const YG_LMS_BACKFILL_BATCH     = 50;

/**
 * Leads that still need delivering, oldest first.
 *
 * @param int $limit Maximum rows.
 * @return array[]
 */
function yg_lms_backfill_candidates( $limit ) {
	global $wpdb;
	$leads = $wpdb->prefix . 'yg_leads';
	$log   = $wpdb->prefix . 'yg_lead_api_log';

	// yg_leads.created_at is stored in UTC.
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - YG_LMS_BACKFILL_MIN_AGE );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.* FROM $leads l
			 WHERE l.id > %d
			   AND l.created_at <= %s
			   AND l.page_url LIKE %s
			   AND l.page_url NOT LIKE %s
			   AND IFNULL(l.referrer, '') NOT LIKE %s
			   AND IFNULL(l.landing_page, '') NOT LIKE %s
			   AND NOT EXISTS ( SELECT 1 FROM $log a WHERE a.lead_id = l.id AND a.ok = 1 AND a.channel IN ('crm','lms') )
			   AND ( SELECT COUNT(*) FROM $log b WHERE b.lead_id = l.id AND b.transport = 'auto-backfill' ) < %d
			 ORDER BY l.id
			 LIMIT %d",
			YG_LMS_BACKFILL_START_ID,
			$cutoff,
			'%://www.yesgermany.com/%',
			'%/wp-json/%',
			'%mh.yesgermany.com%',
			'%mh.yesgermany.com%',
			YG_LMS_BACKFILL_MAX_TRIES,
			(int) $limit
		),
		ARRAY_A
	);
	// phpcs:enable
}

/**
 * The LMS payload for one stored lead - the visitor's own form fields, built
 * by the same function the live LMS push uses, plus a note saying it is a
 * resubmission and when the visitor actually enquired.
 *
 * @param array $lead Row from yg_leads.
 * @return array
 */
function yg_lms_backfill_payload( array $lead ) {
	$posted = json_decode( (string) $lead['fields_json'], true );
	$posted = is_array( $posted ) ? $posted : array();

	$ist = gmdate( 'Y-m-d H:i', strtotime( $lead['created_at'] . ' UTC' ) + 19800 );

	$posted['original_submitted_at'] = $ist . ' IST';
	$posted['submission_note']       = 'Website lead auto-resubmitted on ' . gmdate( 'Y-m-d H:i', time() + 19800 ) . ' IST (originally submitted ' . $ist . ' IST; the website had no CRM/LMS confirmation for it)';

	return array(
		'field_data'   => yg_lms_field_data( $posted ),
		'tracking_url' => (string) $lead['page_url'],
		'redirect_url' => (string) $lead['page_url'],
	);
}

/**
 * Send what is waiting. Returns a summary; prints one line per lead to $out.
 *
 * @param bool     $dry_run List only.
 * @param int      $limit   Batch size.
 * @param callable $out     Line printer.
 * @return array
 */
function yg_lms_backfill_run( $dry_run = false, $limit = YG_LMS_BACKFILL_BATCH, $out = null ) {
	$out     = $out ? $out : function ( $line ) {};
	$summary = array( 'candidates' => 0, 'sent' => 0, 'added' => 0, 'interaction' => 0, 'failed' => 0 );

	if ( ! function_exists( 'yg_is_production' ) || ! yg_is_production() ) {
		$out( 'not production - nothing is sent from this site' );
		return $summary;
	}
	if ( ! function_exists( 'yg_lms_field_data' ) || ! function_exists( 'yg_lms_api_key' ) || ! function_exists( 'yg_lead_api_record' ) ) {
		$out( 'mu-plugins 48 / 57 are not loaded - nothing sent' );
		return $summary;
	}
	if ( '' === yg_lms_api_key() ) {
		$out( 'YG_LMS_API_KEY is not set - nothing sent' );
		return $summary;
	}

	// One run at a time.
	if ( ! $dry_run ) {
		if ( get_transient( 'yg_lms_backfill_lock' ) ) {
			$out( 'another run is in progress - skipped' );
			return $summary;
		}
		set_transient( 'yg_lms_backfill_lock', time(), 30 * MINUTE_IN_SECONDS );
	}

	$leads                 = yg_lms_backfill_candidates( $limit );
	$summary['candidates'] = count( $leads );

	foreach ( $leads as $i => $lead ) {
		if ( $dry_run ) {
			$out( sprintf( 'would send lead %d  %s UTC  %s  %s', $lead['id'], $lead['created_at'], $lead['name'], $lead['page_url'] ) );
			continue;
		}

		$payload = yg_lms_backfill_payload( $lead );
		$started = microtime( true );
		$res     = wp_remote_post(
			yg_lms_url(),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'api-key'      => yg_lms_api_key(),
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
			)
		);
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$code = 0;
			$body = 'transport error: ' . $res->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
		}

		yg_lead_api_record(
			array(
				'channel'     => 'lms',
				'transport'   => 'auto-backfill',
				'http_status' => $code ? $code : null,
				'response'    => $body,
				'lead_id'     => (int) $lead['id'],
				'email'       => $lead['email'],
				'phone'       => $lead['phone'],
				'page_url'    => $lead['page_url'],
				'duration_ms' => $ms,
			)
		);

		$decoded = json_decode( $body, true );
		$ok      = $code >= 200 && $code < 300 && is_array( $decoded ) && ! empty( $decoded['lead_id'] );
		$message = is_array( $decoded ) && isset( $decoded['message'] ) ? $decoded['message'] : substr( $body, 0, 200 );

		$summary['sent']++;
		if ( ! $ok ) {
			$summary['failed']++;
		} elseif ( 0 === stripos( $message, 'Interaction' ) ) {
			$summary['interaction']++;
		} else {
			$summary['added']++;
		}

		$out( sprintf( 'lead %d  %s  HTTP %d  %s  %s', $lead['id'], $lead['name'], $code, $ok ? $decoded['lead_id'] : 'FAILED', $message ) );

		if ( $i < count( $leads ) - 1 ) {
			sleep( 1 );
		}
	}

	if ( ! $dry_run ) {
		delete_transient( 'yg_lms_backfill_lock' );
	}

	return $summary;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Resend unconfirmed www leads to the LMS.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be sent; send nothing.
	 *
	 * [--limit=<n>]
	 * : Maximum leads this run. Default 50.
	 */
	WP_CLI::add_command(
		'yg-lms-backfill',
		function ( $args, $assoc ) {
			$dry   = ! empty( $assoc['dry-run'] );
			$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : YG_LMS_BACKFILL_BATCH;
			$s     = yg_lms_backfill_run(
				$dry,
				$limit,
				function ( $line ) {
					WP_CLI::log( $line );
				}
			);
			WP_CLI::log(
				sprintf(
					'[%s UTC] %s: %d waiting, %d sent, %d new leads, %d added to existing, %d failed',
					gmdate( 'Y-m-d H:i:s' ),
					$dry ? 'dry run' : 'run',
					$s['candidates'],
					$s['sent'],
					$s['added'],
					$s['interaction'],
					$s['failed']
				)
			);
		}
	);
}
