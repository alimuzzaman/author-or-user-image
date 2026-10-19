<?php
/**
 * Real single-site and multisite policy checks. Run with WP-CLI eval-file and candidate-bootstrap.
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Upload_Policy;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use a disposable local fixture only.' );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( Upload_Policy::class ) ) {
	require_once ZMAI_TEST_CANDIDATE . '/includes/class-upload-policy.php';
}

global $wpdb, $zmai_policy_failures, $zmai_policy_checks, $zmai_policy_prefix, $zmai_policy_users;
$zmai_policy_old_current = get_current_user_id();
wp_set_current_user( 1 );
$zmai_policy_failures = array();
$zmai_policy_checks   = 0;
$zmai_policy_prefix   = 'zmai-policy-' . strtolower( wp_generate_password( 8, false, false ) );
$zmai_policy_store    = new Image_Store();
$zmai_policy          = new Upload_Policy( $zmai_policy_store );
$zmai_policy_primary  = get_current_blog_id();
$zmai_policy_site     = 0;
$zmai_policy_users    = array();
$zmai_policy_option   = Upload_Policy::OPTION;
$zmai_policy_missing  = new stdClass();
$zmai_policy_original = get_option( $zmai_policy_option, $zmai_policy_missing );
$zmai_policy_original_rows = $zmai_policy_store->database_values( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $zmai_policy_option ) );
$zmai_policy_original_autoload = $zmai_policy_store->database_values( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, $zmai_policy_option ) );

/** Record a policy assertion. */
function zmai_policy_expect( $condition, $label ) {
	global $zmai_policy_failures, $zmai_policy_checks;
	++$zmai_policy_checks;
	if ( ! $condition ) {
		$zmai_policy_failures[] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/** Create a uniquely named user on the current site. */
function zmai_policy_user( $role, $suffix ) {
	global $zmai_policy_prefix, $zmai_policy_users;
	$user_id = wp_insert_user(
		array(
			'user_login' => $zmai_policy_prefix . '-' . $suffix,
			'user_email' => $zmai_policy_prefix . '-' . $suffix . '@example.invalid',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $role,
		)
	);
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( 'Could not create a policy fixture user (' . implode( ',', $user_id->get_error_codes() ) . '): ' . implode( ' | ', $user_id->get_error_messages() ) );
	}
	$zmai_policy_users[] = (int) $user_id;
	return (int) $user_id;
}

/** Return the uncached decoded policy option, or null when absent. */
function zmai_policy_raw( $store ) {
	global $wpdb;
	$values = $store->database_values( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, Upload_Policy::OPTION ) );
	return $values ? maybe_unserialize( $values[0] ) : null;
}

/** Add a role and make the role definition visible to the current request. */
function zmai_policy_add_role( $slug, $caps ) {
	add_role( $slug, 'ZMAI policy fixture', $caps );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_roles()->for_site( get_current_blog_id() );
}

$zmai_policy_suffix = substr( $zmai_policy_prefix, strlen( 'zmai-policy-' ) );
$zmai_policy_custom = 'zmai_policy_custom_' . $zmai_policy_suffix;
$zmai_policy_no_read = 'zmai_policy_no_read_' . $zmai_policy_suffix;
$zmai_policy_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
$zmai_policy_role_users = array();

zmai_policy_add_role( $zmai_policy_custom, array( 'read' => true ) );
zmai_policy_add_role( $zmai_policy_no_read, array() );

delete_option( $zmai_policy_option );
$zmai_policy_current = $zmai_policy->get_policy();
$zmai_policy_rows = $zmai_policy_store->database_values( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $zmai_policy_option ) );
zmai_policy_expect( 'legacy' === $zmai_policy_current['mode'] && array() === $zmai_policy_current['roles'], 'missing policy selects legacy behavior' );
zmai_policy_expect( empty( $zmai_policy_rows ), 'reading a missing policy does not write an upgrade option' );

foreach ( $zmai_policy_roles as $zmai_policy_role ) {
	$zmai_policy_role_users[ $zmai_policy_role ] = zmai_policy_user( $zmai_policy_role, $zmai_policy_role );
	$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_role ) ) );
	zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_role_users[ $zmai_policy_role ] ), 'default role ' . $zmai_policy_role . ' qualifies when selected' );
}

$zmai_policy_custom_user = zmai_policy_user( $zmai_policy_custom, 'custom' );
$zmai_policy_multi_user  = zmai_policy_user( 'subscriber', 'multi' );
( new WP_User( $zmai_policy_multi_user ) )->add_role( $zmai_policy_custom );
$zmai_policy_reader      = zmai_policy_user( $zmai_policy_no_read, 'individual-read' );
$zmai_policy_no_reader   = zmai_policy_user( $zmai_policy_no_read, 'no-read' );
$zmai_policy_cap_key     = $wpdb->get_blog_prefix( $zmai_policy_primary ) . 'capabilities';
$zmai_policy_reader_caps = get_user_meta( $zmai_policy_reader, $zmai_policy_cap_key, true );
$zmai_policy_reader_caps['read'] = true;
update_user_meta( $zmai_policy_reader, $zmai_policy_cap_key, $zmai_policy_reader_caps );

$zmai_policy->save_policy( array( 'mode' => 'legacy', 'roles' => array() ) );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_reader ), 'legacy mode preserves an individual read grant' );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_no_reader ), 'legacy mode still requires read' );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( 2147483000 ), 'missing users are denied' );

$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_custom ) ) );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_custom_user ), 'custom role qualifies when selected' );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_multi_user ), 'a matching role qualifies a multi-role user' );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_role_users['subscriber'] ), 'a nonmatching role is denied' );
$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array() ) );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_custom_user ), 'an explicit empty role list denies everyone' );
$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_no_read ) ) );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_no_reader ), 'a matching role without read is denied' );

$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_custom ) ) );
update_user_meta( $zmai_policy_custom_user, Image_Store::user_meta_key( Image_Store::BLOCKED_META ), 1 );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_custom_user ), 'individual block takes priority over an allowed role' );
delete_user_meta( $zmai_policy_custom_user, Image_Store::user_meta_key( Image_Store::BLOCKED_META ) );

delete_option( $zmai_policy_option );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_custom_user ), 'absent policy admits a later-created custom read role' );
$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( 'administrator' ) ) );
update_user_meta( $zmai_policy_role_users['administrator'], Image_Store::user_meta_key( Image_Store::BLOCKED_META ), 1 );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_role_users['administrator'] ), 'individual block also wins for selected administrators' );
delete_user_meta( $zmai_policy_role_users['administrator'], Image_Store::user_meta_key( Image_Store::BLOCKED_META ) );
$zmai_policy_raw_before_error = zmai_policy_raw( $zmai_policy_store );
$zmai_policy_invalid_inputs = array(
	array( 'mode' => 'invalid', 'roles' => array() ),
	array( 'mode' => array( 'roles' ), 'roles' => array() ),
	array( 'mode' => 'roles', 'roles' => 'subscriber' ),
	array( 'mode' => 'roles', 'roles' => array( ' subscriber' ) ),
	array( 'mode' => 'roles', 'roles' => array( 42 ) ),
	array( 'mode' => 'roles', 'roles' => array( 'not-a-site-role-' . $zmai_policy_suffix ) ),
	array( 'mode' => 'roles', 'roles' => array( array( 'subscriber' ) ) ),
	array( 'mode' => 'roles', 'roles' => array(), 'extra' => true ),
);
foreach ( $zmai_policy_invalid_inputs as $zmai_policy_invalid_input ) {
	zmai_policy_expect( is_wp_error( $zmai_policy->save_policy( $zmai_policy_invalid_input ) ), 'invalid policy input is rejected' );
	zmai_policy_expect( $zmai_policy_raw_before_error === zmai_policy_raw( $zmai_policy_store ), 'failed validation preserves the saved policy' );
}

$zmai_policy_saved = $zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_custom ) ) );
$zmai_policy_autoload = $zmai_policy_store->database_values( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, $zmai_policy_option ) );
zmai_policy_expect( true === $zmai_policy_saved, 'valid policy saves successfully' );
zmai_policy_expect( array( 'schema' => 1, 'mode' => 'roles', 'roles' => array( $zmai_policy_custom ) ) === zmai_policy_raw( $zmai_policy_store ), 'saved policy adds schema without changing submitted fields' );
zmai_policy_expect( 1 === count( $zmai_policy_autoload ) && ! in_array( $zmai_policy_autoload[0], array( 'yes', 'on', 'auto', 'auto-on' ), true ), 'policy option is nonautoloaded' );

wp_set_option_autoload_values( array( $zmai_policy_option => true ) );
$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_custom ) ) );
$autoload_repaired = $zmai_policy_store->database_values( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, $zmai_policy_option ) );
zmai_policy_expect( ! in_array( $autoload_repaired[0], wp_autoload_values_to_autoload(), true ), 'unchanged explicit save repairs an autoloaded policy' );
$zmai_policy_old = zmai_policy_raw( $zmai_policy_store );
$zmai_policy_hold = static function ( $value, $old_value ) {
	return $old_value;
};
add_filter( 'pre_update_option_' . $zmai_policy_option, $zmai_policy_hold, 10, 2 );
$zmai_policy_failed_write = $zmai_policy->save_policy( array( 'mode' => 'legacy', 'roles' => array() ) );
remove_filter( 'pre_update_option_' . $zmai_policy_option, $zmai_policy_hold, 10 );
zmai_policy_expect( is_wp_error( $zmai_policy_failed_write ), 'a write that does not persist is reported as an error' );
zmai_policy_expect( $zmai_policy_old === zmai_policy_raw( $zmai_policy_store ), 'a failed checked write preserves the previous setting' );

update_option( $zmai_policy_option, array( 'schema' => 1, 'mode' => 'roles', 'roles' => 'broken' ), false );
zmai_policy_expect( 'invalid' === $zmai_policy->get_policy()['mode'], 'corrupt stored policy is reported as invalid' );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_custom_user ), 'corrupt stored policy denies uploads' );

$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_custom ) ) );
remove_role( $zmai_policy_custom );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_custom_user ), 'a removed selected role is inert' );
zmai_policy_add_role( $zmai_policy_custom, array( 'read' => true ) );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_custom_user ), 'recreating the same role slug restores its match' );

// A4: role decisions read the in-memory role table, so a role absent from the database option still counts.
$zmai_policy_virtual      = 'zmai_policy_virtual_' . $zmai_policy_suffix;
$zmai_policy_virtual_def  = array( 'name' => 'ZMAI policy virtual', 'capabilities' => array( 'read' => true ) );
$zmai_policy_virtual_user = zmai_policy_user( 'subscriber', 'virtual' );
update_user_meta( $zmai_policy_virtual_user, $wpdb->get_blog_prefix() . 'capabilities', array( $zmai_policy_virtual => true ) );
$zmai_policy_roles_before  = wp_roles()->roles;
$zmai_policy_global_before = isset( $GLOBALS['wp_user_roles'] ) ? $GLOBALS['wp_user_roles'] : null;
try {
	$GLOBALS['wp_user_roles'] = $zmai_policy_roles_before + array( $zmai_policy_virtual => $zmai_policy_virtual_def );
	wp_roles()->for_site( get_current_blog_id() );
	zmai_policy_expect( isset( wp_roles()->roles[ $zmai_policy_virtual ] ), 'fixture: the global wp_user_roles supplies the virtual role' );
	zmai_policy_expect( true === $zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_virtual ) ) ), 'save_policy accepts a virtual role from the global wp_user_roles' );
	zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_virtual_user ), 'the global wp_user_roles is authoritative without a database match' );
} finally {
	if ( null === $zmai_policy_global_before ) {
		unset( $GLOBALS['wp_user_roles'] );
	} else {
		$GLOBALS['wp_user_roles'] = $zmai_policy_global_before;
	}
	wp_roles()->roles = $zmai_policy_roles_before;
}

$zmai_policy_roles_filter = 'pre_option_' . $wpdb->get_blog_prefix() . 'user_roles';
try {
	wp_roles()->roles[ $zmai_policy_virtual ] = $zmai_policy_virtual_def;
	add_filter( $zmai_policy_roles_filter, '__return_empty_array' );
	zmai_policy_expect( true === $zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_virtual ) ) ), 'save_policy accepts a virtual role while the roles option is filtered' );
	zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_virtual_user ), 'a pre_option roles filter does not change the upload decision' );
} finally {
	remove_filter( $zmai_policy_roles_filter, '__return_empty_array' );
	wp_roles()->roles = $zmai_policy_roles_before;
}

// Rendering must not invalidate the global user cache.
$zmai_policy_clean_calls = 0;
$zmai_policy_count_clean = static function () use ( &$zmai_policy_clean_calls ) {
	++$zmai_policy_clean_calls;
};
$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( $zmai_policy_custom ) ) );
add_action( 'clean_user_cache', $zmai_policy_count_clean );
$zmai_policy_changed = wp_cache_get_last_changed( 'users' );
$zmai_policy->can_upload( $zmai_policy_custom_user );
remove_action( 'clean_user_cache', $zmai_policy_count_clean );
zmai_policy_expect( $zmai_policy_changed === wp_cache_get_last_changed( 'users' ), 'rendering can_upload leaves the user-query last_changed unchanged' );
zmai_policy_expect( 0 === $zmai_policy_clean_calls, 'rendering can_upload does not call clean_user_cache' );

if ( is_multisite() ) {
$zmai_policy_network = get_network();
$zmai_policy_site = wpmu_create_blog( $zmai_policy_network->domain, trailingslashit( $zmai_policy_network->path ) . $zmai_policy_prefix . '/', 'ZMAI policy fixture', get_current_user_id() );
if ( is_wp_error( $zmai_policy_site ) ) {
	throw new RuntimeException( 'Could not create the temporary multisite policy fixture.' );
}
add_user_to_blog( $zmai_policy_site, $zmai_policy_role_users['subscriber'], 'subscriber' );
switch_to_blog( $zmai_policy_site );
$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( 'author' ) ) );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_role_users['subscriber'] ), 'secondary site role policy is isolated from the main site' );
( new WP_User( $zmai_policy_role_users['subscriber'] ) )->set_role( 'author' );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_role_users['subscriber'] ), 'fresh role membership admits the selected secondary-site role' );
$zmai_policy_nonmember = zmai_policy_user( 'subscriber', 'nonmember' );
remove_user_from_blog( $zmai_policy_nonmember, $zmai_policy_site );
$zmai_policy_grant_read = static function ( $allcaps, $caps, $args, $user ) use ( $zmai_policy_nonmember ) {
	if ( (int) $user->ID === $zmai_policy_nonmember ) {
		$allcaps['read'] = true;
	}
	return $allcaps;
};
add_filter( 'user_has_cap', $zmai_policy_grant_read, 10, 4 );
$zmai_policy->save_policy( array( 'mode' => 'legacy', 'roles' => array() ) );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_nonmember ), 'legacy mode preserves a nonmember individual read filter' );
$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( 'subscriber' ) ) );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_nonmember ), 'read-capable nonmembers are denied selected-role uploads' );
grant_super_admin( $zmai_policy_nonmember );
$zmai_policy->save_policy( array( 'mode' => 'legacy', 'roles' => array() ) );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_nonmember ), 'legacy mode preserves a nonmember super administrator' );
$zmai_policy->save_policy( array( 'mode' => 'roles', 'roles' => array( 'subscriber' ) ) );
zmai_policy_expect( ! $zmai_policy->can_upload_fresh( $zmai_policy_nonmember ), 'super administrator without current-site membership is denied' );
remove_filter( 'user_has_cap', $zmai_policy_grant_read, 10 );
revoke_super_admin( $zmai_policy_nonmember );
restore_current_blog();
}

$zmai_policy->save_policy( array( 'mode' => 'legacy', 'roles' => array() ) );
zmai_policy_expect( $zmai_policy->can_upload_fresh( $zmai_policy_role_users['subscriber'] ), 'main-site legacy policy remains independent of secondary-site policy' );

if ( get_current_blog_id() !== $zmai_policy_primary ) { restore_current_blog(); }
if ( $zmai_policy_site ) {
	wpmu_delete_blog( $zmai_policy_site, true );
}
foreach ( $zmai_policy_users as $zmai_policy_user_id ) {
	if ( is_multisite() && function_exists( 'wpmu_delete_user' ) ) {
		wpmu_delete_user( $zmai_policy_user_id );
	} else {
		wp_delete_user( $zmai_policy_user_id );
	}
}
remove_role( $zmai_policy_custom );
remove_role( $zmai_policy_no_read );
if ( $zmai_policy_original !== $zmai_policy_missing ) {
	update_option( $zmai_policy_option, $zmai_policy_original, false );
	if ( $zmai_policy_original_autoload ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET autoload = %s WHERE option_name = %s', $wpdb->options, $zmai_policy_original_autoload[0], $zmai_policy_option ) );
		$zmai_policy_store->clear_option_cache( $zmai_policy_option );
	}
} else {
	delete_option( $zmai_policy_option );
}

wp_set_current_user( $zmai_policy_old_current );
echo 'CHECKS ' . $zmai_policy_checks . "\n";
if ( $zmai_policy_failures ) {
	echo 'FAILURES ' . implode( ' | ', $zmai_policy_failures ) . "\n";
	exit( 1 );
}
echo "RESULT PASS\n";
