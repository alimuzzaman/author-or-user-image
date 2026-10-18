<?php
/** CLI-only file transport for migration interleavings; HTTP tests use native multipart. */
namespace AuthorOrUserImage;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new \RuntimeException( 'Disposable local WP-CLI only.' );
}
/** Send the CLI fixture through real core sideload instead of is_uploaded_file(). */
function media_handle_upload( $field, $post_id, $post_data, $overrides ) {
	return \media_handle_sideload( $_FILES[ $field ], $post_id, null, $post_data );
}
