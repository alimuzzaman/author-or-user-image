<?php
/**
 * WordPress Profile and Edit User screen avatar management checks.
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Profile_Admin;
use AuthorOrUserImage\Upload_Policy;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WP-CLI fixture required.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! class_exists( 'WP_Screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
}
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

$zmai_pa_failed = 0;
$zmai_pa_checks = 0;
$zmai_pa_expect = static function ( $ok, $label ) use ( &$zmai_pa_failed, &$zmai_pa_checks ) {
	++$zmai_pa_checks;
	if ( ! $ok ) {
		++$zmai_pa_failed;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
};

$store  = new Image_Store();
$policy = new Upload_Policy( $store );
$admin  = new Profile_Admin( $store );

$created_users    = array();
$created_att_ids  = array();
$previous_policy  = get_option( Upload_Policy::OPTION, null );
$previous_get     = $_GET;
$previous_post    = $_POST;
$previous_files   = $_FILES;
$previous_method  = $_SERVER['REQUEST_METHOD'] ?? null;
$previous_user_id = get_current_user_id();

/**
 * Create a disposable subscriber or administrator user.
 *
 * @param string $role Role slug.
 * @return int
 */
$create_user = static function ( $role ) use ( &$created_users ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'zmai-pa-' . $role . '-' . wp_generate_uuid4(),
			'user_email' => wp_generate_uuid4() . '@example.invalid',
			'user_pass'  => 'public-local-test-fixture',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) || ! $id ) {
		throw new RuntimeException( 'Could not create disposable profile test user.' );
	}
	$created_users[] = (int) $id;
	return (int) $id;
};

/**
 * Attach a minimal valid PNG avatar owned by the given user.
 *
 * @param int $user_id Owner ID.
 * @return int
 */
$assign_avatar = static function ( $user_id ) use ( $store, &$created_att_ids ) {
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$path  = $uploads['path'] . '/zmai-pa-' . wp_generate_uuid4() . '.png';
	$image = imagecreatetruecolor( 64, 64 );
	imagepng( $image, $path );
	if ( PHP_VERSION_ID < 80000 ) {
		imagedestroy( $image );
	}
	$att_id = wp_insert_attachment(
		array(
			'post_author'    => $user_id,
			'post_mime_type' => 'image/png',
			'post_title'     => 'Profile fixture',
			'post_status'    => 'inherit',
		),
		$path
	);
	wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $path ) );
	update_post_meta( $att_id, Image_Store::ATTACHMENT_OWNER_META, $store->owner_name( $user_id ) );
	update_user_meta( $user_id, Image_Store::user_meta_key( Image_Store::USER_META ), $att_id );
	$created_att_ids[] = (int) $att_id;
	return (int) $att_id;
};

try {
	// 1. Hook registrations and multipart form tag output.
	foreach ( array( 'show_user_profile', 'edit_user_profile', 'user_edit_form_tag', 'personal_options_update', 'edit_user_profile_update', 'user_profile_update_errors' ) as $hook ) {
		$zmai_pa_expect( false !== has_action( $hook ), 'registers profile hook ' . $hook );
	}

	ob_start();
	$admin->add_multipart_enctype();
	$enctype = (string) ob_get_clean();
	$zmai_pa_expect( false !== strpos( $enctype, 'enctype="multipart/form-data"' ), 'add_multipart_enctype emits multipart attribute' );

	// 2. Render permissions and control visibility.
	$admin_id  = $create_user( 'administrator' );
	$sub_id    = $create_user( 'subscriber' );
	$other_sub = $create_user( 'subscriber' );
	$sub_obj   = get_userdata( $sub_id );
	$other_obj = get_userdata( $other_sub );

	delete_option( Upload_Policy::OPTION );
	wp_set_current_user( $sub_id );
	ob_start();
	$admin->render_profile_section( $sub_obj );
	$self_html = (string) ob_get_clean();
	$zmai_pa_expect( false !== strpos( $self_html, 'name="zmai_profile_avatar"' ) && false !== strpos( $self_html, 'name="zmai_profile_nonce"' ), 'allowed subscriber sees file input and nonce on own profile' );
	$zmai_pa_expect( false === strpos( $self_html, 'zmai_profile_blocked' ), 'subscriber never sees admin block checkbox on own profile' );

	// Non-admin viewing another user's profile sees nothing.
	ob_start();
	$admin->render_profile_section( $other_obj );
	$non_admin_other = (string) ob_get_clean();
	$zmai_pa_expect( '' === $non_admin_other, 'non-admin receives no output when viewing another user' );

	// Role-denied subscriber with existing avatar keeps remove option without upload input.
	$assign_avatar( $sub_id );
	$policy->save_policy(
		array(
			'mode'  => 'roles',
			'roles' => array( 'administrator' ),
		)
	);
	ob_start();
	$admin->render_profile_section( $sub_obj );
	$denied_html = (string) ob_get_clean();
	$zmai_pa_expect( false === strpos( $denied_html, 'name="zmai_profile_avatar"' ) && false !== strpos( $denied_html, 'name="zmai_profile_remove"' ), 'role-denied subscriber keeps remove checkbox without upload input' );

	// Blocked subscriber sees notice and neither upload input nor remove checkbox.
	$store->set_blocked( $sub_id, true );
	ob_start();
	$admin->render_profile_section( $sub_obj );
	$blocked_html = (string) ob_get_clean();
	$zmai_pa_expect( false === strpos( $blocked_html, 'name="zmai_profile_avatar"' ) && false === strpos( $blocked_html, 'name="zmai_profile_remove"' ) && false !== strpos( $blocked_html, 'disabled for this account' ), 'blocked subscriber sees disabled message without upload/remove inputs' );
	$store->set_blocked( $sub_id, false );

	// Admin viewing another user sees upload input, block checkbox, and remove checkbox when avatar exists.
	$assign_avatar( $sub_id );
	if ( is_multisite() ) {
		grant_super_admin( $admin_id );
	}
	wp_set_current_user( $admin_id );
	ob_start();
	$admin->render_profile_section( $sub_obj );
	$admin_edit_html = (string) ob_get_clean();
	$zmai_pa_expect(
		false !== strpos( $admin_edit_html, 'name="zmai_profile_avatar"' )
		&& false !== strpos( $admin_edit_html, 'name="zmai_profile_remove"' )
		&& false !== strpos( $admin_edit_html, 'name="zmai_profile_blocked"' )
		&& false !== strpos( $admin_edit_html, 'name="zmai_profile_block_present"' ),
		'admin editing subscriber sees upload, remove, and block controls'
	);

	// 3. save_profile() transport, nonce, authorization, block, remove, and error attachment checks.
	// 3a. Refuses GET cleanly without mutating.
	$before_att = $store->get_user_attachment_id( $sub_id );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_POST                     = array(
		'zmai_profile_nonce'  => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ),
		'zmai_profile_remove' => '1',
	);
	$_FILES                    = array();
	$admin->save_profile( $sub_id );
	$err_bag = new WP_Error();
	$admin->attach_profile_errors( $err_bag, true, $sub_obj );
	$zmai_pa_expect( $store->get_user_attachment_id( $sub_id ) === $before_att && ! $err_bag->has_errors(), 'save_profile ignores GET without mutating' );

	// 3b. Missing nonce is ignored (unrelated profile save).
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array( 'zmai_profile_remove' => '1' );
	$admin->save_profile( $sub_id );
	$err_bag = new WP_Error();
	$admin->attach_profile_errors( $err_bag, true, $sub_obj );
	$zmai_pa_expect( $store->get_user_attachment_id( $sub_id ) === $before_att && ! $err_bag->has_errors(), 'save_profile ignores requests without plugin nonce' );

	// 3c. Invalid nonce attaches fixed error and leaves state unchanged.
	$_POST = array(
		'zmai_profile_nonce'  => 'invalid-nonce',
		'zmai_profile_remove' => '1',
	);
	$admin->save_profile( $sub_id );
	$err_bag = new WP_Error();
	$admin->attach_profile_errors( $err_bag, true, $sub_obj );
	$zmai_pa_expect( $store->get_user_attachment_id( $sub_id ) === $before_att && in_array( 'zmai_invalid_nonce', $err_bag->get_error_codes(), true ), 'save_profile rejects invalid nonce with zmai_invalid_nonce error' );

	// 3d. Non-admin cannot modify another user even with valid nonce.
	wp_set_current_user( $other_sub );
	$_POST = array(
		'zmai_profile_nonce'         => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ),
		'zmai_profile_remove'        => '1',
		'zmai_profile_block_present' => '1',
		'zmai_profile_blocked'       => '1',
	);
	$admin->save_profile( $sub_id );
	$err_bag = new WP_Error();
	$admin->attach_profile_errors( $err_bag, true, $sub_obj );
	$zmai_pa_expect( $store->get_user_attachment_id( $sub_id ) === $before_att && ! $store->is_blocked( $sub_id ) && in_array( 'zmai_forbidden', $err_bag->get_error_codes(), true ), 'non-admin cannot edit another user profile avatar or block state' );

	// 3e. Self-upload denied by role policy attaches zmai_blocked.
	wp_set_current_user( $sub_id );
	$_POST  = array( 'zmai_profile_nonce' => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ) );
	$_FILES = array(
		'zmai_profile_avatar' => array(
			'name'     => 'a.png',
			'tmp_name' => '',
			'error'    => UPLOAD_ERR_OK,
			'size'     => 10,
		),
	);
	$admin->save_profile( $sub_id );
	$err_bag = new WP_Error();
	$admin->attach_profile_errors( $err_bag, true, $sub_obj );
	$zmai_pa_expect( in_array( 'zmai_blocked', $err_bag->get_error_codes(), true ) && $store->get_user_attachment_id( $sub_id ) === $before_att, 'self-upload denied by role policy records zmai_blocked without changing avatar' );
	$_FILES = array();

	// 3f. Self removal succeeds even when role-denied.
	$_POST = array(
		'zmai_profile_nonce'  => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ),
		'zmai_profile_remove' => '1',
	);
	$admin->save_profile( $sub_id );
	$err_bag = new WP_Error();
	$admin->attach_profile_errors( $err_bag, true, $sub_obj );
	$zmai_pa_expect( 0 === $store->get_user_attachment_id( $sub_id ) && ! $err_bag->has_errors(), 'role-denied user can still remove own avatar on profile screen' );

	// 3g. Admin block toggle removes avatar and blocks user; unblocking clears block.
	$assign_avatar( $sub_id );
	wp_set_current_user( $admin_id );
	$_POST = array(
		'zmai_profile_nonce'         => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ),
		'zmai_profile_block_present' => '1',
		'zmai_profile_blocked'       => '1',
	);
	$admin->save_profile( $sub_id );
	$err_bag = new WP_Error();
	$admin->attach_profile_errors( $err_bag, true, $sub_obj );
	$zmai_pa_expect( $store->is_blocked( $sub_id ) && 0 === $store->get_user_attachment_id( $sub_id ) && ! $err_bag->has_errors(), 'admin block toggle via Edit User blocks user and removes avatar' );

	$_POST = array(
		'zmai_profile_nonce'         => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ),
		'zmai_profile_block_present' => '1',
	);
	$admin->save_profile( $sub_id );
	$err_bag = new WP_Error();
	$admin->attach_profile_errors( $err_bag, true, $sub_obj );
	$zmai_pa_expect( ! $store->is_blocked( $sub_id ) && ! $err_bag->has_errors(), 'admin unchecking block via Edit User unblocks user' );

	// 3h. Network Admin and User Admin screen contexts are ignored safely.
	$prev_screen = $GLOBALS['current_screen'] ?? null;
	try {
		wp_set_current_user( $admin_id );
		$assign_avatar( $sub_id );
		$before_ctx_att = $store->get_user_attachment_id( $sub_id );

		foreach ( array( 'user-edit-network', 'profile-user' ) as $ctx_screen ) {
			set_current_screen( $ctx_screen );
			ob_start();
			$admin->add_multipart_enctype();
			$ctx_enctype = (string) ob_get_clean();

			ob_start();
			$admin->render_profile_section( $sub_obj );
			$ctx_html = (string) ob_get_clean();

			$_POST = array(
				'zmai_profile_nonce'         => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ),
				'zmai_profile_remove'        => '1',
				'zmai_profile_block_present' => '1',
				'zmai_profile_blocked'       => '1',
			);
			$admin->save_profile( $sub_id );
			$ctx_bag = new WP_Error();
			$admin->attach_profile_errors( $ctx_bag, true, $sub_obj );

			$zmai_pa_expect(
				'' === $ctx_enctype
				&& '' === $ctx_html
				&& $store->get_user_attachment_id( $sub_id ) === $before_ctx_att
				&& ! $store->is_blocked( $sub_id )
				&& ! $ctx_bag->has_errors(),
				'Profile_Admin ignores ' . $ctx_screen . ' screen context without rendering or mutating'
			);
		}
	} finally {
		$GLOBALS['current_screen'] = $prev_screen;
	}

	// 3i. Admin block/unblock via save_profile ignores poisoned user_meta cache.
	$blocked_key = Image_Store::user_meta_key( Image_Store::BLOCKED_META );
	update_user_meta( $sub_id, $blocked_key, 1 );
	get_user_meta( $sub_id );
	$cached_meta = wp_cache_get( $sub_id, 'user_meta' );
	if ( ! is_array( $cached_meta ) ) {
		$cached_meta = array();
	}
	$cached_meta[ $blocked_key ] = array( '' );
	wp_cache_set( $sub_id, $cached_meta, 'user_meta' );
	wp_set_current_user( $admin_id );
	$_POST = array(
		'zmai_profile_nonce'         => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ),
		'zmai_profile_block_present' => '1',
	);
	$admin->save_profile( $sub_id );
	$zmai_pa_expect( ! $store->is_blocked_fresh( $sub_id ), 'Profile_Admin unblocks user despite poisoned user_meta cache' );

	$assign_avatar( $sub_id );
	get_user_meta( $sub_id );
	$cached_meta = wp_cache_get( $sub_id, 'user_meta' );
	if ( ! is_array( $cached_meta ) ) {
		$cached_meta = array();
	}
	$cached_meta[ $blocked_key ] = array( '1' );
	wp_cache_set( $sub_id, $cached_meta, 'user_meta' );
	$_POST = array(
		'zmai_profile_nonce'         => wp_create_nonce( 'zmai_profile_avatar_' . $sub_id ),
		'zmai_profile_block_present' => '1',
		'zmai_profile_blocked'       => '1',
	);
	$admin->save_profile( $sub_id );
	wp_cache_delete( $sub_id, 'user_meta' );
	$zmai_pa_expect( $store->is_blocked_fresh( $sub_id ) && 0 === $store->get_user_attachment_id( $sub_id ), 'Profile_Admin blocks user and deletes avatar despite poisoned user_meta cache' );
	$store->set_blocked( $sub_id, false );

	// 3j. Legacy-blocked user suppresses preview image and remove checkbox in render_profile_section.
	$assign_avatar( $sub_id );
	$previous_legacy_ban = get_option( Image_Store::LEGACY_BLOCK_OPTION, null );
	update_option( Image_Store::LEGACY_BLOCK_OPTION, array( $sub_obj->user_login ), false );
	try {
		wp_set_current_user( $sub_id );
		ob_start();
		$admin->render_profile_section( $sub_obj );
		$legacy_self_html = (string) ob_get_clean();

		wp_set_current_user( $admin_id );
		ob_start();
		$admin->render_profile_section( $sub_obj );
		$legacy_admin_html = (string) ob_get_clean();

		$zmai_pa_expect(
			false === strpos( $legacy_self_html, 'zmai-avatar-preview' )
			&& false === strpos( $legacy_self_html, 'zmai_profile_remove' )
			&& false !== strpos( $legacy_self_html, 'No local avatar is set.' )
			&& false !== strpos( $legacy_self_html, 'Local avatar uploads are disabled for this account.' )
			&& false === strpos( $legacy_admin_html, 'zmai-avatar-preview' )
			&& false === strpos( $legacy_admin_html, 'zmai_profile_remove' )
			&& false !== strpos( $legacy_admin_html, 'No local avatar is set.' )
			&& false !== strpos( $legacy_admin_html, 'Local avatar uploads are disabled for this account.' ),
			'render_profile_section suppresses avatar preview and remove checkbox for legacy-blocked users'
		);
	} finally {
		if ( null === $previous_legacy_ban ) {
			delete_option( Image_Store::LEGACY_BLOCK_OPTION );
		} else {
			update_option( Image_Store::LEGACY_BLOCK_OPTION, $previous_legacy_ban, false );
		}
		wp_set_current_user( $admin_id );
		$store->delete_user_avatar( $sub_id );
	}

	// 4. Multisite non-member isolation even for super administrators.
	if ( is_multisite() ) {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 2 ) );
		if ( count( $sites ) >= 2 ) {
			$blog2        = (int) $sites[1];
			$nonmember_id = $create_user( 'subscriber' );
			$nm_obj       = get_userdata( $nonmember_id );
			$admin_obj    = get_userdata( $admin_id );
			switch_to_blog( $blog2 );
			try {
				wp_set_current_user( $admin_id );
				ob_start();
				$admin->render_profile_section( $nm_obj );
				$nm_html = (string) ob_get_clean();

				$_POST = array(
					'zmai_profile_nonce'         => wp_create_nonce( 'zmai_profile_avatar_' . $nonmember_id ),
					'zmai_profile_block_present' => '1',
					'zmai_profile_blocked'       => '1',
				);
				$admin->save_profile( $nonmember_id );
				$nm_bag = new WP_Error();
				$admin->attach_profile_errors( $nm_bag, true, $nm_obj );
				$zmai_pa_expect(
					'' === $nm_html && in_array( 'zmai_forbidden', $nm_bag->get_error_codes(), true ) && ! $store->is_blocked( $nonmember_id ),
					'multisite super admin cannot view or edit non-member user on another site via Profile_Admin'
				);

				ob_start();
				$admin->render_profile_section( $admin_obj );
				$self_nm_html = (string) ob_get_clean();

				$_POST = array(
					'zmai_profile_nonce'  => wp_create_nonce( 'zmai_profile_avatar_' . $admin_id ),
					'zmai_profile_remove' => '1',
				);
				$admin->save_profile( $admin_id );
				$self_nm_bag = new WP_Error();
				$admin->attach_profile_errors( $self_nm_bag, true, $admin_obj );
				$zmai_pa_expect(
					'' === $self_nm_html && in_array( 'zmai_forbidden', $self_nm_bag->get_error_codes(), true ),
					'multisite non-member super admin receives empty output and zmai_forbidden on self-save via Profile_Admin'
				);
			} finally {
				restore_current_blog();
			}
		}
	}
} finally {
	$_GET    = $previous_get;
	$_POST   = $previous_post;
	$_FILES  = $previous_files;
	if ( null === $previous_method ) {
		unset( $_SERVER['REQUEST_METHOD'] );
	} else {
		$_SERVER['REQUEST_METHOD'] = $previous_method;
	}
	wp_set_current_user( $previous_user_id );
	if ( null === $previous_policy ) {
		delete_option( Upload_Policy::OPTION );
	} else {
		update_option( Upload_Policy::OPTION, $previous_policy, false );
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

echo "RESULT {$zmai_pa_checks} checks, {$zmai_pa_failed} failed\n";
if ( $zmai_pa_failed > 0 ) {
	throw new RuntimeException( 'Profile admin checks failed.' );
}
