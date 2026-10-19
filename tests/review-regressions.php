<?php
/** Review #1 lifecycle regressions, disposable local WordPress only. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Privacy;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WordPress is required.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; }
require_once __DIR__ . '/migration-upload-transport.php';
$store = new Image_Store();
$checks = 0;
$failures = array();
$expect = static function ( $ok, $label ) use ( &$checks, &$failures ) {
	++$checks;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) { $failures[] = $label; }
};
$call = static function ( $method, ...$args ) use ( $store ) {
	$target = $store->get_lock();
	$ref = new ReflectionMethod( $target, $method );
	if ( PHP_VERSION_ID < 80100 ) { $ref->setAccessible( true ); }
	return $ref->invokeArgs( $target, $args );
};
$token = static function ( $key ) {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) );
	return null === $value ? false : $value;
};
$user = wp_insert_user( array( 'user_login' => 'review-' . uniqid(), 'user_email' => uniqid() . '@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
$email = get_userdata( $user )->user_email;
$name = 'zmai_avatar_lock_user_' . $user;
$lock = $call( 'acquire_lock', $name );
$privacy = new Privacy( $store );
$erased = $privacy->erase( $email, 5 );
$expect( $erased['done'] && ! $erased['items_removed'] && $erased['items_retained'] && $erased['messages'], 'S1 leftover lock stops retrying at the bounded page limit without claiming removal' );
$wrong = $lock;
$wrong['token'] .= 'different';
$expect( is_wp_error( $call( 'release_lock', $wrong ) ) && $token( $name ) === $lock['token'], 'different same-case token cannot release another owner' );
$call( 'release_lock', $lock );

// Recovery after an arbitrary callback exception remains fail-closed.
$error = $call( 'with_owner_lock', $user, static function () { throw new RuntimeException( 'Injected unknown callback failure.' ); } );
$data = is_wp_error( $error ) ? $error->get_error_data() : array();
$expect( is_wp_error( $error ) && ! empty( $data['recovery'] ) && $token( $name ), 'unknown exception inside a locked callback retains a recovery fence' );
$erased = $privacy->erase( $email, 5 );
$expect( $erased['done'] && $erased['items_retained'] && ! $erased['items_removed'], 'S1 recovery fence terminates privacy erasure without false success' );
$call( 'release_lock', array( 'table' => $GLOBALS['wpdb']->options, 'name' => $name, 'token' => $token( $name ) ) );

// Real DB read error before any mutation must not strand the mutex.
$read_fault = static function ( $sql ) {
	return preg_match( '/^SELECT ID FROM .*users.* WHERE ID = /', $sql ) ? 'SELECT zmai_missing_column FROM zmai_nonexistent_table' : $sql;
};
$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
$path = $uploads['path'] . '/review-' . uniqid() . '.png';
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=' );
file_put_contents( $path, $png );
$_FILES['zmai_review'] = array( 'name' => 'avatar.png', 'tmp_name' => $path, 'size' => strlen( $png ), 'error' => UPLOAD_ERR_OK );
$previous = $GLOBALS['wpdb']->suppress_errors( true );
add_filter( 'query', $read_fault );
$error = $store->save_upload( 'zmai_review', $user );
remove_filter( 'query', $read_fault );
$GLOBALS['wpdb']->suppress_errors( $previous );
$data = is_wp_error( $error ) ? $error->get_error_data() : array();
$expect( is_wp_error( $error ) && empty( $data['recovery'] ) && ! $token( $name ), 'N2 authoritative read failure before mutation releases the owner mutex' );
if ( $token( $name ) ) { $call( 'release_lock', array( 'table' => $GLOBALS['wpdb']->options, 'name' => $name, 'token' => $token( $name ) ) ); }
wp_delete_file( $path );
unset( $_FILES['zmai_review'] );

// An identified read error after a write must still retain the recovery fence.
$postwrite_fault = static function ( $sql ) {
	return false !== strpos( $sql, 'SELECT meta_value' ) && false !== strpos( $sql, Image_Store::user_meta_key( Image_Store::BLOCKED_META ) ) ? 'SELECT zmai_missing_column FROM zmai_nonexistent_table' : $sql;
};
$previous = $GLOBALS['wpdb']->suppress_errors( true );
add_filter( 'query', $postwrite_fault );
$error = $store->set_blocked( $user, true );
remove_filter( 'query', $postwrite_fault );
$GLOBALS['wpdb']->suppress_errors( $previous );
$data = is_wp_error( $error ) ? $error->get_error_data() : array();
$expect( is_wp_error( $error ) && ! empty( $data['recovery'] ) && $token( $name ) && $token( 'zmai_avatar_lock_blocks' ), 'N2 post-mutation read failure keeps both recovery fences' );
foreach ( array( $name, 'zmai_avatar_lock_blocks' ) as $key ) {
	$call( 'release_lock', array( 'table' => $GLOBALS['wpdb']->options, 'name' => $key, 'token' => $token( $key ) ) );
}
$store->set_blocked( $user, false );

// A lost file inventory returns a terminal privacy failure on the first page.
$retained_path = $uploads['path'] . '/review-retained-' . uniqid() . '.png';
file_put_contents( $retained_path, $png );
$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'meta_input' => array( Image_Store::ATTACHMENT_OWNER_META => (string) $user ) ), $retained_path );
update_user_meta( $user, Image_Store::user_meta_key( Image_Store::USER_META ), $attachment );
$retain_file = static function () { return ''; };
add_filter( 'wp_delete_file', $retain_file );
$erased = $privacy->erase( $email, 1 );
remove_filter( 'wp_delete_file', $retain_file );
$expect( $erased['done'] && ! $erased['items_removed'] && $erased['items_retained'] && $erased['messages'] && is_file( $retained_path ) && ! get_post( $attachment ), 'S1 persistent recovery-required erasure stops on page one and reports retained files' );
wp_delete_file( $retained_path );
foreach ( array( $name, 'zmai_avatar_lock_blocks' ) as $key ) {
	$call( 'release_lock', array( 'table' => $GLOBALS['wpdb']->options, 'name' => $key, 'token' => $token( $key ) ) );
}

// A migration decision-read failure must not escape admin_init/activation.
$old_version = get_option( Image_Store::VERSION_OPTION, null );
delete_option( Image_Store::VERSION_OPTION );
$migration_fault = static function ( $sql ) {
	return false !== strpos( $sql, 'SELECT option_value' ) && false !== strpos( $sql, "'zmai_data_version'" ) ? 'SELECT zmai_missing_column FROM zmai_nonexistent_table' : $sql;
};
$caught = false;
$previous = $GLOBALS['wpdb']->suppress_errors( true );
add_filter( 'query', $migration_fault );
try { ( new AuthorOrUserImage\Migration( $store ) )->maybe_migrate(); } catch ( Throwable $error ) { $caught = true; }
remove_filter( 'query', $migration_fault );
$GLOBALS['wpdb']->suppress_errors( $previous );
$expect( ! $caught && ! $token( 'zmai_avatar_lock_migration' ), 'N3 migration read failure does not fatal and releases its mutex' );

// A failed migration release must be observable, while leaving the foreign token.
$observed = array();
$observer = static function ( $error ) use ( &$observed ) { $observed[] = $error; };
add_action( 'zmai_migration_error', $observer );
$release_fault = static function ( $sql ) {
	return false !== strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, "'zmai_avatar_lock_migration'" ) ? $sql . ' AND 1 = 0' : $sql;
};
update_option( Image_Store::VERSION_OPTION, ZMAI_VERSION );
$acquired = $call( 'acquire_lock', 'zmai_avatar_lock_migration' );
$call( 'release_lock', $acquired );
delete_option( Image_Store::VERSION_OPTION );
add_filter( 'query', $release_fault );
try { ( new AuthorOrUserImage\Migration( $store ) )->maybe_migrate(); } catch ( Throwable $error ) { $caught = true; }
remove_filter( 'query', $release_fault );
remove_action( 'zmai_migration_error', $observer );
$expect( count( $observed ) > 0 && $token( 'zmai_avatar_lock_migration' ), 'N3 failed migration mutex release is surfaced rather than silently stalling' );
// A later request must retain an administrator-visible, non-dismissable signal.
$migration = new AuthorOrUserImage\Migration( $store );
$migration->maybe_migrate();
$old_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
ob_start();
if ( method_exists( $migration, 'migration_notice' ) ) { $migration->migration_notice(); }
$notice = ob_get_clean();
$expect( false !== strpos( $notice, 'administrator recovery' ) && false === strpos( $notice, 'is-dismissible' ), 'N-B failed migration release remains visible to administrators on later requests' );
wp_set_current_user( $user );
ob_start();
if ( method_exists( $migration, 'migration_notice' ) ) { $migration->migration_notice(); }
$expect( '' === ob_get_clean(), 'N-B migration recovery notice is hidden from users without manage_options' );
wp_set_current_user( $old_user );
$migration_token = $token( 'zmai_avatar_lock_migration' );
if ( $migration_token ) { $call( 'release_lock', array( 'table' => $GLOBALS['wpdb']->options, 'name' => 'zmai_avatar_lock_migration', 'token' => $migration_token ) ); }
$migration->maybe_migrate();
wp_set_current_user( $admins[0]->ID );
ob_start();
if ( method_exists( $migration, 'migration_notice' ) ) { $migration->migration_notice(); }
$expect( '' === ob_get_clean() && ! get_option( 'zmai_migration_release_failed' ), 'N-B recovery notice clears after the failed fence is removed even when migration version is current' );
wp_set_current_user( $old_user );
if ( null === $old_version ) { delete_option( Image_Store::VERSION_OPTION ); } else { update_option( Image_Store::VERSION_OPTION, $old_version ); }

// A completed core deletion must not abort the rest of a bulk operation.
$bulk_users = array();
for ( $i = 0; $i < 2; ++$i ) {
 $bulk_users[] = wp_insert_user( array( 'user_login' => 'bulk-review-' . uniqid(), 'user_pass' => 'public-local-test-fixture' ) );
}
$bulk_name = 'zmai_avatar_lock_user_' . $bulk_users[0];
$bulk_table = $GLOBALS['wpdb']->options;
$bulk_fault = static function ( $sql ) use ( $bulk_name, $bulk_table ) {
 return false !== strpos( $sql, $bulk_table ) && false !== strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, "'" . $bulk_name . "'" ) ? $sql . ' AND 1 = 0' : $sql;
};
$die = static function () { return static function () { throw new RuntimeException( 'Unexpected post-delete wp_die.' ); }; };
add_filter( 'query', $bulk_fault );
add_filter( 'wp_die_handler', $die );
$bulk_caught = false;
try { foreach ( $bulk_users as $bulk_user ) { if ( is_multisite() ) { wpmu_delete_user( $bulk_user ); } else { wp_delete_user( $bulk_user ); } } } catch ( RuntimeException $error ) { $bulk_caught = true; }
remove_filter( 'wp_die_handler', $die );
remove_filter( 'query', $bulk_fault );
$expect( ! $bulk_caught && ! get_userdata( $bulk_users[0] ) && ! get_userdata( $bulk_users[1] ) && $token( $bulk_name ), 'N-C failed post-delete release retains its fence and bulk deletion continues' );
if ( $token( $bulk_name ) ) { $call( 'release_lock', array( 'table' => $GLOBALS['wpdb']->options, 'name' => $bulk_name, 'token' => $token( $bulk_name ) ) ); }
foreach ( $bulk_users as $bulk_user ) { if ( get_userdata( $bulk_user ) ) { if ( is_multisite() ) { wpmu_delete_user( $bulk_user ); } else { wp_delete_user( $bulk_user ); } } }

// Uninstall only removes locks when that site's data cleanup was opted in.
$leftover = 'zmai_avatar_lock_review_uninstall';
add_option( $leftover, 'test-only-leftover', '', false );
$opt_in = get_option( Image_Store::DELETE_DATA_OPTION, null );
update_option( 'zmai_upload_policy', array( 'schema' => 1, 'mode' => 'roles', 'roles' => array() ), false );
update_option( 'zmai_import_job', array( 'schema' => 1 ), false );
update_user_meta( $user, Image_Store::user_meta_key( Image_Store::UPLOAD_RATE_META ), array( 'minute_start' => time(), 'minute_count' => 1, 'hour_start' => time(), 'hour_count' => 1 ) );
delete_option( Image_Store::DELETE_DATA_OPTION );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'author-or-user-image/author_image.php' ); }
include ZMAI_PATH . 'uninstall.php';
$expect( 'test-only-leftover' === get_option( $leftover ), 'N4 opted-out uninstall preserves a leftover lock' );
$expect( get_option( 'zmai_upload_policy' ) && get_option( 'zmai_import_job' ) && metadata_exists( 'user', $user, Image_Store::user_meta_key( Image_Store::UPLOAD_RATE_META ) ), 'phase1 opted-out uninstall preserves policy, job and rate data' );
update_option( Image_Store::DELETE_DATA_OPTION, 1 );
zmai_uninstall_site_data();
$expect( false === get_option( $leftover ), 'N4 opted-in uninstall removes leftover lock rows' );
$expect( ! get_option( 'zmai_upload_policy' ) && ! get_option( 'zmai_import_job' ) && ! metadata_exists( 'user', $user, Image_Store::user_meta_key( Image_Store::UPLOAD_RATE_META ) ), 'phase1 opted-in uninstall removes policy, job and scoped rate data' );
if ( null !== $opt_in ) { update_option( Image_Store::DELETE_DATA_OPTION, $opt_in ); }
delete_option( $leftover );
wp_delete_user( $user );
echo wp_json_encode( array( 'checks' => $checks, 'failures' => $failures ) ) . "\n";
WP_CLI::halt( $failures ? 1 : 0 );
