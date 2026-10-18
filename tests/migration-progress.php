<?php
/**
 * Real WordPress migration retry and pagination checks.
 * Run with wp eval-file tests/migration-progress.php in a disposable local site.
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Store;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use only a disposable local WordPress test instance.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

global $zmai_mp_checks, $zmai_mp_failures, $zmai_mp_users;
$zmai_mp_checks   = 0;
$zmai_mp_failures = array();
$zmai_mp_users    = array();
$zmai_mp_locks    = array();
$zmai_mp_options  = array();
$zmai_mp_missing  = new stdClass();
$zmai_mp_option_names = array(
	Image_Store::DEFAULT_OPTION,
	Image_Store::MIGRATED_DEFAULT_OPTION,
	Image_Store::VERSION_OPTION,
	Image_Store::MIGRATION_OPTION,
	'zmai_migration_retry',
);
foreach ( $zmai_mp_option_names as $name ) {
	$value = get_option( $name, $zmai_mp_missing );
	$zmai_mp_options[ $name ] = $value === $zmai_mp_missing ? array( 'exists' => false ) : array( 'exists' => true, 'value' => $value );
}

$zmai_mp_default_path = WP_CONTENT_DIR . '/uploads/author-image/author_default.jpg';
$zmai_mp_had_default  = is_file( $zmai_mp_default_path );
$zmai_mp_default_data = $zmai_mp_had_default ? file_get_contents( $zmai_mp_default_path ) : null;
if ( $zmai_mp_had_default && false === $zmai_mp_default_data ) {
	throw new RuntimeException( 'Could not preserve the existing legacy default fixture.' );
}

/** Record an assertion while allowing independent migration checks to continue. */
function zmai_migration_progress_expect( $condition, $label ) {
	global $zmai_mp_checks, $zmai_mp_failures;
	++$zmai_mp_checks;
	if ( ! $condition ) {
		$zmai_mp_failures[] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/** Create users that occupy consecutive entries in the ID-ordered migration query. */
function zmai_migration_progress_users( $tag, $count ) {
	global $zmai_mp_users;
	$ids = array();
	for ( $i = 0; $i < $count; ++$i ) {
		$login = $tag . '-' . $i;
		$id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.invalid',
				'user_pass'  => 'public-local-test-fixture',
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Could not create migration fixture user: ' . $id->get_error_code() );
		}
		$ids[] = (int) $id;
		$zmai_mp_users[] = (int) $id;
	}
	sort( $ids, SORT_NUMERIC );
	return $ids;
}

/** Find the first fixture's offset and verify the fixtures form a contiguous ID-ordered block. */
function zmai_migration_progress_offset( $ids ) {
	$all_ids = array_map( 'intval', get_users( array( 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) ) );
	$offset  = array_search( (int) $ids[0], $all_ids, true );
	if ( false === $offset || array_slice( $all_ids, $offset, count( $ids ) ) !== array_values( $ids ) ) {
		throw new RuntimeException( 'Migration fixture users are not a contiguous ID-ordered block.' );
	}
	return $offset;
}

/** Write a valid JPEG using the same real image path used by the migration fixtures. */
function zmai_migration_progress_valid_jpeg( $path ) {
	$image = imagecreatetruecolor( 320, 240 );
	if ( ! $image || ! imagejpeg( $image, $path ) ) {
		if ( $image ) {
			if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
		}
		throw new RuntimeException( 'Could not write the valid JPEG fixture.' );
	}
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
}

/** Write plain text with a .jpg suffix so core sideload MIME validation rejects it. */
function zmai_migration_progress_bad_jpg( $path ) {
	if ( false === file_put_contents( $path, 'This is text, not a JPEG image.' ) ) {
		throw new RuntimeException( 'Could not write the rejected JPG fixture.' );
	}
}

/** Return the attachment recorded by legacy migration, or zero when it is still retryable. */
function zmai_migration_progress_user_attachment( $user_id ) {
	$key = Image_Store::user_meta_key( Image_Store::MIGRATED_USER_META );
	return absint( get_user_meta( $user_id, $key, true ) );
}

/** Return whether the user has a valid imported Media Library image. */
function zmai_migration_progress_user_imported( $user_id ) {
	$id = zmai_migration_progress_user_attachment( $user_id );
	return $id > 0 && get_post( $id ) && wp_attachment_is_image( $id );
}

/** Run migration until a condition is met, while keeping each scenario bounded. */
function zmai_migration_progress_until( $store, $condition ) {
	$population = count( get_users( array( 'fields' => 'ID' ) ) );
	$max_calls = 2 * ( (int) ceil( $population / 20 ) + 2 );
	for ( $call = 0; $call < $max_calls; ++$call ) {
		( new AuthorOrUserImage\Migration( $store ) )->maybe_migrate();
		if ( call_user_func( $condition ) ) {
			return true;
		}
	}
	return false;
}

/** Release only a fixture option row whose stored token still matches exactly. */
function zmai_migration_progress_release_option( $name, $token ) {
	global $wpdb;
	$table = $wpdb->options;
	$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE option_name = %s AND HEX(option_value) = HEX(%s)", $name, $token ) );
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return 1 === $deleted;
}

$store = new Image_Store();
wp_set_current_user( 1 );
$zmai_mp_legacy_files = array();
$zmai_mp_attachment_ids = array();
$zmai_mp_tag = 'zmai-mp-' . strtolower( wp_generate_password( 8, false, false ) );

try {
	$store->register_image_size();
	wp_mkdir_p( dirname( $zmai_mp_default_path ) );

	// An invalid user JPG in page one must not pin the migration before page two.
	$users = zmai_migration_progress_users( $zmai_mp_tag . '-bad', 22 );
	$start = zmai_migration_progress_offset( $users );
	$bad_user = $users[0];
	$later_user = $users[21];
	$bad_path = WP_CONTENT_DIR . '/uploads/author-image/' . get_userdata( $bad_user )->user_login . '.jpg';
	$later_path = WP_CONTENT_DIR . '/uploads/author-image/' . get_userdata( $later_user )->user_login . '.jpg';
	$zmai_mp_legacy_files[] = $bad_path;
	$zmai_mp_legacy_files[] = $later_path;
	zmai_migration_progress_bad_jpg( $bad_path );
	zmai_migration_progress_valid_jpeg( $later_path );
	wp_delete_file( $zmai_mp_default_path );
	delete_option( Image_Store::DEFAULT_OPTION );
	delete_option( Image_Store::MIGRATED_DEFAULT_OPTION );
	delete_option( Image_Store::VERSION_OPTION );
	delete_option( 'zmai_migration_retry' );
	update_option( Image_Store::MIGRATION_OPTION, $start, false );
	$found_later = zmai_migration_progress_until( $store, function () use ( $later_user ) {
		return zmai_migration_progress_user_imported( $later_user );
	} );
	zmai_migration_progress_expect( $found_later, 'bad user JPG in an early page does not block a valid avatar in a later page' );
	zmai_migration_progress_expect( 0 === zmai_migration_progress_user_attachment( $bad_user ), 'rejected user JPG remains without a migrated attachment' );
	zmai_migration_progress_expect( get_option( Image_Store::VERSION_OPTION ) !== ZMAI_VERSION, 'migration stays incomplete while the rejected user JPG remains' );
	if ( $id = zmai_migration_progress_user_attachment( $later_user ) ) {
		$zmai_mp_attachment_ids[] = $id;
	}

	// Repair the source without changing progress state: automatic sweep retry must find it.
	zmai_migration_progress_valid_jpeg( $bad_path );
	$repaired_user = zmai_migration_progress_until( $store, function () use ( $bad_user ) {
		return zmai_migration_progress_user_imported( $bad_user ) && get_option( Image_Store::VERSION_OPTION ) === ZMAI_VERSION;
	} );
	zmai_migration_progress_expect( $repaired_user, 'repaired user JPG is retried and the successful sweep completes' );
	if ( $id = zmai_migration_progress_user_attachment( $bad_user ) ) {
		$zmai_mp_attachment_ids[] = $id;
	}

	// A bad default is independent: it must not stop later user pages, and must retry after repair.
	$users = zmai_migration_progress_users( $zmai_mp_tag . '-default', 22 );
	$start = zmai_migration_progress_offset( $users );
	$later_default_user = $users[21];
	$later_default_path = WP_CONTENT_DIR . '/uploads/author-image/' . get_userdata( $later_default_user )->user_login . '.jpg';
	$zmai_mp_legacy_files[] = $later_default_path;
	zmai_migration_progress_valid_jpeg( $later_default_path );
	zmai_migration_progress_bad_jpg( $zmai_mp_default_path );
	delete_option( Image_Store::DEFAULT_OPTION );
	delete_option( Image_Store::MIGRATED_DEFAULT_OPTION );
	delete_option( Image_Store::VERSION_OPTION );
	delete_option( 'zmai_migration_retry' );
	update_option( Image_Store::MIGRATION_OPTION, $start, false );
	$found_later_default = zmai_migration_progress_until( $store, function () use ( $later_default_user ) {
		return zmai_migration_progress_user_imported( $later_default_user );
	} );
	zmai_migration_progress_expect( $found_later_default, 'failing default JPG does not block a valid user avatar in a later page' );
	zmai_migration_progress_expect( 0 === absint( get_option( Image_Store::MIGRATED_DEFAULT_OPTION, 0 ) ), 'rejected default JPG remains without a migrated attachment' );
	zmai_migration_progress_expect( get_option( Image_Store::VERSION_OPTION ) !== ZMAI_VERSION, 'migration stays incomplete while the default JPG remains rejected' );
	if ( $id = zmai_migration_progress_user_attachment( $later_default_user ) ) {
		$zmai_mp_attachment_ids[] = $id;
	}
	zmai_migration_progress_valid_jpeg( $zmai_mp_default_path );
	$repaired_default = zmai_migration_progress_until( $store, function () {
		return absint( get_option( Image_Store::MIGRATED_DEFAULT_OPTION, 0 ) ) > 0 && get_option( Image_Store::VERSION_OPTION ) === ZMAI_VERSION;
	} );
	zmai_migration_progress_expect( $repaired_default, 'repaired default JPG is retried and the successful sweep completes' );
	if ( $id = absint( get_option( Image_Store::MIGRATED_DEFAULT_OPTION, 0 ) ) ) {
		$zmai_mp_attachment_ids[] = $id;
	}

	// An existing per-user owner lock must leave that user pending while later pages advance.
	$users = zmai_migration_progress_users( $zmai_mp_tag . '-busy', 22 );
	$start = zmai_migration_progress_offset( $users );
	$busy_user = $users[0];
	$later_busy_user = $users[21];
	$busy_path = WP_CONTENT_DIR . '/uploads/author-image/' . get_userdata( $busy_user )->user_login . '.jpg';
	$later_busy_path = WP_CONTENT_DIR . '/uploads/author-image/' . get_userdata( $later_busy_user )->user_login . '.jpg';
	$zmai_mp_legacy_files[] = $busy_path;
	$zmai_mp_legacy_files[] = $later_busy_path;
	zmai_migration_progress_valid_jpeg( $busy_path );
	zmai_migration_progress_valid_jpeg( $later_busy_path );
	$lock_name = 'zmai_avatar_lock_user_' . $busy_user;
	$lock_token = 'owner-lock-fixture-' . wp_generate_uuid4();
	if ( ! add_option( $lock_name, $lock_token, '', false ) ) {
		throw new RuntimeException( 'Could not create the deterministic busy-owner fixture.' );
	}
	$zmai_mp_locks[ $lock_name ] = $lock_token;
	delete_option( Image_Store::VERSION_OPTION );
	delete_option( 'zmai_migration_retry' );
	update_option( Image_Store::MIGRATION_OPTION, $start, false );
	$found_later_busy = zmai_migration_progress_until( $store, function () use ( $later_busy_user ) {
		return zmai_migration_progress_user_imported( $later_busy_user );
	} );
	zmai_migration_progress_expect( $found_later_busy, 'busy owner lock does not block an unaffected user in a later page' );
	zmai_migration_progress_expect( 0 === zmai_migration_progress_user_attachment( $busy_user ), 'busy owner remains without a migrated attachment while locked' );
	zmai_migration_progress_expect( get_option( Image_Store::VERSION_OPTION ) !== ZMAI_VERSION, 'busy owner remains pending instead of completing migration' );
	if ( $id = zmai_migration_progress_user_attachment( $later_busy_user ) ) {
		$zmai_mp_attachment_ids[] = $id;
	}
	zmai_migration_progress_release_option( $lock_name, $lock_token );
	unset( $zmai_mp_locks[ $lock_name ] );
	$retried_busy = zmai_migration_progress_until( $store, function () use ( $busy_user ) {
		return zmai_migration_progress_user_imported( $busy_user ) && get_option( Image_Store::VERSION_OPTION ) === ZMAI_VERSION;
	} );
	zmai_migration_progress_expect( $retried_busy, 'busy owner is retried after its lock is released' );
	if ( $id = zmai_migration_progress_user_attachment( $busy_user ) ) {
		$zmai_mp_attachment_ids[] = $id;
	}

	// The migration-wide mutex must leave both progress markers untouched while held.
	$users = zmai_migration_progress_users( $zmai_mp_tag . '-mutex', 22 );
	$start = zmai_migration_progress_offset( $users );
	$mutex_users = array( $users[20], $users[21] );
	foreach ( $mutex_users as $mutex_user ) {
		$path = WP_CONTENT_DIR . '/uploads/author-image/' . get_userdata( $mutex_user )->user_login . '.jpg';
		$zmai_mp_legacy_files[] = $path;
		zmai_migration_progress_valid_jpeg( $path );
	}
	$mutex_name = 'zmai_avatar_lock_migration';
	$mutex_token = 'migration-lock-fixture-' . wp_generate_uuid4();
	if ( ! add_option( $mutex_name, $mutex_token, '', false ) ) {
		throw new RuntimeException( 'Could not create the deterministic migration-mutex fixture.' );
	}
	$zmai_mp_locks[ $mutex_name ] = $mutex_token;
	$mutex_offset = $start + 20;
	update_option( Image_Store::MIGRATION_OPTION, $mutex_offset, false );
	update_option( Image_Store::VERSION_OPTION, '0', false );
	delete_option( 'zmai_migration_retry' );
	( new AuthorOrUserImage\Migration( $store ) )->maybe_migrate();
	zmai_migration_progress_expect( absint( get_option( Image_Store::MIGRATION_OPTION, -1 ) ) === $mutex_offset && '0' === (string) get_option( Image_Store::VERSION_OPTION ), 'migration mutex leaves cursor and version unchanged while held' );
	zmai_migration_progress_expect( 0 === zmai_migration_progress_user_attachment( $mutex_users[0] ) && 0 === zmai_migration_progress_user_attachment( $mutex_users[1] ), 'migration mutex defers sideloads until released' );
	$released_mutex = zmai_migration_progress_release_option( $mutex_name, $mutex_token );
	unset( $zmai_mp_locks[ $mutex_name ] );
	zmai_migration_progress_expect( $released_mutex, 'migration fixture releases only its own exact mutex token' );
	$retried_after_mutex = zmai_migration_progress_until( $store, function () use ( $mutex_users ) {
		return zmai_migration_progress_user_imported( $mutex_users[0] ) && zmai_migration_progress_user_imported( $mutex_users[1] ) && get_option( Image_Store::VERSION_OPTION ) === ZMAI_VERSION;
	} );
	zmai_migration_progress_expect( $retried_after_mutex, 'migration resumes after the fixture mutex is released' );
} finally {
	foreach ( $zmai_mp_locks as $name => $token ) {
		zmai_migration_progress_release_option( $name, $token );
	}
	foreach ( $zmai_mp_users as $user_id ) {
		$id = zmai_migration_progress_user_attachment( $user_id );
		if ( $id ) {
			$zmai_mp_attachment_ids[] = $id;
		}
		if ( is_multisite() ) {
			// Fixture teardown, not another network lifecycle test per user. Every
			// user here was created on this site only; remove its recorded media below.
			global $wpdb;
			remove_user_from_blog( $user_id, get_current_blog_id() );
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->users, array( 'ID' => $user_id ), array( '%d' ) );
			clean_user_cache( $user_id );
		} else {
			wp_delete_user( $user_id );
		}
	}
	$default_id = absint( get_option( Image_Store::MIGRATED_DEFAULT_OPTION, 0 ) );
	$original_default = isset( $zmai_mp_options[ Image_Store::MIGRATED_DEFAULT_OPTION ]['value'] ) ? absint( $zmai_mp_options[ Image_Store::MIGRATED_DEFAULT_OPTION ]['value'] ) : 0;
	if ( $default_id && $default_id !== $original_default ) {
		$zmai_mp_attachment_ids[] = $default_id;
	}
	foreach ( array_unique( array_filter( array_map( 'absint', $zmai_mp_attachment_ids ) ) ) as $attachment_id ) {
		if ( get_post( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
	foreach ( array_unique( $zmai_mp_legacy_files ) as $path ) {
		wp_delete_file( $path );
	}
	if ( $zmai_mp_had_default ) {
		file_put_contents( $zmai_mp_default_path, $zmai_mp_default_data );
	} else {
		wp_delete_file( $zmai_mp_default_path );
	}
	foreach ( $zmai_mp_options as $name => $snapshot ) {
		if ( $snapshot['exists'] ) {
			update_option( $name, $snapshot['value'], false );
		} else {
			delete_option( $name );
		}
	}
}

$summary = array( 'checks' => $zmai_mp_checks, 'failures' => $zmai_mp_failures );
echo wp_json_encode( $summary ) . "\n";
WP_CLI::halt( $zmai_mp_failures ? 1 : 0 );
