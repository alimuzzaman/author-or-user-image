<?php
/** Isolated blogs with colliding source IDs, disjoint membership and scoped keys. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Import_Sources;
require __DIR__ . '/import-fixtures.php';
global $wpdb;
if ( ! is_multisite() ) { echo "MISSING multisite-only isolated-blog source collisions\n"; return; }
zmai_it_check( class_exists( Import_Sources::class ), 'multisite adapter exists' );
if ( ! class_exists( Import_Sources::class ) ) { zmai_it_done(); return; }
$blogs = array();
$original_blog = get_current_blog_id();
$id = zmai_it_user();
$network = get_network();
$tag = 'import-' . substr( wp_generate_uuid4(), 0, 8 );
$files = array();
$source_ids = array();
$store = new Image_Store();
$sources = new Import_Sources( $store );
try {
	foreach ( array( 'a', 'b' ) as $suffix ) {
		$blog = wpmu_create_blog( $network->domain, trailingslashit( $network->path ) . $tag . '-' . $suffix . '/', 'Importer collision fixture', 1, array( 'public' => 0 ), $network->id );
		if ( is_wp_error( $blog ) ) { throw new RuntimeException( 'Could not create isolated blog.' ); }
		$blogs[] = (int) $blog;
		switch_to_blog( $blog );
		$file = zmai_it_file();
		$attachment = wp_insert_attachment( array( 'import_id' => 500, 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_title' => 'Collision source', 'post_author' => 1 ), $file['path'] );
		$files[] = $file;
		$source_ids[] = (int) $attachment;
		restore_current_blog();
	}
	zmai_it_check( $source_ids[0] === $source_ids[1] && 500 === $source_ids[0], 'source attachments intentionally collide numerically in two site tables' );
	add_user_to_blog( $blogs[0], $id, 'subscriber' );
	switch_to_blog( $blogs[0] );
	$key_a = $wpdb->get_blog_prefix() . 'user_avatar';
	update_user_meta( $id, $key_a, $source_ids[0] );
	update_user_meta( $id, 'wp_user_avatars', array( 'media_id' => $source_ids[0], 'site_id' => $blogs[0], 'full' => $files[0]['url'] ) );
	$first = $store->import_local_image( $sources->resolver( 'one-user-avatar', $id ), $id );
	zmai_it_check( ! is_wp_error( $first ) && 'imported' === $first['status'] && $first['attachment_id'] !== $source_ids[0], 'first blog imports an administrator-authored source into new owned media' );
	$first_id = $store->get_user_attachment_id( $id );
	restore_current_blog();
	switch_to_blog( $blogs[1] );
	$gone = $store->import_local_image( $sources->resolver( 'wp-user-avatars', $id ), $id );
	zmai_it_check( ! is_wp_error( $gone ) && 'gone' === $gone['status'], 'nonmember cannot receive an avatar on the second blog' );
	add_user_to_blog( $blogs[1], $id, 'subscriber' );
	$other = $store->import_local_image( $sources->resolver( 'wp-user-avatars', $id ), $id );
	zmai_it_check( is_wp_error( $other ) && 'zmai_import_other_site' === $other->get_error_code() && ! $store->get_user_attachment_id( $id ), 'foreign site_id is rejected before following the colliding attachment ID' );
	$absent = $sources->resolve( 'one-user-avatar', $id );
	zmai_it_check( is_wp_error( $absent ) && 'zmai_import_no_source' === $absent->get_error_code() && $key_a !== $wpdb->get_blog_prefix() . 'user_avatar', 'prefixed source on another blog is never used as a local historical fallback' );
	update_user_meta( $id, 'wp_user_avatars', array( 'media_id' => $source_ids[1], 'site_id' => $blogs[1], 'full' => $files[1]['url'] ) );
	$second = $store->import_local_image( $sources->resolver( 'wp-user-avatars', $id ), $id );
	zmai_it_check( ! is_wp_error( $second ) && 'imported' === $second['status'] && $store->owns_attachment( $second['attachment_id'], $id ), 'second site independently imports its current-table source' );
	$store->delete_user_avatar( $id );
	zmai_it_check( get_post( $source_ids[1] ) && is_file( $files[1]['path'] ), 'second-site avatar removal retains its competitor source' );
	restore_current_blog();
	switch_to_blog( $blogs[0] );
	zmai_it_check( $first_id === $store->get_user_attachment_id( $id ) && get_post( $source_ids[0] ) && is_file( $files[0]['path'] ), 'second-site cleanup does not change first-site imported mapping or source' );
	restore_current_blog();
} finally {
	while ( get_current_blog_id() !== $original_blog && ms_is_switched() ) { restore_current_blog(); }
	foreach ( $blogs as $blog ) {
		switch_to_blog( $blog ); $store->delete_user_avatar( $id ); restore_current_blog();
		wpmu_delete_blog( $blog, true );
	}
	zmai_it_cleanup();
}
zmai_it_done();
