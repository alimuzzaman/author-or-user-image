<?php
/** Deterministic real-WordPress interleavings during legacy image processing. */
use AuthorOrUserImage\Image_Store;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WordPress is required.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once __DIR__ . '/migration-upload-transport.php';
$store = new Image_Store();
wp_set_current_user( 1 );
$checks = 0;
$failures = array();
foreach ( array( 'user-upload', 'user-remove', 'default-upload', 'default-remove' ) as $scenario ) {
	$is_default = 0 === strpos( $scenario, 'default-' );
	$user_id = wp_insert_user( array( 'user_login' => 'race-' . uniqid(), 'user_email' => 'race-' . uniqid() . '@example.invalid', 'user_pass' => 'public-local-test-fixture' ) );
	delete_option( Image_Store::DEFAULT_OPTION );
	if ( defined( 'AuthorOrUserImage\\Image_Store::MIGRATED_DEFAULT_OPTION' ) ) {
		delete_option( Image_Store::MIGRATED_DEFAULT_OPTION );
	}
	if ( ! $is_default ) {
		wp_delete_file( WP_CONTENT_DIR . '/uploads/author-image/author_default.jpg' );
	}
	$path = WP_CONTENT_DIR . '/uploads/author-image/' . ( $is_default ? 'author_default' : get_userdata( $user_id )->user_login ) . '.jpg';
	wp_mkdir_p( dirname( $path ) );
	$image = imagecreatetruecolor( 320, 240 );
	imagejpeg( $image, $path );
	$ids = get_users( array( 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
	$offset = array_search( $user_id, array_map( 'intval', $ids ), true );
	update_option( Image_Store::MIGRATION_OPTION, $offset );
	delete_option( Image_Store::VERSION_OPTION );
	delete_option( 'zmai_migration_retry' );
	$new_id = 0;
	$import_id = 0;
	$interleaved_result = null;
	$field = 'zmai_race_upload';
	// Store validation and lifecycle use real WordPress media generation. The
	// namespaced CLI transport replaces only the native HTTP temporary-file move.
	$upload_path = wp_tempnam( 'zmai-race.jpg' );
	copy( $path, $upload_path );
	$_FILES[ $field ] = array( 'name' => 'avatar.jpg', 'tmp_name' => $upload_path, 'size' => filesize( $upload_path ), 'error' => UPLOAD_ERR_OK );
	$interleave = null;
	$interleave = static function ( $metadata, $attachment_id ) use ( &$interleave, &$interleaved_result, &$import_id, $field, $scenario, $store, $is_default, $user_id ) {
		remove_filter( 'wp_generate_attachment_metadata', $interleave, 10 );
		$import_id = $attachment_id;
		if ( false !== strpos( $scenario, 'upload' ) ) {
			$interleaved_result = $store->save_upload( $field, $is_default ? 0 : $user_id );
		} elseif ( $is_default ) {
			$interleaved_result = $store->delete_default_avatar();
		} else {
			$interleaved_result = $store->delete_user_avatar( $user_id );
		}
		return $metadata;
	};
	add_filter( 'wp_generate_attachment_metadata', $interleave, 10, 2 );
	$store->register_image_size();
	( new AuthorOrUserImage\Migration( $store ) )->maybe_migrate();
	remove_filter( 'wp_generate_attachment_metadata', $interleave, 10 );
	$current_id = $is_default ? $store->get_default_attachment_id() : $store->get_user_attachment_id( $user_id );
	$conditions = array(
		'interleaving ran' => $import_id > 0,
		'busy operation rejected without superseding completed import' => is_wp_error( $interleaved_result ) && 'zmai_busy' === $interleaved_result->get_error_code() && $current_id === $import_id && get_post( $import_id ),
	);
	if ( false !== strpos( $scenario, 'upload' ) ) {
		$new_id = $store->save_upload( $field, $is_default ? 0 : $user_id );
		$conditions['serial upload completes after rejected busy attempt'] = ! is_wp_error( $new_id ) && $new_id > 0;
	} else {
		$result = $is_default ? $store->delete_default_avatar() : $store->delete_user_avatar( $user_id );
		$conditions['serial removal completes after rejected busy attempt'] = ! is_wp_error( $result );
	}
	$current_id = $is_default ? $store->get_default_attachment_id() : $store->get_user_attachment_id( $user_id );
	$conditions['completed newer intent wins'] = ! is_wp_error( $new_id ) && $new_id === $current_id;
	$conditions['superseded legacy import is cleaned'] = $import_id > 0 && ! get_post( $import_id );
	if ( $new_id && ! is_wp_error( $new_id ) ) {
		$conditions['new upload remains intact'] = get_post( $new_id ) && is_file( get_attached_file( $new_id ) );
	}
	wp_delete_file( $upload_path );
	unset( $_FILES[ $field ] );
	foreach ( $conditions as $label => $ok ) {
		++$checks;
		$label = $scenario . ': ' . $label;
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
		if ( ! $ok ) {
			$failures[] = $label;
		}
	}
}
echo wp_json_encode( array( 'checks' => $checks, 'failures' => $failures ) ) . "\n";
WP_CLI::halt( $failures ? 1 : 0 );
