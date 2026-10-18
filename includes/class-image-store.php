<?php
/**
 * Avatar media, mappings and legacy fallback lookup.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores avatars as Media Library attachments and reads legacy JPG files.
 */
final class Image_Store {
	const USER_META               = '_zmai_avatar_attachment_id';
	const MIGRATED_USER_META      = '_zmai_migrated_avatar_id';
	const BLOCKED_META            = '_zmai_avatar_blocked';
	const DEFAULT_OPTION          = 'zmai_default_avatar_id';
	const MIGRATED_DEFAULT_OPTION = 'zmai_migrated_default_avatar_id';
	const LEGACY_SIZE_OPTION      = 'author_image_size';
	const LEGACY_BLOCK_OPTION     = 'zm_ai_ban_id';
	const VERSION_OPTION          = 'zmai_data_version';
	const MIGRATION_OPTION        = 'zmai_migration_offset';
	const MIGRATION_RETRY_OPTION  = 'zmai_migration_retry';
	const DELETE_DATA_OPTION      = 'zmai_delete_data_on_uninstall';
	const ATTACHMENT_OWNER_META   = '_zmai_avatar_owner';
	const UPLOAD_RATE_META        = '_zmai_avatar_upload_rate';
	const SQUARE_VERSION_META     = '_zmai_avatar_square_version';

	/**
	 * Site-scoped lifecycle mutex.
	 *
	 * @var Lock
	 */
	private $lock;

	/**
	 * Metadata-keyed URL decisions shared by store instances for this request.
	 *
	 * @var array
	 */
	private static $attachment_urls = array();

	/**
	 * Lifecycle mutex shared with cleanup and migration.
	 *
	 * @param Lock|null $lock Lifecycle mutex shared with cleanup and migration.
	 */
	public function __construct( ?Lock $lock = null ) {
		$this->lock = null !== $lock ? $lock : new Lock();
	}

	/**
	 * Shared lifecycle mutex.
	 *
	 * @internal
	 * @return Lock
	 */
	public function get_lock() {
		return $this->lock;
	}

	/**
	 * Scope user metadata to the site whose Media Library owns the attachment.
	 *
	 * @param string $key Base metadata key.
	 * @return string
	 */
	public static function user_meta_key( $key ) {
		return is_multisite() ? $key . '_' . get_current_blog_id() : $key;
	}

	/**
	 * Return the configured square avatar size.
	 *
	 * @return int
	 */
	public function get_size() {
		$legacy = get_option( self::LEGACY_SIZE_OPTION, array() );
		$width  = is_array( $legacy ) && isset( $legacy['width'] ) ? absint( $legacy['width'] ) : 150;
		$height = is_array( $legacy ) && isset( $legacy['h'] ) ? absint( $legacy['h'] ) : 150;

		return min( 1024, max( 32, max( $width, $height ) ) );
	}

	/**
	 * Update the generated avatar size while preserving the old option shape.
	 *
	 * @param int $size Image width and height.
	 * @return void
	 */
	public function update_size( $size ) {
		$size = min( 1024, max( 32, absint( $size ) ) );
		update_option(
			self::LEGACY_SIZE_OPTION,
			array(
				'h'     => $size,
				'width' => $size,
			),
			false
		);
	}

	/**
	 * Register the custom image size before attachments are generated.
	 *
	 * @return void
	 */
	public function register_image_size() {
		$size = $this->get_size();
		add_image_size( 'zmai-avatar', $size, $size, true );
	}

	/**
	 * Store an uploaded image for a user or as the site default.
	 *
	 * @param string $field   File input name.
	 * @param int    $user_id User ID, or zero for the default avatar.
	 * @return int|WP_Error Attachment ID, or busy/storage/cleanup error; errors never mean success.
	 */
	public function save_upload( $field, $user_id = 0 ) {
		if ( ! is_string( $field ) || ! is_scalar( $user_id ) ) {
			return $this->storage_error();
		}
		$field   = sanitize_key( $field );
		$user_id = absint( $user_id );
		return $this->with_owner_lock(
			$user_id,
			function () use ( $field, $user_id ) {
				$policy = new Upload_Policy( $this );
				if ( $user_id ? ! $policy->can_upload_fresh( $user_id ) : ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'zmai_blocked', __( 'Local avatar uploads are disabled for this account.', 'author-or-user-image' ) );
				}
				if ( $user_id ) {
					$rate = $this->consume_upload_attempt( $user_id );
					if ( is_wp_error( $rate ) ) {
						return $rate;
					}
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authenticated handler verifies nonce; all file fields are strictly validated here and core checks HTTP provenance.
				$file = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : array();
				foreach ( array( 'tmp_name', 'name', 'size', 'error' ) as $key ) {
					if ( ! isset( $file[ $key ] ) || ! is_scalar( $file[ $key ] ) ) {
						return new WP_Error( 'zmai_upload_failed', __( 'The image upload did not complete.', 'author-or-user-image' ) );
					}
				}
				if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) ) {
					return new WP_Error( 'zmai_upload_failed', __( 'The image upload did not complete.', 'author-or-user-image' ) );
				}
				$processor = new Image_Processor();
				$validated = $processor->validate_local_image( $file['tmp_name'], $file['name'] );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				if ( 'image/jpeg' === $validated['type'] && ! function_exists( 'exif_read_data' ) ) {
					return new WP_Error( 'zmai_jpeg_exif_unavailable', __( 'JPEG processing requires server EXIF support.', 'author-or-user-image' ) );
				}
				$this->load_media_api();
				$this->register_image_size();
				$this->lock->mark_mutation();
				$attachment_id = $this->stage_media(
					function () use ( $field, $user_id ) {
						return media_handle_upload(
							$field,
							0,
							array(
								/* translators: %d: numeric WordPress user ID. */
								'post_title' => $user_id ? sprintf( __( 'Avatar for user %d', 'author-or-user-image' ), $user_id ) : __( 'Default user avatar', 'author-or-user-image' ),
								'meta_input' => array( self::ATTACHMENT_OWNER_META => $this->owner_name( $user_id ) ),
							),
							array(
								'test_form'                => false,
								'unique_filename_callback' => static function ( $directory, $name, $extension ) {
									return wp_unique_filename( $directory, 'zmai-upload-' . wp_generate_uuid4() . $extension );
								},
							)
						);
					}
				);
				if ( is_wp_error( $attachment_id ) ) {
					return $attachment_id;
				}
				$ready = $this->prepare_attachment( $attachment_id, $user_id, $validated['type'] );
				if ( ! is_wp_error( $ready ) && $user_id && ! $policy->can_upload_fresh( $user_id ) ) {
					$ready = new WP_Error( 'zmai_blocked', __( 'Local avatar uploads are disabled for this account.', 'author-or-user-image' ) );
				}
				if ( ! is_wp_error( $ready ) ) {
					$ready = $this->replace_attachment( $attachment_id, $user_id );
				}
				return is_wp_error( $ready ) ? $this->rollback_unmapped( $attachment_id, $user_id, self::USER_META, self::DEFAULT_OPTION, $ready ) : $attachment_id;
			}
		);
	}

	/**
	 * Consume one authorized attempt under this owner's mutex, before decoding.
	 *
	 * @param int $user_id Current upload owner, never the default owner.
	 * @return true|WP_Error
	 */
	private function consume_upload_attempt( $user_id ) {
		$now    = time();
		$record = $this->read_value( $user_id, self::UPLOAD_RATE_META, '' );
		if ( null === $record ) {
			$record = array(
				'minute_start' => $now,
				'minute_count' => 0,
				'hour_start'   => $now,
				'hour_count'   => 0,
			);
		}
		if ( ! is_array( $record ) || count( $record ) !== 4 ) {
			return $this->storage_error();
		}
		foreach ( array( 'minute_start', 'minute_count', 'hour_start', 'hour_count' ) as $key ) {
			if ( ! isset( $record[ $key ] ) || ! is_int( $record[ $key ] ) || $record[ $key ] < 0 ) {
				return $this->storage_error();
			}
		}
		foreach ( array(
			'minute' => 60,
			'hour'   => 3600,
		) as $window => $seconds ) {
			if ( $now >= $record[ $window . '_start' ] + $seconds ) {
				$record[ $window . '_start' ] = $now;
				$record[ $window . '_count' ] = 0;
			}
		}
		if ( $record['minute_count'] >= 5 || $record['hour_count'] >= 20 ) {
			return new WP_Error( 'zmai_rate_limited', __( 'Too many avatar uploads. Please wait before trying again.', 'author-or-user-image' ) );
		}
		++$record['minute_count'];
		++$record['hour_count'];
		return $this->write_value( $user_id, self::UPLOAD_RATE_META, '', $record );
	}

	/**
	 * Load core media operations for both genuine upload and local-copy paths.
	 *
	 * @return void
	 */
	private function load_media_api() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	/**
	 * Journal this core media call before it can fail to return its attachment ID.
	 * Pin its validated format and reconcile failed staging before releasing locks.
	 *
	 * @param callable $callback Core upload or sideload operation.
	 * @return mixed
	 * @throws \Throwable When database state or the staged file cannot be reconciled.
	 */
	private function stage_media( $callback ) {
		global $wpdb;
		$stage         = array(
			'file' => '',
			'url'  => '',
			'id'   => 0,
		);
		$pin_format    = static function () {
			return array();
		};
		$remember_file = static function ( $upload ) use ( &$stage ) {
			if ( is_array( $upload ) && ! empty( $upload['file'] ) && ! empty( $upload['url'] ) && preg_match( '/^zmai-(upload|import)-[a-f0-9-]+\./D', basename( $upload['file'] ) ) ) {
				$stage['file'] = $upload['file'];
				$stage['url']  = $upload['url'];
			}
			return $upload;
		};
		$remember_id   = static function ( $id ) use ( &$stage ) {
			$post = get_post( $id );
			if ( $stage['file'] && $post && ( $post->guid === $stage['url'] || get_attached_file( $id, true ) === $stage['file'] ) ) {
				$stage['id'] = (int) $id;
			}
		};
		add_filter( 'image_editor_output_format', $pin_format, PHP_INT_MAX );
		add_filter( 'wp_handle_upload', $remember_file, -99999 );
		add_action( 'add_attachment', $remember_id, -99999 );
		try {
			$result = $callback();
		} catch ( \Throwable $error ) {
			if ( $wpdb->last_error || ! $stage['file'] ) {
				throw $error;
			}
			$result = $this->storage_error();
		} finally {
			remove_filter( 'image_editor_output_format', $pin_format, PHP_INT_MAX );
			remove_filter( 'wp_handle_upload', $remember_file, -99999 );
			remove_action( 'add_attachment', $remember_id, -99999 );
		}
		if ( ! is_wp_error( $result ) || ! $stage['file'] ) {
			return $result;
		}
		// The generated UUID and core GUID identify this call even if an insert hook
		// threw before add_attachment. A failed authoritative read retains the fence.
		if ( ! $stage['id'] ) {
			$ids = $this->database_values( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'attachment' AND guid = %s", $wpdb->posts, $stage['url'] ) );
			if ( count( $ids ) > 1 ) {
				return $this->storage_error( true );
			}
			$stage['id'] = $ids ? (int) $ids[0] : 0;
		}
		// Core may write subsizes before a metadata callback throws. The UUID stem
		// belongs only to this staging call, so its file inventory is still known.
		$stem  = pathinfo( $stage['file'], PATHINFO_FILENAME );
		$files = @scandir( dirname( $stage['file'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed listing must retain the attachment inventory and owner fence.
		if ( false === $files ) {
			return $this->storage_error( true );
		}
		if ( $stage['id'] ) {
			$cleanup = $this->delete_attachment_checked( $stage['id'] );
			if ( is_wp_error( $cleanup ) && $this->database_values( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d', $wpdb->posts, $stage['id'] ) ) ) {
				return $cleanup;
			}
		}
		foreach ( $files as $filename ) {
			if ( ! preg_match( '/^' . preg_quote( $stem, '/' ) . '(?:-[0-9]+x[0-9]+|-scaled|-rotated)*\.[a-z0-9]+$/iD', $filename ) ) {
				continue;
			}
			$file = dirname( $stage['file'] ) . '/' . $filename;
			wp_delete_file( $file );
			clearstatcache( true, $file );
			if ( is_file( $file ) || is_link( $file ) ) {
				// No attachment row remains to retain this file inventory for a retry.
				return $this->storage_error( true );
			}
		}
		return $result;
	}

	/**
	 * Copy a fresh current-site source under the owner mutex, preserving explicit intent.
	 * Authorization of the administrator-initiated operation belongs to its caller.
	 *
	 * @param callable $resolve_source Returns array{path:string,name:string}|WP_Error inside the owner mutex.
	 * @param int      $user_id Current-site subject, never the default owner.
	 * @return array{status:string,attachment_id:int}|WP_Error imported/already_present/explicit_choice/blocked/gone.
	 */
	public function import_local_image( $resolve_source, $user_id ) {
		if ( ! is_callable( $resolve_source ) || ! is_numeric( $user_id ) || (int) $user_id < 1 ) {
			return $this->storage_error();
		}
		$user_id = (int) $user_id;
		return $this->with_owner_lock(
			$user_id,
			function () use ( $resolve_source, $user_id ) {
				$state = $this->import_state( $user_id );
				if ( null !== $state ) {
					return $state;
				}
				$source = call_user_func( $resolve_source );
				if ( is_wp_error( $source ) ) {
					return $source;
				}
				if ( ! is_array( $source ) || ! isset( $source['path'], $source['name'] ) || ! is_string( $source['path'] ) || ! is_string( $source['name'] ) || ! $this->is_uploads_file( $source['path'] ) ) {
					return new WP_Error( 'zmai_invalid_source', __( 'A readable local uploads image is required.', 'author-or-user-image' ) );
				}
				$processor = new Image_Processor();
				$validated = $processor->validate_local_image( $source['path'], $source['name'] );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				if ( 'image/jpeg' === $validated['type'] && ! function_exists( 'exif_read_data' ) ) {
					return new WP_Error( 'zmai_jpeg_exif_unavailable', __( 'JPEG processing requires server EXIF support.', 'author-or-user-image' ) );
				}
				$this->load_media_api();
				$source['path'] = $validated['path'];
				$before         = stat( $source['path'] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Copy only validated local bytes with a fixed maximum, even if the source grows.
				$bytes = file_get_contents( $source['path'], false, null, 0, $validated['filesize'] + 1 );
				if ( ! is_string( $bytes ) || strlen( $bytes ) !== $validated['filesize'] ) {
					return new WP_Error( 'zmai_source_changed', __( 'The source image changed. Please retry.', 'author-or-user-image' ) );
				}
				$temporary = wp_tempnam( 'zmai-import' );
				if ( ! $temporary ) {
					return $this->storage_error();
				}
				$this->lock->mark_mutation();
				$copy = null;
				try {
					$stage_copy = function () use ( $temporary, $bytes, $source, $before, $processor, $validated, $user_id, &$copy ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A bounded local byte copy is required for core sideload; no remote filesystem transport.
						if ( file_put_contents( $temporary, $bytes ) !== strlen( $bytes ) ) {
							return $this->storage_error();
						}
						clearstatcache( true, $source['path'] );
						$after = stat( $source['path'] );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Recheck only the bounded local source bytes copied above.
						$recheck = file_get_contents( $source['path'], false, null, 0, $validated['filesize'] + 1 );
						if ( ! $before || ! $after || $after['ino'] !== $before['ino'] || $after['dev'] !== $before['dev'] || $after['size'] !== $before['size'] || ! $this->is_uploads_file( $source['path'] ) || ! is_string( $recheck ) || ! hash_equals( hash( 'sha256', $bytes ), hash( 'sha256', $recheck ) ) ) {
							return new WP_Error( 'zmai_source_changed', __( 'The source image changed. Please retry.', 'author-or-user-image' ) );
						}
						$copy = $processor->validate_local_image( $temporary, $source['name'] );
						if ( is_wp_error( $copy ) ) {
							return $copy;
						}
						$this->register_image_size();
						return $this->stage_media(
							function () use ( $copy, $temporary, $user_id ) {
								return media_handle_sideload(
									array(
										'name'     => 'zmai-import-' . wp_generate_uuid4() . '.' . pathinfo( $copy['name'], PATHINFO_EXTENSION ),
										'tmp_name' => $temporary,
									),
									0,
									null,
									array( 'meta_input' => array( self::ATTACHMENT_OWNER_META => $this->owner_name( $user_id ) ) )
								);
							}
						);
					};
					$id         = $stage_copy();
				} finally {
					clearstatcache( true, $temporary );
					if ( is_file( $temporary ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- wp_tempnam created this private local copy; media deletion filters must not retain it.
						@unlink( $temporary );
						clearstatcache( true, $temporary );
					}
				}
				if ( is_file( $temporary ) ) {
					$error = $this->storage_error();
					return is_int( $id ) && $id > 0 ? $this->rollback_unmapped( $id, $user_id, self::MIGRATED_USER_META, '', $error ) : $error;
				}
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$ready = $this->prepare_attachment( $id, $user_id, $copy['type'] );
				if ( ! is_wp_error( $ready ) ) {
					$state = $this->import_state( $user_id, false );
					if ( null !== $state ) {
						$cleanup = $this->delete_attachment_checked( $id );
						return is_wp_error( $cleanup ) ? $cleanup : $state;
					}
					$ready = $this->write_value( $user_id, self::MIGRATED_USER_META, '', $id );
					if ( ! is_wp_error( $ready ) ) {
						$ready = $this->cleanup_owned( $user_id, $id );
					}
				}
				return is_wp_error( $ready ) ? $this->rollback_unmapped( $id, $user_id, self::MIGRATED_USER_META, '', $ready ) : array(
					'status'        => 'imported',
					'attachment_id' => $id,
				);
			}
		);
	}

	/**
	 * Fresh import suppression. A valid migrated fallback retries old-media cleanup.
	 *
	 * @param int  $user_id Import subject.
	 * @param bool $cleanup Whether to retry cleanup of an existing fallback.
	 * @return array|WP_Error|null Null permits staging.
	 */
	private function import_state( $user_id, $cleanup = true ) {
		global $wpdb;
		if ( ! $this->user_exists_fresh( $user_id ) ) {
			return array(
				'status'        => 'gone',
				'attachment_id' => 0,
			);
		}
		clean_user_cache( $user_id );
		wp_cache_delete( $user_id, 'user_meta' );
		$membership = $this->database_values( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $user_id, $wpdb->get_blog_prefix() . 'capabilities' ) );
		if ( is_multisite() && ( ! $membership || ! is_array( maybe_unserialize( $membership[0] ) ) || ! maybe_unserialize( $membership[0] ) ) ) {
			return array(
				'status'        => 'gone',
				'attachment_id' => 0,
			);
		}
		if ( $this->is_blocked_fresh( $user_id ) ) {
			return array(
				'status'        => 'blocked',
				'attachment_id' => 0,
			);
		}
		$explicit = $this->read_value( $user_id, self::USER_META, '' );
		if ( null !== $explicit ) {
			return array(
				'status'        => 'explicit_choice',
				'attachment_id' => 0,
			);
		}
		$id   = $this->validate_attachment_id( $this->read_value( $user_id, self::MIGRATED_USER_META, '' ) );
		$file = $id ? get_attached_file( $id, true ) : '';
		$meta = $id ? wp_get_attachment_metadata( $id, true ) : array();
		if ( $id && $this->owns_attachment( $id, $user_id ) && $this->is_uploads_file( $file ) && ( ! metadata_exists( 'post', $id, self::SQUARE_VERSION_META ) || $this->valid_square( $file, $meta['sizes']['zmai-avatar'] ?? null ) ) && $this->attachment_url( $id, $this->get_size() ) ) {
			$result = $cleanup ? $this->cleanup_owned( $user_id, $id ) : true;
			return is_wp_error( $result ) ? $result : array(
				'status'        => 'already_present',
				'attachment_id' => $id,
			);
		}
		return null;
	}

	/**
	 * Require a real regular file inside this site's real uploads root.
	 *
	 * @param string $path Local path.
	 * @return bool
	 */
	private function is_uploads_file( $path ) {
		if ( ! is_string( $path ) || false !== strpos( $path, '://' ) || false !== strpos( $path, "\0" ) ) {
			return false;
		}
		$uploads = wp_upload_dir();
		$root    = realpath( $uploads['basedir'] );
		$real    = realpath( $path );
		if ( $uploads['error'] || ! $root || ! $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) || ! is_readable( $real ) ) {
			return false;
		}
		$relative = substr( $real, strlen( $root ) + 1 );
		return ! ( is_multisite() && is_main_site() && 0 === strpos( $relative, 'sites' . DIRECTORY_SEPARATOR ) );
	}

	/**
	 * Remove staged media only when a failed mapping is authoritatively uncommitted.
	 *
	 * @param int      $id Staged attachment.
	 * @param int      $user_id Owner.
	 * @param string   $meta Mapping key.
	 * @param string   $option Default mapping option.
	 * @param WP_Error $error Original error.
	 * @return WP_Error
	 */
	private function rollback_unmapped( $id, $user_id, $meta, $option, $error ) {
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['recovery'] ) ) {
			return $error;
		}
		if ( (string) $id !== (string) $this->read_value( $user_id, $meta, $option ) ) {
			$cleanup = $this->delete_attachment_checked( $id );
			if ( is_wp_error( $cleanup ) ) {
				return $cleanup;
			}
		}
		return $error;
	}

	/**
	 * Confirm square file inventory and metadata before publishing either mapping.
	 *
	 * @param int    $id Staged attachment ID.
	 * @param int    $user_id Owner, zero for default.
	 * @param string $mime Validated input MIME; format conversion is not permitted.
	 * @return true|WP_Error
	 * @throws \Throwable When a metadata operation reports a database failure.
	 */
	private function prepare_attachment( $id, $user_id, $mime ) {
		global $wpdb;
		if ( ! wp_attachment_is_image( $id ) || ! $this->owns_attachment( $id, $user_id ) ) {
			return $this->storage_error();
		}
		$file = get_attached_file( $id, true );
		if ( ! $this->is_uploads_file( $file ) ) {
			return $this->storage_error();
		}
		$processor = new Image_Processor();
		$original  = $processor->validate_local_image( $file, basename( $file ) );
		if ( is_wp_error( $original ) ) {
			return $original;
		}
		if ( $original['type'] !== $mime ) {
			return new WP_Error( 'zmai_image_format', __( 'The image editor changed the image format.', 'author-or-user-image' ) );
		}
		$meta = wp_get_attachment_metadata( $id, true );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return $this->storage_error();
		}
		$target = min( $this->get_size(), $original['width'], $original['height'] );
		$square = isset( $meta['sizes']['zmai-avatar'] ) ? $meta['sizes']['zmai-avatar'] : null;
		if ( ! $this->valid_square( $file, $square, $target, $original['type'] ) ) {
			$this->lock->mark_mutation();
			$saved = $processor->create_square( $file, $this->get_size() );
			if ( is_wp_error( $saved ) ) {
				$data = $saved->get_error_data();
				if ( is_array( $data ) && ! empty( $data['cleanup_files'] ) ) {
					foreach ( $data['cleanup_files'] as $index => $filename ) {
						$meta['sizes'][ 'zmai-avatar-failed-' . $index ] = array( 'file' => $filename );
					}
					wp_update_attachment_metadata( $id, $meta );
					if ( ! $this->confirm_post_meta( $id, '_wp_attachment_metadata', $meta ) ) {
						return $this->storage_error( true );
					}
				}
				return $saved;
			}
			if ( is_array( $square ) ) {
				$meta['sizes']['zmai-avatar-previous'] = $square;
			}
			$meta['sizes']['zmai-avatar'] = array(
				'file'      => $saved['file'],
				'width'     => $saved['width'],
				'height'    => $saved['height'],
				'mime-type' => $saved['mime-type'],
				'filesize'  => $saved['filesize'],
			);
			$this->lock->mark_mutation();
			$failed = false;
			try {
				wp_update_attachment_metadata( $id, $meta );
			} catch ( \Throwable $error ) {
				if ( $wpdb->last_error ) {
					throw $error;
				}
				$failed = true;
			}
			$confirmed = $this->confirm_post_meta( $id, '_wp_attachment_metadata', $meta );
			if ( $failed || ! $confirmed ) {
				wp_delete_file( $saved['path'] );
				clearstatcache( true, $saved['path'] );
				return $this->storage_error( is_file( $saved['path'] ) && ! $confirmed );
			}
		}
		if ( ! $this->confirm_post_meta( $id, '_wp_attachment_metadata', $meta ) || ! $this->valid_square( $file, $meta['sizes']['zmai-avatar'], $target, $original['type'] ) ) {
			return $this->storage_error();
		}
		$this->lock->mark_mutation();
		try {
			update_post_meta( $id, self::SQUARE_VERSION_META, 1 );
		} catch ( \Throwable $error ) {
			if ( $wpdb->last_error ) {
				throw $error;
			}
			$this->confirm_post_meta( $id, self::SQUARE_VERSION_META, 1 );
			return $this->storage_error();
		}
		return $this->confirm_post_meta( $id, self::SQUARE_VERSION_META, 1 ) ? true : $this->storage_error();
	}

	/**
	 * Authoritative equality for every attachment metadata row.
	 *
	 * @param int    $id Attachment.
	 * @param string $key Metadata key.
	 * @param mixed  $expected Required value.
	 * @return bool
	 */
	private function confirm_post_meta( $id, $key, $expected ) {
		global $wpdb;
		wp_cache_delete( $id, 'post_meta' );
		$rows = $this->database_values( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $id, $key ) );
		return $rows && array( (string) maybe_serialize( $expected ) ) === array_values( array_unique( $rows ) );
	}

	/**
	 * Verify named derivative dimensions, MIME and same-directory containment.
	 *
	 * @param string     $original Effective attachment file.
	 * @param array|null $square Named subsize metadata.
	 * @param int        $target Expected side, zero accepts stored side.
	 * @param string     $mime Expected MIME.
	 * @return bool
	 */
	private function valid_square( $original, $square, $target = 0, $mime = '' ) {
		if ( ! is_array( $square ) || empty( $square['file'] ) || ! is_string( $square['file'] ) || basename( $square['file'] ) !== $square['file'] || ! isset( $square['width'], $square['height'], $square['filesize'] ) || ! is_int( $square['width'] ) || ! is_int( $square['height'] ) || $square['width'] < 1 || $square['width'] !== $square['height'] ) {
			return false;
		}
		$path = dirname( $original ) . '/' . $square['file'];
		$dir  = realpath( dirname( $original ) );
		$real = realpath( $path );
		if ( ! $real || ! $dir || dirname( $real ) !== $dir || ! $this->is_uploads_file( $real ) ) {
			return false;
		}
		clearstatcache( true, $real );
		$info = wp_getimagesize( $real );
		return filesize( $real ) === (int) $square['filesize'] && $info && $info[0] === $info[1] && $info[0] === (int) $square['width'] && ( ! $target || $target === $info[0] ) && isset( $info['mime'], $square['mime-type'] ) && $info['mime'] === $square['mime-type'] && ( ! $mime || $mime === $info['mime'] );
	}

	/**
	 * Marked media must serve its checked square at every requested display size.
	 *
	 * @param int $id Attachment ID.
	 * @param int $size Requested display size for historical media only.
	 * @return string
	 */
	private function attachment_url( $id, $size ) {
		$meta   = wp_get_attachment_metadata( $id );
		$marker = get_post_meta( $id, self::SQUARE_VERSION_META, true );
		$key    = get_current_blog_id() . ':' . $id . ':' . $size . ':' . md5( maybe_serialize( array( $marker, $meta ) ) );
		if ( array_key_exists( $key, self::$attachment_urls ) ) {
			return self::$attachment_urls[ $key ];
		}
		$square                        = $meta['sizes']['zmai-avatar'] ?? null;
		$width                         = $square['width'] ?? 0;
		$height                        = $square['height'] ?? 0;
		$named                         = '1' === $marker && is_array( $square ) && ! empty( $square['file'] ) && is_string( $square['file'] ) && basename( $square['file'] ) === $square['file'] && is_int( $width ) && $width > 0 && $width === $height;
		$url                           = wp_get_attachment_image_url( $id, $named ? 'zmai-avatar' : array( $size, $size ) );
		self::$attachment_urls[ $key ] = $url ? $url : '';
		return self::$attachment_urls[ $key ];
	}

	/**
	 * Commit a replacement only while the caller holds the owner lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return true|WP_Error
	 */
	private function replace_attachment( $attachment_id, $user_id ) {
		$result = $this->write_value( $user_id, self::USER_META, self::DEFAULT_OPTION, $attachment_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = $this->cleanup_owned( $user_id, $attachment_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->write_value( $user_id, self::MIGRATED_USER_META, self::MIGRATED_DEFAULT_OPTION, null );
	}

	/**
	 * Exact attachment owner marker.
	 *
	 * @internal
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return string
	 */
	public function owner_name( $user_id ) {
		return $user_id ? (string) $user_id : 'default';
	}

	/**
	 * Storage failure, optionally requiring operator recovery.
	 *
	 * @internal
	 * @param bool $recovery Whether operator recovery is required.
	 * @return WP_Error
	 */
	public function storage_error( $recovery = false ) {
		return Lock::storage_error( $recovery );
	}

	/**
	 * Invalidate convenience caches; decision reads still bypass them.
	 *
	 * @internal
	 * @param string $key Option or metadata key.
	 * @return void
	 */
	public function clear_option_cache( $key ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Authoritative SQL values; a read error must never look like absence.
	 *
	 * @internal
	 * @param string $sql SQL prepared by the caller, including table identifiers.
	 * @return array
	 */
	public function database_values( $sql ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- All callers prepare SQL including identifiers; mutex decisions and write confirmations must bypass caches.
		$values = $wpdb->get_col( $sql );
		if ( $wpdb->last_error ) {
			$this->lock->read_error();
		}
		return $values;
	}

	/**
	 * Uncached first-row value, or null for an absent explicit choice.
	 *
	 * @internal
	 * @param int    $user_id User ID, or zero for the default avatar.
	 * @param string $meta_key Base user metadata key.
	 * @param string $option_key Site option key for the default owner.
	 * @return mixed
	 */
	public function read_value( $user_id, $meta_key, $option_key ) {
		global $wpdb;
		$values = $user_id
			? $this->database_values( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id', $wpdb->usermeta, $user_id, self::user_meta_key( $meta_key ) ) )
			: $this->database_values( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option_key ) );
		return $values ? maybe_unserialize( $values[0] ) : null;
	}

	/**
	 * Confirm all stored rows rather than trusting update_*'s bool.
	 *
	 * @internal
	 * @param int    $user_id User ID, or zero for the default avatar.
	 * @param string $meta_key Base user metadata key.
	 * @param string $option_key Site option key for the default owner.
	 * @param mixed  $value Value to write, or null to delete.
	 * @return true|WP_Error
	 */
	public function write_value( $user_id, $meta_key, $option_key, $value ) {
		global $wpdb;
		self::$attachment_urls = array();
		$this->lock->mark_mutation();
		if ( $user_id ) {
			$key = self::user_meta_key( $meta_key );
			wp_cache_delete( $user_id, 'user_meta' );
			if ( null === $value ) {
				delete_user_meta( $user_id, $key );
			} else {
				update_user_meta( $user_id, $key, $value );
			}
				wp_cache_delete( $user_id, 'user_meta' );
				$values = $this->database_values( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $user_id, $key ) );
		} else {
			$this->clear_option_cache( $option_key );
			if ( null === $value ) {
				delete_option( $option_key );
			} else {
				update_option( $option_key, $value, false );
			}
				$this->clear_option_cache( $option_key );
				$values = $this->database_values( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option_key ) );
		}
		if ( null === $value ) {
			return empty( $values ) ? true : $this->storage_error();
		}
		if ( ! $values ) {
			return $this->storage_error();
		}
		foreach ( $values as $stored ) {
			if ( (string) maybe_serialize( $value ) !== (string) $stored ) {
				return $this->storage_error();
			}
		}
		return true;
	}

	/**
	 * Uncached ownership equality; ambiguous foreign markers are not ours.
	 *
	 * @internal
	 * @param int $id Attachment ID.
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return bool
	 */
	public function owns_attachment( $id, $user_id ) {
		global $wpdb;
		$owners = $this->database_values( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $id, self::ATTACHMENT_OWNER_META ) );
		return $owners && array( $this->owner_name( $user_id ) ) === array_values( array_unique( $owners ) );
	}

	/**
	 * Reclaim historical orphans as well as superseded mappings.
	 *
	 * @internal
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @param int $keep Attachment to preserve, or zero to remove all owned media.
	 * @return true|WP_Error
	 */
	public function cleanup_owned( $user_id, $keep = 0 ) {
		global $wpdb;
		$ids = $this->database_values( $wpdb->prepare( "SELECT DISTINCT p.ID FROM %i p INNER JOIN %i m ON p.ID = m.post_id WHERE p.post_type = 'attachment' AND m.meta_key = %s AND m.meta_value = %s", $wpdb->posts, $wpdb->postmeta, self::ATTACHMENT_OWNER_META, $this->owner_name( $user_id ) ) );
		foreach ( $ids as $id ) {
			if ( (int) $id === (int) $keep || ! $this->owns_attachment( $id, $user_id ) ) {
				continue;
			}
			$result = $this->delete_attachment_checked( (int) $id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Confirm core deleted the row and all recorded image files.
	 *
	 * @internal
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	public function delete_attachment_checked( $id ) {
		global $wpdb;
		clean_post_cache( $id );
		$file   = get_attached_file( $id, true );
		$meta   = wp_get_attachment_metadata( $id, true );
		$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		$files  = $file ? array( $file ) : array();
		if ( $file && is_array( $meta ) ) {
			foreach ( array( 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ) as $key ) {
				if ( ! empty( $meta[ $key ] ) ) {
					$files[] = path_join( dirname( $file ), $meta[ $key ] );
				}
			}
			foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = path_join( dirname( $file ), $size['file'] );
				}
			}
		}
		foreach ( $file && is_array( $backup ) ? $backup : array() as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = path_join( dirname( $file ), $size['file'] );
			}
		}
		$this->lock->mark_mutation();
		$deleted   = wp_delete_attachment( $id, true );
		$remaining = $this->database_values( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d', $wpdb->posts, $id ) );
		if ( $remaining ) {
			return $this->storage_error();
		}
		foreach ( array_unique( $files ) as $path ) {
			clearstatcache( true, $path );
			if ( is_file( $path ) ) {
				return $this->storage_error( true );
			}
		}
		return $deleted ? true : $this->storage_error();
	}

	/**
	 * Authoritative account existence after acquiring the owner lock.
	 *
	 * @internal
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return bool
	 */
	public function user_exists_fresh( $user_id ) {
		global $wpdb;
		return (bool) $this->database_values( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d', $wpdb->users, $user_id ) );
	}

	/**
	 * Authoritative block decision inside the owner critical section.
	 *
	 * @internal
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return bool
	 */
	public function is_blocked_fresh( $user_id ) {
		if ( $this->read_value( $user_id, self::BLOCKED_META, '' ) ) {
			return true;
		}
		$user    = get_userdata( $user_id );
		$blocked = $this->read_value( 0, '', self::LEGACY_BLOCK_OPTION );
		return $user && is_array( $blocked ) && in_array( $user->user_login, $blocked, true );
	}

	/**
	 * Get a valid user avatar attachment ID.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_user_attachment_id( $user_id ) {
		if ( $this->has_user_choice( $user_id ) ) {
			return $this->validate_attachment_id( get_user_meta( $user_id, self::user_meta_key( self::USER_META ), true ) );
		}
		return $this->get_migrated_attachment_id( $user_id );
	}

	/**
	 * Get a valid default avatar attachment ID.
	 *
	 * @return int
	 */
	public function get_default_attachment_id() {
		return $this->has_user_choice( 0 ) ? $this->validate_attachment_id( get_option( self::DEFAULT_OPTION, 0 ) ) : $this->get_migrated_attachment_id( 0 );
	}

	/**
	 * Whether an explicit upload or removal takes precedence over legacy data.
	 *
	 * @param int $user_id User ID, or zero for the site default.
	 * @return bool
	 */
	private function has_user_choice( $user_id ) {
		if ( $user_id ) {
			return metadata_exists( 'user', $user_id, self::user_meta_key( self::USER_META ) );
		}
		return null !== get_option( self::DEFAULT_OPTION, null );
	}

	/**
	 * Read the imported fallback independently of a user's explicit choice.
	 *
	 * @param int $user_id User ID, or zero for the site default.
	 * @return int
	 */
	private function get_migrated_attachment_id( $user_id ) {
		$id = $user_id ? get_user_meta( $user_id, self::user_meta_key( self::MIGRATED_USER_META ), true ) : get_option( self::MIGRATED_DEFAULT_OPTION, 0 );
		return $this->validate_attachment_id( $id );
	}

	/**
	 * Validate an attachment ID.
	 *
	 * @param mixed $attachment_id Possible attachment ID.
	 * @return int
	 */
	public function validate_attachment_id( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		return $attachment_id && wp_attachment_is_image( $attachment_id ) ? $attachment_id : 0;
	}

	/**
	 * Get an avatar URL, preferring managed media and falling back to legacy data.
	 *
	 * @param int  $user_id       User ID.
	 * @param int  $size          Requested size.
	 * @param bool $allow_default Whether the site default may be used.
	 * @return string
	 */
	public function get_url( $user_id, $size, $allow_default = true ) {
		$attachment_id = $this->get_user_attachment_id( $user_id );
		if ( $attachment_id ) {
			return $this->attachment_url( $attachment_id, $size );
		}

		$legacy = $this->get_legacy_user_url( $user_id );
		if ( $legacy ) {
			return $legacy;
		}

		if ( ! $allow_default ) {
			return '';
		}

		$attachment_id = $this->get_default_attachment_id();
		if ( $attachment_id ) {
			return $this->attachment_url( $attachment_id, $size );
		}

		return $this->get_legacy_default_url();
	}

	/**
	 * Remove a user avatar attachment and its legacy fallback.
	 *
	 * @param int $user_id User ID.
	 * @return bool|WP_Error Shared-original retention on success, or a retryable busy/storage/cleanup error.
	 */
	public function delete_user_avatar( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return $this->storage_error();
		}
		return $this->with_owner_lock(
			$user_id,
			function () use ( $user_id ) {
				return $this->delete_avatar_locked( $user_id );
			}
		);
	}

	/**
	 * Confirmed default removal, including historical default orphans.
	 *
	 * @return true|WP_Error
	 */
	public function delete_default_avatar() {
		$result = $this->with_owner_lock(
			0,
			function () {
				return $this->delete_avatar_locked( 0 );
			}
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Shared legacy retention on success. Caller owns the mutex.
	 *
	 * @internal
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return bool|WP_Error
	 */
	public function delete_avatar_locked( $user_id ) {
		$path   = $user_id ? $this->get_legacy_user_path( $user_id, true ) : $this->get_legacy_default_path( true );
		$result = $this->write_value( $user_id, self::USER_META, self::DEFAULT_OPTION, 0 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = $this->cleanup_owned( $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = $this->write_value( $user_id, self::MIGRATED_USER_META, self::MIGRATED_DEFAULT_OPTION, null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $path && is_file( $path ) ) {
			if ( is_multisite() ) {
				return true;
			}
			wp_delete_file( $path );
			clearstatcache( true, $path );
			if ( is_file( $path ) ) {
				return $this->storage_error();
			}
		}
		return false;
	}

	/**
	 * Whether a user is blocked from using this plugin.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function is_blocked( $user_id ) {
		if ( get_user_meta( $user_id, self::user_meta_key( self::BLOCKED_META ), true ) ) {
			return true;
		}

		$user    = get_userdata( $user_id );
		$blocked = get_option( self::LEGACY_BLOCK_OPTION, array() );
		return $user && is_array( $blocked ) && in_array( $user->user_login, $blocked, true );
	}

	/**
	 * Set or remove the blocked state.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $blocked Blocked state.
	 * @return true|WP_Error Confirmed block change and required cleanup, or an error.
	 */
	public function set_blocked( $user_id, $blocked ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return $this->storage_error();
		}
		return $this->with_owner_lock(
			$user_id,
			function () use ( $user_id, $blocked ) {
				$result = $this->write_value( $user_id, self::BLOCKED_META, '', $blocked ? 1 : null );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( $blocked ) {
					$result = $this->delete_avatar_locked( $user_id );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				return $this->sync_legacy_block_option( $user_id );
			},
			true
		);
	}

	/**
	 * Fresh canonical list, while holding the shared block-list mutex.
	 *
	 * @internal
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return true|WP_Error
	 */
	public function sync_legacy_block_option( $user_id ) {
		global $wpdb;
		$logins = $this->database_values( $wpdb->prepare( "SELECT DISTINCT u.user_login FROM %i u INNER JOIN %i m ON u.ID = m.user_id WHERE m.meta_key = %s AND m.meta_value = '1' ORDER BY u.ID", $wpdb->users, $wpdb->usermeta, self::user_meta_key( self::BLOCKED_META ) ) );
		// Preserve unrelated, possibly not-yet-imported legacy entries. Only this
		// owner's old login is replaced by its canonical state under both locks.
		$legacy = $this->read_value( 0, '', self::LEGACY_BLOCK_OPTION );
		if ( null === $legacy && ! $logins ) {
			return true;
		}
		$user   = get_userdata( $user_id );
		$legacy = is_array( $legacy ) ? $legacy : array();
		if ( $user ) {
			$legacy = array_values( array_diff( $legacy, array( $user->user_login ) ) );
		}
		return $this->write_value( 0, '', self::LEGACY_BLOCK_OPTION, array_values( array_unique( array_merge( $legacy, $logins ) ) ) );
	}

	/**
	 * Return the legacy upload directory.
	 *
	 * @return array{path:string,url:string}
	 */
	private function get_legacy_directory() {
		// Version 2.0.2 wrote here even with custom uploads or on subsites.
		return array(
			'path' => WP_CONTENT_DIR . '/uploads/author-image',
			'url'  => content_url( '/uploads/author-image' ),
		);
	}

	/**
	 * Return a legacy user-image path.
	 *
	 * @param int  $user_id         User ID.
	 * @param bool $include_removed Include a locally suppressed shared original.
	 * @return string
	 */
	public function get_legacy_user_path( $user_id, $include_removed = false ) {
		if ( ! $include_removed && '0' === (string) get_user_meta( $user_id, self::user_meta_key( self::USER_META ), true ) ) {
			return '';
		}
		$user = get_userdata( $user_id );
		if ( ! $user || false !== strpbrk( $user->user_login, '/\\' ) ) {
			return '';
		}

		$directory = $this->get_legacy_directory();
		$filename  = $user->user_login . '.jpg';
		return trailingslashit( $directory['path'] ) . $filename;
	}

	/**
	 * Return a cache-busted legacy user-image URL when it exists.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function get_legacy_user_url( $user_id ) {
		$user = get_userdata( $user_id );
		$path = $this->get_legacy_user_path( $user_id );
		if ( ! $user || ! $path || ! is_file( $path ) ) {
			return '';
		}

		$directory = $this->get_legacy_directory();
		$filename  = $user->user_login . '.jpg';
		return add_query_arg( 'ver', (string) filemtime( $path ), trailingslashit( $directory['url'] ) . rawurlencode( $filename ) );
	}

	/**
	 * Return the legacy default-image path.
	 *
	 * @param bool $include_removed Whether to include a locally suppressed legacy original.
	 * @return string
	 */
	public function get_legacy_default_path( $include_removed = false ) {
		if ( ! $include_removed && '0' === (string) get_option( self::DEFAULT_OPTION, null ) ) {
			return '';
		}
		$directory = $this->get_legacy_directory();
		return trailingslashit( $directory['path'] ) . 'author_default.jpg';
	}

	/**
	 * Return the legacy default-image URL when it exists.
	 *
	 * @return string
	 */
	private function get_legacy_default_url() {
		$path = $this->get_legacy_default_path();
		if ( ! is_file( $path ) ) {
			return '';
		}

		$directory = $this->get_legacy_directory();
		return add_query_arg( 'ver', (string) filemtime( $path ), trailingslashit( $directory['url'] ) . 'author_default.jpg' );
	}

	/**
	 * Run a media or block mutation under its owner lock.
	 *
	 * @param int      $user_id User ID, or zero for the default avatar.
	 * @param callable $callback Lifecycle mutation callback.
	 * @param bool     $block_list Whether to acquire the shared block-list mutex first.
	 * @return mixed|WP_Error
	 */
	private function with_owner_lock( $user_id, $callback, $block_list = false ) {
		return $this->lock->with_owner_lock( $user_id, $callback, $block_list );
	}
}
