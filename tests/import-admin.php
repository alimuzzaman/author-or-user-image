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
	ob_start(); $controller->render_page(); $html = ob_get_clean();
	zmai_it_check( false !== strpos( $html, 'zmai_import_start' ) && false !== strpos( $html, 'run_token' ) && false !== strpos( $html, 'name="site_id"' ), 'native start form exposes nonce, run token and site context' );
	$job = $controller->process_request( 'start', $request, 'POST' );
	zmai_it_check( is_array( $job ) && 'running' === $job['state'], 'authorized native Start creates a job' );
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
