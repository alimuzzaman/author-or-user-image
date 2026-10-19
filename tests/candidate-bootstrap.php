<?php
/** Load this isolated candidate in WP-CLI without changing the installed plugin. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    throw new RuntimeException( 'WP-CLI only.' );
}
define( 'ZMAI_TEST_CANDIDATE', dirname( __DIR__ ) );
$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
    'function' => static function ( $plugins ) {
        return array_values( array_filter( $plugins, static function ( $file ) { return 'author_image.php' !== basename( $file ); } ) );
    },
    'accepted_args' => 1,
);
$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10][] = array(
    'function' => static function ( $plugins ) {
        return array_filter( $plugins, static function ( $file ) { return 'author_image.php' !== basename( $file ); }, ARRAY_FILTER_USE_KEY );
    },
    'accepted_args' => 1,
);
$GLOBALS['wp_filter']['plugins_loaded'][10][] = array(
    'function' => static function () {
        if ( 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
            throw new RuntimeException( 'Disposable local WordPress is required.' );
        }
        if ( class_exists( 'AuthorOrUserImage\\Image_Store' ) ) {
            throw new RuntimeException( 'Installed target plugin was not skipped.' );
        }
        require ZMAI_TEST_CANDIDATE . '/author_image.php';
    },
    'accepted_args' => 0,
);
