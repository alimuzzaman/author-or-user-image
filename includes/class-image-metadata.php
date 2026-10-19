<?php
/**
 * Private avatar staging metadata sanitation.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Sanitizes a private copy before any public Media Library write. */
final class Image_Metadata {

	/**
	 * Validate, normalize orientation, strip metadata and verify a staging file.
	 *
	 * @param string $path Private staging file, never a competitor source.
	 * @param string $name Filename for extension/MIME checks.
	 * @param array  $context Trusted owner and source, never request fields.
	 * @return array|WP_Error Validated image descriptor or typed failure.
	 */
	public static function sanitize( $path, $name, array $context ) {
		return self::process( $path, $name, $context, true );
	}

	/**
	 * Remove encoder-generated comments and restore compatible source color data.
	 *
	 * @internal
	 * @param string $path Newly generated owned image.
	 * @param string $icc Source color-profile container records.
	 * @return array|WP_Error
	 */
	public static function sanitize_generated( $path, $icc = '' ) {
		return self::process( $path, basename( $path ), array(), false, $icc );
	}

	/**
	 * Shared bounded processing, with the opt-out evaluated only on input staging.
	 *
	 * @param string $path Local file.
	 * @param string $name Extension-bearing name.
	 * @param array  $context Trusted operation context.
	 * @param bool   $filter Whether this is the incoming private staging operation.
	 * @param string $icc Compatible source color data for generated images.
	 * @return array|WP_Error
	 */
	private static function process( $path, $name, array $context, $filter, $icc = '' ) {
		try {
			$valid = Image_Processor::validate_local_image( $path, $name );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$input = self::read( $valid['path'] );
			$clean = self::parse( $input, $valid['type'] );
			if ( 'image/jpeg' === $valid['type'] && 4 === $clean['channels'] ) {
				$editor = wp_get_image_editor( $valid['path'] );
				if ( is_wp_error( $editor ) ) {
					return self::error();
				}
				if ( $editor instanceof \WP_Image_Editor_GD ) {
					return new WP_Error( 'zmai_metadata_cmyk', __( 'This server cannot safely normalize this CMYK JPEG. Convert it to RGB and try again.', 'author-or-user-image' ) );
				}
			}
			/**
			 * Opt out for this operation through trusted PHP only.
			 *
			 * @param bool  $strip Remove descriptive metadata. Default true.
			 * @param array $context Trusted owner ID and upload/default/import/legacy source.
			 */
			if ( $filter && ! apply_filters( 'zmai_strip_image_metadata', true, $context ) ) {
				$valid['metadata_stripped'] = false;
				return $valid;
			}
			if ( 'image/jpeg' === $valid['type'] && 1 !== $clean['orientation'] ) {
				$clean = self::orient( $valid['path'], $clean );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
			}
			if ( '' !== $icc ) {
				$clean = self::with_profile( $clean, $valid, $icc );
			}
			// An idempotent second parse proves that no removable structure remains.
			$verify = self::parse( $clean['bytes'], $valid['type'] );
			if ( $verify['bytes'] !== $clean['bytes'] || 1 !== $verify['orientation'] ) {
				return self::error();
			}
			// Keep the original PHP upload path and its provenance. Never substitute
			// another tmp_name or disable core's upload test.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Checked private local staging write, not a remote filesystem operation.
			if ( file_put_contents( $valid['path'], $clean['bytes'] ) !== strlen( $clean['bytes'] ) || self::read( $valid['path'] ) !== $clean['bytes'] ) {
				return self::error();
			}
			clearstatcache( true, $valid['path'] );
			$result = Image_Processor::validate_local_image( $valid['path'], $name );
			if ( is_wp_error( $result ) || $result['type'] !== $valid['type'] ) {
				return self::error();
			}
			$swapped = 'image/jpeg' === $valid['type'] && in_array( $clean['input_orientation'] ?? 1, array( 5, 6, 7, 8 ), true );
			if ( $result['width'] !== $valid[ $swapped ? 'height' : 'width' ] || $result['height'] !== $valid[ $swapped ? 'width' : 'height' ] ) {
				return self::error();
			}
			$result['metadata_stripped'] = true;
			$result['metadata_icc']      = $verify['icc'] ?? '';
			$result['metadata_channels'] = $verify['channels'] ?? 3;
			return $result;
		} catch ( \Throwable $error ) {
			return self::error();
		}
	}

	/**
	 * Read no more than the existing processing bound, including editor output.
	 *
	 * @param string $path Private file.
	 * @throws \RuntimeException On a partial or oversized read.
	 * @return string
	 */
	private static function read( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded private local image read.
		$bytes = file_get_contents( $path, false, null, 0, Image_Processor::MAX_FILE_SIZE + 1 );
		if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > Image_Processor::MAX_FILE_SIZE ) {
			throw new \RuntimeException( 'Invalid private image read.' );
		}
		return $bytes;
	}

	/**
	 * Parse a bounded container. Binary contents are never searched with regex.
	 *
	 * @param string $bytes Bounded bytes.
	 * @param string $mime Validated MIME.
	 * @return array
	 */
	private static function parse( $bytes, $mime ) {
		if ( 'image/jpeg' === $mime ) {
			return self::jpeg( $bytes );
		}
		return 'image/png' === $mime ? self::png( $bytes ) : self::webp( $bytes );
	}

	/**
	 * Reject malformed structures through one internal exception boundary.
	 *
	 * @param bool $condition Required structure property.
	 * @throws \RuntimeException On invalid container structure.
	 * @return void
	 */
	private static function require_structure( $condition ) {
		if ( ! $condition ) {
			throw new \RuntimeException( 'Malformed image container.' );
		}
	}

	/**
	 * Read an unsigned endian-specific integer within a TIFF block.
	 *
	 * @param string $tiff TIFF bytes.
	 * @param int    $offset Byte offset.
	 * @param int    $size Two or four bytes.
	 * @param bool   $little Little endian.
	 * @return int
	 */
	private static function integer( $tiff, $offset, $size, $little ) {
		self::require_structure( $offset >= 0 && $offset + $size <= strlen( $tiff ) );
		$format = 2 === $size ? ( $little ? 'v' : 'n' ) : ( $little ? 'V' : 'N' );
		return unpack( $format, substr( $tiff, $offset, $size ) )[1];
	}

	/**
	 * Read IFD0 orientation without trusting offsets or decoding pixels.
	 *
	 * @param string $payload EXIF APP1 contents.
	 * @return int
	 */
	private static function orientation( $payload ) {
		$tiff = substr( $payload, 6 );
		self::require_structure( strlen( $tiff ) >= 8 && in_array( substr( $tiff, 0, 2 ), array( 'II', 'MM' ), true ) );
		$little = 'II' === substr( $tiff, 0, 2 );
		self::require_structure( 42 === self::integer( $tiff, 2, 2, $little ) );
		$offset = self::integer( $tiff, 4, 4, $little );
		self::require_structure( $offset >= 8 );
		$count = self::integer( $tiff, $offset, 2, $little );
		self::require_structure( $offset + 2 + 12 * $count + 4 <= strlen( $tiff ) );
		$orientation = 1;
		$seen        = false;
		for ( $i = 0; $i < $count; ++$i ) {
			$entry = $offset + 2 + 12 * $i;
			if ( 0x0112 !== self::integer( $tiff, $entry, 2, $little ) ) {
				continue;
			}
			self::require_structure( ! $seen && 3 === self::integer( $tiff, $entry + 2, 2, $little ) && 1 === self::integer( $tiff, $entry + 4, 4, $little ) );
			$orientation = self::integer( $tiff, $entry + 8, 2, $little );
			self::require_structure( $orientation >= 1 && $orientation <= 8 );
			$seen = true;
		}
		return $orientation;
	}

	/**
	 * Walk all JPEG markers and entropy scans, retaining only rendering segments.
	 *
	 * @param string $bytes JPEG bytes.
	 * @param bool   $keep_icc Preserve original ICC APP2 segments.
	 * @return array
	 */
	private static function jpeg( $bytes, $keep_icc = true ) {
		$length = strlen( $bytes );
		self::require_structure( $length >= 4 && "\xff\xd8" === substr( $bytes, 0, 2 ) );
		$out           = "\xff\xd8";
		$icc           = '';
		$profiles      = array();
		$profile_count = 0;
		$orientation   = 1;
		$exif          = false;
		$frame         = false;
		$scan          = false;
		$channels      = 0;
		$offset        = 2;
		while ( $offset < $length ) {
			self::require_structure( "\xff" === $bytes[ $offset ] );
			while ( $offset < $length && "\xff" === $bytes[ $offset ] ) {
				++$offset;
			}
			self::require_structure( $offset < $length );
			$marker = ord( $bytes[ $offset++ ] );
			if ( 0xd9 === $marker ) {
				self::require_structure( $offset === $length && $frame && $scan && count( $profiles ) === $profile_count );
				return array(
					'bytes'       => $out . "\xff\xd9",
					'icc'         => $icc,
					'orientation' => $orientation,
					'channels'    => $channels,
				);
			}
			self::require_structure( $marker >= 0xc0 && $marker <= 0xfe && ! in_array( $marker, array( 0xd8, 0xd0, 0xd1, 0xd2, 0xd3, 0xd4, 0xd5, 0xd6, 0xd7 ), true ) && $offset + 2 <= $length );
			$size = unpack( 'n', substr( $bytes, $offset, 2 ) )[1];
			self::require_structure( $size >= 2 && $offset + $size <= $length );
			$payload = substr( $bytes, $offset + 2, $size - 2 );
			$chunk   = "\xff" . chr( $marker ) . substr( $bytes, $offset, $size );
			$offset += $size;
			$keep    = $marker < 0xe0 && 0xfe !== $marker;
			if ( 0xe1 === $marker && "Exif\0\0" === substr( $payload, 0, 6 ) ) {
				self::require_structure( ! $exif );
				$orientation = self::orientation( $payload );
				$exif        = true;
			} elseif ( 0xe2 === $marker && "ICC_PROFILE\0" === substr( $payload, 0, 12 ) ) {
				self::require_structure( strlen( $payload ) >= 15 );
				$sequence = ord( $payload[12] );
				$total    = ord( $payload[13] );
				self::require_structure( $total > 0 && $sequence > 0 && $sequence <= $total && ! isset( $profiles[ $sequence ] ) && ( ! $profile_count || $total === $profile_count ) );
				$profiles[ $sequence ] = true;
				$profile_count         = $total;
				$icc                  .= $chunk;
				$keep                  = $keep_icc;
			} elseif ( 0xe0 === $marker && "JFIF\0" === substr( $payload, 0, 5 ) ) {
				self::require_structure( strlen( $payload ) >= 14 && strlen( $payload ) === 14 + 3 * ord( $payload[12] ) * ord( $payload[13] ) );
				// JFIF thumbnails also retain uncropped pixels; retain only density/version.
				$chunk = "\xff\xe0\0\x10" . substr( $payload, 0, 12 ) . "\0\0";
				$keep  = true;
			} elseif ( 0xee === $marker && 'Adobe' === substr( $payload, 0, 5 ) ) {
				self::require_structure( 12 === strlen( $payload ) );
				$keep = true;
			}
			if ( $marker >= 0xc0 && $marker <= 0xcf && ! in_array( $marker, array( 0xc4, 0xc8, 0xcc ), true ) ) {
				self::require_structure( ! $frame && strlen( $payload ) >= 6 );
				$channels = ord( $payload[5] );
				self::require_structure( $channels > 0 && strlen( $payload ) === 6 + 3 * $channels );
				$frame = true;
			}
			if ( $keep ) {
				$out .= $chunk;
			}
			if ( 0xda === $marker ) {
				self::require_structure( $frame );
				$scan  = true;
				$start = $offset;
				while ( $offset < $length ) {
					if ( "\xff" !== $bytes[ $offset ] ) {
						++$offset;
						continue;
					}
					$next = $offset + 1;
					while ( $next < $length && "\xff" === $bytes[ $next ] ) {
						++$next;
					}
					self::require_structure( $next < $length );
					$value = ord( $bytes[ $next ] );
					if ( 0 === $value || ( $value >= 0xd0 && $value <= 0xd7 ) ) {
						$offset = $next + 1;
					} else {
						break;
					}
				}
				$out .= substr( $bytes, $start, $offset - $start );
			}
		}
		self::require_structure( false );
	}

	/**
	 * Walk PNG chunks, check CRC/bounds and preserve rendering/color chunks only.
	 *
	 * @param string $bytes PNG bytes already checked by Image_Processor.
	 * @return array
	 */
	private static function png( $bytes ) {
		$length = strlen( $bytes );
		self::require_structure( "\x89PNG\r\n\x1a\n" === substr( $bytes, 0, 8 ) );
		$out    = substr( $bytes, 0, 8 );
		$offset = 8;
		$icc    = '';
		$keep   = array( 'IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'iCCP', 'sRGB', 'gAMA', 'cHRM', 'sBIT', 'bKGD' );
		while ( $offset < $length ) {
			self::require_structure( $offset + 12 <= $length );
			$size = unpack( 'N', substr( $bytes, $offset, 4 ) )[1];
			$type = substr( $bytes, $offset + 4, 4 );
			self::require_structure( $size <= 0x7fffffff && $offset + 12 + $size <= $length && 0 === ( ord( $type[2] ) & 32 ) );
			$chunk = substr( $bytes, $offset, $size + 12 );
			self::require_structure( hash_equals( hash( 'crc32b', substr( $chunk, 4, $size + 4 ), true ), substr( $chunk, $size + 8, 4 ) ) );
			if ( 'iCCP' === $type ) {
				self::require_structure( '' === $icc );
				$icc = $chunk;
			}
			if ( in_array( $type, $keep, true ) ) {
				$out .= $chunk;
			} else {
				self::require_structure( 0 !== ( ord( $type[0] ) & 32 ) );
			}
			$offset += $size + 12;
		}
		return array(
			'bytes'       => $out,
			'orientation' => 1,
			'icc'         => $icc,
		);
	}

	/**
	 * Walk static WebP chunks and rebuild RIFF length, padding and feature flags.
	 *
	 * @param string $bytes WebP bytes already checked by Image_Processor.
	 * @return array
	 */
	private static function webp( $bytes ) {
		$length = strlen( $bytes );
		self::require_structure( $length >= 20 && 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) && unpack( 'V', substr( $bytes, 4, 4 ) )[1] + 8 === $length );
		$offset   = 12;
		$out      = '';
		$extended = false;
		$icc      = false;
		$profile  = '';
		$alpha    = false;
		$image    = false;
		$flags    = 0;
		$canvas   = array();
		while ( $offset < $length ) {
			self::require_structure( $offset + 8 <= $length );
			$type = substr( $bytes, $offset, 4 );
			$size = unpack( 'V', substr( $bytes, $offset + 4, 4 ) )[1];
			$end  = $offset + 8 + $size + $size % 2;
			self::require_structure( $end <= $length && ( ! ( $size % 2 ) || "\0" === $bytes[ $end - 1 ] ) );
			$payload = substr( $bytes, $offset + 8, $size );
			if ( 'VP8X' === $type ) {
				self::require_structure( 12 === $offset && ! $extended && 10 === $size && 0 === ( ord( $payload[0] ) & 0xc3 ) && "\0\0\0" === substr( $payload, 1, 3 ) );
				$flags      = ord( $payload[0] );
				$canvas     = array( unpack( 'V', substr( $payload, 4, 3 ) . "\0" )[1] + 1, unpack( 'V', substr( $payload, 7, 3 ) . "\0" )[1] + 1 );
				$payload[0] = chr( $flags & 0x30 );
				$extended   = true;
			}
			if ( 'ICCP' === $type ) {
				self::require_structure( $extended && ! $icc && ! $alpha && ! $image && $size > 0 );
				$icc     = true;
				$profile = substr( $bytes, $offset, $end - $offset );
			}
			if ( 'ALPH' === $type ) {
				self::require_structure( $extended && ( $flags & 0x10 ) && ! $alpha && ! $image && $size > 0 );
				$alpha = true;
			}
			if ( 'VP8 ' === $type || 'VP8L' === $type ) {
				self::require_structure( ! $image );
				if ( 'VP8 ' === $type ) {
					self::require_structure( $size >= 10 && 0 === ( ord( $payload[0] ) & 1 ) && "\x9d\x01\x2a" === substr( $payload, 3, 3 ) );
					$dimensions = array( unpack( 'v', substr( $payload, 6, 2 ) )[1] & 0x3fff, unpack( 'v', substr( $payload, 8, 2 ) )[1] & 0x3fff );
					self::require_structure( ! $extended || (bool) ( $flags & 0x10 ) === $alpha );
				} else {
					self::require_structure( ! $alpha && $size >= 5 && "\x2f" === $payload[0] );
					$bits       = unpack( 'V', substr( $payload, 1, 4 ) )[1];
					$dimensions = array( ( $bits & 0x3fff ) + 1, ( ( $bits >> 14 ) & 0x3fff ) + 1 );
					self::require_structure( 0 === ( $bits >> 29 ) );
				}
				self::require_structure( $dimensions[0] > 0 && $dimensions[1] > 0 && ( ! $extended || $canvas === $dimensions ) );
				$image = true;
			}
			self::require_structure( ! in_array( $type, array( 'ANIM', 'ANMF' ), true ) );
			if ( in_array( $type, array( 'VP8X', 'ICCP', 'ALPH', 'VP8 ', 'VP8L' ), true ) ) {
				$out .= $type . pack( 'V', $size ) . $payload . ( $size % 2 ? "\0" : '' );
			}
			$offset = $end;
		}
		self::require_structure( $image && ( ! $extended || (bool) ( $flags & 0x20 ) === $icc ) );
		if ( $extended ) {
			// Offset 8 is VP8X's feature byte in the rebuilt chunk stream.
			$out[8] = chr( ( ord( $out[8] ) & ~0x20 ) | ( $icc ? 0x20 : 0 ) );
		}
		return array(
			'bytes'       => 'RIFF' . pack( 'V', strlen( $out ) + 4 ) . 'WEBP' . $out,
			'orientation' => 1,
			'icc'         => $profile,
		);
	}

	/**
	 * Restore source ICC after a backend re-encodes the same pixel color space.
	 *
	 * @param array  $clean Parsed clean output.
	 * @param array  $valid Output MIME/dimensions.
	 * @param string $icc Original ICC container records.
	 * @return array
	 */
	private static function with_profile( array $clean, array $valid, $icc ) {
		$mime = $valid['type'];
		if ( 'image/jpeg' === $mime ) {
			// Never attach an RGB source profile to CMYK/grayscale output.
			if ( 3 !== $clean['channels'] ) {
				return $clean;
			}
			$bare           = self::jpeg( $clean['bytes'], false );
			$clean['bytes'] = "\xff\xd8" . $icc . substr( $bare['bytes'], 2 );
			return $clean;
		}
		$bytes  = $clean['bytes'];
		$offset = 'image/png' === $mime ? 8 : 12;
		$out    = '';
		$header = '';
		$alpha  = false;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			if ( 'image/png' === $mime ) {
				$size = unpack( 'N', substr( $bytes, $offset, 4 ) )[1] + 12;
				$type = substr( $bytes, $offset + 4, 4 );
			} else {
				$payload_size = unpack( 'V', substr( $bytes, $offset + 4, 4 ) )[1];
				$size         = 8 + $payload_size + $payload_size % 2;
				$type         = substr( $bytes, $offset, 4 );
				if ( 'ALPH' === $type || ( 'VP8L' === $type && $payload_size >= 5 && ( ord( $bytes[ $offset + 12 ] ) & 0x10 ) ) ) {
					$alpha = true;
				}
			}
			$chunk = substr( $bytes, $offset, $size );
			if ( 'VP8X' === $type ) {
				$chunk[8] = chr( ord( $chunk[8] ) | 0x20 );
				$header   = $chunk;
			} elseif ( 'IHDR' === $type ) {
				$out .= $chunk . $icc;
			} elseif ( ! in_array( $type, array( 'iCCP', 'ICCP', 'sRGB' ), true ) ) {
				$out .= $chunk;
			}
			$offset += $size;
		}
		if ( 'image/png' === $mime ) {
			$clean['bytes'] = substr( $bytes, 0, 8 ) . $out;
		} else {
			if ( '' === $header ) {
				$payload = chr( 0x20 | ( $alpha ? 0x10 : 0 ) ) . "\0\0\0" . substr( pack( 'V', $valid['width'] - 1 ), 0, 3 ) . substr( pack( 'V', $valid['height'] - 1 ), 0, 3 );
				$header  = 'VP8X' . pack( 'V', 10 ) . $payload;
			}
			$out            = $header . $icc . $out;
			$clean['bytes'] = 'RIFF' . pack( 'V', strlen( $out ) + 4 ) . 'WEBP' . $out;
		}
		return $clean;
	}

	/**
	 * Normalize JPEG pixels once, then sanitize the encoder's output too.
	 *
	 * @param string $path Validated private source.
	 * @param array  $source Parsed source and compatible ICC segments.
	 * @return array|WP_Error
	 */
	private static function orient( $path, array $source ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return self::error();
		}
		if ( 4 === $source['channels'] && $editor instanceof \WP_Image_Editor_GD ) {
			return new WP_Error( 'zmai_metadata_cmyk', __( 'This server cannot safely normalize this CMYK JPEG. Convert it to RGB and try again.', 'author-or-user-image' ) );
		}
		$orientation = $source['orientation'];
		$result      = true;
		switch ( $orientation ) {
			case 2:
				$result = $editor->flip( false, true );
				break;
			case 3:
				$result = $editor->flip( true, true );
				break;
			case 4:
				$result = $editor->flip( true, false );
				break;
			case 5:
			case 7:
				$result = $editor->rotate( 90 );
				if ( ! is_wp_error( $result ) ) {
					$result = $editor->flip( 5 === $orientation, 7 === $orientation );
				}
				break;
			case 6:
				$result = $editor->rotate( 270 );
				break;
			case 8:
				$result = $editor->rotate( 90 );
				break;
		}
		if ( is_wp_error( $result ) || is_wp_error( $editor->set_quality( 95 ) ) ) {
			return self::error();
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temporary = wp_tempnam( 'zmai-metadata' );
		if ( ! $temporary ) {
			return self::error();
		}
		$output = $temporary . '.jpg';
		$pin    = static function () {
			return array();
		};
		add_filter( 'image_editor_output_format', $pin, PHP_INT_MAX );
		try {
			$saved = $editor->save( $output, 'image/jpeg' );
			if ( is_wp_error( $saved ) || empty( $saved['path'] ) || $saved['path'] !== $output ) {
				return self::error();
			}
			$normalized = self::jpeg( self::read( $output ) );
			if ( $source['channels'] !== $normalized['channels'] ) {
				return self::error();
			}
			if ( 3 === $source['channels'] ) {
				// GD drops profiles; Imagick may write its own profile. Attach the
				// source RGB ICC exactly once, only when the pixel color space agrees.
				$normalized          = self::jpeg( self::read( $output ), false );
				$normalized['bytes'] = "\xff\xd8" . $source['icc'] . substr( $normalized['bytes'], 2 );
			}
			$normalized['orientation']       = 1;
			$normalized['input_orientation'] = $orientation;
			return $normalized;
		} finally {
			remove_filter( 'image_editor_output_format', $pin, PHP_INT_MAX );
			foreach ( array( $temporary, $output ) as $file ) {
				if ( is_file( $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Private local editor output must not be retained by media deletion filters.
					@unlink( $file );
					clearstatcache( true, $file );
					self::require_structure( ! is_file( $file ) );
				}
			}
		}
	}

	/**
	 * Fixed privacy failure, never a successful fallback to tagged bytes.
	 *
	 * @return WP_Error
	 */
	private static function error() {
		return new WP_Error( 'zmai_metadata_failed', __( 'The image metadata could not be removed safely. Choose another image.', 'author-or-user-image' ) );
	}
}
