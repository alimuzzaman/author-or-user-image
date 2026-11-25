<?php
/**
 * Phase 1 hardening checks: lock diagnostics, HTML token safety, and poisoned object-cache resilience.
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Admin;
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Lock;
use AuthorOrUserImage\Migration;
use AuthorOrUserImage\Upload_Policy;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WP-CLI fixture required.' );
}

require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

$zmai_p1_failed = 0;
$zmai_p1_checks = 0;
$zmai_p1_expect = static function ( $ok, $label ) use ( &$zmai_p1_failed, &$zmai_p1_checks ) {
	++$zmai_p1_checks;
	if ( ! $ok ) {
		++$zmai_p1_failed;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
};

global $wpdb;
$store     = new Image_Store();
$lock_svc  = $store->get_lock();
$policy    = new Upload_Policy( $store );
$migration = new Migration( $store );
$admin     = new Admin( $store );

$held_locks          = array();
$test_user_id        = 0;
$previous_policy     = get_option( Upload_Policy::OPTION, null );
$previous_import_job = get_option( Migration::IMPORT_JOB_OPTION, null );
$previous_rel_failed = get_option( Migration::RELEASE_FAILED_OPTION, null );
$previous_get        = $_GET;
$previous_user_id    = get_current_user_id();

try {
	$test_user_id = wp_insert_user(
		array(
			'user_login' => 'zmai-p1-' . wp_generate_uuid4(),
			'user_email' => wp_generate_uuid4() . '@example.invalid',
			'user_pass'  => 'public-local-test-fixture',
			'role'       => 'subscriber',
		)
	);
	$zmai_p1_expect( is_int( $test_user_id ) && $test_user_id > 0, 'create disposable test subscriber' );

	// 1. Lock::inspect_locks() baseline and classification.
	$baseline = $lock_svc->inspect_locks();
	$zmai_p1_expect( is_array( $baseline ) && array() === $baseline, 'inspect_locks returns empty array when no locks are held' );

	$targets = array(
		'zmai_avatar_lock_migration',
		'zmai_avatar_lock_blocks',
		'zmai_avatar_lock_default',
		'zmai_avatar_lock_user_' . $test_user_id,
	);
	foreach ( $targets as $name ) {
		$acquired = $lock_svc->acquire_lock( $name );
		if ( ! is_wp_error( $acquired ) ) {
			$held_locks[ $name ] = $acquired;
		}
		$zmai_p1_expect( ! is_wp_error( $acquired ), 'acquire synthetic lock ' . $name );
	}

	// Poison object cache to confirm inspect_locks() ignores phantom or deleted cache entries.
	wp_cache_delete( 'zmai_avatar_lock_user_' . $test_user_id, 'options' );
	wp_cache_set( 'zmai_avatar_lock_phantom', 'zmai:phantom-token', 'options' );
	$inspected = $lock_svc->inspect_locks();
	$by_name   = array();
	if ( is_array( $inspected ) ) {
		foreach ( $inspected as $entry ) {
			$by_name[ $entry['name'] ] = $entry;
		}
	}
	$zmai_p1_expect( is_array( $inspected ) && 4 === count( $inspected ) && ! isset( $by_name['zmai_avatar_lock_phantom'] ), 'inspect_locks reads authoritative DB rows and ignores cache phantoms' );
	$zmai_p1_expect( isset( $by_name['zmai_avatar_lock_migration'] ) && 'migration' === $by_name['zmai_avatar_lock_migration']['scope'] && 0 === $by_name['zmai_avatar_lock_migration']['owner_id'], 'migration lock classified accurately' );
	$zmai_p1_expect( isset( $by_name['zmai_avatar_lock_blocks'] ) && 'blocks' === $by_name['zmai_avatar_lock_blocks']['scope'] && 0 === $by_name['zmai_avatar_lock_blocks']['owner_id'], 'blocks lock classified accurately' );
	$zmai_p1_expect( isset( $by_name['zmai_avatar_lock_default'] ) && 'default' === $by_name['zmai_avatar_lock_default']['scope'] && 0 === $by_name['zmai_avatar_lock_default']['owner_id'], 'default lock classified accurately' );
	$user_lock_name = 'zmai_avatar_lock_user_' . $test_user_id;
	$zmai_p1_expect(
		isset( $by_name[ $user_lock_name ] )
		&& 'user' === $by_name[ $user_lock_name ]['scope']
		&& $test_user_id === $by_name[ $user_lock_name ]['owner_id']
		&& $by_name[ $user_lock_name ]['table'] === $wpdb->options
		&& $by_name[ $user_lock_name ]['token'] === $held_locks[ $user_lock_name ]['token'],
		'user lock captures scope, owner_id, options table, and exact token'
	);

	// 2. Settings diagnostics UI renders lock names safely without leaking secret tokens.
	wp_set_current_user( 1 );
	$_GET['tab'] = 'settings';
	update_option( Migration::RELEASE_FAILED_OPTION, 1, false );
	ob_start();
	$admin->render_management_page();
	$html = (string) ob_get_clean();

	$tokens_absent = true;
	foreach ( $held_locks as $held ) {
		if ( false !== strpos( $html, $held['token'] ) ) {
			$tokens_absent = false;
		}
	}
	$zmai_p1_expect( false !== strpos( $html, 'Lifecycle lock status' ) && false !== strpos( $html, $user_lock_name ), 'settings screen renders lock diagnostics section and lock name' );
	$zmai_p1_expect( false !== strpos( $html, 'legacy migration lock release previously failed' ), 'settings screen surfaces migration release failure notice' );
	$zmai_p1_expect( $tokens_absent, 'settings lock diagnostics never expose raw lock tokens in HTML' );
	$zmai_p1_expect( false === strpos( $html, 'force' ) && false === strpos( $html, 'clear_lock' ), 'settings lock diagnostics render no unsafe clear action' );

	// Release held locks and verify clean diagnostics state.
	foreach ( $held_locks as $key => $held ) {
		$rel = $lock_svc->release_lock( $held );
		$zmai_p1_expect( true === $rel, 'release synthetic lock ' . $key );
		unset( $held_locks[ $key ] );
	}
	delete_option( Migration::RELEASE_FAILED_OPTION );
	ob_start();
	$admin->render_management_page();
	$clean_html = (string) ob_get_clean();
	$zmai_p1_expect( false !== strpos( $clean_html, 'No active or retained avatar locks were found on this site.' ), 'settings screen confirms clean state when no locks exist' );

	// 2b. Settings diagnostics reads RELEASE_FAILED_OPTION authoritatively despite poisoned option caches.
	update_option( Migration::RELEASE_FAILED_OPTION, 1, false );
	wp_cache_set( Migration::RELEASE_FAILED_OPTION, 0, 'options' );
	$alloptions_diag = wp_cache_get( 'alloptions', 'options' );
	if ( ! is_array( $alloptions_diag ) ) {
		$alloptions_diag = array();
	}
	$alloptions_diag[ Migration::RELEASE_FAILED_OPTION ] = 0;
	wp_cache_set( 'alloptions', $alloptions_diag, 'options' );
	ob_start();
	$admin->render_management_page();
	$poisoned_true_html = (string) ob_get_clean();
	$zmai_p1_expect( false !== strpos( $poisoned_true_html, 'legacy migration lock release previously failed' ), 'settings diagnostics detects DB release_failed flag despite poisoned options cache' );

	delete_option( Migration::RELEASE_FAILED_OPTION );
	wp_cache_set( Migration::RELEASE_FAILED_OPTION, 1, 'options' );
	$alloptions_diag[ Migration::RELEASE_FAILED_OPTION ] = 1;
	wp_cache_set( 'alloptions', $alloptions_diag, 'options' );
	ob_start();
	$admin->render_management_page();
	$poisoned_false_html = (string) ob_get_clean();
	$zmai_p1_expect(
		false !== strpos( $poisoned_false_html, 'No active or retained avatar locks were found on this site.' )
		&& false === strpos( $poisoned_false_html, 'legacy migration lock release previously failed' ),
		'settings diagnostics ignores poisoned release_failed cache when DB row is absent'
	);
	wp_cache_delete( Migration::RELEASE_FAILED_OPTION, 'options' );
	wp_cache_delete( 'alloptions', 'options' );

	// 3. Poisoned persistent object-cache resilience checks.
	// 3a. Upload_Policy::can_upload_fresh() must read DB policy, not poisoned option/alloptions cache.
	$saved_policy = $policy->save_policy(
		array(
			'mode'  => 'roles',
			'roles' => array(),
		)
	);
	$zmai_p1_expect( true === $saved_policy, 'persist deny-all roles policy for cache test' );
	$poison_legacy = array(
		'schema' => 1,
		'mode'   => 'legacy',
		'roles'  => array(),
	);
	wp_cache_set( Upload_Policy::OPTION, $poison_legacy, 'options' );
	$alloptions = wp_cache_get( 'alloptions', 'options' );
	if ( ! is_array( $alloptions ) ) {
		$alloptions = array();
	}
	$alloptions[ Upload_Policy::OPTION ] = maybe_serialize( $poison_legacy );
	wp_cache_set( 'alloptions', $alloptions, 'options' );
	$zmai_p1_expect( false === $policy->can_upload_fresh( $test_user_id ), 'can_upload_fresh denies when DB policy denies despite poisoned options/alloptions cache' );

	// 3b. Image_Store::is_blocked_fresh() must read DB usermeta and user_login, not poisoned caches.
	$blocked_key = Image_Store::user_meta_key( Image_Store::BLOCKED_META );
	update_user_meta( $test_user_id, $blocked_key, 1 );
	wp_cache_set( $test_user_id, array( $blocked_key => array( '' ) ), 'user_meta' );
	$zmai_p1_expect( true === $store->is_blocked_fresh( $test_user_id ), 'is_blocked_fresh detects DB block despite poisoned user_meta cache' );
	delete_user_meta( $test_user_id, $blocked_key );

	$test_user_obj = get_userdata( $test_user_id );
	$previous_ban  = get_option( Image_Store::LEGACY_BLOCK_OPTION, null );
	update_option( Image_Store::LEGACY_BLOCK_OPTION, array( $test_user_obj->user_login ), false );
	$cached_user               = wp_cache_get( $test_user_id, 'users' );
	$poisoned_user             = is_object( $cached_user ) ? clone $cached_user : new stdClass();
	$poisoned_user->ID         = $test_user_id;
	$poisoned_user->user_login = 'poisoned-wrong-login';
	wp_cache_set( $test_user_id, $poisoned_user, 'users' );
	$zmai_p1_expect( true === $store->is_blocked_fresh( $test_user_id ), 'is_blocked_fresh detects legacy login block despite poisoned users cache' );
	wp_cache_delete( $test_user_id, 'users' );
	if ( null === $previous_ban ) {
		delete_option( Image_Store::LEGACY_BLOCK_OPTION );
	} else {
		update_option( Image_Store::LEGACY_BLOCK_OPTION, $previous_ban, false );
	}

	// 3c. Migration::import_status() and has_release_failure() must read DB options, not poisoned options cache.
	delete_option( Migration::IMPORT_JOB_OPTION );
	$job = $migration->start_import( 'wp-user-avatars', wp_generate_uuid4(), 0 );
	$zmai_p1_expect( is_array( $job ) && 'running' === $job['state'], 'start import job for cache-resilience test' );
	wp_cache_set( Migration::IMPORT_JOB_OPTION, 'corrupt-cached-job', 'options' );
	$status = $migration->import_status();
	$zmai_p1_expect( is_array( $status ) && $status['id'] === $job['id'] && 'running' === $status['state'], 'import_status reads authoritative DB job despite poisoned options cache' );

	update_option( Migration::RELEASE_FAILED_OPTION, 1, false );
	wp_cache_set( Migration::RELEASE_FAILED_OPTION, 0, 'options' );
	$alloptions_mig = wp_cache_get( 'alloptions', 'options' );
	if ( ! is_array( $alloptions_mig ) ) {
		$alloptions_mig = array();
	}
	$alloptions_mig[ Migration::RELEASE_FAILED_OPTION ] = 0;
	wp_cache_set( 'alloptions', $alloptions_mig, 'options' );
	$zmai_p1_expect( true === $migration->has_release_failure(), 'Migration::has_release_failure reads DB state despite poisoned options/alloptions cache' );
	delete_option( Migration::RELEASE_FAILED_OPTION );
} finally {
	$_GET = $previous_get;
	wp_set_current_user( $previous_user_id );
	foreach ( $held_locks as $held ) {
		$lock_svc->release_lock( $held );
	}
	wp_cache_delete( 'zmai_avatar_lock_phantom', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( Upload_Policy::OPTION, 'options' );
	wp_cache_delete( Migration::IMPORT_JOB_OPTION, 'options' );
	wp_cache_delete( Migration::RELEASE_FAILED_OPTION, 'options' );

	if ( null === $previous_policy ) {
		delete_option( Upload_Policy::OPTION );
	} else {
		update_option( Upload_Policy::OPTION, $previous_policy, false );
	}
	if ( null === $previous_import_job ) {
		delete_option( Migration::IMPORT_JOB_OPTION );
	} else {
		update_option( Migration::IMPORT_JOB_OPTION, $previous_import_job, false );
	}
	if ( null === $previous_rel_failed ) {
		delete_option( Migration::RELEASE_FAILED_OPTION );
	} else {
		update_option( Migration::RELEASE_FAILED_OPTION, $previous_rel_failed, false );
	}
	if ( $test_user_id > 0 ) {
		if ( is_multisite() ) {
			wpmu_delete_user( $test_user_id );
		} else {
			wp_delete_user( $test_user_id );
		}
	}
}

echo "RESULT {$zmai_p1_checks} checks, {$zmai_p1_failed} failed\n";
if ( $zmai_p1_failed > 0 ) {
	throw new RuntimeException( 'Phase 1 hardening checks failed.' );
}
