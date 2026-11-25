<?php
/**
 * Loopback-only HTTP router for real multipart Profile_Admin and REST_API upload checks.
 *
 * @package AuthorOrUserImage
 */

if ( ! in_array( $_SERVER['REMOTE_ADDR'], array( '127.0.0.1', '::1' ), true ) || ! is_file( getcwd() . '/.zmai-test-instance' ) ) {
	http_response_code( 403 );
	exit;
}

define( 'ZMAI_TEST_HTTP_CANDIDATE', dirname( __DIR__ ) );
$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
	'function'      => static function ( $plugins ) {
		return array_values( array_filter( $plugins, static function ( $file ) { return 'author_image.php' !== basename( $file ); } ) );
	},
	'accepted_args' => 1,
);
$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10][] = array(
	'function'      => static function ( $plugins ) {
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
if ( class_exists( 'AuthorOrUserImage\\Image_Store' ) ) {
	throw new RuntimeException( 'Installed target plugin was not skipped.' );
}
require ZMAI_TEST_HTTP_CANDIDATE . '/author_image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

header( 'Content-Type: application/json' );

$zmai_blog_id = isset( $_GET['blog_id'] ) ? absint( $_GET['blog_id'] ) : 0;
if ( is_multisite() && $zmai_blog_id > 1 ) {
	switch_to_blog( $zmai_blog_id );
	register_shutdown_function( 'restore_current_blog' );
}

$ensure_user = static function ( $login, $role ) {
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		$id   = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.invalid',
				'user_pass'  => 'public-local-test-fixture',
				'role'       => $role,
			)
		);
		$user = get_userdata( $id );
	}
	if ( is_multisite() && $user ) {
		if ( ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
			add_user_to_blog( get_current_blog_id(), $user->ID, $role );
		}
		if ( 'administrator' === $role && ! is_super_admin( $user->ID ) ) {
			grant_super_admin( $user->ID );
		}
	}
	return $user;
};

$sub_user   = $ensure_user( 'zmai-rp-sub', 'subscriber' );
$admin_user = $ensure_user( 'zmai-rp-admin', 'administrator' );
$role       = isset( $_GET['role'] ) && 'admin' === $_GET['role'] ? 'admin' : 'sub';
$actor      = 'admin' === $role ? $admin_user : $sub_user;
wp_set_current_user( $actor->ID );

$store  = new AuthorOrUserImage\Image_Store();
$policy = new AuthorOrUserImage\Upload_Policy( $store );
$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'snapshot';

if ( 'snapshot' === $action ) {
	echo wp_json_encode(
		array(
			'sub_id'      => (int) $sub_user->ID,
			'admin_id'    => (int) $admin_user->ID,
			'sub_att'     => $store->get_user_attachment_id( $sub_user->ID ),
			'default_att' => $store->get_default_attachment_id(),
			'sub_blocked' => $store->is_blocked( $sub_user->ID ),
		)
	);
	exit;
}

if ( 'nonce' === $action ) {
	$target_id = isset( $_GET['target_id'] ) ? absint( $_GET['target_id'] ) : (int) $sub_user->ID;
	echo wp_json_encode( array( 'nonce' => wp_create_nonce( 'zmai_profile_avatar_' . $target_id ) ) );
	exit;
}

if ( 'set-policy' === $action ) {
	$mode = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'legacy';
	if ( 'admin-only' === $mode ) {
		$policy->save_policy( array( 'mode' => 'roles', 'roles' => array( 'administrator' ) ) );
	} else {
		delete_option( AuthorOrUserImage\Upload_Policy::OPTION );
	}
	echo wp_json_encode( $policy->get_policy() );
	exit;
}

if ( 'set-blocked' === $action ) {
	$state = isset( $_GET['state'] ) && '1' === $_GET['state'];
	$store->set_blocked( $sub_user->ID, $state );
	echo wp_json_encode( array( 'blocked' => $store->is_blocked( $sub_user->ID ) ) );
	exit;
}

if ( 'reset-rate' === $action ) {
	delete_user_meta( $sub_user->ID, AuthorOrUserImage\Image_Store::user_meta_key( AuthorOrUserImage\Image_Store::UPLOAD_RATE_META ) );
	echo '{}';
	exit;
}

if ( 'metadata-inventory' === $action ) {
	$id    = isset( $_GET['default'] ) ? $store->get_default_attachment_id() : $store->get_user_attachment_id( $sub_user->ID );
	$path  = $id ? get_attached_file( $id ) : '';
	$meta  = $id ? wp_get_attachment_metadata( $id ) : array();
	$paths = $path ? array( $path ) : array();
	foreach ( $meta['sizes'] ?? array() as $size ) {
		$paths[] = dirname( $path ) . '/' . $size['file'];
	}
	if ( isset( $meta['original_image'] ) ) {
		$paths[] = dirname( $path ) . '/' . $meta['original_image'];
	}
	$clean = $id > 0 && count( $paths ) > 0;
	foreach ( array_unique( $paths ) as $file ) {
		$clean = $clean && is_file( $file ) && false === strpos( (string) file_get_contents( $file ), 'PRIVATE-PHOTO-V32' );
	}
	echo wp_json_encode( array( 'clean' => $clean, 'files' => count( array_unique( $paths ) ), 'att_id' => $id ) );
	exit;
}

if ( 'profile-save' === $action ) {
	$target_id = isset( $_GET['target_id'] ) ? absint( $_GET['target_id'] ) : (int) $sub_user->ID;
	$errors    = new WP_Error();
	$hook      = $target_id === $actor->ID ? 'personal_options_update' : 'edit_user_profile_update';
	do_action( $hook, $target_id );
	$user_obj = get_userdata( $target_id );
	do_action_ref_array( 'user_profile_update_errors', array( &$errors, true, $user_obj ) );
	echo wp_json_encode(
		array(
			'errors'  => $errors->get_error_codes(),
			'sub_att' => $store->get_user_attachment_id( $target_id ),
		)
	);
	exit;
}

if ( 'rest' === $action ) {
	$method = strtoupper( (string) ( $_GET['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
	$route  = (string) ( $_GET['route'] ?? '' );
	$req    = new WP_REST_Request( $method, '/' . AuthorOrUserImage\REST_API::REST_NAMESPACE . $route );
	$req->set_file_params( $_FILES );
	$req->set_body_params( $_POST );
	$response = rest_do_request( $req );
	http_response_code( $response->get_status() );
	echo wp_json_encode( $response->get_data() );
	exit;
}

if ( 'cleanup-fixture' === $action ) {
	delete_option( AuthorOrUserImage\Upload_Policy::OPTION );
	$store->delete_default_avatar();
	foreach ( array( $sub_user->ID, $admin_user->ID ) as $uid ) {
		$store->delete_user_avatar( $uid );
		if ( is_multisite() ) {
			if ( is_super_admin( $uid ) ) {
				revoke_super_admin( $uid );
			}
			wpmu_delete_user( $uid );
		} else {
			wp_delete_user( $uid );
		}
	}
	echo '{"cleaned":true}';
	exit;
}

http_response_code( 400 );
echo '{"error":"unknown_action"}';
