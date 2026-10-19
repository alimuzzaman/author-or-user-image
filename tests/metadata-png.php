<?php
/**
 * PNG metadata sanitation: an independent chunk walk against Image_Metadata::sanitize().
 *
 * Run through WP-CLI eval-file with the candidate bootstrap. Fixtures are written
 * only under the fix3 scratch fixtures directory.
 *
 * @package AuthorOrUserImage
 */

function zmai_png_check( $ok, $label ) {
	++$GLOBALS['zmai_png_checks'];
	if ( ! $ok ) { ++$GLOBALS['zmai_png_failures']; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

function zmai_png_dir() {
	$dir = '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/fix3/fixtures';
	wp_mkdir_p( $dir );
	return $dir;
}

/** Builds one complete PNG chunk; $crc overrides the correct CRC for malformed fixtures. */
function zmai_png_chunk( $type, $data, $crc = null ) {
	$crc = null === $crc ? crc32( $type . $data ) : $crc;
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', $crc );
}

function zmai_png_assemble( array $raw ) {
	return "\x89PNG\r\n\x1a\n" . implode( '', $raw );
}

/** Independent PNG walker: signature, length bounds, CRC of every chunk, and IEND last. */
function zmai_png_walk( $bytes ) {
	$walk = array( 'ok' => false, 'chunks' => array(), 'error' => 'short' );
	$size = strlen( $bytes );
	if ( $size < 8 || "\x89PNG\r\n\x1a\n" !== substr( $bytes, 0, 8 ) ) {
		$walk['error'] = 'signature';
		return $walk;
	}
	$p = 8;
	while ( $p < $size ) {
		if ( $p + 12 > $size ) {
			$walk['error'] = 'truncated header';
			return $walk;
		}
		$len = unpack( 'N', substr( $bytes, $p, 4 ) )[1];
		if ( $len > 2147483647 || $p + 12 + $len > $size ) {
			$walk['error'] = 'length bounds';
			return $walk;
		}
		$type = substr( $bytes, $p + 4, 4 );
		$data = substr( $bytes, $p + 8, $len );
		$crc  = unpack( 'N', substr( $bytes, $p + 8 + $len, 4 ) )[1];
		if ( $crc !== crc32( $type . $data ) ) {
			$walk['error'] = 'crc ' . $type;
			return $walk;
		}
		$walk['chunks'][] = array( 'type' => $type, 'data' => $data );
		$p += 12 + $len;
		if ( 'IEND' === $type ) {
			$walk['ok']    = $p === $size;
			$walk['error'] = $walk['ok'] ? '' : 'trailing bytes';
			return $walk;
		}
	}
	$walk['error'] = 'missing IEND';
	return $walk;
}

/** 40x30 alpha PNG from GD with transparent, semi-transparent and opaque regions. */
function zmai_png_source() {
	$im = imagecreatetruecolor( 40, 30 );
	imagealphablending( $im, false );
	imagesavealpha( $im, true );
	imagefilledrectangle( $im, 0, 0, 39, 29, imagecolorallocatealpha( $im, 12, 34, 56, 127 ) );
	imagefilledrectangle( $im, 0, 0, 19, 14, imagecolorallocatealpha( $im, 200, 40, 90, 64 ) );
	imagefilledrectangle( $im, 20, 15, 39, 29, imagecolorallocatealpha( $im, 255, 255, 0, 0 ) );
	imagesetpixel( $im, 5, 25, imagecolorallocatealpha( $im, 1, 2, 3, 100 ) );
	ob_start();
	imagepng( $im );
	return ob_get_clean();
}

/** Metadata chunks that must be removed, including duplicate text and an ICC profile. */
function zmai_png_meta_chunks( $icc ) {
	$chunks = array(
		zmai_png_chunk( 'eXIf', "II*\0\x08\0\0\0\0\0\0\0private-exif-gps" ),
		zmai_png_chunk( 'tEXt', "Author\0Private Name" ),
		zmai_png_chunk( 'tEXt', "Author\0Duplicate Private Name" ),
		zmai_png_chunk( 'tEXt', "Comment\0private comment" ),
		zmai_png_chunk( 'iTXt', "XML:com.adobe.xmp\0\0\0\0\0<x:xmpmeta>private xmp</x:xmpmeta>" ),
		zmai_png_chunk( 'zTXt', "Description\0\0" . gzcompress( 'private description' ) ),
		zmai_png_chunk( 'tIME', pack( 'nCCCCC', 2026, 10, 19, 8, 47, 0 ) ),
	);
	if ( null !== $icc ) {
		$chunks[] = zmai_png_chunk( 'iCCP', "sRGB Profile\0\0" . gzcompress( $icc ) );
	}
	return $chunks;
}

/** Raw chunk list: IHDR, then $extra, then every remaining source chunk in order (IEND last). */
function zmai_png_raw( $source, array $extra ) {
	$raw = array();
	foreach ( zmai_png_walk( $source )['chunks'] as $i => $chunk ) {
		$raw[] = zmai_png_chunk( $chunk['type'], $chunk['data'] );
		if ( 0 === $i ) {
			$raw = array_merge( $raw, $extra );
		}
	}
	return $raw;
}

/** Stages a copy of a fixture under a unique name and runs the sanitizer on the copy. */
function zmai_png_sanitize( $bytes, $name, array $context ) {
	$dir     = zmai_png_dir();
	$fixture = $dir . '/png-' . $name . '-' . wp_generate_uuid4() . '.png';
	file_put_contents( $fixture, $bytes );
	$staged = $dir . '/staged-' . wp_generate_uuid4() . '.png';
	copy( $fixture, $staged );
	if ( ! class_exists( 'AuthorOrUserImage\\Image_Metadata' ) ) {
		return new WP_Error( 'zmai_png_missing_class', 'Image_Metadata is not loaded.' );
	}
	return AuthorOrUserImage\Image_Metadata::sanitize( $staged, 'avatar.png', $context );
}

/** Asserts a sanitized descriptor: valid walk, no metadata chunks, ICC byte-equal, pixels unchanged. */
function zmai_png_assert_clean( $result, $label, $icc, $src_im ) {
	$ok = is_array( $result ) && isset( $result['path'] ) && is_file( $result['path'] );
	zmai_png_check( $ok, $label . ' returns a descriptor with a staged file' );
	if ( ! $ok ) {
		return;
	}
	$bytes = file_get_contents( $result['path'] );
	$walk  = zmai_png_walk( $bytes );
	zmai_png_check( $walk['ok'], $label . ' output walks valid (lengths, CRC, IEND): ' . $walk['error'] );
	$types = array_column( $walk['chunks'], 'type' );
	zmai_png_check( array() === array_intersect( $types, array( 'eXIf', 'tEXt', 'iTXt', 'zTXt', 'tIME' ) ), $label . ' no eXIf/tEXt/iTXt/zTXt/tIME chunk remains' );
	if ( null !== $icc ) {
		$profile = null;
		foreach ( $walk['chunks'] as $chunk ) {
			if ( 'iCCP' !== $chunk['type'] ) {
				continue;
			}
			$nul     = strpos( $chunk['data'], "\0" );
			$profile = false === $nul ? null : @gzuncompress( substr( $chunk['data'], $nul + 2 ) );
		}
		zmai_png_check( $icc === $profile, $label . ' iCCP profile byte-equal to sRGB source' );
	}
	$out     = @imagecreatefromstring( $bytes );
	$size_ok = false !== $out && 40 === imagesx( $out ) && 30 === imagesy( $out );
	zmai_png_check( $size_ok, $label . ' output decodes to 40x30' );
	$alpha = 0;
	$rgb   = 0;
	for ( $y = 0; $size_ok && $y < 30; $y++ ) {
		for ( $x = 0; $x < 40; $x++ ) {
			$a = imagecolorat( $src_im, $x, $y );
			$b = imagecolorat( $out, $x, $y );
			if ( ( $a >> 24 ) !== ( $b >> 24 ) ) {
				++$alpha;
			}
			if ( ( $a & 0xffffff ) !== ( $b & 0xffffff ) ) {
				++$rgb;
			}
		}
	}
	zmai_png_check( $size_ok && 0 === $alpha, $label . ' alpha unchanged on all 1200 pixels (mismatches ' . $alpha . ')' );
	zmai_png_check( $size_ok && 0 === $rgb, $label . ' RGB unchanged on all 1200 pixels (mismatches ' . $rgb . ')' );
}

$GLOBALS['zmai_png_checks']   = 0;
$GLOBALS['zmai_png_failures'] = 0;

$icc_path = '/System/Library/ColorSync/Profiles/sRGB Profile.icc';
$icc      = is_file( $icc_path ) ? file_get_contents( $icc_path ) : null;
if ( null === $icc ) {
	echo "SKIP iCCP: sRGB profile not present\n";
}

$source = zmai_png_source();
$src_im = imagecreatefromstring( $source );
$meta   = zmai_png_meta_chunks( $icc );
$clean  = zmai_png_assemble( zmai_png_raw( $source, $meta ) );
zmai_png_check( zmai_png_walk( $clean )['ok'], 'fixture: metadata PNG walks with valid CRCs' );

$raw_no_iend = zmai_png_raw( $source, $meta );
array_pop( $raw_no_iend );
$malformed = array(
	'truncated-length' => zmai_png_assemble( array_merge( $raw_no_iend, array( pack( 'N', 4096 ) . 'tEXt' . 'short' ) ) ),
	'overflow-length'  => zmai_png_assemble( array_merge( $raw_no_iend, array( pack( 'N', 0x80000000 ) . 'tEXt' . 'x' ) ) ),
	'bad-crc'          => zmai_png_assemble( zmai_png_raw( $source, array_merge( $meta, array( zmai_png_chunk( 'tEXt', "Author\0bad crc", crc32( 'tEXt' . "Author\0bad crc" ) ^ 1 ) ) ) ) ),
);
$invalid_fixtures = true;
foreach ( $malformed as $bytes ) {
	$invalid_fixtures = $invalid_fixtures && false === zmai_png_walk( $bytes )['ok'];
}
zmai_png_check( $invalid_fixtures, 'fixture: every malformed PNG is invalid to the independent walker' );

$ctx        = array( 'owner' => 1, 'source' => 'upload' );
$saved_post = $_POST;
$saved_req  = $_REQUEST;
$seen       = array();
$observe    = static function ( $strip, $context ) use ( &$seen ) {
	$seen = array( $strip, $context );
	return $strip;
};
$optout     = static function ( $strip, $context ) use ( &$seen ) {
	$seen = array( $strip, $context );
	return false;
};

try {
	// Default processing.
	zmai_png_assert_clean( zmai_png_sanitize( $clean, 'default', $ctx ), 'default', $icc, $src_im );

	// image_strip_meta=false must not disable the default.
	add_filter( 'image_strip_meta', '__return_false', PHP_INT_MAX );
	try {
		$result = zmai_png_sanitize( $clean, 'strip-meta-false', $ctx );
	} finally {
		remove_filter( 'image_strip_meta', '__return_false', PHP_INT_MAX );
	}
	zmai_png_assert_clean( $result, 'image_strip_meta=false', $icc, $src_im );

	// Forged request fields must not disable the default or alter the trusted context.
	$_POST    = array( 'zmai_strip_image_metadata' => '0', 'image_strip_meta' => '0', 'owner' => '999', 'source' => 'import' );
	$_REQUEST = $_POST;
	add_filter( 'zmai_strip_image_metadata', $observe, 10, 2 );
	try {
		$result = zmai_png_sanitize( $clean, 'forged-post', $ctx );
	} finally {
		remove_filter( 'zmai_strip_image_metadata', $observe, 10 );
	}
	zmai_png_check( true === ( $seen[0] ?? null ) && 1 === ( $seen[1]['owner'] ?? null ) && 'upload' === ( $seen[1]['source'] ?? null ), 'forged POST: filter still receives default true and trusted context' );
	zmai_png_assert_clean( $result, 'forged POST', $icc, $src_im );
	$_POST    = $saved_post;
	$_REQUEST = $saved_req;

	// Trusted PHP-only opt-out: metadata stays, the image is otherwise processed.
	add_filter( 'zmai_strip_image_metadata', $optout, 10, 2 );
	try {
		$result = zmai_png_sanitize( $clean, 'opt-out', $ctx );
	} finally {
		remove_filter( 'zmai_strip_image_metadata', $optout, 10 );
	}
	zmai_png_check( true === ( $seen[0] ?? null ) && 1 === ( $seen[1]['owner'] ?? null ), 'opt-out: filter receives default true and owner int' );
	if ( is_array( $result ) && isset( $result['path'] ) && is_file( $result['path'] ) ) {
		$walk  = zmai_png_walk( file_get_contents( $result['path'] ) );
		$types = array_column( $walk['chunks'], 'type' );
		zmai_png_check( $walk['ok'] && 5 === count( array_unique( array_intersect( $types, array( 'eXIf', 'tEXt', 'iTXt', 'zTXt', 'tIME' ) ) ) ) && 3 === count( array_keys( $types, 'tEXt', true ) ), 'opt-out: metadata chunks intact and output still walks valid' );
	} else {
		zmai_png_check( false, 'opt-out: returns a descriptor with a staged file' );
	}

	// Opt-out never accepts malformed structure.
	add_filter( 'zmai_strip_image_metadata', $optout, 10, 2 );
	try {
		foreach ( $malformed as $label => $bytes ) {
			zmai_png_check( is_wp_error( zmai_png_sanitize( $bytes, 'optout-' . $label, $ctx ) ), 'opt-out: malformed ' . $label . ' still rejected' );
		}
	} finally {
		remove_filter( 'zmai_strip_image_metadata', $optout, 10 );
	}

	// Default malformed structure is rejected.
	foreach ( $malformed as $label => $bytes ) {
		zmai_png_check( is_wp_error( zmai_png_sanitize( $bytes, 'default-' . $label, $ctx ) ), 'default: malformed ' . $label . ' rejected' );
	}
} catch ( Throwable $e ) {
	zmai_png_check( false, 'fixture execution: ' . $e->getMessage() );
} finally {
	$_POST    = $saved_post;
	$_REQUEST = $saved_req;
}

$checks   = $GLOBALS['zmai_png_checks'];
$failures = $GLOBALS['zmai_png_failures'];
echo 'RESULT checks=' . $checks . ' pass=' . ( $checks - $failures ) . ' fail=' . $failures . "\n";
if ( $failures ) {
	throw new RuntimeException( 'PNG metadata checks failed: ' . $failures . ' of ' . $checks . '.' );
}
