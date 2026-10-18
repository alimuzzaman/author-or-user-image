<?php
/** Network-wide avatar cleanup for actual Multisite user deletion. */
use AuthorOrUserImage\Image_Store;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use only a disposable local multisite WordPress test instance.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$zmai_netdel_checks    = 0;
$zmai_netdel_failures  = array();
$zmai_netdel_artifacts = array();
$zmai_netdel_expect    = static function ( $condition, $label ) use ( &$zmai_netdel_checks, &$zmai_netdel_failures ) {
	++$zmai_netdel_checks;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $condition ) {
		$zmai_netdel_failures[] = $label;
	}
};

/** Keep a test-created file on the cleanup list as soon as it exists. */
$zmai_netdel_image = static function ( $name ) use ( &$zmai_netdel_artifacts ) {
	$uploads = wp_upload_dir();
	if ( ! wp_mkdir_p( $uploads['path'] ) ) {
		throw new RuntimeException( 'Could not create a fixture upload directory.' );
	}
	$path  = trailingslashit( $uploads['path'] ) . sanitize_file_name( $name . '-' . uniqid() . '.jpg' );
	$image = imagecreatetruecolor( 320, 240 );
	if ( ! $image || ! imagejpeg( $image, $path ) ) {
		if ( $image ) {
			if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
		}
		throw new RuntimeException( 'Could not create a JPEG fixture.' );
	}
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
	$zmai_netdel_artifacts[] = $path;
	return $path;
};

/** Make a unique admin-authored attachment, optionally owned by the plugin. */
$zmai_netdel_attachment = static function ( $title, $author_id, $owner = null ) use ( $zmai_netdel_image ) {
	$path = $zmai_netdel_image( $title );
	$id   = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'post_author'    => (int) $author_id,
		),
		$path,
		0,
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Could not create an attachment fixture.' );
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	if ( null !== $owner ) {
		add_post_meta( $id, Image_Store::ATTACHMENT_OWNER_META, (string) $owner, true );
	}
	return (int) $id;
};

/** Capture every recorded image path before WordPress deletes its metadata. */
$zmai_netdel_attachment_files = static function ( $attachment_id ) {
	$file  = get_attached_file( $attachment_id, true );
	$meta  = wp_get_attachment_metadata( $attachment_id, true );
	$backs = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
	$files = $file ? array( $file ) : array();
	if ( $file && is_array( $meta ) ) {
		foreach ( array( 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ) as $key ) {
			if ( ! empty( $meta[ $key ] ) ) {
				$files[] = path_join( dirname( $file ), $meta[ $key ] );
			}
		}
		foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = path_join( dirname( $file ), $size['file'] );
			}
		}
	}
	foreach ( $file && is_array( $backs ) ? $backs : array() as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$files[] = path_join( dirname( $file ), $size['file'] );
		}
	}
	return array_values( array_unique( $files ) );
};

$zmai_netdel_option_snapshot = static function ( $key ) {
	$sentinel = 'zmai-netdel-missing-' . uniqid();
	$value    = get_option( $key, $sentinel );
	return array( 'exists' => $sentinel !== $value, 'value' => $value );
};

$zmai_netdel_restore_option = static function ( $key, $snapshot ) {
	if ( $snapshot['exists'] ) {
		update_option( $key, $snapshot['value'], false );
	} else {
		delete_option( $key );
	}
};

$zmai_netdel_remove_attachment = static function ( $id, $files ) {
	if ( $id && get_post( $id ) ) {
		wp_delete_attachment( $id, true );
	}
	foreach ( $files as $path ) {
		clearstatcache( true, $path );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
};

$initial_site_id      = get_current_blog_id();
$admin_id             = 1;
$prefix               = 'zmai-netdel-' . strtolower( wp_generate_password( 8, false, false ) );
$subject_id           = 0;
$reuse_id             = 0;
$deletion_attempted   = false;
$reuse_delete_attempted = false;
$created_site_ids     = array();
$site_ids             = array( $initial_site_id );
$site_fixtures        = array();
$probe_results        = array();
$probe                 = null;
$legacy_directory     = WP_CONTENT_DIR . '/uploads/author-image';
$legacy_path          = $legacy_directory . '/' . $prefix . '.jpg';
$legacy_directory_new = ! is_dir( $legacy_directory );
$legacy_file_created  = false;
$delete_result        = false;
$store                = new Image_Store();

try {
	wp_set_current_user( $admin_id );
	$network = get_network();
	if ( ! $network || ! user_can( $admin_id, 'manage_options' ) ) {
		throw new RuntimeException( 'A local network administrator is required for the fixture.' );
	}
	if ( file_exists( $legacy_path ) ) {
		throw new RuntimeException( 'The unique shared legacy fixture path already exists.' );
	}
	if ( ! wp_mkdir_p( $legacy_directory ) ) {
		throw new RuntimeException( 'Could not create the shared legacy fixture directory.' );
	}
	$image = imagecreatetruecolor( 320, 240 );
	if ( ! $image || ! imagejpeg( $image, $legacy_path ) ) {
		if ( $image ) {
			if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
		}
		throw new RuntimeException( 'Could not create the shared legacy JPEG fixture.' );
	}
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
	$legacy_file_created = true;
	$zmai_netdel_artifacts[] = $legacy_path;

	$subject_id = wp_insert_user(
		array(
			'user_login' => $prefix,
			'user_email' => $prefix . '@example.invalid',
			'user_pass'  => wp_generate_password( 24, true, true ),
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $subject_id ) ) {
		throw new RuntimeException( 'Could not create the unique deletion subject.' );
	}
	$subject_id = (int) $subject_id;

	$preexisting_sites = array_map(
		'intval',
		get_sites( array( 'fields' => 'ids', 'number' => 0, 'orderby' => 'id', 'order' => 'ASC' ) )
	);
	$site_slug = static function ( $suffix ) use ( $prefix ) {
		return $prefix . '-' . $suffix;
	};
	foreach ( array( 'second', 'departed' ) as $suffix ) {
		$blog_id = wpmu_create_blog(
			$network->domain,
			trailingslashit( $network->path ) . $site_slug( $suffix ) . '/',
			'Network deletion fixture ' . $suffix,
			$admin_id
		);
		if ( is_wp_error( $blog_id ) ) {
			throw new RuntimeException( 'Could not create a fixture site.' );
		}
		$created_site_ids[] = (int) $blog_id;
		$site_ids[]         = (int) $blog_id;
		if ( ! add_user_to_blog( $blog_id, $subject_id, 'subscriber' ) ) {
			throw new RuntimeException( 'Could not add the subject to a fixture site.' );
		}
	}

	$legacy_was_shared = true;
	$import_method      = new ReflectionMethod( AuthorOrUserImage\Migration::class, 'sideload_legacy_image' );
	if ( PHP_VERSION_ID < 80100 ) { $import_method->setAccessible( true ); }
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		try {
			$site_store = new Image_Store();
			$site_store->register_image_size();
			$default_before = array(
				Image_Store::DEFAULT_OPTION          => $zmai_netdel_option_snapshot( Image_Store::DEFAULT_OPTION ),
				Image_Store::MIGRATED_DEFAULT_OPTION => $zmai_netdel_option_snapshot( Image_Store::MIGRATED_DEFAULT_OPTION ),
			);
			$imported = $import_method->invoke( new AuthorOrUserImage\Migration( $site_store ), $legacy_path, $subject_id );
			$avatar_id = $site_store->get_user_attachment_id( $subject_id );
			$avatar    = $avatar_id ? get_post( $avatar_id ) : null;
			$avatar_files = $avatar_id ? $zmai_netdel_attachment_files( $avatar_id ) : array();
			$zmai_netdel_expect( true === $imported && $avatar_id > 0 && $avatar, 'legacy avatar imports on site ' . $site_id );
			$zmai_netdel_expect( $avatar && (int) $avatar->post_author === $admin_id && (int) $avatar->post_author !== $subject_id, 'migrated attachment is admin-authored on site ' . $site_id );
			$zmai_netdel_expect( $avatar && get_post_meta( $avatar_id, Image_Store::ATTACHMENT_OWNER_META, true ) === (string) $subject_id, 'migrated attachment has the subject ownership marker on site ' . $site_id );

			$default_id    = $zmai_netdel_attachment( $prefix . '-default', $admin_id, 'default' );
			$default_files = $zmai_netdel_attachment_files( $default_id );
			update_option( Image_Store::DEFAULT_OPTION, $default_id, false );
			$unrelated_id    = $zmai_netdel_attachment( $prefix . '-unrelated', $admin_id );
			$unrelated_files = $zmai_netdel_attachment_files( $unrelated_id );
			$site_fixtures[ $site_id ] = array(
				'avatar_id'      => $avatar_id,
				'avatar_files'   => $avatar_files,
				'default_id'     => $default_id,
				'default_files'  => $default_files,
				'default_before' => $default_before,
				'unrelated_id'   => $unrelated_id,
				'unrelated_files'=> $unrelated_files,
			);
		} finally {
			restore_current_blog();
		}
	}

	$departed_site_id = end( $created_site_ids );
	remove_user_from_blog( $subject_id, $departed_site_id );
	$member_ids = array_map(
		static function ( $blog ) {
			return (int) $blog->userblog_id;
		},
		get_blogs_of_user( $subject_id )
	);
	$zmai_netdel_expect( ! in_array( $departed_site_id, $member_ids, true ), 'third-site membership is removed while its migrated avatar remains' );
	$zmai_netdel_expect( count( $preexisting_sites ) >= 100, 'fixture network already has at least 100 sites for the pagination case' );
	$first_page_ids = array_slice( $preexisting_sites, 0, 100 );
	$fixture_after_first_page = count( array_filter( $created_site_ids, static function ( $id ) use ( $first_page_ids ) {
		return ! in_array( (int) $id, $first_page_ids, true );
	} ) );
	$zmai_netdel_expect( 2 === $fixture_after_first_page, 'both newly created sites are beyond the first 100-site page' );

	$probe = static function ( $deleted_id ) use ( $subject_id, $site_ids, &$probe_results ) {
		if ( (int) $deleted_id !== (int) $subject_id ) {
			return;
		}
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				$store = new Image_Store();
				$acquire = new ReflectionMethod( AuthorOrUserImage\Lock::class, 'acquire_lock' );
				if ( PHP_VERSION_ID < 80100 ) { $acquire->setAccessible( true ); }
				$lock = $acquire->invoke( $store->get_lock(), 'zmai_avatar_lock_user_' . $subject_id );
				$probe_results[ $site_id ] = is_wp_error( $lock ) && 'zmai_busy' === $lock->get_error_code();
				if ( is_array( $lock ) ) {
					$release = new ReflectionMethod( AuthorOrUserImage\Lock::class, 'release_lock' );
					if ( PHP_VERSION_ID < 80100 ) { $release->setAccessible( true ); }
					$release->invoke( $store->get_lock(), $lock );
				}
			} finally {
				restore_current_blog();
			}
		}
	};
	add_action( 'wpmu_delete_user', $probe, 20, 2 );
	$deletion_attempted = true;
	$delete_result      = wpmu_delete_user( $subject_id );
	remove_action( 'wpmu_delete_user', $probe, 20 );
	$zmai_netdel_expect( true === $delete_result && ! get_userdata( $subject_id ), 'actual wpmu_delete_user removes the subject account' );
	foreach ( $site_ids as $site_id ) {
		$zmai_netdel_expect( ! empty( $probe_results[ $site_id ] ), 'site ' . $site_id . ' owner fence rejects mutation during wpmu_delete_user' );
	}
	$remaining_user_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->usermeta WHERE user_id = %d", $subject_id ) );
	$zmai_netdel_expect( 0 === $remaining_user_meta, 'network deletion removes the subject user metadata rows' );
	clearstatcache( true, $legacy_path );
	$zmai_netdel_expect( ! is_file( $legacy_path ), 'network deletion removes the shared legacy username.jpg' );

	foreach ( $site_fixtures as $site_id => $fixture ) {
		switch_to_blog( $site_id );
		try {
			$avatar_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE ID = %d", $fixture['avatar_id'] ) );
			$avatar_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE post_id = %d", $fixture['avatar_id'] ) );
			$zmai_netdel_expect( 0 === $avatar_row && 0 === $avatar_meta && ! get_post( $fixture['avatar_id'] ), 'subject attachment row and metadata are removed on site ' . $site_id );
			$avatar_files_gone = true;
			foreach ( $fixture['avatar_files'] as $path ) {
				clearstatcache( true, $path );
				$avatar_files_gone = $avatar_files_gone && ! is_file( $path );
			}
			$zmai_netdel_expect( $avatar_files_gone, 'subject attachment files are removed on site ' . $site_id );
			$default_files_present = true;
			foreach ( $fixture['default_files'] as $path ) {
				$default_files_present = $default_files_present && is_file( $path );
			}
			$unrelated_files_present = true;
			foreach ( $fixture['unrelated_files'] as $path ) {
				$unrelated_files_present = $unrelated_files_present && is_file( $path );
			}
			$zmai_netdel_expect( $fixture['default_id'] === (int) get_option( Image_Store::DEFAULT_OPTION ) && get_post( $fixture['default_id'] ) && $default_files_present, 'site default attachment and files are preserved on site ' . $site_id );
			$zmai_netdel_expect( get_post( $fixture['unrelated_id'] ) && $unrelated_files_present, 'unrelated attachment and files are preserved on site ' . $site_id );
			$lock_name = 'zmai_avatar_lock_user_' . $subject_id;
			$lock_row  = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name = %s", $lock_name ) );
			$zmai_netdel_expect( null === $lock_row, 'site ' . $site_id . ' owner fence is released after deletion' );
		} finally {
			restore_current_blog();
		}
	}

	$reuse_id = wp_insert_user(
		array(
			'user_login' => $prefix,
			'user_email' => $prefix . '-reuse@example.invalid',
			'user_pass'  => wp_generate_password( 24, true, true ),
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $reuse_id ) ) {
		throw new RuntimeException( 'Could not recreate the deleted username for fallback verification.' );
	}
	$reuse_id = (int) $reuse_id;
	$reuse_store = new Image_Store();
	$zmai_netdel_expect( ! $reuse_store->get_url( $reuse_id, 48, false ), 'reused username cannot resolve the deleted user legacy fallback' );
	$reuse_avatar = get_avatar_data( $reuse_id, array( 'size' => 48 ) );
	$legacy_url   = trailingslashit( content_url( '/uploads/author-image' ) ) . rawurlencode( $prefix ) . '.jpg';
	$zmai_netdel_expect( false === strpos( (string) $reuse_avatar['url'], $legacy_url ), 'WordPress avatar rendering does not return the old fallback after username reuse' );
	$reuse_delete_attempted = true;
	wpmu_delete_user( $reuse_id );
} catch ( Throwable $error ) {
	$zmai_netdel_failures[] = 'fixture execution: ' . $error->getMessage();
	echo 'FAIL fixture execution: ' . $error->getMessage() . "\n";
} finally {
	if ( $probe ) {
		remove_action( 'wpmu_delete_user', $probe, 20 );
	}
	if ( $reuse_id && ! $reuse_delete_attempted && get_userdata( $reuse_id ) ) {
		wpmu_delete_user( $reuse_id );
	}
	if ( $subject_id && ! $deletion_attempted && get_userdata( $subject_id ) ) {
		wpmu_delete_user( $subject_id );
	}

	foreach ( $site_fixtures as $site_id => $fixture ) {
		switch_to_blog( $site_id );
		try {
			foreach ( $fixture['default_before'] as $key => $snapshot ) {
				$zmai_netdel_restore_option( $key, $snapshot );
			}
			$zmai_netdel_remove_attachment( $fixture['avatar_id'], $fixture['avatar_files'] );
			$zmai_netdel_remove_attachment( $fixture['default_id'], $fixture['default_files'] );
			$zmai_netdel_remove_attachment( $fixture['unrelated_id'], $fixture['unrelated_files'] );
		} finally {
			restore_current_blog();
		}
	}

	if ( $legacy_file_created && is_file( $legacy_path ) ) {
		wp_delete_file( $legacy_path );
	}
	foreach ( array_reverse( $created_site_ids ) as $site_id ) {
		if ( get_site( $site_id ) ) {
			wpmu_delete_blog( $site_id, true );
		}
	}
	if ( $subject_id && get_userdata( $subject_id ) ) {
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				remove_user_from_blog( $subject_id, $site_id );
			} finally {
				restore_current_blog();
			}
		}
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $subject_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->users, array( 'ID' => $subject_id ), array( '%d' ) );
		clean_user_cache( $subject_id );
	}
	if ( $reuse_id && get_userdata( $reuse_id ) ) {
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $reuse_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->users, array( 'ID' => $reuse_id ), array( '%d' ) );
		clean_user_cache( $reuse_id );
	}
	if ( $legacy_directory_new && is_dir( $legacy_directory ) ) {
		$remaining = glob( trailingslashit( $legacy_directory ) . '*' );
		if ( empty( $remaining ) ) {
			rmdir( $legacy_directory );
		}
	}
}

echo wp_json_encode( array( 'checks' => $zmai_netdel_checks, 'failures' => $zmai_netdel_failures ) ) . "\n";
WP_CLI::halt( $zmai_netdel_failures ? 1 : 0 );
