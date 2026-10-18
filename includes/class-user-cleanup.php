<?php
/**
 * User deletion fences and site-local privacy cleanup.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** User deletion fences and site-local privacy cleanup. */
final class User_Cleanup {
	/**
	 * Shared image storage.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Image storage.
	 *
	 * @param Image_Store $store Image storage service.
	 */
	public function __construct( Image_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Successful request-owned deletion fences.
	 *
	 * @var array
	 */
	private $deleting_users = array();

	/**
	 * Whether shutdown release is registered for this instance.
	 *
	 * @var bool
	 */
	private $shutdown_registered = false;

	/**
	 * Atomic site-local privacy cleanup and unblock.
	 *
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return bool|WP_Error
	 */
	public function erase_user_data( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return $this->store->storage_error();
		}
		return $this->store->get_lock()->with_owner_lock(
			$user_id,
			function () use ( $user_id ) {
				return $this->erase_user_data_locked( $user_id );
			},
			true
		);
	}

	/**
	 * Keep creation fenced until WordPress finishes deleting the account.
	 *
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return bool|WP_Error
	 */
	public function begin_user_deletion( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return $this->store->storage_error();
		}
		return $this->store->get_lock()->with_owner_lock(
			$user_id,
			function () use ( $user_id ) {
				return $this->erase_user_data_locked( $user_id );
			},
			true,
			function ( $lock ) use ( $user_id ) {
				$this->deleting_users[ get_current_blog_id() . ':' . $user_id ] = $lock;
				if ( ! $this->shutdown_registered ) {
					register_shutdown_function( array( $this, 'shutdown' ) );
					$this->shutdown_registered = true;
				}
			}
		);
	}

	/**
	 * Fence all existing sites, including departed memberships, before network deletion.
	 *
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @throws \RuntimeException When an authoritative database read or site enumeration fails.
	 * @return true|WP_Error Abort deletion on busy/failed cleanup while identity exists.
	 */
	public function begin_network_user_deletion( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! is_multisite() || ! $user_id ) {
			return $this->store->storage_error();
		}
		$path   = $this->store->get_legacy_user_path( $user_id, true );
		$result = true;
		try {
			$offset = 0;
			do {
				$wpdb->last_error = ''; // A cached site query must not inherit an unrelated query failure.
				$sites            = get_sites(
					array(
						'fields'  => 'ids',
						'number'  => 100,
						'offset'  => $offset,
						'orderby' => 'id',
						'order'   => 'ASC',
					)
				);
				if ( $wpdb->last_error || ( ! $sites && 0 === $offset ) ) {
					throw new \RuntimeException( 'Avatar site enumeration failed.' );
				}
				foreach ( $sites as $site_id ) {
					switch_to_blog( $site_id );
					try {
						$result = $this->site_has_data( $user_id ) ? $this->begin_user_deletion( $user_id ) : true;
					} finally {
						restore_current_blog();
					}
					if ( is_wp_error( $result ) ) {
						break 2;
					}
				}
				$site_count = count( $sites );
				$offset    += $site_count;
			} while ( 100 === $site_count );
			// Site-local erasure intentionally retains this shared original. Only
			// account deletion removes it, once every site's owner fence is held.
			if ( ! is_wp_error( $result ) && $path && is_file( $path ) ) {
				wp_delete_file( $path );
				clearstatcache( true, $path );
				if ( is_file( $path ) ) {
					$result = $this->store->storage_error();
				}
			}
		} catch ( \Throwable $error ) {
			$result = $this->store->storage_error();
		}
		if ( is_wp_error( $result ) ) {
			// Successful prior sites have known state and can retry. An uncertain
			// site's recovery locks remain held by with_owner_lock itself.
			$released = $this->finish_user_deletion( $user_id );
			return is_wp_error( $released ) ? $released : $result;
		}
		return true;
	}

	/**
	 * Release this request's captured fences across deletion sites.
	 *
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return true|WP_Error
	 */
	public function finish_user_deletion( $user_id ) {
		$result = true;
		foreach ( $this->deleting_users as $key => $lock ) {
			if ( (int) substr( $key, strpos( $key, ':' ) + 1 ) !== absint( $user_id ) ) {
				continue;
			}
			$released = $this->store->get_lock()->release_lock( $lock );
			unset( $this->deleting_users[ $key ] );
			if ( is_wp_error( $released ) ) {
				$result = $released;
			}
		}
		return $result;
	}

	/**
	 * Caller owns the block-list lock and owner lock.
	 *
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return bool|WP_Error
	 */
	private function erase_user_data_locked( $user_id ) {
		$retained = $this->store->delete_avatar_locked( $user_id );
		if ( is_wp_error( $retained ) ) {
			return $retained;
		}
		$result = $this->store->write_value( $user_id, Image_Store::BLOCKED_META, '', null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = $this->store->sync_legacy_block_option( $user_id );
		return is_wp_error( $result ) ? $result : $retained;
	}
	/**
	 * One authoritative existence query avoids locks/writes on untouched sites.
	 *
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return bool Whether this site has plugin settings or this owner's media/meta.
	 */
	private function site_has_data( $user_id ) {
		global $wpdb;
		$values = $this->store->database_values(
			$wpdb->prepare(
				'SELECT (EXISTS (SELECT 1 FROM %i WHERE option_name LIKE %s OR option_name IN (%s, %s, %s)) OR EXISTS (SELECT 1 FROM %i WHERE user_id = %d AND meta_key IN (%s, %s, %s)) OR EXISTS (SELECT 1 FROM %i WHERE meta_key = %s AND meta_value = %s))',
				$wpdb->options,
				$wpdb->esc_like( 'zmai_' ) . '%',
				Image_Store::LEGACY_SIZE_OPTION,
				Image_Store::LEGACY_BLOCK_OPTION,
				'ai_notification',
				$wpdb->usermeta,
				$user_id,
				Image_Store::user_meta_key( Image_Store::USER_META ),
				Image_Store::user_meta_key( Image_Store::MIGRATED_USER_META ),
				Image_Store::user_meta_key( Image_Store::BLOCKED_META ),
				$wpdb->postmeta,
				Image_Store::ATTACHMENT_OWNER_META,
				(string) $user_id
			)
		);
		return ! empty( $values[0] );
	}

	/**
	 * Release only successful fences captured by this request.
	 *
	 * @return void
	 */
	public function shutdown() {
		foreach ( $this->deleting_users as $key => $lock ) {
			$released = $this->store->get_lock()->release_lock( $lock );
			unset( $this->deleting_users[ $key ] );
			if ( is_wp_error( $released ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Shutdown cannot return an error to core; log a fixed diagnostic without tokens or user data.
				error_log( 'Author or User Image: deletion fence release failed; administrator recovery required.' );
			}
		}
	}
}
