<?php
/** Media faults during avatar upload: insert, metadata, editor and read failures; locks and staged rows reconciled exactly. */
use AuthorOrUserImage\Image_Store;
require __DIR__ . '/import-fixtures.php';
require_once __DIR__ . '/migration-upload-transport.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/** Partial-write editor: a save to a zmai-avatar destination leaves bytes, then throws; other saves pass through. */
class Zmai_Rme_Partial_Editor extends WP_Image_Editor_GD {
	public function save( $destfile = null, $mime_type = null ) {
		if ( is_string( $destfile ) && false !== strpos( basename( $destfile ), 'zmai-avatar' ) ) {
			++$GLOBALS['zmai_rme_hits'];
			file_put_contents( $destfile, 'partial' );
			throw new RuntimeException( 'Fixture editor failed after a partial write.' );
		}
		return parent::save( $destfile, $mime_type );
	}
}

/** PNG of the given size in the temp dir, so it is never counted as an uploads-dir leftover. */
function zmai_rme_source( $dim ) {
	$path  = get_temp_dir() . 'zmai-rme-src-' . wp_generate_uuid4() . '.png';
	$image = imagecreatetruecolor( $dim[0], $dim[1] );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 40, 90, 200 ) );
	imagepng( $image, $path );
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
	return $path;
}

/** One save through the real path with a fresh source; the source is removed if core did not move it. */
function zmai_rme_save( $store, $user_id, $dim ) {
	$source = zmai_rme_source( $dim );
	$_FILES['zmai_rme'] = array( 'name' => 'avatar.png', 'type' => 'image/png', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $source ) );
	try {
		return $store->save_upload( 'zmai_rme', $user_id );
	} catch ( \Throwable $error ) {
		zmai_it_check( false, 'save_upload threw ' . get_class( $error ) . ': ' . $error->getMessage() );
		return null;
	} finally {
		unset( $_FILES['zmai_rme'] );
		if ( is_file( $source ) ) { wp_delete_file( $source ); }
	}
}

/** Every file currently in the uploads month directory. */
function zmai_rme_files() {
	$uploads = wp_upload_dir();
	return array_values( array_filter( (array) glob( $uploads['path'] . '/*' ), 'is_file' ) );
}

/** Attachment IDs carrying this user's exact owner token, in any status. */
function zmai_rme_owned_ids( $store, $user_id ) {
	return array_map( 'intval', get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_key'       => Image_Store::ATTACHMENT_OWNER_META,
		'meta_value'     => $store->owner_name( $user_id ),
	) ) );
}

/** True when the owner lock for this user can be taken and released again. */
function zmai_rme_lock_free( $store, $user_id ) {
	return 'free' === $store->get_lock()->with_owner_lock( $user_id, static function () { return 'free'; } );
}

/** Token of this user's owner-lock row, or null when no row is held. */
function zmai_rme_lock_token( $user_id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'zmai_avatar_lock_user_' . $user_id ) );
}

/**
 * Remove only what this case created: its exact attachment IDs, new uploads-dir files,
 * then the exact owner-lock token. Runs in finally so cleanup never depends on the outcome.
 */
function zmai_rme_reconcile( $store, $user_id, $before_files, $before_ids ) {
	global $wpdb;
	foreach ( array_diff( zmai_rme_owned_ids( $store, $user_id ), $before_ids ) as $id ) {
		$cleanup = $store->delete_attachment_checked( $id );
		zmai_it_check( ! is_wp_error( $cleanup ), 'reconcile: exact fixture attachment ' . $id . ' removed' );
	}
	foreach ( array_diff( zmai_rme_files(), $before_files ) as $path ) { wp_delete_file( $path ); }
	$token = zmai_rme_lock_token( $user_id );
	if ( null !== $token ) {
		$released = $store->get_lock()->release_lock( array( 'table' => $wpdb->options, 'name' => 'zmai_avatar_lock_user_' . $user_id, 'token' => (string) $token ) );
		zmai_it_check( ! is_wp_error( $released ), 'reconcile: exact owner lock token released' );
	}
}

/** Forced core failure: an attachment insert returns an empty-content error after the upload moved. */
function zmai_rme_empty_content( $empty, $post ) {
	if ( 'insert' !== $GLOBALS['zmai_rme_fault'] || 'attachment' !== ( $post['post_type'] ?? '' ) ) { return $empty; }
	++$GLOBALS['zmai_rme_hits'];
	return true;
}

/** Forced metadata failure after the attachment row exists. */
function zmai_rme_metadata_throw( $metadata, $attachment_id ) {
	if ( in_array( $GLOBALS['zmai_rme_fault'], array( 'metadata', 'rename' ), true ) ) {
		++$GLOBALS['zmai_rme_hits'];
		throw new RuntimeException( 'Fixture metadata failed after insert.' );
	}
	return $metadata;
}

/** Simulate a rename-on-upload plugin after the store chooses its UUID name. */
function zmai_rme_rename( $filename ) {
	if ( 'rename' === $GLOBALS['zmai_rme_fault'] && 0 === strpos( $filename, 'zmai-upload-' ) ) {
		++$GLOBALS['zmai_rme_hits'];
		return 'renamed-' . $filename;
	}
	return $filename;
}

/** Forced failure only for the processor-generated avatar size. */
function zmai_rme_update_throw( $data, $attachment_id ) {
	if ( 'update' === $GLOBALS['zmai_rme_fault'] && isset( $data['sizes']['zmai-avatar'] ) ) {
		++$GLOBALS['zmai_rme_hits'];
		throw new RuntimeException( 'Fixture metadata update failed for the avatar size.' );
	}
	return $data;
}

/** Route image edits through the partial-write editor only during its fault case. */
function zmai_rme_editor( $editors ) {
	if ( 'editor' === $GLOBALS['zmai_rme_fault'] ) { array_unshift( $editors, 'Zmai_Rme_Partial_Editor' ); }
	return $editors;
}

/** Arm the read fault only once the attachment row exists, so it lands after mutation. */
function zmai_rme_armed_after_insert() {
	if ( 'read' === $GLOBALS['zmai_rme_fault'] ) { $GLOBALS['zmai_rme_inserted'] = true; }
}

/** Forced database read failure on attachment metadata, only after the insert. */
function zmai_rme_read_fault( $sql ) {
	if ( 'read' !== $GLOBALS['zmai_rme_fault'] || empty( $GLOBALS['zmai_rme_inserted'] ) || false === strpos( $sql, '_wp_attachment_metadata' ) || 0 !== stripos( ltrim( $sql ), 'SELECT' ) ) {
		return $sql;
	}
	++$GLOBALS['zmai_rme_hits'];
	return 'SELECT zmai_rme_read_fault FROM ' . $GLOBALS['wpdb']->prefix . 'zmai_rme_missing';
}

/**
 * One faulted save after a genuine prior avatar. The prior mapping and file must survive.
 * $mode 'clean': WP_Error without recovery, owner lock released, no staged rows or files.
 * $mode 'fence': WP_Error with recovery, owner lock held, staged rows retained until reconciliation.
 */
function zmai_rme_case( $store, $label, $fault, $dim, $mode ) {
	global $wpdb;
	$user_id = zmai_it_user();
	$first   = zmai_rme_save( $store, $user_id, $dim );
	zmai_it_check( is_int( $first ) && $first > 0, $label . ': genuine prior avatar stored' );
	$before_files = zmai_rme_files();
	$before_ids   = zmai_rme_owned_ids( $store, $user_id );
	$GLOBALS['zmai_rme_hits']     = 0;
	$GLOBALS['zmai_rme_inserted'] = false;
	$GLOBALS['zmai_rme_fault']    = $fault;
	$previous = $wpdb->suppress_errors( 'read' === $fault );
	try {
		$result = zmai_rme_save( $store, $user_id, $dim );
	} finally {
		$GLOBALS['zmai_rme_fault'] = '';
		$wpdb->suppress_errors( $previous );
	}
	try {
		$data     = is_wp_error( $result ) ? $result->get_error_data() : null;
		$recovery = is_array( $data ) && ! empty( $data['recovery'] );
		$fence    = 'fence' === $mode;
		zmai_it_check( $GLOBALS['zmai_rme_hits'] > 0, $label . ': fault reached' );
		zmai_it_check( is_wp_error( $result ), $label . ': WP_Error returned' );
		zmai_it_check( $fence === $recovery, $label . ( $fence ? ': recovery fence set' : ': no recovery flag' ) );
		zmai_it_check( null !== $first && (int) $store->get_user_attachment_id( $user_id ) === (int) $first, $label . ': prior mapping unchanged' );
		zmai_it_check( null !== $first && is_file( (string) get_attached_file( $first ) ), $label . ': prior source file intact' );
		$free      = zmai_rme_lock_free( $store, $user_id );
		$new_files = array_values( array_diff( zmai_rme_files(), $before_files ) );
		$new_ids   = array_values( array_diff( zmai_rme_owned_ids( $store, $user_id ), $before_ids ) );
		zmai_it_check( $fence ? ! $free : $free, $label . ( $fence ? ': owner lock held by fence' : ': owner lock released' ) );
		if ( $fence ) {
			zmai_it_check( array() !== $new_files && array() !== $new_ids, $label . ': staged rows retained until reconciliation' );
		} else {
			zmai_it_check( array() === $new_files, $label . ': no staged files' );
			zmai_it_check( array() === $new_ids, $label . ': no staged attachments' );
		}
	} finally {
		zmai_rme_reconcile( $store, $user_id, $before_files, $before_ids );
	}
}

$GLOBALS['zmai_rme_fault']    = '';
$GLOBALS['zmai_rme_hits']     = 0;
$GLOBALS['zmai_rme_inserted'] = false;
add_filter( 'wp_insert_post_empty_content', 'zmai_rme_empty_content', 10, 2 );
add_filter( 'wp_generate_attachment_metadata', 'zmai_rme_metadata_throw', 10, 2 );
add_filter( 'wp_update_attachment_metadata', 'zmai_rme_update_throw', 10, 2 );
add_filter( 'wp_image_editors', 'zmai_rme_editor' );
add_filter( 'query', 'zmai_rme_read_fault' );
add_action( 'add_attachment', 'zmai_rme_armed_after_insert' );
add_filter( 'sanitize_file_name', 'zmai_rme_rename' );
try {
	$store = new Image_Store();
	zmai_rme_case( $store, 'insert failure', 'insert', array( 400, 400 ), 'clean' );
	zmai_rme_case( $store, 'metadata throw', 'metadata', array( 400, 400 ), 'clean' );
	zmai_rme_case( $store, 'avatar size update throw', 'update', array( 400, 400 ), 'clean' );
	zmai_rme_case( $store, 'partial editor save', 'editor', array( 61, 21 ), 'clean' );
	zmai_rme_case( $store, 'read failure after insert', 'read', array( 400, 400 ), 'fence' );
	zmai_rme_case( $store, 'renamed upload metadata throw', 'rename', array( 400, 400 ), 'fence' );
	// A failed sideload leaves the plugin's temporary copy, not uncertain DB state.
	$user_id = zmai_it_user();
	$source = zmai_it_file();
	$temporary = '';
	$fail_sideload = static function ( $file ) use ( &$temporary ) {
		$temporary = $file['tmp_name'];
		$file['error'] = 'Fixture sideload refused.';
		return $file;
	};
	$veto_temporary = static function ( $file ) use ( &$temporary ) { return $temporary === $file ? '' : $file; };
	add_filter( 'wp_handle_sideload_prefilter', $fail_sideload );
	add_filter( 'wp_delete_file', $veto_temporary );
	try {
		$result = $store->import_local_image( static function () use ( $source ) { return array( 'path' => $source['path'], 'name' => basename( $source['path'] ) ); }, $user_id );
	} finally {
		remove_filter( 'wp_handle_sideload_prefilter', $fail_sideload );
		remove_filter( 'wp_delete_file', $veto_temporary );
	}
	$data = is_wp_error( $result ) ? $result->get_error_data() : null;
	zmai_it_check( '' !== $temporary && is_wp_error( $result ), 'temporary-copy fault reached and reported' );
	zmai_it_check( ! is_array( $data ) || empty( $data['recovery'] ), 'temporary-copy refusal requests no database recovery fence' );
	zmai_it_check( ! is_file( $temporary ), 'temporary-copy refusal cleans its private temporary file' );
	zmai_it_check( zmai_rme_lock_free( $store, $user_id ), 'temporary-copy refusal releases the owner lock' );
	wp_delete_file( $temporary );
	$before_files = zmai_rme_files();
	zmai_rme_reconcile( $store, $user_id, $before_files, array() );
} finally {
	remove_filter( 'wp_insert_post_empty_content', 'zmai_rme_empty_content', 10 );
	remove_filter( 'wp_generate_attachment_metadata', 'zmai_rme_metadata_throw', 10 );
	remove_filter( 'wp_update_attachment_metadata', 'zmai_rme_update_throw', 10 );
	remove_filter( 'wp_image_editors', 'zmai_rme_editor' );
	remove_filter( 'query', 'zmai_rme_read_fault' );
	remove_action( 'add_attachment', 'zmai_rme_armed_after_insert' );
	remove_filter( 'sanitize_file_name', 'zmai_rme_rename' );
	$GLOBALS['zmai_rme_fault'] = '';
	zmai_it_cleanup();
}
zmai_it_done();
