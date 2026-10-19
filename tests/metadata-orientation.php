<?php
/**
 * Orientation 2-8, repeat-processing, RGB ICC and CMYK checks for Image_Metadata::sanitize().
 *
 * Standalone: copies the asymmetric fixture and EXIF orientation reference helpers
 * under the zmo_ prefix. It does not require image-processing.php or sibling suites.
 */
use AuthorOrUserImage\Image_Metadata;

$GLOBALS['zmo_checks']   = 0;
$GLOBALS['zmo_failures'] = array();
$GLOBALS['zmo_missing']  = array();
$GLOBALS['zmo_fixtures'] = '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/fix3/fixtures';
$GLOBALS['zmo_icc_path'] = '/System/Library/ColorSync/Profiles/sRGB Profile.icc';

/** Record one check and echo PASS or FAIL.
 *
 * @param bool   $condition Whether the check passed.
 * @param string $label Check description.
 * @return bool
 */
function zmo_expect( $condition, $label ) {
	++$GLOBALS['zmo_checks'];
	if ( ! $condition ) {
		$GLOBALS['zmo_failures'][] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	return (bool) $condition;
}

/** Record a capability that is unavailable without counting it as a pass.
 *
 * @param string $label Capability description.
 */
function zmo_missing( $label ) {
	$GLOBALS['zmo_missing'][] = $label;
	echo 'MISSING ' . $label . "\n";
}

/** Build a unique fixture path inside the approved fix3 fixtures directory.
 *
 * @param string $label Fixture label.
 * @param string $extension Filename extension.
 * @return string
 */
function zmo_path( $label, $extension ) {
	wp_mkdir_p( $GLOBALS['zmo_fixtures'] );
	return $GLOBALS['zmo_fixtures'] . '/' . sanitize_file_name( $label . '-' . wp_generate_uuid4() ) . '.' . $extension;
}

/** Write a four-color JPEG with large regions for EXIF orientation checks.
 *
 * @param int $width Image width.
 * @param int $height Image height.
 * @return string
 */
function zmo_quadrants( $width, $height ) {
	$image  = imagecreatetruecolor( $width, $height );
	$colors = array(
		imagecolorallocate( $image, 240, 20, 20 ),
		imagecolorallocate( $image, 20, 220, 20 ),
		imagecolorallocate( $image, 20, 20, 240 ),
		imagecolorallocate( $image, 240, 220, 20 ),
	);
	$path = zmo_path( 'orientation-' . $width . 'x' . $height, 'jpg' );
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
 */
function zmo_set_orientation( $path, $orientation ) {
	$jpeg = file_get_contents( $path );
	$tiff = "II" . pack( 'v', 42 ) . pack( 'V', 8 ) . pack( 'v', 1 );
	$tiff .= pack( 'v', 0x0112 ) . pack( 'v', 3 ) . pack( 'V', 1 ) . pack( 'v', $orientation ) . pack( 'v', 0 ) . pack( 'V', 0 );
	$data = "Exif\0\0" . $tiff;
	$app1 = "\xff\xe1" . pack( 'n', strlen( $data ) + 2 ) . $data;
	file_put_contents( $path, substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) );
}

/** Add an ICC_PROFILE APP2 segment (single chunk) after a JPEG's SOI marker.
 *
 * @param string $path JPEG path.
 * @param string $profile Raw ICC profile bytes.
 */
function zmo_inject_icc( $path, $profile ) {
	$jpeg    = file_get_contents( $path );
	$payload = "ICC_PROFILE\0" . chr( 1 ) . chr( 1 ) . $profile;
	$segment = "\xff\xe2" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	file_put_contents( $path, substr( $jpeg, 0, 2 ) . $segment . substr( $jpeg, 2 ) );
}

/** Walk JPEG marker segments up to the start of scan data.
 *
 * @param string $bytes JPEG bytes.
 * @return array<int, array{marker:int,payload:string}>
 */
function zmo_segments( $bytes ) {
	$segments = array();
	$p        = 2;
	$length   = strlen( $bytes );
	while ( $p + 4 <= $length && "\xff" === $bytes[ $p ] ) {
		$marker = ord( $bytes[ $p + 1 ] );
		if ( 0xda === $marker || 0xd9 === $marker ) {
			break;
		}
		$size = unpack( 'n', substr( $bytes, $p + 2, 2 ) )[1];
		if ( $size < 2 ) {
			break;
		}
		$segments[] = array(
			'marker'  => $marker,
			'payload' => substr( $bytes, $p + 4, $size - 2 ),
		);
		$p += 2 + $size;
	}
	return $segments;
}

/** Whether any APP1 segment carries an Exif payload.
 *
 * @param string $bytes JPEG bytes.
 * @return bool
 */
function zmo_has_exif( $bytes ) {
	foreach ( zmo_segments( $bytes ) as $segment ) {
		if ( 0xe1 === $segment['marker'] && 0 === strpos( $segment['payload'], "Exif\0\0" ) ) {
			return true;
		}
	}
	return false;
}

/** Return ICC profile bytes carried by ICC_PROFILE APP2 segments.
 *
 * @param string $bytes JPEG bytes.
 * @return string[]
 */
function zmo_icc_payloads( $bytes ) {
	$profiles = array();
	foreach ( zmo_segments( $bytes ) as $segment ) {
		if ( 0xe2 === $segment['marker'] && 0 === strpos( $segment['payload'], "ICC_PROFILE\0" ) ) {
			$profiles[] = substr( $segment['payload'], 14 );
		}
	}
	return $profiles;
}

/** Return the RGB channels at one decoded pixel.
 *
 * @param resource|GdImage $image Decoded image.
 * @param int              $x Horizontal coordinate.
 * @param int              $y Vertical coordinate.
 * @return array
 */
function zmo_rgb( $image, $x, $y ) {
	$pixel = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
	return array( $pixel['red'], $pixel['green'], $pixel['blue'] );
}

/** Map a normalized output coordinate to its raw JPEG source coordinate.
 *
 * @param int $orientation EXIF orientation value.
 * @param int $x Normalized output coordinate.
 * @param int $y Normalized output coordinate.
 * @param int $width Raw JPEG width.
 * @param int $height Raw JPEG height.
 * @return array Raw source coordinates.
 */
function zmo_source_point( $orientation, $x, $y, $width, $height ) {
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

/** Compare RGB channels while allowing one JPEG re-encode's loss.
 *
 * @param array $left First RGB pixel.
 * @param array $right Second RGB pixel.
 * @param int   $tolerance Maximum channel delta.
 * @return bool
 */
function zmo_pixels_near( $left, $right, $tolerance = 70 ) {
	for ( $channel = 0; $channel < 3; ++$channel ) {
		if ( abs( $left[ $channel ] - $right[ $channel ] ) > $tolerance ) {
			return false;
		}
	}
	return true;
}

/** Check four asymmetric output pixels against the raw fixture under one orientation.
 *
 * @param string $oriented_path Sanitized JPEG path.
 * @param string $raw_path Raw source JPEG path.
 * @param int    $orientation EXIF orientation value.
 * @param int    $raw_width Raw width.
 * @param int    $raw_height Raw height.
 * @return bool
 */
function zmo_mapping_matches( $oriented_path, $raw_path, $orientation, $raw_width, $raw_height ) {
	$out    = imagecreatefromjpeg( $oriented_path );
	$raw    = imagecreatefromjpeg( $raw_path );
	$out_w  = imagesx( $out );
	$out_h  = imagesy( $out );
	$points = array( array( 2, 2 ), array( $out_w - 3, 2 ), array( 2, $out_h - 3 ), array( $out_w - 3, $out_h - 3 ) );
	$match  = true;
	foreach ( $points as $point ) {
		$source = zmo_source_point( $orientation, $point[0], $point[1], $raw_width, $raw_height );
		$match  = $match && zmo_pixels_near( zmo_rgb( $raw, $source[0], $source[1] ), zmo_rgb( $out, $point[0], $point[1] ) );
	}
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $out ); imagedestroy( $raw ); }
	return $match;
}

/** Call the sanitizer with the production context shape.
 *
 * @param string $path JPEG path.
 * @return mixed Descriptor, WP_Error, or null when the call threw.
 */
function zmo_sanitize( $path ) {
	try {
		return Image_Metadata::sanitize( $path, 'orientation.jpg', array( 'owner' => 1, 'source' => 'upload' ) );
	} catch ( \Throwable $error ) {
		echo 'THREW ' . $error->getMessage() . "\n";
		return null;
	}
}

/** Check one EXIF orientation under one editor: once, twice, dimensions, pixels, bytes.
 *
 * @param string $backend Editor name: GD or Imagick.
 * @param int    $orientation EXIF orientation value.
 */
function zmo_check_orientation( $backend, $orientation ) {
	$label    = $backend . ' orientation ' . $orientation;
	$source   = zmo_quadrants( 16, 12 );
	zmo_set_orientation( $source, $orientation );
	$before   = hash_file( 'sha256', $source );
	$swap     = in_array( $orientation, array( 5, 6, 7, 8 ), true );
	$want_w   = $swap ? 12 : 16;
	$want_h   = $swap ? 16 : 12;

	$stage = zmo_path( 'staged-' . $orientation, 'jpg' );
	copy( $source, $stage );
	$first = zmo_sanitize( $stage );
	zmo_expect( hash_file( 'sha256', $source ) === $before, $label . ' leaves the source unchanged' );
	if ( ! zmo_expect( is_array( $first ) && ! is_wp_error( $first ) && ! empty( $first['path'] ), $label . ' once returns a descriptor' ) ) {
		return;
	}
	zmo_expect( $first['path'] !== $source, $label . ' once writes a fresh file' );
	zmo_expect( isset( $first['width'], $first['height'] ) && $want_w === $first['width'] && $want_h === $first['height'], $label . ' once descriptor is ' . $want_w . 'x' . $want_h );
	$size = getimagesize( $first['path'] );
	zmo_expect( is_array( $size ) && $want_w === $size[0] && $want_h === $size[1] && IMAGETYPE_JPEG === $size[2], $label . ' once file is JPEG ' . $want_w . 'x' . $want_h );
	zmo_expect( zmo_mapping_matches( $first['path'], $source, $orientation, 16, 12 ), $label . ' once maps asymmetric decoded pixels' );
	$bytes = file_get_contents( $first['path'] );
	zmo_expect( ! zmo_has_exif( $bytes ) && false === strpos( $bytes, "Exif\0\0" ), $label . ' once final bytes carry no EXIF' );

	$second = zmo_sanitize( $first['path'] );
	if ( ! zmo_expect( is_array( $second ) && ! is_wp_error( $second ) && ! empty( $second['path'] ), $label . ' twice returns a descriptor' ) ) {
		return;
	}
	$second_size = getimagesize( $second['path'] );
	zmo_expect( is_array( $second_size ) && $want_w === $second_size[0] && $want_h === $second_size[1], $label . ' twice keeps dimensions (no double rotation)' );
	zmo_expect( zmo_mapping_matches( $second['path'], $source, $orientation, 16, 12 ), $label . ' twice maps pixels once (no double rotation)' );
	$second_bytes = file_get_contents( $second['path'] );
	zmo_expect( ! zmo_has_exif( $second_bytes ) && false === strpos( $second_bytes, "Exif\0\0" ), $label . ' twice final bytes carry no EXIF' );
}

/** Check that an RGB ICC profile survives an oriented save, byte for byte.
 *
 * @param string $backend Editor name: GD or Imagick.
 */
function zmo_check_icc( $backend ) {
	if ( ! is_readable( $GLOBALS['zmo_icc_path'] ) ) {
		zmo_missing( 'sRGB ICC profile unavailable; RGB ICC retention unverified' );
		return;
	}
	$profile = file_get_contents( $GLOBALS['zmo_icc_path'] );
	for ( $orientation = 2; $orientation <= 8; ++$orientation ) {
		$label  = $backend . ' ICC orientation ' . $orientation;
		$source = zmo_quadrants( 16, 12 );
		zmo_set_orientation( $source, $orientation );
		zmo_inject_icc( $source, $profile );
		zmo_expect( 1 === count( zmo_icc_payloads( file_get_contents( $source ) ) ), $label . ' fixture carries one ICC profile' );
		$result = zmo_sanitize( $source );
		if ( ! zmo_expect( is_array( $result ) && ! is_wp_error( $result ) && ! empty( $result['path'] ), $label . ' returns a descriptor' ) ) {
			continue;
		}
		$icc = zmo_icc_payloads( file_get_contents( $result['path'] ) );
		zmo_expect( 1 === count( $icc ) && $profile === $icc[0], $label . ' keeps the original RGB ICC profile bytes' );
	}
}

/** Run the full orientation and ICC matrix with one editor forced.
 *
 * @param string $backend Editor name: GD or Imagick.
 */
function zmo_run_backend( $backend ) {
	$only = function () use ( $backend ) {
		return array( 'WP_Image_Editor_' . $backend );
	};
	add_filter( 'wp_image_editors', $only, PHP_INT_MAX );
	for ( $orientation = 2; $orientation <= 8; ++$orientation ) {
		zmo_check_orientation( $backend, $orientation );
	}
	zmo_check_icc( $backend );
	remove_filter( 'wp_image_editors', $only, PHP_INT_MAX );
}

/** Oriented CMYK on GD must return a typed WP_Error. The fixture is generated with Imagick.
 */
function zmo_check_cmyk() {
	if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
		zmo_missing( 'Imagick unavailable; oriented CMYK GD error unverified' );
		return;
	}
	$path = zmo_path( 'orientation-cmyk-16x12', 'jpg' );
	try {
		$image = new Imagick();
		$image->newImage( 16, 12, new ImagickPixel( 'rgb(240,20,20)' ) );
		$image->transformImageColorspace( Imagick::COLORSPACE_CMYK );
		$image->setImageFormat( 'jpeg' );
		$image->writeImage( $path );
		$image->clear();
		$image->destroy();
	} catch ( \Exception $error ) {
		zmo_missing( 'Imagick CMYK fixture generation failed: ' . $error->getMessage() );
		return;
	}
	zmo_set_orientation( $path, 6 );
	$size = getimagesize( $path );
	zmo_expect( is_array( $size ) && isset( $size['channels'] ) && 4 === $size['channels'], 'CMYK fixture is 4-channel JPEG' );

	$only = function () {
		return array( 'WP_Image_Editor_GD' );
	};
	add_filter( 'wp_image_editors', $only, PHP_INT_MAX );
	$result = zmo_sanitize( $path );
	zmo_expect( is_wp_error( $result ) && 'zmai_metadata_cmyk' === $result->get_error_code(), 'GD rejects oriented CMYK with a typed WP_Error' );
	$optout = static function () { return false; };
	add_filter( 'zmai_strip_image_metadata', $optout );
	try {
		$result = zmo_sanitize( $path );
		zmo_expect( is_wp_error( $result ) && 'zmai_metadata_cmyk' === $result->get_error_code(), 'PHP metadata opt-out cannot bypass GD CMYK validation' );
	} finally {
		remove_filter( 'zmai_strip_image_metadata', $optout );
		remove_filter( 'wp_image_editors', $only, PHP_INT_MAX );
	}
}

zmo_run_backend( 'GD' );
if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
	zmo_run_backend( 'Imagick' );
} else {
	zmo_missing( 'Imagick extension unavailable; Imagick orientation and ICC matrix unverified' );
}
zmo_check_cmyk();

echo wp_json_encode(
	array(
		'checks'   => $GLOBALS['zmo_checks'],
		'failures' => $GLOBALS['zmo_failures'],
		'missing'  => $GLOBALS['zmo_missing'],
	)
) . "\n";
if ( $GLOBALS['zmo_failures'] ) {
	WP_CLI::halt( 1 );
}
