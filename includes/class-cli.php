<?php
/**
 * WP-CLI commands for batch competitor avatar import and exact-token lock recovery.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

defined( 'ABSPATH' ) || exit;

/**
 * Manage competitor avatar imports and inspect or release lifecycle locks via WP-CLI.
 */
final class CLI {
	/**
	 * Shared singleton instance.
	 *
	 * @var CLI|null
	 */
	private static $instance = null;

	/**
	 * Image storage service.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Migration and competitor import coordinator.
	 *
	 * @var Migration
	 */
	private $migration;

	/**
	 * Construct the CLI command handler.
	 *
	 * @param Image_Store $store     Image storage service.
	 * @param Migration   $migration Migration coordinator.
	 */
	private function __construct( Image_Store $store, Migration $migration ) {
		$this->store     = $store;
		$this->migration = $migration;
	}

	/**
	 * Register WP-CLI subcommands when running under WP-CLI.
	 *
	 * @param Image_Store $store     Image storage service.
	 * @param Migration   $migration Migration coordinator.
	 * @return void
	 */
	public static function register( Image_Store $store, Migration $migration ) {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		self::$instance = new self( $store, $migration );

		\WP_CLI::add_command( 'zmai import sources', array( self::$instance, 'import_sources' ) );
		\WP_CLI::add_command( 'zmai import status', array( self::$instance, 'import_status' ) );
		\WP_CLI::add_command( 'zmai import run', array( self::$instance, 'import_run' ) );
		\WP_CLI::add_command( 'zmai import pause', array( self::$instance, 'import_pause' ) );
		\WP_CLI::add_command( 'zmai import resume', array( self::$instance, 'import_resume' ) );
		\WP_CLI::add_command( 'zmai import retry', array( self::$instance, 'import_retry' ) );
		\WP_CLI::add_command( 'zmai import finish', array( self::$instance, 'import_finish' ) );
		\WP_CLI::add_command( 'zmai locks list', array( self::$instance, 'locks_list' ) );
		\WP_CLI::add_command( 'zmai locks unlock', array( self::$instance, 'locks_unlock' ) );
	}

	/**
	 * List detected or supported competitor avatar import sources.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Include all supported sources, even when none are detected on this site.
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv, yaml). Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function import_sources( $args, $assoc_args ) {
		$show_all = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$format   = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( ! in_array( $format, array( 'table', 'json', 'csv', 'yaml' ), true ) ) {
			\WP_CLI::error( 'Format must be table, json, csv, or yaml.' );
		}

		$detected = ( new Import_Sources( $this->store ) )->detected_sources();
		if ( is_wp_error( $detected ) ) {
			\WP_CLI::error( $detected->get_error_message() );
		}

		$registry = Import_Sources::registry();
		$items    = array();
		if ( $show_all ) {
			foreach ( $registry as $source => $name ) {
				$items[] = array(
					'source'   => $source,
					'name'     => $name,
					'detected' => isset( $detected[ $source ] ) ? 'yes' : 'no',
				);
			}
		} else {
			foreach ( $detected as $source => $name ) {
				$items[] = array(
					'source'   => $source,
					'name'     => $name,
					'detected' => 'yes',
				);
			}
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'source', 'name', 'detected' ) );
	}

	/**
	 * Show the current competitor avatar import job status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml). Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function import_status( $args, $assoc_args ) {
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( ! in_array( $format, array( 'table', 'json', 'yaml' ), true ) ) {
			\WP_CLI::error( 'Format must be table, json, or yaml.' );
		}
		$job = $this->migration->import_status();
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}

		if ( null === $job ) {
			if ( 'json' === $format ) {
				\WP_CLI::line( (string) wp_json_encode( array( 'state' => 'idle' ) ) );
			} elseif ( 'yaml' === $format ) {
				\WP_CLI\Utils\format_items( 'yaml', array( array( 'state' => 'idle' ) ), array( 'state' ) );
			} else {
				\WP_CLI::log( 'No import job recorded on this site.' );
			}
			return;
		}

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $job ) );
			return;
		}

		$row = array(
			'id'        => $job['id'],
			'source'    => $job['source'],
			'state'     => $job['state'],
			'sweep'     => $job['sweep'],
			'revision'  => $job['revision'],
			'inspected' => $job['counts']['inspected'],
			'imported'  => $job['counts']['imported'],
			'failed'    => $job['counts']['failed'],
			'skipped'   => $job['counts']['skipped'],
		);
		\WP_CLI\Utils\format_items( $format, array( $row ), array_keys( $row ) );
	}

	/**
	 * Start or continue a competitor avatar import in bounded batches.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source plugin key (e.g. simple-local-avatars, one-user-avatar, wp-user-avatars, metronet-profile-picture).
	 *
	 * [--max-batches=<n>]
	 * : Maximum batches to execute in this invocation (0 = run until non-running). Default: 0.
	 *
	 * [--format=<format>]
	 * : Output format (table, json). Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function import_run( $args, $assoc_args ) {
		$source = isset( $args[0] ) ? (string) $args[0] : '';
		if ( ! isset( Import_Sources::registry()[ $source ] ) ) {
			\WP_CLI::error( 'Unknown import source. Run "wp zmai import sources --all" to list supported sources.' );
		}

		$max_batches = 0;
		if ( isset( $assoc_args['max-batches'] ) ) {
			if ( is_bool( $assoc_args['max-batches'] ) || ! is_scalar( $assoc_args['max-batches'] ) ) {
				\WP_CLI::error( 'Invalid --max-batches value. Provide a non-negative integer.' );
			}
			$raw_max = (string) $assoc_args['max-batches'];
			if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $raw_max ) ) {
				\WP_CLI::error( 'Invalid --max-batches value. Provide a non-negative integer.' );
			}
			$max_batches = (int) $raw_max;
		}

		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			\WP_CLI::error( 'Format must be table or json.' );
		}

		$job = $this->migration->import_status();
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}

		if ( ! $job || 'complete' === $job['state'] ) {
			$job = $this->migration->start_import( $source, wp_generate_uuid4(), $job ? (int) $job['revision'] : 0 );
		} elseif ( 'running' !== $job['state'] || $source !== $job['source'] ) {
			\WP_CLI::error(
				sprintf(
					'Cannot run "%1$s" while job %2$s (%3$s) is in state "%4$s". Use resume, retry, finish, or recovery first.',
					$source,
					$job['id'],
					$job['source'],
					$job['state']
				)
			);
		}

		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}

		$batches = 0;
		$limit   = $max_batches > 0 ? $max_batches : 10000;
		while ( is_array( $job ) && 'running' === $job['state'] && $batches < $limit ) {
			$next = $this->migration->run_import_batch( $job['id'], $job['revision'] );
			if ( is_wp_error( $next ) ) {
				$job = $next;
				break;
			}
			$job = $next;
			++$batches;
			if ( function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
				\WP_CLI\Utils\wp_clear_object_cache();
			}
		}

		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $job ) );
			if ( 'recovery_required' === $job['state'] ) {
				\WP_CLI::error( 'Import entered recovery_required state.' );
			}
			return;
		}

		\WP_CLI::log(
			sprintf(
				'Job %1$s (%2$s): state=%3$s inspected=%4$d imported=%5$d failed=%6$d skipped=%7$d',
				$job['id'],
				$job['source'],
				$job['state'],
				$job['counts']['inspected'],
				$job['counts']['imported'],
				$job['counts']['failed'],
				$job['counts']['skipped']
			)
		);

		if ( 'complete' === $job['state'] ) {
			\WP_CLI::success( 'Import completed.' );
		} elseif ( 'recovery_required' === $job['state'] ) {
			\WP_CLI::error( 'Import stopped in recovery_required state. Reconcile retained locks before continuing.' );
		} elseif ( 'paused_with_failures' === $job['state'] ) {
			\WP_CLI::warning( 'Import paused with temporary failures. Run "wp zmai import retry" or "wp zmai import finish".' );
		} elseif ( 'paused' === $job['state'] ) {
			\WP_CLI::warning( 'Import was paused. Run "wp zmai import resume" or "wp zmai import finish".' );
		} elseif ( $max_batches > 0 ) {
			\WP_CLI::warning( 'Stopped after reaching --max-batches limit; job remains running.' );
		} else {
			\WP_CLI::warning( 'Stopped after reaching the 10000-batch safety cap; re-run "wp zmai import run" to continue.' );
		}
	}

	/**
	 * Pause the current running import job.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json). Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function import_pause( $args, $assoc_args ) {
		$this->run_job_transition( 'pause', $assoc_args );
	}

	/**
	 * Resume the current paused import job.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json). Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function import_resume( $args, $assoc_args ) {
		$this->run_job_transition( 'resume', $assoc_args );
	}

	/**
	 * Retry a paused_with_failures import job from the start of a new sweep.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json). Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function import_retry( $args, $assoc_args ) {
		$this->run_job_transition( 'retry', $assoc_args );
	}

	/**
	 * Finish a paused or paused_with_failures import job without retrying.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json). Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function import_finish( $args, $assoc_args ) {
		$this->run_job_transition( 'finish', $assoc_args );
	}

	/**
	 * List active or retained lifecycle locks on the current site.
	 *
	 * ## OPTIONS
	 *
	 * [--show-tokens]
	 * : Include exact lock ownership tokens in the output.
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv, yaml). Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function locks_list( $args, $assoc_args ) {
		$show_tokens = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'show-tokens', false );
		$format      = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( ! in_array( $format, array( 'table', 'json', 'csv', 'yaml' ), true ) ) {
			\WP_CLI::error( 'Format must be table, json, csv, or yaml.' );
		}

		$locks = $this->store->get_lock()->inspect_locks();
		if ( is_wp_error( $locks ) ) {
			\WP_CLI::error( $locks->get_error_message() );
		}

		try {
			$release_failed = (bool) $this->store->read_value( 0, '', Migration::RELEASE_FAILED_OPTION );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $this->store->storage_error()->get_error_message() );
		}

		if ( $release_failed ) {
			\WP_CLI::warning( 'Option zmai_migration_release_failed is set on this site.' );
		}

		$rows = array();
		foreach ( $locks as $lock ) {
			$row = array(
				'name'     => $lock['name'],
				'scope'    => $lock['scope'],
				'owner_id' => $lock['owner_id'],
				'table'    => $lock['table'],
			);
			if ( $show_tokens ) {
				$row['token'] = $lock['token'];
			}
			$rows[] = $row;
		}

		$fields = $show_tokens ? array( 'name', 'scope', 'owner_id', 'token', 'table' ) : array( 'name', 'scope', 'owner_id', 'table' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Conditionally release a retained lifecycle lock using its exact ownership token.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Lock option name (must begin with zmai_avatar_lock_).
	 *
	 * --token=<token>
	 * : Exact byte-for-byte ownership token from the lock row.
	 *
	 * [--yes]
	 * : Confirm that all PHP writers are quiescent and incomplete media files were reconciled.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative options.
	 * @return void
	 */
	public function locks_unlock( $args, $assoc_args ) {
		global $wpdb;

		$name  = isset( $args[0] ) ? (string) $args[0] : '';
		$token = isset( $assoc_args['token'] ) && is_string( $assoc_args['token'] ) ? $assoc_args['token'] : '';
		$yes   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( '' === $name || 0 !== strpos( $name, 'zmai_avatar_lock_' ) ) {
			\WP_CLI::error( 'Provide a valid zmai_avatar_lock_* option name.' );
		}
		if ( '' === $token ) {
			\WP_CLI::error( 'Provide the exact lock token via --token=<token>.' );
		}
		if ( ! $yes ) {
			\WP_CLI::error( 'Pass --yes after confirming all writers are quiescent and incomplete files are reconciled.' );
		}

		$released = $this->store->get_lock()->release_lock(
			array(
				'table' => (string) $wpdb->options,
				'name'  => $name,
				'token' => $token,
			)
		);
		if ( is_wp_error( $released ) ) {
			\WP_CLI::error( 'Lock was not released. Confirm the lock name and exact token.' );
		}

		if ( 'zmai_avatar_lock_migration' === $name ) {
			delete_option( Migration::RELEASE_FAILED_OPTION );
		}

		\WP_CLI::success( sprintf( 'Released lock %s.', $name ) );
	}

	/**
	 * Dispatch one import job transition and emit formatted output.
	 *
	 * @param string $operation  Transition name (pause, resume, retry, finish).
	 * @param array  $assoc_args Associative CLI flags.
	 * @return void
	 */
	private function run_job_transition( $operation, $assoc_args ) {
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			\WP_CLI::error( 'Format must be table or json.' );
		}

		$job = $this->migration->import_status();
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}
		if ( ! $job ) {
			\WP_CLI::error( 'No import job exists on this site.' );
		}

		$allowed_states = array(
			'pause'  => array( 'running' ),
			'resume' => array( 'paused' ),
			'retry'  => array( 'paused_with_failures' ),
			'finish' => array( 'paused', 'paused_with_failures' ),
		);
		$expected_state = array(
			'pause'  => 'paused',
			'resume' => 'running',
			'retry'  => 'running',
			'finish' => 'complete',
		);

		if ( ! isset( $allowed_states[ $operation ], $expected_state[ $operation ] ) || ! in_array( $job['state'], $allowed_states[ $operation ], true ) ) {
			\WP_CLI::error(
				sprintf(
					'Cannot %1$s job %2$s while it is in state "%3$s".',
					$operation,
					$job['id'],
					$job['state']
				)
			);
		}

		$method = $operation . '_import';
		$result = $this->migration->$method( $job['id'], $job['revision'] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( ! is_array( $result ) || (int) $result['revision'] !== (int) $job['revision'] + 1 || $result['state'] !== $expected_state[ $operation ] ) {
			\WP_CLI::error(
				sprintf(
					'Could not %1$s job %2$s; current state is "%3$s" (revision %4$d).',
					$operation,
					$job['id'],
					is_array( $result ) && isset( $result['state'] ) ? (string) $result['state'] : 'unknown',
					is_array( $result ) && isset( $result['revision'] ) ? (int) $result['revision'] : 0
				)
			);
		}

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $result ) );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Job %1$s is now in state "%2$s" (revision %3$d).',
				$result['id'],
				$result['state'],
				$result['revision']
			)
		);
	}
}
