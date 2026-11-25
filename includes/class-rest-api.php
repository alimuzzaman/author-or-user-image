<?php
/**
 * REST API controller for avatar management and settings.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes site-scoped avatar, block-list, bulk, and settings REST endpoints.
 */
final class REST_API {
	/**
	 * REST API namespace.
	 */
	const REST_NAMESPACE = 'author-or-user-image/v1';

	/**
	 * Image storage service.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Shared upload policy.
	 *
	 * @var Upload_Policy
	 */
	private $policy;

	/**
	 * Register REST API initialization hook.
	 *
	 * @param Image_Store $store Image storage service.
	 */
	public function __construct( Image_Store $store ) {
		$this->store  = $store;
		$this->policy = new Upload_Policy( $store );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all plugin REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/avatars',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_avatars' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'role'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
					'size'     => array(
						'type'    => 'integer',
						'minimum' => 16,
						'maximum' => 1024,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/avatars/(?P<user_id>[1-9][0-9]*)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_user_avatar' ),
					'permission_callback' => array( $this, 'check_user_read_permission' ),
					'args'                => array(
						'user_id' => $this->user_id_arg(),
						'size'    => array(
							'type'    => 'integer',
							'minimum' => 16,
							'maximum' => 1024,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_user_avatar' ),
					'permission_callback' => array( $this, 'check_user_upload_permission' ),
					'args'                => array(
						'user_id' => $this->user_id_arg(),
						'size'    => array(
							'type'    => 'integer',
							'minimum' => 16,
							'maximum' => 1024,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_user_avatar' ),
					'permission_callback' => array( $this, 'check_user_remove_permission' ),
					'args'                => array(
						'user_id' => $this->user_id_arg(),
						'size'    => array(
							'type'    => 'integer',
							'minimum' => 16,
							'maximum' => 1024,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/default',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_default_avatar' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'size' => array(
							'type'    => 'integer',
							'minimum' => 16,
							'maximum' => 1024,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_default_avatar' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'size' => array(
							'type'    => 'integer',
							'minimum' => 16,
							'maximum' => 1024,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_default_avatar' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'size' => array(
							'type'    => 'integer',
							'minimum' => 16,
							'maximum' => 1024,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/blocked',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_blocked' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'block_user' ),
					'permission_callback' => array( $this, 'check_admin_user_param_permission' ),
					'args'                => array(
						'user_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/blocked/(?P<user_id>[1-9][0-9]*)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'unblock_user' ),
				'permission_callback' => array( $this, 'check_admin_user_param_permission' ),
				'args'                => array(
					'user_id' => $this->user_id_arg(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bulk' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'action'   => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'delete', 'block', 'unblock' ),
					),
					'user_ids' => array(
						'required'          => true,
						'type'              => 'array',
						'minItems'          => 1,
						'maxItems'          => 50,
						'items'             => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'validate_callback' => array( $this, 'validate_bulk_user_ids' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * Require site-administrator capabilities.
	 *
	 * @return bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Authorize self read or site-administrator read for a target user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function check_user_read_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user_id = absint( $request['user_id'] );
		if ( get_current_user_id() === $user_id ) {
			return $this->can_self_access( $user_id );
		}
		return $this->check_target_user_admin( $user_id );
	}

	/**
	 * Authorize self upload (subject to site policy) or site-administrator upload.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function check_user_upload_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user_id = absint( $request['user_id'] );
		if ( get_current_user_id() === $user_id ) {
			return $this->can_self_access( $user_id ) && $this->policy->can_upload( $user_id );
		}
		return $this->check_target_user_admin( $user_id );
	}

	/**
	 * Authorize self removal (even when role-excluded) or site-administrator removal.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function check_user_remove_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user_id = absint( $request['user_id'] );
		if ( get_current_user_id() === $user_id ) {
			return $this->can_self_access( $user_id );
		}
		return $this->check_target_user_admin( $user_id );
	}

	/**
	 * Authorize admin mutations targeting a supplied user_id parameter.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function check_admin_user_param_permission( WP_REST_Request $request ) {
		return $this->check_target_user_admin( absint( $request['user_id'] ) );
	}

	/**
	 * List current-site users who have a local avatar.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_avatars( WP_REST_Request $request ) {
		$role     = $request->get_param( 'role' );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$size     = $this->requested_size( $request );

		$args = array(
			'number'      => $per_page,
			'offset'      => ( $page - 1 ) * $per_page,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => true,
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only paginated avatar listing.
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
			),
		);

		if ( is_string( $role ) && '' !== $role ) {
			$known = wp_roles()->roles;
			if ( ! is_array( $known ) || ! isset( $known[ $role ] ) ) {
				return new WP_Error( 'zmai_invalid_role', __( 'Unknown role filter.', 'author-or-user-image' ), array( 'status' => 400 ) );
			}
			$args['role'] = $role;
		}

		$query = new WP_User_Query( $args );
		$items = array();
		foreach ( $query->get_results() as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$uid     = (int) $user->ID;
			$url     = $this->store->get_url( $uid, $size, false );
			$items[] = array(
				'user_id'          => $uid,
				'user_login'       => $user->user_login,
				'display_name'     => $user->display_name,
				'attachment_id'    => $this->store->get_user_attachment_id( $uid ),
				'url'              => $url,
				'blocked'          => $this->store->is_blocked( $uid ),
				'has_local_avatar' => '' !== $url,
			);
		}

		return $this->paginated_response( $items, (int) $query->get_total(), $per_page );
	}

	/**
	 * Read avatar state for one user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_user_avatar( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );
		$size    = $this->requested_size( $request );
		return new WP_REST_Response( $this->build_avatar_payload( $user_id, $size ), 200 );
	}

	/**
	 * Upload or replace one user's avatar via multipart request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_user_avatar( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );
		$field   = $this->detect_file_field( $request, array( 'file', 'user_avatar' ) );
		if ( is_wp_error( $field ) ) {
			return $field;
		}

		$by_admin = get_current_user_id() !== $user_id;
		$result   = $this->store->save_upload( $field, $user_id, $by_admin );
		if ( is_wp_error( $result ) ) {
			return $this->map_error( $result );
		}

		return new WP_REST_Response( $this->build_avatar_payload( $user_id, $this->requested_size( $request ) ), 200 );
	}

	/**
	 * Remove one user's local avatar.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_user_avatar( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );
		$result  = $this->store->delete_user_avatar( $user_id );
		if ( is_wp_error( $result ) ) {
			return $this->map_error( $result );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'avatar'  => $this->build_avatar_payload( $user_id, $this->requested_size( $request ) ),
			),
			200
		);
	}

	/**
	 * Read the site default avatar state.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_default_avatar( WP_REST_Request $request ) {
		return new WP_REST_Response( $this->build_default_payload( $this->requested_size( $request ) ), 200 );
	}

	/**
	 * Upload or replace the site default avatar via multipart request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_default_avatar( WP_REST_Request $request ) {
		$field = $this->detect_file_field( $request, array( 'file', 'default_avatar' ) );
		if ( is_wp_error( $field ) ) {
			return $field;
		}

		$result = $this->store->save_upload( $field, 0 );
		if ( is_wp_error( $result ) ) {
			return $this->map_error( $result );
		}

		return new WP_REST_Response( $this->build_default_payload( $this->requested_size( $request ) ), 200 );
	}

	/**
	 * Remove the site default avatar.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_default_avatar( WP_REST_Request $request ) {
		$result = $this->store->delete_default_avatar();
		if ( is_wp_error( $result ) ) {
			return $this->map_error( $result );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'default' => $this->build_default_payload( $this->requested_size( $request ) ),
			),
			200
		);
	}

	/**
	 * List blocked users on the current site.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_blocked( WP_REST_Request $request ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ) ) );

		$query = new WP_User_Query(
			array(
				'number'      => $per_page,
				'offset'      => ( $page - 1 ) * $per_page,
				'orderby'     => 'display_name',
				'order'       => 'ASC',
				'count_total' => true,
				'meta_key'    => Image_Store::user_meta_key( Image_Store::BLOCKED_META ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin-only paginated blocked-user listing.
				'meta_value'  => 1, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin-only paginated blocked-user listing.
			)
		);

		$items = array();
		foreach ( $query->get_results() as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$items[] = array(
				'user_id'      => (int) $user->ID,
				'user_login'   => $user->user_login,
				'display_name' => $user->display_name,
				'blocked'      => true,
			);
		}

		return $this->paginated_response( $items, (int) $query->get_total(), $per_page );
	}

	/**
	 * Block local avatar uploads for a user and remove any existing avatar.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function block_user( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );
		$result  = $this->store->set_blocked( $user_id, true );
		if ( is_wp_error( $result ) ) {
			return $this->map_error( $result );
		}

		return new WP_REST_Response(
			array(
				'user_id' => $user_id,
				'blocked' => true,
			),
			200
		);
	}

	/**
	 * Unblock local avatar uploads for a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unblock_user( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );
		$result  = $this->store->set_blocked( $user_id, false );
		if ( is_wp_error( $result ) ) {
			return $this->map_error( $result );
		}

		return new WP_REST_Response(
			array(
				'user_id' => $user_id,
				'blocked' => false,
			),
			200
		);
	}

	/**
	 * Validate bulk user_ids arrays strictly before running mutations.
	 *
	 * @param mixed $value Untrusted parameter value.
	 * @return bool
	 */
	public function validate_bulk_user_ids( $value ) {
		if ( ! is_array( $value ) || count( $value ) < 1 || count( $value ) > 50 ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( is_int( $item ) ) {
				if ( $item < 1 ) {
					return false;
				}
			} elseif ( is_string( $item ) && preg_match( '/^[1-9][0-9]*$/D', $item ) ) {
				if ( (int) $item < 1 ) {
					return false;
				}
			} else {
				return false;
			}
		}
		return true;
	}

	/**
	 * Perform bounded bulk avatar delete/block/unblock actions.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_bulk( WP_REST_Request $request ) {
		$action   = sanitize_key( (string) $request->get_param( 'action' ) );
		$raw_ids  = $request->get_param( 'user_ids' );
		$user_ids = is_array( $raw_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) ) : array();
		if ( ! in_array( $action, array( 'delete', 'block', 'unblock' ), true ) || ! $user_ids || count( $user_ids ) > 50 ) {
			return new WP_Error( 'zmai_invalid_request', __( 'Provide an allowed bulk action and between 1 and 50 user IDs.', 'author-or-user-image' ), array( 'status' => 400 ) );
		}

		$succeeded = array();
		$failed    = array();
		$blog_id   = get_current_blog_id();

		foreach ( $user_ids as $uid ) {
			if ( ! get_userdata( $uid ) || ( is_multisite() && ! is_user_member_of_blog( $uid, $blog_id ) ) ) {
				$failed[] = array(
					'user_id' => $uid,
					'code'    => 'zmai_user_not_found',
				);
				continue;
			}
			if ( ! current_user_can( 'edit_user', $uid ) ) {
				$failed[] = array(
					'user_id' => $uid,
					'code'    => 'zmai_forbidden',
				);
				continue;
			}

			if ( 'delete' === $action ) {
				$result = $this->store->delete_user_avatar( $uid );
			} else {
				$result = $this->store->set_blocked( $uid, 'block' === $action );
			}

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'user_id' => $uid,
					'code'    => $result->get_error_code(),
				);
			} else {
				$succeeded[] = $uid;
			}
		}

		return new WP_REST_Response(
			array(
				'action'    => $action,
				'succeeded' => $succeeded,
				'failed'    => $failed,
			),
			200
		);
	}

	/**
	 * Read current-site plugin settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return new WP_REST_Response( $this->build_settings_payload(), 200 );
	}

	/**
	 * Validate and persist updated current-site plugin settings.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$has_size      = $request->has_param( 'avatar_size' );
		$has_uninstall = $request->has_param( 'delete_data_on_uninstall' );
		$has_policy    = $request->has_param( 'upload_policy' );

		$parsed_size = 0;
		if ( $has_size ) {
			$raw_size = $request->get_param( 'avatar_size' );
			if ( is_int( $raw_size ) ) {
				$parsed_size = $raw_size;
			} elseif ( is_string( $raw_size ) && preg_match( '/^[0-9]+$/D', $raw_size ) ) {
				$parsed_size = (int) $raw_size;
			} else {
				return new WP_Error( 'zmai_invalid_size', __( 'Avatar size must be an integer between 32 and 1024.', 'author-or-user-image' ), array( 'status' => 400 ) );
			}
			if ( $parsed_size < 32 || $parsed_size > 1024 ) {
				return new WP_Error( 'zmai_invalid_size', __( 'Avatar size must be an integer between 32 and 1024.', 'author-or-user-image' ), array( 'status' => 400 ) );
			}
		}

		$parsed_uninstall = false;
		if ( $has_uninstall ) {
			$raw_uninstall = $request->get_param( 'delete_data_on_uninstall' );
			if ( ! is_bool( $raw_uninstall ) ) {
				return new WP_Error( 'zmai_invalid_setting', __( 'Uninstall cleanup flag must be a boolean.', 'author-or-user-image' ), array( 'status' => 400 ) );
			}
			$parsed_uninstall = $raw_uninstall;
		}

		if ( $has_policy ) {
			$raw_policy = $request->get_param( 'upload_policy' );
			if ( ! is_array( $raw_policy ) ) {
				return new WP_Error( 'zmai_invalid_policy', __( 'Choose a valid upload policy and known site roles.', 'author-or-user-image' ), array( 'status' => 400 ) );
			}
			$saved = $this->policy->save_policy( $raw_policy );
			if ( is_wp_error( $saved ) ) {
				return $this->map_error( $saved );
			}
		}

		if ( $has_size ) {
			$this->store->update_size( $parsed_size );
		}
		if ( $has_uninstall ) {
			update_option( Image_Store::DELETE_DATA_OPTION, $parsed_uninstall ? 1 : 0, false );
		}

		return new WP_REST_Response( $this->build_settings_payload(), 200 );
	}

	/**
	 * Shared route argument definition for user_id path params.
	 *
	 * @return array
	 */
	private function user_id_arg() {
		return array(
			'required'          => true,
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * Check whether the logged-in user can act on their own site-scoped avatar.
	 *
	 * @param int $user_id Current user ID.
	 * @return bool
	 */
	private function can_self_access( $user_id ) {
		if ( ! current_user_can( 'read' ) ) {
			return false;
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Check admin permissions, target existence, edit_user capability, and site membership.
	 *
	 * @param int $user_id Target user ID.
	 * @return bool|WP_Error
	 */
	private function check_target_user_admin( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'zmai_user_not_found', __( 'The requested user was not found.', 'author-or-user-image' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'zmai_user_not_found', __( 'The requested user is not a member of this site.', 'author-or-user-image' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Find the uploaded multipart field name from allowlisted candidates.
	 *
	 * @param WP_REST_Request   $request    Incoming request.
	 * @param array<int,string> $candidates Allowed field names.
	 * @return string|WP_Error
	 */
	private function detect_file_field( WP_REST_Request $request, array $candidates ) {
		$files = $request->get_file_params();
		foreach ( $candidates as $candidate ) {
			if ( isset( $files[ $candidate ] ) && is_array( $files[ $candidate ] ) ) {
				return $candidate;
			}
		}
		return new WP_Error( 'zmai_upload_missing', __( 'Choose an image file to upload.', 'author-or-user-image' ), array( 'status' => 400 ) );
	}

	/**
	 * Clamp requested preview size or fall back to the configured default.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return int
	 */
	private function requested_size( WP_REST_Request $request ) {
		$size = $request->get_param( 'size' );
		if ( null === $size || '' === $size ) {
			return $this->store->get_size();
		}
		return min( 1024, max( 16, absint( $size ) ) );
	}

	/**
	 * Build a user avatar state payload.
	 *
	 * @param int $user_id Target user ID.
	 * @param int $size    Requested image size.
	 * @return array
	 */
	private function build_avatar_payload( $user_id, $size ) {
		$url          = $this->store->get_url( $user_id, $size, false );
		$fallback_url = $this->store->get_url( $user_id, $size, true );
		return array(
			'user_id'          => $user_id,
			'attachment_id'    => $this->store->get_user_attachment_id( $user_id ),
			'url'              => $url,
			'fallback_url'     => $fallback_url,
			'blocked'          => $this->store->is_blocked( $user_id ),
			'can_upload'       => $this->policy->can_upload( $user_id ),
			'has_local_avatar' => '' !== $url,
		);
	}

	/**
	 * Build the site default avatar payload.
	 *
	 * @param int $size Requested image size.
	 * @return array
	 */
	private function build_default_payload( $size ) {
		$url = $this->store->get_url( 0, $size );
		return array(
			'attachment_id'      => $this->store->get_default_attachment_id(),
			'url'                => $url,
			'has_default_avatar' => '' !== $url,
		);
	}

	/**
	 * Build the current-site settings payload.
	 *
	 * @return array
	 */
	private function build_settings_payload() {
		return array(
			'upload_policy'            => $this->policy->get_policy(),
			'avatar_size'              => $this->store->get_size(),
			'delete_data_on_uninstall' => (bool) get_option( Image_Store::DELETE_DATA_OPTION, false ),
		);
	}

	/**
	 * Wrap paginated items with standard WordPress pagination headers.
	 *
	 * @param array $items    Page items.
	 * @param int   $total    Total matching items.
	 * @param int   $per_page Page size.
	 * @return WP_REST_Response
	 */
	private function paginated_response( array $items, $total, $per_page ) {
		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		$response    = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );
		return $response;
	}

	/**
	 * Map internal plugin errors onto typed HTTP status codes.
	 *
	 * @param WP_Error $error Internal error.
	 * @return WP_Error
	 */
	private function map_error( WP_Error $error ) {
		$code   = $error->get_error_code();
		$status = 500;
		if ( 'zmai_rate_limited' === $code ) {
			$status = 429;
		} elseif ( in_array( $code, array( 'zmai_blocked', 'zmai_forbidden' ), true ) ) {
			$status = 403;
		} elseif ( 'zmai_busy' === $code ) {
			$status = 409;
		} elseif ( in_array( $code, array( 'zmai_invalid_user', 'zmai_user_not_found' ), true ) ) {
			$status = 404;
		} elseif ( in_array(
			$code,
			array(
				'zmai_invalid_image',
				'zmai_invalid_image_container',
				'zmai_animated_image',
				'zmai_upload_size',
				'zmai_invalid_dimensions',
				'zmai_metadata_failed',
				'zmai_metadata_cmyk',
				'zmai_jpeg_exif_unavailable',
				'zmai_image_format',
				'zmai_upload_failed',
				'zmai_upload_provenance',
				'zmai_upload_missing',
				'zmai_invalid_policy',
				'zmai_invalid_role',
				'zmai_invalid_size',
				'zmai_invalid_setting',
			),
			true
		) ) {
			$status = 400;
		}
		return new WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
	}
}
