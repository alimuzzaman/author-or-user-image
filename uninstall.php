<?php
/**
 * Optional uninstall cleanup.
 *
 * @package AuthorOrUserImage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-lock.php';
require_once __DIR__ . '/includes/class-image-store.php';

/**
 * Delete this plugin's data from the current site when the owner opted in.
 *
 * @return void
 */
function zmai_uninstall_site_data() {
	if ( ! get_option( 'zmai_delete_data_on_uninstall', false ) ) {
		return;
	}

	$attachments = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_zmai_avatar_owner', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time uninstall cleanup.
		)
	);
	foreach ( $attachments as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}

	delete_metadata( 'user', 0, AuthorOrUserImage\Image_Store::user_meta_key( '_zmai_avatar_attachment_id' ), '', true );
	delete_metadata( 'user', 0, AuthorOrUserImage\Image_Store::user_meta_key( '_zmai_migrated_avatar_id' ), '', true );
	delete_metadata( 'user', 0, AuthorOrUserImage\Image_Store::user_meta_key( '_zmai_avatar_blocked' ), '', true );
	delete_metadata( 'user', 0, AuthorOrUserImage\Image_Store::user_meta_key( '_zmai_avatar_upload_rate' ), '', true );
	delete_option( 'zmai_upload_policy' );
	delete_option( 'zmai_import_job' );
	delete_option( 'zmai_default_avatar_id' );
	delete_option( 'zmai_migrated_default_avatar_id' );
	delete_option( 'zmai_data_version' );
	delete_option( 'zmai_migration_offset' );
	delete_option( 'zmai_migration_retry' );
	delete_option( 'zmai_migration_release_failed' );
	delete_option( 'zmai_delete_data_on_uninstall' );
	delete_option( 'author_image_size' );
	delete_option( 'zm_ai_ban_id' );
	delete_option( 'ai_notification' );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Quiescent opted-in uninstall must enumerate orphaned mutex rows from storage, bypassing stale option caches.
	$locks = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'zmai_avatar_lock_' ) . '%' ) );
	foreach ( $locks as $name ) {
		delete_option( $name );
	}
}

if ( is_multisite() ) {
	$zmai_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $zmai_site_ids as $zmai_site_id ) {
		switch_to_blog( $zmai_site_id );
		zmai_uninstall_site_data();
		restore_current_blog();
	}
} else {
	zmai_uninstall_site_data();
}
