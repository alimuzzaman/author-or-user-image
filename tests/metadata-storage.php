<?php
/** Complete newly owned inventory, copy-only and failure-path privacy checks. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
require __DIR__ . '/import-fixtures.php';
require_once __DIR__ . '/metadata-fixtures.php';
require_once __DIR__ . '/migration-upload-transport.php';

function zmai_ms_source( $width = 120, $height = 80 ) {
	$path = '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/fix3/fixtures/storage-' . wp_generate_uuid4() . '.jpg';
	wp_mkdir_p( dirname( $path ) );
	$im = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $im, 0, 0, $width - 1, $height - 1, imagecolorallocate( $im, 240, 30, 50 ) );
	imagejpeg( $im, $path, 95 );
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $im ); }
	$exif = "Exif\0\0" . zmai_mf_tiff( zmai_mf_thumbnail() );
	$dirty = '';
	foreach ( array( 0xe1 => $exif, 0xed => 'Photoshop 3.0 location-private', 0xfe => 'private comment' ) as $marker => $payload ) {
		$dirty .= "\xff" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}
	$dirty .= "\xff\xe1" . pack( 'n', 44 ) . str_pad( 'http://ns.adobe.com/xap/1.0/' . "\0GPS private", 42, ' ' );
	$dirty .= zmai_mf_segment( 0xe2, "ICC_PROFILE\0\x01\x01" . zmai_mf_srgb_profile() );
	$bytes = file_get_contents( $path );
	file_put_contents( $path, substr( $bytes, 0, 2 ) . $dirty . substr( $bytes, 2 ) );
	return $path;
}

/** Independent marker walker, including progressive/multiple entropy scans. */
function zmai_ms_clean( $path ) {
	$b = file_get_contents( $path );
	if ( "\xff\xd8" !== substr( $b, 0, 2 ) ) { return false; }
	$p = 2;
	while ( $p < strlen( $b ) ) {
		if ( "\xff" !== $b[ $p++ ] ) { return false; }
		while ( $p < strlen( $b ) && "\xff" === $b[ $p ] ) { ++$p; }
		if ( $p >= strlen( $b ) ) { return false; }
		$m = ord( $b[ $p++ ] );
		if ( 0xd9 === $m ) { return $p === strlen( $b ); }
		if ( in_array( $m, array( 0xe1, 0xed, 0xfe ), true ) || $p + 2 > strlen( $b ) ) { return false; }
		$n = unpack( 'n', substr( $b, $p, 2 ) )[1];
		if ( $n < 2 || $p + $n > strlen( $b ) ) { return false; }
		$p += $n;
		if ( 0xda === $m ) {
			while ( $p < strlen( $b ) ) {
				if ( "\xff" !== $b[ $p ] ) { ++$p; continue; }
				$q = $p + 1;
				while ( $q < strlen( $b ) && "\xff" === $b[ $q ] ) { ++$q; }
				if ( $q >= strlen( $b ) ) { return false; }
				$v = ord( $b[ $q ] );
				if ( 0 === $v || ( $v >= 0xd0 && $v <= 0xd7 ) ) { $p = $q + 1; } else { break; }
			}
		}
	}
	return false;
}

function zmai_ms_inventory( $id, $before, $label ) {
	$uploads = wp_upload_dir();
	$after = array_values( array_filter( glob( $uploads['path'] . '/*' ), 'is_file' ) );
	$new = array_diff( $after, $before );
	zmai_it_check( $id > 0 && count( $new ) >= ( false !== strpos( $label, 'legacy' ) ? 1 : 2 ), $label . ' stores expected original and rendition inventory' );
	foreach ( $new as $path ) {
		zmai_it_check( zmai_ms_clean( $path ), $label . ' clean newly stored file ' . basename( $path ) );
		zmai_it_check( false !== strpos( file_get_contents( $path ), "ICC_PROFILE\0\x01\x01" . zmai_mf_srgb_profile() ), $label . ' retains compatible ICC in ' . basename( $path ) );
	}
	if ( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$im = $meta['image_meta'] ?? array();
		zmai_it_check( empty( $im['camera'] ) && empty( $im['credit'] ) && empty( $im['caption'] ) && empty( $im['created_timestamp'] ), $label . ' attachment image_meta has no personal fields' );
		zmai_it_check( '' === get_post_field( 'post_excerpt', $id ) && '' === get_post_field( 'post_content', $id ), $label . ' attachment caption and content are empty' );
	}
}

$store = new Image_Store();
$classification = new ReflectionMethod( Migration::class, 'permanent_import_reason' );
if ( PHP_VERSION_ID < 80100 ) { $classification->setAccessible( true ); }
zmai_it_check( 'metadata_cmyk' === $classification->invoke( new Migration( $store ), 'zmai_metadata_cmyk' ), 'unsupported GD CMYK is a bounded permanent import skip' );
zmai_it_check( '' === $classification->invoke( new Migration( $store ), 'zmai_metadata_failed' ), 'generic sanitizer processing failure remains retryable' );

$saved_default = get_option( Image_Store::DEFAULT_OPTION, null );
$saved_migrated = get_option( Image_Store::MIGRATED_DEFAULT_OPTION, null );
$saved_policy = get_option( 'zmai_upload_policy', null );
$default_ids = array();
wp_set_current_user( 1 );
delete_option( 'zmai_upload_policy' );
$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
$files = static function () use ( $uploads ) { return array_values( array_filter( glob( $uploads['path'] . '/*' ), 'is_file' ) ); };
$fallback = static function ( $sizes ) { unset( $sizes['zmai-avatar'] ); return $sizes; };
try {
	foreach ( array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ) as $backend ) {
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
		if ( ! $backend::test() ) { echo 'SKIP ' . $backend . " unavailable\n"; continue; }
		$select = static function () use ( $backend ) { return array( $backend ); };
		add_filter( 'wp_image_editors', $select, PHP_INT_MAX );
		add_filter( 'image_strip_meta', '__return_false', PHP_INT_MAX );
		try {
			foreach ( array( 'import', 'fallback', 'scaled', 'user', 'default', 'legacy', 'legacy-default' ) as $mode ) {
				$uid = zmai_it_user();
				$source = zmai_ms_source( 'scaled' === $mode ? 3001 : 120 );
				$hash = hash_file( 'sha256', $source );
				zmai_it_check( 'Fixture' === ( wp_read_image_metadata( $source )['camera'] ?? '' ), $backend . ' ' . $mode . ' input camera metadata exists before sanitation' );
				$before = $files();
				if ( 'fallback' === $mode ) { add_filter( 'intermediate_image_sizes_advanced', $fallback, PHP_INT_MAX ); }
				try {
					if ( in_array( $mode, array( 'user', 'default' ), true ) ) {
						$owner = 'default' === $mode ? 0 : $uid;
						if ( ! $owner ) { delete_option( Image_Store::DEFAULT_OPTION ); delete_option( Image_Store::MIGRATED_DEFAULT_OPTION ); }
						$_FILES['metadata'] = array( 'tmp_name' => $source, 'name' => 'avatar.jpg', 'size' => filesize( $source ), 'error' => 0 );
						$r = $store->save_upload( 'metadata', $owner );
						$id = is_wp_error( $r ) ? 0 : (int) $r;
						if ( ! $owner && $id ) { $default_ids[] = $id; }
					} elseif ( in_array( $mode, array( 'legacy', 'legacy-default' ), true ) ) {
						$owner = 'legacy-default' === $mode ? 0 : $uid;
						if ( ! $owner ) { delete_option( Image_Store::DEFAULT_OPTION ); delete_option( Image_Store::MIGRATED_DEFAULT_OPTION ); }
						$m = new ReflectionMethod( Migration::class, 'sideload_legacy_image' );
						if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
						$r = $m->invoke( new Migration( $store ), $source, $owner );
						$id = $owner ? $store->get_user_attachment_id( $owner ) : $store->get_default_attachment_id();
						if ( ! $owner && $id ) { $default_ids[] = $id; }
					} else {
						// The store accepts uploads-contained sources only.
						$local = $uploads['path'] . '/metadata-source-' . wp_generate_uuid4() . '.jpg';
						copy( $source, $local );
						$GLOBALS['zmai_it_files'][] = $local;
						$before = $files();
						$r = $store->import_local_image( static function () use ( $local ) { return array( 'path' => $local, 'name' => 'avatar.jpg' ); }, $uid );
						$id = is_wp_error( $r ) ? 0 : (int) $r['attachment_id'];
						zmai_it_check( $hash === hash_file( 'sha256', $local ), $backend . ' ' . $mode . ' competitor source unchanged' );
					}
					zmai_it_check( ! is_wp_error( $r ) && false !== $r, $backend . ' ' . $mode . ' succeeds' );
					zmai_ms_inventory( $id, $before, $backend . ' ' . $mode );
					if ( is_file( $source ) ) { zmai_it_check( $hash === hash_file( 'sha256', $source ), $backend . ' ' . $mode . ' private/source cleanup preserves retained source' ); }
				} finally {
					remove_filter( 'intermediate_image_sizes_advanced', $fallback, PHP_INT_MAX );
					unset( $_FILES['metadata'] );
					if ( is_file( $source ) ) { unlink( $source ); }
				}
			}
		} finally {
			remove_filter( 'image_strip_meta', '__return_false', PHP_INT_MAX );
			remove_filter( 'wp_image_editors', $select, PHP_INT_MAX );
		}
	}
	// A trusted filter exception must fail before any media handler or mapping write.
	$uid = zmai_it_user();
	$source = zmai_ms_source();
	$_FILES['metadata'] = array( 'tmp_name' => $source, 'name' => 'avatar.jpg', 'size' => filesize( $source ), 'error' => 0 );
	$previous = $store->save_upload( 'metadata', $uid );
	zmai_it_check( is_int( $previous ) && $previous > 0, 'failure injection has a previous avatar control' );
	$source = zmai_ms_source();
	$_FILES['metadata'] = array( 'tmp_name' => $source, 'name' => 'avatar.jpg', 'size' => filesize( $source ), 'error' => 0 );
	$before = $files();
	$throw = static function () { throw new RuntimeException( 'Private sanitation fault.' ); };
	add_filter( 'zmai_strip_image_metadata', $throw );
	$legacy_source = zmai_ms_source();
	$legacy_uid = zmai_it_user();
	try {
		$rejected = $store->save_upload( 'metadata', $uid );
		$m = new ReflectionMethod( Migration::class, 'sideload_legacy_image' );
		if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
		$legacy_rejected = $m->invoke( new Migration( $store ), $legacy_source, $legacy_uid );
		zmai_it_check( is_wp_error( $legacy_rejected ) && 'zmai_metadata_failed' === $legacy_rejected->get_error_code(), 'legacy sanitizer failure preserves typed error' );
	} finally {
		remove_filter( 'zmai_strip_image_metadata', $throw );
		if ( is_file( $legacy_source ) ) { unlink( $legacy_source ); }
	}
	zmai_it_check( is_wp_error( $rejected ) && 'zmai_metadata_failed' === $rejected->get_error_code(), 'sanitizer exception returns typed error' );
	zmai_it_check( $previous === $store->get_user_attachment_id( $uid ) && $before === $files(), 'sanitizer failure leaves mapping and public inventory unchanged' );
	zmai_it_check( ! is_file( $source ), 'sanitizer failure removes private upload bytes' );
	if ( is_file( $source ) ) { unlink( $source ); }
	unset( $_FILES['metadata'] );
} finally {
	foreach ( $default_ids as $id ) { $store->delete_attachment_checked( $id ); }
	foreach ( array( Image_Store::DEFAULT_OPTION => $saved_default, Image_Store::MIGRATED_DEFAULT_OPTION => $saved_migrated, 'zmai_upload_policy' => $saved_policy ) as $key => $value ) {
		if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); }
	}
	zmai_it_cleanup();
}
zmai_it_done();
