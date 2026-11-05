<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Approved phase-2B filename is class-admin-import.php.
/**
 * Explicit administrator-only competitor imports with native POST fallback.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Users > Avatar Migration, with no import work on admin_init. */
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
		if ( ! current_user_can( 'manage_options' ) || is_network_admin() ) {
			return;
		}
		$hook = add_users_page( __( 'Avatar Migration', 'author-or-user-image' ), __( 'Avatar Migration', 'author-or-user-image' ), 'manage_options', 'author-or-user-image-import', array( $this, 'render_page' ) );
		add_action( 'load-' . $hook, array( $this, 'set_page_title' ) );
		$job = $this->migration->import_status();
		if ( is_wp_error( $job ) || ( $job && 'complete' !== $job['state'] ) || get_option( Migration::RELEASE_FAILED_OPTION ) ) {
			return;
		}
		if ( array() === ( new Import_Sources() )->detected_sources() ) {
			// Keep the page registered so Avatar Management and saved URLs can open it.
			remove_submenu_page( 'users.php', 'author-or-user-image-import' );
		}
	}

	/**
	 * Retain the document title even when the submenu entry is hidden.
	 *
	 * @return void
	 */
	public function set_page_title() {
		global $title;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core reads this screen title after the hidden submenu's load hook.
		$title = __( 'Avatar Migration', 'author-or-user-image' );
	}

	/**
	 * Load the progressive sequential driver only on this page.
	 *
	 * @param string $hook Current admin screen.
	 * @return void
	 */
	public function enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation selects the enhanced management tab.
		$management_tab = 'users_page_author-or-user-image-manage' === $hook && isset( $_GET['tab'] ) && 'migration' === $_GET['tab'];
		if ( ( 'users_page_author-or-user-image-import' === $hook || $management_tab ) && current_user_can( 'manage_options' ) ) {
			wp_enqueue_style( 'zmai-admin', plugins_url( 'assets/css/admin.css', ZMAI_PATH . 'author_image.php' ), array(), ZMAI_VERSION );
			wp_enqueue_script( 'zmai-import', plugins_url( 'assets/js/import.js', ZMAI_PATH . 'author_image.php' ), array(), ZMAI_VERSION, true );
			wp_localize_script( 'zmai-import', 'zmaiImportStates', $this->state_labels() );
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
		$url = admin_url( 'users.php?page=author-or-user-image-manage&tab=migration' );
		wp_safe_redirect( $failed ? add_query_arg( 'import_error', '1', $url ) : $url, 303 );
		exit;
	}

	/**
	 * Render persisted status and explicit native actions; a GET never imports.
	 *
	 * @param bool $embedded Render inside the Avatar Management tab.
	 * @return void
	 */
	public function render_page( $embedded = false ) {
		if ( ! current_user_can( 'manage_options' ) || is_network_admin() ) {
			wp_die( esc_html__( 'You cannot access this page.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}
		$job = $this->migration->import_status();
		echo '<div class="' . ( $embedded ? 'zmai-wrap zmai-migration' : 'wrap zmai-wrap zmai-migration' ) . '" data-zmai-import data-stop-label="' . esc_attr__( 'Stop', 'author-or-user-image' ) . '" data-error-label="' . esc_attr__( 'Import stopped. Reload this page before continuing. Retained locks may need administrator recovery.', 'author-or-user-image' ) . '">';
		if ( ! $embedded ) {
			echo '<h1>' . esc_html__( 'Avatar Migration', 'author-or-user-image' ) . '</h1>';
		}
		echo '<p>' . esc_html__( 'Bring over avatars from another plugin. Existing avatars, explicit removals and blocked users are preserved.', 'author-or-user-image' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A fixed flag renders a generic notice only, with no state mutation or reflected value.
		if ( isset( $_GET['import_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Import stopped. Review the stored progress and restore missing files locally before retrying. Retained locks require operator reconciliation.', 'author-or-user-image' ) . '</p></div>';
		}
		if ( is_wp_error( $job ) || get_option( Migration::RELEASE_FAILED_OPTION ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Import state is unavailable or needs recovery. Ask the administrator to reconcile its schema and retained locks. No import was started.', 'author-or-user-image' ) . '</p></div></div>';
			return;
		}
		if ( ! $job || 'complete' === $job['state'] ) {
			$this->render_sources( $job );
		}

		$states = $this->state_labels();
		$state  = $job ? $job['state'] : 'idle';
		echo '<section class="zmai-card" data-zmai-progress' . ( $job ? '' : ' hidden' ) . '>';
		echo '<div aria-live="polite" aria-atomic="true"><h2 data-zmai-state>' . esc_html( $states[ $state ] ) . '</h2>';
		$counts = $job ? $job['counts'] : array_fill_keys( array( 'inspected', 'imported', 'existing', 'suppressed', 'blocked', 'no_source', 'other_site', 'failed', 'skipped' ), 0 );
		$labels = array(
			'inspected'  => __( 'Users checked', 'author-or-user-image' ),
			'imported'   => __( 'Avatars imported', 'author-or-user-image' ),
			'existing'   => __( 'Existing', 'author-or-user-image' ),
			'suppressed' => __( 'Explicit choice or no longer a member', 'author-or-user-image' ),
			'blocked'    => __( 'Blocked', 'author-or-user-image' ),
			'no_source'  => __( 'No source or source removed', 'author-or-user-image' ),
			'other_site' => __( 'Source belongs to another site', 'author-or-user-image' ),
			'failed'     => __( 'Temporary failures; retry', 'author-or-user-image' ),
			'skipped'    => __( 'Unsupported or missing files; repair before a new import', 'author-or-user-image' ),
		);
		echo '<dl class="zmai-migration-counts">';
		foreach ( array( 'inspected', 'imported' ) as $key ) {
			$this->render_count( $key, $labels[ $key ], $counts[ $key ] );
		}
		echo '</dl></div>';
		if ( 'complete' === $state && $counts['inspected'] > 0 && $counts['inspected'] === $counts['no_source'] ) {
			echo '<p>' . esc_html__( 'No source image was available for any of the checked users.', 'author-or-user-image' ) . '</p>';
		}
		echo '<details><summary>' . esc_html__( 'Detailed breakdown', 'author-or-user-image' ) . '</summary><dl class="zmai-migration-counts">';
		foreach ( $labels as $key => $label ) {
			if ( ! in_array( $key, array( 'inspected', 'imported' ), true ) ) {
				$this->render_count( $key, $label, $counts[ $key ] );
			}
		}
		echo '</dl></details>';
		if ( $job && $job['skips'] ) {
			echo '<h2>' . esc_html__( 'Skipped users', 'author-or-user-image' ) . '</h2><p>' . esc_html__( 'The first 100 skipped users and reason codes are listed below. Repair their source files, then start a new import. Counts include all skipped users.', 'author-or-user-image' ) . '</p><ul data-zmai-skips>';
			foreach ( $job['skips'] as $skip ) {
				/* translators: 1: numeric user ID, 2: fixed reason code. */
				echo '<li>' . esc_html( sprintf( __( 'User %1$d: %2$s', 'author-or-user-image' ), $skip['user_id'], $skip['reason'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $job && 'complete' !== $job['state'] ) {
			echo '<p>' . esc_html( Import_Sources::registry()[ $job['source'] ] ) . '</p>';
			echo '<p>' . esc_html__( 'Closing this page stops further batches. Reopen it to continue or resume.', 'author-or-user-image' ) . '</p><div class="zmai-migration-actions">';
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
			echo '</div>';
		}
		echo '</section><p data-zmai-message role="status"></p></div>';
	}

	/**
	 * Render only detected sources, with one explicit action per source.
	 *
	 * @param array|null $job Persisted job.
	 * @return void
	 */
	private function render_sources( $job ) {
		$sources = ( new Import_Sources() )->detected_sources();
		echo '<section class="zmai-card">';
		if ( ! $sources || is_wp_error( $sources ) ) {
			echo '<h2>' . esc_html__( 'Import sources', 'author-or-user-image' ) . '</h2>';
		} else {
			echo '<h2>' . esc_html( $job ? __( 'Start another import', 'author-or-user-image' ) : __( 'Import from another plugin', 'author-or-user-image' ) ) . '</h2>';
		}
		if ( is_wp_error( $sources ) ) {
			echo '<p>' . esc_html__( 'Could not check for avatar sources. Reload this page to try again.', 'author-or-user-image' ) . '</p>';
		} elseif ( ! $sources ) {
			echo '<p>' . esc_html__( 'No supported avatar plugin or saved avatar records were detected for this site.', 'author-or-user-image' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Available sources are listed below. Local files are checked during import.', 'author-or-user-image' ) . '</p><div class="zmai-migration-actions">';
			foreach ( $sources as $source => $name ) {
				/* translators: %s: source plugin name. */
				$this->form( 'start', $job, sprintf( __( 'Import from %s', 'author-or-user-image' ), $name ), $source );
			}
			echo '</div><details><summary>' . esc_html__( 'What gets migrated?', 'author-or-user-image' ) . '</summary>';
			echo '<p>' . esc_html__( 'Only local avatar files are copied, which uses additional disk space. The first imported fallback wins. Source files, metadata and settings stay in place.', 'author-or-user-image' ) . '</p>';
			echo '<p>' . esc_html__( 'Avatar ratings, defaults and override preferences stay with the source plugin. If it still overrides displayed avatars, review the results before disabling its override. Removing an imported avatar leaves the source data in place.', 'author-or-user-image' ) . '</p></details>';
		}
		echo '</section>';
	}

	/**
	 * Render a labeled counter for native and enhanced progress.
	 *
	 * @param string $key Counter identifier.
	 * @param string $label Translated label.
	 * @param int    $count Current count.
	 * @return void
	 */
	private function render_count( $key, $label, $count ) {
		echo '<div><dt>' . esc_html( $label ) . '</dt><dd data-zmai-count="' . esc_attr( $key ) . '">' . esc_html( (string) $count ) . '</dd></div>';
	}

	/**
	 * Labels shared by the server and the progressive driver.
	 *
	 * @return array<string,string>
	 */
	private function state_labels() {
		return array(
			'idle'                 => __( 'Ready to import', 'author-or-user-image' ),
			'running'              => __( 'Import in progress', 'author-or-user-image' ),
			'paused'               => __( 'Import paused', 'author-or-user-image' ),
			'paused_with_failures' => __( 'Import needs attention', 'author-or-user-image' ),
			'complete'             => __( 'Import complete', 'author-or-user-image' ),
			'recovery_required'    => __( 'Recovery required', 'author-or-user-image' ),
		);
	}

	/**
	 * Native forms remain usable without JavaScript.
	 *
	 * @param string     $operation Fixed action.
	 * @param array|null $job Persisted job.
	 * @param string     $label Translated button label.
	 * @param string     $source Detected source for a new import.
	 * @return void
	 */
	private function form( $operation, $job, $label, $source = '' ) {
		$id = $job ? $job['id'] : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-zmai-operation="' . esc_attr( $operation ) . '">';
		echo '<input type="hidden" name="action" value="zmai_import_' . esc_attr( $operation ) . '"><input type="hidden" name="site_id" value="' . esc_attr( (string) get_current_blog_id() ) . '">';
		echo '<input type="hidden" name="job_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="revision" value="' . esc_attr( $job ? (string) $job['revision'] : '0' ) . '">';
		wp_nonce_field( 'zmai_import_' . $operation . ( 'start' === $operation ? '' : '_' . $id ) );
		if ( 'start' === $operation ) {
			echo '<input type="hidden" name="run_token" value="' . esc_attr( wp_generate_uuid4() ) . '"><input type="hidden" name="source" value="' . esc_attr( $source ) . '">';
		}
		submit_button( $label, 'pause' === $operation || 'finish' === $operation ? 'secondary' : 'primary', 'zmai-' . $operation . '-' . $source, false );
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
