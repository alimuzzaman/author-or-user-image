<?php
/**
 * Legacy imports, migration cursor and retry progress.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Legacy imports, migration cursor and retry progress. */
final class Migration {
	/** Persistent failed-release signal, cleared only after the fence is absent. */
	const RELEASE_FAILED_OPTION = 'zmai_migration_release_failed';
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
	 * Migrate legacy block entries and JPG files once, retaining safe fallbacks.
	 *
	 * @return void
	 */
	public function maybe_migrate() {
		if ( get_option( self::RELEASE_FAILED_OPTION ) ) {
			try {
				if ( null === $this->store->read_value( 0, '', 'zmai_avatar_lock_migration' ) ) {
					delete_option( self::RELEASE_FAILED_OPTION );
				}
			} catch ( \Throwable $error ) {
				// Keep the notice when storage cannot confirm recovery.
				$this->report_error( $this->store->storage_error() );
			}
		}
		if ( version_compare( (string) get_option( Image_Store::VERSION_OPTION, '0' ), ZMAI_VERSION, '>=' ) ) {
			return;
		}
		$this->with_migration_lock(
			function () {
				$this->migrate_locked();
			}
		);
	}

	/**
	 * Serialize legacy and explicit import coordinators with exact-token release.
	 * Callers may acquire owner locks inside the callback, never in reverse order.
	 *
	 * @param callable $callback Coordinator operation.
	 * @return mixed|WP_Error Callback result or lock/storage failure.
	 */
	public function with_migration_lock( $callback ) {
		$lock = $this->store->get_lock()->acquire_lock( 'zmai_avatar_lock_migration' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$result = null;
		try {
			$result = call_user_func( $callback );
		} catch ( \Throwable $error ) {
			$result = $this->store->storage_error();
			$this->report_error( $result );
		} finally {
			$released = $this->store->get_lock()->release_lock( $lock );
			if ( is_wp_error( $released ) ) {
				update_option( self::RELEASE_FAILED_OPTION, 1, false );
				$this->report_error( $released );
				$result = $released;
			}
		}
		return $result;
	}

	/**
	 * Show a persistent recovery notice only to site administrators.
	 *
	 * @return void
	 */
	public function migration_notice() {
		if ( current_user_can( 'manage_options' ) && get_option( self::RELEASE_FAILED_OPTION ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Author or User Image: a legacy migration lock could not be released. Ask for administrator recovery before retrying migration. See the lifecycle recovery guide.', 'author-or-user-image' ) . '</p></div>';
		}
	}

	/**
	 * Surface storage failures without terminating administrator pages.
	 *
	 * @param WP_Error $error Storage or mutex-release error.
	 * @return void
	 */
	private function report_error( WP_Error $error ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Persistent migration/release failures need an operator-visible diagnostic; no values or paths are logged.
		error_log( 'Author or User Image: legacy migration storage or lock-release failure.' );
		do_action( 'zmai_migration_error', $error );
	}

	/**
	 * Caller holds the migration cursor mutex.
	 *
	 * @return void
	 */
	private function migrate_locked() {
		if ( version_compare( (string) $this->store->read_value( 0, '', Image_Store::VERSION_OPTION ), ZMAI_VERSION, '>=' ) ) {
			return;
		}

		$blocked = get_option( Image_Store::LEGACY_BLOCK_OPTION, array() );
		if ( is_array( $blocked ) ) {
			foreach ( $blocked as $login ) {
				$user = get_user_by( 'login', sanitize_user( $login ) );
				if ( $user ) {
					$result = $this->store->get_lock()->with_owner_lock(
						$user->ID,
						function () use ( $user ) {
							$current = $this->store->read_value( 0, '', Image_Store::LEGACY_BLOCK_OPTION );
							return is_array( $current ) && in_array( $user->user_login, $current, true ) ? $this->store->write_value( $user->ID, Image_Store::BLOCKED_META, '', 1 ) : true;
						},
						true
					);
					if ( is_wp_error( $result ) && is_wp_error( $this->store->write_value( 0, '', Image_Store::MIGRATION_RETRY_OPTION, 1 ) ) ) {
						return;
					}
				}
			}
		}

		if ( $this->migrate_legacy_images() ) {
			if ( is_wp_error( $this->store->write_value( 0, '', Image_Store::MIGRATION_OPTION, null ) ) ) {
				return;
			}
			if ( is_wp_error( $this->store->write_value( 0, '', Image_Store::MIGRATION_RETRY_OPTION, null ) ) ) {
				return;
			}
			$this->store->write_value( 0, '', Image_Store::VERSION_OPTION, ZMAI_VERSION );
		}
	}

	/**
	 * Import legacy JPGs into the Media Library where possible.
	 *
	 * @return bool Whether a whole sweep completed without failed or busy imports.
	 * Incomplete pages must advance and retry in a subsequent bounded sweep.
	 */
	private function migrate_legacy_images() {
		$complete     = true;
		$default_path = $this->store->get_legacy_default_path();
		if ( ! $this->store->get_default_attachment_id() && $default_path && is_file( $default_path ) ) {
			$complete = $this->sideload_legacy_image( $default_path, 0 );
		}

		$offset = absint( $this->store->read_value( 0, '', Image_Store::MIGRATION_OPTION ) );
		$users  = get_users(
			array(
				'number'  => 20,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'user_login' ),
			)
		);
		foreach ( $users as $user ) {
			if ( $this->store->get_user_attachment_id( $user->ID ) ) {
				continue;
			}

			$path = $this->store->get_legacy_user_path( $user->ID );
			if ( $path && is_file( $path ) ) {
				if ( ! $this->sideload_legacy_image( $path, $user->ID ) ) {
					$complete = false;
				}
			}
		}

		if ( ! $complete ) {
			if ( is_wp_error( $this->store->write_value( 0, '', Image_Store::MIGRATION_RETRY_OPTION, 1 ) ) ) {
				return false;
			}
		}

		if ( count( $users ) < 20 ) {
			if ( $this->store->read_value( 0, '', Image_Store::MIGRATION_RETRY_OPTION ) ) {
				// One boolean bounds retry state regardless of the number of bad files.
				// Revisit the whole population; failed/busy owners were never done.
				if ( is_wp_error( $this->store->write_value( 0, '', Image_Store::MIGRATION_OPTION, 0 ) ) ) {
					return false;
				}
				$this->store->write_value( 0, '', Image_Store::MIGRATION_RETRY_OPTION, null );
				return false;
			}
			return true;
		}

		$this->store->write_value( 0, '', Image_Store::MIGRATION_OPTION, $offset + count( $users ) );
		return false;
	}

	/**
	 * Import one old JPG without deleting it, so a failed update can roll back.
	 *
	 * @param string $path    Legacy file path.
	 * @param int    $user_id User ID, or zero for the default.
	 * @return bool Whether the image was imported successfully.
	 */
	private function sideload_legacy_image( $path, $user_id ) {
		$result = $this->store->get_lock()->with_owner_lock(
			$user_id,
			function () use ( $path, $user_id ) {
				if ( $user_id && ! $this->store->user_exists_fresh( $user_id ) ) {
					return true;
				}
				if ( null !== $this->store->read_value( $user_id, Image_Store::USER_META, Image_Store::DEFAULT_OPTION ) || ( $user_id && $this->store->is_blocked_fresh( $user_id ) ) ) {
					return true;
				}
				$old_id = $this->store->read_value( $user_id, Image_Store::MIGRATED_USER_META, Image_Store::MIGRATED_DEFAULT_OPTION );
				if ( $old_id && $this->store->validate_attachment_id( $old_id ) ) {
					return true;
				}
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				$this->store->get_lock()->mark_mutation();
				$temporary = wp_tempnam( basename( $path ) );
				if ( ! $temporary || ! copy( $path, $temporary ) ) {
					if ( $temporary ) {
						wp_delete_file( $temporary );
					}
					return false;
				}
				$file = array(
					'name'     => $user_id ? 'user-avatar-' . $user_id . '.jpg' : 'default-user-avatar.jpg',
					'tmp_name' => $temporary,
				);
				try {
					$id = media_handle_sideload( $file, 0, null, array( 'meta_input' => array( Image_Store::ATTACHMENT_OWNER_META => $this->store->owner_name( $user_id ) ) ) );
				} finally {
					wp_delete_file( $temporary );
				}
				if ( is_wp_error( $id ) ) {
					return false;
				}
				if ( ! wp_attachment_is_image( $id ) || ! $this->store->owns_attachment( $id, $user_id ) ) {
					$cleanup = $this->store->delete_attachment_checked( $id );
					return is_wp_error( $cleanup ) ? $cleanup : false;
				}
				$written = $this->store->write_value( $user_id, Image_Store::MIGRATED_USER_META, Image_Store::MIGRATED_DEFAULT_OPTION, $id );
				if ( is_wp_error( $written ) ) {
					$cleanup = $this->store->delete_attachment_checked( $id );
					return is_wp_error( $cleanup ) ? $cleanup : $written;
				}
				return $this->store->cleanup_owned( $user_id, $id );
			}
		);
		return ! is_wp_error( $result ) && true === $result;
	}
}
