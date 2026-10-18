<?php
/**
 * CLI-only importer browser fixture. Prepare/cleanup with eval-file arguments:
 * prepare|cleanup /absolute/scratch/3/import-browser.json
 * The integrator owns the browser and must serialize this fixture with its run.
 */
use AuthorOrUserImage\Image_Store;
require __DIR__ . '/import-fixtures.php';
$mode = $args[0] ?? '';
$output = $args[1] ?? '';
$root = realpath( '/Users/alim/Sites/git/.scratch/author-or-user-image/v3.1/3' );
$parent_dir = realpath( dirname( $output ) );
if ( ! $root || ! $parent_dir || ( $root !== $parent_dir && 0 !== strpos( $parent_dir, $root . DIRECTORY_SEPARATOR ) ) || ! in_array( $mode, array( 'prepare', 'cleanup' ), true ) ) {
	throw new RuntimeException( 'Use prepare|cleanup and a JSON file inside the Phase 3 scratch directory.' );
}
wp_set_current_user( 1 );
if ( 'cleanup' === $mode ) {
	$fixture = json_decode( file_get_contents( $output ), true );
	if ( ! is_array( $fixture ) || realpath( ZMAI_PATH ) !== $fixture['candidate'] || get_current_blog_id() !== $fixture['site_id'] ) { throw new RuntimeException( 'Fixture candidate or site mismatch.' ); }
	$GLOBALS['zmai_it_users'] = $fixture['sourceUserIds'];
	$GLOBALS['zmai_it_files'] = array( $fixture['repairPath'], $fixture['missingPath'] );
	$GLOBALS['zmai_it_saved_job'] = $fixture['oldJob'];
	wp_delete_post( $fixture['postId'], true );
	zmai_it_cleanup();
	wp_delete_file( $output );
	echo "CLEANED importer browser fixture\n"; return;
}
if ( file_exists( $output ) || get_option( 'zmai_import_job' ) ) { throw new RuntimeException( 'Cleanup the previous browser fixture/job first.' ); }
$post_id = 0;
try {
	$file = zmai_it_file();
	$missing_path = dirname( $file['path'] ) . '/missing-' . wp_generate_uuid4() . '.png';
	$users = array();
	for ( $index = 0; $index < 45; ++$index ) {
		$id = zmai_it_user(); $users[] = $id;
		update_user_meta( $id, 'wp_user_avatars', array( 'full' => 0 === $index ? dirname( $file['url'] ) . '/' . basename( $missing_path ) : $file['url'] ) );
	}
	$post_id = wp_insert_post( array( 'post_title' => 'Disposable importer pixel fixture', 'post_status' => 'publish', 'post_author' => end( $users ), 'post_content' => '<!-- wp:post-author {"showAvatar":true} /-->' ), true );
	if ( is_wp_error( $post_id ) ) { throw new RuntimeException( 'Could not create fixture post.' ); }
	$fixture = array( 'candidate' => realpath( ZMAI_PATH ), 'site_id' => get_current_blog_id(), 'source' => 'wp-user-avatars', 'sourceUserIds' => $users, 'frontUrl' => get_permalink( $post_id ), 'postId' => $post_id, 'avatarSelector' => '.wp-block-post-author__avatar img', 'expectedRGB' => array( 0, 200, 50 ), 'missingPath' => $missing_path, 'repairPath' => $file['path'], 'oldJob' => $GLOBALS['zmai_it_saved_job'] );
	if ( false === file_put_contents( $output, wp_json_encode( $fixture ) ) ) { throw new RuntimeException( 'Could not record fixture state.' ); }
	echo "PREPARED 45 users, one missing local source, and an author-avatar pixel page\n";
} catch ( Throwable $error ) {
	if ( is_int( $post_id ) && $post_id ) { wp_delete_post( $post_id, true ); }
	zmai_it_cleanup();
	throw $error;
}
