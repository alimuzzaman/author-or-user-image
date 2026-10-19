<?php
/** Real metadata-stage competitor import interleavings and source preservation. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
use AuthorOrUserImage\Import_Sources;
use AuthorOrUserImage\Privacy;
require __DIR__ . '/import-fixtures.php';
global $wpdb;
zmai_it_check( class_exists( Import_Sources::class ), 'source resolver contract exists for owner-lock interleavings' );
if ( ! class_exists( Import_Sources::class ) ) { zmai_it_done(); return; }
wp_set_current_user( 1 );
$store = new Image_Store();
$sources = new Import_Sources();
$migration = new Migration( $store );
$source_posts = array();
try {
	$file = zmai_it_file();
	$hash = hash_file( 'sha256', $file['path'] );
	$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Competitor source', 'post_status' => 'inherit' ), $file['path'] );
	$source_posts[] = $attachment;
	update_post_meta( $attachment, '_wp_attachment_wp_user_avatar', 12345 );
	foreach ( array_keys( Import_Sources::registry() ) as $source ) {
		$id = zmai_it_user();
		if ( 'simple-local-avatars' === $source ) { update_user_meta( $id, 'simple_local_avatar', array( 'media_id' => $attachment, 'blog_id' => get_current_blog_id(), 'full' => $file['url'] ) ); }
		if ( 'one-user-avatar' === $source ) { update_user_meta( $id, $wpdb->get_blog_prefix() . 'user_avatar', $attachment ); }
		if ( 'wp-user-avatars' === $source ) { update_user_meta( $id, 'wp_user_avatars', array( 'full' => $file['url'] ) ); }
		if ( 'metronet-profile-picture' === $source ) { update_user_meta( $id, $wpdb->get_blog_prefix() . 'metronet_image_id', $attachment ); }
		$before = get_user_meta( $id );
		$result = $store->import_local_image( $sources->resolver( $source, $id ), $id );
		$dest = is_wp_error( $result ) ? 0 : $result['attachment_id'];
		zmai_it_check( ! is_wp_error( $result ) && 'imported' === $result['status'] && $dest !== $attachment && $store->owns_attachment( $dest, $id ), $source . ' creates a new owned attachment' );
		$metadata = wp_get_attachment_metadata( $dest );
		zmai_it_check( isset( $metadata['sizes']['zmai-avatar'] ) && '1' === get_post_meta( $dest, '_zmai_avatar_square_version', true ) && get_attached_file( $dest ) !== $file['path'], $source . ' publishes a square on its own path' );
		$again = $store->import_local_image( $sources->resolver( $source, $id ), $id );
		zmai_it_check( ! is_wp_error( $again ) && $dest === $again['attachment_id'] && 'already_present' === $again['status'], $source . ' replay retains exactly the existing destination' );
		$erase = ( new Privacy( $store ) )->erase( get_userdata( $id )->user_email );
		$after = get_user_meta( $id );
		$unchanged = true;
		foreach ( $before as $key => $value ) { if ( ! isset( $after[ $key ] ) || $value !== $after[ $key ] ) { $unchanged = false; } }
		$retry = $store->import_local_image( $sources->resolver( $source, $id ), $id );
		zmai_it_check( $erase['items_removed'] && $unchanged && ! is_wp_error( $retry ) && 'explicit_choice' === $retry['status'] && get_post( $attachment ) && $hash === hash_file( 'sha256', $file['path'] ), $source . ' erase preserves source and explicit zero prevents resurrection' );
	}
	$id = zmai_it_user(); update_user_meta( $id, 'wp_user_avatars', array( 'full' => $file['url'] ) );
	foreach ( array( 'remove', 'block', 'erase', 'delete' ) as $action ) {
		$store->write_value( $id, Image_Store::USER_META, '', null );
		$attempt = null;
		$interleave = static function ( $metadata ) use ( $action, $store, $id, &$attempt ) {
			if ( 'remove' === $action ) { $attempt = $store->delete_user_avatar( $id ); }
			if ( 'block' === $action ) { $attempt = $store->set_blocked( $id, true ); }
			if ( 'erase' === $action ) { $attempt = ( new Privacy( $store ) )->erase( get_userdata( $id )->user_email ); }
			if ( 'delete' === $action ) { $attempt = ( new AuthorOrUserImage\User_Cleanup( $store ) )->begin_user_deletion( $id ); }
			return $metadata;
		};
		add_filter( 'wp_generate_attachment_metadata', $interleave );
		$import = $store->import_local_image( $sources->resolver( 'wp-user-avatars', $id ), $id );
		remove_filter( 'wp_generate_attachment_metadata', $interleave );
		$denied = is_wp_error( $attempt ) || ( is_array( $attempt ) && ! empty( $attempt['items_retained'] ) );
		zmai_it_check( ! is_wp_error( $import ) && $denied, $action . ' contends on owner mutex during real image metadata generation' );
		$store->delete_user_avatar( $id );
		$retry = $store->import_local_image( $sources->resolver( 'wp-user-avatars', $id ), $id );
		zmai_it_check( ! is_wp_error( $retry ) && 'explicit_choice' === $retry['status'], $action . ' later explicit removal wins on retry' );
	}
	$store->write_value( $id, Image_Store::USER_META, '', null );
	$reject = static function ( $check, $object_id, $key, $value ) { return '_wp_attachment_metadata' === $key && is_array( $value ) && isset( $value['sizes']['zmai-avatar'] ) ? false : $check; };
	add_filter( 'update_post_metadata', $reject, 10, 4 );
	$failed = $store->import_local_image( $sources->resolver( 'wp-user-avatars', $id ), $id );
	remove_filter( 'update_post_metadata', $reject );
	zmai_it_check( is_wp_error( $failed ) && ! $store->get_user_attachment_id( $id ) && $hash === hash_file( 'sha256', $file['path'] ), 'physical metadata failure rolls back destination and retains source' );
	$coordinator = $store->get_lock()->acquire_lock( 'zmai_avatar_lock_migration' );
	delete_option( 'zmai_import_job' );
	$contend = $migration->start_import( 'wp-user-avatars', wp_generate_uuid4(), 0 );
	zmai_it_check( is_wp_error( $contend ) && 'zmai_busy' === $contend->get_error_code(), 'competitor job shares migration mutex with legacy upgrade' );
	$store->get_lock()->release_lock( $coordinator );
	zmai_it_check( '12345' === get_post_meta( $attachment, '_wp_attachment_wp_user_avatar', true ) && $hash === hash_file( 'sha256', $file['path'] ), 'reverse source marker and original survive all scenarios' );
} finally {
	foreach ( $source_posts as $post_id ) { wp_delete_post( $post_id, true ); }
	zmai_it_cleanup();
}
zmai_it_done();
