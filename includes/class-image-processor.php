<?php
/**
 * Validates local avatar images and creates contained square renditions.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;


/**
 * Local image processing contract.
 *
 * Only readable local regular JPEG, PNG, and static WebP files whose extension
 * and detected MIME agree with the current site's allowed MIME list qualify.
 * Input size is bounded by the smaller of the site upload limit and 8 MiB;
 * either dimension above 4096 pixels is rejected. Animated WebP, APNG, GIF,
 * SVG, and other formats are unsupported.
 *
 * Square renditions are centered crops, never upscaled, written beside the
 * source under a unique generated name, and verified before they are returned.
 * JPEG processing requires the core EXIF extension so orientation can be
 * normalized by the selected WordPress image editor.
 */
final class Image_Processor {

	/** Maximum accepted source file size in bytes. */
	const MAX_FILE_SIZE = 8388608;

	/** Maximum accepted width or height in pixels. */
	const MAX_DIMENSION = 4096;

	/** Supported image MIME types and filename extensions. */
	const SUPPORTED_MIMES = array(
		'image/jpeg' => array( 'jpg', 'jpeg', 'jpe' ),
		'image/png'  => array( 'png' ),
		'image/webp' => array( 'webp' ),
	);

	/**
	 * Validate a local file and return its canonical descriptor.
	 *
	 * @param string $path Local file path.
	 * @param string $name User-facing filename whose extension must match.
	 * @return array{path:string,name:string,type:string,width:int,height:int,filesize:int}|WP_Error Validated local descriptor.
	 */
	public static function validate_local_image( $path, $name ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) || ! is_string( $name ) || '' === $name ) {
			return self::error( 'zmai_invalid_image', __( 'Choose a valid local image.', 'author-or-user-image' ) );
		}

		if ( preg_match( '/^[a-z][a-z0-9+.-]*:\/\//i', $path ) ) {
			return self::error( 'zmai_invalid_image', __( 'Choose a valid local image.', 'author-or-user-image' ) );
		}

		$file_stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing or unreadable local paths are a normal validation failure.
		if ( ! is_array( $file_stat ) || ! isset( $file_stat['mode'] ) || 0100000 !== ( $file_stat['mode'] & 0170000 ) ) {
			return self::error( 'zmai_invalid_image', __( 'Choose a readable local image file.', 'author-or-user-image' ) );
		}

		$real_path = realpath( $path );
		if ( false === $real_path || ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
			return self::error( 'zmai_invalid_image', __( 'Choose a readable local image file.', 'author-or-user-image' ) );
		}

		clearstatcache( true, $real_path );
		$file_size = filesize( $real_path );
		$max_bytes = min( (int) wp_max_upload_size(), self::MAX_FILE_SIZE );
		if ( false === $file_size || $file_size < 1 || $max_bytes < 1 || $file_size > $max_bytes ) {
			return self::error( 'zmai_upload_size', __( 'The image exceeds this site’s upload limit.', 'author-or-user-image' ) );
		}

		$bytes = @file_get_contents( $real_path, false, null, 0, $max_bytes + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A failed bounded read is returned as a validation error.
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $file_size || strlen( $bytes ) > $max_bytes ) {
			return self::error( 'zmai_invalid_image', __( 'The image could not be read completely.', 'author-or-user-image' ) );
		}

		$filename  = wp_basename( $name );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime      = self::mime_for_extension( $extension );
		if ( ! $mime || ! isset( self::SUPPORTED_MIMES[ $mime ] ) ) {
			return self::error( 'zmai_invalid_image', __( 'Choose a JPEG, PNG, or static WebP image.', 'author-or-user-image' ) );
		}

		$container_result = true;
		if ( 'image/png' === $mime ) {
			$container_result = self::inspect_png( $bytes );
		} elseif ( 'image/webp' === $mime ) {
			$container_result = self::inspect_webp( $bytes );
		}

		if ( 'animated' === $container_result ) {
			return self::error( 'zmai_animated_image', __( 'Animated images are not supported. Choose a still image.', 'author-or-user-image' ) );
		}
		if ( true !== $container_result ) {
			return self::error( 'zmai_invalid_image_container', __( 'The image container is malformed or unsupported.', 'author-or-user-image' ) );
		}

		$checked = wp_check_filetype_and_ext( $real_path, $filename );
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) || strtolower( $checked['ext'] ) !== $extension || $checked['type'] !== $mime ) {
			return self::error( 'zmai_invalid_image', __( 'The image extension and file type must match.', 'author-or-user-image' ) );
		}

		$image_info = @getimagesize( $real_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image headers are an ordinary validation failure.
		if ( ! is_array( $image_info ) || empty( $image_info['mime'] ) || $image_info['mime'] !== $mime ) {
			return self::error( 'zmai_invalid_image', __( 'The file does not contain a supported image.', 'author-or-user-image' ) );
		}

		$width  = isset( $image_info[0] ) ? (int) $image_info[0] : 0;
		$height = isset( $image_info[1] ) ? (int) $image_info[1] : 0;
		if ( $width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION ) {
			return self::error( 'zmai_invalid_dimensions', __( 'Choose an image no larger than 4096 by 4096 pixels.', 'author-or-user-image' ) );
		}

		return array(
			'path'     => $real_path,
			'name'     => sanitize_file_name( $filename ),
			'type'     => $mime,
			'width'    => $width,
			'height'   => $height,
			'filesize' => (int) $file_size,
		);
	}

	/**
	 * Create and verify a centered square rendition beside a local source.
	 *
	 * @param string $path Local image file path.
	 * @param int    $size Maximum square side in pixels; smaller sources stay small.
	 * @return array{path:string,file:string,width:int,height:int,mime-type:string,filesize:int}|WP_Error Saved rendition.
	 */
	public static function create_square( $path, $size ) {
		if ( ! is_int( $size ) || $size < 1 || $size > 1024 ) {
			return self::error( 'zmai_invalid_image_size', __( 'Choose a square image size between 1 and 1024 pixels.', 'author-or-user-image' ) );
		}

		$validated = self::validate_local_image( $path, is_string( $path ) ? wp_basename( $path ) : '' );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( 'image/jpeg' === $validated['type'] && ! function_exists( 'exif_read_data' ) ) {
			return self::error( 'zmai_jpeg_exif_unavailable', __( 'JPEG processing is unavailable because this server lacks EXIF support.', 'author-or-user-image' ) );
		}

		$directory      = dirname( $validated['path'] );
		$real_directory = realpath( $directory );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Core image editors need a local writable output directory, not a remote filesystem transport.
		if ( false === $real_directory || ! is_dir( $real_directory ) || ! is_writable( $real_directory ) ) {
			return self::error( 'zmai_image_save_failed', __( 'The image could not be saved beside its source.', 'author-or-user-image' ) );
		}

		$editor = wp_get_image_editor( $validated['path'], array( 'mime_type' => $validated['type'] ) );
		if ( is_wp_error( $editor ) ) {
			return self::error( 'zmai_image_editor', __( 'This image type cannot be processed by the available image editor.', 'author-or-user-image' ) );
		}

		$rotation = $editor->maybe_exif_rotate();
		if ( is_wp_error( $rotation ) ) {
			return self::error( 'zmai_image_editor', __( 'The image orientation could not be processed.', 'author-or-user-image' ) );
		}

		$dimensions = $editor->get_size();
		if ( ! is_array( $dimensions ) || empty( $dimensions['width'] ) || empty( $dimensions['height'] ) ) {
			return self::error( 'zmai_image_editor', __( 'The image dimensions could not be read.', 'author-or-user-image' ) );
		}

		$width       = (int) $dimensions['width'];
		$height      = (int) $dimensions['height'];
		$source_side = min( $width, $height );
		$output_side = min( $size, $source_side );
		$crop_x      = (int) floor( ( $width - $source_side ) / 2 );
		$crop_y      = (int) floor( ( $height - $source_side ) / 2 );
		$cropped     = $editor->crop( $crop_x, $crop_y, $source_side, $source_side, $output_side, $output_side, false );
		if ( is_wp_error( $cropped ) || true !== $cropped ) {
			return self::error( 'zmai_image_editor', __( 'The image could not be cropped.', 'author-or-user-image' ) );
		}

		$extension = self::extension_for_mime( $validated['type'] );
		if ( ! $extension ) {
			return self::error( 'zmai_invalid_image', __( 'The image type is not supported.', 'author-or-user-image' ) );
		}

		$basename    = 'zmai-avatar-' . str_replace( '-', '', wp_generate_uuid4() ) . '.' . $extension;
		$unique_name = wp_unique_filename( $real_directory, $basename );
		$output_path = trailingslashit( $real_directory ) . $unique_name;
		$saved       = $editor->save( $output_path, $validated['type'] );
		if ( is_wp_error( $saved ) || ! is_array( $saved ) ) {
			$removed = self::remove_file( $output_path );
			return self::error( 'zmai_image_save_failed', __( 'The square image could not be saved.', 'author-or-user-image' ), $removed ? null : array( 'recovery' => true ) );
		}

		$saved_path = isset( $saved['path'] ) && is_string( $saved['path'] ) ? $saved['path'] : $output_path;
		$real_saved = realpath( $saved_path );
		if ( false === $real_saved || dirname( $real_saved ) !== $real_directory || basename( $real_saved ) !== $unique_name || ! self::is_regular_file( $real_saved ) ) {
			$removed   = self::remove_file( $output_path );
			$uncertain = $saved_path !== $output_path || ! $removed;
			return self::error( 'zmai_image_save_failed', __( 'The saved image is outside its expected local directory.', 'author-or-user-image' ), $uncertain ? array( 'recovery' => true ) : null );
		}

		clearstatcache( true, $real_saved );
		$actual_size    = filesize( $real_saved );
		$saved_info     = @getimagesize( $real_saved ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Partial editor writes are verified and removed below.
		$actual_mime    = is_array( $saved_info ) && isset( $saved_info['mime'] ) ? $saved_info['mime'] : '';
		$actual_width   = is_array( $saved_info ) && isset( $saved_info[0] ) ? (int) $saved_info[0] : 0;
		$actual_height  = is_array( $saved_info ) && isset( $saved_info[1] ) ? (int) $saved_info[1] : 0;
		$metadata_ok    = isset( $saved['width'], $saved['height'], $saved['mime-type'], $saved['filesize'] )
			&& (int) $saved['width'] === $actual_width
			&& (int) $saved['height'] === $actual_height
			&& $saved['mime-type'] === $actual_mime
			&& (int) $saved['filesize'] === $actual_size;
		$orientation_ok = true;
		if ( 'image/jpeg' === $actual_mime && function_exists( 'exif_read_data' ) ) {
			$exif           = @exif_read_data( $real_saved ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some valid JPEG encoders omit EXIF metadata.
			$orientation_ok = empty( $exif['Orientation'] ) || 1 === (int) $exif['Orientation'];
		}

		if ( $actual_size < 1 || ! $metadata_ok || ! $orientation_ok || $actual_mime !== $validated['type'] || $actual_width !== $output_side || $actual_height !== $output_side ) {
			$removed = self::remove_file( $output_path );
			return self::error( 'zmai_image_save_failed', __( 'The saved square image failed its format, dimension, or metadata check.', 'author-or-user-image' ), $removed ? null : array( 'recovery' => true ) );
		}

		return array(
			'path'      => $real_saved,
			'file'      => basename( $real_saved ),
			'width'     => $actual_width,
			'height'    => $actual_height,
			'mime-type' => $actual_mime,
			'filesize'  => (int) $actual_size,
		);
	}

	/**
	 * Find the permitted MIME type for an extension in the current site's list.
	 *
	 * @param string $extension Lowercase filename extension.
	 * @return string|false MIME type, or false when the site disallows it.
	 */
	private static function mime_for_extension( $extension ) {
		$allowed_mimes = get_allowed_mime_types();
		foreach ( $allowed_mimes as $extensions => $mime ) {
			if ( in_array( $extension, explode( '|', strtolower( $extensions ) ), true ) ) {
				return $mime;
			}
		}
		return false;
	}

	/**
	 * Get the canonical extension used for a supported MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return string|false Canonical extension, or false when unsupported.
	 */
	private static function extension_for_mime( $mime ) {
		if ( ! isset( self::SUPPORTED_MIMES[ $mime ] ) ) {
			return false;
		}
		return self::SUPPORTED_MIMES[ $mime ][0];
	}

	/**
	 * Validate a PNG's chunk table and detect APNG control chunks.
	 *
	 * @param string $bytes Bounded file contents.
	 * @return bool|string True for a complete still PNG, 'animated' for APNG, false otherwise.
	 */
	private static function inspect_png( $bytes ) {
		$length = strlen( $bytes );
		if ( $length < 20 || "\x89PNG\r\n\x1a\n" !== substr( $bytes, 0, 8 ) ) {
			return false;
		}

		$offset      = 8;
		$chunk_count = 0;
		$seen_ihdr   = false;
		$seen_idat   = false;
		$seen_iend   = false;
		$animated    = false;
		while ( $offset < $length ) {
			if ( ++$chunk_count > 65536 || $offset + 12 > $length ) {
				return false;
			}
			$unpacked   = unpack( 'Nsize', substr( $bytes, $offset, 4 ) );
			$chunk_size = $unpacked['size'];
			$chunk_type = substr( $bytes, $offset + 4, 4 );
			$chunk_end  = $offset + 12 + $chunk_size;
			if ( ! preg_match( '/^[A-Za-z]{4}$/D', $chunk_type ) || $chunk_end > $length || $seen_iend ) {
				return false;
			}
			if ( ! hash_equals( hash( 'crc32b', substr( $bytes, $offset + 4, 4 + $chunk_size ), true ), substr( $bytes, $offset + 8 + $chunk_size, 4 ) ) ) {
				return false;
			}
			if ( 'fcTL' === $chunk_type || 'fdAT' === $chunk_type ) {
				$animated = true;
			}
			if ( ! $seen_ihdr ) {
				if ( 'IHDR' !== $chunk_type || 13 !== $chunk_size ) {
					return false;
				}
				$seen_ihdr = true;
			} elseif ( 'IHDR' === $chunk_type ) {
				return false;
			}
			if ( 'acTL' === $chunk_type ) {
				if ( 8 !== $chunk_size ) {
					return false;
				}
				$animated = true;
			}
			if ( 'IDAT' === $chunk_type ) {
				$seen_idat = true;
			}
			if ( 'IEND' === $chunk_type ) {
				if ( 0 !== $chunk_size || ! $seen_idat || $chunk_end !== $length ) {
					return false;
				}
				$seen_iend = true;
			}
			$offset = $chunk_end;
		}

		if ( ! $seen_ihdr || ! $seen_idat || ! $seen_iend ) {
			return false;
		}
		return $animated ? 'animated' : true;
	}

	/**
	 * Validate a WebP RIFF chunk table and reject animated forms.
	 *
	 * @param string $bytes Bounded file contents.
	 * @return bool|string True for a complete still WebP, 'animated' for animation, false otherwise.
	 */
	private static function inspect_webp( $bytes ) {
		$length = strlen( $bytes );
		if ( $length < 20 || 'RIFF' !== substr( $bytes, 0, 4 ) || 'WEBP' !== substr( $bytes, 8, 4 ) ) {
			return false;
		}
		$riff_size = unpack( 'Vsize', substr( $bytes, 4, 4 ) );
		if ( $riff_size['size'] + 8 !== $length ) {
			return false;
		}

		$offset       = 12;
		$chunk_count  = 0;
		$image_chunks = 0;
		$has_vp8x     = false;
		$animated     = false;
		while ( $offset < $length ) {
			if ( ++$chunk_count > 65536 || $offset + 8 > $length ) {
				return false;
			}
			$chunk_type = substr( $bytes, $offset, 4 );
			$unpacked   = unpack( 'Vsize', substr( $bytes, $offset + 4, 4 ) );
			$chunk_size = $unpacked['size'];
			$chunk_end  = $offset + 8 + $chunk_size + ( $chunk_size % 2 );
			if ( ! preg_match( '/^[A-Z0-9 ]{4}$/D', $chunk_type ) || $chunk_end > $length ) {
				return false;
			}

			if ( $chunk_size % 2 && "\0" !== $bytes[ $chunk_end - 1 ] ) {
				return false;
			}
			if ( 'VP8X' === $chunk_type ) {
				if ( $has_vp8x || 12 !== $offset || 10 !== $chunk_size ) {
					return false;
				}
				if ( "\0\0\0" !== substr( $bytes, $offset + 9, 3 ) ) {
					return false;
				}
				$has_vp8x = true;
				$flags    = ord( $bytes[ $offset + 8 ] );
				if ( 0 !== ( $flags & 0xc1 ) ) {
					return false;
				}
				if ( 0 !== ( $flags & 0x02 ) ) {
					$animated = true;
				}
			}

			if ( 'ANIM' === $chunk_type || 'ANMF' === $chunk_type ) {
				$animated = true;
			}
			if ( 'VP8 ' === $chunk_type || 'VP8L' === $chunk_type ) {
				++$image_chunks;
			}

			$offset = $chunk_end;
		}

		if ( $animated ) {
			return 'animated';
		}
		if ( 1 !== $image_chunks ) {
			return false;
		}
		return $animated ? 'animated' : true;
	}

	/**
	 * Confirm that a path is a regular file rather than a directory or symlink.
	 *
	 * @param string $path Canonical local path.
	 * @return bool Whether the path is a regular file.
	 */
	private static function is_regular_file( $path ) {
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Partial output is treated as a failed save.
		return is_array( $stat ) && isset( $stat['mode'] ) && 0100000 === ( $stat['mode'] & 0170000 );
	}

	/**
	 * Remove only a processor-generated output path after a failed verification.
	 *
	 * @param string $path Generated output path.
	 * @return bool Whether the exact generated output is absent.
	 */
	private static function remove_file( $path ) {
		if ( ! is_string( $path ) || ( ! file_exists( $path ) && ! is_link( $path ) ) ) {
			return true;
		}
		if ( ! is_file( $path ) && ! is_link( $path ) ) {
			return false;
		}
		wp_delete_file( $path );
		clearstatcache( true, $path );
		return ! file_exists( $path ) && ! is_link( $path );
	}

	/**
	 * Build a safe processing error.
	 *
	 * @param string $code Error code.
	 * @param string $message User-safe error message.
	 * @param mixed  $data Optional recovery signal.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $data = null ) {
		return new WP_Error( $code, $message, $data );
	}
}
