<?php
/** Checked read failures and exact-token release diagnostics stop import driving. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
use AuthorOrUserImage\Import_Sources;
require __DIR__ . '/import-fixtures.php';
global $wpdb;
zmai_it_check( method_exists( Migration::class, 'start_import' ), 'recovery job API exists' );
if ( ! method_exists( Migration::class, 'start_import' ) ) { zmai_it_done(); return; }
$store = new Image_Store();
$migration = new Migration( $store );
$old_signal = get_option( Migration::RELEASE_FAILED_OPTION, null );
$replacement = 'fixture-release-' . wp_generate_uuid4();
wp_set_current_user( 1 );
try {
	if ( null !== $old_signal || get_option( 'zmai_avatar_lock_migration' ) ) { throw new RuntimeException( 'Preexisting recovery state must be reconciled before fixture run.' ); }
	$id = zmai_it_user(); $file = zmai_it_file();
	update_user_meta( $id, 'wp_user_avatars', array( 'full' => $file['url'] ) );
	$sources = new Import_Sources( $store );
	$break_read = static function ( $sql ) {
		return false !== strpos( $sql, "meta_key = 'wp_user_avatars'" ) && 0 === strpos( $sql, 'SELECT meta_value' ) ? 'SELECT zmai_fixture_missing_column FROM zmai_fixture_missing_table' : $sql;
	};
	$suppress = $wpdb->suppress_errors( true );
	add_filter( 'query', $break_read );
	try { $failed = $sources->resolve( 'wp-user-avatars', $id ); } finally { remove_filter( 'query', $break_read ); $wpdb->suppress_errors( $suppress ); }
	zmai_it_check( is_wp_error( $failed ) && 'zmai_storage' === $failed->get_error_code(), 'failed authoritative source read is a typed storage error rather than absence' );
	delete_option( 'zmai_import_job' );
	$job = $migration->start_import( 'wp-user-avatars', wp_generate_uuid4(), 0 );
	$job['last_user_id'] = $id - 1; update_option( 'zmai_import_job', $job, false );
	$lose_token = static function ( $metadata ) use ( $replacement ) { update_option( 'zmai_avatar_lock_migration', $replacement, false ); return $metadata; };
	add_filter( 'wp_generate_attachment_metadata', $lose_token );
	try { $result = $migration->run_import_batch( $job['id'], $job['revision'] ); } finally { remove_filter( 'wp_generate_attachment_metadata', $lose_token ); }
	zmai_it_check( is_wp_error( $result ) && get_option( Migration::RELEASE_FAILED_OPTION ) && $replacement === get_option( 'zmai_avatar_lock_migration' ), 'lost coordinator token reports failure and never deletes a foreign replacement fence' );
	zmai_it_check( is_wp_error( $migration->import_status() ) && is_wp_error( $migration->resume_import( $job['id'], $job['revision'] ) ), 'status and resume cannot claim completion after a failed exact-token release' );
	zmai_it_check( $store->get_user_attachment_id( $id ) > 0 && is_file( $file['path'] ), 'release failure retains committed media and unchanged source for operator reconciliation' );
	// Only this fixture's deliberate replacement token is reconciled. No production takeover.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'zmai_avatar_lock_migration', $replacement ) );
	wp_cache_delete( 'zmai_avatar_lock_migration', 'options' );
	delete_option( Migration::RELEASE_FAILED_OPTION );
	$recovered = $migration->import_status();
	zmai_it_check( is_array( $recovered ) && 'complete' === $recovered['state'], 'only explicit fixture reconciliation reveals the already committed terminal revision' );
	$recovered['state'] = 'recovery_required'; $recovered['last_error_code'] = 'recovery'; $recovered['retry_needed'] = true;
	update_option( 'zmai_import_job', $recovered, false );
	zmai_it_check( $recovered === $migration->resume_import( $recovered['id'], $recovered['revision'] ) && $recovered === $migration->retry_import( $recovered['id'], $recovered['revision'] ), 'retained owner recovery state cannot be bypassed by Resume or Retry' );
} finally {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'zmai_avatar_lock_migration', $replacement ) );
	wp_cache_delete( 'zmai_avatar_lock_migration', 'options' );
	if ( null === $old_signal ) { delete_option( Migration::RELEASE_FAILED_OPTION ); } else { update_option( Migration::RELEASE_FAILED_OPTION, $old_signal, false ); }
	zmai_it_cleanup();
}
zmai_it_done();
