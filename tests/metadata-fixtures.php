<?php
/**
 * Programmatic JPEG fixture for the metadata sanitizer tests (first pass).
 *
 * GD draws the pixels; this file adds the metadata segments byte by byte, so every
 * container field is known and nothing is copied from a camera or a competitor.
 * The sRGB profile is the local macOS system profile, checked before use.
 *
 * @package AuthorOrUserImage
 */

/** Source size: landscape, so any rotation changes the output shape. */
const ZMAI_MF_WIDTH  = 32;
const ZMAI_MF_HEIGHT = 16;

/**
 * Draw the source: red, green, blue and yellow quadrants (top-left, top-right, bottom-left, bottom-right).
 *
 * @return resource GD image.
 */
function zmai_mf_source_image() {
	$img = imagecreatetruecolor( ZMAI_MF_WIDTH, ZMAI_MF_HEIGHT );
	imagefilledrectangle( $img, 0, 0, 15, 7, imagecolorallocate( $img, 220, 20, 20 ) );
	imagefilledrectangle( $img, 16, 0, 31, 7, imagecolorallocate( $img, 20, 200, 40 ) );
	imagefilledrectangle( $img, 0, 8, 15, 15, imagecolorallocate( $img, 30, 40, 230 ) );
	imagefilledrectangle( $img, 16, 8, 31, 15, imagecolorallocate( $img, 240, 220, 20 ) );
	return $img;
}

/**
 * Encode a GD image as JPEG bytes.
 *
 * @param resource $img     GD image.
 * @param int      $quality JPEG quality.
 * @return string
 */
function zmai_mf_encode_jpeg( $img, $quality ) {
	ob_start();
	imagejpeg( $img, null, $quality );
	return ob_get_clean();
}

/**
 * One JPEG marker segment: FF, marker, big-endian length that counts itself, payload.
 *
 * @param int    $marker  Marker byte.
 * @param string $payload Segment payload.
 * @return string
 */
function zmai_mf_segment( $marker, $payload ) {
	return "\xFF" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;
}

/**
 * Local sRGB profile, checked before use. Returns an empty string when unavailable.
 *
 * @return string
 */
function zmai_mf_srgb_profile() {
	$file = '/System/Library/ColorSync/Profiles/sRGB Profile.icc';
	$data = is_readable( $file ) ? file_get_contents( $file ) : false;
	if ( false === $data || strlen( $data ) < 128 || 'RGB ' !== substr( $data, 16, 4 ) || 'acsp' !== substr( $data, 36, 4 ) ) {
		return '';
	}
	return $data;
}

/**
 * Small JPEG used as the EXIF thumbnail.
 *
 * @return string
 */
function zmai_mf_thumbnail() {
	$img = imagecreatetruecolor( 8, 4 );
	imagefilledrectangle( $img, 0, 0, 7, 3, imagecolorallocate( $img, 128, 128, 128 ) );
	return zmai_mf_encode_jpeg( $img, 80 );
}

/**
 * Little-endian TIFF: IFD0 (Model, Orientation 1, GPS pointer), a GPS IFD and an IFD1 thumbnail.
 *
 * @param string $thumb JPEG thumbnail bytes.
 * @return string
 */
function zmai_mf_tiff( $thumb ) {
	$make  = "Fixture\0";
	$lat   = pack( 'V6', 40, 1, 42, 1, 4612, 100 );
	$lon   = pack( 'V6', 74, 1, 0, 1, 2112, 100 );
	$ifd0  = 8;                           // Header length.
	$gps   = $ifd0 + 2 + 3 * 12 + 4;      // 50.
	$ifd1  = $gps + 2 + 4 * 12 + 4;       // 104.
	$data  = $ifd1 + 2 + 2 * 12 + 4;      // 134.
	$lat_o = $data + strlen( $make );
	$lon_o = $lat_o + strlen( $lat );
	$thumb_o = $lon_o + strlen( $lon );

	$ifd0_block = pack( 'v', 3 )
		. pack( 'vvVV', 0x0110, 2, strlen( $make ), $data )
		. pack( 'vvVvv', 0x0112, 3, 1, 1, 0 )
		. pack( 'vvVV', 0x8825, 4, 1, $gps )
		. pack( 'V', $ifd1 );

	$gps_block = pack( 'v', 4 )
		. pack( 'vvV', 0x0001, 2, 2 ) . "N\0\0\0"
		. pack( 'vvVV', 0x0002, 5, 3, $lat_o )
		. pack( 'vvV', 0x0003, 2, 2 ) . "W\0\0\0"
		. pack( 'vvVV', 0x0004, 5, 3, $lon_o )
		. pack( 'V', 0 );

	$ifd1_block = pack( 'v', 2 )
		. pack( 'vvVV', 0x0201, 4, 1, $thumb_o )
		. pack( 'vvVV', 0x0202, 4, 1, strlen( $thumb ) )
		. pack( 'V', 0 );

	return "II*\0" . pack( 'V', 8 ) . $ifd0_block . $gps_block . $ifd1_block
		. $make . $lat . $lon . $thumb;
}

/**
 * XMP packet with a creator property.
 *
 * @return string
 */
function zmai_mf_xmp() {
	return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
		. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
		. '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>Fixture Author</dc:creator>'
		. '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
}

/**
 * Photoshop 3.0 APP13 payload carrying one IPTC byline (record 2, dataset 80).
 *
 * @return string
 */
function zmai_mf_iptc_payload() {
	$byline = 'Fixture Author';
	$iptc   = "\x1C\x02\x50" . pack( 'n', strlen( $byline ) ) . $byline;
	$pad    = strlen( $iptc ) % 2 ? "\0" : '';
	return "Photoshop 3.0\0" . '8BIM' . pack( 'n', 0x0404 ) . "\0\0"
		. pack( 'N', strlen( $iptc ) ) . $iptc . $pad;
}

/**
 * Orientation-1 JPEG: GD pixels, then EXIF (GPS, orientation, thumbnail), XMP, IPTC,
 * COM and RGB ICC segments after GD's JFIF header.
 *
 * @param bool $metadata Include the descriptive segments; false keeps only the ICC segment.
 * @return string
 */
function zmai_mf_jpeg( $metadata ) {
	$base  = zmai_mf_encode_jpeg( zmai_mf_source_image(), 100 );
	$split = 2;
	if ( "\xFF\xE0" === substr( $base, 2, 2 ) ) {
		$split = 4 + unpack( 'n', substr( $base, 4, 2 ) )[1];
	}
	$out = substr( $base, 0, $split );
	if ( $metadata ) {
		$out .= zmai_mf_segment( 0xE1, "Exif\0\0" . zmai_mf_tiff( zmai_mf_thumbnail() ) );
		$out .= zmai_mf_segment( 0xE1, "http://ns.adobe.com/xap/1.0/\0" . zmai_mf_xmp() );
		$out .= zmai_mf_segment( 0xED, zmai_mf_iptc_payload() );
		$out .= zmai_mf_segment( 0xFE, 'Fixture comment' );
	}
	$out .= zmai_mf_segment( 0xE2, "ICC_PROFILE\0\x01\x01" . zmai_mf_srgb_profile() );
	return $out . substr( $base, $split );
}

/**
 * Write the first-pass fixtures into the scratch fixture directory.
 *
 * @param string $dir Absolute fixture directory.
 * @return array|null {jpeg:string,profile:string}, or null when the sRGB profile or directory is unavailable.
 */
function zmai_mf_write( $dir ) {
	$profile = zmai_mf_srgb_profile();
	if ( '' === $profile || ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) ) {
		return null;
	}
	$jpeg = $dir . '/zmai-meta-orient1-full.jpg';
	file_put_contents( $dir . '/srgb.icc', $profile );
	file_put_contents( $jpeg, zmai_mf_jpeg( true ) );
	return array(
		'jpeg'    => $jpeg,
		'profile' => $profile,
	);
}
