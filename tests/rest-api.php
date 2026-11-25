<?php
/**
 * REST API contract, permission, and multisite scoping checks.
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\REST_API;
use AuthorOrUserImage\Upload_Policy;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WP-CLI fixture required.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

$zmai_rest_failed = 0;
$zmai_rest_checks = 0;
$zmai_rest_expect = static function ( $ok, $label ) use ( &$zmai_rest_failed, &$zmai_rest_checks ) {
	++$zmai_rest_checks;
	if ( ! $ok ) {
		++$zmai_rest_failed;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
};

$store            = new Image_Store();
$policy           = new Upload_Policy( $store );
$created_users    = array();
$created_att_ids  = array();
$previous_policy  = get_option( Upload_Policy::OPTION, null );
$previous_size    = get_option( Image_Store::LEGACY_SIZE_OPTION, null );
$previous_del     = get_option( Image_Store::DELETE_DATA_OPTION, null );
$previous_default = get_option( Image_Store::DEFAULT_OPTION, null );
$previous_user_id = get_current_user_id();

/**
 * Dispatch an internal WordPress REST API request.
 *
 * @param string $method HTTP method.
 * @param string $route  Route relative to namespace (e.g. '/avatars').
 * @param array  $params Query/body parameters.
 * @return WP_REST_Response
 */
$call = static function ( $method, $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/' . REST_API::REST_NAMESPACE . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};

$create_user = static function ( $role ) use ( &$created_users ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'zmai-rest-' . $role . '-' . wp_generate_uuid4(),
			'user_email' => wp_generate_uuid4() . '@example.invalid',
			'user_pass'  => 'public-local-test-fixture',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) || ! $id ) {
		throw new RuntimeException( 'Failed to create REST fixture user.' );
	}
	$created_users[] = (int) $id;
	return (int) $id;
};

$assign_avatar = static function ( $user_id ) use ( $store, &$created_att_ids ) {
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$path  = $uploads['path'] . '/zmai-rest-' . wp_generate_uuid4() . '.png';
	$image = imagecreatetruecolor( 64, 64 );
	imagepng( $image, $path );
	if ( PHP_VERSION_ID < 80000 ) {
		imagedestroy( $image );
	}
	$att_id = wp_insert_attachment(
		array(
			'post_author'    => $user_id,
			'post_mime_type' => 'image/png',
			'post_title'     => 'REST fixture',
			'post_status'    => 'inherit',
		),
		$path
	);
	wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $path ) );
	update_post_meta( $att_id, Image_Store::ATTACHMENT_OWNER_META, $store->owner_name( $user_id ) );
	if ( $user_id ) {
		update_user_meta( $user_id, Image_Store::user_meta_key( Image_Store::USER_META ), $att_id );
	} else {
		update_option( Image_Store::DEFAULT_OPTION, $att_id, false );
	}
	$created_att_ids[] = (int) $att_id;
	return (int) $att_id;
};

try {
	$routes = rest_get_server()->get_routes();
	foreach ( array( '/avatars', '/avatars/(?P<user_id>[1-9][0-9]*)', '/default', '/blocked', '/blocked/(?P<user_id>[1-9][0-9]*)', '/bulk', '/settings' ) as $suffix ) {
		$zmai_rest_expect( isset( $routes[ '/' . REST_API::REST_NAMESPACE . $suffix ] ), 'route registered: ' . $suffix );
	}

	$admin_id = $create_user( 'administrator' );
	if ( is_multisite() ) {
		grant_super_admin( $admin_id );
	}
	$sub_id   = $create_user( 'subscriber' );
	$other_id = $create_user( 'subscriber' );

	// 1. Unauthenticated requests are rejected on every endpoint.
	wp_set_current_user( 0 );
	$unauth = array(
		$call( 'GET', '/avatars' )->get_status(),
		$call( 'GET', '/avatars/' . $sub_id )->get_status(),
		$call( 'POST', '/avatars/' . $sub_id )->get_status(),
		$call( 'DELETE', '/avatars/' . $sub_id )->get_status(),
		$call( 'GET', '/default' )->get_status(),
		$call( 'POST', '/default' )->get_status(),
		$call( 'DELETE', '/default' )->get_status(),
		$call( 'GET', '/blocked' )->get_status(),
		$call( 'POST', '/blocked', array( 'user_id' => $sub_id ) )->get_status(),
		$call( 'DELETE', '/blocked/' . $sub_id )->get_status(),
		$call(
			'POST',
			'/bulk',
			array(
				'action'   => 'delete',
				'user_ids' => array( $sub_id ),
			)
		)->get_status(),
		$call( 'GET', '/settings' )->get_status(),
		$call( 'POST', '/settings', array( 'avatar_size' => 128 ) )->get_status(),
	);
	$zmai_rest_expect(
		count( array_filter( $unauth, static function ( $s ) { return in_array( $s, array( 401, 403 ), true ); } ) ) === count( $unauth ),
		'unauthenticated callers are rejected with 401/403 on every route'
	);

	// 2. Subscriber permissions: self read + self delete (even if role-denied); forbidden elsewhere.
	$assign_avatar( $sub_id );
	$policy->save_policy(
		array(
			'mode'  => 'roles',
			'roles' => array( 'administrator' ),
		)
	);
	wp_set_current_user( $sub_id );

	$self_get = $call( 'GET', '/avatars/' . $sub_id );
	$self_data = $self_get->get_data();
	$zmai_rest_expect(
		200 === $self_get->get_status()
		&& $self_data['user_id'] === $sub_id
		&& true === $self_data['has_local_avatar']
		&& false === $self_data['can_upload'],
		'subscriber reads own avatar state with can_upload=false when role-excluded'
	);

	$self_del = $call( 'DELETE', '/avatars/' . $sub_id );
	$zmai_rest_expect( 200 === $self_del->get_status() && 0 === $store->get_user_attachment_id( $sub_id ), 'role-excluded subscriber can still delete own avatar via REST' );

	$sub_forbidden = array(
		$call( 'GET', '/avatars' )->get_status(),
		$call( 'GET', '/avatars/' . $other_id )->get_status(),
		$call( 'POST', '/avatars/' . $other_id )->get_status(),
		$call( 'DELETE', '/avatars/' . $other_id )->get_status(),
		$call( 'GET', '/default' )->get_status(),
		$call( 'POST', '/default' )->get_status(),
		$call( 'DELETE', '/default' )->get_status(),
		$call( 'GET', '/blocked' )->get_status(),
		$call( 'POST', '/blocked', array( 'user_id' => $other_id ) )->get_status(),
		$call( 'DELETE', '/blocked/' . $other_id )->get_status(),
		$call(
			'POST',
			'/bulk',
			array(
				'action'   => 'delete',
				'user_ids' => array( $other_id ),
			)
		)->get_status(),
		$call( 'GET', '/settings' )->get_status(),
		$call( 'POST', '/settings', array( 'avatar_size' => 128 ) )->get_status(),
	);
	$zmai_rest_expect(
		count( array_filter( $sub_forbidden, static function ( $s ) { return 403 === $s; } ) ) === count( $sub_forbidden ),
		'subscriber gets 403 on all admin and other-user endpoints'
	);

	// 3. Admin CRUD: list avatars, invalid role filter, 404 user, blocked, bulk, settings, default.
	$sub_att   = $assign_avatar( $sub_id );
	$other_att = $assign_avatar( $other_id );
	$assign_avatar( 0 );
	wp_set_current_user( $admin_id );

	$list_ok = $call(
		'GET',
		'/avatars',
		array(
			'role'     => 'subscriber',
			'per_page' => 10,
			'page'     => 1,
		)
	);
	$list_headers = $list_ok->get_headers();
	$list_ids     = array_column( $list_ok->get_data(), 'user_id' );
	$zmai_rest_expect(
		200 === $list_ok->get_status()
		&& in_array( $sub_id, $list_ids, true )
		&& in_array( $other_id, $list_ids, true )
		&& (int) ( $list_headers['X-WP-Total'] ?? 0 ) >= 2
		&& (int) ( $list_headers['X-WP-TotalPages'] ?? 0 ) >= 1,
		'admin lists avatars with role filter and pagination headers'
	);

	$bad_role = $call( 'GET', '/avatars', array( 'role' => 'nonexistent_role_slug' ) );
	$zmai_rest_expect( 400 === $bad_role->get_status(), 'invalid role query returns 400' );

	$punct_role = $call( 'GET', '/avatars', array( 'role' => '!!!' ) );
	$zmai_rest_expect( 400 === $punct_role->get_status(), 'non-alphanumeric role query returns 400 instead of unfiltered list' );

	$missing_user = $call( 'GET', '/avatars/9999999' );
	$zmai_rest_expect( 404 === $missing_user->get_status(), 'admin querying nonexistent user returns 404' );

	foreach ( array(
		'missing' => array(),
		'zero'    => array( 'user_id' => 0 ),
		'neg'     => array( 'user_id' => -5 ),
		'string'  => array( 'user_id' => 'abc' ),
	) as $label => $params ) {
		$bad_blk = $call( 'POST', '/blocked', $params );
		$zmai_rest_expect( 400 === $bad_blk->get_status(), 'POST /blocked rejects invalid user_id (' . $label . ') with 400' );
	}

	$block_res = $call( 'POST', '/blocked', array( 'user_id' => $sub_id ) );
	$zmai_rest_expect( 200 === $block_res->get_status() && $store->is_blocked( $sub_id ) && 0 === $store->get_user_attachment_id( $sub_id ), 'POST /blocked blocks user and removes avatar' );

	$blocked_avatar = $call( 'GET', '/avatars/' . $sub_id );
	$blocked_data   = $blocked_avatar->get_data();
	$zmai_rest_expect(
		200 === $blocked_avatar->get_status()
		&& true === $blocked_data['blocked']
		&& 0 === $blocked_data['attachment_id']
		&& '' === $blocked_data['url']
		&& '' === $blocked_data['fallback_url']
		&& false === $blocked_data['has_local_avatar'],
		'GET /avatars/{id} omits site default fallback_url and local avatar URL when user is blocked'
	);

	$blocked_list = $call( 'GET', '/blocked' );
	$blocked_ids  = array_column( $blocked_list->get_data(), 'user_id' );
	$zmai_rest_expect( 200 === $blocked_list->get_status() && in_array( $sub_id, $blocked_ids, true ), 'GET /blocked lists blocked user' );

	$unblock_res = $call( 'DELETE', '/blocked/' . $sub_id );
	$zmai_rest_expect( 200 === $unblock_res->get_status() && ! $store->is_blocked( $sub_id ), 'DELETE /blocked/{id} unblocks user' );

	// Bulk delete, block, unblock, and invalid bulk size (>50).
	$bulk_del = $call(
		'POST',
		'/bulk',
		array(
			'action'   => 'delete',
			'user_ids' => array( $other_id ),
		)
	);
	$zmai_rest_expect( 200 === $bulk_del->get_status() && array( $other_id ) === $bulk_del->get_data()['succeeded'] && 0 === $store->get_user_attachment_id( $other_id ), 'POST /bulk delete removes target avatar' );

	$bulk_block = $call(
		'POST',
		'/bulk',
		array(
			'action'   => 'block',
			'user_ids' => array( $other_id ),
		)
	);
	$zmai_rest_expect( 200 === $bulk_block->get_status() && $store->is_blocked( $other_id ), 'POST /bulk block blocks user' );

	$bulk_unblock = $call(
		'POST',
		'/bulk',
		array(
			'action'   => 'unblock',
			'user_ids' => array( $other_id ),
		)
	);
	$zmai_rest_expect( 200 === $bulk_unblock->get_status() && ! $store->is_blocked( $other_id ), 'POST /bulk unblock unblocks user' );

	$too_many = $call(
		'POST',
		'/bulk',
		array(
			'action'   => 'delete',
			'user_ids' => range( 1, 51 ),
		)
	);
	$zmai_rest_expect( 400 === $too_many->get_status(), 'POST /bulk rejects more than 50 user_ids with 400' );

	// Settings GET and POST validation.
	$set_bad = $call(
		'POST',
		'/settings',
		array(
			'avatar_size'   => 192,
			'upload_policy' => array(
				'mode'  => 'roles',
				'roles' => array( 'bogus_role' ),
			),
		)
	);
	$zmai_rest_expect( 400 === $set_bad->get_status() && 192 !== $store->get_size(), 'invalid settings policy rejects with 400 without partial avatar_size write' );

	$set_ok = $call(
		'POST',
		'/settings',
		array(
			'avatar_size'              => 192,
			'delete_data_on_uninstall' => true,
			'upload_policy'            => array(
				'mode'  => 'legacy',
				'roles' => array(),
			),
		)
	);
	$set_data = $set_ok->get_data();
	$zmai_rest_expect(
		200 === $set_ok->get_status()
		&& 192 === $set_data['avatar_size']
		&& true === $set_data['delete_data_on_uninstall']
		&& 'legacy' === $set_data['upload_policy']['mode'],
		'POST /settings validates and persists policy, avatar_size, and uninstall option'
	);

	// Default GET & DELETE.
	$def_get = $call( 'GET', '/default' );
	$zmai_rest_expect( 200 === $def_get->get_status() && true === $def_get->get_data()['has_default_avatar'], 'GET /default returns default avatar payload' );

	$def_del = $call( 'DELETE', '/default' );
	$zmai_rest_expect( 200 === $def_del->get_status() && 0 === $store->get_default_attachment_id(), 'DELETE /default removes default avatar' );

	// 3b. map_error() never leaks raw internal upload_error paths and maps upload_error -> 400.
	$rest_ctrl = new REST_API( $store );
	$map_ref   = new ReflectionMethod( REST_API::class, 'map_error' );
	if ( PHP_VERSION_ID < 80100 ) {
		$map_ref->setAccessible( true );
	}
	$mapped_upload = $map_ref->invoke( $rest_ctrl, new WP_Error( 'upload_error', '/var/www/outside-abspath/secret/path could not be written' ) );
	$mapped_data   = $mapped_upload->get_error_data();
	$zmai_rest_expect(
		is_wp_error( $mapped_upload )
		&& 400 === ( $mapped_data['status'] ?? 0 )
		&& false === strpos( $mapped_upload->get_error_message(), '/var/www/outside-abspath' )
		&& false === strpos( $mapped_upload->get_error_message(), 'secret/path' ),
		'REST map_error() maps upload_error to HTTP 400 with fixed message and no internal path leakage'
	);

	// 3c. Oversized request exceeding post_max_size (empty files/body with large Content-Length) returns 400 zmai_upload_size.
	$post_max = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
	if ( $post_max > 0 ) {
		$oversize_req = new WP_REST_Request( 'POST', '/' . REST_API::REST_NAMESPACE . '/avatars/' . $sub_id );
		$oversize_req->set_header( 'content-length', (string) ( $post_max + 1024 ) );
		$oversize_res  = rest_do_request( $oversize_req );
		$oversize_data = $oversize_res->get_data();
		$zmai_rest_expect(
			400 === $oversize_res->get_status() && 'zmai_upload_size' === ( $oversize_data['code'] ?? '' ),
			'POST /avatars/{id} with oversized Content-Length and empty body returns 400 zmai_upload_size'
		);
	}

	// 3d. Path user_id cannot be shadowed by query/body user_id, and associative bulk arrays are rejected.
	$shadow_req = new WP_REST_Request( 'GET', '/' . REST_API::REST_NAMESPACE . '/avatars/' . $sub_id );
	$shadow_req->set_query_params( array( 'user_id' => 'abc' ) );
	$shadow_res = rest_do_request( $shadow_req );
	$zmai_rest_expect( 400 === $shadow_res->get_status(), 'GET /avatars/{id}?user_id=abc returns 400 instead of 404' );

	$call( 'POST', '/blocked', array( 'user_id' => $sub_id ) );
	$call( 'POST', '/blocked', array( 'user_id' => $other_id ) );
	$unblock_shadow = new WP_REST_Request( 'DELETE', '/' . REST_API::REST_NAMESPACE . '/blocked/' . $sub_id );
	$unblock_shadow->set_query_params( array( 'user_id' => $other_id ) );
	$unblock_shadow->set_body_params( array( 'user_id' => $other_id ) );
	$unblock_shadow_res = rest_do_request( $unblock_shadow );
	$zmai_rest_expect(
		200 === $unblock_shadow_res->get_status()
		&& ! $store->is_blocked_fresh( $sub_id )
		&& $store->is_blocked_fresh( $other_id ),
		'DELETE /blocked/{id} ignores shadowed query/body user_id and unblocks only path user'
	);
	$call( 'DELETE', '/blocked/' . $other_id );

	$assoc_bulk = $call(
		'POST',
		'/bulk',
		array(
			'action'   => 'block',
			'user_ids' => array( 'a' => $sub_id ),
		)
	);
	$zmai_rest_expect( 400 === $assoc_bulk->get_status(), 'POST /bulk rejects associative user_ids array with 400' );

	// 4. Multisite non-member isolation for a site-only administrator (not super admin).
	if ( is_multisite() ) {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 2 ) );
		if ( count( $sites ) >= 2 ) {
			$blog2         = (int) $sites[1];
			$nonmember_id  = $create_user( 'subscriber' );
			$site_admin_id = $create_user( 'subscriber' );

			switch_to_blog( $blog2 );
			add_user_to_blog( $blog2, $site_admin_id, 'administrator' );
			wp_set_current_user( $site_admin_id );

			$nm_get   = $call( 'GET', '/avatars/' . $nonmember_id );
			$nm_post  = $call( 'POST', '/avatars/' . $nonmember_id );
			$nm_del   = $call( 'DELETE', '/avatars/' . $nonmember_id );
			$nm_blk   = $call( 'POST', '/blocked', array( 'user_id' => $nonmember_id ) );
			$nm_unblk = $call( 'DELETE', '/blocked/' . $nonmember_id );
			$nm_bulk  = $call(
				'POST',
				'/bulk',
				array(
					'action'   => 'block',
					'user_ids' => array( $nonmember_id ),
				)
			);
			$bulk_failed = $nm_bulk->get_data()['failed'][0]['code'] ?? '';

			$zmai_rest_expect(
				404 === $nm_get->get_status() && 'zmai_user_not_found' === ( $nm_get->get_data()['code'] ?? '' )
				&& 404 === $nm_post->get_status() && 'zmai_user_not_found' === ( $nm_post->get_data()['code'] ?? '' )
				&& 404 === $nm_del->get_status() && 'zmai_user_not_found' === ( $nm_del->get_data()['code'] ?? '' )
				&& 404 === $nm_blk->get_status() && 'zmai_user_not_found' === ( $nm_blk->get_data()['code'] ?? '' )
				&& 404 === $nm_unblk->get_status() && 'zmai_user_not_found' === ( $nm_unblk->get_data()['code'] ?? '' )
				&& 200 === $nm_bulk->get_status()
				&& 'zmai_user_not_found' === $bulk_failed
				&& ! $store->is_blocked( $nonmember_id ),
				'multisite subsite admin receives uniform 404 zmai_user_not_found for non-member users via REST'
			);
			restore_current_blog();
		}
	}
} finally {
	if ( is_multisite() && ms_is_switched() ) {
		restore_current_blog();
	}
	wp_set_current_user( $previous_user_id );
	if ( null === $previous_policy ) {
		delete_option( Upload_Policy::OPTION );
	} else {
		update_option( Upload_Policy::OPTION, $previous_policy, false );
	}
	if ( null === $previous_size ) {
		delete_option( Image_Store::LEGACY_SIZE_OPTION );
	} else {
		update_option( Image_Store::LEGACY_SIZE_OPTION, $previous_size, false );
	}
	if ( null === $previous_del ) {
		delete_option( Image_Store::DELETE_DATA_OPTION );
	} else {
		update_option( Image_Store::DELETE_DATA_OPTION, $previous_del, false );
	}
	if ( null === $previous_default ) {
		delete_option( Image_Store::DEFAULT_OPTION );
	} else {
		update_option( Image_Store::DEFAULT_OPTION, $previous_default, false );
	}
	foreach ( $created_att_ids as $att_id ) {
		wp_delete_attachment( $att_id, true );
	}
	foreach ( $created_users as $uid ) {
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
}

echo "RESULT {$zmai_rest_checks} checks, {$zmai_rest_failed} failed\n";
if ( $zmai_rest_failed > 0 ) {
	throw new RuntimeException( 'REST API checks failed.' );
}
