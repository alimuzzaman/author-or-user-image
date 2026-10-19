<?php
/** Real network deletion abort/retry and untouched-site regression. */
use AuthorOrUserImage\Image_Store;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local network required.' );
}
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
$store = new Image_Store();
$failures = array();
$checks = 0;
$expect = static function ( $ok, $label ) use ( &$checks, &$failures ) {
	++$checks;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) { $failures[] = $label; }
};
$prefix = 'net-failure-' . uniqid();
$user = wp_insert_user( array( 'user_login' => $prefix, 'user_email' => $prefix . '@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
$sites = array();
for ( $i = 0; $i < 3; ++$i ) {
	$sites[] = wpmu_create_blog( '127.0.0.1', '/' . $prefix . '-' . $i . '/', 'Network failure fixture', 1 );
}
$name = 'zmai_avatar_lock_user_' . $user;
foreach ( array_slice( $sites, 0, 2 ) as $site ) {
	switch_to_blog( $site );
	update_user_meta( $user, Image_Store::user_meta_key( Image_Store::USER_META ), 0 );
	restore_current_blog();
}
switch_to_blog( $sites[1] );
add_option( $name, 'foreign-worker-token', '', false );
restore_current_blog();
$path = WP_CONTENT_DIR . '/uploads/author-image/' . $prefix . '.jpg';
wp_mkdir_p( dirname( $path ) );
file_put_contents( $path, 'Retained legacy fixture; no import requested.' );
$response = null;
$die = static function () use ( &$response ) {
	return static function ( $message, $title, $args ) use ( &$response ) {
		$response = $args['response'];
		throw new RuntimeException( 'Fixture captured wp_die.' );
	};
};
add_filter( 'wp_die_handler', $die );
try { wpmu_delete_user( $user ); } catch ( RuntimeException $error ) {}
remove_filter( 'wp_die_handler', $die );
$expect( 409 === $response && get_userdata( $user ) && is_file( $path ), 'S2 busy site aborts actual network deletion with 409, account and legacy file retained' );
switch_to_blog( $sites[0] );
$expect( false === get_option( $name ) && ! get_option( 'zmai_avatar_lock_blocks' ), 'S2 busy site partway through releases successful earlier site fences' );
restore_current_blog();
switch_to_blog( $sites[1] );
$expect( 'foreign-worker-token' === get_option( $name ), 'S2 partial failure never releases a foreign site fence' );
delete_option( $name );
restore_current_blog();
$expect( wpmu_delete_user( $user ) && ! get_userdata( $user ) && ! is_file( $path ), 'S2 network deletion retries successfully after the busy worker ends' );
switch_to_blog( $sites[2] );
$expect( false === get_option( 'zm_ai_ban_id' ) && false === get_option( $name ) && ! metadata_exists( 'user', $user, Image_Store::user_meta_key( Image_Store::USER_META ) ), 'S2 untouched site gets no block option, owner fence or suppression metadata' );
restore_current_blog();
// Enumeration and pre-lock probe errors are retryable; cached site queries must
// not inherit an unrelated earlier database error.
$cleanup = new AuthorOrUserImage\User_Cleanup( $store );
$probe_user = wp_insert_user( array( 'user_login' => 'probe-review-' . uniqid(), 'user_pass' => 'public-local-test-fixture' ) );
$enumeration_fault = static function () { throw new RuntimeException( 'Injected enumeration failure.' ); };
add_filter( 'sites_pre_query', $enumeration_fault );
$result = $cleanup->begin_network_user_deletion( $probe_user );
remove_filter( 'sites_pre_query', $enumeration_fault );
$expect( is_wp_error( $result ) && empty( $result->get_error_data()['recovery'] ), 'N-D enumeration failure without a lock is retryable' );
$probe_fault = static function ( $sql ) { return false !== strpos( $sql, 'SELECT (EXISTS' ) ? 'SELECT zmai_missing_column FROM zmai_nonexistent_table' : $sql; };
$previous = $GLOBALS['wpdb']->suppress_errors( true );
add_filter( 'query', $probe_fault );
$result = $cleanup->begin_network_user_deletion( $probe_user );
remove_filter( 'query', $probe_fault );
$GLOBALS['wpdb']->suppress_errors( $previous );
$expect( is_wp_error( $result ) && empty( $result->get_error_data()['recovery'] ), 'N-D pre-lock site probe failure is retryable' );
$GLOBALS['wpdb']->last_error = 'Earlier unrelated query failure';
$result = $cleanup->begin_network_user_deletion( $probe_user );
$expect( true === $result, 'N-D cached site enumeration ignores stale database errors' );
$cleanup->finish_user_deletion( $probe_user );
wpmu_delete_user( $probe_user );
echo wp_json_encode( array( 'checks' => $checks, 'failures' => $failures ) ) . "\n";
WP_CLI::halt( $failures ? 1 : 0 );
