<?php
// phpcs:ignoreFile WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations,WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- This CLI test writes bounded binary fixtures only in its approved scratch directory and prints assertion labels.
/**
 * Real local image validation and crop checks. Run with WP-CLI against a
 * disposable local site and tests/candidate-bootstrap.php.
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Processor;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use only a disposable local WordPress test instance.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

if ( ! class_exists( Image_Processor::class ) && defined( 'ZMAI_TEST_CANDIDATE' ) ) {
	$zmai_processor_file = ZMAI_TEST_CANDIDATE . '/includes/class-image-processor.php';
	if ( is_file( $zmai_processor_file ) ) {
		require_once $zmai_processor_file;
	}
}

$GLOBALS['zmai_image_failures'] = array();
$GLOBALS['zmai_image_checks']   = 0;
$GLOBALS['zmai_image_missing']  = array();
$GLOBALS['zmai_image_root']     = '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/fixtures';

/** Record an assertion and keep running independent cases.
 *
 * @param bool   $condition Assertion condition.
 * @param string $label Assertion label.
 */
function zmai_image_expect( $condition, $label ) {
	++$GLOBALS['zmai_image_checks'];
	if ( ! $condition ) {
		$GLOBALS['zmai_image_failures'][] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/** Record an unavailable capability without counting it as a passing test.
 *
 * @param string $label Capability description.
 */
function zmai_image_missing( $label ) {
	$GLOBALS['zmai_image_missing'][] = $label;
	echo 'MISSING ' . $label . "\n";
}

/** Finish a test run with a machine-readable summary. */
function zmai_image_finish() {
	$summary = array(
		'checks'  => $GLOBALS['zmai_image_checks'],
		'failures' => $GLOBALS['zmai_image_failures'],
		'missing' => $GLOBALS['zmai_image_missing'],
	);
	echo wp_json_encode( $summary ) . "\n";
	if ( $GLOBALS['zmai_image_failures'] ) {
		WP_CLI::halt( 1 );
	}
	WP_CLI::halt( 0 );
}

/** Create a writable, task-scoped fixture directory.
 *
 * @throws RuntimeException When the approved scratch directory cannot be created.
 * @return string
 */
function zmai_image_prepare_directory() {
	$directory = $GLOBALS['zmai_image_root'];
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( 'Could not create the approved image fixture directory.' );
	}
	return $directory;
}

/** Create a unique fixture filename inside the approved scratch root.
 *
 * @param string $label Fixture label.
 * @param string $extension Filename extension.
 * @return string
 */
function zmai_image_path( $label, $extension ) {
	return $GLOBALS['zmai_image_root'] . '/' . sanitize_file_name( $label . '-' . wp_generate_uuid4() ) . '.' . $extension;
}

/** Make an image whose pixels encode their source coordinates.
 *
 * @param int    $width Image width.
 * @param int    $height Image height.
 * @param string $extension Image extension.
 * @throws RuntimeException When GD cannot allocate the image.
 * @return string
 */
function zmai_image_write_grid( $width, $height, $extension = 'png' ) {
	$image = imagecreatetruecolor( $width, $height );
	if ( ! $image ) {
		throw new RuntimeException( 'GD could not allocate a fixture image.' );
	}
	for ( $y = 0; $y < $height; ++$y ) {
		for ( $x = 0; $x < $width; ++$x ) {
			$color = imagecolorallocate( $image, ( $x * 31 + $y * 3 ) % 256, ( $y * 37 + $x * 5 ) % 256, ( $x * 11 + $y * 17 ) % 256 );
			imagesetpixel( $image, $x, $y, $color );
		}
	}
	$path = zmai_image_path( 'grid-' . $width . 'x' . $height, $extension );
	if ( 'png' === $extension ) {
		imagepng( $image, $path );
	} elseif ( 'jpg' === $extension ) {
		imagejpeg( $image, $path, 100 );
	} elseif ( 'webp' === $extension && function_exists( 'imagewebp' ) ) {
		imagewebp( $image, $path, 100 );
	}
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
	return $path;
}

/** Write a four-color JPEG with large regions for EXIF orientation checks.
 *
 * @param int $width Image width.
 * @param int $height Image height.
 * @return string
 */
function zmai_image_write_quadrants( $width, $height ) {
	$image  = imagecreatetruecolor( $width, $height );
	$colors = array(
		imagecolorallocate( $image, 240, 20, 20 ),
		imagecolorallocate( $image, 20, 220, 20 ),
		imagecolorallocate( $image, 20, 20, 240 ),
		imagecolorallocate( $image, 240, 220, 20 ),
	);
	$path = zmai_image_path( 'orientation-' . $width . 'x' . $height, 'jpg' );
	for ( $y = 0; $y < $height; ++$y ) {
		for ( $x = 0; $x < $width; ++$x ) {
			$index = ( $y >= (int) ( $height / 2 ) ? 2 : 0 ) + ( $x >= (int) ( $width / 2 ) ? 1 : 0 );
			imagesetpixel( $image, $x, $y, $colors[ $index ] );
		}
	}
	imagejpeg( $image, $path, 100 );
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
	return $path;
}

/** Add a standards-shaped EXIF orientation entry after a JPEG's SOI marker.
 *
 * @param string $path JPEG path.
 * @param int    $orientation EXIF orientation value.
 * @return void
 */
function zmai_image_set_orientation( $path, $orientation ) {
	$jpeg = file_get_contents( $path );
	$tiff = "II" . pack( 'v', 42 ) . pack( 'V', 8 ) . pack( 'v', 1 );
	$tiff .= pack( 'v', 0x0112 ) . pack( 'v', 3 ) . pack( 'V', 1 ) . pack( 'v', $orientation ) . pack( 'v', 0 ) . pack( 'V', 0 );
	$data = "Exif\0\0" . $tiff;
	$app1 = "\xff\xe1" . pack( 'n', strlen( $data ) + 2 ) . $data;
	file_put_contents( $path, substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) );
}

/** Return the RGB channels at one decoded pixel.
 *
 * @param resource|GdImage $image Decoded image.
 * @param int              $x Horizontal coordinate.
 * @param int              $y Vertical coordinate.
 * @return array
 */
function zmai_image_rgb( $image, $x, $y ) {
	$pixel = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
	return array( $pixel['red'], $pixel['green'], $pixel['blue'] );
}

/** Return a full WebP RIFF chunk including its odd-byte pad.
 *
 * @param string $name Four-byte chunk name.
 * @param string $data Chunk payload.
 * @return string
 */
function zmai_image_webp_chunk( $name, $data ) {
	$chunk = $name . pack( 'V', strlen( $data ) ) . $data;
	return strlen( $data ) % 2 ? $chunk . "\0" : $chunk;
}

/** Encode a 24-bit little-endian integer used by WebP canvas and frame fields.
 *
 * @param int $value Integer to encode.
 * @return string
 */
function zmai_image_uint24( $value ) {
	return chr( $value & 0xff ) . chr( ( $value >> 8 ) & 0xff ) . chr( ( $value >> 16 ) & 0xff );
}

/** Extract the first static VP8 or VP8L chunk from a WebP file.
 *
 * @param string $path WebP file path.
 * @return string|false Encoded image chunk, or false when absent or malformed.
 */
function zmai_image_webp_bitstream_chunk( $path ) {
	$bytes  = file_get_contents( $path );
	$length = strlen( $bytes );
	$offset = 12;
	while ( $offset + 8 <= $length ) {
		$name       = substr( $bytes, $offset, 4 );
		$chunk_size = unpack( 'Vsize', substr( $bytes, $offset + 4, 4 ) );
		$chunk_size = $chunk_size['size'];
		$end        = $offset + 8 + $chunk_size + ( $chunk_size % 2 );
		if ( $end > $length ) {
			return false;
		}
		if ( 'VP8 ' === $name || 'VP8L' === $name ) {
			return substr( $bytes, $offset, $end - $offset );
		}
		$offset = $end;
	}
	return false;
}

/** Wrap chunks in a bounded WebP RIFF container.
 *
 * @param string $chunks Encoded WebP chunks.
 * @return string
 */
function zmai_image_webp_container( $chunks ) {
	$body = 'WEBP' . $chunks;
	return 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
}

/** Build a valid static extended WebP wrapper around a core-generated bitstream.
 *
 * @param string $source Core-generated WebP file.
 * @return string|false Fixture path, or false when no still image chunk exists.
 */
function zmai_image_write_extended_webp( $source ) {
	$stream = zmai_image_webp_bitstream_chunk( $source );
	if ( false === $stream ) {
		return false;
	}
	$vp8x  = "\x10\0\0\0" . zmai_image_uint24( 3 ) . zmai_image_uint24( 3 );
	$bytes = zmai_image_webp_container( zmai_image_webp_chunk( 'VP8X', $vp8x ) . $stream );
	$path  = zmai_image_path( 'static-extended', 'webp' );
	file_put_contents( $path, $bytes );
	return $path;
}

/** Build a two-frame animated WebP using a real still frame bitstream.
 *
 * @param string $source Core-generated WebP file.
 * @return string|false Fixture path, or false when no still image chunk exists.
 */
function zmai_image_write_animated_webp( $source ) {
	$stream = zmai_image_webp_bitstream_chunk( $source );
	if ( false === $stream ) {
		return false;
	}
	$vp8x   = "\x02\0\0\0" . zmai_image_uint24( 3 ) . zmai_image_uint24( 3 );
	$anim   = pack( 'V', 0 ) . pack( 'v', 0 );
	$frame  = zmai_image_uint24( 0 ) . zmai_image_uint24( 0 ) . zmai_image_uint24( 3 ) . zmai_image_uint24( 3 ) . zmai_image_uint24( 100 ) . "\0" . $stream;
	$chunks = zmai_image_webp_chunk( 'VP8X', $vp8x ) . zmai_image_webp_chunk( 'ANIM', $anim );
	$chunks .= zmai_image_webp_chunk( 'ANMF', $frame ) . zmai_image_webp_chunk( 'ANMF', $frame );
	$path = zmai_image_path( 'animated', 'webp' );
	file_put_contents( $path, zmai_image_webp_container( $chunks ) );
	return $path;
}

/** Insert a valid APNG animation-control chunk into an ordinary PNG.
 *
 * @param string $source Core-generated PNG file.
 * @return string
 */
function zmai_image_write_apng( $source ) {
	$bytes      = file_get_contents( $source );
	$ihdr_size  = unpack( 'Nsize', substr( $bytes, 8, 4 ) );
	$insert_at  = 8 + 12 + $ihdr_size['size'];
	$chunk_data = pack( 'N2', 2, 0 );
	$chunk      = pack( 'N', strlen( $chunk_data ) ) . 'acTL' . $chunk_data . pack( 'H*', hash( 'crc32b', 'acTL' . $chunk_data ) );
	$path       = zmai_image_path( 'animated', 'png' );
	file_put_contents( $path, substr( $bytes, 0, $insert_at ) . $chunk . substr( $bytes, $insert_at ) );
	return $path;
}

/** Map normalized output coordinates back to a raw JPEG for EXIF assertions.
 *
 * @param int $orientation EXIF orientation value.
 * @param int $x Normalized output coordinate.
 * @param int $y Normalized output coordinate.
 * @param int $width Raw JPEG width.
 * @param int $height Raw JPEG height.
 * @return array Raw source coordinates.
 */
function zmai_image_exif_source_point( $orientation, $x, $y, $width, $height ) {
	switch ( $orientation ) {
		case 2:
			return array( $width - 1 - $x, $y );
		case 3:
			return array( $width - 1 - $x, $height - 1 - $y );
		case 4:
			return array( $x, $height - 1 - $y );
		case 5:
			return array( $y, $x );
		case 6:
			return array( $y, $height - 1 - $x );
		case 7:
			return array( $width - 1 - $y, $height - 1 - $x );
		case 8:
			return array( $width - 1 - $y, $x );
		default:
			return array( $x, $y );
	}
}

/** Compare decoded RGB channels while allowing one JPEG re-encode's loss.
 *
 * @param array $left First RGB pixel.
 * @param array $right Second RGB pixel.
 * @param int   $tolerance Maximum channel delta.
 * @return bool Whether all channels are within tolerance.
 */
function zmai_image_pixels_near( $left, $right, $tolerance = 70 ) {
	for ( $channel = 0; $channel < 3; ++$channel ) {
		if ( abs( $left[ $channel ] - $right[ $channel ] ) > $tolerance ) {
			return false;
		}
	}
	return true;
}

zmai_image_prepare_directory();
zmai_image_expect( class_exists( Image_Processor::class ), 'candidate Image_Processor class is available' );
if ( ! class_exists( Image_Processor::class ) ) {
	zmai_image_finish();
}

if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
	zmai_image_missing( 'GD fixture encoder unavailable; all pixel fixture cases are unverified' );
	zmai_image_finish();
}

if ( 'missing-exif' === getenv( 'ZMAI_IMAGE_PROCESSOR_MODE' ) ) {
	zmai_image_expect( ! function_exists( 'exif_read_data' ), 'PHP EXIF function is disabled for the missing-extension case' );
	$jpeg = zmai_image_write_quadrants( 16, 12 );
	$crop = Image_Processor::create_square( $jpeg, 12 );
	zmai_image_expect( is_wp_error( $crop ) && 'zmai_jpeg_exif_unavailable' === $crop->get_error_code(), 'JPEG crop reports the specific missing-EXIF processing error' );
	zmai_image_finish();
}

$landscape = zmai_image_write_grid( 9, 5 );
$portrait  = zmai_image_write_grid( 5, 9 );
$odd       = zmai_image_write_grid( 7, 4 );
$square    = zmai_image_write_grid( 4, 4 );
$small     = zmai_image_write_grid( 3, 3 );
$thin      = zmai_image_write_grid( 1, 7 );
$jpeg      = zmai_image_write_grid( 8, 6, 'jpg' );

$valid = Image_Processor::validate_local_image( $landscape, basename( $landscape ) );
zmai_image_expect( is_array( $valid ) && 'image/png' === $valid['type'] && 9 === $valid['width'] && 5 === $valid['height'] && filesize( $landscape ) === $valid['filesize'], 'valid PNG returns the exact local descriptor' );
zmai_image_expect( is_array( Image_Processor::validate_local_image( $jpeg, basename( $jpeg ) ) ), 'valid JPEG qualifies under the current site MIME list' );
zmai_image_expect( is_wp_error( Image_Processor::validate_local_image( $landscape, 'mismatch.jpg' ) ), 'a filename extension that disagrees with PNG bytes is rejected' );
zmai_image_expect( is_wp_error( Image_Processor::validate_local_image( 'https://example.invalid/avatar.png', 'avatar.png' ) ), 'remote stream paths are rejected without fetching' );

$gif = zmai_image_path( 'unsupported', 'gif' );
file_put_contents( $gif, base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ) );
zmai_image_expect( is_wp_error( Image_Processor::validate_local_image( $gif, basename( $gif ) ) ), 'GIF is rejected' );

$oversized_dimension = zmai_image_write_grid( 4097, 1 );
$dimension_result    = Image_Processor::validate_local_image( $oversized_dimension, basename( $oversized_dimension ) );
zmai_image_expect( is_wp_error( $dimension_result ) && 'zmai_invalid_dimensions' === $dimension_result->get_error_code(), 'a 4097-pixel side exceeds the dimension cap' );

$size_filter = static function () {
	return 16;
};
add_filter( 'upload_size_limit', $size_filter, PHP_INT_MAX );
$size_result = Image_Processor::validate_local_image( $landscape, basename( $landscape ) );
remove_filter( 'upload_size_limit', $size_filter, PHP_INT_MAX );
zmai_image_expect( is_wp_error( $size_result ) && 'zmai_upload_size' === $size_result->get_error_code(), 'actual bytes above the current site limit are rejected' );

$mime_filter = static function ( $mimes ) {
	foreach ( $mimes as $extensions => $mime ) {
		$parts = explode( '|', $extensions );
		if ( in_array( 'png', $parts, true ) ) {
			unset( $mimes[ $extensions ] );
		}
	}
	return $mimes;
};
add_filter( 'upload_mimes', $mime_filter, PHP_INT_MAX );
$mime_result = Image_Processor::validate_local_image( $landscape, basename( $landscape ) );
remove_filter( 'upload_mimes', $mime_filter, PHP_INT_MAX );
zmai_image_expect( is_wp_error( $mime_result ), 'a MIME removed by current-site policy is rejected' );

$png_bytes = file_get_contents( $landscape );
$bad_png   = zmai_image_path( 'malformed-length', 'png' );
file_put_contents( $bad_png, substr_replace( $png_bytes, "\x7f\xff\xff\xff", 8, 4 ) );
zmai_image_expect( is_wp_error( Image_Processor::validate_local_image( $bad_png, basename( $bad_png ) ) ), 'PNG chunk lengths extending beyond the bounded file fail closed' );

$bad_crc = zmai_image_path( 'malformed-crc', 'png' );
file_put_contents( $bad_crc, substr_replace( $png_bytes, "\0\0\0\0", strlen( $png_bytes ) - 4, 4 ) );
zmai_image_expect( is_wp_error( Image_Processor::validate_local_image( $bad_crc, basename( $bad_crc ) ) ), 'PNG chunks with invalid CRC fail closed' );

$apng = zmai_image_write_apng( $landscape );
$apng_result = Image_Processor::validate_local_image( $apng, basename( $apng ) );
zmai_image_expect( is_wp_error( $apng_result ) && 'zmai_animated_image' === $apng_result->get_error_code(), 'APNG acTL control chunk is explicitly rejected' );

$static_webp = false;
if ( function_exists( 'imagewebp' ) ) {
	$webp_source = zmai_image_write_grid( 4, 4, 'webp' );
	$webp_result = Image_Processor::validate_local_image( $webp_source, basename( $webp_source ) );
	zmai_image_expect( is_array( $webp_result ) && 'image/webp' === $webp_result['type'], 'static WebP validates' );
	$static_webp = zmai_image_write_extended_webp( $webp_source );
	if ( $static_webp ) {
		$extended_result = Image_Processor::validate_local_image( $static_webp, basename( $static_webp ) );
		zmai_image_expect( is_array( $extended_result ) && 'image/webp' === $extended_result['type'], 'static VP8X WebP is accepted when its animation bit is clear' );
	} else {
		zmai_image_missing( 'could not construct the static VP8X WebP fixture from the selected encoder output' );
	}
	$animated = zmai_image_write_animated_webp( $webp_source );
	$animated_result = Image_Processor::validate_local_image( $animated, basename( $animated ) );
	zmai_image_expect( is_wp_error( $animated_result ) && 'zmai_animated_image' === $animated_result->get_error_code(), 'ANIM and ANMF WebP chunks are explicitly rejected' );
	$webp_bytes = file_get_contents( $webp_source );
	$bad_webp   = zmai_image_path( 'malformed-riff-length', 'webp' );
	file_put_contents( $bad_webp, substr_replace( $webp_bytes, "\xff\xff\xff\xff", 4, 4 ) );
	zmai_image_expect( is_wp_error( Image_Processor::validate_local_image( $bad_webp, basename( $bad_webp ) ) ), 'WebP RIFF lengths extending beyond the bounded file fail closed' );
} else {
	zmai_image_missing( 'GD WebP encoder unavailable; WebP acceptance, animation, and malformed RIFF cells are unverified' );
}

/** Assert an unscaled center crop using exact asymmetric PNG pixels. */
function zmai_image_assert_crop( $label, $width, $height, $crop_x, $crop_y ) {
	$source = zmai_image_write_grid( $width, $height );
	$result = Image_Processor::create_square( $source, 32 );
	if ( is_wp_error( $result ) ) {
		zmai_image_expect( false, $label . ' returns a saved square (' . $result->get_error_code() . ')' );
		return;
	}
	$details = getimagesize( $result['path'] );
	zmai_image_expect( false !== $details && min( $width, $height ) === $details[0] && min( $width, $height ) === $details[1], $label . ' output is square and never upscaled' );
	zmai_image_expect( dirname( $result['path'] ) === dirname( $source ) && 0 === strpos( basename( $result['file'] ), 'zmai-avatar-' ), $label . ' output is unique and beside its source' );
	$original = imagecreatefrompng( $source );
	$output   = imagecreatefrompng( $result['path'] );
	$side     = min( $width, $height );
	$points   = array( array( 0, 0 ), array( (int) ( $side / 2 ), (int) ( $side / 2 ) ), array( $side - 1, $side - 1 ) );
	foreach ( $points as $point ) {
		$expected = zmai_image_rgb( $original, $point[0] + $crop_x, $point[1] + $crop_y );
		$actual   = zmai_image_rgb( $output, $point[0], $point[1] );
		zmai_image_expect( $expected === $actual, $label . ' decoded pixel at ' . $point[0] . ',' . $point[1] . ' comes from the centered source crop' );
	}
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $original ); }
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $output ); }
}

zmai_image_assert_crop( 'landscape crop', 9, 5, 2, 0 );
zmai_image_assert_crop( 'portrait crop', 5, 9, 0, 2 );
zmai_image_assert_crop( 'odd landscape crop uses floor centering', 7, 4, 1, 0 );
zmai_image_assert_crop( 'already-square image', 4, 4, 0, 0 );
zmai_image_assert_crop( 'smaller-than-setting image', 3, 3, 0, 0 );
zmai_image_assert_crop( 'one-pixel side image', 1, 7, 0, 3 );

$transparent = imagecreatetruecolor( 5, 5 );
imagealphablending( $transparent, false );
imagesavealpha( $transparent, true );
$clear = imagecolorallocatealpha( $transparent, 200, 30, 90, 127 );
$half  = imagecolorallocatealpha( $transparent, 20, 180, 220, 64 );
imagefill( $transparent, 0, 0, $clear );
imagesetpixel( $transparent, 2, 2, $half );
$alpha_path = zmai_image_path( 'alpha', 'png' );
imagepng( $transparent, $alpha_path );
if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $transparent ); }
$alpha_result = Image_Processor::create_square( $alpha_path, 10 );
if ( is_wp_error( $alpha_result ) ) {
	zmai_image_expect( false, 'transparent PNG crop succeeds (' . $alpha_result->get_error_code() . ')' );
} else {
	$alpha_image = imagecreatefrompng( $alpha_result['path'] );
	$alpha_pixel = imagecolorat( $alpha_image, 2, 2 );
	zmai_image_expect( ( ( $alpha_pixel >> 24 ) & 0x7f ) > 0, 'transparent PNG crop preserves alpha without upscaling' );
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $alpha_image ); }
}

if ( function_exists( 'exif_read_data' ) ) {
	for ( $orientation = 1; $orientation <= 8; ++$orientation ) {
		$oriented = zmai_image_write_quadrants( 16, 12 );
		zmai_image_set_orientation( $oriented, $orientation );
		$before  = hash_file( 'sha256', $oriented );
		$first   = Image_Processor::create_square( $oriented, 12 );
		$second  = Image_Processor::create_square( $oriented, 12 );
		$changed = hash_file( 'sha256', $oriented ) !== $before;
		zmai_image_expect( ! is_wp_error( $first ) && ! is_wp_error( $second ), 'JPEG EXIF orientation ' . $orientation . ' processes twice' );
		zmai_image_expect( ! $changed, 'JPEG EXIF orientation ' . $orientation . ' leaves the source unchanged' );
		if ( is_wp_error( $first ) || is_wp_error( $second ) ) {
			continue;
		}
		$first_image  = imagecreatefromjpeg( $first['path'] );
		$second_image = imagecreatefromjpeg( $second['path'] );
		$repeat_point = array( 2, 2 );
		$repeat_same  = zmai_image_rgb( $first_image, $repeat_point[0], $repeat_point[1] ) === zmai_image_rgb( $second_image, $repeat_point[0], $repeat_point[1] );
		zmai_image_expect( $repeat_same, 'JPEG EXIF orientation ' . $orientation . ' is not applied twice on repeat processing' );
		$raw       = imagecreatefromjpeg( $oriented );
		$offset_x  = 2;
		$offset_y  = 0;
		if ( in_array( $orientation, array( 5, 6, 7, 8 ), true ) ) {
			$offset_x = 0;
			$offset_y = 2;
		}
		$effective = array( array( 2, 2 ), array( 9, 2 ), array( 2, 9 ), array( 9, 9 ) );
		$pixel_match = true;
		foreach ( $effective as $point ) {
			$effective_x = $point[0] + $offset_x;
			$effective_y = $point[1] + $offset_y;
			$source_point = zmai_image_exif_source_point( $orientation, $effective_x, $effective_y, 16, 12 );
			$expected     = zmai_image_rgb( $raw, $source_point[0], $source_point[1] );
			$actual       = zmai_image_rgb( $first_image, $point[0], $point[1] );
			$pixel_match  = $pixel_match && zmai_image_pixels_near( $expected, $actual );
		}
		zmai_image_expect( $pixel_match, 'JPEG EXIF orientation ' . $orientation . ' maps asymmetric decoded pixels correctly' );
		if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $raw ); }
		if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $first_image ); }
		if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $second_image ); }
	}
} else {
	zmai_image_missing( 'EXIF extension unavailable; orientation 1-8 matrix is unverified' );
	$jpeg_result = Image_Processor::create_square( $jpeg, 4 );
	zmai_image_expect( is_wp_error( $jpeg_result ) && 'zmai_jpeg_exif_unavailable' === $jpeg_result->get_error_code(), 'JPEG processing reports missing EXIF support' );
}

if ( $static_webp ) {
	$webp_crop = Image_Processor::create_square( $static_webp, 8 );
	if ( is_wp_error( $webp_crop ) ) {
		zmai_image_missing( 'selected image editor cannot crop the static WebP fixture: ' . $webp_crop->get_error_code() );
	} else {
		$webp_info = getimagesize( $webp_crop['path'] );
		zmai_image_expect( $webp_info && 'image/webp' === $webp_info['mime'] && $webp_info[0] === $webp_info[1], 'static WebP crop keeps its MIME and square dimensions' );
	}
}

if ( function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromwebp' ) ) {
    $alpha_path = zmai_image_path( 'alpha-static', 'webp' );
    $alpha_image = imagecreatetruecolor( 7, 5 );
    imagealphablending( $alpha_image, false );
    imagesavealpha( $alpha_image, true );
    imagefill( $alpha_image, 0, 0, imagecolorallocatealpha( $alpha_image, 0, 0, 0, 127 ) );
    imagefilledrectangle( $alpha_image, 2, 1, 4, 3, imagecolorallocatealpha( $alpha_image, 240, 90, 20, 0 ) );
    imagewebp( $alpha_image, $alpha_path, 100 );
    $alpha_saved = Image_Processor::create_square( $alpha_path, 5 );
    zmai_image_expect( ! is_wp_error( $alpha_saved ), 'static WebP with transparency is processed' );
    if ( ! is_wp_error( $alpha_saved ) ) {
        $alpha_output = imagecreatefromwebp( $alpha_saved['path'] );
        $corner = imagecolorsforindex( $alpha_output, imagecolorat( $alpha_output, 0, 0 ) );
        $center = imagecolorsforindex( $alpha_output, imagecolorat( $alpha_output, 2, 2 ) );
        zmai_image_expect( 127 === $corner['alpha'] && 0 === $center['alpha'], 'static WebP keeps transparent and opaque pixels through the crop' );
    }
}

$source_for_editor = zmai_image_write_grid( 9, 5 );
$editors           = array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' );
if ( ! is_array( $editors ) || ! $editors ) {
	zmai_image_missing( 'WordPress reports no available image editor; backend crop matrix is unverified' );
} else {
	foreach ( $editors as $editor_class ) {
		$only_editor = static function () use ( $editor_class ) {
			return array( $editor_class );
		};
		add_filter( 'wp_image_editors', $only_editor, PHP_INT_MAX );
		$editor_result = Image_Processor::create_square( $source_for_editor, 8 );
		remove_filter( 'wp_image_editors', $only_editor, PHP_INT_MAX );
		if ( is_wp_error( $editor_result ) ) {
			zmai_image_missing( $editor_class . ' crop matrix cell unavailable: ' . $editor_result->get_error_code() );
			continue;
		}
		$editor_image = imagecreatefrompng( $editor_result['path'] );
		$source_image = imagecreatefrompng( $source_for_editor );
		$pixel_ok     = zmai_image_rgb( $editor_image, 0, 0 ) === zmai_image_rgb( $source_image, 2, 0 );
		zmai_image_expect( $pixel_ok, $editor_class . ' preserves center-crop geometry' );
		if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $editor_image ); }
		if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $source_image ); }
	}
}

$missing_editor = static function () {
	return array();
};
add_filter( 'wp_image_editors', $missing_editor, PHP_INT_MAX );
$editor_error = Image_Processor::create_square( $landscape, 8 );
remove_filter( 'wp_image_editors', $missing_editor, PHP_INT_MAX );
zmai_image_expect( is_wp_error( $editor_error ), 'missing image editor support returns WP_Error' );

zmai_image_finish();
