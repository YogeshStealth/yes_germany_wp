<?php
/*
 * One-off content fix, run on STAGING, then Publish to Live.
 *
 * Points the hand-built LMS forms in Elementor HTML widgets at the server relay
 * (/wp-json/yg/v1/lms-lead, mu-plugin 55) and removes the api-key from the page.
 * Run only once mu-plugin 55 is deployed to production, or the published forms
 * would post to a route that does not exist yet.
 *
 *   wp eval-file 2026-10-01-lms-forms-to-relay.php          # dry run, prints JSON (key redacted)
 *   wp eval-file 2026-10-01-lms-forms-to-relay.php apply    # writes
 *
 * Apply saves every changed _elementor_data to the option
 * yg_lms_relay_backup_<UTC timestamp> first. A post is left untouched if any key
 * or LMS URL would remain in it.
 */
global $wpdb; $P = $wpdb->prefix;
$apply = in_array( 'apply', (array) ( $args ?? array() ), true );
$RELAY = '/wp-json/yg/v1/lms-lead';

function yg_rw_code( $c, $relay ) {
	$c = preg_replace( '#https://(?:lms|crm-backend)\.yesgermany\.org/api/v1/lead-management/lead-widget/website#', $relay, $c );
	$c = str_replace( '${baseUrl}/api/v1/lead-management/lead-widget/website', $relay, $c );
	// header lines, live or commented out
	$c = preg_replace( '#^[ \t]*(?://[ \t]*)?[\w.]*setRequestHeader\(\s*["\']api-key["\']\s*,\s*["\'][^"\']*["\']\s*\);[^\n]*\r?\n#mi', '', $c );
	// "api-key": "...", inside a headers object
	$c = preg_replace( '#^[ \t]*["\']api-key["\']\s*:\s*["\'][^"\']*["\']\s*,?[ \t]*\r?\n#mi', '', $c );
	// One lead, one post: the second copy only ever went to the dead hostname.
	$c = preg_replace( '#^([ \t]*)(xhrCRM\.send\()#m', '$1// $2', $c );
	return $c;
}
$rows = $wpdb->get_results( "SELECT p.ID, p.post_name, p.post_status, m.meta_id, m.meta_value FROM {$P}postmeta m JOIN {$P}posts p ON p.ID=m.post_id WHERE m.meta_key='_elementor_data' AND m.meta_value LIKE '%lead-widget%' AND p.post_type IN ('page','post','elementor_library') AND p.post_status IN ('publish','draft','private','pending','future') ORDER BY p.ID", ARRAY_A );
$report = array(); $backup = array(); $bad = 0; $changed = 0;
foreach ( $rows as $r ) {
	$data = json_decode( $r['meta_value'], true );
	if ( ! is_array( $data ) ) { $report[] = array( 'ID' => $r['ID'], 'error' => 'json' ); $bad++; continue; }
	$touched = array();
	$walk = function ( &$els ) use ( &$walk, &$touched, $RELAY ) {
		foreach ( $els as &$e ) {
			foreach ( array( 'html', 'editor', 'custom_html' ) as $k ) {
				if ( isset( $e['settings'][ $k ] ) && is_string( $e['settings'][ $k ] ) && false !== strpos( $e['settings'][ $k ], 'lead-widget' ) ) {
					$new = yg_rw_code( $e['settings'][ $k ], $RELAY );
					if ( $new !== $e['settings'][ $k ] ) { $e['settings'][ $k ] = $new; $touched[] = array( 'el' => $e['id'], 'code' => $new ); }
				}
			}
			if ( ! empty( $e['elements'] ) ) $walk( $e['elements'] );
		}
	};
	$walk( $data );
	$json = wp_json_encode( $data );
	$left = preg_match_all( '/ApiKey[A-Za-z0-9_\-]{6,}/', $json ) + preg_match_all( '#lead-widget\\\\?/website#', $json );
	$report[] = array( 'ID' => $r['ID'], 'slug' => $r['post_name'], 'status' => $r['post_status'], 'widgets' => count( $touched ), 'left' => $left,
		'codes' => array_map( function ( $t ) { return preg_replace( '/ApiKey[A-Za-z0-9_\-]+/', 'ApiKeyREDACTED', $t['code'] ); }, $touched ) );
	if ( $left ) { $bad++; continue; }
	if ( $touched && $apply ) {
		$backup[ $r['ID'] ] = $r['meta_value'];
		$wpdb->update( "{$P}postmeta", array( 'meta_value' => $json ), array( 'meta_id' => $r['meta_id'] ) );
		clean_post_cache( (int) $r['ID'] );
		$changed++;
	}
}
if ( $apply && $backup ) {
	add_option( 'yg_lms_relay_backup_' . gmdate( 'Ymd_His' ), $backup, '', 'no' );
}
fwrite( STDERR, sprintf( "rows=%d changed=%d not-clean=%d apply=%s\n", count( $rows ), $changed, $bad, $apply ? 'yes' : 'no' ) );
echo wp_json_encode( $report );
