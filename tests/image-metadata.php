<?php
/**
 * First-pass sanitizer regression for Image_Metadata::sanitize(): an orientation-1
 * JPEG with EXIF (GPS, orientation, thumbnail), XMP, IPTC, COM and an RGB ICC
 * profile. Fixtures come from tests/metadata-fixtures.php. Every expectation is read
 * with the independent parsers below, never with the production sanitizer.
 *
 * Run on a disposable local site:
 * wp --path=<site> --require=<plugin>/tests/candidate-bootstrap.php eval-file <plugin>/tests/image-metadata.php
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Metadata;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use only a disposable local WordPress test instance.' );
}

require_once __DIR__ . '/metadata-fixtures.php';

if ( ! class_exists( Image_Metadata::class ) ) {
	$zmai_meta_candidate = defined( 'ZMAI_TEST_CANDIDATE' ) ? ZMAI_TEST_CANDIDATE : dirname( __DIR__ );
	require_once $zmai_meta_candidate . '/includes/class-image-metadata.php';
}

$GLOBALS['zmai_meta_checks']   = 0;
$GLOBALS['zmai_meta_failures'] = array();
$GLOBALS['zmai_meta_missing']  = array();

/**
 * Record an assertion and keep running independent cases.
 *
 * @param bool   $condition Assertion condition.
 * @param string $label     Assertion label.
 * @return bool
 */
function zmai_meta_expect( $condition, $label ) {
	++$GLOBALS['zmai_meta_checks'];
	if ( ! $condition ) {
		$GLOBALS['zmai_meta_failures'][] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	return (bool) $condition;
}

/**
 * Record a capability that is absent. A missing check is never a pass.
 *
 * @param string $label  Capability or check.
 * @param string $reason Why it was skipped.
 */
function zmai_meta_missing( $label, $reason ) {
	$GLOBALS['zmai_meta_missing'][] = $label . ': ' . $reason;
	echo 'MISSING ' . $label . ': ' . $reason . "\n";
}

/**
 * Independent JPEG marker walk. Returns [marker, payload] pairs up to and including SOS,
 * or null when the structure is malformed (bad SOI, length below 2, or overrun).
 *
 * @param string $bytes File bytes.
 * @return array|null
 */
function zmai_meta_jpeg_segments( $bytes ) {
	if ( ! is_string( $bytes ) || "\xFF\xD8" !== substr( $bytes, 0, 2 ) ) {
		return null;
	}
	$segments = array();
	$pos      = 2;
	$len      = strlen( $bytes );
	while ( $pos + 4 <= $len ) {
		if ( "\xFF" !== $bytes[ $pos ] ) {
			return null;
		}
		$marker = ord( $bytes[ $pos + 1 ] );
		$seg    = unpack( 'n', substr( $bytes, $pos + 2, 2 ) );
		$seg    = $seg[1];
		if ( $seg < 2 || $pos + 2 + $seg > $len ) {
			return null;
		}
		$segments[] = array( $marker, substr( $bytes, $pos + 4, $seg - 2 ) );
		if ( 0xDA === $marker ) {
			return $segments;
		}
		$pos += 2 + $seg;
	}
	return null;
}

/**
 * Expected RGB at an orientation-1 source coordinate, from the quadrant table.
 *
 * @param int $x Column.
 * @param int $y Row.
 * @return int[]
 */
function zmai_meta_quadrant_rgb( $x, $y ) {
	if ( $y < 8 ) {
		return $x < 16 ? array( 220, 20, 20 ) : array( 20, 200, 40 );
	}
	return $x < 16 ? array( 30, 40, 230 ) : array( 240, 220, 20 );
}

/**
 * Orientation-1 JPEG case: fixture self-checks, then sanitizer output checks.
 *
 * @param string $root Scratch root for fixtures and work copies.
 */
function zmai_meta_jpeg_orient1( $root ) {
	$fixture = zmai_mf_write( $root . '/fixtures' );
	if ( null === $fixture ) {
		zmai_meta_missing( 'sRGB fixture profile', 'local sRGB Profile.icc is unreadable or not an RGB ICC; no fallback generated' );
		return;
	}

	$input_segments = zmai_meta_jpeg_segments( file_get_contents( $fixture['jpeg'] ) );
	zmai_meta_expect( is_array( $input_segments ), 'fixture: independent parser reads the input JPEG' );
	$input_segments = is_array( $input_segments ) ? $input_segments : array();
	$counts         = array(
		0xE1 => 0,
		0xED => 0,
		0xFE => 0,
	);
	$icc_ok         = false;
	foreach ( $input_segments as $segment ) {
		list( $marker, $payload ) = $segment;
		if ( isset( $counts[ $marker ] ) ) {
			++$counts[ $marker ];
		}
		if ( 0xE2 === $marker && 0 === strpos( $payload, "ICC_PROFILE\0" ) && $fixture['profile'] === substr( $payload, 14 ) ) {
			$icc_ok = true;
		}
	}
	zmai_meta_expect( 2 === $counts[0xE1], 'fixture: two APP1 segments (EXIF and XMP)' );
	zmai_meta_expect( 1 === $counts[0xED], 'fixture: one APP13 IPTC segment' );
	zmai_meta_expect( $counts[0xFE] >= 1, 'fixture: at least one COM segment' );
	zmai_meta_expect( $icc_ok, 'fixture: APP2 ICC_PROFILE carries the sRGB profile' );

	$work = $root . '/work/orient1-full';
	if ( ! is_dir( $work ) ) {
		mkdir( $work, 0755, true );
	}
	$staged = $work . '/zmai-meta-orient1-full.jpg';
	copy( $fixture['jpeg'], $staged );
	$result = Image_Metadata::sanitize( $staged, 'zmai-meta-orient1-full.jpg', array( 'owner' => 1, 'source' => 'upload' ) );

	$output_labels = array(
		'descriptor: readable file, type image/jpeg, 32x16',
		'output: no APP1, APP13 or COM segments',
		'output: RGB ICC profile byte-identical to sRGB fixture',
		'output: GD pixels match orientation-1 quadrants',
	);
	if ( ! is_array( $result ) ) {
		$code = is_wp_error( $result ) ? $result->get_error_code() : 'non-array';
		echo 'sanitize returned ' . $code . "\n";
		zmai_meta_expect( false, 'sanitize: returns a descriptor array' );
		foreach ( $output_labels as $label ) {
			zmai_meta_expect( false, $label . ' (no descriptor)' );
		}
		return;
	}
	zmai_meta_expect( true, 'sanitize: returns a descriptor array' );

	$path = isset( $result['path'] ) ? (string) $result['path'] : '';
	zmai_meta_expect(
		is_file( $path ) && 'image/jpeg' === ( $result['type'] ?? '' ) && 32 === ( $result['width'] ?? 0 ) && 16 === ( $result['height'] ?? 0 ),
		$output_labels[0]
	);

	$bytes        = is_file( $path ) ? file_get_contents( $path ) : '';
	$out_segments = zmai_meta_jpeg_segments( $bytes );
	$parsed       = is_array( $out_segments );
	$out_segments = $parsed ? $out_segments : array();
	$forbidden    = array();
	$icc          = null;
	foreach ( $out_segments as $segment ) {
		list( $marker, $payload ) = $segment;
		if ( 0xE0 === $marker && 0 === strpos( $payload, "JFIF\0" ) ) {
			continue;
		}
		if ( 0xE2 === $marker && 0 === strpos( $payload, "ICC_PROFILE\0" ) ) {
			$icc = substr( $payload, 14 );
			continue;
		}
		if ( 0xFE === $marker || ( $marker >= 0xE0 && $marker <= 0xEF ) ) {
			$forbidden[] = sprintf( '0x%02X', $marker );
		}
	}
	zmai_meta_expect(
		$parsed && array() === $forbidden,
		$output_labels[1] . ( $forbidden ? ' (found ' . implode( ',', $forbidden ) . ')' : '' )
	);
	zmai_meta_expect( null !== $icc && $icc === $fixture['profile'], $output_labels[2] );

	$img        = is_file( $path ) ? @imagecreatefromjpeg( $path ) : false;
	$checked    = 0;
	$mismatched = 0;
	if ( $img ) {
		for ( $y = 0; $y < ZMAI_MF_HEIGHT; $y++ ) {
			for ( $x = 0; $x < ZMAI_MF_WIDTH; $x++ ) {
				if ( abs( $x - 15.5 ) < 2 || abs( $y - 7.5 ) < 2 ) {
					continue;
				}
				++$checked;
				$px   = imagecolorat( $img, $x, $y );
				$got  = array( ( $px >> 16 ) & 0xFF, ( $px >> 8 ) & 0xFF, $px & 0xFF );
				$want = zmai_meta_quadrant_rgb( $x, $y );
				if ( max( abs( $got[0] - $want[0] ), abs( $got[1] - $want[1] ), abs( $got[2] - $want[2] ) ) > 48 ) {
					++$mismatched;
				}
			}
		}
	}
	zmai_meta_expect( $img && $checked > 0 && 0 === $mismatched, $output_labels[3] . " (mismatched $mismatched of $checked)" );
}

/**
 * Print the JSON summary and exit. Missing checks fail the run, never pass it.
 */
function zmai_meta_finish() {
	$summary = array(
		'checks'   => $GLOBALS['zmai_meta_checks'],
		'failures' => $GLOBALS['zmai_meta_failures'],
		'missing'  => $GLOBALS['zmai_meta_missing'],
	);
	echo wp_json_encode( $summary ) . "\n";
	WP_CLI::halt( ( $GLOBALS['zmai_meta_failures'] || $GLOBALS['zmai_meta_missing'] ) ? 1 : 0 );
}

$zmai_meta_root = '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/fix3';

if ( ! function_exists( 'imagejpeg' ) || ! function_exists( 'imagecreatefromjpeg' ) ) {
	zmai_meta_missing( 'GD JPEG support', 'imagejpeg/imagecreatefromjpeg not loaded; fixture cannot be built' );
} else {
	zmai_meta_jpeg_orient1( $zmai_meta_root );
}
// Bounds-checked JPEG marker parsing must reject malformed lengths before writes.
foreach ( array( 'short' => "\xff\xd8\xff\xe1\0\1", 'overrun' => "\xff\xd8\xff\xe1\xff\xfftruncated" ) as $label => $bytes ) {
	$path = $zmai_meta_root . '/fixtures/malformed-' . $label . '-' . wp_generate_uuid4() . '.jpg';
	file_put_contents( $path, $bytes );
	$hash = hash_file( 'sha256', $path );
	$bad = Image_Metadata::sanitize( $path, 'bad.jpg', array( 'owner' => 1, 'source' => 'upload' ) );
	zmai_meta_expect( is_wp_error( $bad ) && $hash === hash_file( 'sha256', $path ), 'malformed JPEG ' . $label . ' rejected without a partial write' );
}
zmai_meta_finish();
