<?php
/** Disposable competitor-import fixtures shared by the new CLI suites. */
use AuthorOrUserImage\Image_Store;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WordPress required.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; }
foreach ( array( 'class-import-sources.php', 'class-admin-import.php' ) as $file ) {
	if ( is_file( ZMAI_PATH . 'includes/' . $file ) ) { require_once ZMAI_PATH . 'includes/' . $file; }
}
$GLOBALS['zmai_it_checks'] = 0;
$GLOBALS['zmai_it_failures'] = 0;
$GLOBALS['zmai_it_users'] = array();
$GLOBALS['zmai_it_files'] = array();
$GLOBALS['zmai_it_saved_job'] = get_option( 'zmai_import_job', null );
function zmai_it_check( $ok, $label ) {
	++$GLOBALS['zmai_it_checks'];
	if ( ! $ok ) { ++$GLOBALS['zmai_it_failures']; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function zmai_it_user() {
	$id = wp_insert_user( array( 'user_login' => 'import-' . wp_generate_uuid4(), 'user_email' => wp_generate_uuid4() . '@example.invalid', 'user_pass' => 'public-disposable-fixture', 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture user failed.' ); }
	$GLOBALS['zmai_it_users'][] = (int) $id;
	return (int) $id;
}
function zmai_it_file() {
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$path = $uploads['path'] . '/import-' . wp_generate_uuid4() . '.png';
	$image = imagecreatetruecolor( 60, 30 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 210, 30, 40 ) );
	imagefilledrectangle( $image, 15, 0, 44, 29, imagecolorallocate( $image, 0, 200, 50 ) );
	imagepng( $image, $path );
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
	$GLOBALS['zmai_it_files'][] = $path;
	return array( 'path' => $path, 'url' => $uploads['url'] . '/' . basename( $path ) );
}
function zmai_it_cleanup() {
	$store = new Image_Store();
	foreach ( $GLOBALS['zmai_it_users'] as $id ) {
		$store->delete_user_avatar( $id );
		if ( is_multisite() ) { wpmu_delete_user( $id ); } else { wp_delete_user( $id ); }
	}
	foreach ( $GLOBALS['zmai_it_files'] as $path ) { wp_delete_file( $path ); }
	if ( null === $GLOBALS['zmai_it_saved_job'] ) { delete_option( 'zmai_import_job' ); } else { update_option( 'zmai_import_job', $GLOBALS['zmai_it_saved_job'], false ); }
}
function zmai_it_done() {
	echo 'RESULT ' . $GLOBALS['zmai_it_checks'] . ' checks, ' . $GLOBALS['zmai_it_failures'] . " failed\n";
	if ( $GLOBALS['zmai_it_failures'] ) { WP_CLI::halt( 1 ); }
}
