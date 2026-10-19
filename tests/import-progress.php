<?php
/** Revisioned, bounded importer jobs against real WordPress option persistence. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
require __DIR__ . '/import-fixtures.php';
global $wpdb;
zmai_it_check( method_exists( Migration::class, 'start_import' ), 'job contract exists before implementation' );
if ( ! method_exists( Migration::class, 'start_import' ) ) { zmai_it_done(); return; }
wp_set_current_user( 1 );
$store = new Image_Store();
$migration = new Migration( $store );
$source = 'wp-user-avatars';
$http_count = 0;
$deny_http = static function () use ( &$http_count ) { ++$http_count; return new WP_Error( 'test_http_forbidden' ); };
add_filter( 'pre_http_request', $deny_http );
try {
	delete_option( 'zmai_import_job' );
	zmai_it_check( null === $migration->import_status() && false === get_option( 'zmai_import_job', false ), 'missing job stays idle without GET writes' );
	$bad = $migration->start_import( 'arbitrary-class', wp_generate_uuid4(), 0 );
	zmai_it_check( is_wp_error( $bad ), 'source registry rejects arbitrary identifiers' );
	$file = zmai_it_owned_file();
	$hash = hash_file( 'sha256', $file['path'] );
	$users = array();
	for ( $i = 0; $i < 48; ++$i ) {
		$id = zmai_it_user(); $users[] = $id;
		update_user_meta( $id, 'wp_user_avatars', array( 'full' => $file['url'] ) );
	}
	$bad_user = $users[0];
	update_user_meta( $bad_user, 'wp_user_avatars', array( 'full' => $file['url'] . '.missing' ) );
	$store->set_blocked( $users[1], true );
	$store->write_value( $users[2], Image_Store::USER_META, '', 0 );
	$busy = $store->get_lock()->acquire_lock( 'zmai_avatar_lock_user_' . $users[3] );
	$token = wp_generate_uuid4();
	$job = $migration->start_import( $source, $token, 0 );
	zmai_it_check( ! is_wp_error( $job ) && $token === $job['id'] && 1 === $job['revision'], 'start snapshots an explicit revisioned job' );
	$replay = $migration->start_import( $source, $token, 0 );
	zmai_it_check( $job === $replay, 'start run token replay is idempotent' );
	$conflict = $migration->start_import( 'one-user-avatar', wp_generate_uuid4(), $job['revision'] );
	zmai_it_check( is_wp_error( $conflict ), 'active job cannot switch sources' );
	$stale_start = $migration->start_import( 'one-user-avatar', wp_generate_uuid4(), 0 );
	zmai_it_check( is_wp_error( $stale_start ) && 'zmai_import_request' === $stale_start->get_error_code() && $job === $migration->import_status(), 'stale_start_does_not_continue_other_source: typed conflict leaves job and counts unchanged' );
	$controller = new AuthorOrUserImage\Import_Admin( $migration );
	$stale_response = $controller->process_request( 'start', array( 'site_id' => (string) get_current_blog_id(), 'revision' => '0', 'source' => 'one-user-avatar', 'run_token' => wp_generate_uuid4(), '_wpnonce' => wp_create_nonce( 'zmai_import_start' ) ), 'POST' );
	zmai_it_check( is_wp_error( $stale_response ) && $job === $migration->import_status() && 0 === $store->get_user_attachment_id( $users[47] ), 'stale_start_does_not_continue_other_source: admin dispatch returns no successful job or imported media' );
	$wrong_source_replay = $migration->start_import( 'one-user-avatar', $token, 0 );
	zmai_it_check( is_wp_error( $wrong_source_replay ) && $job === $migration->import_status(), 'Start token replay cannot change its bound source' );
	$late = zmai_it_user(); update_user_meta( $late, 'wp_user_avatars', array( 'full' => $file['url'] ) );
	$upper = $job['upper_user_id'];
	$first = $migration->run_import_batch( $job['id'], $job['revision'] );
	zmai_it_check( ! is_wp_error( $first ) && $first['counts']['inspected'] <= 20 && $first['revision'] === $job['revision'] + 1, 'one POST advances at most twenty owners' );
	$stale = $migration->run_import_batch( $job['id'], $job['revision'] );
	zmai_it_check( $first === $stale, 'stale batch cannot advance twice' );
	if ( is_multisite() ) { remove_user_from_blog( $users[40], get_current_blog_id() ); }
	if ( is_multisite() ) { wpmu_delete_user( $users[41] ); } else { wp_delete_user( $users[41] ); }
	$paused = $migration->pause_import( $first['id'], $first['revision'] );
	$still = $migration->run_import_batch( $paused['id'], $paused['revision'] );
	zmai_it_check( $paused === $still && 'paused' === $still['state'], 'pause prevents any further batch work' );
	$job = $migration->resume_import( $paused['id'], $paused['revision'] );
	for ( $n = 0; $n < 30 && 'running' === $job['state']; ++$n ) { $job = $migration->run_import_batch( $job['id'], $job['revision'] ); if ( is_wp_error( $job ) ) { throw new RuntimeException( $job->get_error_code() ); } }
	zmai_it_check( 'paused_with_failures' === $job['state'] && $job['retry_needed'] && $job['counts']['failed'] >= 1 && ( $job['counts']['skipped'] ?? 0 ) >= 1, 'busy owners pause with failures and missing owners are permanent skips' );
	zmai_it_check( $store->get_user_attachment_id( $users[47] ) > 0 && 0 === $store->get_user_attachment_id( $bad_user ), 'failed early owners do not prevent later pages' );
	zmai_it_check( $job['upper_user_id'] === $upper && 0 === $store->get_user_attachment_id( $late ), 'new users after snapshot wait for another explicit run' );
	zmai_it_check( $job['counts']['blocked'] >= 1 && $job['counts']['suppressed'] >= 1, 'blocks and explicit zero retain separate counts' );
	zmai_it_check( ! get_userdata( $users[41] ) && ! $store->get_user_attachment_id( $users[41] ), 'deleted user ID gaps do not stop keyset progress' );
	if ( is_multisite() ) { zmai_it_check( ! $store->get_user_attachment_id( $users[40] ), 'removed site membership is excluded from later pages' ); }
	$store->get_lock()->release_lock( $busy );
	update_user_meta( $bad_user, 'wp_user_avatars', array( 'full' => $file['url'] ) );
	$job = $migration->retry_import( $job['id'], $job['revision'] );
	zmai_it_check( 2 === $job['sweep'] && 0 === $job['last_user_id'] && 0 === $job['counts']['inspected'], 'explicit repair retry begins fresh bounded sweep' );
	for ( $n = 0; $n < 30 && 'running' === $job['state']; ++$n ) { $job = $migration->run_import_batch( $job['id'], $job['revision'] ); }
	zmai_it_check( 'complete' === $job['state'] && 0 === $job['counts']['failed'] && '' === $job['last_error_code'], 'repaired sweep completes without retained operational errors' );
	zmai_it_check( 0 === $job['last_user_id'] && 0 === $job['upper_user_id'], 'finished job clears operational owner cursors and retains aggregate counts' );
	zmai_it_check( $store->get_user_attachment_id( $bad_user ) > 0 && $job['counts']['existing'] >= 40, 'retry preserves successful mappings and imports repaired owners' );
	zmai_it_check( $job === $migration->run_import_batch( $job['id'], $job['revision'] ), 'completed jobs never repeat work' );
	zmai_it_check( hash_file( 'sha256', $file['path'] ) === $hash && array( 'full' => $file['url'] ) === get_user_meta( $users[47], 'wp_user_avatars', true ), 'success and retry leave source bytes and records unchanged' );
	$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, 'zmai_import_job' ) );
	zmai_it_check( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), 'aggregate job option is not autoloaded' );
	delete_option( 'zmai_import_job' );
	$job = $migration->start_import( $source, wp_generate_uuid4(), 0 );
	$deny_write = static function ( $value, $old ) { return $old; };
	add_filter( 'pre_update_option_zmai_import_job', $deny_write, 10, 2 );
	$failed = $migration->run_import_batch( $job['id'], $job['revision'] );
	remove_filter( 'pre_update_option_zmai_import_job', $deny_write );
	zmai_it_check( is_wp_error( $failed ) && $migration->import_status() === $job, 'failed checked cursor persistence cannot record completion' );
	update_option( 'zmai_import_job', array( 'schema' => 99 ), false );
	zmai_it_check( is_wp_error( $migration->import_status() ) && array( 'schema' => 99 ) === get_option( 'zmai_import_job' ), 'schema mismatch pauses safely without discarding progress' );
	zmai_it_check( 0 === $http_count, 'all competitor import work makes zero HTTP requests' );
} finally {
	remove_filter( 'pre_http_request', $deny_http );
	if ( isset( $busy ) && is_array( $busy ) ) { $store->get_lock()->release_lock( $busy ); }
	zmai_it_cleanup();
}
zmai_it_done();
