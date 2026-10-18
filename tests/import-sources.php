<?php
/** Source-adapter contracts on disposable WordPress. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WordPress required.' );
}

$sources_file = dirname( __DIR__ ) . '/includes/class-import-sources.php';
if ( is_file( $sources_file ) ) {
	require_once $sources_file;
}
global $sources_adapter, $source_checks, $source_failures, $source_user_id, $source_attachment_ids, $source_post_ids, $source_file_paths, $source_sla_filter, $source_had_sla_filter, $source_http_requests;
$sources_class = 'AuthorOrUserImage\\Import_Sources';
if ( ! class_exists( $sources_class ) ) {
	echo "FAIL Import_Sources class file/class is missing\n";
	WP_CLI::halt( 1 );
}
$sources_adapter = new $sources_class();

global $wpdb;
$source_checks  = 0;
$source_failures = array();
$source_user_id = 0;
$source_attachment_ids = array();
$source_post_ids = array();
$source_file_paths = array();
$source_site_id = get_current_blog_id();
$source_original_base_prefix = isset( $wpdb->base_prefix ) ? $wpdb->base_prefix : null;
$source_custom_base_prefix = 'zmai_import_test_' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '_';
$source_sla_settings = array( 'shared' => 0 );
$source_http_requests = 0;
$source_sla_filter = null;
$source_http_filter = null;

function import_sources_check( $condition, $label ) {
	global $source_checks, $source_failures;
	++$source_checks;
	if ( ! $condition ) {
		$source_failures[] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

function import_sources_fixture_file( $uploads, $label ) {
	global $source_file_paths;
	$path = trailingslashit( $uploads['path'] ) . 'zmai-import-' . sanitize_key( $label ) . '-' . uniqid() . '.png';
	$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true );
	if ( false === $png || false === file_put_contents( $path, $png ) || ! is_file( $path ) ) {
		throw new RuntimeException( 'Could not create source-image fixture.' );
	}
	$source_file_paths[] = $path;
	$image = @getimagesize( $path );
	if ( ! $image || 'image/png' !== $image['mime'] ) {
		throw new RuntimeException( 'Source-image fixture is not a readable PNG.' );
	}
	return $path;
}

function import_sources_fixture_attachment( $uploads, $label, $author_id ) {
	global $source_attachment_ids;
	$path = import_sources_fixture_file( $uploads, $label );
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Import source ' . sanitize_text_field( $label ),
			'post_status'    => 'inherit',
			'post_author'    => (int) $author_id,
		),
		$path
	);
	if ( is_wp_error( $id ) || ! $id ) {
		throw new RuntimeException( 'Could not create source attachment fixture.' );
	}
	$source_attachment_ids[] = (int) $id;
	return array( 'id' => (int) $id, 'path' => $path );
}

function import_sources_put_meta( $user_id, $key, $value ) {
	if ( metadata_exists( 'user', $user_id, $key ) ) {
		update_user_meta( $user_id, $key, $value );
	} else {
		add_user_meta( $user_id, $key, $value, true );
	}
}

function import_sources_snapshot() {
	global $wpdb, $source_user_id, $source_attachment_ids, $source_post_ids, $source_file_paths, $source_sla_filter, $source_had_sla_filter;
	$snapshot = array(
		'user_meta'   => $source_user_id ? serialize( get_user_meta( $source_user_id ) ) : null,
		'posts'       => array(),
		'files'       => array(),
		'options'     => array(),
		'base_prefix' => $wpdb->base_prefix,
	);
	foreach ( array( 'simple_local_avatars' ) as $name ) {
		if ( $source_had_sla_filter ) {
			remove_filter( 'pre_option_simple_local_avatars', $source_sla_filter, PHP_INT_MAX );
		}
		$missing = new stdClass();
		$value   = get_option( $name, $missing );
		if ( $source_had_sla_filter ) {
			add_filter( 'pre_option_simple_local_avatars', $source_sla_filter, PHP_INT_MAX );
		}
		$snapshot['options'][ $name ] = $value === $missing ? null : serialize( $value );
	}
	foreach ( array( 'simple_local_avatars_mode' ) as $name ) {
		$missing = new stdClass();
		$value   = get_site_option( $name, $missing );
		$snapshot['options'][ 'site:' . $name ] = $value === $missing ? null : serialize( $value );
	}
	foreach ( array_unique( array_merge( $source_post_ids, $source_attachment_ids ) ) as $post_id ) {
		$post = get_post( $post_id );
		$snapshot['posts'][ $post_id ] = array(
			'post' => $post ? serialize( (array) $post ) : null,
			'meta' => serialize( get_post_meta( $post_id ) ),
		);
	}
	foreach ( $source_file_paths as $path ) {
		$snapshot['files'][ $path ] = array(
			'is_link' => is_link( $path ),
			'hash'    => is_file( $path ) ? hash_file( 'sha256', $path ) : false,
		);
	}
	return $snapshot;
}

function import_sources_resolve_unchanged( $source, $label ) {
	global $sources_adapter, $source_user_id;
	$before = import_sources_snapshot();
	$result = $sources_adapter->resolve( $source, $source_user_id );
	import_sources_check( $before === import_sources_snapshot(), $label . ' leaves source metadata, posts, prefix and files unchanged' );
	return $result;
}

function import_sources_expect_file( $result, $expected_path, $label ) {
	$valid = is_array( $result )
		&& isset( $result['path'], $result['name'] )
		&& is_string( $result['path'] )
		&& is_string( $result['name'] )
		&& '' !== $result['name']
		&& realpath( $result['path'] ) === realpath( $expected_path );
	import_sources_check( $valid, $label );
}

function import_sources_expect_error( $result, $code, $label ) {
	import_sources_check( is_wp_error( $result ) && $code === $result->get_error_code(), $label );
}

$source_uploads = array();
$source_profile_meta_key = '';
$source_profile_image_key = '';
$source_one_avatar_key = '';
$source_sla_shared_key = '';
$source_sla_local_key = '';
$source_wp_avatars_key = 'wp_user_avatars';
$source_had_sla_filter = false;
$source_had_http_filter = false;

try {
	if ( null === $source_original_base_prefix ) {
		throw new RuntimeException( 'WordPress table prefix is unavailable.' );
	}
	$wpdb->base_prefix = $source_custom_base_prefix;
	$source_uploads = wp_upload_dir();
	if ( ! empty( $source_uploads['error'] ) || ! wp_mkdir_p( $source_uploads['path'] ) ) {
		throw new RuntimeException( 'Current-site uploads directory is unavailable.' );
	}
	$source_prefix = $wpdb->get_blog_prefix( $source_site_id );
	$source_sla_shared_key = 'simple_local_avatar';
	$source_sla_local_key = 'simple_local_avatar_' . $source_site_id;
	$source_one_avatar_key = $source_prefix . 'user_avatar';
	$source_profile_meta_key = $source_prefix . 'metronet_post_id';
	$source_profile_image_key = $source_prefix . 'metronet_image_id';
	$source_user_id = wp_insert_user(
		array(
			'user_login' => 'zmai-import-' . strtolower( wp_generate_password( 8, false ) ),
			'user_email' => 'zmai-import-' . uniqid() . '@example.invalid',
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $source_user_id ) ) {
		$source_user_id = 0;
		throw new RuntimeException( 'Could not create source-adapter fixture user.' );
	}
	if ( ! $source_user_id ) {
		throw new RuntimeException( 'Could not create source-adapter fixture user.' );
	}
	$source_user_id = (int) $source_user_id;

	$source_sla_media = import_sources_fixture_attachment( $source_uploads, 'sla-media', 1 );
	$source_oua_media = import_sources_fixture_attachment( $source_uploads, 'one-user-avatar', 1 );
	$source_wp_media = import_sources_fixture_attachment( $source_uploads, 'wp-user-avatars', 1 );
	$source_metronet_mirror = import_sources_fixture_attachment( $source_uploads, 'metronet-mirror', 1 );
	$source_metronet_thumbnail = import_sources_fixture_attachment( $source_uploads, 'metronet-thumbnail', 1 );
	$source_wp_full_path = import_sources_fixture_file( $source_uploads, 'wp-user-avatars-full' );
	$source_sla_full_path = import_sources_fixture_file( $source_uploads, 'sla-full' );

	$source_original_sla = get_option( 'simple_local_avatars', null );
	update_option( 'simple_local_avatars', $source_sla_settings, false );
	$source_http_filter = static function ( $preempt, $args = null, $url = null ) use ( &$source_http_requests ) {
		++$source_http_requests;
		return new WP_Error( 'zmai_import_test_network_blocked' );
	};
	add_filter( 'pre_http_request', $source_http_filter, PHP_INT_MAX, 3 );
	$source_had_http_filter = true;

	$registry = call_user_func( array( $sources_class, 'registry' ) );
	$expected_ids = array( 'simple-local-avatars', 'one-user-avatar', 'wp-user-avatars', 'metronet-profile-picture' );
	$registry_ids = is_array( $registry ) ? array_keys( $registry ) : array();
	sort( $expected_ids );
	sort( $registry_ids );
	$labels_valid = is_array( $registry );
	if ( $labels_valid ) {
		foreach ( $registry as $label ) {
			$labels_valid = $labels_valid && is_string( $label ) && '' !== trim( $label );
		}
	}
	import_sources_check( $expected_ids === $registry_ids && $labels_valid, 'registry exposes only the four fixed source IDs with labels' );

	import_sources_expect_error( import_sources_resolve_unchanged( 'simple-local-avatars', 'absent SLA source' ), 'zmai_import_no_source', 'absent SLA metadata reports no source' );
	import_sources_expect_error( import_sources_resolve_unchanged( 'one-user-avatar', 'absent One User Avatar source' ), 'zmai_import_no_source', 'absent One User Avatar metadata reports no source' );
	import_sources_expect_error( import_sources_resolve_unchanged( 'wp-user-avatars', 'absent WP User Avatars source' ), 'zmai_import_no_source', 'absent WP User Avatars metadata reports no source' );
	import_sources_expect_error( import_sources_resolve_unchanged( 'metronet-profile-picture', 'absent Metronet source' ), 'zmai_import_no_source', 'absent Metronet metadata reports no source' );
	import_sources_expect_error( import_sources_resolve_unchanged( array( 'bad-source' ), 'invalid source argument' ), 'zmai_import_invalid_source', 'non-scalar source argument reports invalid source' );
	import_sources_expect_error( import_sources_resolve_unchanged( 'unknown-avatar-source', 'unknown source ID' ), 'zmai_import_invalid_source', 'unknown source ID reports invalid source' );

	$source_sla_settings = array( 'shared' => 0 );
	import_sources_put_meta(
		$source_user_id,
		$source_sla_local_key,
		array( 'full' => $source_uploads['url'] . '/' . basename( $source_sla_full_path ), 'blog_id' => $source_site_id )
	);
	import_sources_put_meta(
		$source_user_id,
		$source_sla_shared_key,
		array( 'media_id' => $source_sla_media['id'], 'full' => wp_get_attachment_url( $source_sla_media['id'] ), 'blog_id' => $source_site_id )
	);
	if ( is_multisite() ) {
		import_sources_expect_file( import_sources_resolve_unchanged( 'simple-local-avatars', 'SLA nonshared resolution' ), $source_sla_full_path, 'nonshared SLA reads the current-blog suffixed key and local full URL' );
	} else {
		echo "MISSING multisite-only SLA nonshared-key case\n";
	}
	$source_sla_settings = array( 'shared' => 1 );
	update_option( 'simple_local_avatars', $source_sla_settings, false );
	import_sources_expect_file( import_sources_resolve_unchanged( 'simple-local-avatars', 'SLA shared resolution' ), $source_sla_media['path'], 'shared SLA reads the unsuffixed media ID and matching blog ID' );
	$resolver_before = import_sources_snapshot();
	$sla_resolver = $sources_adapter->resolver( 'simple-local-avatars', $source_user_id );
	import_sources_check( is_callable( $sla_resolver ) && $resolver_before === import_sources_snapshot(), 'resolver returns a callable without mutating source state' );
	import_sources_put_meta(
		$source_user_id,
		$source_sla_shared_key,
		array( 'media_id' => $source_oua_media['id'], 'full' => wp_get_attachment_url( $source_oua_media['id'] ), 'blog_id' => $source_site_id )
	);
	$resolver_call_before = import_sources_snapshot();
	$resolver_result = is_callable( $sla_resolver ) ? $sla_resolver() : null;
	import_sources_check( $resolver_call_before === import_sources_snapshot(), 'fresh resolver call leaves source metadata, posts and files unchanged' );
	import_sources_expect_file( $resolver_result, $source_oua_media['path'], 'resolver performs a fresh source lookup when invoked' );
	import_sources_put_meta(
		$source_user_id,
		$source_sla_shared_key,
		array( 'media_id' => $source_sla_media['id'], 'full' => wp_get_attachment_url( $source_sla_media['id'] ), 'blog_id' => $source_site_id )
	);
	import_sources_put_meta(
		$source_user_id,
		$source_sla_shared_key,
		array( 'media_id' => $source_sla_media['id'], 'full' => wp_get_attachment_url( $source_sla_media['id'] ), 'blog_id' => $source_site_id + 1 )
	);
	import_sources_expect_error( import_sources_resolve_unchanged( 'simple-local-avatars', 'SLA cross-site media record' ), 'zmai_import_other_site', 'SLA refuses a media record from another site' );

	$source_fallback_key = 'wp_user_avatar';
	import_sources_put_meta( $source_user_id, $source_fallback_key, $source_oua_media['id'] );
	if ( $source_one_avatar_key !== $source_fallback_key ) {
		import_sources_put_meta( $source_user_id, $source_one_avatar_key, 0 );
		import_sources_expect_error( import_sources_resolve_unchanged( 'one-user-avatar', 'empty prefixed One User Avatar row' ), 'zmai_import_no_source', 'explicit empty prefixed One User Avatar row suppresses legacy fallback' );
		delete_user_meta( $source_user_id, $source_one_avatar_key );
		if ( ! is_multisite() || 1 === (int) $source_site_id ) {
			import_sources_expect_file( import_sources_resolve_unchanged( 'one-user-avatar', 'absent prefixed One User Avatar row on main/single site' ), $source_oua_media['path'], 'absent prefixed One User Avatar row may use the historical main-site fallback' );
		} else {
			import_sources_expect_error( import_sources_resolve_unchanged( 'one-user-avatar', 'absent prefixed One User Avatar row on subsite' ), 'zmai_import_no_source', 'subsite does not mistake the main-site One User Avatar row for a local fallback' );
		}
	}
	import_sources_put_meta( $source_user_id, $source_one_avatar_key, $source_oua_media['id'] );
	import_sources_expect_file( import_sources_resolve_unchanged( 'one-user-avatar', 'prefixed One User Avatar row' ), $source_oua_media['path'], 'One User Avatar reads the current blog-prefixed attachment ID' );

	import_sources_put_meta(
		$source_user_id,
		$source_wp_avatars_key,
		array( 'media_id' => $source_wp_media['id'], 'site_id' => $source_site_id, 'full' => wp_get_attachment_url( $source_wp_media['id'] ), 96 => 'https://cache.invalid/ignored.png' )
	);
	import_sources_expect_file( import_sources_resolve_unchanged( 'wp-user-avatars', 'WP User Avatars Media Library record' ), $source_wp_media['path'], 'WP User Avatars resolves media_id on matching site and ignores cached sizes' );
	import_sources_put_meta(
		$source_user_id,
		$source_wp_avatars_key,
		array( 'media_id' => $source_wp_media['id'], 'site_id' => $source_site_id + 1, 'full' => wp_get_attachment_url( $source_wp_media['id'] ) )
	);
	import_sources_expect_error( import_sources_resolve_unchanged( 'wp-user-avatars', 'WP User Avatars other-site record' ), 'zmai_import_other_site', 'WP User Avatars refuses a media record from another site' );
	import_sources_put_meta( $source_user_id, $source_wp_avatars_key, array( 'full' => $source_uploads['url'] . '/' . basename( $source_wp_full_path ) ) );
	import_sources_expect_file( import_sources_resolve_unchanged( 'wp-user-avatars', 'WP User Avatars upload-only record' ), $source_wp_full_path, 'WP User Avatars resolves upload-only full URL without an attachment' );
	import_sources_put_meta( $source_user_id, $source_wp_avatars_key, new stdClass() );
	import_sources_expect_error( import_sources_resolve_unchanged( 'wp-user-avatars', 'malformed WP User Avatars record' ), 'zmai_import_invalid_source', 'malformed WP User Avatars object reports invalid source' );
	import_sources_put_meta( $source_user_id, $source_one_avatar_key, array( 'attachment' => $source_oua_media['id'] ) );
	import_sources_expect_error( import_sources_resolve_unchanged( 'one-user-avatar', 'malformed One User Avatar row' ), 'zmai_import_invalid_source', 'malformed One User Avatar non-ID row reports invalid source' );
	import_sources_put_meta( $source_user_id, $source_one_avatar_key, $source_oua_media['id'] );

	$source_metronet_image_only_key = $source_profile_image_key;
	import_sources_put_meta( $source_user_id, $source_metronet_image_only_key, $source_metronet_mirror['id'] );
	import_sources_expect_file( import_sources_resolve_unchanged( 'metronet-profile-picture', 'Metronet image-ID-only record' ), $source_metronet_mirror['path'], 'Metronet supports a local image-ID-only compatibility record when no profile post exists' );
	$valid_profile_post = wp_insert_post(
		array(
			'post_type'   => 'mt_pp',
			'post_status' => 'publish',
			'post_title'  => 'Metronet valid profile fixture',
			'post_author' => $source_user_id,
		),
		true
	);
	if ( is_wp_error( $valid_profile_post ) || ! $valid_profile_post ) {
		throw new RuntimeException( 'Could not create Metronet profile-post fixture.' );
	}
	$source_post_ids[] = (int) $valid_profile_post;
	update_post_meta( $valid_profile_post, '_thumbnail_id', $source_metronet_thumbnail['id'] );
	import_sources_put_meta( $source_user_id, $source_profile_meta_key, (int) $valid_profile_post );
	import_sources_put_meta( $source_user_id, $source_profile_image_key, $source_metronet_mirror['id'] );
	import_sources_expect_file( import_sources_resolve_unchanged( 'metronet-profile-picture', 'Metronet authoritative thumbnail record' ), $source_metronet_thumbnail['path'], 'valid Metronet profile post thumbnail overrides the mirrored image ID' );
	delete_post_meta( $valid_profile_post, '_thumbnail_id' );
	import_sources_expect_error( import_sources_resolve_unchanged( 'metronet-profile-picture', 'Metronet removed thumbnail' ), 'zmai_import_no_source', 'removed Metronet thumbnail does not resurrect stale image ID' );
	update_post_meta( $valid_profile_post, '_thumbnail_id', $source_metronet_thumbnail['id'] );

	$wrong_type_post = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Wrong-type Metronet fixture',
			'post_author' => $source_user_id,
		),
		true
	);
	if ( is_wp_error( $wrong_type_post ) || ! $wrong_type_post ) {
		throw new RuntimeException( 'Could not create wrong-type profile-post fixture.' );
	}
	$source_post_ids[] = (int) $wrong_type_post;
	update_post_meta( $wrong_type_post, '_thumbnail_id', $source_metronet_thumbnail['id'] );
	import_sources_put_meta( $source_user_id, $source_profile_meta_key, (int) $wrong_type_post );
	import_sources_put_meta( $source_user_id, $source_profile_image_key, $source_metronet_mirror['id'] );
	import_sources_expect_error( import_sources_resolve_unchanged( 'metronet-profile-picture', 'Metronet wrong post type' ), 'zmai_import_invalid_source', 'Metronet refuses a non-mt_pp profile post without falling back to image ID' );

	$wrong_owner_post = wp_insert_post(
		array(
			'post_type'   => 'mt_pp',
			'post_status' => 'publish',
			'post_title'  => 'Wrong-owner Metronet fixture',
			'post_author' => 1 === $source_user_id ? 2 : 1,
		),
		true
	);
	if ( is_wp_error( $wrong_owner_post ) || ! $wrong_owner_post ) {
		throw new RuntimeException( 'Could not create wrong-owner profile-post fixture.' );
	}
	$source_post_ids[] = (int) $wrong_owner_post;
	update_post_meta( $wrong_owner_post, '_thumbnail_id', $source_metronet_thumbnail['id'] );
	import_sources_put_meta( $source_user_id, $source_profile_meta_key, (int) $wrong_owner_post );
	import_sources_expect_error( import_sources_resolve_unchanged( 'metronet-profile-picture', 'Metronet wrong post owner' ), 'zmai_import_invalid_source', 'Metronet refuses a profile post authored by another user without falling back' );

	$missing_file_url = $source_uploads['baseurl'] . '/zmai-import-missing-' . uniqid() . '.png';
	import_sources_put_meta( $source_user_id, $source_wp_avatars_key, array( 'full' => $missing_file_url ) );
	import_sources_expect_error( import_sources_resolve_unchanged( 'wp-user-avatars', 'missing uploads file' ), 'zmai_import_missing_file', 'valid local uploads URL with no file reports missing file' );

	$uploads_host = wp_parse_url( $source_uploads['baseurl'], PHP_URL_HOST );
	$uploads_scheme = wp_parse_url( $source_uploads['baseurl'], PHP_URL_SCHEME );
	$uploads_path = wp_parse_url( $source_uploads['baseurl'], PHP_URL_PATH );
	$uploads_origin = $uploads_scheme . '://' . $uploads_host . ( wp_parse_url( $source_uploads['baseurl'], PHP_URL_PORT ) ? ':' . wp_parse_url( $source_uploads['baseurl'], PHP_URL_PORT ) : '' );
	$safe_relative_url = $source_uploads['url'] . '/' . basename( $source_wp_full_path );
	$unsafe_urls = array(
		array( 'another host', 'https://remote.invalid/avatar.png', 'zmai_import_unsupported_source' ),
		array( 'loopback host', 'http://127.0.0.1/avatar.png', 'zmai_import_unsupported_source' ),
		array( 'private host', 'http://192.168.1.1/avatar.png', 'zmai_import_unsupported_source' ),
		array( 'URL credentials', $uploads_scheme . '://user:pass@' . $uploads_host . $uploads_path . '/' . basename( $source_wp_full_path ), 'zmai_import_unsupported_source' ),
		array( 'unexpected URL port', $uploads_origin . ':4567' . $uploads_path . '/' . basename( $source_wp_full_path ), 'zmai_import_unsupported_source' ),
		array( 'query string', $safe_relative_url . '?download=1', 'zmai_import_unsupported_source' ),
		array( 'fragment', $safe_relative_url . '#avatar', 'zmai_import_unsupported_source' ),
		array( 'encoded slash traversal', $source_uploads['baseurl'] . '/%2e%2e%2foutside.png', 'zmai_import_unsupported_source' ),
		array( 'encoded backslash traversal', $source_uploads['baseurl'] . '/%2e%2e%5coutside.png', 'zmai_import_unsupported_source' ),
		array( 'double-decoded traversal', $source_uploads['baseurl'] . '/%252e%252e/outside.png', 'zmai_import_unsupported_source' ),
		array( 'encoded NUL', $source_uploads['baseurl'] . '/avatar%00.png', 'zmai_import_unsupported_source' ),
		array( 'uploads-prefix sibling', $source_uploads['baseurl'] . '-outside/avatar.png', 'zmai_import_unsupported_source' ),
		array( 'absolute filesystem path', $source_uploads['basedir'] . '/avatar.png', 'zmai_import_unsupported_source' ),
		array( 'file wrapper', 'file:///etc/passwd', 'zmai_import_unsupported_source' ),
		array( 'PHP stream wrapper', 'php://filter/resource=/etc/passwd', 'zmai_import_unsupported_source' ),
		array( 'protocol-relative URL', '//remote.invalid/avatar.png', 'zmai_import_unsupported_source' ),
	);
	foreach ( $unsafe_urls as $case ) {
		import_sources_put_meta( $source_user_id, $source_wp_avatars_key, array( 'full' => $case[1] ) );
		import_sources_expect_error( import_sources_resolve_unchanged( 'wp-user-avatars', 'unsafe URL/path: ' . $case[0] ), $case[2], 'rejects unsafe URL/path: ' . $case[0] );
	}

	$symlink_path = trailingslashit( $source_uploads['path'] ) . 'zmai-import-symlink-' . uniqid() . '.png';
	if ( function_exists( 'symlink' ) && @symlink( __FILE__, $symlink_path ) ) {
		$source_file_paths[] = $symlink_path;
		import_sources_put_meta( $source_user_id, $source_wp_avatars_key, array( 'full' => $source_uploads['url'] . '/' . basename( $symlink_path ) ) );
		import_sources_expect_error( import_sources_resolve_unchanged( 'wp-user-avatars', 'uploads symlink escape' ), 'zmai_import_unsupported_source', 'rejects uploads symlink whose real file escapes uploads' );
	} else {
		echo "MISSING symlink-escape case (symlink unavailable)\n";
	}
	// The historical Metronet key is a profile post, never a bare image ID.
	delete_user_meta( $source_user_id, $source_profile_meta_key );
	import_sources_put_meta( $source_user_id, 'metronet_post_id', (int) $valid_profile_post );
	import_sources_expect_file( import_sources_resolve_unchanged( 'metronet-profile-picture', 'legacy unprefixed Metronet profile' ), $source_metronet_thumbnail['path'], 'legacy unprefixed Metronet profile resolves its validated thumbnail' );
	import_sources_put_meta( $source_user_id, $source_profile_meta_key, 0 );
	import_sources_expect_error( import_sources_resolve_unchanged( 'metronet-profile-picture', 'invalid current profile with legacy fallback' ), 'zmai_import_invalid_source', 'present invalid current profile never falls back to legacy profile' );

	if ( is_multisite() ) {
		$network_keys = array( 'active_sitewide_plugins', 'simple_local_avatars_mode', 'simple_local_avatars' );
		$network_original = array();
		foreach ( $network_keys as $key ) { $network_original[ $key ] = get_site_option( $key, null ); }
		try {
			$active = is_array( $network_original['active_sitewide_plugins'] ) ? $network_original['active_sitewide_plugins'] : array();
			$active['simple-local-avatars/simple-local-avatars.php'] = time();
			update_site_option( 'active_sitewide_plugins', $active );
			update_site_option( 'simple_local_avatars_mode', 'enforce' );
			update_site_option( 'simple_local_avatars', array( 'shared' => 1 ) );
			update_option( 'simple_local_avatars', array( 'shared' => 0 ), false );
			import_sources_put_meta( $source_user_id, $source_sla_shared_key, array( 'media_id' => $source_sla_media['id'], 'full' => wp_get_attachment_url( $source_sla_media['id'] ), 'blog_id' => $source_site_id ) );
			import_sources_expect_file( import_sources_resolve_unchanged( 'simple-local-avatars', 'network enforced SLA setting' ), $source_sla_media['path'], 'network-enforced SLA shared setting overrides conflicting site setting' );
		} finally {
			foreach ( $network_original as $key => $value ) { if ( null === $value ) { delete_site_option( $key ); } else { update_site_option( $key, $value ); } }
			update_option( 'simple_local_avatars', array( 'shared' => 1 ), false );
		}
	}

	$valid_wp_record = array( 'media_id' => $source_wp_media['id'], 'site_id' => $source_site_id, 'full' => wp_get_attachment_url( $source_wp_media['id'] ) );
	import_sources_put_meta( $source_user_id, $source_wp_avatars_key, $valid_wp_record );
	add_user_meta( $source_user_id, $source_wp_avatars_key, $valid_wp_record );
	import_sources_expect_error( import_sources_resolve_unchanged( 'wp-user-avatars', 'ambiguous duplicate source rows' ), 'zmai_import_invalid_source', 'duplicate source rows are rejected even when their values agree' );
	delete_user_meta( $source_user_id, $source_wp_avatars_key );
	import_sources_put_meta( $source_user_id, $source_wp_avatars_key, array( 'media_id' => null, 'site_id' => $source_site_id, 'full' => $source_uploads['url'] . '/' . basename( $source_wp_full_path ) ) );
	import_sources_expect_error( import_sources_resolve_unchanged( 'wp-user-avatars', 'present null attachment ID' ), 'zmai_import_invalid_source', 'present null media_id is malformed and cannot fall back to a full URL' );

	import_sources_put_meta( $source_user_id, $source_wp_avatars_key, $valid_wp_record );
	$original_attached = get_post_meta( $source_wp_media['id'], '_wp_attached_file', true );
	$wpdb->update( $wpdb->postmeta, array( 'meta_value' => null ), array( 'post_id' => $source_wp_media['id'], 'meta_key' => '_wp_attached_file' ) );
	wp_cache_delete( $source_wp_media['id'], 'post_meta' );
	$source_warning_count = 0;
	set_error_handler( static function ( $severity ) use ( &$source_warning_count ) { if ( error_reporting() & $severity ) { ++$source_warning_count; } return true; } );
	try { $null_path = $sources_adapter->resolve( 'wp-user-avatars', $source_user_id ); } finally { restore_error_handler(); }
	import_sources_expect_error( $null_path, 'zmai_import_invalid_source', 'SQL NULL attachment path is malformed' );
	import_sources_check( 0 === $source_warning_count, 'malformed NULL source rows emit no PHP warnings or path disclosures' );
	update_post_meta( $source_wp_media['id'], '_wp_attached_file', $original_attached );

	import_sources_check( 0 === $source_http_requests, 'source URL resolution makes no HTTP requests' );
} catch ( Throwable $error ) {
	import_sources_check( false, 'fixture setup or source assertion raised an exception' );
} finally {
	if ( isset( $source_original_sla ) ) { update_option( 'simple_local_avatars', $source_original_sla, false ); } else { delete_option( 'simple_local_avatars' ); }
	if ( $source_had_sla_filter ) {
		remove_filter( 'pre_option_simple_local_avatars', $source_sla_filter, PHP_INT_MAX );
	}
	if ( $source_had_http_filter ) {
		remove_filter( 'pre_http_request', $source_http_filter, PHP_INT_MAX );
	}
	if ( null !== $source_original_base_prefix ) {
		$wpdb->base_prefix = $source_original_base_prefix;
	}
	foreach ( $source_post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $source_attachment_ids as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}
	foreach ( $source_file_paths as $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
	if ( $source_user_id && get_userdata( $source_user_id ) ) {
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $source_user_id );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $source_user_id );
		}
	}
	$cleanup_ok = null !== $source_original_base_prefix && $wpdb->base_prefix === $source_original_base_prefix;
	$cleanup_ok = $cleanup_ok && ( ! $source_had_sla_filter || false === has_filter( 'pre_option_simple_local_avatars', $source_sla_filter ) );
	$cleanup_ok = $cleanup_ok && ( ! $source_had_http_filter || false === has_filter( 'pre_http_request', $source_http_filter ) );
	$cleanup_ok = $cleanup_ok && ( ! $source_user_id || ! get_userdata( $source_user_id ) );
	foreach ( array_unique( array_merge( $source_post_ids, $source_attachment_ids ) ) as $post_id ) {
		$cleanup_ok = $cleanup_ok && ! get_post( $post_id );
	}
	foreach ( $source_file_paths as $path ) {
		$cleanup_ok = $cleanup_ok && ! is_file( $path ) && ! is_link( $path );
	}
	import_sources_check( $cleanup_ok, 'finally removes fixture records/files and restores filters and table prefix' );
}

echo wp_json_encode( array( 'checks' => $source_checks, 'failures' => $source_failures ) ) . "\n";
WP_CLI::halt( $source_failures ? 1 : 0 );
