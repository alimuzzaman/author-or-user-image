<?php
/**
 * Read-only adapters for the verified local competitor storage formats.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Resolve competitor records without loading their code or changing their data. */
final class Import_Sources {
	/**
	 * Authoritative database reader shared with the owner lock.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Construct the read-only adapters.
	 *
	 * @param Image_Store|null $store Shared storage reader.
	 */
	public function __construct( ?Image_Store $store = null ) {
		$this->store = $store ? $store : new Image_Store();
	}

	/**
	 * Allowlisted source names and verified versions, never arbitrary class names.
	 *
	 * @return array<string,string>
	 */
	public static function registry() {
		return array(
			'simple-local-avatars'     => __( 'Simple Local Avatars 2.8.6', 'author-or-user-image' ),
			'one-user-avatar'          => __( 'One User Avatar 2.5.6 / WP User Avatar 2.2.16 attachment IDs', 'author-or-user-image' ),
			'wp-user-avatars'          => __( 'WP User Avatars 2.2.0', 'author-or-user-image' ),
			'metronet-profile-picture' => __( 'User Profile Picture / Metronet 2.6.4', 'author-or-user-image' ),
		);
	}

	/**
	 * Keep all source reads inside Image_Store's owner mutex.
	 *
	 * @param string $source Registry identifier.
	 * @param int    $user_id Subject.
	 * @return callable
	 */
	public function resolver( $source, $user_id ) {
		return function () use ( $source, $user_id ) {
			$first = $this->resolve( $source, $user_id );
			if ( is_wp_error( $first ) ) {
				return $first;
			}
			$second = $this->resolve( $source, $user_id );
			if ( is_wp_error( $second ) || $first !== $second ) {
				return new WP_Error( 'zmai_source_changed', __( 'The source changed. Please retry.', 'author-or-user-image' ) );
			}
			return $second;
		};
	}

	/**
	 * Resolve a verified source shape from uncached current-site rows.
	 *
	 * @param string $source Registry identifier.
	 * @param int    $user_id Subject.
	 * @return array{path:string,name:string}|WP_Error
	 */
	public function resolve( $source, $user_id ) {
		try {
			return $this->resolve_source( $source, $user_id );
		} catch ( \Throwable $error ) {
			return $this->store->storage_error();
		}
	}

	/**
	 * Read the internally selected format. Read failures cannot mean absence.
	 *
	 * @param string $source Registry identifier.
	 * @param int    $user_id Subject.
	 * @return array|WP_Error
	 */
	private function resolve_source( $source, $user_id ) {
		if ( ! is_string( $source ) || ! isset( self::registry()[ $source ] ) || ! $this->positive_id( $user_id ) ) {
			return $this->error( 'invalid_source' );
		}
		switch ( $source ) {
			case 'simple-local-avatars':
				return $this->simple_local( $user_id );
			case 'one-user-avatar':
				return $this->one_user( $user_id );
			case 'wp-user-avatars':
				return $this->array_source( $this->user_row( $user_id, 'wp_user_avatars' ), 'site_id', $user_id );
			case 'metronet-profile-picture':
				return $this->metronet( $user_id );
		}
		return $this->error( 'invalid_source' );
	}

	/**
	 * Read one row with presence separate from empty values. Never instantiate objects.
	 *
	 * @param string $sql Internally prepared SQL.
	 * @return array|WP_Error {present:bool,value:mixed}.
	 */
	private function row( $sql ) {
		$values = $this->store->database_values( $sql );
		if ( count( $values ) > 1 ) {
			return $this->error( 'invalid_source' );
		}
		if ( ! $values ) {
			return array(
				'present' => false,
				'value'   => null,
			);
		}
		$raw = $values[0];
		if ( ! is_string( $raw ) || strlen( $raw ) > 65536 ) {
			return $this->error( 'invalid_source' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Read competitor serialization without ever constructing a PHP object.
		$value = is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : $raw;
		if ( $this->has_object( $value ) ) {
			return $this->error( 'invalid_source' );
		}
		return array(
			'present' => true,
			'value'   => $value,
		);
	}

	/**
	 * Reject objects even in unused cached source values.
	 *
	 * @param mixed $value Decoded source.
	 * @param int   $depth Nesting budget.
	 * @return bool
	 */
	private function has_object( $value, $depth = 0 ) {
		if ( is_object( $value ) || $depth > 8 ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->has_object( $item, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Read an exact competitor usermeta key, without this plugin's suffix convention.
	 *
	 * @param int    $user_id Subject.
	 * @param string $key Internally chosen key.
	 * @return array|WP_Error
	 */
	private function user_row( $user_id, $key ) {
		global $wpdb;
		return $this->row( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $user_id, $key ) );
	}

	/**
	 * Read current-site or current-network settings without source plugin filters.
	 *
	 * @param string $key Fixed setting key.
	 * @param bool   $network Network scope.
	 * @return array|WP_Error
	 */
	private function setting( $key, $network = false ) {
		global $wpdb;
		return $network
			? $this->row( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE site_id = %d AND meta_key = %s', $wpdb->sitemeta, get_current_network_id(), $key ) )
			: $this->row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) );
	}

	/**
	 * SLA 2.8.6, including effective network enforcement and nonshared keys.
	 *
	 * @param int $user_id Subject.
	 * @return array|WP_Error
	 */
	private function simple_local( $user_id ) {
		$options = $this->setting( 'simple_local_avatars' );
		if ( is_multisite() ) {
			$active = $this->setting( 'active_sitewide_plugins', true );
			$mode   = $this->setting( 'simple_local_avatars_mode', true );
			if ( is_wp_error( $active ) || is_wp_error( $mode ) ) {
				return $this->error( 'invalid_source' );
			}
			if ( is_array( $active['value'] ) && isset( $active['value']['simple-local-avatars/simple-local-avatars.php'] ) && 'enforce' === $mode['value'] ) {
				$options = $this->setting( 'simple_local_avatars', true );
			}
		}
		if ( is_wp_error( $options ) || ( $options['present'] && ! is_array( $options['value'] ) ) ) {
			return $this->error( 'invalid_source' );
		}
		$key = 'simple_local_avatar';
		if ( is_multisite() && isset( $options['value']['shared'] ) && 1 !== $options['value']['shared'] ) {
			$key .= '_' . get_current_blog_id();
		}
		return $this->array_source( $this->user_row( $user_id, $key ), 'blog_id', $user_id );
	}

	/**
	 * SLA / WP User Avatars media selection or direct upload URL.
	 *
	 * @param array|WP_Error $row Source row.
	 * @param string         $site_key Verified site-ID field.
	 * @param int            $user_id Subject.
	 * @return array|WP_Error
	 */
	private function array_source( $row, $site_key, $user_id ) {
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! $row['present'] || array() === $row['value'] || '' === $row['value'] || '0' === $row['value'] ) {
			return $this->error( 'no_source' );
		}
		$value = $row['value'];
		if ( ! is_array( $value ) ) {
			return $this->error( 'invalid_source' );
		}
		if ( array_key_exists( $site_key, $value ) ) {
			if ( ! $this->positive_id( $value[ $site_key ] ) ) {
				return $this->error( 'invalid_source' );
			}
			if ( get_current_blog_id() !== (int) $value[ $site_key ] ) {
				return $this->error( 'other_site' );
			}
		}
		if ( array_key_exists( 'media_id', $value ) ) {
			if ( ! $this->positive_id( $value['media_id'] ) || ( is_multisite() && ! isset( $value[ $site_key ] ) ) ) {
				return $this->error( 'invalid_source' );
			}
			return $this->attachment( $value['media_id'], $user_id );
		}
		return isset( $value['full'] ) ? $this->url_file( $value['full'], $user_id, 'site_id' === $site_key ) : $this->error( 'invalid_source' );
	}

	/**
	 * One User Avatar and WP User Avatar attachment-ID storage only.
	 *
	 * @param int $user_id Subject.
	 * @return array|WP_Error
	 */
	private function one_user( $user_id ) {
		global $wpdb;
		$key = $wpdb->get_blog_prefix() . 'user_avatar';
		$row = $this->user_row( $user_id, $key );
		if ( ! is_wp_error( $row ) && ! $row['present'] && 'wp_user_avatar' !== $key && ( ! is_multisite() || is_main_site() ) ) {
			$row = $this->user_row( $user_id, 'wp_user_avatar' );
		}
		return $this->id_row( $row, $user_id );
	}

	/**
	 * Metronet profile thumbnail is authoritative, including its removal.
	 *
	 * @param int $user_id Subject.
	 * @return array|WP_Error
	 */
	private function metronet( $user_id ) {
		global $wpdb;
		$row = $this->user_row( $user_id, $wpdb->get_blog_prefix() . 'metronet_post_id' );
		if ( ! is_wp_error( $row ) && ! $row['present'] ) {
			$row = $this->user_row( $user_id, 'metronet_post_id' );
		}
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! $row['present'] ) {
			return $this->id_row( $this->user_row( $user_id, $wpdb->get_blog_prefix() . 'metronet_image_id' ), $user_id );
		}
		if ( ! $this->positive_id( $row['value'] ) ) {
			return $this->error( 'invalid_source' );
		}
		$types = $this->store->database_values( $wpdb->prepare( 'SELECT post_type FROM %i WHERE ID = %d AND post_author = %d', $wpdb->posts, $row['value'], $user_id ) );
		if ( array( 'mt_pp' ) !== $types ) {
			return $this->error( 'invalid_source' );
		}
		$thumbnail = $this->row( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $row['value'], '_thumbnail_id' ) );
		return $this->id_row( $thumbnail, $user_id );
	}

	/**
	 * Interpret an ID row with explicit empty/zero removal.
	 *
	 * @param array|WP_Error $row Source row.
	 * @param int            $user_id Subject.
	 * @return array|WP_Error
	 */
	private function id_row( $row, $user_id ) {
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! $row['present'] || '' === $row['value'] || '0' === $row['value'] ) {
			return $this->error( 'no_source' );
		}
		return $this->positive_id( $row['value'] ) ? $this->attachment( $row['value'], $user_id ) : $this->error( 'invalid_source' );
	}

	/**
	 * Require canonical positive integers, rejecting floats, booleans and overflow.
	 *
	 * @param mixed $value ID.
	 * @return bool
	 */
	private function positive_id( $value ) {
		return ( is_int( $value ) && $value > 0 ) || ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) && (string) (int) $value === $value );
	}

	/**
	 * Resolve unfiltered current-table attachment metadata, never switch blogs.
	 *
	 * @param int $id Attachment ID.
	 * @param int $user_id Subject.
	 * @return array|WP_Error
	 */
	private function attachment( $id, $user_id ) {
		global $wpdb;
		$types = $this->store->database_values( $wpdb->prepare( "SELECT post_type FROM %i WHERE ID = %d AND post_mime_type IN ('image/jpeg','image/png','image/webp')", $wpdb->posts, $id ) );
		if ( array( 'attachment' ) !== $types ) {
			return $this->error( 'invalid_source' );
		}
		$authors = $this->store->database_values( $wpdb->prepare( 'SELECT post_author FROM %i WHERE ID = %d', $wpdb->posts, $id ) );
		if ( 1 !== count( $authors ) || ( (int) $authors[0] !== (int) $user_id && ! user_can( (int) $authors[0], 'edit_user', (int) $user_id ) ) ) {
			return $this->error( 'not_owned' );
		}
		$row = $this->row( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $id, '_wp_attached_file' ) );
		if ( is_wp_error( $row ) || ! $row['present'] || ! is_string( $row['value'] ) ) {
			return $this->error( 'invalid_source' );
		}
		$path = $row['value'];
		if ( ! path_is_absolute( $path ) ) {
			$uploads = wp_upload_dir( null, false );
			$path    = trailingslashit( $uploads['basedir'] ) . $path;
		}
		return $this->local_file( $path );
	}

	/**
	 * Exact canonical uploads URL mapping only. This method never fetches a URL.
	 *
	 * @param mixed $url Stored full URL.
	 * @param int   $user_id Subject.
	 * @param bool  $wp_user_avatars Permit verified WPUA direct-upload naming.
	 * @return array|WP_Error
	 */
	private function url_file( $url, $user_id, $wp_user_avatars = false ) {
		if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\\\\?#]/', $url ) || preg_match( '/%(?:2f|5c|00)/i', $url ) ) {
			return $this->error( 'unsupported_source' );
		}
		$uploads = wp_upload_dir( null, false );
		$base    = wp_parse_url( $uploads['baseurl'] );
		$parts   = wp_parse_url( $url );
		if ( ! is_array( $base ) || ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) || $parts['scheme'] !== $base['scheme'] || strtolower( $parts['host'] ) !== strtolower( $base['host'] ) || ( $parts['port'] ?? null ) !== ( $base['port'] ?? null ) ) {
			return $this->error( 'unsupported_source' );
		}
		$prefix = trailingslashit( $base['path'] ?? '/' );
		if ( 0 !== strpos( $parts['path'], $prefix ) ) {
			return $this->error( 'unsupported_source' );
		}
		$relative = rawurldecode( substr( $parts['path'], strlen( $prefix ) ) );
		if ( '' === $relative || false !== strpos( $relative, '%' ) || preg_match( '/[\x00-\x1f\\\\]/', $relative ) || preg_match( '#(?:^|/)(?:\.|\.\.)(?:/|$)#', $relative ) || false !== strpos( $relative, '//' ) ) {
			return $this->error( 'unsupported_source' );
		}
		if ( is_multisite() && is_main_site() && 0 === strpos( $relative, 'sites/' ) ) {
			return $this->error( 'other_site' );
		}
		$file = $this->local_file( trailingslashit( $uploads['basedir'] ) . $relative );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		return $this->owned_url_file( $file, $user_id, $wp_user_avatars );
	}

	/**
	 * Authorize a canonical URL source by its attachment or verified WPUA naming.
	 *
	 * @param array $file Canonical contained file.
	 * @param int   $user_id Subject.
	 * @param bool  $wp_user_avatars Allow WPUA's subject-ID direct-upload names.
	 * @return array|WP_Error
	 */
	private function owned_url_file( array $file, $user_id, $wp_user_avatars ) {
		global $wpdb;
		$uploads  = wp_upload_dir( null, false );
		$root     = realpath( $uploads['basedir'] );
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file['path'], strlen( $root ) + 1 ) );
		$ids      = $this->store->database_values( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value IN (%s, %s)', $wpdb->postmeta, '_wp_attached_file', $relative, $file['path'] ) );
		foreach ( $ids as $id ) {
			$owned = $this->attachment( $id, $user_id );
			if ( ! is_wp_error( $owned ) && $owned['path'] === $file['path'] ) {
				return $file;
			}
		}
		// WPUA 2.2.0 common.php::wp_user_avatars_unique_filename_callback uses
		// avatar_user_ID_timestamp[_collision]. Its direct uploads have no attachment.
		// Only the ordinary uploads root/date directories qualify, not protected trees.
		if ( ! $ids && $wp_user_avatars && preg_match( '#^(?:[0-9]{4}/(?:0[1-9]|1[0-2])/)?avatar_user_' . (int) $user_id . '_[1-9][0-9]{8,10}(?:_[1-9][0-9]*)?\.(?:jpg|jpeg|jpe|png|webp)$#D', $relative ) ) {
			return $file;
		}
		return $this->error( 'not_owned' );
	}

	/**
	 * Canonical regular-file containment with a separator boundary and no streams.
	 *
	 * @param string $path Candidate local path.
	 * @return array|WP_Error
	 */
	private function local_file( $path ) {
		if ( false !== strpos( $path, '://' ) || false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) ) {
			return $this->error( 'unsupported_source' );
		}
		$uploads = wp_upload_dir( null, false );
		$root    = realpath( $uploads['basedir'] );
		$real    = realpath( $path );
		if ( $uploads['error'] || ! $root ) {
			return $this->error( 'unsupported_source' );
		}
		if ( ! $real ) {
			return $this->error( 'missing_file' );
		}
		if ( 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) || ! is_readable( $real ) ) {
			return $this->error( 'unsupported_source' );
		}
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) + 1 ) );
		if ( is_multisite() && is_main_site() && 0 === strpos( $relative, 'sites/' ) ) {
			return $this->error( 'other_site' );
		}
		return array(
			'path' => $real,
			'name' => basename( $real ),
		);
	}

	/**
	 * Fixed operational codes; never expose source identifiers, URLs or paths.
	 *
	 * @param string $code Internally chosen suffix.
	 * @return WP_Error
	 */
	private function error( $code ) {
		return new WP_Error( 'zmai_import_' . $code, __( 'The selected source has no supported local image for this site. Restore missing or offloaded files locally before retrying. Source data was not changed.', 'author-or-user-image' ) );
	}
}
