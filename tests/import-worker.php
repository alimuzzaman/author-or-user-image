<?php
/** Independent CLI workers for the local importer coordinator contention test. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) { throw new RuntimeException( 'Disposable local CLI required.' ); }
require_once ZMAI_PATH . 'includes/class-import-sources.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; }
$mode = $args[0] ?? '';
$directory = $args[1] ?? '';
$root = realpath( getenv( 'ZMAI_TEST_SCRATCH_ROOT' ) ?: '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/3' );
$real = realpath( $directory );
if ( ! $root || ! $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) { throw new RuntimeException( 'External marked scratch required.' ); }
$state_file = $real . '/state.json';
$store = new Image_Store();
$migration = new Migration( $store );
wp_set_current_user( 1 );
if ( 'setup' === $mode ) {
	$old = get_option( 'zmai_import_job', null );
	$id = wp_insert_user( array( 'user_login' => 'import-worker-' . wp_generate_uuid4(), 'user_email' => wp_generate_uuid4() . '@example.invalid', 'user_pass' => 'local-public-fixture', 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture user failed.' ); }
	$uploads = wp_upload_dir(); wp_mkdir_p( $uploads['path'] );
	$path = $uploads['path'] . '/avatar_user_' . $id . '_' . time() . '.png';
	$image = imagecreatetruecolor( 60, 30 ); imagepng( $image, $path );
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
	update_user_meta( $id, 'wp_user_avatars', array( 'full' => $uploads['url'] . '/' . basename( $path ) ) );
	delete_option( 'zmai_import_job' );
	$job = $migration->start_import( 'wp-user-avatars', wp_generate_uuid4(), 0 );
	if ( is_wp_error( $job ) ) { throw new RuntimeException( 'Fixture job failed.' ); }
	$job['last_user_id'] = (int) $id - 1; update_option( 'zmai_import_job', $job, false );
	file_put_contents( $state_file, wp_json_encode( array( 'user' => (int) $id, 'job' => $job, 'old' => $old, 'path' => $path, 'hash' => hash_file( 'sha256', $path ) ) ) );
	echo "READY\n"; return;
}
$state = json_decode( file_get_contents( $state_file ), true );
if ( 'cleanup' === $mode ) {
	$store->delete_user_avatar( $state['user'] );
	if ( is_multisite() ) { wpmu_delete_user( $state['user'] ); } else { wp_delete_user( $state['user'] ); }
	wp_delete_file( $state['path'] );
	if ( null === $state['old'] ) { delete_option( 'zmai_import_job' ); } else { update_option( 'zmai_import_job', $state['old'], false ); }
	echo "CLEANED\n"; return;
}
if ( 'hold' === $mode ) {
	add_filter( 'wp_generate_attachment_metadata', static function ( $metadata ) use ( $real ) {
		file_put_contents( $real . '/ready', 'metadata' );
		$deadline = microtime( true ) + 30;
		while ( ! is_file( $real . '/release' ) && microtime( true ) < $deadline ) { usleep( 50000 ); clearstatcache(); }
		return $metadata;
	} );
}
if ( 'legacy' === $mode ) {
	$result = $migration->with_migration_lock( static function () { return true; } );
} else {
	$result = $migration->run_import_batch( $state['job']['id'], $state['job']['revision'] );
}
if ( 'verify' === $mode ) {
	global $wpdb;
	$owned = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, Image_Store::ATTACHMENT_OWNER_META, (string) $state['user'] ) );
	$result = array( 'job' => $result, 'owned' => count( $owned ), 'mapped' => $store->get_user_attachment_id( $state['user'] ), 'source_unchanged' => is_file( $state['path'] ) && hash_file( 'sha256', $state['path'] ) === $state['hash'] && array( 'full' => wp_upload_dir()['url'] . '/' . basename( $state['path'] ) ) === get_user_meta( $state['user'], 'wp_user_avatars', true ) );
}
echo wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : $result ) . "\n";
