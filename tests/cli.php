<?php
/**
 * WP-CLI batch competitor importer and lock recovery checks.
 *
 * @package AuthorOrUserImage
 */

use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WP-CLI fixture required.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

$zmai_cli_failed = 0;
$zmai_cli_checks = 0;
$zmai_cli_expect = static function ( $ok, $label ) use ( &$zmai_cli_failed, &$zmai_cli_checks ) {
	++$zmai_cli_checks;
	if ( ! $ok ) {
		++$zmai_cli_failed;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
};

$repo_root = defined( 'ZMAI_TEST_CANDIDATE' ) ? ZMAI_TEST_CANDIDATE : dirname( __DIR__ );
$bootstrap = $repo_root . '/tests/candidate-bootstrap.php';
$site_url  = '';
$switched  = false;

if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids', 'number' => 2 ) );
	if ( count( $sites ) >= 2 ) {
		$blog2    = (int) $sites[1];
		$site_url = get_site_url( $blog2 );
		switch_to_blog( $blog2 );
		$switched = true;
	}
}

$run_cli = static function ( array $subargs ) use ( $bootstrap, $site_url ) {
	$cmd = array(
		PHP_BINARY,
		'/usr/local/bin/wp',
		'--path=' . ABSPATH,
		'--require=' . $bootstrap,
	);
	if ( '' !== $site_url ) {
		$cmd[] = '--url=' . $site_url;
	}
	$cmd = array_merge( $cmd, $subargs );

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$proc = proc_open( $cmd, $descriptors, $pipes, ABSPATH );
	if ( ! is_resource( $proc ) ) {
		return array( 'code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed' );
	}
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	return array(
		'code'   => $code,
		'stdout' => $stdout,
		'stderr' => $stderr,
	);
};

$store            = new Image_Store();
$migration        = new Migration( $store );
$created_user     = 0;
$source_file      = '';
$source_hash      = '';
$held_locks       = array();
$previous_job     = get_option( Migration::IMPORT_JOB_OPTION, null );
$previous_failed  = get_option( Migration::RELEASE_FAILED_OPTION, null );

try {
	// 1. Sources --all lists all 4 adapters.
	$all_sources = $run_cli( array( 'zmai', 'import', 'sources', '--all', '--format=json' ) );
	$all_decoded = json_decode( $all_sources['stdout'], true );
	$zmai_cli_expect( 0 === $all_sources['code'] && is_array( $all_decoded ) && 4 === count( $all_decoded ), 'wp zmai import sources --all lists all 4 sources' );

	// 2. Create fixture subscriber with owned wp_user_avatars file and verify detection + run.
	$created_user = wp_insert_user(
		array(
			'user_login' => 'zmai-cli-' . wp_generate_uuid4(),
			'user_email' => wp_generate_uuid4() . '@example.invalid',
			'user_pass'  => 'public-local-test-fixture',
			'role'       => 'subscriber',
		)
	);
	$zmai_cli_expect( is_int( $created_user ) && $created_user > 0, 'create disposable user for CLI importer' );
	if ( is_multisite() && '' !== $site_url ) {
		add_user_to_blog( get_current_blog_id(), $created_user, 'subscriber' );
	}

	$upload_info = $run_cli( array( 'eval', 'echo wp_json_encode( wp_upload_dir() );' ) );
	$uploads     = json_decode( $upload_info['stdout'], true );
	if ( ! is_array( $uploads ) || empty( $uploads['path'] ) || empty( $uploads['url'] ) ) {
		$uploads = wp_upload_dir();
	}
	wp_mkdir_p( $uploads['path'] );
	$source_file = $uploads['path'] . '/avatar_user_' . $created_user . '_' . time() . '.png';
	$img         = imagecreatetruecolor( 80, 40 );
	imagepng( $img, $source_file );
	if ( PHP_VERSION_ID < 80000 ) {
		imagedestroy( $img );
	}
	$source_hash = (string) hash_file( 'sha256', $source_file );
	update_user_meta( $created_user, 'wp_user_avatars', array( 'full' => $uploads['url'] . '/' . basename( $source_file ) ) );
	delete_option( Migration::IMPORT_JOB_OPTION );

	$detected_res = $run_cli( array( 'zmai', 'import', 'sources', '--format=json' ) );
	$detected     = json_decode( $detected_res['stdout'], true );
	$zmai_cli_expect( 0 === $detected_res['code'] && is_array( $detected ) && in_array( 'wp-user-avatars', array_column( $detected, 'source' ), true ), 'wp zmai import sources detects wp-user-avatars fixture' );

	$run_res = $run_cli( array( 'zmai', 'import', 'run', 'wp-user-avatars', '--format=json' ) );
	$run_job = json_decode( $run_res['stdout'], true );
	wp_cache_delete( $created_user, 'user_meta' );
	$zmai_cli_expect(
		0 === $run_res['code']
		&& is_array( $run_job )
		&& 'complete' === ( $run_job['state'] ?? '' )
		&& ( $run_job['counts']['imported'] ?? 0 ) >= 1
		&& $store->get_user_attachment_id( $created_user ) > 0
		&& is_file( $source_file )
		&& hash_file( 'sha256', $source_file ) === $source_hash,
		'wp zmai import run imports avatar cleanly while preserving source file'
	);

	// 3. Status + pause / resume / finish transitions via CLI.
	$status_res = $run_cli( array( 'zmai', 'import', 'status', '--format=json' ) );
	$status_job = json_decode( $status_res['stdout'], true );
	$zmai_cli_expect( 0 === $status_res['code'] && is_array( $status_job ) && 'complete' === ( $status_job['state'] ?? '' ), 'wp zmai import status reports completed job' );

	$started = $migration->start_import( 'wp-user-avatars', wp_generate_uuid4(), (int) $status_job['revision'] );
	$zmai_cli_expect( is_array( $started ) && 'running' === $started['state'], 'start new job for CLI pause/resume/finish transitions' );

	$pause1 = $run_cli( array( 'zmai', 'import', 'pause', '--format=json' ) );
	$p1_job = json_decode( $pause1['stdout'], true );
	$zmai_cli_expect( 0 === $pause1['code'] && 'paused' === ( $p1_job['state'] ?? '' ), 'wp zmai import pause pauses running job' );

	$resume1 = $run_cli( array( 'zmai', 'import', 'resume', '--format=json' ) );
	$r1_job  = json_decode( $resume1['stdout'], true );
	$zmai_cli_expect( 0 === $resume1['code'] && 'running' === ( $r1_job['state'] ?? '' ), 'wp zmai import resume restores running state' );

	$pause2 = $run_cli( array( 'zmai', 'import', 'pause', '--format=json' ) );
	$fin1   = $run_cli( array( 'zmai', 'import', 'finish', '--format=json' ) );
	$f1_job = json_decode( $fin1['stdout'], true );
	$zmai_cli_expect( 0 === $pause2['code'] && 0 === $fin1['code'] && 'complete' === ( $f1_job['state'] ?? '' ), 'wp zmai import finish completes paused job' );

	// 4. Locks list and exact-token unlock checks.
	$empty_locks = $run_cli( array( 'zmai', 'locks', 'list', '--format=json' ) );
	$zmai_cli_expect( 0 === $empty_locks['code'] && array() === json_decode( $empty_locks['stdout'], true ), 'wp zmai locks list returns empty array when clean' );

	$user_lock_name = 'zmai_avatar_lock_user_' . $created_user;
	$user_lock      = $store->get_lock()->acquire_lock( $user_lock_name );
	if ( ! is_wp_error( $user_lock ) ) {
		$held_locks[ $user_lock_name ] = $user_lock;
	}
	$zmai_cli_expect( ! is_wp_error( $user_lock ), 'acquire synthetic user lock for CLI tests' );

	$hidden_res = $run_cli( array( 'zmai', 'locks', 'list', '--format=json' ) );
	$hidden     = json_decode( $hidden_res['stdout'], true );
	$zmai_cli_expect( 0 === $hidden_res['code'] && 1 === count( $hidden ) && $hidden[0]['name'] === $user_lock_name && ! array_key_exists( 'token', $hidden[0] ), 'wp zmai locks list omits token by default' );

	$shown_res = $run_cli( array( 'zmai', 'locks', 'list', '--show-tokens', '--format=json' ) );
	$shown     = json_decode( $shown_res['stdout'], true );
	$zmai_cli_expect( 0 === $shown_res['code'] && 1 === count( $shown ) && ( $shown[0]['token'] ?? '' ) === $user_lock['token'], 'wp zmai locks list --show-tokens returns exact token' );

	$no_yes = $run_cli( array( 'zmai', 'locks', 'unlock', $user_lock_name, '--token=' . $user_lock['token'] ) );
	$zmai_cli_expect( 0 !== $no_yes['code'] && 1 === count( $store->get_lock()->inspect_locks() ), 'wp zmai locks unlock fails without --yes and preserves lock' );

	$bad_tok = $run_cli( array( 'zmai', 'locks', 'unlock', $user_lock_name, '--token=wrong-token', '--yes' ) );
	$zmai_cli_expect( 0 !== $bad_tok['code'] && 1 === count( $store->get_lock()->inspect_locks() ), 'wp zmai locks unlock fails with mismatched token and preserves lock' );

	$good_unlock = $run_cli( array( 'zmai', 'locks', 'unlock', $user_lock_name, '--token=' . $user_lock['token'], '--yes' ) );
	$zmai_cli_expect( 0 === $good_unlock['code'] && array() === $store->get_lock()->inspect_locks(), 'wp zmai locks unlock releases lock with exact token and --yes' );
	if ( 0 === $good_unlock['code'] ) {
		unset( $held_locks[ $user_lock_name ] );
	}

	// 5. Unlocking migration lock clears zmai_migration_release_failed flag.
	$mig_lock = $store->get_lock()->acquire_lock( 'zmai_avatar_lock_migration' );
	if ( ! is_wp_error( $mig_lock ) ) {
		$held_locks['zmai_avatar_lock_migration'] = $mig_lock;
	}
	update_option( Migration::RELEASE_FAILED_OPTION, 1, false );
	$mig_unlock = $run_cli( array( 'zmai', 'locks', 'unlock', 'zmai_avatar_lock_migration', '--token=' . $mig_lock['token'], '--yes' ) );
	wp_cache_delete( Migration::RELEASE_FAILED_OPTION, 'options' );
	$zmai_cli_expect(
		0 === $mig_unlock['code']
		&& array() === $store->get_lock()->inspect_locks()
		&& false === get_option( Migration::RELEASE_FAILED_OPTION, false ),
		'unlocking migration lock clears zmai_migration_release_failed'
	);
	if ( 0 === $mig_unlock['code'] ) {
		unset( $held_locks['zmai_avatar_lock_migration'] );
	}
} finally {
	foreach ( $held_locks as $held ) {
		$store->get_lock()->release_lock( $held );
	}
	if ( '' !== $source_file && is_file( $source_file ) ) {
		wp_delete_file( $source_file );
	}
	if ( null === $previous_job ) {
		delete_option( Migration::IMPORT_JOB_OPTION );
	} else {
		update_option( Migration::IMPORT_JOB_OPTION, $previous_job, false );
	}
	if ( null === $previous_failed ) {
		delete_option( Migration::RELEASE_FAILED_OPTION );
	} else {
		update_option( Migration::RELEASE_FAILED_OPTION, $previous_failed, false );
	}
	if ( $created_user > 0 ) {
		$store->delete_user_avatar( $created_user );
		if ( is_multisite() ) {
			wpmu_delete_user( $created_user );
		} else {
			wp_delete_user( $created_user );
		}
	}
	if ( $switched ) {
		restore_current_blog();
	}
}

echo "RESULT {$zmai_cli_checks} checks, {$zmai_cli_failed} failed\n";
if ( $zmai_cli_failed > 0 ) {
	throw new RuntimeException( 'CLI checks failed.' );
}
