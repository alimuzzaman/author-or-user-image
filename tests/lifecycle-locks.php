<?php
/** Real database lock ownership and failure-path contracts, disposable WP-CLI only. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Privacy;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WordPress is required.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once __DIR__ . '/migration-upload-transport.php';
$store = new Image_Store();
$checks = 0;
$failed = array();
$expect = static function ( $ok, $label ) use ( &$checks, &$failed ) {
	++$checks;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) { $failed[] = $label; }
};
$call = static function ( $method, ...$args ) use ( $store ) {
	$target = $store->get_lock();
	$reflection = new ReflectionMethod( $target, $method );
	if ( PHP_VERSION_ID < 80100 ) { $reflection->setAccessible( true ); }
	return $reflection->invokeArgs( $target, $args );
};
$name = 'zmai_avatar_lock_test_' . uniqid();
$lock = $call( 'acquire_lock', $name );
$expect( is_array( $lock ), 'one plain INSERT grants lock ownership' );
wp_cache_set( 'notoptions', array( $name => true ), 'options' );
$expect( is_wp_error( $call( 'acquire_lock', $name ) ), 'cached absence cannot grant a duplicate lock' );
$wrong = $lock;
$wrong['token'] = strtoupper( $lock['token'] );
$expect( is_wp_error( $call( 'release_lock', $wrong ) ), 'token comparison is byte exact even on case-insensitive collations' );
$expect( is_wp_error( $call( 'acquire_lock', $name ) ), 'wrong-token release leaves original lock held' );
$expect( true === $call( 'release_lock', $lock ), 'original token releases exactly one row' );
$expect( is_wp_error( $call( 'release_lock', $lock ) ), 'already-released token cannot claim another release' );
$lock = $call( 'acquire_lock', $name );
$expect( is_array( $lock ) && $lock['token'] !== $wrong['token'], 'new owner acquires after release' );
$call( 'release_lock', $lock );

$user_id = wp_insert_user( array( 'user_login' => 'zmai-lock-' . uniqid(), 'user_email' => uniqid() . '@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
$owner_name = 'zmai_avatar_lock_user_' . $user_id;
$lock = $call( 'acquire_lock', $owner_name );
$expect( is_wp_error( $store->delete_user_avatar( $user_id ) ), 'busy owner removal is an error' );
$expect( is_wp_error( $store->set_blocked( $user_id, true ) ) && ! $store->is_blocked( $user_id ), 'busy blocking makes no block-state change' );
$privacy = new Privacy( $store );
$erased = $privacy->erase( get_userdata( $user_id )->user_email, 2 );
$expect( ! $erased['done'] && $erased['items_retained'] && ! $erased['items_removed'], 'busy privacy retry on page two remains incomplete' );
$other = wp_insert_user( array( 'user_login' => 'zmai-other-' . uniqid(), 'user_email' => uniqid() . '@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
$expect( ! is_wp_error( $store->delete_user_avatar( $other ) ), 'another owner proceeds while first owner is busy' );
if ( is_multisite() ) {
	$site_id = wpmu_create_blog( '127.0.0.1', '/lock-' . uniqid() . '/', 'Lock isolation fixture', 1 );
	switch_to_blog( $site_id );
	$expect( ! is_wp_error( $store->delete_user_avatar( $user_id ) ), 'same numeric owner on another site proceeds independently' );
	// release_lock must use its captured table, even after a blog switch.
	$expect( true === $call( 'release_lock', $lock ), 'release uses the captured site table after blog switch' );
	restore_current_blog();
} else {
	$call( 'release_lock', $lock );
}
$expect( ! is_wp_error( $store->set_blocked( $user_id, true ) ) && $store->is_blocked( $user_id ), 'block succeeds after owner lock is released' );
$expect( ! is_wp_error( $store->set_blocked( $user_id, false ) ) && ! $store->is_blocked( $user_id ), 'unblock clears canonical and rollback block state' );

// Failed mapping/ownership writes must leave no staged orphan and no false upload success.
$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jVioAAAAASUVORK5CYII=' );
$upload = static function () use ( $store, $user_id, $uploads, $png ) {
	$tmp = $uploads['path'] . '/stage-' . uniqid() . '.png';
	file_put_contents( $tmp, $png );
	$_FILES['zmai_contract'] = array( 'name' => 'avatar.png', 'tmp_name' => $tmp, 'size' => strlen( $png ), 'error' => UPLOAD_ERR_OK );
	$result = $store->save_upload( 'zmai_contract', $user_id );
	wp_delete_file( $tmp );
	unset( $_FILES['zmai_contract'] );
	return $result;
};
$first = $upload();
$expect( ! is_wp_error( $first ) && $store->get_user_attachment_id( $user_id ) === $first, 'serial upload control succeeds before fault injection' );
$deny_mapping = static function ( $check, $id, $key ) use ( $user_id ) {
	return (int) $id === (int) $user_id && Image_Store::user_meta_key( Image_Store::USER_META ) === $key ? true : $check;
};
add_filter( 'update_user_metadata', $deny_mapping, 10, 3 );
$denied = $upload();
remove_filter( 'update_user_metadata', $deny_mapping, 10 );
$owned = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => Image_Store::ATTACHMENT_OWNER_META, 'meta_value' => (string) $user_id ) );
$expect( is_wp_error( $denied ) && $store->get_user_attachment_id( $user_id ) === $first && count( $owned ) === 1 && is_file( get_attached_file( $first ) ), 'failed mapping commit preserves old media and cleans new staging' );
$deny_owner = static function ( $check, $id, $key ) { return Image_Store::ATTACHMENT_OWNER_META === $key ? false : $check; };
add_filter( 'add_post_metadata', $deny_owner, 10, 3 );
$denied = $upload();
remove_filter( 'add_post_metadata', $deny_owner, 10 );
$expect( is_wp_error( $denied ) && $store->get_user_attachment_id( $user_id ) === $first, 'failed creation ownership marker cannot report upload success' );
$staged = 0;
$capture = static function ( $id ) use ( &$staged ) { $staged = $id; };
$veto = static function ( $delete, $post ) use ( $first ) { return (int) $post->ID === (int) $first ? false : $delete; };
add_action( 'add_attachment', $capture );
add_filter( 'pre_delete_attachment', $veto, 10, 2 );
$denied = $upload();
remove_filter( 'pre_delete_attachment', $veto, 10 );
remove_action( 'add_attachment', $capture );
$expect( is_wp_error( $denied ) && $staged && $store->get_user_attachment_id( $user_id ) === $staged && get_post( $first ) && is_file( get_attached_file( $first ) ) && get_post( $staged ) && is_file( get_attached_file( $staged ) ), 'post-commit cleanup failure preserves mapped new files and retryable old row/files' );
$store->set_blocked( $user_id, true );
$denied = $upload();
$expect( is_wp_error( $denied ) && 'zmai_blocked' === $denied->get_error_code() && ! $store->get_user_attachment_id( $user_id ), 'store itself rejects a blocked upload after an earlier admin precheck' );
$store->set_blocked( $user_id, false );

// Exercise the actual WordPress hook interval, after plugin cleanup and before
// core finishes account deletion. Another cooperative mutation must be busy.
$deleting = wp_insert_user( array( 'user_login' => 'zmai-delete-' . uniqid(), 'user_email' => uniqid() . '@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
$during = null;
$probe = static function ( $id ) use ( $deleting, $store, &$during ) {
	if ( (int) $id === (int) $deleting ) { $during = $store->delete_user_avatar( $id ); }
};
add_action( 'delete_user', $probe, 20 );
$deleted = wp_delete_user( $deleting );
remove_action( 'delete_user', $probe, 20 );
$expect( $deleted && is_wp_error( $during ) && 'zmai_busy' === $during->get_error_code(), 'normal core deletion holds the owner fence until deleted_user' );
$after = $call( 'acquire_lock', 'zmai_avatar_lock_user_' . $deleting );
$expect( is_array( $after ), 'deleted_user releases exactly the request-owned fence' );
if ( is_array( $after ) ) { $call( 'release_lock', $after ); }

// Core can delete a row while a file filter prevents physical deletion. That loses
// retry inventory, so this result must retain the non-expiring owner lock.
$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
$path = $uploads['path'] . '/lock-retention-' . uniqid() . '.png';
file_put_contents( $path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jVioAAAAASUVORK5CYII=' ) );
$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'meta_input' => array( Image_Store::ATTACHMENT_OWNER_META => (string) $user_id ) ), $path );
update_user_meta( $user_id, Image_Store::user_meta_key( Image_Store::USER_META ), $id );
$deny = static function () { return ''; };
add_filter( 'wp_delete_file', $deny );
$result = $store->delete_user_avatar( $user_id );
remove_filter( 'wp_delete_file', $deny );
$expect( is_wp_error( $result ) && ! get_post( $id ) && is_file( $path ), 'physical-delete failure is reported even after core removed the row' );
$expect( is_wp_error( $store->delete_user_avatar( $user_id ) ), 'lost file inventory keeps owner fail-closed until operator recovery' );
// Explicit test-only recovery: the synchronous worker has ended; reconcile the
// known retained file before deleting precisely the token that remains in DB.
wp_delete_file( $path );
global $wpdb;
$token = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $owner_name ) );
$recovery = array( 'table' => $wpdb->options, 'name' => $owner_name, 'token' => $token );
$expect( ! is_file( $path ) && true === $call( 'release_lock', $recovery ) && ! is_wp_error( $store->delete_user_avatar( $user_id ) ), 'quiescent exact-token operator recovery restores mutations' );
echo wp_json_encode( array( 'checks' => $checks, 'failures' => $failed ) ) . "\n";
WP_CLI::halt( $failed ? 1 : 0 );
