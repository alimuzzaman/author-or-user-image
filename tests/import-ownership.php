<?php
/** Import ownership: forged foreign attachments are permanent not_owned skips; own and admin-assigned media import. */
use AuthorOrUserImage\Admin;
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
require __DIR__ . '/import-fixtures.php';

/** Attachment with the given author and status, backed by a disposable PNG and tracked for cleanup. */
function zmai_io_attachment( $author, $status ) {
	$path = zmai_it_file()['path'];
	$id   = wp_insert_attachment(
		array(
			'post_author'    => (int) $author,
			'post_status'    => $status,
			'post_mime_type' => 'image/png',
			'post_title'     => 'import-ownership',
		),
		$path
	);
	if ( ! $id || is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture attachment failed.' );
	}
	$GLOBALS['zmai_io_attachments'][] = (int) $id;
	return (int) $id;
}

/** Metronet profile post authored by $author whose thumbnail is $attachment_id. */
function zmai_io_profile( $author, $attachment_id ) {
	$post = wp_insert_post(
		array(
			'post_type'   => 'mt_pp',
			'post_author' => (int) $author,
			'post_status' => 'publish',
			'post_title'  => 'import-ownership',
		)
	);
	if ( ! $post || is_wp_error( $post ) ) {
		throw new RuntimeException( 'Fixture profile failed.' );
	}
	update_post_meta( $post, '_thumbnail_id', (int) $attachment_id );
	$GLOBALS['zmai_io_profiles'][] = (int) $post;
	return (int) $post;
}

/** Valid image in a directory no attachment registers; its URL is the only source reference. */
function zmai_io_protected_url() {
	$uploads = wp_upload_dir();
	$dir     = $uploads['basedir'] . '/zmai-protected-' . wp_generate_uuid4();
	wp_mkdir_p( $dir );
	$path = $dir . '/avatar.png';
	copy( zmai_it_file()['path'], $path );
	$GLOBALS['zmai_it_files'][] = $path;
	$GLOBALS['zmai_io_dirs'][]  = $dir;
	return $uploads['baseurl'] . '/' . basename( $dir ) . '/avatar.png';
}

/** Source value, plus bytes, row and meta of an attachment and of a profile post; equal snapshots prove no source write. */
function zmai_io_snapshot( $subject, $key, $attachment_id = 0, $profile = 0 ) {
	$file = $attachment_id ? (string) get_attached_file( $attachment_id ) : '';
	return array(
		'bytes'     => '' !== $file && is_file( $file ) ? md5_file( $file ) : '',
		'post'      => $attachment_id ? (array) get_post( $attachment_id ) : array(),
		'meta'      => $attachment_id ? get_post_meta( $attachment_id ) : array(),
		'source'    => get_user_meta( $subject, $key, true ),
		'profile'   => $profile ? (array) get_post( $profile ) : array(),
		'thumbnail' => $profile ? get_post_meta( $profile, '_thumbnail_id', true ) : '',
	);
}

/** One fresh permanent-import sweep for $source with a bounded drain; a WP_Error ends it. */
function zmai_io_sweep( Migration $migration, $source ) {
	delete_option( 'zmai_import_job' );
	$job = $migration->start_import( $source, wp_generate_uuid4(), 0 );
	for ( $n = 0; $n < 60 && is_array( $job ) && 'running' === ( $job['state'] ?? '' ); ++$n ) {
		$job = $migration->run_import_batch( $job['id'], $job['revision'] );
	}
	return is_array( $job ) ? $job : array();
}

/** Permanent reason recorded for the owner, or an empty string when the owner was not skipped. */
function zmai_io_reason( $job, $subject ) {
	$records = is_array( $job['skips'] ?? null ) ? $job['skips'] : array();
	foreach ( $records as $record ) {
		if ( is_array( $record ) && (int) ( $record['user_id'] ?? 0 ) === (int) $subject ) {
			return (string) ( $record['reason'] ?? '' );
		}
	}
	return '';
}

/** A sweep with only permanent skips completes without failure or retry. */
function zmai_io_expect_clean( $label, $job ) {
	zmai_it_check( 'complete' === ( $job['state'] ?? '' ) && 0 === ( $job['counts']['failed'] ?? -1 ) && false === ( $job['retry_needed'] ?? true ), $label . ' completes with no failure and no retry' );
}

/** Forged or unregistered reference: permanent not_owned, no avatar imported, source untouched. */
function zmai_io_expect_forged( $label, $job, $subject, Image_Store $store, $before, $after ) {
	zmai_it_check( 'not_owned' === zmai_io_reason( $job, $subject ), $label . ' is a permanent not_owned skip' );
	zmai_it_check( 0 === (int) $store->get_user_attachment_id( $subject ), $label . ' imports no avatar' );
	zmai_it_check( $before === $after, $label . ' leaves source bytes and metadata unchanged' );
}

/** Own or administrator-assigned media: not skipped, imported, source untouched. */
function zmai_io_expect_accepted( $label, $job, $subject, Image_Store $store, $before, $after ) {
	zmai_it_check( '' === zmai_io_reason( $job, $subject ) && (int) $store->get_user_attachment_id( $subject ) > 0, $label . ' is accepted and imported' );
	zmai_it_check( $before === $after, $label . ' leaves source bytes and metadata unchanged' );
}

/** Rendered status notice for a status and an arbitrary zmai_error, via Reflection on the private renderer. */
function zmai_io_notice( $status, $error = null ) {
	$old_status = $_GET['zmai_status'] ?? null;
	$old_error  = $_GET['zmai_error'] ?? null;
	$_GET['zmai_status'] = $status;
	if ( null === $error ) {
		unset( $_GET['zmai_error'] );
	} else {
		$_GET['zmai_error'] = $error;
	}
	$level = ob_get_level();
	$html  = '';
	try {
		$admin  = ( new ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();
		$render = new ReflectionMethod( Admin::class, 'render_status_notice' );
		if ( PHP_VERSION_ID < 80100 ) {
			$render->setAccessible( true );
		}
		ob_start();
		$render->invoke( $admin );
		$html = (string) ob_get_clean();
	} catch ( \Throwable $error_thrown ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$html = 'EXCEPTION';
	}
	if ( null === $old_status ) {
		unset( $_GET['zmai_status'] );
	} else {
		$_GET['zmai_status'] = $old_status;
	}
	if ( null === $old_error ) {
		unset( $_GET['zmai_error'] );
	} else {
		$_GET['zmai_error'] = $old_error;
	}
	return $html;
}

$GLOBALS['zmai_io_attachments'] = array();
$GLOBALS['zmai_io_profiles']    = array();
$GLOBALS['zmai_io_dirs']        = array();
wp_set_current_user( 1 );
global $wpdb;
$store     = new Image_Store();
$migration = new Migration( $store );
$prefix    = $wpdb->get_blog_prefix();
$blog      = get_current_blog_id();
try {
	// 1. Simple Local Avatars: a forged foreign private media_id is not_owned; an admin-authored public one is accepted.
	$sla_owner   = zmai_it_user();
	$sla_forged  = zmai_it_user();
	$sla_admin   = zmai_it_user();
	$sla_private = zmai_io_attachment( $sla_owner, 'private' );
	$sla_public  = zmai_io_attachment( 1, 'publish' );
	update_user_meta( $sla_forged, 'simple_local_avatar', array( 'media_id' => $sla_private, 'blog_id' => $blog ) );
	update_user_meta( $sla_admin, 'simple_local_avatar', array( 'media_id' => $sla_public, 'blog_id' => $blog ) );
	$sla_before = array(
		zmai_io_snapshot( $sla_forged, 'simple_local_avatar', $sla_private ),
		zmai_io_snapshot( $sla_admin, 'simple_local_avatar', $sla_public ),
	);
	$job = zmai_io_sweep( $migration, 'simple-local-avatars' );
	zmai_io_expect_clean( 'Simple Local Avatars sweep', $job );
	zmai_io_expect_forged( 'SLA media_id to another subscriber\'s private attachment', $job, $sla_forged, $store, $sla_before[0], zmai_io_snapshot( $sla_forged, 'simple_local_avatar', $sla_private ) );
	zmai_io_expect_accepted( 'SLA admin-authored public attachment with edit_user(subject)', $job, $sla_admin, $store, $sla_before[1], zmai_io_snapshot( $sla_admin, 'simple_local_avatar', $sla_public ) );

	// 2. One User Avatar: a forged foreign private attachment ID is not_owned.
	$oua_owner   = zmai_it_user();
	$oua_forged  = zmai_it_user();
	$oua_private = zmai_io_attachment( $oua_owner, 'private' );
	update_user_meta( $oua_forged, $prefix . 'user_avatar', $oua_private );
	$oua_before = zmai_io_snapshot( $oua_forged, $prefix . 'user_avatar', $oua_private );
	$job        = zmai_io_sweep( $migration, 'one-user-avatar' );
	zmai_io_expect_clean( 'One User Avatar sweep', $job );
	zmai_io_expect_forged( 'OUA attachment ID to another subscriber\'s private attachment', $job, $oua_forged, $store, $oua_before, zmai_io_snapshot( $oua_forged, $prefix . 'user_avatar', $oua_private ) );

	// 3. Metronet: a forged image_id and a forged profile-post thumbnail are both not_owned.
	$mt_owner     = zmai_it_user();
	$mt_image     = zmai_it_user();
	$mt_thumb     = zmai_it_user();
	$mt_image_att = zmai_io_attachment( $mt_owner, 'private' );
	$mt_thumb_att = zmai_io_attachment( $mt_owner, 'private' );
	$mt_profile   = zmai_io_profile( $mt_thumb, $mt_thumb_att );
	update_user_meta( $mt_image, $prefix . 'metronet_image_id', $mt_image_att );
	update_user_meta( $mt_thumb, $prefix . 'metronet_post_id', $mt_profile );
	$mt_before = array(
		zmai_io_snapshot( $mt_image, $prefix . 'metronet_image_id', $mt_image_att ),
		zmai_io_snapshot( $mt_thumb, $prefix . 'metronet_post_id', $mt_thumb_att, $mt_profile ),
	);
	$job = zmai_io_sweep( $migration, 'metronet-profile-picture' );
	zmai_io_expect_clean( 'Metronet sweep', $job );
	zmai_io_expect_forged( 'Metronet image_id to another subscriber\'s private attachment', $job, $mt_image, $store, $mt_before[0], zmai_io_snapshot( $mt_image, $prefix . 'metronet_image_id', $mt_image_att ) );
	zmai_io_expect_forged( 'Metronet profile thumbnail to another subscriber\'s private attachment', $job, $mt_thumb, $store, $mt_before[1], zmai_io_snapshot( $mt_thumb, $prefix . 'metronet_post_id', $mt_thumb_att, $mt_profile ) );

	// 4. WP User Avatars: forged media_id, URL mapped to a foreign attachment and an unregistered protected directory are not_owned; own media is accepted.
	$wp_owner     = zmai_it_user();
	$wp_forged    = zmai_it_user();
	$wp_url       = zmai_it_user();
	$wp_dir       = zmai_it_user();
	$wp_own       = zmai_it_user();
	$wp_private   = zmai_io_attachment( $wp_owner, 'private' );
	$wp_url_att   = zmai_io_attachment( $wp_owner, 'private' );
	$wp_own_att   = zmai_io_attachment( $wp_own, 'inherit' );
	$wp_url_value = wp_get_attachment_url( $wp_url_att );
	$wp_dir_url   = zmai_io_protected_url();
	update_user_meta( $wp_forged, 'wp_user_avatars', array( 'media_id' => $wp_private, 'site_id' => $blog ) );
	update_user_meta( $wp_url, 'wp_user_avatars', array( 'full' => $wp_url_value ) );
	update_user_meta( $wp_dir, 'wp_user_avatars', array( 'full' => $wp_dir_url ) );
	update_user_meta( $wp_own, 'wp_user_avatars', array( 'media_id' => $wp_own_att, 'site_id' => $blog ) );
	$wp_before = array(
		zmai_io_snapshot( $wp_forged, 'wp_user_avatars', $wp_private ),
		zmai_io_snapshot( $wp_url, 'wp_user_avatars', $wp_url_att ),
		zmai_io_snapshot( $wp_dir, 'wp_user_avatars' ),
		zmai_io_snapshot( $wp_own, 'wp_user_avatars', $wp_own_att ),
	);
	$job = zmai_io_sweep( $migration, 'wp-user-avatars' );
	zmai_io_expect_clean( 'WP User Avatars sweep', $job );
	zmai_io_expect_forged( 'WPUA media_id to another subscriber\'s private attachment', $job, $wp_forged, $store, $wp_before[0], zmai_io_snapshot( $wp_forged, 'wp_user_avatars', $wp_private ) );
	zmai_io_expect_forged( 'URL mapped to a foreign private attachment', $job, $wp_url, $store, $wp_before[1], zmai_io_snapshot( $wp_url, 'wp_user_avatars', $wp_url_att ) );
	zmai_io_expect_forged( 'URL in an unregistered protected directory', $job, $wp_dir, $store, $wp_before[2], zmai_io_snapshot( $wp_dir, 'wp_user_avatars' ) );
	zmai_io_expect_accepted( 'own attachment (author is the subject)', $job, $wp_own, $store, $wp_before[3], zmai_io_snapshot( $wp_own, 'wp_user_avatars', $wp_own_att ) );
} finally {
	foreach ( $GLOBALS['zmai_io_profiles'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $GLOBALS['zmai_io_attachments'] as $id ) {
		wp_delete_attachment( $id, true );
	}
	zmai_it_cleanup();
	foreach ( $GLOBALS['zmai_io_dirs'] as $dir ) {
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fixture directory may already be gone.
	}
}

// 5. Status notices: known text is fixed; an arbitrary zmai_error never renders and failure notices show one fixed generic message.
$secret_error = 'zmai-secret-marker@example.com /var/www/secret';
$other_error  = 'Fatal error: unrelated arbitrary text';
$upload_a     = zmai_io_notice( 'upload-error', $secret_error );
$upload_b     = zmai_io_notice( 'upload-error', $other_error );
$remove_a     = zmai_io_notice( 'remove-error', $secret_error );
$remove_b     = zmai_io_notice( 'remove-error', $other_error );
zmai_it_check( false !== strpos( $upload_a, 'notice-error' ), 'upload-error with an arbitrary zmai_error renders an error notice' );
zmai_it_check( false === strpos( $upload_a, 'zmai-secret-marker' ) && false === strpos( $upload_a, '/var/www/secret' ), 'arbitrary zmai_error text is never displayed on upload-error' );
zmai_it_check( $upload_a === $upload_b, 'unknown upload-error codes render one fixed generic message' );
zmai_it_check( false !== strpos( $remove_a, 'notice-error' ) && $remove_a === $remove_b && false === strpos( $remove_a, 'zmai-secret-marker' ), 'unknown remove-error codes render one fixed generic message without the raw text' );
zmai_it_check( false !== strpos( zmai_io_notice( 'settings-saved' ), 'Settings saved.' ), 'known settings-saved status keeps its fixed text' );
zmai_it_check( zmai_io_notice( 'uploaded', $secret_error ) === zmai_io_notice( 'uploaded' ) && false !== strpos( zmai_io_notice( 'uploaded' ), 'The image was uploaded.' ), 'known uploaded status keeps fixed text and ignores an arbitrary zmai_error' );
zmai_it_check( zmai_io_notice( 'operation-error', $secret_error ) === zmai_io_notice( 'operation-error' ) && '' !== zmai_io_notice( 'operation-error' ), 'operation-error keeps its fixed text whatever zmai_error holds' );
zmai_it_check( '' === zmai_io_notice( 'unknown-status', $secret_error ), 'unknown zmai_status with an arbitrary zmai_error renders nothing' );
zmai_it_done();
