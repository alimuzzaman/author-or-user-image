<?php
/** One foreign-plugin exit/fatal scenario, invoked by review-shutdown.py. */
if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) || ! is_file( $argv[1] . '/.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Explicit disposable local WordPress root required.' );
}
$site = $argv[1];
$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
	'function' => static function ( $plugins ) { return array_values( array_filter( $plugins, static function ( $file ) { return 'author_image.php' !== basename( $file ); } ) ); },
	'accepted_args' => 1,
);
$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10][] = array(
	'function' => static function ( $plugins ) { return array_filter( $plugins, static function ( $file ) { return 'author_image.php' !== basename( $file ); }, ARRAY_FILTER_USE_KEY ); },
	'accepted_args' => 1,
);
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WordPress required.' ); }
require dirname( __DIR__ ) . '/author_image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
$target = (int) $argv[2];
$mode = $argv[3];
add_action( 'delete_user', static function ( $id ) use ( $target, $mode ) {
	if ( (int) $id !== $target ) { return; }
	if ( 'foreign' === $mode ) {
		update_option( 'zmai_avatar_lock_user_' . $id, 'replacement-foreign-token', false );
	}
	if ( 'wpdie' === $mode ) { wp_die( 'Synthetic later-plugin veto.', '', array( 'response' => 409 ) ); }
	if ( 'fatal' === $mode ) {
		zmai_nonexistent_foreign_plugin_callback();
	}
	exit( 0 );
}, 20 );
wp_delete_user( $target );
throw new RuntimeException( 'Foreign veto did not execute.' );
