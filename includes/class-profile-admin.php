<?php
/**
 * WordPress Profile and Edit User screen avatar management.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates avatar upload, removal, and administrator blocking into profile screens.
 */
final class Profile_Admin {
	/**
	 * Image storage service.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Shared site upload policy.
	 *
	 * @var Upload_Policy
	 */
	private $policy;

	/**
	 * Fixed translated errors collected during profile save, keyed by target user ID.
	 *
	 * @var array<int,array<int,array{code:string,message:string}>>
	 */
	private $pending_errors = array();

	/**
	 * Register WordPress profile hooks.
	 *
	 * @param Image_Store $store Image storage service.
	 */
	public function __construct( Image_Store $store ) {
		$this->store  = $store;
		$this->policy = new Upload_Policy( $store );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'user_edit_form_tag', array( $this, 'add_multipart_enctype' ) );
		add_action( 'show_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
		add_action( 'user_profile_update_errors', array( $this, 'attach_profile_errors' ), 10, 3 );
	}

	/**
	 * Enqueue plugin styles only on Profile and Edit User screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'zmai-admin', ZMAI_URL . 'assets/css/admin.css', array(), ZMAI_VERSION );
	}

	/**
	 * Enable multipart file uploads on the WordPress profile form.
	 *
	 * @return void
	 */
	public function add_multipart_enctype() {
		echo ' enctype="multipart/form-data"';
	}

	/**
	 * Render avatar management controls inside the WordPress Profile / Edit User screen.
	 *
	 * @param WP_User $user Target user being viewed or edited.
	 * @return void
	 */
	public function render_profile_section( WP_User $user ) {
		$target_id      = (int) $user->ID;
		$is_self        = get_current_user_id() === $target_id;
		$can_admin_edit = current_user_can( 'manage_options' ) && current_user_can( 'edit_user', $target_id );
		if ( ( ! $is_self && ! $can_admin_edit ) || ( $is_self && ! current_user_can( 'read' ) ) ) {
			return;
		}

		$size       = $this->store->get_size();
		$blocked    = $this->store->is_blocked( $target_id );
		$url        = $this->store->get_url( $target_id, $size, false );
		$can_upload = ! $blocked && ( $is_self ? $this->policy->can_upload( $target_id ) : $can_admin_edit );
		?>
		<h2><?php esc_html_e( 'Author or User Image', 'author-or-user-image' ); ?></h2>
		<?php wp_nonce_field( 'zmai_profile_avatar_' . $target_id, 'zmai_profile_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Current local avatar', 'author-or-user-image' ); ?></th>
				<td>
					<?php if ( $url ) : ?>
						<img class="zmai-avatar-preview" src="<?php echo esc_url( $url ); ?>" alt="<?php esc_attr_e( 'Current avatar image', 'author-or-user-image' ); ?>" width="<?php echo absint( $size ); ?>" height="<?php echo absint( $size ); ?>">
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No local avatar is set.', 'author-or-user-image' ); ?></p>
					<?php endif; ?>
					<?php if ( $url && ( $can_admin_edit || ( $is_self && ! $blocked ) ) ) : ?>
						<p>
							<label for="zmai-profile-remove">
								<input id="zmai-profile-remove" name="zmai_profile_remove" type="checkbox" value="1">
								<?php esc_html_e( 'Remove current local avatar', 'author-or-user-image' ); ?>
							</label>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="zmai-profile-avatar"><?php esc_html_e( 'Upload local avatar', 'author-or-user-image' ); ?></label></th>
				<td>
					<?php if ( $can_upload ) : ?>
						<input id="zmai-profile-avatar" name="zmai_profile_avatar" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="zmai-profile-avatar-limits">
						<p id="zmai-profile-avatar-limits" class="description"><?php esc_html_e( 'Choose a JPEG, PNG or static WebP up to 4096 × 4096 pixels. The limit is 8 MiB or your site’s upload limit, whichever is lower.', 'author-or-user-image' ); ?></p>
					<?php elseif ( $blocked ) : ?>
						<p class="description"><?php esc_html_e( 'Local avatar uploads are disabled for this account.', 'author-or-user-image' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Your role is not allowed to upload a new avatar on this site. You can still remove your avatar.', 'author-or-user-image' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( ! $is_self && $can_admin_edit ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Local avatar restriction', 'author-or-user-image' ); ?></th>
					<td>
						<input type="hidden" name="zmai_profile_block_present" value="1">
						<label for="zmai-profile-blocked">
							<input id="zmai-profile-blocked" name="zmai_profile_blocked" type="checkbox" value="1" <?php checked( $blocked ); ?>>
							<?php esc_html_e( 'Block local avatar uploads for this user and remove any existing local avatar', 'author-or-user-image' ); ?>
						</label>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Process avatar block, removal, and upload changes from the Profile / Edit User form.
	 *
	 * @param int $user_id Target user ID.
	 * @return void
	 */
	public function save_profile( $user_id ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence of our form nonce determines whether the plugin section was submitted; nonce verification follows immediately.
		if ( ! isset( $_POST['zmai_profile_nonce'] ) ) {
			return;
		}

		$target_id = absint( $user_id );
		if ( ! $target_id ) {
			return;
		}

		$nonce = is_string( $_POST['zmai_profile_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['zmai_profile_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'zmai_profile_avatar_' . $target_id ) ) {
			$this->record_error( $target_id, 'zmai_invalid_nonce', __( 'The security check failed. Reload the page and try again.', 'author-or-user-image' ) );
			return;
		}

		$is_self        = get_current_user_id() === $target_id;
		$can_admin_edit = current_user_can( 'manage_options' ) && current_user_can( 'edit_user', $target_id );
		if ( ( ! $is_self && ! $can_admin_edit ) || ( $is_self && ! current_user_can( 'read' ) ) ) {
			$this->record_error( $target_id, 'zmai_forbidden', __( 'You are not allowed to do that.', 'author-or-user-image' ) );
			return;
		}

		if ( ! $is_self && $can_admin_edit && isset( $_POST['zmai_profile_block_present'] ) ) {
			$want_block = ! empty( $_POST['zmai_profile_blocked'] );
			if ( $want_block !== $this->store->is_blocked( $target_id ) ) {
				$block_result = $this->store->set_blocked( $target_id, $want_block );
				if ( is_wp_error( $block_result ) ) {
					$this->record_wp_error( $target_id, $block_result );
					return;
				}
			}
			if ( $want_block ) {
				return;
			}
		}

		if ( ! empty( $_POST['zmai_profile_remove'] ) ) {
			$remove_result = $this->store->delete_user_avatar( $target_id );
			if ( is_wp_error( $remove_result ) ) {
				$this->record_wp_error( $target_id, $remove_result );
				return;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only inspects PHP upload error status before Image_Store validates provenance and file bytes.
		$file_error = isset( $_FILES['zmai_profile_avatar'] ) && is_array( $_FILES['zmai_profile_avatar'] ) && isset( $_FILES['zmai_profile_avatar']['error'] ) && is_scalar( $_FILES['zmai_profile_avatar']['error'] ) ? (int) $_FILES['zmai_profile_avatar']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE !== $file_error ) {
			$by_admin = ! $is_self && $can_admin_edit;
			if ( ! $by_admin && ! $this->policy->can_upload( $target_id ) ) {
				$this->record_error( $target_id, 'zmai_blocked', Admin::upload_error_message( 'zmai_blocked' ) );
				return;
			}
			$upload_result = $this->store->save_upload( 'zmai_profile_avatar', $target_id, $by_admin );
			if ( is_wp_error( $upload_result ) ) {
				$this->record_wp_error( $target_id, $upload_result );
			}
		}
	}

	/**
	 * Attach any fixed avatar errors to WordPress's profile error bag.
	 *
	 * @param WP_Error $errors Profile validation error bag.
	 * @param bool     $update Whether this is an existing user update.
	 * @param object   $user   Core user data object.
	 * @return void
	 */
	public function attach_profile_errors( $errors, $update, $user ) {
		if ( ! $update || ! is_object( $user ) || ! isset( $user->ID ) || ! ( $errors instanceof WP_Error ) ) {
			return;
		}
		$uid = (int) $user->ID;
		if ( empty( $this->pending_errors[ $uid ] ) ) {
			return;
		}
		foreach ( $this->pending_errors[ $uid ] as $entry ) {
			$errors->add( $entry['code'], $entry['message'] );
		}
		unset( $this->pending_errors[ $uid ] );
	}

	/**
	 * Queue a fixed translated error for the current profile update.
	 *
	 * @param int    $user_id Target user ID.
	 * @param string $code    Error code.
	 * @param string $message Fixed translated error message.
	 * @return void
	 */
	private function record_error( $user_id, $code, $message ) {
		$this->pending_errors[ $user_id ][] = array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Queue a fixed translated message from a WP_Error without exposing raw error text.
	 *
	 * @param int      $user_id Target user ID.
	 * @param WP_Error $error   Internal operation failure.
	 * @return void
	 */
	private function record_wp_error( $user_id, WP_Error $error ) {
		$code = $error->get_error_code();
		$this->record_error( $user_id, $code, Admin::upload_error_message( $code ) );
	}
}
