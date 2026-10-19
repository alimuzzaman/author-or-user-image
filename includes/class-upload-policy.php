<?php
/**
 * Upload eligibility policy for the current site.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a user may upload an avatar on the current site.
 */
final class Upload_Policy {
	const OPTION = 'zmai_upload_policy';

	/**
	 * Authoritative store used for uncached decisions and checked writes.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Create the site upload policy.
	 *
	 * @param Image_Store|null $store Shared image store; created when omitted.
	 */
	public function __construct( ?Image_Store $store = null ) {
		$this->store = null !== $store ? $store : new Image_Store();
	}

	/**
	 * Read the persisted policy. Missing storage means legacy behavior; malformed
	 * storage returns mode "invalid" and an empty role list.
	 *
	 * @return array{mode:string,roles:array} Policy mode and selected role slugs.
	 */
	public function get_policy() {
		$value = $this->store->read_value( 0, '', self::OPTION );
		if ( null === $value ) {
			return array(
				'mode'  => 'legacy',
				'roles' => array(),
			);
		}
		if ( ! $this->valid_shape( $value, true ) ) {
			return array(
				'mode'  => 'invalid',
				'roles' => array(),
			);
		}
		return array(
			'mode'  => $value['mode'],
			'roles' => $value['roles'],
		);
	}

	/**
	 * Check upload eligibility for rendering and early handler checks.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether this user may upload on the current site.
	 */
	public function can_upload( $user_id ) {
		try {
			return $this->can_upload_fresh( $user_id );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Recheck upload eligibility from current user, role, block, and policy data.
	 * Call from the owner's locked operation path.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether this user may upload on the current site.
	 */
	public function can_upload_fresh( $user_id ) {
		global $wpdb;
		if ( ! is_numeric( $user_id ) || (int) $user_id < 1 || ! $this->store->user_exists_fresh( (int) $user_id ) ) {
			return false;
		}
		$user_id = (int) $user_id;
		wp_cache_delete( $user_id, 'user_meta' );
		if ( $this->store->is_blocked_fresh( $user_id ) ) {
			return false;
		}
		$policy = $this->get_policy();
		if ( 'invalid' === $policy['mode'] ) {
			return false;
		}
		$prefix = $wpdb->get_blog_prefix();
		$rows   = $this->store->database_values( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id', $wpdb->usermeta, $user_id, $prefix . 'capabilities' ) );
		$caps   = $rows ? maybe_unserialize( $rows[0] ) : array();
		if ( ! is_array( $caps ) || count( array_unique( $rows ) ) > 1 ) {
			return false;
		}
		$roles = wp_roles()->roles;
		if ( ! is_array( $roles ) ) {
			return false;
		}
		$user = new \WP_User( $user_id, '', get_current_blog_id() );
		if ( ! $user->exists() ) {
			return false;
		}
		// Use uncached persisted user capabilities and the current site's role table, while
		// retaining core capability filters and its legacy super-administrator read grant.
		// No role capabilities are changed.
		$user->caps    = $caps;
		$user->roles   = array_values( array_intersect( array_keys( $roles ), array_keys( $caps ) ) );
		$user->allcaps = array();
		foreach ( $user->roles as $role ) {
			if ( isset( $roles[ $role ]['capabilities'] ) && is_array( $roles[ $role ]['capabilities'] ) ) {
				$user->allcaps = array_merge( $user->allcaps, $roles[ $role ]['capabilities'] );
			}
		}
		$user->allcaps = array_merge( $user->allcaps, $caps );
		if ( ! user_can( $user, 'read' ) ) {
			return false;
		}
		if ( 'legacy' === $policy['mode'] ) {
			return true;
		}
		// A persisted current-site capability record establishes membership even
		// on single-site; a super-administrator gets no selected-policy bypass.
		return ! empty( $caps ) && (bool) array_intersect( $user->roles, $policy['roles'] );
	}

	/**
	 * Validate and persist an explicit settings choice.
	 *
	 * Accepts exactly {mode: 'legacy'|'roles', roles: string[]} and writes
	 * {schema: 1, mode: string, roles: string[]} as a nonautoloaded site option.
	 * Roles are checked against the current site's role table. The caller owns
	 * POST, capability, and nonce authorization.
	 *
	 * @param mixed $input Submitted policy fields.
	 * @return true|WP_Error True after checked persistence, or a validation/storage error.
	 */
	public function save_policy( $input ) {
		if ( ! $this->valid_shape( $input, false ) ) {
			return new WP_Error( 'zmai_invalid_policy', __( 'Choose a valid upload policy and known site roles.', 'author-or-user-image' ) );
		}
		global $wpdb;
		$known = wp_roles()->roles;
		if ( ! is_array( $known ) || array_diff( $input['roles'], array_keys( $known ) ) ) {
			return new WP_Error( 'zmai_invalid_policy', __( 'Choose a valid upload policy and known site roles.', 'author-or-user-image' ) );
		}
		$value   = array(
			'schema' => 1,
			'mode'   => $input['mode'],
			'roles'  => $input['roles'],
		);
		$written = $this->store->write_value( 0, '', self::OPTION, $value );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		// update_option skips unchanged values, so explicitly repair autoload too.
		wp_set_option_autoload_values( array( self::OPTION => false ) );
		$autoload = $this->store->database_values( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
		return 1 === count( $autoload ) && ! in_array( $autoload[0], wp_autoload_values_to_autoload(), true ) ? true : $this->store->storage_error();
	}
	/**
	 * Validate exact persisted/submitted shape without coercing invalid slugs.
	 * Removed role slugs remain valid persisted identities, but inert at decision time.
	 *
	 * @param mixed $input Untrusted policy structure.
	 * @param bool  $persisted Whether schema:1 must be present.
	 * @return bool
	 */
	private function valid_shape( $input, $persisted ) {
		if ( ! is_array( $input ) || count( $input ) !== ( $persisted ? 3 : 2 ) || ! isset( $input['mode'], $input['roles'] ) || ! is_string( $input['mode'] ) || ! in_array( $input['mode'], array( 'legacy', 'roles' ), true ) || ! is_array( $input['roles'] ) || ( $persisted && ( ! isset( $input['schema'] ) || 1 !== $input['schema'] ) ) ) {
			return false;
		}
		if ( $input['roles'] && array_keys( $input['roles'] ) !== range( 0, count( $input['roles'] ) - 1 ) ) {
			return false;
		}
		foreach ( $input['roles'] as $role ) {
			if ( ! is_string( $role ) || '' === $role || sanitize_key( $role ) !== $role ) {
				return false;
			}
		}
		return count( array_unique( $input['roles'] ) ) === count( $input['roles'] );
	}
}
