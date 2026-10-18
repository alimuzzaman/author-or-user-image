<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Approved phase-2B filename is class-admin-import.php.
/**
 * Explicit administrator-only competitor imports with native POST fallback.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Users > Import avatars, with no import work on admin_init. */
final class Import_Admin {
	/**
	 * Explicit job coordinator.
	 *
	 * @var Migration
	 */
	private $migration;

	/**
	 * Construct without registering hooks; the integrator registers this service.
	 *
	 * @param Migration $migration Shared coordinator.
	 */
	public function __construct( Migration $migration ) {
		$this->migration = $migration;
	}

	/**
	 * Register fixed handlers and the site-admin page, never a background scanner.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		foreach ( array( 'start', 'batch', 'pause', 'resume', 'retry', 'finish' ) as $operation ) {
			add_action(
				'admin_post_zmai_import_' . $operation,
				function () use ( $operation ) {
					$this->handle_post( $operation );
				}
			);
		}
	}

	/**
	 * Add only a current-site management page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_users_page( __( 'Import avatars', 'author-or-user-image' ), __( 'Import avatars', 'author-or-user-image' ), 'manage_options', 'author-or-user-image-import', array( $this, 'render_page' ) );
	}

	/**
	 * Load the progressive sequential driver only on this page.
	 *
	 * @param string $hook Current admin screen.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'users_page_author-or-user-image-import' === $hook && current_user_can( 'manage_options' ) ) {
			wp_enqueue_script( 'zmai-import', plugins_url( 'assets/js/import.js', ZMAI_PATH . 'author_image.php' ), array(), ZMAI_VERSION, true );
		}
	}

	/**
	 * Testable permission boundary for native and enhanced requests.
	 *
	 * @param string $operation Fixed operation name.
	 * @param array  $request Unslashed request fields.
	 * @param string $method HTTP method.
	 * @return array|null|WP_Error
	 */
	public function process_request( $operation, array $request, $method ) {
		if ( ! current_user_can( 'manage_options' ) || is_network_admin() ) {
			return $this->request_error( 'forbidden' );
		}
		$site_id = $this->integer( $request['site_id'] ?? null );
		if ( get_current_blog_id() !== $site_id ) {
			return $this->request_error( 'request' );
		}
		if ( 'status' === $operation && 'GET' === $method ) {
			return $this->migration->import_status();
		}
		if ( 'POST' !== $method || ! in_array( $operation, array( 'start', 'batch', 'pause', 'resume', 'retry', 'finish' ), true ) ) {
			return $this->request_error( 'request' );
		}
		$id = $request['job_id'] ?? '';
		if ( ! is_string( $id ) || ( 'start' !== $operation && ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) ) {
			return $this->request_error( 'request' );
		}
		$nonce  = $request['_wpnonce'] ?? '';
		$action = 'zmai_import_' . $operation . ( 'start' === $operation ? '' : '_' . $id );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, $action ) ) {
			return $this->request_error( 'nonce' );
		}
		$revision = $this->integer( $request['revision'] ?? null );
		if ( null === $revision ) {
			return $this->request_error( 'request' );
		}
		if ( 'start' === $operation ) {
			return $this->migration->start_import( $request['source'] ?? null, $request['run_token'] ?? null, $revision );
		}
		switch ( $operation ) {
			case 'batch':
				return $this->migration->run_import_batch( $id, $revision );
			case 'pause':
				return $this->migration->pause_import( $id, $revision );
			case 'resume':
				return $this->migration->resume_import( $id, $revision );
			case 'retry':
				return $this->migration->retry_import( $id, $revision );
			case 'finish':
				return $this->migration->finish_import( $id, $revision );
		}
		return $this->request_error( 'request' );
	}

	/**
	 * Handle native forms or return bounded JSON; errors never include source data.
	 *
	 * @param string $operation Registered handler operation.
	 * @return void
	 */
	private function handle_post( $operation ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- process_request verifies capability, method, site and purpose-specific nonce before any mutation.
		$request = wp_unslash( $_POST );
		$result  = $this->process_request( $operation, $request, isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' );
		$failed  = is_wp_error( $result );
		if ( isset( $request['format'] ) && 'json' === $request['format'] ) {
			if ( $failed ) {
				wp_send_json_error( array( 'message' => __( 'Import stopped. Reload the page before continuing. Retained locks may need administrator recovery.', 'author-or-user-image' ) ), 'zmai_import_forbidden' === $result->get_error_code() ? 403 : 409 );
			}
			wp_send_json_success(
				array(
					'job'    => $result,
					'nonces' => $this->nonces( $result['id'] ),
				)
			);
		}
		if ( $failed && in_array( $result->get_error_code(), array( 'zmai_import_forbidden', 'zmai_import_nonce', 'zmai_import_request' ), true ) ) {
			wp_die( esc_html__( 'You cannot run this import request.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}
		$url = admin_url( 'users.php?page=author-or-user-image-import' );
		wp_safe_redirect( $failed ? add_query_arg( 'import_error', '1', $url ) : $url, 303 );
		exit;
	}

	/**
	 * Render persisted status and explicit native actions; a GET never imports.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) || is_network_admin() ) {
			wp_die( esc_html__( 'You cannot access this page.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}
		$job = $this->migration->import_status();
		echo '<div class="wrap" data-zmai-import data-stop-label="' . esc_attr__( 'Stop', 'author-or-user-image' ) . '" data-error-label="' . esc_attr__( 'Import stopped. Reload this page before continuing. Retained locks may need administrator recovery.', 'author-or-user-image' ) . '"><h1>' . esc_html__( 'Import avatars', 'author-or-user-image' ) . '</h1>';
		echo '<p>' . esc_html__( 'Import one source at a time. The first imported fallback wins; existing avatars, explicit removals and blocked users are preserved. Only local uploads files are supported. Source metadata, settings and files are never changed.', 'author-or-user-image' ) . '</p>';
		echo '<p>' . esc_html__( 'Imports copy files and use additional disk space. Avatar ratings, defaults and override preferences are not migrated. Other plugins may continue overriding displayed avatars. Review results, then manually disable their avatar override if needed. Erasing an imported image does not erase the source plugin data.', 'author-or-user-image' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A fixed flag renders a generic notice only, with no state mutation or reflected value.
		if ( isset( $_GET['import_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Import stopped. Review the stored progress and restore missing files locally before retrying. Retained locks require operator reconciliation.', 'author-or-user-image' ) . '</p></div>';
		}
		if ( is_wp_error( $job ) || get_option( Migration::RELEASE_FAILED_OPTION ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Import state is unavailable or needs recovery. Ask the administrator to reconcile its schema and retained locks. No import was started.', 'author-or-user-image' ) . '</p></div></div>';
			return;
		}
		echo '<div aria-live="polite" aria-atomic="true" data-zmai-progress><p>' . esc_html__( 'State:', 'author-or-user-image' ) . ' <strong data-zmai-state>' . esc_html( $job ? $job['state'] : 'idle' ) . '</strong></p>';
		$counts = $job ? $job['counts'] : array_fill_keys( array( 'inspected', 'imported', 'existing', 'suppressed', 'blocked', 'no_source', 'other_site', 'failed', 'skipped' ), 0 );
		$labels = array(
			'inspected'  => __( 'Inspected', 'author-or-user-image' ),
			'imported'   => __( 'Imported', 'author-or-user-image' ),
			'existing'   => __( 'Existing', 'author-or-user-image' ),
			'suppressed' => __( 'Explicit choice or no longer a member', 'author-or-user-image' ),
			'blocked'    => __( 'Blocked', 'author-or-user-image' ),
			'no_source'  => __( 'No source or source removed', 'author-or-user-image' ),
			'other_site' => __( 'Source belongs to another site', 'author-or-user-image' ),
			'failed'     => __( 'Temporary failures; retry', 'author-or-user-image' ),
			'skipped'    => __( 'Unsupported or missing files; repair before a new import', 'author-or-user-image' ),
		);
		echo '<ul>';
		foreach ( $counts as $key => $count ) {
			echo '<li>' . esc_html( $labels[ $key ] ) . ': <span data-zmai-count="' . esc_attr( $key ) . '">' . esc_html( (string) $count ) . '</span></li>';
		}
		echo '</ul><p data-zmai-message>' . esc_html__( 'Work runs only after an explicit action on this page. Closing the page stops further batches. Reopen and choose Continue or Resume.', 'author-or-user-image' ) . '</p></div>';
		if ( $job && $job['skips'] ) {
			echo '<h2>' . esc_html__( 'Skipped users', 'author-or-user-image' ) . '</h2><p>' . esc_html__( 'The first 100 skipped users and reason codes are listed below. Repair their source files, then start a new import. Counts include all skipped users.', 'author-or-user-image' ) . '</p><ul data-zmai-skips>';
			foreach ( $job['skips'] as $skip ) {
				/* translators: 1: numeric user ID, 2: fixed reason code. */
				echo '<li>' . esc_html( sprintf( __( 'User %1$d: %2$s', 'author-or-user-image' ), $skip['user_id'], $skip['reason'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! $job || 'complete' === $job['state'] ) {
			$this->form( 'start', $job, __( 'Import avatars', 'author-or-user-image' ) );
		} else {
			echo '<p>' . esc_html( Import_Sources::registry()[ $job['source'] ] ) . '</p>';
			if ( 'running' === $job['state'] ) {
				$this->form( 'batch', $job, __( 'Continue', 'author-or-user-image' ) );
				$this->form( 'pause', $job, __( 'Stop', 'author-or-user-image' ) );
			} elseif ( 'paused' === $job['state'] ) {
				$this->form( 'resume', $job, __( 'Resume', 'author-or-user-image' ) );
				$this->form( 'finish', $job, __( 'Finish this import', 'author-or-user-image' ) );
			} elseif ( 'paused_with_failures' === $job['state'] ) {
				$this->form( 'retry', $job, __( 'Retry repaired sources', 'author-or-user-image' ) );
				$this->form( 'finish', $job, __( 'Finish this import', 'author-or-user-image' ) );
			} else {
				echo '<p>' . esc_html__( 'Administrator recovery is required. Do not remove a live or unknown worker lock. See the lifecycle recovery guide.', 'author-or-user-image' ) . '</p>';
			}
		}
		echo '</div>';
	}

	/**
	 * Native forms remain usable without JavaScript.
	 *
	 * @param string     $operation Fixed action.
	 * @param array|null $job Persisted job.
	 * @param string     $label Translated button label.
	 * @return void
	 */
	private function form( $operation, $job, $label ) {
		$id = $job ? $job['id'] : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-zmai-operation="' . esc_attr( $operation ) . '">';
		echo '<input type="hidden" name="action" value="zmai_import_' . esc_attr( $operation ) . '"><input type="hidden" name="site_id" value="' . esc_attr( (string) get_current_blog_id() ) . '">';
		echo '<input type="hidden" name="job_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="revision" value="' . esc_attr( $job ? (string) $job['revision'] : '0' ) . '">';
		wp_nonce_field( 'zmai_import_' . $operation . ( 'start' === $operation ? '' : '_' . $id ) );
		if ( 'start' === $operation ) {
			echo '<input type="hidden" name="run_token" value="' . esc_attr( wp_generate_uuid4() ) . '"><p><label for="zmai-import-source">' . esc_html__( 'Source plugin', 'author-or-user-image' ) . '</label> <select name="source" id="zmai-import-source">';
			foreach ( Import_Sources::registry() as $source => $name ) {
				echo '<option value="' . esc_attr( $source ) . '">' . esc_html( $name ) . '</option>';
			}
			echo '</select></p>';
		}
		submit_button( $label, 'pause' === $operation ? 'secondary' : 'primary' );
		echo '</form>';
	}

	/**
	 * Nonces are bound to purpose and job, and contain no personal data.
	 *
	 * @param string $id Job UUID.
	 * @return array<string,string>
	 */
	private function nonces( $id ) {
		$result = array();
		foreach ( array( 'batch', 'pause', 'resume', 'retry', 'finish' ) as $operation ) {
			$result[ $operation ] = wp_create_nonce( 'zmai_import_' . $operation . '_' . $id );
		}
		return $result;
	}

	/**
	 * Require canonical nonnegative decimal integers without coercing arrays.
	 *
	 * @param mixed $value Request field.
	 * @return int|null
	 */
	private function integer( $value ) {
		if ( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) && (string) (int) $value === $value ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Uniform request failure.
	 *
	 * @param string $code Fixed category.
	 * @return WP_Error
	 */
	private function request_error( $code ) {
		return new WP_Error( 'zmai_import_' . $code, __( 'You cannot run this import request.', 'author-or-user-image' ) );
	}
}
