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
$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10][] = array(
	'function' => static function ( $plugins ) {
		return array_filter( $plugins, static function ( $file ) { return 'author_image.php' !== basename( $file ); }, ARRAY_FILTER_USE_KEY );
	},
	'accepted_args' => 1,
);
define( 'WP_ADMIN', true );
$zmai_orig_uri  = $_SERVER['REQUEST_URI'] ?? '/';
$zmai_wp_config = getcwd() . '/wp-config.php';
if ( is_file( $zmai_wp_config ) ) {
	$zmai_cfg = (string) file_get_contents( $zmai_wp_config );
	if ( preg_match( "/define\s*\(\s*['\"]MULTISITE['\"]\s*,\s*true\s*\)/i", $zmai_cfg ) ) {
		if ( preg_match( "/define\s*\(\s*['\"]DOMAIN_CURRENT_SITE['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $zmai_cfg, $zmai_domain ) ) {
			$_SERVER['HTTP_HOST'] = $zmai_domain[1];
		}
		if ( preg_match( "/define\s*\(\s*['\"]PATH_CURRENT_SITE['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $zmai_cfg, $zmai_path ) ) {
			$_SERVER['REQUEST_URI'] = $zmai_path[1];
		}
	}
}
require getcwd() . '/wp-load.php';
$_SERVER['REQUEST_URI'] = $zmai_orig_uri;
if ( 'local' !== wp_get_environment_type() ) {
	http_response_code( 403 );
	exit;
}
if ( class_exists( 'AuthorOrUserImage\\Image_Store' ) ) { throw new RuntimeException( 'Installed target plugin was not skipped.' ); }
require ZMAI_TEST_HTTP_CANDIDATE . '/author_image.php';
header( 'Content-Type: application/json' );
$zmai_blog_id = isset( $_GET['blog_id'] ) ? absint( $_GET['blog_id'] ) : 0;
if ( is_multisite() && $zmai_blog_id > 1 ) {
	switch_to_blog( $zmai_blog_id );
	register_shutdown_function( 'restore_current_blog' );
}
$role = isset( $_GET['role'] ) && 'admin' === $_GET['role'] ? 'administrator' : 'subscriber';
$user = get_user_by( 'login', 'zmai-http-' . $role );
if ( ! $user ) {
	$id = wp_insert_user( array( 'user_login' => 'zmai-http-' . $role, 'user_email' => 'zmai-http-' . $role . '@example.invalid', 'user_pass' => 'public-local-test-fixture', 'role' => $role ) );
	$user = get_userdata( $id );
}
if ( is_multisite() && $user && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
	add_user_to_blog( get_current_blog_id(), $user->ID, $role );
}
wp_set_current_user( $user->ID );
$store = new AuthorOrUserImage\Image_Store();
$metadata_editor = isset( $_GET['metadata_editor'] ) ? $_GET['metadata_editor'] : '';
if ( in_array( $metadata_editor, array( 'GD', 'Imagick' ), true ) ) {
	add_filter( 'wp_image_editors', static function () use ( $metadata_editor ) { return array( 'WP_Image_Editor_' . $metadata_editor ); } );
	add_filter( 'image_strip_meta', '__return_false', PHP_INT_MAX );
}
$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'snapshot';
if ( 'metadata-inventory' === $action ) {
	$id = isset( $_GET['default'] ) ? $store->get_default_attachment_id() : $store->get_user_attachment_id( $user->ID );
	$path = get_attached_file( $id );
	$meta = wp_get_attachment_metadata( $id );
	$paths = $path ? array( $path ) : array();
	foreach ( $meta['sizes'] ?? array() as $size ) { $paths[] = dirname( $path ) . '/' . $size['file']; }
	if ( isset( $meta['original_image'] ) ) { $paths[] = dirname( $path ) . '/' . $meta['original_image']; }
	$clean = $id > 0 && count( $paths ) > 0;
	foreach ( array_unique( $paths ) as $file ) {
		$clean = $clean && is_file( $file ) && false === strpos( file_get_contents( $file ), 'PRIVATE-PHOTO-FIX3' );
	}
	echo wp_json_encode( array( 'clean' => $clean, 'files' => count( array_unique( $paths ) ), 'imagick' => extension_loaded( 'imagick' ), 'image_meta' => $meta['image_meta'] ?? array() ) );
	exit;
}
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
