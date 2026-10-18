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

	/**
	 * Site-scoped lifecycle mutex.
	 *
	 * @var Lock
	 */
	private $lock;

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
		$field = sanitize_key( $field );
		$file  = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The caller verifies the upload form nonce; values are validated below and by WordPress upload handling.

		if ( empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'zmai_upload_failed', __( 'The image upload did not complete.', 'author-or-user-image' ) );
		}

		$max_bytes = min( wp_max_upload_size(), 8 * MB_IN_BYTES );
		if ( empty( $file['size'] ) || (int) $file['size'] > $max_bytes ) {
			return new WP_Error( 'zmai_upload_size', __( 'The image exceeds this site’s upload limit.', 'author-or-user-image' ) );
		}

		$name          = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$validated     = wp_check_filetype_and_ext( $file['tmp_name'], $name );
		$allowed_types = array( 'image/jpeg', 'image/png', 'image/webp' );
		if ( empty( $validated['type'] ) || ! in_array( $validated['type'], $allowed_types, true ) ) {
			return new WP_Error( 'zmai_invalid_image', __( 'Choose a valid image file.', 'author-or-user-image' ) );
		}

		$dimensions = wp_getimagesize( $file['tmp_name'] );
		if ( ! $dimensions || empty( $dimensions[0] ) || empty( $dimensions[1] ) || $dimensions[0] > 4096 || $dimensions[1] > 4096 ) {
			return new WP_Error( 'zmai_invalid_dimensions', __( 'Choose an image no larger than 4096 by 4096 pixels.', 'author-or-user-image' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$user_id = absint( $user_id );
		return $this->with_owner_lock(
			$user_id,
			function () use ( $field, $user_id ) {
				if ( $user_id && ( ! $this->user_exists_fresh( $user_id ) || $this->is_blocked_fresh( $user_id ) ) ) {
					return new WP_Error( 'zmai_blocked', __( 'Local avatar uploads are disabled for this account.', 'author-or-user-image' ) );
				}
				$this->lock->mark_mutation();
				$attachment_id = media_handle_upload(
					$field,
					0,
					array(
						/* translators: %d: numeric WordPress user ID. */
						'post_title' => $user_id ? sprintf( __( 'Avatar for user %d', 'author-or-user-image' ), $user_id ) : __( 'Default user avatar', 'author-or-user-image' ),
						'meta_input' => array( self::ATTACHMENT_OWNER_META => $this->owner_name( $user_id ) ),
					),
					array( 'test_form' => false )
				);
				if ( is_wp_error( $attachment_id ) ) {
					return $attachment_id;
				}
				if ( ! wp_attachment_is_image( $attachment_id ) || ! $this->owns_attachment( $attachment_id, $user_id ) ) {
					$cleanup = $this->delete_attachment_checked( $attachment_id );
					return is_wp_error( $cleanup ) ? $cleanup : $this->storage_error();
				}
				$result = $this->replace_attachment( $attachment_id, $user_id );
				if ( is_wp_error( $result ) ) {
					// A failed mapping commit must not leave newly staged media behind.
					if ( (string) $attachment_id !== (string) $this->read_value( $user_id, self::USER_META, self::DEFAULT_OPTION ) ) {
						$cleanup = $this->delete_attachment_checked( $attachment_id );
						if ( is_wp_error( $cleanup ) ) {
							return $cleanup;
						}
					}
					return $result;
				}
				return $attachment_id;
			}
		);
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
			$url = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );
			return $url ? $url : '';
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
			$url = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );
			return $url ? $url : '';
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
