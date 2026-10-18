<?php
/**
 * Atomic site-scoped mutex with exact-token release.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Atomic site-scoped mutex with exact-token release. */
final class Lock {
	/**
	 * Active callback frames, including nested owner operations.
	 *
	 * @var array
	 */
	private $frames = array();

	/**
	 * Storage failure, optionally requiring operator recovery.
	 *
	 * @param bool $recovery Whether operator recovery is required.
	 * @return WP_Error
	 */
	public static function storage_error( $recovery = false ) {
		return new WP_Error( 'zmai_storage', $recovery ? __( 'Avatar cleanup needs administrator recovery before it can continue.', 'author-or-user-image' ) : __( 'Avatar data could not be updated completely. Please retry.', 'author-or-user-image' ), array( 'recovery' => $recovery ) );
	}

	/**
	 * A plain INSERT arbitrates on unique option_name. There is no expiry/takeover.
	 *
	 * @param string $name Mutex option name.
	 * @return array|WP_Error Captured site table, name and random ownership token.
	 */
	public function acquire_lock( $name ) {
		global $wpdb;
		$token    = 'zmai:' . wp_generate_uuid4();
		$table    = $wpdb->options;
		$previous = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic unique INSERT must bypass add_option's upsert and caches.
			$inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, $name, $token, 'no' ) );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		if ( 1 !== $inserted ) {
			return new WP_Error( 'zmai_busy', __( 'Avatar data is busy or unavailable. Please retry.', 'author-or-user-image' ) );
		}
		return array(
			'table' => $table,
			'name'  => $name,
			'token' => $token,
		);
	}

	/**
	 * Conditional, byte-exact release of our captured row.
	 *
	 * @param array $lock Captured mutex table, name and token.
	 * @return true|WP_Error
	 */
	public function release_lock( $lock ) {
		global $wpdb;
		// HEX avoids case-insensitive MySQL value collation and also works on SQLite.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional DELETE must compare the captured token against authoritative storage.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND HEX(option_value) = HEX(%s)', $lock['table'], $lock['name'], $lock['token'] ) );
		if ( $lock['table'] === $wpdb->options ) {
			wp_cache_delete( $lock['name'], 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return 1 === $deleted ? true : self::storage_error( true );
	}

	/**
	 * Mark before attempting any callback mutation.
	 *
	 * @internal
	 * @return void
	 */
	public function mark_mutation() {
		foreach ( $this->frames as &$frame ) {
			$frame['mutated'] = true;
		}
		unset( $frame );
	}

	/**
	 * Throw an identified authoritative read failure.
	 *
	 * @throws \RuntimeException When an authoritative database read fails.
	 * @internal
	 * @return void
	 */
	public function read_error() {
		if ( $this->frames ) {
			$this->frames[ count( $this->frames ) - 1 ]['read_error'] = true;
		}
		throw new \RuntimeException( 'Avatar database read failed.' );
	}

	/**
	 * Block-list writers take the shared mutex first. Successful retained owner
	 * fences transfer to the supplied request-local cleanup callback.
	 *
	 * @param int           $user_id User ID, or zero for the default avatar.
	 * @param callable      $callback Lifecycle mutation callback.
	 * @param bool          $block_list Whether to acquire the shared block-list mutex first.
	 * @param callable|null $retain_success Callback accepting a successfully retained owner fence.
	 * @return mixed|WP_Error Unknown exceptions or lost inventory retain recovery fences.
	 */
	public function with_owner_lock( $user_id, $callback, $block_list = false, $retain_success = null ) {
		$locks   = array();
		$names   = $block_list ? array( 'zmai_avatar_lock_blocks' ) : array();
		$names[] = 'zmai_avatar_lock_' . ( $user_id ? 'user_' . $user_id : 'default' );
		foreach ( $names as $name ) {
			$lock = $this->acquire_lock( $name );
			if ( is_wp_error( $lock ) ) {
				foreach ( array_reverse( $locks ) as $held ) {
					$released = $this->release_lock( $held );
					if ( is_wp_error( $released ) ) {
						return $released;
					}
				}
				return $lock;
			}
			$locks[] = $lock;
		}
		$index          = count( $this->frames );
		$this->frames[] = array(
			'mutated'    => false,
			'read_error' => false,
		);
		$result         = null;
		try {
			$result = call_user_func( $callback );
		} catch ( \Throwable $error ) {
			// Only our identified pre-mutation decision-read failure is safe to retry.
			$frame  = $this->frames[ $index ];
			$result = self::storage_error( ! $frame['read_error'] || $frame['mutated'] );
		} finally {
			array_pop( $this->frames );
			if ( $retain_success && ! is_wp_error( $result ) ) {
				call_user_func( $retain_success, array_pop( $locks ) );
			}
			$data = is_wp_error( $result ) ? $result->get_error_data() : null;
			if ( ! is_array( $data ) || empty( $data['recovery'] ) ) {
				foreach ( array_reverse( $locks ) as $held ) {
					$released = $this->release_lock( $held );
					if ( is_wp_error( $released ) ) {
						$result = $released;
					}
				}
			}
		}
		return $result;
	}
}
