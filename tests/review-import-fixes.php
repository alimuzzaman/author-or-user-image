<?php
/** Review fixes: permanent import skips, operation-nonce finish and bounded skip records. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
use AuthorOrUserImage\Import_Admin;
require __DIR__ . '/import-fixtures.php';

/** Stored job, or an empty array so a WP_Error or null never reaches array access. */
function zmai_rv_job( $job ) {
	return is_array( $job ) ? $job : array();
}

/** Owner avatar URL for a file that is never created. */
function zmai_rv_missing_url() {
	$uploads = wp_upload_dir();
	return $uploads['url'] . '/import-' . wp_generate_uuid4() . '.png';
}

/** Static GIF, a deterministic permanent-skip source. */
function zmai_rv_gif() {
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$path  = $uploads['path'] . '/import-' . wp_generate_uuid4() . '.gif';
	$image = imagecreatetruecolor( 20, 20 );
	imagegif( $image, $path );
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
	$GLOBALS['zmai_it_files'][] = $path;
	return $uploads['url'] . '/' . basename( $path );
}

/** Bytes named as PNG but not an image: a permanent invalid-source skip. */
function zmai_rv_garbage() {
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$path = $uploads['path'] . '/import-' . wp_generate_uuid4() . '.png';
	file_put_contents( $path, 'not an image' );
	$GLOBALS['zmai_it_files'][] = $path;
	return $uploads['url'] . '/' . basename( $path );
}

/** Fixed skip record: positive integer owner ID and lowercase reason code only. */
function zmai_rv_record_ok( $record ) {
	return is_array( $record ) && 2 === count( $record ) && isset( $record['user_id'], $record['reason'] ) && is_int( $record['user_id'] ) && $record['user_id'] > 0 && is_string( $record['reason'] ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $record['reason'] );
}

/** Owner IDs in stored order. */
function zmai_rv_skip_ids( $job ) {
	$records = is_array( $job['skips'] ?? null ) ? $job['skips'] : array();
	return array_map( static function ( $record ) { return is_array( $record ) ? (int) ( $record['user_id'] ?? 0 ) : 0; }, $records );
}

/** Schema-valid records with distinct owner IDs, for cap checks. */
function zmai_rv_records( $count ) {
	$records = array();
	for ( $i = 1; $i <= $count; ++$i ) {
		$records[] = array( 'user_id' => $i, 'reason' => 'missing_file' );
	}
	return $records;
}

/** Bounded POSTs until the sweep stops; a WP_Error ends the loop and is returned as is. */
function zmai_rv_drain( Migration $migration, $job ) {
	for ( $n = 0; $n < 60 && is_array( $job ) && 'running' === ( $job['state'] ?? '' ); ++$n ) {
		$job = $migration->run_import_batch( $job['id'], $job['revision'] );
	}
	return $job;
}

$has_finish = method_exists( Migration::class, 'finish_import' );
zmai_it_check( $has_finish, 'finish_import contract exists before implementation' );
wp_set_current_user( 1 );
$store      = new Image_Store();
$migration  = new Migration( $store );
$controller = new Import_Admin( $migration );
$source     = 'wp-user-avatars';
$finish     = static function ( $id, $revision ) use ( $migration, $has_finish ) {
	return $has_finish ? $migration->finish_import( $id, $revision ) : new WP_Error( 'zmai_test_absent', 'finish_import is not implemented.' );
};
$http_count = 0;
$deny_http  = static function () use ( &$http_count ) {
	++$http_count;
	return new WP_Error( 'test_http_forbidden' );
};
add_filter( 'pre_http_request', $deny_http );
$busy = null;
try {
	// 1. Schema: reflection on the validator only, no sweep and no DB writes beyond the option.
	$validate  = new ReflectionMethod( Migration::class, 'valid_import_job' );
	$counts_of = new ReflectionMethod( Migration::class, 'import_counts' );
	if ( PHP_VERSION_ID < 80100 ) {
		$validate->setAccessible( true );
		$counts_of->setAccessible( true );
	}
	$valid = static function ( $job ) use ( $validate, $migration ) {
		return (bool) $validate->invoke( $migration, $job );
	};
	$counts = array_diff_key( $counts_of->invoke( $migration ), array( 'skipped' => 0 ) );
	$legacy_counts = $counts;
	$counts['skipped'] = 0;
	$job_base = array(
		'schema'          => 1,
		'id'              => wp_generate_uuid4(),
		'source'          => $source,
		'site_id'         => get_current_blog_id(),
		'state'           => 'paused_with_failures',
		'last_user_id'    => 0,
		'upper_user_id'   => 500,
		'sweep'           => 1,
		'revision'        => 2,
		'retry_needed'    => true,
		'counts'          => $counts,
		'last_error_code' => 'busy',
		'skips'           => array(),
	);
	$legacy = $job_base;
	unset( $legacy['skips'] );
	$legacy['counts'] = $legacy_counts;
	zmai_it_check( $valid( $legacy ), 'schema 1 jobs without skips or skipped count stay accepted' );
	zmai_it_check( $valid( $job_base ), 'new schema 1 job with empty skips and skipped count validates' );
	$full = $job_base;
	$full['skips'] = zmai_rv_records( 100 );
	$full['counts']['skipped'] = 100;
	zmai_it_check( $valid( $full ), 'exactly 100 skip records is the schema maximum' );
	$over = $full;
	$over['skips'] = zmai_rv_records( 101 );
	$over['counts']['skipped'] = 101;
	zmai_it_check( ! $valid( $over ), '101 skip records are rejected by the schema' );
	$malformed = array(
		'string user ID'   => array( array( 'user_id' => '7', 'reason' => 'missing_file' ) ),
		'zero user ID'     => array( array( 'user_id' => 0, 'reason' => 'missing_file' ) ),
		'negative user ID' => array( array( 'user_id' => -3, 'reason' => 'missing_file' ) ),
		'integer reason'   => array( array( 'user_id' => 7, 'reason' => 5 ) ),
		'empty reason'     => array( array( 'user_id' => 7, 'reason' => '' ) ),
		'path reason'      => array( array( 'user_id' => 7, 'reason' => '/var/www/wp-content/uploads/a.png' ) ),
		'URL reason'       => array( array( 'user_id' => 7, 'reason' => 'https://example.invalid/a.png' ) ),
		'skips not a list' => 'none',
	);
	foreach ( $malformed as $label => $skips ) {
		$bad = $job_base;
		$bad['skips'] = $skips;
		if ( is_array( $skips ) ) {
			$bad['counts']['skipped'] = count( $skips );
		}
		zmai_it_check( ! $valid( $bad ), 'schema rejects ' . $label );
	}
	$negative = $job_base;
	$negative['counts']['skipped'] = -1;
	zmai_it_check( ! $valid( $negative ), 'schema rejects a negative skipped count' );
	$stringy = $job_base;
	$stringy['counts']['skipped'] = '1';
	zmai_it_check( ! $valid( $stringy ), 'schema rejects a string skipped count' );

	// 1b. Source-changed and image-editor failures are not permanent skips; zmai_import_path has no producer.
	$reason_of = new ReflectionMethod( Migration::class, 'permanent_import_reason' );
	if ( PHP_VERSION_ID < 80100 ) {
		$reason_of->setAccessible( true );
	}
	foreach ( array( 'zmai_source_changed', 'zmai_image_editor', 'zmai_import_path' ) as $code ) {
		zmai_it_check( '' === $reason_of->invoke( $migration, $code ), 'permanent_import_reason returns empty for ' . $code );
	}
	zmai_it_check( 'missing_file' === $reason_of->invoke( $migration, 'zmai_import_missing_file' ), 'permanent_import_reason still maps zmai_import_missing_file to missing_file' );
	// Schema-1 jobs persisted before this change may already hold these reasons; they must keep validating.
	$persisted_reasons = $job_base;
	$persisted_reasons['skips'] = array( array( 'user_id' => 7, 'reason' => 'source_changed' ), array( 'user_id' => 8, 'reason' => 'image_editor' ) );
	$persisted_reasons['counts']['skipped'] = 2;
	zmai_it_check( $valid( $persisted_reasons ), 'existing skip records with source_changed and image_editor reasons stay schema-valid' );

	// 2. Permanent skips only: a sweep completes and records owner IDs with fixed reason codes.
	delete_option( 'zmai_import_job' );
	$missing = zmai_it_user();
	update_user_meta( $missing, 'wp_user_avatars', array( 'full' => zmai_rv_missing_url() ) );
	$gif = zmai_it_user();
	update_user_meta( $gif, 'wp_user_avatars', array( 'full' => zmai_rv_gif() ) );
	$garbage = zmai_it_user();
	update_user_meta( $garbage, 'wp_user_avatars', array( 'full' => zmai_rv_garbage() ) );
	$skip_job     = zmai_rv_job( zmai_rv_drain( $migration, $migration->start_import( $source, wp_generate_uuid4(), 0 ) ) );
	$skip_records = is_array( $skip_job['skips'] ?? null ) ? $skip_job['skips'] : array();
	zmai_it_check( 'complete' === ( $skip_job['state'] ?? '' ) && 0 === ( $skip_job['counts']['failed'] ?? -1 ) && false === ( $skip_job['retry_needed'] ?? true ), 'permanent-only sweep completes with no failures and no retry' );
	zmai_it_check( array() === array_diff( array( $missing, $gif, $garbage ), zmai_rv_skip_ids( $skip_job ) ), 'missing, GIF and invalid owners are permanent skips by ID' );
	zmai_it_check( array() !== $skip_records && count( array_filter( $skip_records, 'zmai_rv_record_ok' ) ) === count( $skip_records ), 'each skip record is an integer owner ID with a fixed reason code' );
	zmai_it_check( ( $skip_job['counts']['skipped'] ?? 0 ) >= 3, 'skipped count covers every permanent skip' );
	$json = (string) wp_json_encode( $skip_records );
	zmai_it_check( array() !== $skip_records && false === strpos( $json, ABSPATH ) && false === strpos( $json, 'wp-content' ) && false === strpos( $json, 'import-' ) && false === strpos( $json, 'http' ), 'skip records disclose no path, URL or file name' );

	// 3. Busy owners pause with failures; only an authenticated operation-nonce finish completes them.
	delete_option( 'zmai_import_job' );
	$busy_user = zmai_it_user();
	update_user_meta( $busy_user, 'wp_user_avatars', array( 'full' => zmai_it_owned_file()['url'] ) );
	$busy   = $store->get_lock()->acquire_lock( 'zmai_avatar_lock_user_' . $busy_user );
	$paused = zmai_rv_job( zmai_rv_drain( $migration, $migration->start_import( $source, wp_generate_uuid4(), 0 ) ) );
	zmai_it_check( 'paused_with_failures' === ( $paused['state'] ?? '' ) && true === ( $paused['retry_needed'] ?? false ) && ( $paused['counts']['failed'] ?? 0 ) >= 1, 'busy owner pauses the sweep with a retryable failure' );
	zmai_it_check( ! in_array( $busy_user, zmai_rv_skip_ids( $paused ), true ), 'transient busy owner is not recorded as a permanent skip' );
	$request = array(
		'site_id'  => (string) get_current_blog_id(),
		'job_id'   => (string) ( $paused['id'] ?? '' ),
		'revision' => (string) ( $paused['revision'] ?? 0 ),
	);
	$wrong = $request;
	$wrong['_wpnonce'] = wp_create_nonce( 'zmai_import_batch_' . ( $paused['id'] ?? '' ) );
	$denied = $controller->process_request( 'finish', $wrong, 'POST' );
	zmai_it_check( is_wp_error( $denied ) && $paused === $migration->import_status(), 'finish with a batch nonce is denied and changes nothing' );
	$get = $request;
	$get['_wpnonce'] = wp_create_nonce( 'zmai_import_finish_' . ( $paused['id'] ?? '' ) );
	zmai_it_check( is_wp_error( $controller->process_request( 'finish', $get, 'GET' ) ), 'finish requires POST' );
	$stale = $request;
	$stale['_wpnonce']  = wp_create_nonce( 'zmai_import_finish_' . ( $paused['id'] ?? '' ) );
	$stale['revision']  = (string) max( 0, ( $paused['revision'] ?? 1 ) - 1 );
	$before_stale = $migration->import_status();
	$controller->process_request( 'finish', $stale, 'POST' );
	zmai_it_check( $before_stale === $migration->import_status(), 'stale finish revision is a no-op' );
	$finish_req = $request;
	$finish_req['_wpnonce'] = wp_create_nonce( 'zmai_import_finish_' . ( $paused['id'] ?? '' ) );
	$finished = $controller->process_request( 'finish', $finish_req, 'POST' );
	$after    = zmai_rv_job( $migration->import_status() );
	zmai_it_check( ! is_wp_error( $finished ) && 'complete' === ( $after['state'] ?? '' ), 'authenticated finish with the current revision completes the paused job' );
	zmai_it_check( ( $after['counts'] ?? null ) === ( $paused['counts'] ?? array() ) && ( $after['skips'] ?? array() ) === ( $paused['skips'] ?? array() ), 'finish preserves counts and skip records' );
	// 3b. Replaying Finish or Resume after success is a no-op, with the stale and the current revision.
	$replayed = $migration->import_status();
	foreach ( array( 'finish', 'resume' ) as $replay ) {
		foreach ( array( (string) ( $paused['revision'] ?? 0 ), (string) ( $after['revision'] ?? 0 ) ) as $replay_revision ) {
			$replay_req             = $request;
			$replay_req['revision'] = $replay_revision;
			$replay_req['_wpnonce'] = wp_create_nonce( 'zmai_import_' . $replay . '_' . ( $paused['id'] ?? '' ) );
			$replay_result = $controller->process_request( $replay, $replay_req, 'POST' );
			zmai_it_check( ! is_wp_error( $replay_result ) && $replayed === $migration->import_status(), $replay . ' replay dispatches with purpose nonce and preserves completed job at revision ' . $replay_revision );
		}
	}
	zmai_it_check( $replayed === $migration->import_status(), 'stale and current Finish/Resume replays after success are no-ops' );
	$next = $migration->start_import( 'one-user-avatar', wp_generate_uuid4(), (int) ( $after['revision'] ?? 0 ) );
	zmai_it_check( is_array( $next ) && 'running' === ( $next['state'] ?? '' ) && 'one-user-avatar' === ( $next['source'] ?? '' ), 'a new source starts after the paused job is finished' );
	$store->get_lock()->release_lock( $busy );
	$busy = null;

	// 4. Running jobs and recovery fences never finish, by service or by admin request.
	delete_option( 'zmai_import_job' );
	$running = zmai_rv_job( $migration->start_import( $source, wp_generate_uuid4(), 0 ) );
	$finish( $running['id'] ?? '', $running['revision'] ?? 0 );
	zmai_it_check( 'running' === ( zmai_rv_job( $migration->import_status() )['state'] ?? '' ), 'finish cannot complete a running job' );
	$fenced = zmai_rv_job( $migration->import_status() );
	$fenced['state']           = 'recovery_required';
	$fenced['last_error_code'] = 'recovery';
	update_option( 'zmai_import_job', $fenced, false );
	$fenced = zmai_rv_job( $migration->import_status() );
	$finish( $fenced['id'] ?? '', $fenced['revision'] ?? 0 );
	zmai_it_check( 'recovery_required' === ( zmai_rv_job( $migration->import_status() )['state'] ?? '' ), 'recovery_required jobs cannot be finished' );
	$fence_req = array(
		'site_id'  => (string) get_current_blog_id(),
		'job_id'   => (string) ( $fenced['id'] ?? '' ),
		'revision' => (string) ( $fenced['revision'] ?? 0 ),
		'_wpnonce' => wp_create_nonce( 'zmai_import_finish_' . ( $fenced['id'] ?? '' ) ),
	);
	$controller->process_request( 'finish', $fence_req, 'POST' );
	zmai_it_check( 'recovery_required' === ( zmai_rv_job( $migration->import_status() )['state'] ?? '' ), 'authenticated finish also leaves recovery fences in place' );

	// 5. The native status page renders skip owner IDs and reason codes, never paths.
	delete_option( 'zmai_import_job' );
	update_option( 'zmai_import_job', $skip_job, false );
	ob_start();
	$controller->render_page();
	$html  = ob_get_clean();
	$shows = array() !== $skip_records;
	foreach ( $skip_records as $record ) {
		$shows = $shows && false !== strpos( $html, (string) ( $record['user_id'] ?? '' ) ) && false !== strpos( $html, (string) ( $record['reason'] ?? '' ) );
	}
	zmai_it_check( $shows, 'status page renders each skip owner ID and reason code' );
	zmai_it_check( false === strpos( $html, ABSPATH ) && false === strpos( $html, 'wp-content/uploads' ), 'status page discloses no source paths' );

	// 6. A real sweep with 102 permanent skips keeps the first 100 IDs in order and counts all of them.
	delete_option( 'zmai_import_job' );
	for ( $i = 0; $i < 102; ++$i ) {
		$cap_user = zmai_it_user();
		update_user_meta( $cap_user, 'wp_user_avatars', array( 'full' => zmai_rv_missing_url() ) );
	}
	$cap_job    = zmai_rv_job( zmai_rv_drain( $migration, $migration->start_import( $source, wp_generate_uuid4(), 0 ) ) );
	$cap_skips  = is_array( $cap_job['skips'] ?? null ) ? $cap_job['skips'] : array();
	$cap_ids    = zmai_rv_skip_ids( $cap_job );
	$sorted_ids = $cap_ids;
	sort( $sorted_ids );
	zmai_it_check( 'complete' === ( $cap_job['state'] ?? '' ) && 0 === ( $cap_job['counts']['failed'] ?? -1 ), 'permanent skips alone complete the 102-owner sweep' );
	zmai_it_check( 100 === count( $cap_skips ) && ( $cap_job['counts']['skipped'] ?? 0 ) >= 102, 'skip list is capped at 100 while the skipped count keeps all of them' );
	zmai_it_check( array() !== $cap_ids && $cap_ids === $sorted_ids && count( array_unique( $cap_ids ) ) === count( $cap_ids ), 'capped skip records stay in deterministic ascending owner order' );

	zmai_it_check( 0 === $http_count, 'skip, finish and cap checks make zero HTTP requests' );
} finally {
	remove_filter( 'pre_http_request', $deny_http );
	if ( is_array( $busy ) ) {
		$store->get_lock()->release_lock( $busy );
	}
	zmai_it_cleanup();
}
zmai_it_done();
