<?php
/** Refusal and notice regressions on a disposable local fixture. */
use AuthorOrUserImage\Admin;
use AuthorOrUserImage\Front_End;
use AuthorOrUserImage\Image_Store;
require __DIR__ . '/import-fixtures.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
wp_set_current_user( 1 );
$store = new Image_Store();
$admin = new Admin( $store );
$old_post = $_POST;
$old_get = $_GET;
$old_server = $_SERVER;
$redirect = static function ( $location, $status ) { throw new RuntimeException( 'REDIRECT ' . $status . ' ' . $location ); };
$die = static function () { return static function ( $message ) { throw new RuntimeException( 'DIED ' . strip_tags( $message ) ); }; };
add_filter( 'wp_redirect', $redirect, -99999, 2 );
add_filter( 'wp_die_handler', $die );
add_filter( 'wp_die_ajax_handler', $die );
try {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['CONTENT_LENGTH'] = (string) ( wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) + 1 );
	$_POST = array();
	$_GET = array( 'action' => 'zmai_save_avatar', 'zmai_context' => 'front', 'zmai_return' => home_url( '/avatar-test/' ) );
	try { $admin->save_avatar(); $message = ''; } catch ( RuntimeException $error ) { $message = $error->getMessage(); }
	zmai_it_check( 0 === strpos( $message, 'REDIRECT 303 ' ) && false !== strpos( $message, 'zmai_status=upload-too-large' ) && false !== strpos( $message, '/avatar-test/' ), 'oversized empty POST returns to the safe upload page with a clear status' );
	$_GET['zmai_return'] = 'https://evil.example/';
	try { $admin->save_avatar(); $message = ''; } catch ( RuntimeException $error ) { $message = $error->getMessage(); }
	zmai_it_check( 0 === strpos( $message, 'REDIRECT 303 ' ) && false === strpos( $message, 'evil.example' ), 'oversize refusal cannot redirect outside this site' );
	$_SERVER['CONTENT_LENGTH'] = '0';
	try { $admin->save_avatar(); $message = ''; } catch ( RuntimeException $error ) { $message = $error->getMessage(); }
	zmai_it_check( 0 === strpos( $message, 'DIED ' ), 'ordinary empty POST still requires the operation nonce' );
	$user = zmai_it_user(); wp_set_current_user( $user );
	$held = $store->get_lock()->acquire_lock( 'zmai_avatar_lock_user_' . $user );
	$_POST = array( 'avatar_type' => 'user', '_wpnonce' => wp_create_nonce( 'zmai_remove_avatar' ), 'zmai_context' => 'front', 'zmai_return' => home_url( '/avatar-test/' ) );
	try { $admin->remove_avatar(); $message = ''; } catch ( RuntimeException $error ) { $message = $error->getMessage(); }
	zmai_it_check( false !== strpos( $message, 'zmai_status=remove-error' ), 'busy removal reports a removal-specific failure' );
	$store->get_lock()->release_lock( $held ); unset( $held );
	wp_set_current_user( 1 );
	set_current_screen( 'dashboard' );
	$queries = 0;
	$count = static function ( $sql ) use ( &$queries ) { if ( false !== strpos( $sql, "option_name = 'zmai_upload_policy'" ) ) { ++$queries; } return $sql; };
	add_filter( 'query', $count );
	ob_start(); $admin->policy_notice(); ob_end_clean();
	remove_filter( 'query', $count );
	zmai_it_check( 0 === $queries, 'unrelated admin screens do not query the avatar policy notice' );

	// manage_users() enforces edit_user capability on target users.
	$deny_target = zmai_it_user();
	$deny_cap    = static function ( $caps, $cap, $user_id, $args ) use ( $deny_target ) {
		if ( 'edit_user' === $cap && isset( $args[0] ) && (int) $args[0] === $deny_target ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	};
	add_filter( 'map_meta_cap', $deny_cap, 10, 4 );
	try {
		$_POST = array(
			'action'      => 'zmai_manage_users',
			'user_action' => 'block',
			'return_tab'  => 'images',
			'user_ids'    => array( $deny_target ),
			'_wpnonce'    => wp_create_nonce( 'zmai_manage_users' ),
		);
		try { $admin->manage_users(); } catch ( RuntimeException $error ) { unset( $error ); }
		zmai_it_check( ! $store->is_blocked( $deny_target ), 'manage_users skips target user when edit_user capability is denied' );
		$denied_upload = $store->save_upload( 'avatar', $deny_target, true );
		zmai_it_check( is_wp_error( $denied_upload ) && 'zmai_forbidden' === $denied_upload->get_error_code(), 'admin save_upload returns zmai_forbidden when edit_user capability is denied' );
	} finally {
		remove_filter( 'map_meta_cap', $deny_cap, 10 );
	}

	$missing_upload = $store->save_upload( 'avatar', 999999999, true );
	zmai_it_check( is_wp_error( $missing_upload ) && 'zmai_invalid_user' === $missing_upload->get_error_code(), 'admin save_upload rejects nonexistent user with zmai_invalid_user' );

	if ( is_multisite() ) {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 2 ) );
		if ( count( $sites ) >= 2 ) {
			$blog2      = (int) $sites[1];
			$nonmember  = zmai_it_user();
			remove_user_from_blog( $nonmember, $blog2 );
			switch_to_blog( $blog2 );
			try {
				$site_admin = zmai_it_user();
				add_user_to_blog( $blog2, $site_admin, 'administrator' );
				wp_set_current_user( $site_admin );
				$_POST = array(
					'action'      => 'zmai_manage_users',
					'user_action' => 'block',
					'return_tab'  => 'images',
					'user_ids'    => array( $nonmember ),
					'_wpnonce'    => wp_create_nonce( 'zmai_manage_users' ),
				);
				try { $admin->manage_users(); } catch ( RuntimeException $error ) { unset( $error ); }
				zmai_it_check( ! $store->is_blocked( $nonmember ), 'multisite subsite admin cannot block or delete non-member user via manage_users' );
				$nonmember_upload = $store->save_upload( 'avatar', $nonmember, true );
				zmai_it_check( is_wp_error( $nonmember_upload ) && 'zmai_invalid_user' === $nonmember_upload->get_error_code(), 'multisite subsite admin save_upload rejects non-member user with zmai_invalid_user' );
			} finally {
				restore_current_blog();
				wp_set_current_user( 1 );
			}
		}
	}
} finally {
	remove_filter( 'wp_redirect', $redirect, -99999 ); remove_filter( 'wp_die_handler', $die ); remove_filter( 'wp_die_ajax_handler', $die );
	if ( isset( $held ) && is_array( $held ) ) { $store->get_lock()->release_lock( $held ); }
	$_POST = $old_post; $_GET = $old_get; $_SERVER = $old_server;
	zmai_it_cleanup();
}
zmai_it_done();
