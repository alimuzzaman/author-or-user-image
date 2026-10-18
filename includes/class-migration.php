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

	/** Site-local nonautoloaded competitor progress, separate from legacy cursors. */
	const IMPORT_JOB_OPTION = 'zmai_import_job';

	/**
	 * Authoritative aggregate status. Reading never starts or advances a job.
	 *
	 * @return array|null|WP_Error
	 */
	public function import_status() {
		global $wpdb;
		try {
			$signals = $this->store->database_values( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::RELEASE_FAILED_OPTION ) );
			if ( $signals ) {
				return $this->import_error( 'recovery' );
			}
			$rows = $this->store->database_values( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::IMPORT_JOB_OPTION ) );
			if ( ! $rows ) {
				return null;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Job data must never instantiate stored PHP objects.
			$job = 1 === count( $rows ) && is_serialized( $rows[0] ) ? @unserialize( $rows[0], array( 'allowed_classes' => false ) ) : null;
			if ( ! $this->valid_import_job( $job ) ) {
				return $this->import_error( 'schema' );
			}
			// Older schema-1 jobs remain readable without a GET-time upgrade write.
			$job['skips']             = $job['skips'] ?? array();
			$job['counts']['skipped'] = $job['counts']['skipped'] ?? 0;
			return $job;
		} catch ( \Throwable $error ) {
			return $this->store->storage_error();
		}
	}

	/**
	 * Begin an explicit source run. Its UUID is a single-use replay token.
	 *
	 * @param string $source Allowlisted source.
	 * @param string $token UUID from the Start form.
	 * @param int    $revision Expected current job revision, zero when absent.
	 * @return array|WP_Error
	 */
	public function start_import( $source, $token, $revision ) {
		if ( ! is_string( $source ) || ! isset( Import_Sources::registry()[ $source ] ) || ! $this->import_uuid( $token ) || ! is_int( $revision ) || $revision < 0 ) {
			return $this->import_error( 'request' );
		}
		return $this->with_migration_lock(
			function () use ( $source, $token, $revision ) {
				$job = $this->import_status();
				if ( is_wp_error( $job ) ) {
					return $job;
				}
				if ( $job && $token === $job['id'] ) {
					return $job;
				}
				if ( ( $job ? $job['revision'] : 0 ) !== $revision ) {
					return $job ? $job : $this->import_error( 'request' );
				}
				if ( $job && 'complete' !== $job['state'] ) {
					return $this->import_error( 'active' );
				}
				if ( $this->store->read_value( 0, '', self::RELEASE_FAILED_OPTION ) ) {
					return $this->import_error( 'recovery' );
				}
				$ids = $this->import_user_ids( 0, PHP_INT_MAX, true );
				$job = array(
					'schema'          => 1,
					'id'              => $token,
					'source'          => $source,
					'site_id'         => get_current_blog_id(),
					'state'           => 'running',
					'last_user_id'    => 0,
					'upper_user_id'   => $ids ? (int) $ids[0] : 0,
					'sweep'           => 1,
					'revision'        => $revision + 1,
					'retry_needed'    => false,
					'counts'          => $this->import_counts(),
					'last_error_code' => '',
					'skips'           => array(),
				);
				return $this->save_import_job( $job );
			}
		);
	}

	/**
	 * Advance one explicit bounded page, serialized with legacy migration.
	 *
	 * @param string $id Job UUID.
	 * @param int    $revision Expected revision.
	 * @return array|WP_Error
	 */
	public function run_import_batch( $id, $revision ) {
		return $this->mutate_import(
			$id,
			$revision,
			function ( $job ) {
				if ( 'running' !== $job['state'] ) {
					return $job;
				}
				$started = microtime( true );
				$users   = $this->import_user_ids( $job['last_user_id'], $job['upper_user_id'] );
				$sources = new Import_Sources( $this->store );
				$visited = 0;
				foreach ( $users as $user_id ) {
					if ( $visited && microtime( true ) - $started >= 5 ) {
						break;
					}
					$result = $this->store->import_local_image( $sources->resolver( $job['source'], (int) $user_id ), (int) $user_id );
					++$visited;
					++$job['counts']['inspected'];
					if ( is_wp_error( $result ) ) {
						$code = $result->get_error_code();
						$data = $result->get_error_data();
						if ( in_array( $code, array( 'zmai_import_no_source', 'zmai_import_other_site' ), true ) ) {
							++$job['counts'][ 'zmai_import_no_source' === $code ? 'no_source' : 'other_site' ];
						} elseif ( ( ! is_array( $data ) || empty( $data['recovery'] ) ) && $this->permanent_import_reason( $code ) ) {
							++$job['counts']['skipped'];
							if ( count( $job['skips'] ) < 100 ) {
								$job['skips'][] = array(
									'user_id' => (int) $user_id,
									'reason'  => $this->permanent_import_reason( $code ),
								);
							}
						} else {
							++$job['counts']['failed'];
							$job['retry_needed']    = true;
							$job['last_error_code'] = 'zmai_busy' === $code ? 'busy' : 'import_failed';
							if ( is_array( $data ) && ! empty( $data['recovery'] ) ) {
								$job['state']           = 'recovery_required';
								$job['last_error_code'] = 'recovery';
								break;
							}
						}
					} else {
						$keys = array(
							'imported'        => 'imported',
							'already_present' => 'existing',
							'explicit_choice' => 'suppressed',
							'blocked'         => 'blocked',
							'gone'            => 'suppressed',
						);
						if ( ! is_array( $result ) || ! isset( $result['status'], $keys[ $result['status'] ] ) ) {
							return $this->store->storage_error( true );
						}
						++$job['counts'][ $keys[ $result['status'] ] ];
					}
					$job['last_user_id'] = (int) $user_id;
				}
				if ( 'running' === $job['state'] && ( ! $users || ( count( $users ) === $visited && count( $users ) < 20 ) || $job['last_user_id'] === $job['upper_user_id'] ) ) {
					$job['state'] = $job['retry_needed'] ? 'paused_with_failures' : 'complete';
					if ( 'complete' === $job['state'] ) {
						$job['last_error_code'] = '';
						$job['last_user_id']    = 0;
						$job['upper_user_id']   = 0;
					}
				}
				++$job['revision'];
				return $this->save_import_job( $job );
			}
		);
	}

	/**
	 * Pause without changing any owner, source or fence.
	 *
	 * @param string $id Job UUID.
	 * @param int    $revision Expected revision.
	 * @return array|WP_Error
	 */
	public function pause_import( $id, $revision ) {
		return $this->change_import_state( $id, $revision, 'pause' );
	}

	/**
	 * Resume the same site, source and upper bound.
	 *
	 * @param string $id Job UUID.
	 * @param int    $revision Expected revision.
	 * @return array|WP_Error
	 */
	public function resume_import( $id, $revision ) {
		return $this->change_import_state( $id, $revision, 'resume' );
	}

	/**
	 * Explicitly retry a repaired failed sweep with aggregate counts reset.
	 *
	 * @param string $id Job UUID.
	 * @param int    $revision Expected revision.
	 * @return array|WP_Error
	 */
	public function retry_import( $id, $revision ) {
		return $this->change_import_state( $id, $revision, 'retry' );
	}

	/**
	 * Finish a paused job without retrying or changing any owner or recovery lock.
	 *
	 * @param string $id Job UUID.
	 * @param int    $revision Expected revision.
	 * @return array|WP_Error
	 */
	public function finish_import( $id, $revision ) {
		return $this->change_import_state( $id, $revision, 'finish' );
	}

	/**
	 * Implement fixed transitions. A recovery state requires operator reconciliation.
	 *
	 * @param string $id Job UUID.
	 * @param int    $revision Expected revision.
	 * @param string $operation Internal transition name.
	 * @return array|WP_Error
	 */
	private function change_import_state( $id, $revision, $operation ) {
		return $this->mutate_import(
			$id,
			$revision,
			function ( $job ) use ( $operation ) {
				if ( 'pause' === $operation && 'running' === $job['state'] ) {
					$job['state'] = 'paused';
				} elseif ( 'resume' === $operation && 'paused' === $job['state'] ) {
					$job['state'] = 'running';
				} elseif ( 'retry' === $operation && 'paused_with_failures' === $job['state'] ) {
					$job['state']           = 'running';
					$job['last_user_id']    = 0;
					$job['counts']          = $this->import_counts();
					$job['retry_needed']    = false;
					$job['last_error_code'] = '';
					$job['skips']           = array();
					++$job['sweep'];
				} elseif ( 'finish' === $operation && in_array( $job['state'], array( 'paused', 'paused_with_failures' ), true ) ) {
					$job['state']           = 'complete';
					$job['last_user_id']    = 0;
					$job['upper_user_id']   = 0;
					$job['retry_needed']    = false;
					$job['last_error_code'] = '';
				} else {
					return $job;
				}
				++$job['revision'];
				return $this->save_import_job( $job );
			}
		);
	}

	/**
	 * Validate identity/revision under the shared coordinator fence before mutation.
	 *
	 * @param string   $id Job UUID.
	 * @param int      $revision Expected revision.
	 * @param callable $callback Validated transition.
	 * @return array|WP_Error
	 */
	private function mutate_import( $id, $revision, $callback ) {
		if ( ! $this->import_uuid( $id ) || ! is_int( $revision ) || $revision < 1 ) {
			return $this->import_error( 'request' );
		}
		return $this->with_migration_lock(
			function () use ( $id, $revision, $callback ) {
				$job = $this->import_status();
				if ( is_wp_error( $job ) ) {
					return $job;
				}
				if ( ! $job || $id !== $job['id'] ) {
					return $this->import_error( 'request' );
				}
				if ( $this->store->read_value( 0, '', self::RELEASE_FAILED_OPTION ) ) {
					return $this->import_error( 'recovery' );
				}
				return $revision === $job['revision'] ? call_user_func( $callback, $job ) : $job;
			}
		);
	}

	/**
	 * Keyset pagination over current-site members, using the actual blog prefix.
	 *
	 * @param int  $last Lower exclusive ID.
	 * @param int  $upper Upper inclusive ID.
	 * @param bool $maximum Whether to snapshot the maximum member ID only.
	 * @return array
	 */
	private function import_user_ids( $last, $upper, $maximum = false ) {
		global $wpdb;
		if ( is_multisite() ) {
			return $this->store->database_values(
				$maximum
					? $wpdb->prepare( 'SELECT MAX(u.ID) FROM %i u WHERE EXISTS (SELECT 1 FROM %i m WHERE m.user_id = u.ID AND m.meta_key = %s)', $wpdb->users, $wpdb->usermeta, $wpdb->get_blog_prefix() . 'capabilities' )
					: $wpdb->prepare( 'SELECT u.ID FROM %i u WHERE u.ID > %d AND u.ID <= %d AND EXISTS (SELECT 1 FROM %i m WHERE m.user_id = u.ID AND m.meta_key = %s) ORDER BY u.ID ASC LIMIT 20', $wpdb->users, $last, $upper, $wpdb->usermeta, $wpdb->get_blog_prefix() . 'capabilities' )
			);
		}
		return $this->store->database_values(
			$maximum
				? $wpdb->prepare( 'SELECT MAX(ID) FROM %i', $wpdb->users )
				: $wpdb->prepare( 'SELECT ID FROM %i WHERE ID > %d AND ID <= %d ORDER BY ID ASC LIMIT 20', $wpdb->users, $last, $upper )
		);
	}

	/**
	 * Fixed sweep counts; bounded skip records are stored separately.
	 *
	 * @return array<string,int>
	 */
	private function import_counts() {
		return array_fill_keys( array( 'inspected', 'imported', 'existing', 'suppressed', 'blocked', 'no_source', 'other_site', 'failed', 'skipped' ), 0 );
	}

	/**
	 * Deterministic source/validation errors need repair, not an automatic retry.
	 *
	 * @param string $code Typed error code.
	 * @return string Fixed reason code, or empty for a transient failure.
	 */
	private function permanent_import_reason( $code ) {
		$reasons = array(
			'zmai_import_invalid_source'     => 'invalid_source',
			'zmai_import_missing_file'       => 'missing_file',
			'zmai_import_unsupported_source' => 'unsupported_source',
			'zmai_import_path'               => 'invalid_source',
			'zmai_invalid_source'            => 'invalid_source',
			'zmai_invalid_image'             => 'invalid_image',
			'zmai_upload_size'               => 'upload_size',
			'zmai_animated_image'            => 'animated_image',
			'zmai_invalid_image_container'   => 'invalid_image_container',
			'zmai_invalid_dimensions'        => 'invalid_dimensions',
			'zmai_jpeg_exif_unavailable'     => 'jpeg_exif_unavailable',
			'zmai_image_editor'              => 'image_editor',
			'zmai_image_format'              => 'image_format',
			'zmai_source_changed'            => 'source_changed',
		);
		return $reasons[ $code ] ?? '';
	}

	/**
	 * Check a fully typed versioned job before acting on it.
	 *
	 * @param mixed $job Stored job.
	 * @return bool
	 */
	private function valid_import_job( $job ) {
		$keys = array( 'schema', 'id', 'source', 'site_id', 'state', 'last_user_id', 'upper_user_id', 'sweep', 'revision', 'retry_needed', 'counts', 'last_error_code' );
		if ( ! is_array( $job ) || array_diff( $keys, array_keys( $job ) ) || array_diff( array_keys( $job ), array_merge( $keys, array( 'skips' ) ) ) ) {
			return false;
		}
		if ( 1 !== $job['schema'] || ! $this->import_uuid( $job['id'] ) || ! is_string( $job['source'] ) || ! isset( Import_Sources::registry()[ $job['source'] ] ) || get_current_blog_id() !== $job['site_id'] || ! in_array( $job['state'], array( 'running', 'paused', 'paused_with_failures', 'recovery_required', 'complete' ), true ) || ! is_bool( $job['retry_needed'] ) || ! in_array( $job['last_error_code'], array( '', 'busy', 'import_failed', 'recovery' ), true ) ) {
			return false;
		}
		foreach ( array( 'last_user_id', 'upper_user_id', 'sweep', 'revision' ) as $key ) {
			if ( ! is_int( $job[ $key ] ) || $job[ $key ] < ( in_array( $key, array( 'sweep', 'revision' ), true ) ? 1 : 0 ) ) {
				return false;
			}
		}
		$counts = $this->import_counts();
		if ( ! isset( $job['skips'] ) ) {
			unset( $counts['skipped'] );
		}
		if ( $job['last_user_id'] > $job['upper_user_id'] || ! is_array( $job['counts'] ) || array_keys( $counts ) !== array_keys( $job['counts'] ) ) {
			return false;
		}
		foreach ( $job['counts'] as $count ) {
			if ( ! is_int( $count ) || $count < 0 ) {
				return false;
			}
		}
		if ( array_key_exists( 'skips', $job ) ) {
			if ( ! is_array( $job['skips'] ) || count( $job['skips'] ) > 100 || ( $job['skips'] && array_keys( $job['skips'] ) !== range( 0, count( $job['skips'] ) - 1 ) ) || count( $job['skips'] ) > $job['counts']['skipped'] ) {
				return false;
			}
			foreach ( $job['skips'] as $skip ) {
				if ( ! is_array( $skip ) || 2 !== count( $skip ) || ! isset( $skip['user_id'], $skip['reason'] ) || ! is_int( $skip['user_id'] ) || $skip['user_id'] < 1 || ! is_string( $skip['reason'] ) || ( ! $this->permanent_import_reason( 'zmai_' . $skip['reason'] ) && ! $this->permanent_import_reason( 'zmai_import_' . $skip['reason'] ) ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Exact request UUID, not an arbitrary string used in nonce/action names.
	 *
	 * @param mixed $id UUID.
	 * @return bool
	 */
	private function import_uuid( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id );
	}

	/**
	 * Confirm persistence before advertising a new cursor or completion.
	 *
	 * @param array $job Next status.
	 * @return array|WP_Error
	 */
	private function save_import_job( $job ) {
		$result = $this->store->write_value( 0, '', self::IMPORT_JOB_OPTION, $job );
		return is_wp_error( $result ) ? $result : $job;
	}

	/**
	 * Fixed job error without source paths, URLs or owner details.
	 *
	 * @param string $code Internal failure category.
	 * @return WP_Error
	 */
	private function import_error( $code ) {
		return new WP_Error( 'zmai_import_' . $code, __( 'The import cannot continue in its current state. Reload the import page. If recovery is required, ask the administrator to reconcile the retained locks before retrying.', 'author-or-user-image' ) );
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
