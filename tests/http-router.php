<?php
/** Loopback-only HTTP harness for disposable WordPress upload checks. */
if ( ! in_array( $_SERVER['REMOTE_ADDR'], array( '127.0.0.1', '::1' ), true ) || ! is_file( getcwd() . '/.zmai-test-instance' ) ) {
	http_response_code( 403 );
	exit;
}
define( 'ZMAI_TEST_HTTP_CANDIDATE', dirname( __DIR__ ) );
$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
	'function' => static function ( $plugins ) {
		return array_values( array_filter( $plugins, static function ( $file ) { return 'author_image.php' !== basename( $file ); } ) );
	},
	'accepted_args' => 1,
);
define( 'WP_ADMIN', true );
require getcwd() . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) {
	http_response_code( 403 );
	exit;
}
if ( class_exists( 'AuthorOrUserImage\\Image_Store' ) ) { throw new RuntimeException( 'Installed target plugin was not skipped.' ); }
require ZMAI_TEST_HTTP_CANDIDATE . '/author_image.php';
header( 'Content-Type: application/json' );
$role = isset( $_GET['role'] ) && 'admin' === $_GET['role'] ? 'administrator' : 'subscriber';
$user = get_user_by( 'login', 'zmai-http-' . $role );
if ( ! $user ) {
	$id = wp_insert_user( array( 'user_login' => 'zmai-http-' . $role, 'user_email' => 'zmai-http-' . $role . '@example.invalid', 'user_pass' => 'public-local-test-fixture', 'role' => $role ) );
	$user = get_userdata( $id );
}
wp_set_current_user( $user->ID );
$store = new AuthorOrUserImage\Image_Store();
$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'snapshot';
if ( 'reset-rate-fixture' === $action ) {
	delete_user_meta( $user->ID, AuthorOrUserImage\Image_Store::user_meta_key( AuthorOrUserImage\Image_Store::UPLOAD_RATE_META ) );
	echo '{}';
	exit;
}
if ( 'policy-fixture' === $action ) {
	echo wp_json_encode( ( new AuthorOrUserImage\Upload_Policy( $store ) )->get_policy() );
	exit;
}
if ( 'nonce' === $action ) {
	echo wp_json_encode( array( 'nonce' => wp_create_nonce( sanitize_key( $_GET['for'] ) ) ) );
	exit;
}
if ( 'snapshot' === $action ) {
	echo wp_json_encode( array( 'id' => $store->get_user_attachment_id( $user->ID ), 'default' => $store->get_default_attachment_id(), 'blocked' => $store->is_blocked( $user->ID ), 'count' => count( get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => -1 ) ) ) ) );
	exit;
}
if ( 'block-fixture' === $action ) {
	$store->set_blocked( $user->ID, true );
	echo '{}';
	exit;
}
if ( 'unblock-fixture' === $action ) {
	$store->set_blocked( $user->ID, false );
	echo '{}';
	exit;
}
final class Zmai_Test_Stop extends RuntimeException {}
$zmai_location = '';
add_filter( 'wp_redirect', static function ( $location ) use ( &$zmai_location ) {
	$zmai_location = $location;
	throw new Zmai_Test_Stop();
} );
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		http_response_code( isset( $args['response'] ) ? $args['response'] : 403 );
		throw new Zmai_Test_Stop();
	};
} );
try {
	do_action( 'admin_post_' . $action );
} catch ( Zmai_Test_Stop $e ) {
	echo wp_json_encode( array( 'location' => $zmai_location ) );
}
