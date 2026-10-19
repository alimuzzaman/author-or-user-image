<?php
/** Shared import, rate, square and lifecycle contracts on disposable WordPress. */
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Migration;
use AuthorOrUserImage\Privacy;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Disposable local WordPress required.' );
}
global $checks, $failures;
$checks = 0;
$failures = array();
function p1_check( $ok, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $ok ) { $failures[] = $label; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
p1_check( method_exists( Image_Store::class, 'import_local_image' ), 'store exposes owner-locked local import contract' );
p1_check( method_exists( Migration::class, 'with_migration_lock' ), 'migration exposes shared coordinator mutex' );
if ( $failures ) { WP_CLI::halt( 1 ); }
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once __DIR__ . '/migration-upload-transport.php';
$store = new Image_Store();
wp_set_current_user( 1 );
$old_policy = get_option( 'zmai_upload_policy', null );
delete_option( 'zmai_upload_policy' );
$uid = wp_insert_user( array( 'user_login' => 'phase1-' . uniqid(), 'user_email' => uniqid() . '@example.invalid', 'user_pass' => 'public-local-test-fixture', 'role' => 'subscriber' ) );
$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
$path = $uploads['path'] . '/p1-source-' . uniqid() . '.png';
$image = imagecreatetruecolor( 61, 21 );
$red = imagecolorallocate( $image, 255, 0, 0 );
$green = imagecolorallocate( $image, 0, 255, 0 );
imagefill( $image, 0, 0, $red );
imagefilledrectangle( $image, 20, 0, 40, 20, $green );
imagepng( $image, $path );
if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $image ); }
$hash = hash_file( 'sha256', $path );
$resolve = static function () use ( $path ) { return array( 'path' => $path, 'name' => 'source.png' ); };
$result = $store->import_local_image( $resolve, $uid );
p1_check( ! is_wp_error( $result ) && 'imported' === $result['status'], 'local import returns imported outcome' );
$id = is_wp_error( $result ) ? 0 : $result['attachment_id'];
$meta = wp_get_attachment_metadata( $id );
$square = $id && isset( $meta['sizes']['zmai-avatar']['file'] ) ? dirname( get_attached_file( $id ) ) . '/' . $meta['sizes']['zmai-avatar']['file'] : '';
$dims = $square && is_file( $square ) ? getimagesize( $square ) : false;
p1_check( $dims && 21 === $dims[0] && 21 === $dims[1], 'small rectangle gets non-upscaled square metadata/file' );
if ( $dims ) {
	$decoded = imagecreatefrompng( $square );
	$left = imagecolorsforindex( $decoded, imagecolorat( $decoded, 0, 10 ) );
	$right = imagecolorsforindex( $decoded, imagecolorat( $decoded, 20, 10 ) );
	p1_check( 0 === $left['red'] && 255 === $left['green'] && 0 === $right['red'] && 255 === $right['green'], 'store publishes actual center crop pixels' );
	if ( PHP_VERSION_ID < 80000 ) { imagedestroy( $decoded ); }
}
p1_check( $id && ! metadata_exists( 'user', $uid, Image_Store::user_meta_key( Image_Store::USER_META ) ) && $id === (int) get_user_meta( $uid, Image_Store::user_meta_key( Image_Store::MIGRATED_USER_META ), true ), 'import commits only migrated mapping' );
p1_check( $id && $store->owns_attachment( $id, $uid ) && '1' === get_post_meta( $id, '_zmai_avatar_square_version', true ), 'owned attachment carries checked square marker' );
p1_check( hash_file( 'sha256', $path ) === $hash && get_attached_file( $id ) !== $path, 'source bytes are unchanged and copied' );
p1_check( $square && basename( $square ) === basename( wp_parse_url( $store->get_url( $uid, 1024, false ), PHP_URL_PATH ) ), 'larger avatar request uses named square rather than rectangle' );
// A failed cleanup after mapping commit stays a failure; replay retries cleanup.
$old_id = $id;
wp_delete_file( get_attached_file( $old_id ) );
$veto = static function ( $delete, $post ) use ( $old_id ) { return (int) $post->ID === $old_id ? false : $delete; };
add_filter( 'pre_delete_attachment', $veto, 10, 2 );
$committed_failure = $store->import_local_image( $resolve, $uid );
remove_filter( 'pre_delete_attachment', $veto, 10 );
$new_id = $store->get_user_attachment_id( $uid );
p1_check( is_wp_error( $committed_failure ) && $new_id && $new_id !== $old_id && get_post( $old_id ), 'post-commit cleanup veto reports failure while preserving committed replacement' );
$replay = $store->import_local_image( $resolve, $uid );
p1_check( ! is_wp_error( $replay ) && 'already_present' === $replay['status'] && ! get_post( $old_id ), 'existing-fallback replay retries old-media cleanup before success' );
// Continue assertions with the replacement's square inventory.
$id = $new_id;
$meta = wp_get_attachment_metadata( $id );
$square = dirname( get_attached_file( $id ) ) . '/' . $meta['sizes']['zmai-avatar']['file'];
$called = false;
$unused = static function () use ( &$called ) { $called = true; return new WP_Error( 'unexpected' ); };
$again = $store->import_local_image( $unused, $uid );
p1_check( ! is_wp_error( $again ) && 'already_present' === $again['status'] && ! $called, 'replay preserves migrated fallback without resolving source' );
$store->write_value( $uid, Image_Store::USER_META, '', 99999999 );
$choice = $store->import_local_image( $unused, $uid );
p1_check( ! is_wp_error( $choice ) && 'explicit_choice' === $choice['status'], 'invalid positive explicit choice still suppresses import' );
$store->delete_user_avatar( $uid );
$choice = $store->import_local_image( $unused, $uid );
p1_check( ! is_wp_error( $choice ) && 'explicit_choice' === $choice['status'] && ! is_file( $square ), 'removal leaves explicit zero and deletes recorded square' );
$store->set_blocked( $uid, true );
$blocked = $store->import_local_image( $unused, $uid );
p1_check( ! is_wp_error( $blocked ) && 'blocked' === $blocked['status'], 'blocked import has distinct outcome' );
$store->set_blocked( $uid, false );
$gone = $store->import_local_image( $unused, 99999999 );
p1_check( ! is_wp_error( $gone ) && 'gone' === $gone['status'], 'gone import has distinct outcome' );
$store->write_value( $uid, Image_Store::USER_META, '', null );
$busy = $store->get_lock()->acquire_lock( 'zmai_avatar_lock_user_' . $uid );
$denied = $store->import_local_image( $unused, $uid );
p1_check( is_wp_error( $denied ) && 'zmai_busy' === $denied->get_error_code(), 'busy import does not resolve or mutate' );
$store->get_lock()->release_lock( $busy );
update_option( 'zmai_upload_policy', array( 'schema' => 1, 'mode' => 'roles', 'roles' => array() ), false );
$import = $store->import_local_image( $resolve, $uid );
p1_check( ! is_wp_error( $import ) && 'imported' === $import['status'], 'administrator import ignores self-service role restriction' );
$newid = is_wp_error( $import ) ? 0 : $import['attachment_id'];
$newmeta = wp_get_attachment_metadata( $newid );
$new_square = $newid ? dirname( get_attached_file( $newid ) ) . '/' . $newmeta['sizes']['zmai-avatar']['file'] : '';
if ( $new_square ) { wp_delete_file( $new_square ); }
p1_check( basename( $new_square ) === basename( wp_parse_url( $store->get_url( $uid, 1024, false ), PHP_URL_PATH ) ), 'offloaded square keeps its metadata URL without falling back to rectangular full image' );
$repaired = $store->import_local_image( $resolve, $uid );
p1_check( ! is_wp_error( $repaired ) && 'imported' === $repaired['status'] && ! get_post( $newid ), 'invalid migrated fallback is repaired and old owned media cleaned' );
$store->delete_user_avatar( $uid );
delete_option( 'zmai_upload_policy' );
$outside = $store->import_local_image( static function () { return array( 'path' => __FILE__, 'name' => 'outside.png' ); }, $uid );
p1_check( ! is_wp_error( $outside ) && 'explicit_choice' === $outside['status'], 'explicit zero wins before path resolution' );
$store->write_value( $uid, Image_Store::USER_META, '', null );
$outside = $store->import_local_image( static function () { return array( 'path' => __FILE__, 'name' => 'outside.png' ); }, $uid );
p1_check( is_wp_error( $outside ), 'import rejects files outside current uploads' );
$rate_key = Image_Store::user_meta_key( '_zmai_avatar_upload_rate' );
for ( $attempt = 1; $attempt <= 6; ++$attempt ) {
	$copy = $uploads['path'] . '/p1-upload-' . uniqid() . '.png';
	copy( $path, $copy );
	$_FILES['p1'] = array( 'name' => 'p1.png', 'tmp_name' => $copy, 'size' => filesize( $copy ), 'error' => UPLOAD_ERR_OK );
	$saved = $store->save_upload( 'p1', $uid );
	p1_check( $attempt < 6 ? is_int( $saved ) && $saved > 0 : is_wp_error( $saved ) && 'zmai_rate_limited' === $saved->get_error_code(), 'owner upload attempt ' . $attempt . ' enforces minute cap' );
	wp_delete_file( $copy );
}
$record = get_user_meta( $uid, $rate_key, true );
p1_check( is_array( $record ) && 5 === $record['minute_count'] && 5 === $record['hour_count'], 'rate record is bounded and denied attempt does not advance it' );
update_user_meta( $uid, $rate_key, array( 'minute_start' => time() + 60, 'minute_count' => 5, 'hour_start' => time() + 3600, 'hour_count' => 5 ) );
$reversed = $store->save_upload( 'p1', $uid );
p1_check( is_wp_error( $reversed ) && 'zmai_rate_limited' === $reversed->get_error_code(), 'clock reversal grants no new upload window' );
// An exhausted hour cannot be bypassed by a fresh minute.
update_user_meta( $uid, $rate_key, array( 'minute_start' => time() - 61, 'minute_count' => 5, 'hour_start' => time(), 'hour_count' => 20 ) );
$hour = $store->save_upload( 'p1', $uid );
p1_check( is_wp_error( $hour ) && 'zmai_rate_limited' === $hour->get_error_code(), 'twenty-first hourly attempt is denied after minute expires' );
// Both elapsed windows reset. The CLI transport uses real core sideload.
update_user_meta( $uid, $rate_key, array( 'minute_start' => time() - 3601, 'minute_count' => 5, 'hour_start' => time() - 3601, 'hour_count' => 20 ) );
$copy = $uploads['path'] . '/p1-reset-' . uniqid() . '.png';
copy( $path, $copy );
$_FILES['p1']['tmp_name'] = $copy;
$reset = $store->save_upload( 'p1', $uid );
$record = get_user_meta( $uid, $rate_key, true );
p1_check( is_int( $reset ) && 1 === $record['minute_count'] && 1 === $record['hour_count'], 'expired minute and hour reset under owner lock' );
// Known failed counter persistence must stop before media creation.
delete_user_meta( $uid, $rate_key );
$deny_rate = static function ( $check, $object_id, $key ) use ( $rate_key ) { return $key === $rate_key ? false : $check; };
add_filter( 'add_user_metadata', $deny_rate, 10, 3 );
$copy = $uploads['path'] . '/p1-ratefail-' . uniqid() . '.png';
copy( $path, $copy );
$_FILES['p1']['tmp_name'] = $copy;
$ratefail = $store->save_upload( 'p1', $uid );
remove_filter( 'add_user_metadata', $deny_rate, 10 );
p1_check( is_wp_error( $ratefail ) && 'zmai_storage' === $ratefail->get_error_code() && $store->get_user_attachment_id( $uid ) === $reset, 'failed counter persistence prevents media and mapping changes' );
wp_delete_file( $copy );
update_user_meta( $uid, $rate_key, array( 'minute_start' => time(), 'minute_count' => 5, 'hour_start' => time(), 'hour_count' => 5 ) );
$privacy = new Privacy( $store );
$export = $privacy->export( get_userdata( $uid )->user_email );
p1_check( false !== strpos( wp_json_encode( $export ), 'Upload attempts' ), 'privacy exports rate counters and reset times' );
$erase = $privacy->erase( get_userdata( $uid )->user_email );
p1_check( $erase['items_removed'] && ! metadata_exists( 'user', $uid, $rate_key ) && '0' === get_user_meta( $uid, Image_Store::user_meta_key( Image_Store::USER_META ), true ), 'privacy clears rate while retaining zero suppression' );
$store->write_value( $uid, Image_Store::USER_META, '', null );
// Reject a generated fallback metadata write and verify staged physical rollback.
$reject_metadata = static function ( $check, $object_id, $key, $value ) {
    return '_wp_attachment_metadata' === $key && is_array( $value ) && isset( $value['sizes']['zmai-avatar'] ) ? false : $check;
};
add_filter( 'update_post_metadata', $reject_metadata, 10, 4 );
$metadata_failure = $store->import_local_image( $resolve, $uid );
remove_filter( 'update_post_metadata', $reject_metadata, 10 );
p1_check( is_wp_error( $metadata_failure ) && ! $store->get_user_attachment_id( $uid ), 'rejected square metadata prevents mapping publication' );
// Metadata-stage removal contends on the same owner mutex.
$interleaved = null;
$race = static function ( $metadata, $attachment_id ) use ( $store, $uid, &$interleaved ) { $interleaved = $store->delete_user_avatar( $uid ); return $metadata; };
add_filter( 'wp_generate_attachment_metadata', $race, 10, 2 );
$import = $store->import_local_image( $resolve, $uid );
remove_filter( 'wp_generate_attachment_metadata', $race, 10 );
p1_check( ! is_wp_error( $import ) && is_wp_error( $interleaved ) && 'zmai_busy' === $interleaved->get_error_code(), 'import and removal share the same mutex during real metadata work' );
$store->delete_user_avatar( $uid );
p1_check( hash_file( 'sha256', $path ) === $hash, 'rollback and privacy never modify source bytes' );
$migration = new Migration( $store );
$value = $migration->with_migration_lock( static function () { return 'callback-value'; } );
p1_check( 'callback-value' === $value && ! get_option( 'zmai_avatar_lock_migration' ), 'migration wrapper returns callback value and releases token' );
$lock = $store->get_lock()->acquire_lock( 'zmai_avatar_lock_migration' );
$value = $migration->with_migration_lock( static function () { return true; } );
p1_check( is_wp_error( $value ) && 'zmai_busy' === $value->get_error_code(), 'coordinator wrapper serializes independent import callers' );
$store->get_lock()->release_lock( $lock );
wp_delete_file( $path );
if ( is_multisite() ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; wpmu_delete_user( $uid ); } else { wp_delete_user( $uid ); }
if ( null === $old_policy ) { delete_option( 'zmai_upload_policy' ); } else { update_option( 'zmai_upload_policy', $old_policy, false ); }
echo wp_json_encode( compact( 'checks', 'failures' ) ) . "\n";
WP_CLI::halt( $failures ? 1 : 0 );
