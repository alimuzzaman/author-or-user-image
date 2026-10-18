<?php
// phpcs:ignoreFile -- CLI regression test: writes bounded fixtures under its approved scratch root, installs a fake editor, and prints assertion labels.
/**
 * Review regressions for square output format pinning and failure cleanup (A1),
 * plus offloaded, recompressed and per-request URL rendering (A2).
 *
 * Run with WP-CLI against a disposable local instance:
 * wp --path=<disposable> --require=<plugin>/tests/candidate-bootstrap.php eval-file <plugin>/tests/review-image-fixes.php
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Processor;
use AuthorOrUserImage\Image_Store;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use only a disposable local WordPress test instance.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( Image_Processor::class ) && defined( 'ZMAI_TEST_CANDIDATE' ) ) {
	$zmai_review_processor_file = ZMAI_TEST_CANDIDATE . '/includes/class-image-processor.php';
	if ( is_file( $zmai_review_processor_file ) ) {
		require_once $zmai_review_processor_file;
	}
}

$GLOBALS['zmai_review_checks']   = 0;
$GLOBALS['zmai_review_failures'] = array();
$GLOBALS['zmai_review_missing']  = array();
$GLOBALS['zmai_review_stage']    = '';
$GLOBALS['zmai_review_downsize'] = 0;
$GLOBALS['zmai_review_root']     = '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/fix1/fixtures-review';

/**
 * Fake editor: a real GD editor whose square saves can be forced to fail.
 * Only the processor's zmai-avatar-* outputs are affected; other saves
 * (core sub-size generation during import) pass through to GD.
 */
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
if ( ! class_exists( 'Zmai_Review_Fake_Editor' ) ) {
	class Zmai_Review_Fake_Editor extends WP_Image_Editor_GD {
		/**
		 * Force a rotation failure when staged.
		 *
		 * @return true|WP_Error
		 */
		public function maybe_exif_rotate() {
			if ( 'rotate_error' === $GLOBALS['zmai_review_stage'] ) {
				return new WP_Error( 'zmai_review_rotate', 'forced rotate failure' );
			}
			return parent::maybe_exif_rotate();
		}

		/**
		 * Force an unreadable dimension result when staged.
		 *
		 * @return array|false
		 */
		public function get_size() {
			if ( 'size_error' === $GLOBALS['zmai_review_stage'] ) {
				return false;
			}
			return parent::get_size();
		}

		/**
		 * Force a crop failure when staged.
		 *
		 * @return true|WP_Error
		 */
		public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
			if ( 'crop_error' === $GLOBALS['zmai_review_stage'] ) {
				return new WP_Error( 'zmai_review_crop', 'forced crop failure' );
			}
			return parent::crop( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h, $src_abs );
		}

		/**
		 * Force save failures for processor square outputs only.
		 *
		 * @param string|null $destfilename Destination path.
		 * @param string|null $mime_type    Requested MIME type.
		 * @return array|WP_Error
		 */
		public function save( $destfilename = null, $mime_type = null ) {
			$stage = $GLOBALS['zmai_review_stage'];
			if ( ! in_array( $stage, array( 'save_error', 'save_partial_error', 'save_wrong_metadata', 'save_exception', 'save_alternate' ), true ) || 0 !== strpos( wp_basename( (string) $destfilename ), 'zmai-avatar-' ) ) {
				return parent::save( $destfilename, $mime_type );
			}
			if ( 'save_error' === $stage ) {
				return new WP_Error( 'zmai_review_save', 'forced save failure' );
			}
			if ( 'save_partial_error' === $stage ) {
				file_put_contents( $destfilename, 'partial' );
				return new WP_Error( 'zmai_review_save', 'forced partial save failure' );
			}
			if ( 'save_exception' === $stage ) {
				file_put_contents( $destfilename, 'partial before exception' );
				throw new RuntimeException( 'forced save exception' );
			}
			if ( 'save_alternate' === $stage ) {
				return parent::save( preg_replace( '/\\.jpg$/', '.webp', $destfilename ), 'image/webp' );
			}
			$saved = parent::save( $destfilename, $mime_type );
			if ( is_array( $saved ) ) {
				$saved['width'] = max( 1, (int) $saved['width'] - 1 );
			}
			return $saved;
		}
	}
}

/**
 * Record an assertion and keep running independent cases.
 *
 * @param bool   $condition Assertion condition.
 * @param string $label     Assertion label.
 */
function zmai_review_expect( $condition, $label ) {
	++$GLOBALS['zmai_review_checks'];
	if ( ! $condition ) {
		$GLOBALS['zmai_review_failures'][] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/**
 * Record an unavailable capability without counting it as a pass.
 *
 * @param string $label Capability description.
 */
function zmai_review_missing( $label ) {
	$GLOBALS['zmai_review_missing'][] = $label;
	echo 'MISSING ' . $label . "\n";
}

/** Finish with a machine-readable summary. */
function zmai_review_finish() {
	$summary = array(
		'checks'   => $GLOBALS['zmai_review_checks'],
		'failures' => $GLOBALS['zmai_review_failures'],
		'missing'  => $GLOBALS['zmai_review_missing'],
	);
	echo wp_json_encode( $summary ) . "\n";
	WP_CLI::halt( $GLOBALS['zmai_review_failures'] ? 1 : 0 );
}

/**
 * Create a unique directory under the approved scratch root.
 *
 * @param string $label Fixture label.
 * @throws RuntimeException When the directory cannot be created.
 * @return string
 */
function zmai_review_case_dir( $label ) {
	$dir = $GLOBALS['zmai_review_root'] . '/' . sanitize_file_name( $label . '-' . wp_generate_uuid4() );
	if ( ! wp_mkdir_p( $dir ) ) {
		throw new RuntimeException( 'Could not create the approved review fixture directory.' );
	}
	return $dir;
}

/**
 * Write a gradient JPEG fixture.
 *
 * @param int    $width   Width.
 * @param int    $height  Height.
 * @param string $path    Destination.
 * @param int    $quality JPEG quality.
 * @throws RuntimeException When GD cannot allocate the image.
 */
function zmai_review_write_jpeg( $width, $height, $path, $quality = 90 ) {
	$image = imagecreatetruecolor( $width, $height );
	if ( ! $image ) {
		throw new RuntimeException( 'GD could not allocate a fixture image.' );
	}
	for ( $y = 0; $y < $height; ++$y ) {
		for ( $x = 0; $x < $width; ++$x ) {
			imagesetpixel( $image, $x, $y, imagecolorallocate( $image, ( $x * 31 + $y * 3 ) % 256, ( $y * 37 + $x * 5 ) % 256, ( $x * 11 + $y * 17 ) % 256 ) );
		}
	}
	imagejpeg( $image, $path, $quality );
	if ( PHP_VERSION_ID < 80000 ) {
		imagedestroy( $image );
	}
}

/**
 * Re-encode a JPEG in place to change its byte size.
 *
 * @param string $path    JPEG path.
 * @param int    $quality JPEG quality.
 */
function zmai_review_recompress( $path, $quality ) {
	$image = imagecreatefromjpeg( $path );
	imagejpeg( $image, $path, $quality );
	if ( PHP_VERSION_ID < 80000 ) {
		imagedestroy( $image );
	}
}

/**
 * Remove a fixture tree this suite created.
 *
 * @param string $dir Directory.
 */
function zmai_review_remove_tree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( glob( $dir . '/*' ) ?: array() as $entry ) {
		if ( is_dir( $entry ) ) {
			zmai_review_remove_tree( $entry );
		} else {
			unlink( $entry );
		}
	}
	rmdir( $dir );
}

/**
 * Upload-directory source for store imports (the store requires uploads paths).
 *
 * @param string $label Fixture label.
 * @return string Source path inside uploads.
 */
function zmai_review_upload_source( $label ) {
	$dir = wp_upload_dir()['basedir'] . '/zmai-review-' . sanitize_file_name( $label . '-' . wp_generate_uuid4() );
	wp_mkdir_p( $dir );
	$path = $dir . '/zmai-review-source.jpg';
	zmai_review_write_jpeg( 64, 48, $path );
	return $path;
}

/**
 * Create a disposable subscriber for owner-scoped avatar operations.
 *
 * @param string $label Fixture label.
 * @throws RuntimeException When the user cannot be created.
 * @return int
 */
function zmai_review_user( $label ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'zmai-review-' . $label . '-' . uniqid(),
			'user_email' => uniqid() . '@example.invalid',
			'user_pass'  => 'public-local-test-fixture',
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Could not create fixture user: ' . $id->get_error_code() );
	}
	return (int) $id;
}

/**
 * Remove a fixture user's avatar and the user itself.
 *
 * @param int $uid User ID, or zero.
 */
function zmai_review_release_user( $uid ) {
	if ( $uid ) {
		( new Image_Store() )->delete_user_avatar( $uid );
		wp_delete_user( $uid );
	}
}

/**
 * Import a local fixture for a user through the public store API.
 *
 * @param int    $uid  User ID.
 * @param string $path Source path inside uploads.
 * @return array|WP_Error
 */
function zmai_review_import( $uid, $path ) {
	return ( new Image_Store() )->import_local_image(
		static function () use ( $path ) {
			return array(
				'path' => $path,
				'name' => 'zmai-review-source.jpg',
			);
		},
		$uid
	);
}

/**
 * Describe a store or processor result for an assertion label.
 *
 * @param mixed $result Result.
 * @return string
 */
function zmai_review_status( $result ) {
	if ( is_wp_error( $result ) ) {
		return 'WP_Error ' . $result->get_error_code();
	}
	if ( is_array( $result ) && isset( $result['status'] ) ) {
		return (string) $result['status'];
	}
	return gettype( $result );
}

/**
 * Whether a WP_Error asks the caller to run recovery.
 *
 * @param mixed $result Result.
 * @return bool
 */
function zmai_review_requests_recovery( $result ) {
	if ( ! is_wp_error( $result ) ) {
		return false;
	}
	$data = $result->get_error_data();
	return is_array( $data ) && ! empty( $data['recovery'] );
}

/**
 * Stage files left in one directory.
 *
 * @param string $dir Directory.
 * @return string[]
 */
function zmai_review_stage_files( $dir ) {
	return array_values( glob( $dir . '/zmai-avatar-*' ) ?: array() );
}

/**
 * Every zmai-avatar-* file anywhere under uploads, for leak comparison.
 *
 * @return string[]
 */
function zmai_review_upload_stage_files() {
	$found    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( wp_upload_dir()['basedir'], FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 0 === strpos( $file->getFilename(), 'zmai-avatar-' ) ) {
			$found[] = $file->getPathname();
		}
	}
	sort( $found );
	return $found;
}

/**
 * Attachment row count, for rollback comparison.
 *
 * @return int
 */
function zmai_review_attachment_count() {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
}

/**
 * Prepend the fake editor to the candidate editor list.
 *
 * @param string[] $editors Editor class names.
 * @return string[]
 */
function zmai_review_fake_editor( $editors ) {
	array_unshift( $editors, 'Zmai_Review_Fake_Editor' );
	return $editors;
}

/**
 * Enable or disable the staged fake editor.
 *
 * @param string $stage Stage name, or empty to disable.
 */
function zmai_review_stage( $stage ) {
	$GLOBALS['zmai_review_stage'] = $stage;
	remove_filter( 'wp_image_editors', 'zmai_review_fake_editor', 1 );
	if ( '' !== $stage ) {
		add_filter( 'wp_image_editors', 'zmai_review_fake_editor', 1 );
	}
}

/**
 * A1: square saves pin their input MIME when a global output-format filter maps JPEG elsewhere.
 */
function zmai_review_a1_processor_mapping() {
	foreach ( array( 'webp' => 'image/webp', 'avif' => 'image/avif' ) as $label => $target ) {
		$dir = zmai_review_case_dir( 'a1-map-' . $label );
		$src = $dir . '/source.jpg';
		zmai_review_write_jpeg( 64, 48, $src );
		$map = static function ( $formats ) use ( $target ) {
			$formats['image/jpeg'] = $target;
			return $formats;
		};
		add_filter( 'image_editor_output_format', $map );
		try {
			$editor = wp_get_image_editor( $src );
			echo 'INFO ' . $label . ' default editor: ' . ( is_object( $editor ) ? get_class( $editor ) : 'none' ) . "\n";
			$square = Image_Processor::create_square( $src, 32 );
			zmai_review_expect( ! is_wp_error( $square ), 'A1 ' . $label . ' mapping: square save succeeds (' . zmai_review_status( $square ) . ')' );
			if ( ! is_wp_error( $square ) ) {
				zmai_review_expect( 'image/jpeg' === $square['mime-type'], 'A1 ' . $label . ' mapping: square keeps the pinned image/jpeg MIME' );
				zmai_review_expect( 'jpg' === pathinfo( $square['path'], PATHINFO_EXTENSION ), 'A1 ' . $label . ' mapping: square file keeps .jpg' );
			}
			zmai_review_expect( false !== has_filter( 'image_editor_output_format', $map ), 'A1 ' . $label . ' mapping: format filter stays installed after create_square' );
		} catch ( Throwable $e ) {
			zmai_review_expect( false, 'A1 ' . $label . ' mapping threw: ' . $e->getMessage() );
		} finally {
			remove_filter( 'image_editor_output_format', $map );
			zmai_review_remove_tree( $dir );
		}
	}
}

/**
 * A1: forced editor and file failures return errors, request no recovery, and leave no stage files.
 */
function zmai_review_a1_processor_failures() {
	$cases = array(
		'rotate_error'        => 'zmai_image_editor',
		'size_error'          => 'zmai_image_editor',
		'crop_error'          => 'zmai_image_editor',
		'save_error'          => 'zmai_image_save_failed',
		'save_wrong_metadata' => 'zmai_image_save_failed',
		'save_partial_error'  => 'zmai_image_save_failed',
		'save_exception'      => 'zmai_image_save_failed',
		'save_alternate'      => 'zmai_image_save_failed',
	);
	foreach ( $cases as $stage => $code ) {
		$dir = zmai_review_case_dir( 'a1-stage-' . $stage );
		$src = $dir . '/source.jpg';
		zmai_review_write_jpeg( 64, 48, $src );
		zmai_review_stage( $stage );
		try {
			$result = Image_Processor::create_square( $src, 32 );
			zmai_review_expect( is_wp_error( $result ), 'A1 ' . $stage . ' returns WP_Error (' . zmai_review_status( $result ) . ')' );
			if ( is_wp_error( $result ) ) {
				zmai_review_expect( $code === $result->get_error_code(), 'A1 ' . $stage . ' error code is ' . $code );
				zmai_review_expect( ! zmai_review_requests_recovery( $result ), 'A1 ' . $stage . ' requests no recovery fence' );
			}
			zmai_review_expect( array() === zmai_review_stage_files( $dir ), 'A1 ' . $stage . ' leaves no owned zmai-avatar stage file' );
			zmai_review_expect( is_file( $src ), 'A1 ' . $stage . ' keeps the source fixture' );
		} catch ( Throwable $e ) {
			zmai_review_expect( false, 'A1 ' . $stage . ' escaped as an exception: ' . $e->getMessage() );
			zmai_review_expect( array() === zmai_review_stage_files( $dir ), 'A1 ' . $stage . ' (after throw) leaves no owned stage file' );
		} finally {
			zmai_review_stage( '' );
			zmai_review_remove_tree( $dir );
		}
	}
}

/**
 * A1: real store imports succeed while a JPEG output-format mapping is active; owner removal still works.
 */
function zmai_review_a1_store_mapping() {
	foreach ( array( 'webp' => 'image/webp', 'avif' => 'image/avif' ) as $label => $target ) {
		$uid = 0;
		$src = zmai_review_upload_source( 'a1-store-' . $label );
		$map = static function ( $formats ) use ( $target ) {
			$formats['image/jpeg'] = $target;
			return $formats;
		};
		add_filter( 'image_editor_output_format', $map );
		try {
			$uid    = zmai_review_user( 'a1-' . $label );
			$result = zmai_review_import( $uid, $src );
			zmai_review_expect( is_array( $result ) && 'imported' === $result['status'], 'A1 ' . $label . ' mapping: store import succeeds (' . zmai_review_status( $result ) . ')' );
			zmai_review_expect( false !== has_filter( 'image_editor_output_format', $map ), 'A1 ' . $label . ' mapping: format filter stays installed after import' );
			$removed = ( new Image_Store() )->delete_user_avatar( $uid );
			zmai_review_expect( ! is_wp_error( $removed ), 'A1 ' . $label . ' mapping: remove returns no error, so owner lock was released (' . zmai_review_status( $removed ) . ')' );
		} catch ( Throwable $e ) {
			zmai_review_expect( false, 'A1 ' . $label . ' store mapping threw: ' . $e->getMessage() );
		} finally {
			remove_filter( 'image_editor_output_format', $map );
			zmai_review_release_user( $uid );
			zmai_review_remove_tree( dirname( $src ) );
		}
	}
}

/**
 * A1: a forced square save failure during import rolls back, leaves no stage files, and releases the owner lock.
 */
function zmai_review_a1_store_forced_failure() {
	$uid = 0;
	$src = zmai_review_upload_source( 'a1-store-fail' );
	try {
		$uid                = zmai_review_user( 'a1-fail' );
		$files_before       = zmai_review_upload_stage_files();
		$attachments_before = zmai_review_attachment_count();
		zmai_review_stage( 'save_error' );
		try {
			$failed = zmai_review_import( $uid, $src );
		} finally {
			zmai_review_stage( '' );
		}
		zmai_review_expect( is_wp_error( $failed ), 'A1 forced save_error during import returns WP_Error (' . zmai_review_status( $failed ) . ')' );
		zmai_review_expect( ! zmai_review_requests_recovery( $failed ), 'A1 forced save_error during import requests no recovery fence' );
		zmai_review_expect( $files_before === zmai_review_upload_stage_files(), 'A1 forced save_error leaves no zmai-avatar file under uploads' );
		zmai_review_expect( $attachments_before === zmai_review_attachment_count(), 'A1 forced save_error rolls back the sideloaded attachment' );
		zmai_review_expect( '' === ( new Image_Store() )->get_url( $uid, 32, false ), 'A1 forced save_error maps no avatar' );
		$retry = zmai_review_import( $uid, $src );
		zmai_review_expect( is_array( $retry ) && 'imported' === $retry['status'], 'A1 owner lock released after forced save_error: retry import (' . zmai_review_status( $retry ) . ')' );
	} catch ( Throwable $e ) {
		zmai_review_expect( false, 'A1 forced store failure threw: ' . $e->getMessage() );
	} finally {
		zmai_review_stage( '' );
		zmai_review_release_user( $uid );
		zmai_review_remove_tree( dirname( $src ) );
	}
}

/**
 * Move files back to their original paths, keeping anything that cannot be restored.
 *
 * @param array $moved Map of original path => offloaded path; cleared on return.
 */
function zmai_review_restore_moves( &$moved ) {
	foreach ( $moved as $from => $to ) {
		if ( ! file_exists( $from ) && file_exists( $to ) ) {
			rename( $to, $from );
		}
	}
	$moved = array();
}

/**
 * A2: rendering keeps the square URL when files are offloaded or recompressed, falls back to history, caches per request, and invalidates.
 */
function zmai_review_a2_offload() {
	$uid      = 0;
	$hist_uid = 0;
	$hist_id  = 0;
	$hist_dir = '';
	$src      = zmai_review_upload_source( 'a2' );
	$hold     = zmai_review_case_dir( 'a2-hold' );
	$moved    = array();
	$downsize = static function ( $out ) {
		++$GLOBALS['zmai_review_downsize'];
		return $out;
	};
	try {
		$uid      = zmai_review_user( 'a2' );
		$imported = zmai_review_import( $uid, $src );
		zmai_review_expect( is_array( $imported ) && 'imported' === $imported['status'], 'A2 baseline import (' . zmai_review_status( $imported ) . ')' );
		if ( ! is_array( $imported ) ) {
			return;
		}
		$id       = (int) $imported['attachment_id'];
		$original = get_attached_file( $id, true );
		$meta     = wp_get_attachment_metadata( $id, true );
		$square   = $original && isset( $meta['sizes']['zmai-avatar']['file'] ) ? dirname( $original ) . '/' . $meta['sizes']['zmai-avatar']['file'] : '';
		zmai_review_expect( '' !== $square && is_file( $square ) && is_file( $original ), 'A2 successful staging writes the managed square and original' );
		if ( '' === $square ) {
			return;
		}

		$url0 = ( new Image_Store() )->get_url( $uid, 32, false );
		zmai_review_expect( '' !== $url0, 'A2 baseline square URL resolves' );

		// Offload: move the managed square and original away after staging.
		$moved = array(
			$square   => $hold . '/square.bin',
			$original => $hold . '/original.bin',
		);
		foreach ( $moved as $from => $to ) {
			rename( $from, $to );
		}
		try {
			$url1 = ( new Image_Store() )->get_url( $uid, 32, false );
			zmai_review_expect( $url1 === $url0, 'A2 offloaded square and original keep the same URL on a fresh store' );
		} finally {
			zmai_review_restore_moves( $moved );
		}

		// Recompression: a changed byte size must not change the rendered URL.
		$backup = $hold . '/square-before-recompress.bin';
		copy( $square, $backup );
		try {
			zmai_review_recompress( $square, 25 );
			clearstatcache( true, $square );
			zmai_review_expect( filesize( $square ) !== (int) $meta['sizes']['zmai-avatar']['filesize'], 'A2 recompression changed the square byte size (fixture sanity)' );
			$url2 = ( new Image_Store() )->get_url( $uid, 32, false );
			zmai_review_expect( $url2 === $url0, 'A2 recompressed square with a changed size keeps the same URL' );
		} finally {
			copy( $backup, $square );
			unlink( $backup );
		}

		// Marked media without square metadata uses the historical attachment URL fallback (revised contract).
		$without = $meta;
		unset( $without['sizes']['zmai-avatar'] );
		wp_update_attachment_metadata( $id, $without );
		try {
			$url3       = ( new Image_Store() )->get_url( $uid, 32, false );
			$historical = wp_get_attachment_image_url( $id, array( 32, 32 ) );
			zmai_review_expect( '' !== $historical && $url3 === $historical, 'A2 marked media without square metadata uses the historical attachment URL fallback' );
		} finally {
			wp_update_attachment_metadata( $id, $meta );
		}

		// Unmarked historical media uses the historical attachment URL.
		$hist_uid = zmai_review_user( 'a2-hist' );
		$hist_dir = wp_upload_dir()['basedir'] . '/zmai-review-hist-' . wp_generate_uuid4();
		wp_mkdir_p( $hist_dir );
		$hist_file = $hist_dir . '/zmai-review-historical.jpg';
		zmai_review_write_jpeg( 64, 48, $hist_file );
		$hist_id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'zmai-review-historical',
				'post_status'    => 'inherit',
			),
			$hist_file
		);
		update_user_meta( $hist_uid, Image_Store::user_meta_key( Image_Store::USER_META ), $hist_id );
		$expected = $hist_id ? wp_get_attachment_image_url( $hist_id, array( 32, 32 ) ) : '';
		$got      = ( new Image_Store() )->get_url( $hist_uid, 32, false );
		zmai_review_expect( '' !== $expected && $got === $expected, 'A2 unmarked historical media uses the historical attachment URL' );

		// Per-request URL resolution: a second fresh store reuses the first lookup.
		add_filter( 'image_downsize', $downsize, 0, 3 );
		try {
			$GLOBALS['zmai_review_downsize'] = 0;
			$first                           = ( new Image_Store() )->get_url( $uid, 32, false );
			$GLOBALS['zmai_review_downsize'] = 0;
			$second                          = ( new Image_Store() )->get_url( $uid, 32, false );
			$second_calls                    = $GLOBALS['zmai_review_downsize'];
			zmai_review_expect( $first === $url0 && $second === $url0, 'A2 fresh stores resolve the same square URL within one request' );
			zmai_review_expect( 0 === $second_calls, 'A2 second fresh-store lookup reuses the request URL cache (image_downsize calls: ' . $second_calls . ')' );
		} finally {
			remove_filter( 'image_downsize', $downsize, 0 );
		}

		// Invalidation: remove, then replace, must not serve the old square URL.
		$removed = ( new Image_Store() )->delete_user_avatar( $uid );
		zmai_review_expect( ! is_wp_error( $removed ), 'A2 remove returns no error (' . zmai_review_status( $removed ) . ')' );
		$after_remove = ( new Image_Store() )->get_url( $uid, 32, false );
		zmai_review_expect( '' === $after_remove, 'A2 remove invalidates the same-request square URL' );

		$src2 = zmai_review_upload_source( 'a2-replace' );
		try {
			$blocked = zmai_review_import( $uid, $src2 );
			zmai_review_expect( is_array( $blocked ) && 'explicit_choice' === $blocked['status'], 'A2 import after explicit remove is explicit_choice (' . zmai_review_status( $blocked ) . ')' );
			// Clear only this synthetic user's USER_META so the replacement import is not suppressed.
			( new Image_Store() )->write_value( $uid, Image_Store::USER_META, '', null );
			$replacement = zmai_review_import( $uid, $src2 );
			zmai_review_expect( is_array( $replacement ) && 'imported' === $replacement['status'], 'A2 replacement import after clearing synthetic USER_META (' . zmai_review_status( $replacement ) . ')' );
			$url_new = ( new Image_Store() )->get_url( $uid, 32, false );
			zmai_review_expect( '' !== $url_new && $url_new !== $url0, 'A2 replacement mapping replaces the same-request URL' );
		} finally {
			zmai_review_release_user( $uid );
			$uid = 0;
			zmai_review_remove_tree( dirname( $src2 ) );
		}
	} catch ( Throwable $e ) {
		zmai_review_expect( false, 'A2 threw: ' . $e->getMessage() );
	} finally {
		zmai_review_restore_moves( $moved );
		@rmdir( $hold ); // Only removes an empty directory; anything unrestored stays.
		if ( $hist_id ) {
			wp_delete_attachment( $hist_id, true );
		}
		zmai_review_release_user( $hist_uid );
		if ( $hist_dir ) {
			zmai_review_remove_tree( $hist_dir );
		}
		zmai_review_release_user( $uid );
		zmai_review_remove_tree( dirname( $src ) );
	}
}

if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
	zmai_review_missing( 'GD JPEG support' );
	zmai_review_finish();
}

zmai_review_stage( '' );
$zmai_review_groups = array(
	'zmai_review_a1_processor_mapping',
	'zmai_review_a1_processor_failures',
	'zmai_review_a1_store_mapping',
	'zmai_review_a1_store_forced_failure',
	'zmai_review_a2_offload',
);
foreach ( $zmai_review_groups as $zmai_review_group ) {
	try {
		call_user_func( $zmai_review_group );
	} catch ( Throwable $zmai_review_error ) {
		zmai_review_expect( false, $zmai_review_group . ' threw ' . get_class( $zmai_review_error ) . ': ' . $zmai_review_error->getMessage() );
	}
}
zmai_review_stage( '' );
zmai_review_finish();
