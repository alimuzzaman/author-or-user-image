<?php
/**
 * Real WordPress integration checks. Run with wp eval-file tests/runtime.php.
 * Requires a disposable local WordPress root with a .zmai-test-instance file.
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Privacy;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use only a disposable local WordPress test instance.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
global $zmai_failures, $zmai_checks;
$zmai_failures = array();
$zmai_checks   = 0;
$zmai_prefix   = 'zmai-' . uniqid();

/** Record an assertion without stopping independent checks. */
function zmai_expect( $condition, $label ) {
	global $zmai_failures, $zmai_checks;
	++$zmai_checks;
	if ( ! $condition ) {
		$zmai_failures[] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/** Write a real JPEG into the site's legacy directory. */
function zmai_legacy_fixture( $filename ) {
	$dir = WP_CONTENT_DIR . '/uploads/author-image';
	wp_mkdir_p( $dir );
	$path  = $dir . '/' . $filename;
	$image = imagecreatetruecolor( 320, 240 );
	imagejpeg( $image, $path );
	return $path;
}

/** Create a real, unrelated Media Library image. */
function zmai_media_fixture() {
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$path  = $uploads['path'] . '/unrelated-' . uniqid() . '.jpg';
	$image = imagecreatetruecolor( 320, 240 );
	imagejpeg( $image, $path );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Unrelated image' ), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	return $id;
}

/** Start another migration pass using disposable fixture data. */
function zmai_migrate( $store ) {
	delete_option( Image_Store::VERSION_OPTION );
	delete_option( Image_Store::MIGRATION_OPTION );
	delete_option( 'zmai_migration_retry' );
	$store->register_image_size();
	for ( $batch = 0; $batch < 100; ++$batch ) {
		$offset = get_option( Image_Store::MIGRATION_OPTION, 0 );
		( new AuthorOrUserImage\Migration( $store ) )->maybe_migrate();
		if ( get_option( Image_Store::VERSION_OPTION ) === ZMAI_VERSION || $offset === get_option( Image_Store::MIGRATION_OPTION, 0 ) ) {
			break;
		}
	}
}

$store   = new Image_Store();
$user_id = wp_insert_user( array( 'user_login' => $zmai_prefix, 'user_email' => $zmai_prefix . '@example.invalid', 'user_pass' => 'public-local-test-fixture', 'role' => 'subscriber' ) );
if ( is_wp_error( $user_id ) ) {
	throw new RuntimeException( 'Could not create local fixture user.' );
}
wp_set_current_user( $user_id );
zmai_expect( current_user_can( 'read' ) && ! current_user_can( 'manage_options' ), 'fixture has subscriber capabilities' );
$admin = new AuthorOrUserImage\Admin( $store );
$admin->enqueue_assets( 'profile_page_author-or-user-image' );
zmai_expect( wp_style_is( 'zmai-admin', 'enqueued' ) && wp_script_is( 'zmai-admin', 'enqueued' ), 'subscriber profile-page hook loads plugin assets' );
$store->update_size( 9999 );
zmai_expect( 1024 === $store->get_size(), 'configured size is bounded above' );
$store->update_size( 1 );
zmai_expect( 32 === $store->get_size(), 'configured size is bounded below' );
$store->update_size( 150 );

if ( ! is_multisite() ) {
	delete_option( Image_Store::DEFAULT_OPTION );
	delete_option( Image_Store::MIGRATED_DEFAULT_OPTION );
	$legacy = zmai_legacy_fixture( $zmai_prefix . '.jpg' );
	zmai_legacy_fixture( 'author_default.jpg' );
	zmai_migrate( $store );
	$id = $store->get_user_attachment_id( $user_id );
	zmai_expect( $id > 0 && is_file( get_attached_file( $id ) ), 'legacy user image imports into Media Library' );
	zmai_expect( is_file( $legacy ), 'migration retains the legacy fallback' );
	zmai_expect( $store->get_default_attachment_id() > 0, 'legacy default image imports' );
	( new AuthorOrUserImage\Migration( $store ) )->maybe_migrate();
	zmai_expect( $id === $store->get_user_attachment_id( $user_id ), 'completed migration is idempotent' );
	$avatar = get_avatar_data( $user_id, array( 'size' => 48 ) );
	zmai_expect( $avatar['url'] === $store->get_url( $user_id, 48 ), 'WordPress avatar API uses the personal avatar' );
	$post_id = wp_insert_post( array( 'post_title' => 'Local avatar fixture', 'post_author' => $user_id, 'post_status' => 'publish' ) );
	$post_avatar = get_avatar_data( get_post( $post_id ), array( 'size' => 48 ) );
	zmai_expect( $post_avatar['url'] === $store->get_url( $user_id, 48 ), 'WordPress post objects resolve to the author avatar' );
	$comment_id = wp_insert_comment( array( 'comment_post_ID' => $post_id, 'user_id' => $user_id, 'comment_type' => 'pingback' ) );
	$pingback_avatar = get_avatar_data( get_comment( $comment_id ), array( 'size' => 48 ) );
	zmai_expect( false === $pingback_avatar['url'], 'pingbacks retain core avatar exclusion' );
	$default = get_avatar_data( 1, array( 'size' => 48 ) );
	zmai_expect( $default['url'] === $store->get_url( 0, 48 ), 'registered user without a personal avatar gets the site default' );
	$forced = get_avatar_data( $user_id, array( 'force_default' => true ) );
	zmai_expect( false === strpos( $forced['url'], '/uploads/' ), 'force_default bypasses local avatars' );
	$privacy = new Privacy( $store );
	$export  = $privacy->export( $zmai_prefix . '@example.invalid' );
	zmai_expect( 1 === count( $export['data'] ) && $export['done'], 'privacy export includes local user data' );
	$privacy->erase( $zmai_prefix . '@example.invalid' );
	zmai_expect( ! get_post( $id ) && ! is_file( $legacy ) && ! $store->get_url( $user_id, 48, false ), 'privacy erasure removes managed media and legacy fallback' );
	zmai_legacy_fixture( $zmai_prefix . '.jpg' );
	zmai_migrate( $store );
	$store->set_blocked( $user_id, true );
	zmai_expect( $store->is_blocked( $user_id ) && ! $store->get_user_attachment_id( $user_id ), 'blocking removes the avatar' );
	$store->set_blocked( $user_id, false );
	zmai_expect( ! $store->is_blocked( $user_id ), 'unblocking clears current and legacy state' );
	$custom_uploads = static function ( $uploads ) {
		$uploads['basedir'] = WP_CONTENT_DIR . '/custom-uploads';
		$uploads['baseurl'] = content_url( '/custom-uploads' );
		$uploads['path']    = $uploads['basedir'];
		$uploads['url']     = $uploads['baseurl'];
		return $uploads;
	};
	$custom_id = wp_insert_user( array( 'user_login' => $zmai_prefix . '-custom', 'user_email' => $zmai_prefix . '-custom@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
	zmai_legacy_fixture( get_userdata( $custom_id )->user_login . '.jpg' );
	add_filter( 'upload_dir', $custom_uploads );
	zmai_migrate( $store );
	zmai_expect( $store->get_user_attachment_id( $custom_id ) > 0, 'actual 2.0.2 fixed-path JPG imports with a custom uploads directory' );
	remove_filter( 'upload_dir', $custom_uploads );
	$spaced_id = wp_insert_user( array( 'user_login' => $zmai_prefix . ' space', 'user_email' => $zmai_prefix . '-space@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
	$spaced_path = zmai_legacy_fixture( get_userdata( $spaced_id )->user_login . '.jpg' );
	zmai_migrate( $store );
	zmai_expect( $store->get_user_attachment_id( $spaced_id ) > 0, 'legacy filenames containing spaces migrate' );
	$store->delete_user_avatar( $spaced_id );
	zmai_expect( ! is_file( $spaced_path ), 'legacy filenames containing spaces are erased' );
	$retry_id = wp_insert_user( array( 'user_login' => $zmai_prefix . '-retry', 'user_email' => $zmai_prefix . '-retry@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
	zmai_legacy_fixture( get_userdata( $retry_id )->user_login . '.jpg' );
	$reject_sideload = static function ( $file ) {
		$file['error'] = 'Synthetic temporary import failure';
		return $file;
	};
	add_filter( 'wp_handle_sideload_prefilter', $reject_sideload );
	zmai_migrate( $store );
	zmai_expect( ! $store->get_user_attachment_id( $retry_id ), 'temporary import failure leaves no attachment' );
	remove_filter( 'wp_handle_sideload_prefilter', $reject_sideload );
	zmai_migrate( $store );
	zmai_expect( $store->get_user_attachment_id( $retry_id ) > 0, 'failed legacy imports retry on a later complete migration pass' );
	$unrelated = zmai_media_fixture();
	$legacy    = zmai_legacy_fixture( $zmai_prefix . '.jpg' );
	define( 'WP_UNINSTALL_PLUGIN', 'author-or-user-image/author_image.php' );
	include ZMAI_PATH . 'uninstall.php';
	zmai_expect( is_file( $legacy ) && $store->get_default_attachment_id() > 0, 'default uninstall preserves data' );
	update_option( Image_Store::DELETE_DATA_OPTION, 1 );
	zmai_uninstall_site_data();
	zmai_expect( ! get_option( Image_Store::DEFAULT_OPTION ) && get_post( $unrelated ), 'opted-in uninstall removes managed data and preserves unrelated media' );
} else {
	delete_option( Image_Store::DEFAULT_OPTION );
	delete_option( Image_Store::MIGRATED_DEFAULT_OPTION );
	$site_id = wpmu_create_blog( '127.0.0.1', '/' . $zmai_prefix . '/', 'Local test site', 1 );
	if ( is_wp_error( $site_id ) ) {
		throw new RuntimeException( 'Could not create local fixture site.' );
	}
	add_user_to_blog( $site_id, $user_id, 'subscriber' );
	zmai_legacy_fixture( $zmai_prefix . '.jpg' );
	zmai_legacy_fixture( 'author_default.jpg' );
	zmai_migrate( $store );
	zmai_expect( $store->get_user_attachment_id( $user_id ) > 0, 'main-site legacy image imports before isolation checks' );
	$main_url = $store->get_url( $user_id, 48 );
	$late_site_id = wpmu_create_blog( '127.0.0.1', '/late-' . $zmai_prefix . '/', 'Unmigrated local site', 1 );
	add_user_to_blog( $late_site_id, $user_id, 'subscriber' );
	switch_to_blog( $site_id );
	$unrelated = zmai_media_fixture();
	zmai_expect( 0 === $store->get_user_attachment_id( $user_id ), 'a site never resolves another site\'s avatar attachment ID' );
	zmai_legacy_fixture( $zmai_prefix . '.jpg' );
	zmai_migrate( $store );
	$site_avatar = $store->get_user_attachment_id( $user_id );
	zmai_expect( $site_avatar > 0 && $site_avatar !== $unrelated, 'secondary site imports its avatar despite colliding media IDs' );
	zmai_expect( $store->get_default_attachment_id() > 0, 'secondary site imports the real shared legacy default' );
	$store->delete_default_avatar();
	zmai_expect( ! $store->get_url( 0, 48 ) && is_file( WP_CONTENT_DIR . '/uploads/author-image/author_default.jpg' ), 'site default removal suppresses its own shared fallback and retains the original' );
	zmai_migrate( $store );
	zmai_expect( ! $store->get_default_attachment_id(), 'later migration does not resurrect a removed default' );
	$site_url = $store->get_url( $user_id, 48 );
	restore_current_blog();
	zmai_expect( $main_url === $store->get_url( $user_id, 48 ), 'secondary-site migration preserves main-site mapping' );
	$store->set_blocked( $user_id, true );
	zmai_expect( is_file( WP_CONTENT_DIR . '/uploads/author-image/' . $zmai_prefix . '.jpg' ), 'site-local removal preserves the shared legacy file for other sites' );
	zmai_expect( ! $store->get_url( $user_id, 48, false ), 'site-local removal suppresses its own shared legacy fallback' );
	switch_to_blog( $late_site_id );
	zmai_expect( ! $store->get_user_attachment_id( $user_id ) && $store->get_url( $user_id, 48, false ), 'an unmigrated site retains its shared legacy fallback after another site removes it' );
	$privacy = new Privacy( $store );
	$erasure = $privacy->erase( $zmai_prefix . '@example.invalid' );
	zmai_expect( $erasure['items_retained'] && $erasure['messages'] && ! $store->get_url( $user_id, 48, false ), 'multisite privacy erasure reports a shared retained original and suppresses it locally' );
	restore_current_blog();
	switch_to_blog( $site_id );
	zmai_expect( ! $store->is_blocked( $user_id ), 'upload blocks apply only to the current site' );
	zmai_expect( $site_url === $store->get_url( $user_id, 48 ) && get_post( $site_avatar ), 'main-site blocking preserves secondary-site avatar' );
	restore_current_blog();
	$store->set_blocked( $user_id, false );
	zmai_migrate( $store );
	zmai_expect( ! $store->get_user_attachment_id( $user_id ), 'later migration does not resurrect a removed shared legacy avatar' );
	update_option( Image_Store::DELETE_DATA_OPTION, 1 );
	define( 'WP_UNINSTALL_PLUGIN', 'author-or-user-image/author_image.php' );
	include ZMAI_PATH . 'uninstall.php';
	switch_to_blog( $site_id );
	zmai_expect( $site_avatar === $store->get_user_attachment_id( $user_id ) && get_post( $site_avatar ), 'uninstall preserves opted-out site metadata and media' );
	restore_current_blog();
}

echo wp_json_encode( array( 'checks' => $zmai_checks, 'failures' => $zmai_failures ) ) . "\n";
if ( $zmai_failures ) {
	WP_CLI::halt( 1 );
}
