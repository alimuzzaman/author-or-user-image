<?php
/** CLI-only file transport for migration interleavings; HTTP tests use native multipart. */
namespace AuthorOrUserImage;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new \RuntimeException( 'Disposable local WP-CLI only.' );
}
/** Send the CLI fixture through real core sideload instead of is_uploaded_file(). */
function is_uploaded_file( $path ) {
	return is_string( $path ) && is_file( $path );
}
/** Send the CLI fixture through real core sideload after the test provenance adapter. */
function media_handle_upload( $field, $post_id, $post_data, $overrides ) {
	$file = $_FILES[ $field ];
	if ( isset( $overrides['unique_filename_callback'] ) ) {
		$extension = '.' . pathinfo( $file['name'], PATHINFO_EXTENSION );
		$uploads = wp_upload_dir();
		$file['name'] = call_user_func( $overrides['unique_filename_callback'], $uploads['path'], pathinfo( $file['name'], PATHINFO_FILENAME ), $extension );
	}
	return \media_handle_sideload( $file, $post_id, null, $post_data );
}
