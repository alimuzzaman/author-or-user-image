<?php
/** Literal-directory cleanup and failed-listing recovery regressions. */
use AuthorOrUserImage\Image_Processor;
use AuthorOrUserImage\Image_Store;
require __DIR__ . '/import-fixtures.php';

$directory = get_temp_dir() . 'zmai-cleanup-[2]*?-' . wp_generate_uuid4();
wp_mkdir_p( $directory );
$square = new ReflectionMethod( Image_Processor::class, 'square_failure' );
$stage  = new ReflectionMethod( Image_Store::class, 'stage_media' );
if ( PHP_VERSION_ID < 80100 ) {
	$square->setAccessible( true );
	$stage->setAccessible( true );
}
$store = new Image_Store();
$stem = 'zmai-avatar-' . str_replace( '-', '', wp_generate_uuid4() );
$paths = array( $directory . '/' . $stem . '.png', $directory . '/' . $stem . '.webp' );
$unrelated = $directory . '/' . $stem . 'other.png';
try {
	foreach ( array_merge( $paths, array( $unrelated ) ) as $path ) { file_put_contents( $path, 'partial' ); }
	$result = $square->invoke( null, $paths[0], 'Fixture save failed.' );
	zmai_it_check( is_wp_error( $result ), 'N1 square failure returns an error' );
	zmai_it_check( ! is_file( $paths[0] ) && ! is_file( $paths[1] ), 'N1 literal metacharacter directory cleans exact square stem and alternate extension' );
	zmai_it_check( is_file( $unrelated ), 'N1 square cleanup preserves a different stem sharing the prefix' );

	// Exercise core's upload journal and failure cleanup without an attachment row.
	$stem = 'zmai-upload-' . wp_generate_uuid4();
	$original = $directory . '/' . $stem . '.png';
	$subsize = $directory . '/' . $stem . '-150x150.png';
	$scaled = $directory . '/' . $stem . '-scaled-150x150.webp';
	$other = $directory . '/' . $stem . 'other.png';
	foreach ( array( $original, $subsize, $scaled, $other ) as $path ) { file_put_contents( $path, 'partial' ); }
	$result = $stage->invoke( $store, static function () use ( $original ) {
		apply_filters( 'wp_handle_upload', array( 'file' => $original, 'url' => 'https://example.invalid/' . basename( $original ), 'type' => 'image/png' ) );
		return new WP_Error( 'fixture_insert_failed' );
	} );
	zmai_it_check( is_wp_error( $result ), 'N1 staged failure returns an error' );
	zmai_it_check( ! is_file( $original ) && ! is_file( $subsize ) && ! is_file( $scaled ), 'N1 literal metacharacter directory cleans staged original and subsizes' );
	zmai_it_check( is_file( $other ), 'N1 staging cleanup preserves a different stem sharing the prefix' );

	// A missing directory is a real failed listing, independent of process privileges.
	$missing = $directory . '/missing/' . $stem . '.png';
	$result = $square->invoke( null, $missing, 'Fixture inventory failed.' );
	$data = $result->get_error_data();
	zmai_it_check( is_array( $data ) && in_array( basename( $missing ), $data['cleanup_files'] ?? array(), true ) && ! empty( $data['recovery'] ), 'N1 square failed listing retains cleanup inventory and recovery' );
	$result = $stage->invoke( $store, static function () use ( $missing ) {
		apply_filters( 'wp_handle_upload', array( 'file' => $missing, 'url' => 'https://example.invalid/' . basename( $missing ), 'type' => 'image/png' ) );
		return new WP_Error( 'fixture_insert_failed' );
	} );
	$data = $result->get_error_data();
	zmai_it_check( is_array( $data ) && ! empty( $data['recovery'] ), 'N1 staging failed listing retains recovery' );
} finally {
	foreach ( scandir( $directory ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) { unlink( $directory . '/' . $entry ); }
	}
	rmdir( $directory );
	zmai_it_cleanup();
}
zmai_it_done();
