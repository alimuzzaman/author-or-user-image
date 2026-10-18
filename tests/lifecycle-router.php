<?php
/** Bounded loopback-only lifecycle regression router; never install on a public site. */
if ( ! in_array( $_SERVER['REMOTE_ADDR'], array( '127.0.0.1', '::1' ), true ) || ! is_file( getcwd() . '/.zmai-test-instance' ) ) {
	http_response_code( 403 );
	exit;
}
$zmai_lifecycle_plugin = getenv( 'ZMAI_TEST_PLUGIN' );
$state = getenv( 'ZMAI_TEST_STATE' );
if ( ! $zmai_lifecycle_plugin || ! is_file( $zmai_lifecycle_plugin . '/author_image.php' ) || ! $state || ! is_dir( $state ) ) {
	throw new RuntimeException( 'Explicit candidate and scratch state paths required.' );
}
// WordPress reconstructs these preinitialized filters before loading active plugins.
$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
	'function' => static function ( $zmai_lifecycle_plugins ) {
		return array_values( array_filter( $zmai_lifecycle_plugins, static function ( $file ) { return 'author_image.php' !== basename( $file ); } ) );
	},
	'accepted_args' => 1,
);
define( 'WP_ADMIN', true );
require getcwd() . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || is_multisite() ) {
	http_response_code( 403 );
	exit( 'Disposable single-site local environment required.' );
}
if ( class_exists( 'AuthorOrUserImage\\Image_Store' ) ) {
	throw new RuntimeException( 'Installed plugin was not skipped.' );
}
require $zmai_lifecycle_plugin . '/author_image.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
header( 'Content-Type: application/json' );
$fixture = isset( $_GET['fixture'] ) ? sanitize_key( $_GET['fixture'] ) : '';
if ( ! preg_match( '/^zmailifecycle[a-z0-9]+$/', $fixture ) ) {
	throw new RuntimeException( 'Unique disposable fixture required.' );
}
$mode = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'snapshot';
$default = ! empty( $_GET['default'] );
$login = $fixture . ( $default ? 'default' : 'user' );
$user = get_user_by( 'login', $login );
if ( ! $user ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => 'public-local-test-fixture', 'role' => $default ? 'administrator' : 'subscriber' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$user = get_userdata( $id );
}
wp_set_current_user( $user->ID );
if ( 'bulk' === $mode ) {
	$administrator = get_user_by( 'login', $fixture . 'default' );
	if ( ! $administrator ) { throw new RuntimeException( 'Administrator nonce fixture is required.' ); }
	wp_set_current_user( $administrator->ID );
}
$owner = $default ? 0 : $user->ID;
$store = new AuthorOrUserImage\Image_Store();
$store->register_image_size();

function zmai_lifecycle_owned( $owner ) {
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => AuthorOrUserImage\Image_Store::ATTACHMENT_OWNER_META, 'meta_value' => $owner ? (string) $owner : 'default' ) );
	return array_map( 'intval', $ids );
}
function zmai_lifecycle_barrier( $state, $name, $data ) {
	file_put_contents( $state . '/arrival-' . $name, wp_json_encode( $data ) );
	$deadline = microtime( true ) + 25;
	while ( ! is_file( $state . '/release-' . $name ) ) {
		if ( microtime( true ) >= $deadline ) { throw new RuntimeException( 'Bounded barrier timeout.' ); }
		usleep( 10000 );
	}
}
if ( 'snapshot' === $mode ) {
	$id = $default ? $store->get_default_attachment_id() : $store->get_user_attachment_id( $owner );
	echo wp_json_encode( array( 'mapped' => $id, 'owned' => zmai_lifecycle_owned( $owner ), 'blocked' => $store->is_blocked( $user->ID ), 'user' => $user->ID, 'plugin' => realpath( ZMAI_PATH ), 'cache' => wp_using_ext_object_cache() ) );
	exit;
}
if ( 'nonce' === $mode ) {
	echo wp_json_encode( array( 'nonce' => wp_create_nonce( isset( $_GET['for'] ) ? sanitize_key( $_GET['for'] ) : '' ) ) );
	exit;
}
if ( 'seed' === $mode ) {
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$path = $uploads['path'] . '/' . $fixture . '-' . uniqid() . '.png';
	file_put_contents( $path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jVioAAAAASUVORK5CYII=' ) );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $fixture ), $path );
	$marker = isset( $_GET['foreign'] ) ? 'foreign-' . $fixture : ( $owner ? (string) $owner : 'default' );
	update_post_meta( $id, AuthorOrUserImage\Image_Store::ATTACHMENT_OWNER_META, $marker );
	if ( ! empty( $_GET['map'] ) ) {
		if ( $owner ) { update_user_meta( $owner, AuthorOrUserImage\Image_Store::user_meta_key( AuthorOrUserImage\Image_Store::USER_META ), $id ); }
		else { update_option( AuthorOrUserImage\Image_Store::DEFAULT_OPTION, $id, false ); }
	}
	echo wp_json_encode( array( 'id' => $id, 'path' => $path ) );
	exit;
}
if ( 'exists' === $mode ) {
	$id = absint( $_GET['id'] );
	echo wp_json_encode( array( 'exists' => (bool) get_post( $id ), 'file' => is_file( (string) get_attached_file( $id ) ) ) );
	exit;
}
if ( ! empty( $_GET['denydelete'] ) ) {
	add_filter( 'pre_delete_attachment', static function () { return false; } );
}
if ( 'erase' === $mode ) {
	$privacy = new AuthorOrUserImage\Privacy( $store );
	echo wp_json_encode( $privacy->erase( $user->user_email, isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1 ) );
	exit;
}
if ( 'block' === $mode ) {
	$result = $store->set_blocked( $user->ID, true );
	echo wp_json_encode( array( 'error' => is_wp_error( $result ) ? $result->get_error_code() : '', 'result' => is_wp_error( $result ) ? false : $result ) );
	exit;
}
$barrier = isset( $_GET['barrier'] ) ? sanitize_key( $_GET['barrier'] ) : '';
if ( $barrier && ! preg_match( '/^[a-z0-9]+$/', $barrier ) ) { throw new RuntimeException( 'Invalid barrier.' ); }
// Prime the same metadata cache as Admin::save_avatar before another process changes it.
$store->is_blocked( $user->ID );
if ( $barrier && 'preflight' === ( isset( $_GET['pause'] ) ? $_GET['pause'] : '' ) ) {
	zmai_lifecycle_barrier( $state, $barrier, array( 'cached' => $owner ? $store->get_user_attachment_id( $owner ) : $store->get_default_attachment_id() ) );
}
if ( $barrier && 'media' === ( isset( $_GET['pause'] ) ? $_GET['pause'] : '' ) ) {
	add_filter( 'wp_generate_attachment_metadata', static function ( $metadata, $id ) use ( $state, $barrier ) {
		zmai_lifecycle_barrier( $state, $barrier, array( 'id' => (int) $id ) );
		return $metadata;
	}, 10, 2 );
}
if ( $barrier && 'deletion' === ( isset( $_GET['pause'] ) ? $_GET['pause'] : '' ) ) {
	add_filter( 'pre_delete_attachment', static function ( $delete, $post ) use ( $state, $barrier ) {
		zmai_lifecycle_barrier( $state, $barrier, array( 'id' => (int) $post->ID, 'file' => get_attached_file( $post->ID ) ) );
		return $delete;
	}, 10, 2 );
}
final class Zmai_Lifecycle_Stop extends RuntimeException {}
$location = '';
add_filter( 'wp_redirect', static function ( $url ) use ( &$location ) { $location = $url; throw new Zmai_Lifecycle_Stop(); } );
add_filter( 'wp_die_handler', static function () { return static function () { throw new Zmai_Lifecycle_Stop(); }; } );
try {
	if ( 'bulk' === $mode ) { $_POST['user_ids'] = array( $user->ID ); do_action( 'admin_post_zmai_manage_users' ); }
	elseif ( 'remove' === $mode ) { do_action( 'admin_post_zmai_remove_avatar' ); }
	elseif ( 'upload' === $mode ) { do_action( 'admin_post_zmai_save_avatar' ); }
	else { throw new RuntimeException( 'Unknown mode.' ); }
} catch ( Zmai_Lifecycle_Stop $error ) {
	echo wp_json_encode( array( 'location' => $location ) );
}
