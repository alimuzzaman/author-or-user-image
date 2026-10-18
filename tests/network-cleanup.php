<?php
/** Check uninstall on a real network exceeding the default site-query limit. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use only a disposable local WordPress network.' );
}
wp_set_current_user( 1 );
$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
for ( $index = count( $site_ids ); $index < 101; ++$index ) {
	$site_id = wpmu_create_blog( '127.0.0.1', '/cleanup-' . uniqid() . '/', 'Local cleanup fixture', 1 );
	if ( is_wp_error( $site_id ) ) {
		throw new RuntimeException( 'Could not create disposable network fixture.' );
	}
}
$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
$last_id  = end( $site_ids );
switch_to_blog( $last_id );
update_option( 'zmai_delete_data_on_uninstall', 1 );
update_option( 'zmai_default_avatar_id', 123456 );
update_option( 'zmai_upload_policy', array( 'schema' => 1, 'mode' => 'roles', 'roles' => array() ), false );
update_option( 'zmai_import_job', array( 'schema' => 1 ), false );
update_user_meta( 1, AuthorOrUserImage\Image_Store::user_meta_key( '_zmai_avatar_upload_rate' ), array( 'minute_count' => 1 ) );
restore_current_blog();
define( 'WP_UNINSTALL_PLUGIN', 'author-or-user-image/author_image.php' );
include ZMAI_PATH . 'uninstall.php';
switch_to_blog( $last_id );
$cleaned = ! get_option( 'zmai_default_avatar_id' ) && ! get_option( 'zmai_delete_data_on_uninstall' ) && ! get_option( 'zmai_upload_policy' ) && ! get_option( 'zmai_import_job' ) && ! metadata_exists( 'user', 1, AuthorOrUserImage\Image_Store::user_meta_key( '_zmai_avatar_upload_rate' ) );
restore_current_blog();
echo wp_json_encode( array( 'sites' => count( $site_ids ), 'last_site_cleaned' => $cleaned ) ) . "\n";
WP_CLI::halt( $cleaned ? 0 : 1 );
