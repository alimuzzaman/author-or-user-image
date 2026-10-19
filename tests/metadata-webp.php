<?php
/**
 * Image_Metadata::sanitize() WebP contract: extended VP8X container, EXIF/XMP
 * removal including duplicates, ICC and alpha retention, and typed rejection of
 * malformed RIFF chunk length, chunk padding and RIFF size.
 *
 * Fixtures are generated at runtime into the fix3 scratch fixtures directory.
 * Run with WP-CLI eval-file after tests/candidate-bootstrap.php.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) { throw new RuntimeException( 'Disposable local WordPress required.' ); }

const ZMAI_W_FIXTURES = '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/fix3/fixtures';
const ZMAI_W_ICC      = '/System/Library/ColorSync/Profiles/sRGB Profile.icc';
const ZMAI_W_CLASS    = 'AuthorOrUserImage\\Image_Metadata';

$GLOBALS['zmai_w_results'] = array( 'pass' => 0, 'fail' => 0 );

function zmai_w_check( $ok, $label ) {
	if ( $ok ) {
		++$GLOBALS['zmai_w_results']['pass'];
		echo 'PASS ' . $label . PHP_EOL;
		return;
	}
	++$GLOBALS['zmai_w_results']['fail'];
	echo 'FAIL ' . $label . PHP_EOL;
}

function zmai_w_write( $bytes, $tag ) {
	if ( ! is_dir( ZMAI_W_FIXTURES ) ) {
		mkdir( ZMAI_W_FIXTURES, 0775, true );
	}
	$path = ZMAI_W_FIXTURES . '/metadata-webp-' . bin2hex( random_bytes( 6 ) ) . '-' . $tag . '.webp';
	file_put_contents( $path, $bytes );
	return $path;
}

function zmai_w_chunk( $fourcc, $payload ) {
	$chunk = $fourcc . pack( 'V', strlen( $payload ) ) . $payload;
	return strlen( $payload ) % 2 ? $chunk . "\0" : $chunk;
}

/** Independent RIFF/WEBP chunk walker: order, payloads, first offsets and validity. */
function zmai_w_walk( $bytes ) {
	$walk = array( 'valid' => false, 'reason' => '', 'order' => array(), 'payloads' => array(), 'offsets' => array() );
	$end  = strlen( $bytes );
	if ( $end < 12 || 'RIFF' !== substr( $bytes, 0, 4 ) || 'WEBP' !== substr( $bytes, 8, 4 ) ) {
		$walk['reason'] = 'not RIFF/WEBP';
		return $walk;
	}
	if ( unpack( 'V', substr( $bytes, 4, 4 ) )[1] !== $end - 8 ) {
		$walk['reason'] = 'RIFF size mismatch';
		return $walk;
	}
	$p = 12;
	while ( $p < $end ) {
		if ( $p + 8 > $end ) {
			$walk['reason'] = 'truncated chunk header';
			return $walk;
		}
		$fourcc = substr( $bytes, $p, 4 );
		$size   = unpack( 'V', substr( $bytes, $p + 4, 4 ) )[1];
		$next   = $p + 8 + $size;
		$pad    = $size % 2;
		if ( $next > $end ) {
			$walk['reason'] = 'chunk overruns RIFF';
			return $walk;
		}
		if ( $pad && ( $next + 1 > $end || "\0" !== $bytes[ $next ] ) ) {
			$walk['reason'] = 'missing or nonzero pad byte';
			return $walk;
		}
		$walk['order'][]              = $fourcc;
		$walk['payloads'][ $fourcc ][] = substr( $bytes, $p + 8, $size );
		$walk['offsets'][ $fourcc ]   = $walk['offsets'][ $fourcc ] ?? $p;
		$p = $next + $pad;
	}
	$walk['valid'] = true;
	return $walk;
}

/** Ordered chunk list from a well-formed GD-produced file. */
function zmai_w_source_chunks( $bytes ) {
	$chunks = array();
	$p      = 12;
	while ( $p + 8 <= strlen( $bytes ) ) {
		$size     = unpack( 'V', substr( $bytes, $p + 4, 4 ) )[1];
		$chunks[] = array( substr( $bytes, $p, 4 ), substr( $bytes, $p + 8, $size ) );
		$p       += 8 + $size + ( $size % 2 );
	}
	return $chunks;
}

/** 40x30 GD WebP with a transparent border and an opaque centre block. */
function zmai_w_source_webp() {
	$im = imagecreatetruecolor( 40, 30 );
	imagealphablending( $im, false );
	imagesavealpha( $im, true );
	imagefilledrectangle( $im, 0, 0, 39, 29, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
	imagefilledrectangle( $im, 10, 8, 29, 21, imagecolorallocatealpha( $im, 200, 40, 40, 0 ) );
	ob_start();
	imagewebp( $im );
	return ob_get_clean();
}

/**
 * Rebuilds GD output as an extended VP8X container: VP8X, optional ICCP, the
 * image chunks, then the supplied EXIF/XMP chunks. Flags follow what is present.
 */
function zmai_w_extended( $source, $with_icc, $meta ) {
	$image = '';
	$alpha = false;
	foreach ( zmai_w_source_chunks( $source ) as $chunk ) {
		list( $fourcc, $payload ) = $chunk;
		if ( in_array( $fourcc, array( 'VP8X', 'ICCP', 'EXIF', 'XMP ' ), true ) ) {
			continue;
		}
		$alpha  = $alpha || in_array( $fourcc, array( 'ALPH', 'VP8L' ), true );
		$image .= zmai_w_chunk( $fourcc, $payload );
	}
	$flags = ( $alpha ? 0x10 : 0 ) | ( $with_icc ? 0x20 : 0 );
	foreach ( $meta as $chunk ) {
		$flags |= ( 'EXIF' === $chunk[0] ) ? 0x08 : ( ( 'XMP ' === $chunk[0] ) ? 0x04 : 0 );
	}
	$canvas = substr( pack( 'V', 39 ), 0, 3 ) . substr( pack( 'V', 29 ), 0, 3 );
	$chunks = zmai_w_chunk( 'VP8X', chr( $flags ) . "\0\0\0" . $canvas );
	if ( $with_icc ) {
		$chunks .= zmai_w_chunk( 'ICCP', file_get_contents( ZMAI_W_ICC ) );
	}
	$chunks .= $image;
	foreach ( $meta as $chunk ) {
		$chunks .= zmai_w_chunk( $chunk[0], $chunk[1] );
	}
	return 'RIFF' . pack( 'V', 4 + strlen( $chunks ) ) . 'WEBP' . $chunks;
}

function zmai_w_sanitize( $path, $name ) {
	if ( ! class_exists( ZMAI_W_CLASS ) ) {
		return null;
	}
	$staging = ZMAI_W_FIXTURES . '/metadata-webp-' . bin2hex( random_bytes( 6 ) ) . '-staging.webp';
	copy( $path, $staging );
	try {
		return call_user_func( array( ZMAI_W_CLASS, 'sanitize' ), $staging, $name, array( 'owner' => 1, 'source' => 'upload' ) );
	} catch ( Throwable $e ) {
		echo 'EXCEPTION: ' . $e->getMessage() . PHP_EOL;
		return null;
	}
}

/** Output bytes from a descriptor's path, or null. */
function zmai_w_output( $result ) {
	if ( ! is_array( $result ) || ! isset( $result['path'] ) || ! is_file( $result['path'] ) ) {
		return null;
	}
	return file_get_contents( $result['path'] );
}

function zmai_w_canvas( $payload ) {
	if ( strlen( $payload ) < 10 ) {
		return null;
	}
	$read = static fn( $o ) => ord( $payload[ $o ] ) | ( ord( $payload[ $o + 1 ] ) << 8 ) | ( ord( $payload[ $o + 2 ] ) << 16 );
	return array( $read( 4 ) + 1, $read( 7 ) + 1 );
}

/** Decoded size and GD alpha (0 opaque, 127 transparent) at a corner and the centre. */
function zmai_w_decode( $bytes ) {
	$im = imagecreatefromstring( $bytes );
	if ( false === $im ) {
		return null;
	}
	return array(
		imagesx( $im ),
		imagesy( $im ),
		( imagecolorat( $im, 0, 0 ) >> 24 ) & 0x7F,
		( imagecolorat( $im, 20, 15 ) >> 24 ) & 0x7F,
	);
}

/** Shared assertions for a valid output: descriptor, chunk walk, decode. */
function zmai_w_assert_clean( $out, $label, $expect_icc ) {
	$w = zmai_w_walk( (string) $out );
	zmai_w_check( $w['valid'], $label . ' RIFF size and chunk walk are valid' . ( $w['reason'] ? ': ' . $w['reason'] : '' ) );
	zmai_w_check( 'VP8X' === ( $w['order'][0] ?? '' ), $label . ' starts with extended VP8X' );
	zmai_w_check( ! isset( $w['payloads']['EXIF'] ) && ! isset( $w['payloads']['XMP '] ), $label . ' removes EXIF and XMP chunks' );
	zmai_w_check( count( $w['payloads']['ICCP'] ?? array() ) <= 1, $label . ' keeps at most one ICCP' );

	$flags  = isset( $w['payloads']['VP8X'][0] ) ? ord( $w['payloads']['VP8X'][0][0] ) : 0;
	$canvas = isset( $w['payloads']['VP8X'][0] ) ? zmai_w_canvas( $w['payloads']['VP8X'][0] ) : null;
	zmai_w_check( 0 === ( $flags & 0x0C ), $label . ' clears EXIF and XMP flag bits' );
	zmai_w_check( 0x10 === ( $flags & 0x10 ), $label . ' keeps alpha flag set' );
	zmai_w_check( $expect_icc === ( 0x20 === ( $flags & 0x20 ) ), $label . ' ICC flag matches ICCP presence' );
	zmai_w_check( array( 40, 30 ) === $canvas, $label . ' canvas is 40x30' );
	zmai_w_check( isset( $w['payloads']['VP8L'] ) || ( isset( $w['payloads']['VP8 '] ) && isset( $w['payloads']['ALPH'] ) ), $label . ' keeps image and alpha chunks' );

	$dec = zmai_w_decode( (string) $out );
	zmai_w_check( null !== $dec && 40 === $dec[0] && 30 === $dec[1], $label . ' decodes at 40x30' );
	zmai_w_check( null !== $dec && $dec[2] >= 100, $label . ' transparent corner alpha survives decode' );
	zmai_w_check( null !== $dec && $dec[3] <= 20, $label . ' opaque centre survives decode' );
	return $w;
}

// Precondition: the ICC profile exists so the ICCP path is exercised.
$icc_bytes = is_file( ZMAI_W_ICC ) ? file_get_contents( ZMAI_W_ICC ) : '';
zmai_w_check( '' !== $icc_bytes, 'sRGB ICC profile exists for the ICCP fixture' );

$source  = zmai_w_source_webp();
$decoded = zmai_w_decode( $source );
zmai_w_check( null !== $decoded && 40 === $decoded[0] && 30 === $decoded[1] && $decoded[2] >= 100 && $decoded[3] <= 20, 'GD source fixture is 40x30 with alpha' );

// Case 1: EXIF, XMP, duplicate EXIF/XMP (odd length), ICCP and alpha.
$meta_full = array(
	array( 'EXIF', 'Exif\0\0gps-private' ),
	array( 'XMP ', '<x:xmpmeta>gps-private</x:xmpmeta>' ),
	array( 'EXIF', 'exif-duplicate' ),
	array( 'XMP ', 'xmp-duplicate-odd' ),
);
$input = zmai_w_extended( $source, true, $meta_full );
$in    = zmai_w_walk( $input );
zmai_w_check( $in['valid'] && isset( $in['payloads']['EXIF'], $in['payloads']['XMP '], $in['payloads']['ICCP'] ), 'extended fixture carries EXIF, XMP and ICCP' );

$result = zmai_w_sanitize( zmai_w_write( $input, 'extended-icc-meta' ), 'extended-icc-meta.webp' );
zmai_w_check( is_array( $result ), 'extended fixture with metadata returns a descriptor' );
$out = zmai_w_output( $result );
zmai_w_check( null !== $out, 'descriptor path points to an existing file' );
$w = zmai_w_assert_clean( (string) $out, 'extended fixture', true );
zmai_w_check( isset( $w['payloads']['ICCP'][0] ) && $icc_bytes === $w['payloads']['ICCP'][0], 'ICCP payload is retained byte-identical' );
$same = true;
foreach ( array( 'ALPH', 'VP8 ', 'VP8L' ) as $f ) {
	$same = $same && ( $w['payloads'][ $f ] ?? null ) === ( $in['payloads'][ $f ] ?? null );
}
zmai_w_check( $same, 'ALPH and VP8 rendering payloads are byte-identical to input' );

// Case 2: duplicate descriptive chunks only, no ICC.
$meta_dup = array(
	array( 'EXIF', 'exif-one' ),
	array( 'EXIF', 'exif-two' ),
	array( 'XMP ', 'xmp-one' ),
	array( 'XMP ', 'xmp-two' ),
);
$dup    = zmai_w_extended( $source, false, $meta_dup );
$result = zmai_w_sanitize( zmai_w_write( $dup, 'duplicate-meta' ), 'duplicate-meta.webp' );
zmai_w_check( is_array( $result ), 'duplicate-metadata fixture returns a descriptor' );
$out = zmai_w_output( $result );
zmai_w_check( null !== $out, 'duplicate-metadata descriptor path points to an existing file' );
$w = zmai_w_assert_clean( (string) $out, 'duplicate-metadata fixture', false );
zmai_w_check( ! isset( $w['payloads']['ICCP'] ), 'duplicate-metadata fixture gains no ICCP' );

// Malformed inputs: each must be rejected with WP_Error.
$dup_walk = zmai_w_walk( $dup );
zmai_w_check( $dup_walk['valid'], 'duplicate-metadata fixture input is structurally valid' );

$overrun = substr_replace( $dup, pack( 'V', 4096 ), $dup_walk['offsets']['EXIF'] + 4, 4 );
zmai_w_check( 'chunk overruns RIFF' === zmai_w_walk( $overrun )['reason'], 'overrun fixture is rejected by the independent walker' );

$unpadded = substr( zmai_w_extended( $source, false, array( array( 'EXIF', 'abcde' ) ) ), 0, -1 );
$unpadded = substr_replace( $unpadded, pack( 'V', strlen( $unpadded ) - 8 ), 4, 4 );
zmai_w_check( 'missing or nonzero pad byte' === zmai_w_walk( $unpadded )['reason'], 'missing-pad fixture is rejected by the independent walker' );

$riff_long = substr_replace( $dup, pack( 'V', strlen( $dup ) - 8 + 16 ), 4, 4 );
zmai_w_check( 'RIFF size mismatch' === zmai_w_walk( $riff_long )['reason'], 'overstated-RIFF fixture is rejected by the independent walker' );

foreach ( array( 'chunk-overrun' => $overrun, 'missing-pad' => $unpadded, 'riff-overstated' => $riff_long ) as $tag => $bytes ) {
	$rejected = zmai_w_sanitize( zmai_w_write( $bytes, $tag ), $tag . '.webp' );
	zmai_w_check( is_wp_error( $rejected ), 'malformed ' . $tag . ' returns WP_Error' );
}

// Reconstruction structure is independent of descriptive chunk removal.
$chunks = array();
foreach ( zmai_w_source_chunks( $input ) as $chunk ) {
	$chunks[ $chunk[0] ] = zmai_w_chunk( $chunk[0], $chunk[1] );
}
$header = $chunks['VP8X'];
$image  = $chunks['VP8 '];
$alpha  = $chunks['ALPH'];
$color  = $chunks['ICCP'];
$wrong_canvas = substr_replace( $header, substr( pack( 'V', 63 ), 0, 3 ), 12, 3 );
$no_alpha_flag = $header;
$no_alpha_flag[8] = chr( ord( $header[8] ) & ~0x10 );
$malformed = array(
	'canvas-mismatch' => $wrong_canvas . $color . $alpha . $image,
	'alpha-after-image' => $header . $color . $image . $alpha,
	'duplicate-alpha' => $header . $color . $alpha . $alpha . $image,
	'color-after-image' => $header . $alpha . $image . $color,
	'alpha-without-extended' => $alpha . $image,
	'alpha-flag-clear' => $no_alpha_flag . $color . $alpha . $image,
);
foreach ( $malformed as $tag => $body ) {
	$bytes = 'RIFF' . pack( 'V', strlen( $body ) + 4 ) . 'WEBP' . $body;
	$result = zmai_w_sanitize( zmai_w_write( $bytes, $tag ), $tag . '.webp' );
	zmai_w_check( is_wp_error( $result ), 'malformed reconstruction ' . $tag . ' is rejected' );
}

$results = $GLOBALS['zmai_w_results'];
echo 'RESULT ' . ( $results['pass'] + $results['fail'] ) . ' checks, ' . $results['fail'] . ' failed' . PHP_EOL;
exit( $results['fail'] > 0 ? 1 : 0 );
