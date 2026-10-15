<?php
/**
 * Plugin Name: YG: daily lead check
 * Description: `wp yg-lead-check` - every morning, checks yesterday's enquiries end to end
 *              (saved in the CRM, in the LMS under the right source, mh emails, relay forms,
 *              ad clicks that carried no UTM tags) and emails the result.
 * Version:     1.0.0
 * Author:      YES Germany
 */

defined( 'ABSPATH' ) || exit;

/*
 * Why this exists
 * ---------------
 * In October 2026 paid-campaign enquiries reached the LMS as "website" for
 * days before anyone noticed. This check reads what actually happened to
 * every enquiry of one day (India time) and says plainly what went wrong:
 *
 *   www enquiries  - saveDetails answer (CRM lead ID / saved without ID / failed),
 *                    the campaign the site sends for it, and the source the LMS
 *                    shows. German-language enquiries go to a separate LMS and
 *                    are only checked in the CRM.
 *   mh enquiries   - email only (client decision 8 Oct 2026): sent or not, and
 *                    whether the ad details (campaign ID / click ID) came along.
 *   relay forms    - the ~35 hand-built forms that post through mu-plugin 55.
 *   backfill job   - the runs of `wp yg-lms-backfill` (mu-plugin 59) that day.
 *   ad clicks      - visits that came from a Google ad (gclid / gad_campaignid)
 *                    or Facebook / Instagram (fbclid) with no utm_source, read from
 *                    the server's access logs, by campaign ID. Those ads need UTM
 *                    tags in Google Ads / Meta.
 *
 * Read-only: it sends nothing to the CRM or the LMS.
 *
 * Usage:  wp yg-lead-check [--date=YYYY-MM-DD] [--no-mail]
 * Cron:   5 9 * * *  (server clock is India time) - see ~/yg-cron/crontab.txt
 * Mail:   YG_LEAD_CHECK_RECIPIENTS in wp-config.php, comma-separated. Not set -> no mail.
 * Copy:   ~/yg-cron/lead-check-<date>.txt
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

const YG_LEAD_CHECK_IST = 19800;

/**
 * Letters and digits only, lower case - "Google-ads" and "Google Ads" compare equal.
 *
 * @param string $s Value.
 * @return string
 */
function yg_lead_check_norm( $s ) {
	return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $s ) );
}

/**
 * One field of a lead's posted form.
 *
 * @param array  $posted Decoded fields_json.
 * @param string $key    Field name.
 * @return string
 */
function yg_lead_check_field( array $posted, $key ) {
	$v = isset( $posted[ $key ] ) ? $posted[ $key ] : '';
	return trim( is_array( $v ) ? (string) reset( $v ) : (string) $v );
}

/**
 * LMS source of a lead: array( 'lead_id', 'source', 'secondary' ), array() when not found, null when the LMS could not be asked.
 *
 * @param array $lead Row from yg_leads.
 * @return array|null
 */
function yg_lead_check_lms( array $lead ) {
	$found = yg_lms_find_lead( $lead );
	if ( null === $found ) {
		return null;
	}
	if ( ! $found ) {
		return array();
	}
	$res = wp_remote_get(
		YG_LMS_READ_BASE . '/lead-management/lead-details/' . rawurlencode( $found['lead_id'] ),
		array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . yg_lms_read_token(), 'Accept' => 'application/json' ),
		)
	);
	$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
		return array( 'lead_id' => $found['lead_id'], 'source' => '?', 'secondary' => '' );
	}
	return array(
		'lead_id'   => $found['lead_id'],
		'source'    => (string) ( $data['data']['lead_source'] ?? '' ),
		'secondary' => is_scalar( $data['data']['secondary_source'] ?? null ) ? (string) $data['data']['secondary_source'] : '',
	);
}

/**
 * The saveDetails result for a lead, from the answer log (mu-plugin 57).
 *
 * @param int $lead_id yg_leads id.
 * @return array{state:string,text:string,sends:int}
 */
function yg_lead_check_crm( $lead_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ok, remote_id, http_status, response FROM {$wpdb->prefix}yg_lead_api_log WHERE lead_id = %d AND channel = 'crm' ORDER BY id", (int) $lead_id ), ARRAY_A );
	$out  = array( 'state' => 'none', 'text' => 'not sent', 'sends' => count( $rows ) );
	foreach ( $rows as $r ) {
		if ( (int) $r['ok'] ) {
			return array( 'state' => 'id', 'text' => 'CRM ID ' . $r['remote_id'], 'sends' => count( $rows ) );
		}
		if ( false !== strpos( (string) $r['response'], '"success":true' ) ) {
			$out = array( 'state' => 'noid', 'text' => 'saved, CRM gave no ID', 'sends' => count( $rows ) );
		} elseif ( 'noid' !== $out['state'] ) {
			$out = array( 'state' => 'failed', 'text' => 'FAILED HTTP ' . (int) $r['http_status'] . ' ' . substr( preg_replace( '/\s+/', ' ', (string) $r['response'] ), 0, 80 ), 'sends' => count( $rows ) );
		}
	}
	return $out;
}

/**
 * Ad click IDs a lead carried (enquiry page first, then first arrival).
 *
 * @param array $lead   Row from yg_leads.
 * @param array $posted Decoded fields_json.
 * @return array{gclid:string,gad_campaignid:string,fbclid:string}
 */
function yg_lead_check_clicks( array $lead, array $posted ) {
	$out = array( 'gclid' => '', 'gad_campaignid' => '', 'fbclid' => '' );
	if ( ! function_exists( 'yg_lms_backfill_clicks' ) ) {
		return $out;
	}
	foreach ( yg_lms_backfill_clicks( $lead, $posted ) as $c ) {
		foreach ( $out as $k => $v ) {
			if ( '' === $v && ! empty( $c[ $k ] ) ) {
				$out[ $k ] = (string) $c[ $k ];
			}
		}
	}
	return $out;
}

/**
 * Untagged ad visits in the access logs of one host on one India-time day.
 *
 * @param string $host  'yesgermany.com' or 'mh.yesgermany.com' (the cPanel log name).
 * @param int    $day   Unix time of 00:00 IST that day.
 * @return array{read:bool,files:string[],google:array,facebook:int,facebook_ips:int,tagged:int}
 */
function yg_lead_check_ad_visits( $host, $day ) {
	$home  = dirname( rtrim( ABSPATH, '/' ) );
	$stamp = '[' . gmdate( 'd/M/Y', $day + YG_LEAD_CHECK_IST ) . ':';
	$files = array(
		$home . '/logs/' . $host . '-ssl_log-' . gmdate( 'M-Y', $day + YG_LEAD_CHECK_IST ) . '.gz',
		$home . '/access-logs/' . $host . '-ssl_log',
	);
	$out   = array( 'read' => false, 'files' => array(), 'google' => array(), 'facebook' => 0, 'facebook_ips' => 0, 'tagged' => 0 );
	$seen  = array();
	$fb    = array();
	foreach ( $files as $file ) {
		if ( ! is_readable( $file ) ) {
			continue;
		}
		$fh = '.gz' === substr( $file, -3 ) ? gzopen( $file, 'rb' ) : fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			continue;
		}
		$out['read']    = true;
		$out['files'][] = basename( $file );
		$gets           = '.gz' === substr( $file, -3 ) ? 'gzgets' : 'fgets';
		while ( false !== ( $line = $gets( $fh ) ) ) {
			if ( false === strpos( $line, $stamp ) || ( false === strpos( $line, 'gclid=' ) && false === strpos( $line, 'gad_campaignid=' ) && false === strpos( $line, 'fbclid=' ) ) ) {
				continue;
			}
			if ( ! preg_match( '/^(\S+) .*?"GET (\S+) HTTP[^"]*" (\d{3}) .*"([^"]*)"\s*$/', $line, $m ) || preg_match( '/bot|crawler|spider|preview|facebookexternalhit/i', $m[4] ) ) {
				continue;
			}
			$key = md5( $line );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = 1;
			$q            = array();
			parse_str( (string) wp_parse_url( $m[2], PHP_URL_QUERY ), $q );
			if ( ! empty( $q['utm_source'] ) ) {
				$out['tagged']++;
				continue;
			}
			if ( ! empty( $q['gclid'] ) || ! empty( $q['gad_campaignid'] ) || ! empty( $q['gbraid'] ) || ! empty( $q['wbraid'] ) ) {
				$id = ! empty( $q['gad_campaignid'] ) && is_scalar( $q['gad_campaignid'] ) ? preg_replace( '/\D/', '', (string) $q['gad_campaignid'] ) : '(no campaign ID)';
				if ( ! isset( $out['google'][ $id ] ) ) {
					$out['google'][ $id ] = array( 'visits' => 0, 'ips' => array(), 'page' => strtok( $m[2], '?' ) );
				}
				$out['google'][ $id ]['visits']++;
				$out['google'][ $id ]['ips'][ $m[1] ] = 1;
			} elseif ( ! empty( $q['fbclid'] ) ) {
				$out['facebook']++;
				$fb[ $m[1] ] = 1;
			}
		}
		'.gz' === substr( $file, -3 ) ? gzclose( $fh ) : fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$out['facebook_ips'] = count( $fb );
	uasort(
		$out['google'],
		function ( $a, $b ) {
			return $b['visits'] - $a['visits'];
		}
	);
	return $out;
}

/**
 * Build the report for one India-time day.
 *
 * @param string   $date YYYY-MM-DD (India time).
 * @param callable $tick Progress printer.
 * @return array{text:string,problems:int}
 */
function yg_lead_check_report( $date, $tick ) {
	global $wpdb;
	$day  = strtotime( $date . ' 00:00:00 UTC' ) - YG_LEAD_CHECK_IST;
	$from = gmdate( 'Y-m-d H:i:s', $day );
	$to   = gmdate( 'Y-m-d H:i:s', $day + DAY_IN_SECONDS - 1 );
	$p    = array();   // Problems, one line each.
	$t    = array();   // Report body.

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
	$leads = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}yg_leads WHERE created_at BETWEEN %s AND %s ORDER BY id", $from, $to ), ARRAY_A );
	// phpcs:enable

	$www = array();
	$mh  = array();
	$oth = array();
	foreach ( $leads as $l ) {
		$is_mh = false !== stripos( $l['page_url'] . ' ' . $l['referrer'] . ' ' . $l['landing_page'], 'mh.yesgermany.com' );
		if ( $is_mh ) {
			$mh[] = $l;
		} elseif ( false !== strpos( $l['page_url'], '://www.yesgermany.com/' ) && false === strpos( $l['page_url'], '/wp-json/' ) ) {
			$www[] = $l;
		} else {
			$oth[] = $l;
		}
	}

	/* ---- www enquiries ---- */
	$rows = array();
	$cnt  = array( 'id' => 0, 'noid' => 0, 'failed' => 0, 'none' => 0, 'lms_ok' => 0, 'lms_diff' => 0, 'lms_missing' => 0, 'lms_error' => 0, 'german' => 0, 'test' => 0 );
	foreach ( $www as $i => $l ) {
		$tick( sprintf( 'www lead %d (%d/%d)', $l['id'], $i + 1, count( $www ) ) );
		$posted = json_decode( (string) $l['fields_json'], true );
		$posted = is_array( $posted ) ? $posted : array();
		$name   = yg_lead_check_field( $posted, 'full_name' ) ?: $l['name'];
		$test   = (bool) preg_match( '/\btest\b/i', $name . ' ' . $l['email'] );
		$sent   = function_exists( 'yg_lead_crm_payload_from_lead' ) ? yg_lead_crm_payload_from_lead( $l ) : array();
		$src    = trim( (string) ( $sent['utm_source'] ?? '' ) );
		$camp   = trim( (string) ( $sent['utm_campaign'] ?? '' ) );
		$crm    = yg_lead_check_crm( (int) $l['id'] );
		$german = function_exists( 'yg_lms_backfill_is_held_german' ) && yg_lms_backfill_is_held_german( $l );
		$clicks = yg_lead_check_clicks( $l, $posted );
		$tag    = sprintf( 'www lead %d %s IST %s', $l['id'], gmdate( 'H:i', strtotime( $l['created_at'] . ' UTC' ) + YG_LEAD_CHECK_IST ), $test ? '(TEST) ' : '' );

		$cnt[ $crm['state'] ]++;
		if ( $test ) {
			$cnt['test']++;
		}
		if ( ! $test && in_array( $crm['state'], array( 'failed', 'none' ), true ) ) {
			$p[] = $tag . '- not saved in the CRM: ' . $crm['text'];
		}
		if ( ! $test && $crm['sends'] > 2 ) {
			$p[] = $tag . '- sent to saveDetails ' . $crm['sends'] . ' times (possible duplicates in the CRM)';
		}
		if ( ( $clicks['gclid'] || $clicks['gad_campaignid'] || $clicks['fbclid'] ) && ( '' === $src || ( function_exists( 'yg_lead_api_is_internal_utm' ) && yg_lead_api_is_internal_utm( $src ) ) ) ) {
			$p[] = $tag . '- came from an ad (' . ( $clicks['gad_campaignid'] ? 'Google campaign ' . $clicks['gad_campaignid'] : ( $clicks['gclid'] ? 'Google click' : 'Facebook click' ) ) . ') but the site sends no campaign - website code problem';
		}

		$lms_txt = '';
		if ( $german ) {
			$cnt['german']++;
			$lms_txt = 'German LMS (not checked here)';
		} elseif ( $test ) {
			$lms_txt = 'test - LMS not checked';
		} else {
			$lms = yg_lead_check_lms( $l );
			usleep( 200000 );
			if ( null === $lms ) {
				$cnt['lms_error']++;
				$lms_txt = 'LMS search failed';
				$p[]     = $tag . '- could not ask the LMS (search failed)';
			} elseif ( ! $lms ) {
				$cnt['lms_missing']++;
				$lms_txt = 'NOT IN LMS';
				$p[]     = $tag . '- not in the LMS (' . $crm['text'] . ')';
			} else {
				$want    = '' === $src ? 'website' : $src;
				$lms_txt = 'LMS: ' . ( '' === $lms['source'] ? '(empty)' : $lms['source'] ) . ( '' !== $lms['secondary'] ? ' / secondary: ' . $lms['secondary'] : '' );
				if ( yg_lead_check_norm( $want ) === yg_lead_check_norm( $lms['source'] ) || yg_lead_check_norm( $want ) === yg_lead_check_norm( $lms['secondary'] ) ) {
					$cnt['lms_ok']++;
				} else {
					$cnt['lms_diff']++;
					$p[] = $tag . '- site sent "' . $want . '"' . ( '' !== $camp ? ' (' . $camp . ')' : '' ) . ', LMS shows "' . $lms['source'] . '"' . ( '' !== $lms['secondary'] ? ' / secondary "' . $lms['secondary'] . '"' : '' ) . ' - LMS lead ' . $lms['lead_id'];
				}
			}
		}

		$rows[] = sprintf(
			'%-5d %s  %-22s %-28s %-24s %s',
			$l['id'],
			gmdate( 'H:i', strtotime( $l['created_at'] . ' UTC' ) + YG_LEAD_CHECK_IST ),
			substr( ( $test ? 'TEST ' : '' ) . ( $german ? 'German ' : '' ) . ( '' === $src ? '(no campaign)' : $src ), 0, 22 ),
			substr( $camp, 0, 28 ),
			substr( $crm['text'], 0, 24 ),
			$lms_txt
		);
	}

	/* ---- mh enquiries (email only) ---- */
	$mh_rows = array();
	$mh_cnt  = array( 'campaign' => 0, 'gclid' => 0, 'fb' => 0, 'none' => 0, 'mail_failed' => 0 );
	$mh_camp = array();
	foreach ( $mh as $l ) {
		$posted = json_decode( (string) $l['fields_json'], true );
		$posted = is_array( $posted ) ? $posted : array();
		$cid    = yg_lead_check_field( $posted, 'yg_gad_campaignid' );
		$gclid  = yg_lead_check_field( $posted, 'yg_gclid' ) ?: (string) $l['gclid'];
		$fbclid = yg_lead_check_field( $posted, 'yg_fbclid' ) ?: (string) $l['fbclid'];
		if ( '' !== $cid ) {
			$mh_cnt['campaign']++;
			$mh_camp[ $cid ] = ( $mh_camp[ $cid ] ?? 0 ) + 1;
		} elseif ( '' !== $gclid ) {
			$mh_cnt['gclid']++;
		} elseif ( '' !== $fbclid ) {
			$mh_cnt['fb']++;
		} else {
			$mh_cnt['none']++;
		}
		if ( 'sent' !== $l['status'] ) {
			$mh_cnt['mail_failed']++;
			$p[] = sprintf( 'mh lead %d - notification email status "%s" (not sent)', $l['id'], $l['status'] );
		}
		$mh_rows[] = sprintf( '%-5d %s  %-12s %s', $l['id'], gmdate( 'H:i', strtotime( $l['created_at'] . ' UTC' ) + YG_LEAD_CHECK_IST ), $l['status'], '' !== $cid ? 'Google campaign ' . $cid : ( '' !== $gclid ? 'Google click, no campaign ID' : ( '' !== $fbclid ? 'Facebook / Instagram click' : 'no ad details' ) ) );
	}

	/* ---- relay forms ---- */
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	$relay     = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, http_status, ok, page_url, response FROM {$wpdb->prefix}yg_lead_api_log WHERE transport = 'relay' AND created_at BETWEEN %s AND %s ORDER BY id", $from, $to ), ARRAY_A );
	$relay_bad = array();
	foreach ( $relay as $r ) {
		if ( ! (int) $r['ok'] ) {
			$relay_bad[] = $r;
			$p[]         = sprintf( 'relay form %s - LMS answered HTTP %d: %s', strtok( (string) $r['page_url'], '?' ), $r['http_status'], substr( preg_replace( '/\s+/', ' ', (string) $r['response'] ), 0, 100 ) );
		}
	}
	$uploads   = wp_upload_dir();
	$relay_log = array();
	$lf        = trailingslashit( $uploads['basedir'] ) . 'yg-lms-push.log';
	if ( is_readable( $lf ) ) {
		foreach ( file( $lf ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$d = json_decode( $line, true );
			if ( is_array( $d ) && isset( $d['at'], $d['reason'] ) && $d['at'] >= $from && $d['at'] <= $to ) {
				$relay_log[] = $d['at'] . ' UTC  ' . substr( (string) $d['reason'], 0, 160 );
				$p[]         = 'LMS push / relay failure logged: ' . substr( (string) $d['reason'], 0, 160 );
			}
		}
	}

	/* ---- backfill job ---- */
	$runs = array();
	$bf   = dirname( rtrim( ABSPATH, '/' ) ) . '/yg-cron/lms-backfill.log';
	if ( is_readable( $bf ) ) {
		foreach ( file( $bf ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( preg_match( '/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) UTC\] run: (.*)$/', trim( $line ), $m ) && $m[1] >= $from && $m[1] <= $to ) {
				$runs[] = gmdate( 'H:i', strtotime( $m[1] . ' UTC' ) + YG_LEAD_CHECK_IST ) . ' IST  ' . $m[2];
				if ( preg_match( '/(\d+) saveDetails failed/', $m[2], $f ) && (int) $f[1] > 0 ) {
					$p[] = 'backfill run ' . gmdate( 'H:i', strtotime( $m[1] . ' UTC' ) + YG_LEAD_CHECK_IST ) . ' IST: ' . $f[0];
				}
				if ( preg_match( '/(\d+) push failed/', $m[2], $f ) && (int) $f[1] > 0 ) {
					$p[] = 'backfill run ' . gmdate( 'H:i', strtotime( $m[1] . ' UTC' ) + YG_LEAD_CHECK_IST ) . ' IST: ' . $f[0];
				}
			}
		}
	}
	if ( count( $runs ) < 4 && strtotime( $to . ' UTC' ) < time() - HOUR_IN_SECONDS ) {
		$p[] = 'backfill job ran ' . count( $runs ) . ' times (expected 4: 00:17, 06:17, 12:17, 18:17 IST)';
	}

	/* ---- ad clicks without UTM ---- */
	$tick( 'reading access logs' );
	$ads = array(
		'www.yesgermany.com' => yg_lead_check_ad_visits( 'yesgermany.com', $day ),
		'mh.yesgermany.com'  => yg_lead_check_ad_visits( 'mh.yesgermany.com', $day ),
	);

	/* ---- text ---- */
	$t[] = 'YES Germany - lead check for ' . gmdate( 'D j M Y', $day + YG_LEAD_CHECK_IST ) . ' (India time)';
	$t[] = 'Made ' . gmdate( 'Y-m-d H:i', time() + YG_LEAD_CHECK_IST ) . ' IST by `wp yg-lead-check` on www.yesgermany.com. Read-only.';
	$t[] = '';
	$t[] = 'PROBLEMS: ' . ( $p ? count( $p ) : 'none' );
	foreach ( $p as $line ) {
		$t[] = '  - ' . $line;
	}
	$t[] = '';
	$t[] = 'ENQUIRIES: ' . count( $leads ) . ' total = ' . count( $www ) . ' www, ' . count( $mh ) . ' mh (email only), ' . count( $oth ) . ' other';
	$t[] = '';
	$t[] = '1. www.yesgermany.com enquiries: ' . count( $www ) . ( $cnt['test'] ? ' (' . $cnt['test'] . ' tests)' : '' );
	$t[] = sprintf( '   CRM (saveDetails): %d with CRM ID, %d saved without ID, %d failed, %d not sent', $cnt['id'], $cnt['noid'], $cnt['failed'], $cnt['none'] );
	$t[] = sprintf( '   LMS: %d same source as the site sent, %d different, %d not found, %d could not check; %d German-language (separate LMS)', $cnt['lms_ok'], $cnt['lms_diff'], $cnt['lms_missing'], $cnt['lms_error'], $cnt['german'] );
	$t[] = '';
	$t[] = '   ID    IST    site sends (source)    campaign                     CRM                      LMS';
	foreach ( $rows as $r ) {
		$t[] = '   ' . $r;
	}
	$t[] = '';
	$t[] = '2. mh.yesgermany.com enquiries (email only): ' . count( $mh );
	$t[] = sprintf( '   %d with Google campaign ID, %d Google click without campaign ID, %d Facebook / Instagram click, %d no ad details; %d email not sent', $mh_cnt['campaign'], $mh_cnt['gclid'], $mh_cnt['fb'], $mh_cnt['none'], $mh_cnt['mail_failed'] );
	arsort( $mh_camp );
	foreach ( $mh_camp as $cid => $n ) {
		$t[] = '   campaign ' . $cid . ': ' . $n;
	}
	foreach ( $mh_rows as $r ) {
		$t[] = '   ' . $r;
	}
	$t[] = '';
	$t[] = '3. Hand-built forms via the relay (go to the LMS directly): ' . count( $relay ) . ' sent, ' . count( $relay_bad ) . ' refused by the LMS';
	foreach ( $relay_log as $r ) {
		$t[] = '   failure log: ' . $r;
	}
	if ( $oth ) {
		$t[] = '';
		$t[] = '   Other enquiries stored by the site (not www pages, not mh): ' . count( $oth );
		foreach ( $oth as $l ) {
			$t[] = sprintf( '   %-5d %s  %s', $l['id'], strtok( (string) $l['page_url'], '?' ), strtok( (string) $l['referrer'], '?' ) );
		}
	}
	$t[] = '';
	$t[] = '4. Backfill job runs (mu-plugin 59):';
	foreach ( $runs ? $runs : array( '(none found)' ) as $r ) {
		$t[] = '   ' . $r;
	}
	$t[] = '';
	$t[] = '5. Ad visits WITHOUT utm_source (the ad needs UTM tags in Google Ads / Meta). From the server access logs; bots left out.';
	foreach ( $ads as $site => $a ) {
		if ( ! $a['read'] ) {
			$t[] = '   ' . $site . ': access log not readable';
			continue;
		}
		$t[] = '   ' . $site . ' (' . $a['tagged'] . ' ad visits had UTM tags):';
		if ( ! $a['google'] && ! $a['facebook'] ) {
			$t[] = '     none';
		}
		foreach ( $a['google'] as $cid => $g ) {
			$t[] = sprintf( '     Google %-18s %4d visits, %4d people (IPs), e.g. %s', $cid, $g['visits'], count( $g['ips'] ), $g['page'] );
		}
		if ( $a['facebook'] ) {
			$t[] = sprintf( '     Facebook / Instagram (fbclid)  %4d visits, %4d people (IPs) - fbclid does not say which ad', $a['facebook'], $a['facebook_ips'] );
		}
	}
	$t[] = '';
	$t[] = 'How to read this: "site sends" is the campaign the website gives saveDetails for the enquiry (page URL UTM, else the visitor\'s first ad visit, else the Google / Facebook click), worked out from the stored enquiry with the rules live today. The LMS is asked by phone, then email. A different LMS source for someone who was already in the LMS is usually the LMS keeping their first source (see "secondary"). "saved, CRM gave no ID": saveDetails said it saved the enquiry but returned no CRM lead ID - for the CRM team.';

	return array( 'text' => implode( "\n", $t ) . "\n", 'problems' => count( $p ) );
}

WP_CLI::add_command(
	'yg-lead-check',
	function ( $args, $assoc ) {
		if ( ! function_exists( 'yg_is_production' ) || ! yg_is_production() ) {
			WP_CLI::error( 'not production - the check reads the live CRM log, LMS and access logs; nothing done' );
		}
		foreach ( array( 'yg_lms_find_lead', 'yg_lms_read_token', 'yg_lead_crm_payload_from_lead', 'yg_lead_api_utm_resolve' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				WP_CLI::error( $fn . '() is missing - mu-plugins 45 / 59 not loaded' );
			}
		}
		$date = isset( $assoc['date'] ) ? (string) $assoc['date'] : gmdate( 'Y-m-d', time() + YG_LEAD_CHECK_IST - DAY_IN_SECONDS );
		if ( ! preg_match( '/^\d{4}-\d\d-\d\d$/', $date ) ) {
			WP_CLI::error( '--date must be YYYY-MM-DD' );
		}

		$r = yg_lead_check_report(
			$date,
			function ( $s ) {
				WP_CLI::debug( $s, 'yg-lead-check' );
			}
		);
		WP_CLI::log( $r['text'] );

		$dir = dirname( rtrim( ABSPATH, '/' ) ) . '/yg-cron';
		if ( is_dir( $dir ) && is_writable( $dir ) ) {
			file_put_contents( $dir . '/lead-check-' . $date . '.txt', $r['text'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		if ( ! \WP_CLI\Utils\get_flag_value( $assoc, 'mail', true ) ) {
			return;
		}
		$to = defined( 'YG_LEAD_CHECK_RECIPIENTS' ) ? array_filter( array_map( 'trim', explode( ',', (string) YG_LEAD_CHECK_RECIPIENTS ) ), 'is_email' ) : array();
		if ( ! $to ) {
			WP_CLI::warning( 'YG_LEAD_CHECK_RECIPIENTS is not set in wp-config.php - no mail sent' );
			return;
		}
		$subject = sprintf( 'YES Germany lead check %s: %s', gmdate( 'j M Y', strtotime( $date . ' UTC' ) ), $r['problems'] ? $r['problems'] . ' problem' . ( 1 === $r['problems'] ? '' : 's' ) : 'all fine' );
		$ok      = wp_mail( $to, $subject, $r['text'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
		WP_CLI::log( $ok ? 'mailed to ' . count( $to ) . ' recipient(s)' : 'MAIL FAILED' );
	},
	array(
		'shortdesc' => 'Check one day\'s enquiries end to end (CRM, LMS source, mh, relay, untagged ads) and email the result.',
		'synopsis'  => array(
			array( 'type' => 'assoc', 'name' => 'date', 'optional' => true, 'description' => 'India-time day, YYYY-MM-DD. Default: yesterday.' ),
			array( 'type' => 'flag', 'name' => 'mail', 'optional' => true, 'default' => true, 'description' => 'Email the result (default). --no-mail prints and saves only.' ),
		),
	)
);
