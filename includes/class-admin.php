<?php
/**
 * Administration screens and form handlers.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

defined( 'ABSPATH' ) || exit;

/**
 * Provides self-service and administrator avatar controls.
 */
final class Admin {
	/**
	 * Image storage service.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Shared self-service policy.
	 *
	 * @var Upload_Policy
	 */
	private $policy;

	/**
	 * Register admin hooks.
	 *
	 * @param Image_Store $store Image storage service.
	 */
	public function __construct( Image_Store $store ) {
		$this->store  = $store;
		$this->policy = new Upload_Policy( $store );

		add_action( 'admin_notices', array( $this, 'policy_notice' ) );
		add_action( 'admin_menu', array( $this, 'register_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_zmai_save_avatar', array( $this, 'save_avatar' ) );
		add_action( 'admin_post_zmai_remove_avatar', array( $this, 'remove_avatar' ) );
		add_action( 'admin_post_zmai_manage_users', array( $this, 'manage_users' ) );
		add_action( 'admin_post_zmai_save_settings', array( $this, 'save_settings' ) );
	}

	/**
	 * Register pages below Users.
	 *
	 * @return void
	 */
	public function register_pages() {
		add_users_page(
			__( 'Author or User Image', 'author-or-user-image' ),
			__( 'Author or User Image', 'author-or-user-image' ),
			'read',
			'author-or-user-image',
			array( $this, 'render_avatar_page' )
		);

		add_users_page(
			__( 'Avatar Management', 'author-or-user-image' ),
			__( 'Avatar Management', 'author-or-user-image' ),
			'manage_options',
			'author-or-user-image-manage',
			array( $this, 'render_management_page' )
		);
	}

	/**
	 * Load plugin assets only on plugin pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'users_page_author-or-user-image', 'profile_page_author-or-user-image', 'users_page_author-or-user-image-manage', 'profile_page_author-or-user-image-manage' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'zmai-admin', ZMAI_URL . 'assets/css/admin.css', array(), ZMAI_VERSION );
		wp_enqueue_script( 'zmai-admin', ZMAI_URL . 'assets/js/admin.js', array(), ZMAI_VERSION, true );
		wp_localize_script(
			'zmai-admin',
			'zmaiAdmin',
			array(
				'confirmDelete' => __( 'Remove the selected avatar images? This cannot be undone.', 'author-or-user-image' ),
				'confirmBlock'  => __( 'Block the selected users and remove their avatar images?', 'author-or-user-image' ),
			)
		);
	}

	/**
	 * Render the current user's avatar page.
	 *
	 * @return void
	 */
	public function render_avatar_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}

		$user_id = get_current_user_id();
		$blocked = $this->store->is_blocked( $user_id );
		?>
		<div class="wrap zmai-wrap">
			<h1><?php esc_html_e( 'Author or User Image', 'author-or-user-image' ); ?></h1>
			<?php $this->render_status_notice(); ?>

			<?php if ( $blocked ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'An administrator has disabled local avatar uploads for your account.', 'author-or-user-image' ); ?></p></div>
			<?php else : ?>
				<div class="zmai-card">
					<h2><?php esc_html_e( 'Your avatar', 'author-or-user-image' ); ?></h2>
					<?php $this->render_avatar_preview( $user_id, false ); ?>
					<?php if ( $this->policy->can_upload( $user_id ) ) : ?>
						<?php $this->render_upload_form( 'user_avatar', false ); ?>
					<?php else : ?>
						<p><?php esc_html_e( 'Your role is not allowed to upload a new avatar on this site. You can still remove your avatar.', 'author-or-user-image' ); ?></p>
					<?php endif; ?>
					<?php if ( $this->store->get_url( $user_id, $this->store->get_size(), false ) ) : ?>
						<?php $this->render_remove_form( 'user' ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<div class="zmai-card">
					<h2><?php esc_html_e( 'Site default avatar', 'author-or-user-image' ); ?></h2>
					<p><?php esc_html_e( 'This image is used when a registered user has not uploaded one.', 'author-or-user-image' ); ?></p>
					<?php $this->render_avatar_preview( 0, true ); ?>
					<?php $this->render_upload_form( 'default_avatar', true ); ?>
					<?php if ( $this->store->get_url( 0, $this->store->get_size() ) ) : ?>
						<?php $this->render_remove_form( 'default' ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render an avatar preview.
	 *
	 * @param int  $user_id   User ID.
	 * @param bool $is_default Whether this is the default avatar.
	 * @return void
	 */
	private function render_avatar_preview( $user_id, $is_default ) {
		$size = $this->store->get_size();
		$url  = $is_default ? $this->store->get_url( 0, $size ) : $this->store->get_url( $user_id, $size, false );

		if ( $url ) {
			printf(
				'<img class="zmai-avatar-preview" src="%1$s" alt="%2$s" width="%3$d" height="%3$d">',
				esc_url( $url ),
				esc_attr__( 'Current avatar image', 'author-or-user-image' ),
				absint( $size )
			);
		} else {
			echo '<p class="description">' . esc_html__( 'No image is set.', 'author-or-user-image' ) . '</p>';
		}
	}

	/**
	 * Render an upload form.
	 *
	 * @param string $field      Input name.
	 * @param bool   $is_default Whether this is the default avatar.
	 * @return void
	 */
	private function render_upload_form( $field, $is_default ) {
		?>
		<form class="zmai-form" method="post" action="<?php echo esc_url( add_query_arg( 'action', 'zmai_save_avatar', admin_url( 'admin-post.php' ) ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="zmai_save_avatar">
			<input type="hidden" name="avatar_type" value="<?php echo $is_default ? 'default' : 'user'; ?>">
			<?php wp_nonce_field( 'zmai_save_avatar' ); ?>
			<label for="<?php echo esc_attr( $field ); ?>"><?php esc_html_e( 'Choose an image', 'author-or-user-image' ); ?></label>
			<p class="description"><?php esc_html_e( 'Use a JPEG, PNG or static WebP up to 4096 by 4096 pixels and the smaller of this site’s upload limit or 8 MiB. New avatars use a centered square without upscaling. Uncropped originals remain in the Media Library. JPEG processing requires server EXIF support.', 'author-or-user-image' ); ?></p>
			<input id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" type="file" accept="image/jpeg,image/png,image/webp" required>
			<?php submit_button( __( 'Upload image', 'author-or-user-image' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render an avatar removal form.
	 *
	 * @param string $type Avatar type.
	 * @return void
	 */
	private function render_remove_form( $type ) {
		?>
		<form class="zmai-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zmai_remove_avatar">
			<input type="hidden" name="avatar_type" value="<?php echo esc_attr( $type ); ?>">
			<?php wp_nonce_field( 'zmai_remove_avatar' ); ?>
			<?php submit_button( __( 'Remove image', 'author-or-user-image' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Process an avatar upload.
	 *
	 * @return void
	 */
	public function save_avatar() {
		$this->reject_oversized_upload();
		$type = $this->validate_avatar_request( 'zmai_save_avatar' );

		$user_id = get_current_user_id();
		if ( 'default' === $type ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to change the default avatar.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
			}
			$result = $this->store->save_upload( 'default_avatar', 0 );
		} else {
			if ( ! $this->policy->can_upload( $user_id ) ) {
				if ( $this->is_front_request() ) {
					$this->redirect( 'denied' );
				}
				wp_die( esc_html__( 'Local avatar uploads are disabled for your account.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
			}
			$result = $this->store->save_upload( 'user_avatar', $user_id );
		}

		if ( is_wp_error( $result ) ) {
			if ( $this->is_front_request() && 'zmai_rate_limited' === $result->get_error_code() ) {
				$this->redirect( 'rate-limited' );
			}
			$this->redirect( 'upload-error', $result->get_error_message() );
		}
		$this->redirect( 'uploaded' );
	}

	/**
	 * Process an avatar removal.
	 *
	 * @return void
	 */
	public function remove_avatar() {
		$type = $this->validate_avatar_request( 'zmai_remove_avatar' );

		if ( 'default' === $type ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to change the default avatar.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
			}
			$result = $this->store->delete_default_avatar();
		} else {
			$result = $this->store->delete_user_avatar( get_current_user_id() );
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'remove-error', $result->get_error_message() );
		}
		$this->redirect( 'removed' );
	}

	/**
	 * Refuse a body PHP discarded before nonce validation, without changing data.
	 * Upload form URLs retain their action and safe return hint when POST is empty.
	 *
	 * @return void
	 */
	private function reject_oversized_upload() {
		$limit  = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detect PHP's discarded body; no submitted data is processed and no state is changed.
		if ( ! current_user_can( 'read' ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || $_POST || $_FILES || $limit <= 0 || ! ctype_digit( $length ) || (float) $length <= $limit ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PHP discarded the nonce with the oversized body. This branch only redirects a refused request and never mutates data.
		$front = isset( $_GET['zmai_context'] ) && 'front' === $_GET['zmai_context'];
		if ( $front ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Refusal only; strict same-origin/path validation follows.
			$return = isset( $_GET['zmai_return'] ) ? wp_unslash( $_GET['zmai_return'] ) : '';
			wp_safe_redirect( add_query_arg( 'zmai_status', 'upload-too-large', Front_End::safe_return_url( $return ) ), 303 );
			exit;
		}
		$this->redirect( 'upload-error', __( 'The upload exceeds the server request limit. Choose a smaller image.', 'author-or-user-image' ) );
	}

	/**
	 * Recognize the strictly validated front-end context.
	 *
	 * @return bool
	 */
	private function is_front_request() {
		return isset( $_POST['zmai_context'] ) && 'front' === $_POST['zmai_context']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only in nonce-verified handlers and their redirect.
	}

	/**
	 * Reject malformed transport before touching avatar storage.
	 *
	 * @param string $action Nonce action.
	 * @return string Strict avatar type.
	 */
	private function validate_avatar_request( $action ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'A POST request is required.', 'author-or-user-image' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $action ) ) {
			wp_die( esc_html__( 'The security check failed. Reload the page and try again.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_POST['avatar_type'] ) || ! is_string( $_POST['avatar_type'] ) || ! in_array( $_POST['avatar_type'], array( 'user', 'default' ), true ) || ( isset( $_POST['zmai_context'] ) && 'front' !== $_POST['zmai_context'] ) || ( $this->is_front_request() && ( 'user' !== $_POST['avatar_type'] || ( isset( $_POST['zmai_return'] ) && ! is_string( $_POST['zmai_return'] ) ) ) ) ) {
			wp_die( esc_html__( 'The avatar request is invalid.', 'author-or-user-image' ), '', array( 'response' => 400 ) );
		}
		return wp_unslash( $_POST['avatar_type'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact allowlist validation above, no normalization of malformed values.
	}

	/**
	 * Render the management page and its tabs.
	 *
	 * @return void
	 */
	public function render_management_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'images'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection; state changes require a separate nonce-verified POST.
		if ( ! in_array( $tab, array( 'images', 'blocked', 'settings' ), true ) ) {
			$tab = 'images';
		}
		?>
		<div class="wrap zmai-wrap">
			<h1><?php esc_html_e( 'Avatar Management', 'author-or-user-image' ); ?></h1>
			<?php $this->render_status_notice(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Avatar management sections', 'author-or-user-image' ); ?>">
				<?php $this->render_tab_link( 'images', __( 'User images', 'author-or-user-image' ), $tab ); ?>
				<?php $this->render_tab_link( 'blocked', __( 'Blocked users', 'author-or-user-image' ), $tab ); ?>
				<?php $this->render_tab_link( 'settings', __( 'Settings', 'author-or-user-image' ), $tab ); ?>
			</nav>
			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings();
			} else {
				$this->render_user_table( $tab );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render a tab link.
	 *
	 * @param string $tab     Tab slug.
	 * @param string $label   Tab label.
	 * @param string $current Current tab.
	 * @return void
	 */
	private function render_tab_link( $tab, $label, $current ) {
		$url = add_query_arg(
			array(
				'page' => 'author-or-user-image-manage',
				'tab'  => $tab,
			),
			admin_url( 'users.php' )
		);
		printf(
			'<a class="nav-tab %1$s" href="%2$s">%3$s</a>',
			$current === $tab ? 'nav-tab-active' : '',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Render a paginated user table.
	 *
	 * @param string $tab Current table type.
	 * @return void
	 */
	private function render_user_table( $tab ) {
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination value.
		$args  = array(
			'number'      => 50,
			'offset'      => ( $paged - 1 ) * 50,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => true,
		);
		if ( 'blocked' === $tab ) {
			$args['meta_key']   = Image_Store::user_meta_key( Image_Store::BLOCKED_META ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for an admin-only filtered list.
			$args['meta_value'] = 1; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for an admin-only filtered list.
		} else {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for an admin-only filtered list.
				'relation' => 'OR',
				array(
					'key'     => Image_Store::user_meta_key( Image_Store::USER_META ),
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => Image_Store::user_meta_key( Image_Store::MIGRATED_USER_META ),
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			);
		}

		$query = new \WP_User_Query( $args );
		$users = $query->get_results();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zmai_manage_users">
			<input type="hidden" name="return_tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'zmai_manage_users' ); ?>
			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label class="screen-reader-text" for="zmai-bulk-action"><?php esc_html_e( 'Bulk action', 'author-or-user-image' ); ?></label>
					<select id="zmai-bulk-action" name="user_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'author-or-user-image' ); ?></option>
						<?php if ( 'blocked' === $tab ) : ?>
							<option value="unblock"><?php esc_html_e( 'Unblock', 'author-or-user-image' ); ?></option>
						<?php else : ?>
							<option value="delete"><?php esc_html_e( 'Remove images', 'author-or-user-image' ); ?></option>
							<option value="block"><?php esc_html_e( 'Block and remove images', 'author-or-user-image' ); ?></option>
						<?php endif; ?>
					</select>
					<?php submit_button( __( 'Apply', 'author-or-user-image' ), 'secondary', 'submit', false ); ?>
				</div>
				<?php $this->render_pagination( $query->get_total(), $paged, $tab ); ?>
			</div>
			<table class="wp-list-table widefat fixed striped table-view-list users">
				<thead><tr><td class="manage-column column-cb check-column"><input id="cb-select-all-1" type="checkbox"><label for="cb-select-all-1"><span class="screen-reader-text"><?php esc_html_e( 'Select all', 'author-or-user-image' ); ?></span></label></td><th scope="col"><?php esc_html_e( 'User', 'author-or-user-image' ); ?></th><th scope="col"><?php esc_html_e( 'Email', 'author-or-user-image' ); ?></th><th scope="col"><?php esc_html_e( 'Avatar', 'author-or-user-image' ); ?></th></tr></thead>
				<tbody>
				<?php if ( $users ) : ?>
					<?php foreach ( $users as $user ) : ?>
						<tr>
							<?php /* translators: %s: user display name. */ ?>
							<th scope="row" class="check-column"><input id="user-<?php echo esc_attr( $user->ID ); ?>" name="user_ids[]" type="checkbox" value="<?php echo esc_attr( $user->ID ); ?>"><label for="user-<?php echo esc_attr( $user->ID ); ?>"><span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Select %s', 'author-or-user-image' ), $user->display_name ) ); ?></span></label></th>
							<td><strong><?php echo esc_html( $user->display_name ); ?></strong><br><span class="description"><?php echo esc_html( $user->user_login ); ?></span></td>
							<td><?php echo esc_html( $user->user_email ); ?></td>
							<td><?php echo get_avatar( $user->ID, 48, '', $user->display_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns safe core-generated markup. ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="4"><?php echo 'blocked' === $tab ? esc_html__( 'No users are blocked.', 'author-or-user-image' ) : esc_html__( 'No user images were found.', 'author-or-user-image' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</form>
		<?php
	}

	/**
	 * Render table pagination.
	 *
	 * @param int    $total Total matching users.
	 * @param int    $paged Current page.
	 * @param string $tab   Current tab.
	 * @return void
	 */
	private function render_pagination( $total, $paged, $tab ) {
		$total_pages = (int) ceil( $total / 50 );
		if ( $total_pages < 2 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page'  => 'author-or-user-image-manage',
				'tab'   => $tab,
				'paged' => '%#%',
			),
			admin_url( 'users.php' )
		);
		echo '<div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => $base,
					'format'  => '',
					'current' => $paged,
					'total'   => $total_pages,
				)
			)
		);
		echo '</div>';
	}

	/**
	 * Process administrator bulk actions.
	 *
	 * @return void
	 */
	public function manage_users() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'zmai_manage_users' );

		$action     = isset( $_POST['user_action'] ) ? sanitize_key( wp_unslash( $_POST['user_action'] ) ) : '';
		$return_tab = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'images';
		$raw_ids    = isset( $_POST['user_ids'] ) && is_array( $_POST['user_ids'] ) ? wp_unslash( $_POST['user_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Converted to absolute integers below.
		$user_ids   = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
		if ( ! in_array( $action, array( 'delete', 'block', 'unblock' ), true ) || ! $user_ids ) {
			$this->redirect_manage( 'nothing-selected', $return_tab );
		}

		foreach ( $user_ids as $user_id ) {
			if ( ! get_userdata( $user_id ) ) {
				continue;
			}
			if ( 'delete' === $action ) {
				$result = $this->store->delete_user_avatar( $user_id );
			} else {
				$result = $this->store->set_blocked( $user_id, 'block' === $action );
			}
			if ( is_wp_error( $result ) ) {
				$this->redirect_manage( 'operation-error', $return_tab );
			}
		}

		$this->redirect_manage( 'users-updated', $return_tab );
	}

	/**
	 * Render plugin settings.
	 *
	 * @return void
	 */
	private function render_settings() {
		$policy      = $this->policy->get_policy();
		$delete_data = (bool) get_option( Image_Store::DELETE_DATA_OPTION, false );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zmai_save_settings">
			<?php wp_nonce_field( 'zmai_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Who can upload avatars', 'author-or-user-image' ); ?></th>
					<td>
						<label><input type="radio" name="upload_policy_mode" value="legacy" <?php checked( 'legacy', $policy['mode'] ); ?>> <?php esc_html_e( 'All users who can read', 'author-or-user-image' ); ?></label><br>
						<label><input type="radio" name="upload_policy_mode" value="roles" <?php checked( 'roles', $policy['mode'] ); ?>> <?php esc_html_e( 'Selected roles only', 'author-or-user-image' ); ?></label>
						<?php foreach ( wp_roles()->roles as $slug => $role ) : ?>
							<br><label><input type="checkbox" name="upload_policy_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $policy['roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'An empty selected list disables uploads. Existing avatars and removal remain available. Individual blocks always win. Recreating a removed role with the same slug restores its match.', 'author-or-user-image' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="zmai-avatar-size"><?php esc_html_e( 'Avatar image size', 'author-or-user-image' ); ?></label></th>
					<td><input id="zmai-avatar-size" name="avatar_size" type="number" class="small-text" min="32" max="1024" value="<?php echo esc_attr( $this->store->get_size() ); ?>"> <span><?php esc_html_e( 'pixels square', 'author-or-user-image' ); ?></span><p class="description"><?php esc_html_e( 'New uploads receive a centered square at this maximum size, without upscaling. Existing avatars are not regenerated. Uncropped originals and their metadata remain available in the Media Library.', 'author-or-user-image' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall behavior', 'author-or-user-image' ); ?></th>
					<td><label><input name="delete_data" type="checkbox" value="1" <?php checked( $delete_data ); ?>> <?php esc_html_e( 'Permanently delete plugin settings and plugin-managed avatar attachments when the plugin is uninstalled.', 'author-or-user-image' ); ?></label><p class="description"><?php esc_html_e( 'This does not run on deactivation. Leave unchecked to preserve user data.', 'author-or-user-image' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'author-or-user-image' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Save plugin settings.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'A POST request is required.', 'author-or-user-image' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'author-or-user-image' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'zmai_save_settings' );

		if ( isset( $_POST['upload_policy_mode'] ) || isset( $_POST['upload_policy_roles'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The policy service strictly validates the complete untrusted structure rather than normalizing malformed roles.
			$input  = array(
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict validation in Upload_Policy rejects malformed input without normalization.
				'mode'  => isset( $_POST['upload_policy_mode'] ) ? wp_unslash( $_POST['upload_policy_mode'] ) : null,
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each role is validated against the current site's known slugs.
				'roles' => isset( $_POST['upload_policy_roles'] ) ? wp_unslash( $_POST['upload_policy_roles'] ) : array(),
			);
			$result = $this->policy->save_policy( $input );
			if ( is_wp_error( $result ) ) {
				$this->redirect_manage( 'operation-error', 'settings' );
			}
		}

		$size = isset( $_POST['avatar_size'] ) ? absint( $_POST['avatar_size'] ) : 150;
		$this->store->update_size( $size );
		update_option( Image_Store::DELETE_DATA_OPTION, isset( $_POST['delete_data'] ) ? 1 : 0, false );
		$this->redirect_manage( 'settings-saved', 'settings' );
	}

	/**
	 * Corrupt persisted policy denies uploads until an administrator repairs it.
	 *
	 * @return void
	 */
	public function policy_notice() {
		$screen = get_current_screen();
		if ( ! current_user_can( 'manage_options' ) || ! $screen || ! in_array( $screen->id, array( 'users_page_author-or-user-image', 'users_page_author-or-user-image-manage' ), true ) ) {
			return;
		}
		$policy = $this->policy->get_policy();
		if ( 'invalid' === $policy['mode'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Author or User Image: the upload policy is invalid. Save a valid policy in Avatar Management settings to enable uploads.', 'author-or-user-image' ) . '</p></div>';
		}
	}

	/**
	 * Render a whitelisted status notice.
	 *
	 * @return void
	 */
	private function render_status_notice() {
		$status = isset( $_GET['zmai_status'] ) ? sanitize_key( wp_unslash( $_GET['zmai_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status value from a redirect.
		$error  = isset( $_GET['zmai_error'] ) ? sanitize_text_field( wp_unslash( $_GET['zmai_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status value from a redirect.
		$map    = array(
			'uploaded'         => __( 'The image was uploaded.', 'author-or-user-image' ),
			'removed'          => __( 'The image was removed.', 'author-or-user-image' ),
			'users-updated'    => __( 'The selected users were updated.', 'author-or-user-image' ),
			'nothing-selected' => __( 'Choose at least one user and an action.', 'author-or-user-image' ),
			'settings-saved'   => __( 'Settings saved.', 'author-or-user-image' ),
		);

		if ( 'operation-error' === $status ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The requested changes did not all complete. Avatar data may be busy or need administrator recovery. Please retry.', 'author-or-user-image' ) . '</p></div>';
		} elseif ( in_array( $status, array( 'upload-error', 'remove-error' ), true ) && $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		} elseif ( isset( $map[ $status ] ) ) {
			$class = 'nothing-selected' === $status ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $map[ $status ] ) . '</p></div>';
		}
	}

	/**
	 * Redirect to the self-service page.
	 *
	 * @param string $status Status code.
	 * @param string $error  Optional safe error text.
	 * @return void
	 */
	private function redirect( $status, $error = '' ) {
		if ( $this->is_front_request() ) {
			$status = Front_End::status_message( $status ) ? $status : 'upload-error';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by handler; strict origin/path validation follows.
			$return = isset( $_POST['zmai_return'] ) ? wp_unslash( $_POST['zmai_return'] ) : '';
			wp_safe_redirect( add_query_arg( 'zmai_status', $status, Front_End::safe_return_url( $return ) ), 303 );
			exit;
		}
		$args = array(
			'page'        => 'author-or-user-image',
			'zmai_status' => sanitize_key( $status ),
		);
		if ( $error ) {
			$args['zmai_error'] = sanitize_text_field( $error );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * Redirect to an administrator tab.
	 *
	 * @param string $status Status code.
	 * @param string $tab    Optional tab.
	 * @return void
	 */
	private function redirect_manage( $status, $tab = '' ) {
		$tab = $tab ? sanitize_key( $tab ) : 'images';
		if ( ! in_array( $tab, array( 'images', 'blocked', 'settings' ), true ) ) {
			$tab = 'images';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'author-or-user-image-manage',
					'tab'         => $tab,
					'zmai_status' => sanitize_key( $status ),
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}
}
