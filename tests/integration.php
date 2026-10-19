<?php
/** Integrated loader and runtime hook contracts on a disposable WP-CLI fixture. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
    throw new RuntimeException( 'Disposable local WP-CLI fixture required.' );
}
$zmai_integration_failed = 0;
$zmai_integration_expect = static function ( $ok, $label ) use ( &$zmai_integration_failed ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    $zmai_integration_failed += $ok ? 0 : 1;
};
$zmai_integration_expect( class_exists( 'AuthorOrUserImage\\Import_Sources' ), 'entry loads source adapters' );
$zmai_integration_expect( class_exists( 'AuthorOrUserImage\\Import_Admin' ), 'entry loads import controller' );
$zmai_integration_expect( shortcode_exists( 'author_or_user_image_upload' ), 'integrated front-end shortcode' );
$zmai_integration_expect( WP_Block_Type_Registry::get_instance()->is_registered( 'author-or-user-image/avatar-upload' ), 'integrated upload block' );
foreach ( array( 'start', 'batch', 'pause', 'resume', 'retry' ) as $zmai_operation ) {
    $zmai_hook = 'admin_post_zmai_import_' . $zmai_operation;
    $zmai_integration_expect( is_admin() === ( false !== has_action( $zmai_hook ) ), 'import hook context ' . $zmai_operation );
    if ( is_admin() ) {
        $zmai_integration_expect( 1 === count( $GLOBALS['wp_filter'][ $zmai_hook ]->callbacks[10] ?? array() ), 'single import handler ' . $zmai_operation );
    }
}
if ( is_admin() ) {
    foreach ( array( 'save', 'remove' ) as $zmai_operation ) {
        $zmai_integration_expect( false !== has_action( 'admin_post_zmai_' . $zmai_operation . '_avatar' ), 'front-end admin-post handler ' . $zmai_operation );
    }
}
if ( $zmai_integration_failed ) {
    throw new RuntimeException( 'Integrated runtime hook contracts failed.' );
}
