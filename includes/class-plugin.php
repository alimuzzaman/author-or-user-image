<?php
/**
 * Main plugin integration.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Comment;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Connects avatar storage to WordPress.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Image storage service.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Legacy import coordinator.
	 *
	 * @var Migration
	 */
	private $migration;

	/**
	 * Account and privacy cleanup.
	 *
	 * @var User_Cleanup
	 */
	private $cleanup;

	/**
	 * Return the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		$store = new Image_Store();
		$store->register_image_size();
		( new Migration( $store ) )->maybe_migrate();
	}

	/**
	 * Register runtime hooks.
	 */
	private function __construct() {
		$this->store     = new Image_Store();
		$this->migration = new Migration( $this->store );
		$this->cleanup   = new User_Cleanup( $this->store );

		add_action( 'init', array( $this->store, 'register_image_size' ) );
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
		add_action( 'delete_user', array( $this, 'delete_user_data' ) );
		add_action( 'wpmu_delete_user', array( $this, 'delete_network_user_data' ) );
		add_action( 'deleted_user', array( $this, 'finish_user_deletion' ) );

		if ( is_admin() ) {
			new Admin( $this->store );
			( new Import_Admin( $this->migration ) )->register();
			add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
			add_action( 'admin_notices', array( $this->migration, 'migration_notice' ) );
		}

		new Front_End( $this->store );
		new Privacy( $this->store, $this->cleanup );
	}

	/**
	 * Upgrade legacy data only from an authorized admin request.
	 *
	 * @return void
	 */
	public function maybe_migrate() {
		if ( current_user_can( 'manage_options' ) ) {
			$this->migration->maybe_migrate();
		}
	}

	/**
	 * Supply a local avatar URL to WordPress's normal avatar renderer.
	 *
	 * @param array $args        Avatar data.
	 * @param mixed $id_or_email User ID, email, user, or comment object.
	 * @return array
	 */
	public function filter_avatar_data( $args, $id_or_email ) {
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		// This early filter must preserve core's exclusions for comment types.
		if ( is_object( $id_or_email ) && isset( $id_or_email->comment_ID ) ) {
			$id_or_email = get_comment( $id_or_email );
			$allowed     = $id_or_email && ( function_exists( 'is_avatar_comment_type' ) ? is_avatar_comment_type( get_comment_type( $id_or_email ) ) : in_array( get_comment_type( $id_or_email ), (array) apply_filters( 'get_avatar_comment_types', array( 'comment' ) ), true ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core filter.
			if ( ! $allowed ) {
				return $args;
			}
		}

		$user = $this->resolve_user( $id_or_email );
		if ( ! $user || $this->store->is_blocked( $user->ID ) ) {
			return $args;
		}

		$size = isset( $args['size'] ) ? min( 1024, max( 16, absint( $args['size'] ) ) ) : 96;
		$url  = $this->store->get_url( $user->ID, $size );
		if ( $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * Normalize the values accepted by WordPress avatar functions.
	 *
	 * @param mixed $id_or_email User ID, email, user, or comment object.
	 * @return WP_User|false
	 */
	private function resolve_user( $id_or_email ) {
		if ( $id_or_email instanceof WP_User ) {
			return $id_or_email;
		}

		if ( $id_or_email instanceof WP_Post ) {
			return get_user_by( 'id', $id_or_email->post_author );
		}

		if ( $id_or_email instanceof WP_Comment ) {
			if ( $id_or_email->user_id ) {
				return get_user_by( 'id', $id_or_email->user_id );
			}

			return get_user_by( 'email', sanitize_email( $id_or_email->comment_author_email ) );
		}

		if ( is_object( $id_or_email ) ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return get_user_by( 'id', absint( $id_or_email->user_id ) );
			}
			if ( ! empty( $id_or_email->comment_author_email ) ) {
				return get_user_by( 'email', sanitize_email( $id_or_email->comment_author_email ) );
			}
		}

		if ( is_numeric( $id_or_email ) ) {
			return get_user_by( 'id', absint( $id_or_email ) );
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			return get_user_by( 'email', sanitize_email( $id_or_email ) );
		}

		return false;
	}

	/**
	 * Remove plugin-owned data when WordPress deletes a user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function delete_user_data( $user_id ) {
		$result = $this->cleanup->begin_user_deletion( $user_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
		}
	}

	/**
	 * Abort network account deletion if any site's owned cleanup is incomplete.
	 *
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return void
	 */
	public function delete_network_user_data( $user_id ) {
		$result = $this->cleanup->begin_network_user_deletion( $user_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
		}
	}

	/**
	 * Release the request-owned fence after core has finished user deletion.
	 *
	 * @param int $user_id User ID, or zero for the default avatar.
	 * @return void
	 */
	public function finish_user_deletion( $user_id ) {
		$result = $this->cleanup->finish_user_deletion( $user_id );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Core already deleted the user; retain the fence for recovery without aborting bulk deletion.
			error_log( 'Author or User Image: deletion fence release failed; administrator recovery required.' );
		}
	}
}
