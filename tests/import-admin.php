<?php
/** Import controller authorization, native forms and bounded status responses. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
use AuthorOrUserImage\Import_Admin;
require __DIR__ . '/import-fixtures.php';
zmai_it_check( class_exists( Import_Admin::class ), 'dedicated import admin boundary exists' );
if ( ! class_exists( Import_Admin::class ) ) { zmai_it_done(); return; }
$controller = new Import_Admin( new Migration( new Image_Store() ) );
try {
	delete_option( 'zmai_import_job' );
	wp_set_current_user( 1 );
	$token = wp_generate_uuid4();
	$request = array( 'site_id' => (string) get_current_blog_id(), 'source' => 'wp-user-avatars', 'run_token' => $token, 'revision' => '0', '_wpnonce' => wp_create_nonce( 'zmai_import_start' ) );
	$bad = $controller->process_request( 'start', $request, 'GET' );
	zmai_it_check( is_wp_error( $bad ) && null === ( new Migration( new Image_Store() ) )->import_status(), 'GET never starts a competitor import' );
	$wrong = $request; $wrong['_wpnonce'] = 'invalid';
	zmai_it_check( is_wp_error( $controller->process_request( 'start', $wrong, 'POST' ) ), 'start requires valid purpose nonce' );
	$subscriber = zmai_it_user(); wp_set_current_user( $subscriber );
	$request['_wpnonce'] = wp_create_nonce( 'zmai_import_start' );
	zmai_it_check( is_wp_error( $controller->process_request( 'start', $request, 'POST' ) ), 'subscriber cannot run a job with a valid nonce' );
	zmai_it_check( is_wp_error( $controller->process_request( 'status', array( 'site_id' => (string) get_current_blog_id() ), 'GET' ) ), 'status also requires manage_options' );
	wp_set_current_user( 1 ); $request['_wpnonce'] = wp_create_nonce( 'zmai_import_start' );
	$wrong = $request; $wrong['site_id'] = (string) ( get_current_blog_id() + 1 );
	zmai_it_check( is_wp_error( $controller->process_request( 'start', $wrong, 'POST' ) ), 'cross-site context cannot mutate this site job' );
	$wrong = $request; $wrong['revision'] = '-1';
	zmai_it_check( is_wp_error( $controller->process_request( 'start', $wrong, 'POST' ) ), 'invalid revision types are rejected' );
	$menu_visible = static function () use ( $controller ) {
		global $submenu;
		remove_submenu_page( 'users.php', 'author-or-user-image-import' );
		$controller->add_menu();
		foreach ( $submenu['users.php'] ?? array() as $item ) {
			if ( 'author-or-user-image-import' === $item[2] ) { return true; }
		}
		return false;
	};
	zmai_it_check( ! $menu_visible(), 'migration submenu is hidden with no source and no unfinished job' );
	zmai_it_check( isset( $GLOBALS['_registered_pages'][get_plugin_page_hookname( 'author-or-user-image-import', 'users.php' )] ), 'hidden migration page remains registered for direct access' );
	do_action( 'load-' . get_plugin_page_hookname( 'author-or-user-image-import', 'users.php' ) );
	zmai_it_check( 'Avatar Migration' === ( $GLOBALS['title'] ?? null ), 'hidden migration page retains its document title' );
	$admin = new \AuthorOrUserImage\Admin( new Image_Store() );
	$old_get = $_GET;
	foreach ( array( 'images', 'blocked', 'settings', 'migration' ) as $tab ) {
		$_GET['tab'] = $tab;
		ob_start(); $admin->render_management_page(); $management = ob_get_clean();
		zmai_it_check( 1 === preg_match( '/<a class="nav-tab[^"]*" href="[^"]*tab=migration[^"]*">Avatar Migration<\/a>/', $management ) && false === strpos( $management, 'page-title-action' ), 'management ' . $tab . ' always includes the migration tab' );
		if ( 'migration' === $tab ) {
			zmai_it_check( 1 === preg_match( '/<a class="nav-tab nav-tab-active" href="[^"]*tab=migration[^"]*">/', $management ) && false !== strpos( $management, 'data-zmai-import' ) && false !== strpos( $management, 'No supported avatar plugin' ), 'selected migration tab renders the migration empty state in management' );
		}
	}
	$_GET = $old_get;
	$fixture_user = zmai_it_user();
	ob_start(); $controller->render_page(); $html = ob_get_clean();
	zmai_it_check( false === strpos( $html, 'data-zmai-operation="start"' ), 'no detected source offers no start action' );
	update_user_meta( $fixture_user, 'wp_user_avatars', array( 'full' => zmai_it_file()['url'], 'site_id' => get_current_blog_id() ) );
	$capabilities_key = $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities';
	$capabilities = get_user_meta( $fixture_user, $capabilities_key, true );
	delete_user_meta( $fixture_user, $capabilities_key );
	zmai_it_check( is_multisite() ? ! $menu_visible() : $menu_visible(), 'saved source detection follows single-site users and multisite membership' );
	update_user_meta( $fixture_user, $capabilities_key, $capabilities );
	ob_start(); $controller->render_page(); $html = ob_get_clean();
	zmai_it_check( false !== strpos( $html, 'zmai_import_start' ) && false !== strpos( $html, 'run_token' ) && false !== strpos( $html, 'name="site_id"' ), 'native start form exposes nonce, run token and site context' );
	zmai_it_check( false === strpos( $html, '<select' ) && 1 === substr_count( $html, 'data-zmai-operation="start"' ) && false !== strpos( $html, 'name="source" value="wp-user-avatars"' ), 'saved source offers one direct action without a dropdown' );
	zmai_it_check( $menu_visible(), 'migration submenu is visible when a source is detected' );
	update_user_meta( $fixture_user, $GLOBALS['wpdb']->get_blog_prefix() . 'user_avatar', 123 );
	ob_start(); $controller->render_page(); $html = ob_get_clean();
	zmai_it_check( 2 === substr_count( $html, 'data-zmai-operation="start"' ) && false === strpos( $html, '<select' ), 'multiple sources offer separate direct actions' );
	delete_user_meta( $fixture_user, $GLOBALS['wpdb']->get_blog_prefix() . 'user_avatar' );
	$job = $controller->process_request( 'start', $request, 'POST' );
	zmai_it_check( is_array( $job ) && 'running' === $job['state'], 'authorized native Start creates a job' );
	$saved_source = get_user_meta( $fixture_user, 'wp_user_avatars', true );
	delete_user_meta( $fixture_user, 'wp_user_avatars' );
	zmai_it_check( $menu_visible(), 'unfinished migration remains reachable after its source disappears' );
	update_user_meta( $fixture_user, 'wp_user_avatars', $saved_source );
	$status = $controller->process_request( 'status', array( 'site_id' => (string) get_current_blog_id() ), 'GET' );
	zmai_it_check( $status === $job && strlen( wp_json_encode( $status ) ) < 2048, 'GET status is bounded aggregate state with no advancement' );
	$batch = array( 'site_id' => (string) get_current_blog_id(), 'job_id' => $job['id'], 'revision' => (string) $job['revision'], '_wpnonce' => wp_create_nonce( 'zmai_import_pause_' . $job['id'] ) );
	zmai_it_check( is_wp_error( $controller->process_request( 'batch', $batch, 'POST' ) ), 'purpose-specific pause nonce cannot run a batch' );
	$batch['_wpnonce'] = wp_create_nonce( 'zmai_import_batch_' . $job['id'] );
	$next = $controller->process_request( 'batch', $batch, 'POST' );
	zmai_it_check( is_array( $next ) && $next['revision'] > $job['revision'] && $next === $controller->process_request( 'batch', $batch, 'POST' ), 'authorized batch and HTTP-style replay advance once' );
	ob_start(); $controller->render_page(); $html = ob_get_clean();
	zmai_it_check( false !== strpos( $html, 'zmai_import_' . ( 'complete' === $next['state'] ? 'start' : 'batch' ) ), 'persisted job renders the native action appropriate to its state' );
	zmai_it_check( false !== strpos( $html, 'data-zmai-import' ) && false !== strpos( $html, 'aria-live="polite"' ), 'progressive driver has accessible persisted progress' );
	zmai_it_check( false === strpos( $html, ABSPATH ) && false === strpos( $html, 'sourceURL' ), 'admin output discloses no source paths or URLs' );
} finally { zmai_it_cleanup(); }
zmai_it_done();
