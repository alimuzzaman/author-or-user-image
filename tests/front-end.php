<?php
/**
 * Phase 2A checks for the front-end avatar block, shortcode and admin-post
 * handlers. Run with wp eval-file tests/front-end.php on a disposable local
 * single-site fixture that has a .zmai-test-instance marker.
 *
 * @package AuthorOrUserImage
 */

// phpcs:disable WordPress.Security.EscapeOutput -- WP-CLI console assertions and captured exceptions are not HTML responses.
// phpcs:disable Squiz.Commenting.FunctionComment -- This CLI harness uses short purpose comments; production interfaces carry full parameter documentation.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Universal.Files.SeparateFunctionsFromOO.Mixed -- WP-CLI eval-file combines fixture functions and capture exceptions.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the candidate's local block.json, never a remote or credential source.

use AuthorOrUserImage\Admin;
use AuthorOrUserImage\Image_Store;
use AuthorOrUserImage\Upload_Policy;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! is_file( ABSPATH . '.zmai-test-instance' ) ) {
	throw new RuntimeException( 'Use only a disposable local WordPress test instance.' );
}
if ( is_multisite() ) {
	throw new RuntimeException( 'Phase 2A front-end checks run on a single-site fixture only.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/** Thrown by the wp_die handler so a rejected request is read instead of exiting. */
class ZMAI_FE_Die_Captured extends RuntimeException {
	/**
	 * Captured HTTP status.
	 *
	 * @var int
	 */
	public $status;

	public function __construct( $status, $message ) {
		parent::__construct( (string) $message );
		$this->status = (int) $status;
	}
}

/** Thrown by the wp_redirect filter so a redirect is read instead of exiting. */
class ZMAI_FE_Redirect_Captured extends RuntimeException {
	/**
	 * Captured HTTP status.
	 *
	 * @var int
	 */
	public $status;
	/**
	 * Captured redirect destination.
	 *
	 * @var string
	 */
	public $location;

	public function __construct( $status, $location ) {
		parent::__construct( 'redirect' );
		$this->status   = (int) $status;
		$this->location = (string) $location;
	}
}

global $zmai_fe_failures, $zmai_fe_checks, $zmai_fe_writes, $zmai_fe_users, $zmai_fe_attachments, $zmai_fe_front, $zmai_fe_store, $zmai_fe_admin, $zmai_fe_policy_before, $zmai_fe_prefix;
$zmai_fe_failures      = array();
$zmai_fe_checks        = 0;
$zmai_fe_writes        = array();
$zmai_fe_users         = array();
$zmai_fe_attachments   = array();
$zmai_fe_front         = null;
$zmai_fe_store         = new Image_Store();
$zmai_fe_admin         = new Admin( $zmai_fe_store );
$zmai_fe_policy_before = get_option( Upload_Policy::OPTION, null );
$zmai_fe_prefix        = 'zmai-fe-' . uniqid();

/** Record one assertion. Failures are collected and reported together at the end. */
function zmai_fe_expect( $condition, $label ) {
	global $zmai_fe_failures, $zmai_fe_checks;
	++$zmai_fe_checks;
	if ( ! $condition ) {
		$zmai_fe_failures[] = $label;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/** Print a diagnostic line that is not itself an assertion. */
function zmai_fe_info( $label, $outcome ) {
	echo 'INFO ' . $label . ': kind=' . $outcome['kind'] . ' status=' . $outcome['status'] . ' writes=' . $outcome['writes'] . ( '' !== $outcome['message'] ? ' message=' . $outcome['message'] : '' ) . "\n";
}

/** Run one check group so a thrown error becomes a failure and the other groups still run. */
function zmai_fe_run( $group ) {
	echo "\n== " . $group . " ==\n";
	try {
		call_user_func( 'zmai_fe_' . $group );
	} catch ( Throwable $error ) {
		zmai_fe_expect( false, $group . ' group threw ' . get_class( $error ) . ': ' . $error->getMessage() );
	}
	zmai_fe_reset_request();
}

/** Clear request superglobals between groups. */
function zmai_fe_reset_request() {
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	$_FILES   = array();
	unset( $_SERVER['REQUEST_METHOD'] );
}

/** Set the request method and fields that admin-post would see. */
function zmai_fe_request( $method, $post = array(), $get = array() ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_GET                      = $get;
	$_POST                     = $post;
	$_REQUEST                  = array_merge( $get, $post );
}

/** Path inside the candidate under test. */
function zmai_fe_path( $relative ) {
	$root = defined( 'ZMAI_TEST_CANDIDATE' ) ? ZMAI_TEST_CANDIDATE : dirname( __DIR__ );
	return $root . '/' . $relative;
}

/**
 * Create a fixture user with a unique login.
 *
 * @throws RuntimeException If fixture creation fails.
 */
function zmai_fe_user( $role, $suffix ) {
	global $zmai_fe_prefix, $zmai_fe_users;
	$login = $zmai_fe_prefix . '-' . $suffix;
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.invalid',
			'user_pass'  => 'public-local-test-fixture',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Could not create fixture user: ' . $id->get_error_message() );
	}
	$zmai_fe_users[] = $id;
	return $id;
}

/** Create a subscriber whose current avatar is a real JPEG attachment they own. */
function zmai_fe_avatar_user( $suffix ) {
	global $zmai_fe_store, $zmai_fe_attachments;
	$user_id = zmai_fe_user( 'subscriber', $suffix );
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$path  = $uploads['path'] . '/zmai-fe-' . uniqid() . '.jpg';
	$image = imagecreatetruecolor( 320, 240 );
	imagejpeg( $image, $path );
	$attachment = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Front-end fixture',
			'post_status'    => 'inherit',
		),
		$path
	);
	wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $path ) );
	update_post_meta( $attachment, Image_Store::ATTACHMENT_OWNER_META, $zmai_fe_store->owner_name( $user_id ) );
	update_user_meta( $user_id, Image_Store::user_meta_key( Image_Store::USER_META ), $attachment );
	$zmai_fe_attachments[] = $attachment;
	return array( $user_id, $attachment );
}

/** Current avatar attachment ID for a user, or zero. */
function zmai_fe_avatar_of( $user_id ) {
	global $zmai_fe_store;
	$id = $zmai_fe_store->get_user_attachment_id( $user_id );
	return is_numeric( $id ) ? (int) $id : 0;
}

/** Set the upload policy to a roles list that grants nobody. */
function zmai_fe_deny_all_policy() {
	update_option(
		Upload_Policy::OPTION,
		array(
			'schema' => 1,
			'mode'   => 'roles',
			'roles'  => array(),
		),
		false
	);
}

/** Return to the legacy policy, under which subscribers may upload. */
function zmai_fe_legacy_policy() {
	delete_option( Upload_Policy::OPTION );
}

/** Count write statements issued while a probe is attached to the query filter. */
function zmai_fe_write_probe( $sql ) {
	global $zmai_fe_writes;
	if ( preg_match( '/^\s*\(?\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql ) ) {
		$zmai_fe_writes[] = $sql;
	}
	return $sql;
}

/** WordPress error handler that throws, so a rejection is observable. */
function zmai_fe_die_handler() {
	return 'zmai_fe_die_capture';
}

/**
 * Capture a rejected request.
 *
 * @throws ZMAI_FE_Die_Captured Always.
 */
function zmai_fe_die_capture( $message, $title = '', $args = array() ) {
	$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0;
	throw new ZMAI_FE_Die_Captured( $status, (string) $message );
}

/**
 * Capture a redirect response.
 *
 * @throws ZMAI_FE_Redirect_Captured Always.
 */
function zmai_fe_redirect_capture( $location, $status ) {
	throw new ZMAI_FE_Redirect_Captured( $status, $location );
}

/**
 * Run one admin-post handler and report how it ended, without exiting the process.
 *
 * @return array{kind:string,status:int,location:string,message:string,writes:int}
 */
function zmai_fe_call( $callback ) {
	global $zmai_fe_writes;
	$zmai_fe_writes = array();
	add_filter( 'wp_die_handler', 'zmai_fe_die_handler' );
	add_filter( 'wp_redirect', 'zmai_fe_redirect_capture', 10, 2 );
	add_filter( 'query', 'zmai_fe_write_probe' );
	$outcome = array(
		'kind'     => 'none',
		'status'   => 0,
		'location' => '',
		'message'  => '',
	);
	try {
		call_user_func( $callback );
	} catch ( ZMAI_FE_Die_Captured $error ) {
		$outcome = array(
			'kind'     => 'die',
			'status'   => $error->status,
			'location' => '',
			'message'  => $error->getMessage(),
		);
	} catch ( ZMAI_FE_Redirect_Captured $error ) {
		$outcome = array(
			'kind'     => 'redirect',
			'status'   => $error->status,
			'location' => $error->location,
			'message'  => '',
		);
	} catch ( Throwable $error ) {
		$outcome = array(
			'kind'     => 'threw',
			'status'   => 0,
			'location' => '',
			'message'  => get_class( $error ) . ': ' . $error->getMessage(),
		);
	}
	remove_filter( 'query', 'zmai_fe_write_probe' );
	remove_filter( 'wp_redirect', 'zmai_fe_redirect_capture', 10 );
	remove_filter( 'wp_die_handler', 'zmai_fe_die_handler' );
	$outcome['writes'] = count( $zmai_fe_writes );
	return $outcome;
}

/** True when the handler refused with exactly this HTTP status. */
function zmai_fe_died_with( $outcome, $status ) {
	return 'die' === $outcome['kind'] && $status === $outcome['status'];
}

/**
 * True for a client-error refusal: a 4xx die, or a 303 redirect whose fixed
 * status is not a success code.
 */
function zmai_fe_is_rejected( $outcome ) {
	if ( 'die' === $outcome['kind'] ) {
		return $outcome['status'] >= 400 && $outcome['status'] < 500;
	}
	if ( 'redirect' === $outcome['kind'] && 303 === $outcome['status'] ) {
		return false === strpos( $outcome['location'], 'zmai_status=uploaded' ) && false === strpos( $outcome['location'], 'zmai_status=removed' );
	}
	return false;
}

/** The Front_End service, or null while the class is missing. */
function zmai_fe_front() {
	global $zmai_fe_store, $zmai_fe_front;
	$class = 'AuthorOrUserImage\\Front_End';
	if ( ! class_exists( $class ) ) {
		return null;
	}
	if ( null === $zmai_fe_front ) {
		$zmai_fe_front = new $class( $zmai_fe_store );
	}
	return $zmai_fe_front;
}

/** Call a Front_End method. Null means the class is missing or the call threw. */
function zmai_fe_front_call( $method, $args ) {
	$front = zmai_fe_front();
	if ( null === $front ) {
		return null;
	}
	try {
		return $front->$method( ...$args );
	} catch ( Throwable $error ) {
		return null;
	}
}

/** Render the block and shortcode callback. Null means the class is missing or threw. */
function zmai_fe_render( $attributes = array() ) {
	$front = zmai_fe_front();
	if ( null === $front ) {
		return null;
	}
	try {
		return $front->render_form( $attributes );
	} catch ( Throwable $error ) {
		zmai_fe_expect( false, 'render_form threw ' . get_class( $error ) . ': ' . $error->getMessage() );
		return null;
	}
}

/** Whether a callback is Front_End::render_form, in either instance or static form. */
function zmai_fe_is_render_form( $callback ) {
	if ( ! is_array( $callback ) || 2 !== count( $callback ) || 'render_form' !== $callback[1] ) {
		return false;
	}
	$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
	return 'AuthorOrUserImage\\Front_End' === $class;
}

/** Name of the registered block that renders through Front_End::render_form. */
function zmai_fe_block_name() {
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return null;
	}
	foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		if ( zmai_fe_is_render_form( $type->render_callback ?? null ) ) {
			return $name;
		}
	}
	return null;
}

/** Tag of the shortcode that renders through Front_End::render_form. */
function zmai_fe_shortcode_tag() {
	global $shortcode_tags;
	foreach ( (array) $shortcode_tags as $tag => $callback ) {
		if ( zmai_fe_is_render_form( $callback ) ) {
			return $tag;
		}
	}
	return null;
}

/** Parse markup for XPath checks. */
function zmai_fe_dom( $html ) {
	$doc      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . (string) $html . '</div>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return $doc;
}

/** Number of nodes matching an XPath query. */
function zmai_fe_count( DOMDocument $doc, $query ) {
	$nodes = ( new DOMXPath( $doc ) )->query( $query );
	return $nodes ? $nodes->length : 0;
}

/** Attribute values of nodes matching an XPath query. */
function zmai_fe_attr_values( DOMDocument $doc, $query, $attribute ) {
	$values = array();
	$nodes  = ( new DOMXPath( $doc ) )->query( $query );
	if ( $nodes ) {
		foreach ( $nodes as $node ) {
			$values[] = $node->getAttribute( $attribute );
		}
	}
	return $values;
}

/** True when the markup has at least two forms and no form sits inside another. */
function zmai_fe_forms_unnested( $html ) {
	if ( ! is_string( $html ) ) {
		return false;
	}
	preg_match_all( '#<(/?)form\b#i', $html, $tags, PREG_SET_ORDER );
	$depth  = 0;
	$opened = 0;
	foreach ( $tags as $tag ) {
		if ( '' === $tag[1] ) {
			++$depth;
			++$opened;
		} else {
			--$depth;
		}
		if ( $depth < 0 || $depth > 1 ) {
			return false;
		}
	}
	return 0 === $depth && $opened >= 2;
}

/** Replace unique IDs, reference attributes and nonce values so two renders can be compared. */
function zmai_fe_normalize( $html ) {
	$html = preg_replace( '/\b(id|for|aria-controls|aria-describedby|aria-labelledby)="[^"]*"/', '$1="N"', (string) $html );
	return preg_replace_callback(
		'/<input\b[^>]*>/i',
		static function ( $matches ) {
			return false !== strpos( $matches[0], 'name="_wpnonce"' ) ? preg_replace( '/value="[^"]*"/', 'value="N"', $matches[0] ) : $matches[0];
		},
		$html
	);
}

/** True when both renders are non-empty strings and match after normalization. */
function zmai_fe_same( $a, $b ) {
	return is_string( $a ) && is_string( $b ) && '' !== $a && zmai_fe_normalize( $a ) === zmai_fe_normalize( $b );
}

/** True when markup has visible text. */
function zmai_fe_has_text( $html ) {
	return is_string( $html ) && '' !== trim( wp_strip_all_tags( $html ) );
}

/** Registration, assets and wiring that the block and shortcode depend on. */
function zmai_fe_registration() {
	$block_path = zmai_fe_path( 'blocks/avatar-upload/block.json' );
	$meta       = is_file( $block_path ) ? json_decode( (string) file_get_contents( $block_path ), true ) : null;
	zmai_fe_expect( is_array( $meta ), 'blocks/avatar-upload/block.json exists and parses' );
	$meta = is_array( $meta ) ? $meta : array();
	zmai_fe_expect( 3 === ( $meta['apiVersion'] ?? null ), 'block.json declares apiVersion 3' );
	zmai_fe_expect( isset( $meta['name'] ) && false !== strpos( (string) $meta['name'], '/' ), 'block.json name is namespaced' );
	zmai_fe_expect( false === ( $meta['supports']['html'] ?? true ), 'block.json disables supports.html' );
	zmai_fe_expect( ! empty( $meta['textdomain'] ), 'block.json declares a textdomain' );
	zmai_fe_expect( 'zmai-avatar-upload-editor' === ( $meta['editorScript'] ?? null ), 'block.json editorScript is the registered editor handle' );
	zmai_fe_expect( is_file( zmai_fe_path( 'assets/js/avatar-upload-editor.js' ) ), 'editor source exists at assets/js/avatar-upload-editor.js' );
	zmai_fe_expect( is_file( zmai_fe_path( 'assets/css/front-end.css' ) ), 'front-end stylesheet exists at assets/css/front-end.css' );
	zmai_fe_expect( wp_style_is( 'zmai-front-end', 'registered' ), 'zmai-front-end style is registered' );
	zmai_fe_expect( wp_script_is( 'zmai-avatar-upload-editor', 'registered' ), 'zmai-avatar-upload-editor script is registered' );
	$deps = wp_scripts()->registered['zmai-avatar-upload-editor']->deps ?? array();
	foreach ( array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n' ) as $dependency ) {
		zmai_fe_expect( in_array( $dependency, (array) $deps, true ), 'editor script depends on ' . $dependency );
	}
	zmai_fe_expect( null !== zmai_fe_block_name(), 'a block is registered with Front_End::render_form as its render callback' );
	zmai_fe_expect( null !== zmai_fe_shortcode_tag(), 'a shortcode is registered with Front_End::render_form as its callback' );
	zmai_fe_expect( ! has_action( 'admin_post_nopriv_zmai_save_avatar' ) && ! has_action( 'admin_post_nopriv_zmai_remove_avatar' ), 'no nopriv admin-post write hooks exist' );
}

/** Guest, allowed, denied, blocked and callback-attribute renders. */
function zmai_fe_render_contract() {
	global $zmai_fe_store;
	$store = $zmai_fe_store;
	// The removal form renders only for a user who has an avatar, so the form checks need one.
	list( $user ) = zmai_fe_avatar_user( 'render' );
	$other        = zmai_fe_user( 'subscriber', 'render-other' );

	wp_set_current_user( 0 );
	$guest = zmai_fe_render( array() );
	zmai_fe_expect( is_string( $guest ) && false !== strpos( $guest, 'wp-login.php' ), 'guest sees a login link instead of the form' );
	zmai_fe_expect( is_string( $guest ) && false === strpos( $guest, 'type="file"' ) && false === strpos( $guest, '_wpnonce' ), 'guest markup has no file input and no nonce' );

	zmai_fe_legacy_policy();
	wp_set_current_user( $user );
	zmai_fe_expect( ( new Upload_Policy( $store ) )->can_upload( $user ), 'precondition: subscriber may upload under the legacy policy' );
	$allowed     = zmai_fe_render( array() );
	$allowed_doc = zmai_fe_dom( $allowed );
	zmai_fe_expect( is_string( $allowed ) && 1 === zmai_fe_count( $allowed_doc, '//input[@type="file"]' ), 'allowed subscriber gets exactly one file input' );
	zmai_fe_expect( zmai_fe_forms_unnested( $allowed ) && 2 === zmai_fe_count( $allowed_doc, '//form[@method="post" and contains(@action,"admin-post.php")]' ), 'upload and removal are two unnested POST forms to admin-post.php' );
	zmai_fe_expect( 1 === zmai_fe_count( $allowed_doc, '//form[@enctype="multipart/form-data"]//input[@name="action" and @value="zmai_save_avatar"]' ), 'the multipart form is the zmai_save_avatar form' );
	zmai_fe_expect( 0 === zmai_fe_count( $allowed_doc, '//form[.//input[@name="action" and @value="zmai_remove_avatar"]]//input[@type="file"]' ), 'the removal form has no file input' );
	zmai_fe_expect( 1 === zmai_fe_count( $allowed_doc, '//form[.//input[@name="action" and @value="zmai_save_avatar"]]//input[@name="zmai_context" and @value="front"]' ) && 1 === zmai_fe_count( $allowed_doc, '//form[.//input[@name="action" and @value="zmai_remove_avatar"]]//input[@name="zmai_context" and @value="front"]' ), 'both forms post zmai_context=front' );
	zmai_fe_expect( 1 === zmai_fe_count( $allowed_doc, '//form[.//input[@name="action" and @value="zmai_save_avatar"]]//input[@name="avatar_type" and @value="user"]' ), 'the save form posts avatar_type=user' );

	zmai_fe_deny_all_policy();
	$denied_policy = ( new Upload_Policy( $store ) )->get_policy();
	zmai_fe_expect( 'roles' === ( $denied_policy['mode'] ?? '' ), 'precondition: the deny fixture is a valid roles policy' );
	zmai_fe_expect( ! ( new Upload_Policy( $store ) )->can_upload( $user ), 'precondition: an empty role list denies the subscriber' );
	$denied = zmai_fe_render( array() );
	zmai_fe_expect( is_string( $denied ) && false === strpos( $denied, 'type="file"' ), 'denied subscriber gets no file input' );
	zmai_fe_expect( zmai_fe_has_text( $denied ), 'denied subscriber gets a visible reason' );
	zmai_fe_legacy_policy();

	$store->set_blocked( $user, true );
	zmai_fe_expect( $store->is_blocked( $user ), 'precondition: subscriber is blocked' );
	$blocked = zmai_fe_render( array() );
	zmai_fe_expect( is_string( $blocked ) && false === strpos( $blocked, 'type="file"' ), 'blocked subscriber gets no file input' );
	zmai_fe_expect( zmai_fe_has_text( $blocked ), 'blocked subscriber gets a visible reason' );
	$store->set_blocked( $user, false );

	wp_set_current_user( $user );
	$plain   = zmai_fe_render( array() );
	$hostile = zmai_fe_render(
		array(
			'user_id'     => $other,
			'src'         => 'https://evil.example/a.jpg',
			'avatar_type' => 'default',
			'default'     => 1,
		)
	);
	zmai_fe_expect( zmai_fe_same( $plain, $hostile ), 'callback attributes are ignored: render matches the no-attribute render' );
	zmai_fe_expect( is_string( $hostile ) && false === strpos( $hostile, 'evil.example' ), 'callback attribute values never reach the markup' );
}

/** Shortcode and block produce the same markup. */
function zmai_fe_parity() {
	$user = zmai_fe_user( 'subscriber', 'parity' );
	wp_set_current_user( $user );
	zmai_fe_legacy_policy();
	$tag   = zmai_fe_shortcode_tag();
	$block = zmai_fe_block_name();
	if ( null === $tag || null === $block ) {
		zmai_fe_expect( false, 'shortcode/block parity cannot run until both are registered' );
		return;
	}
	$shortcode  = do_shortcode( '[' . $tag . ']' );
	$block_html = render_block(
		array(
			'blockName'    => $block,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
	zmai_fe_expect( is_string( $shortcode ) && false !== strpos( $shortcode, 'type="file"' ), 'shortcode renders the upload form for an allowed subscriber' );
	zmai_fe_expect( zmai_fe_same( $shortcode, $block_html ), 'shortcode and dynamic block produce identical markup after ID and nonce normalization' );
}

/** Render paths issue no write queries. */
function zmai_fe_no_writes() {
	global $zmai_fe_writes;
	$user = zmai_fe_user( 'subscriber', 'no-writes' );
	zmai_fe_legacy_policy();
	wp_set_current_user( $user );
	$tag   = zmai_fe_shortcode_tag();
	$block = zmai_fe_block_name();

	$zmai_fe_writes = array();
	add_filter( 'query', 'zmai_fe_write_probe' );
	$rendered   = array();
	$rendered[] = zmai_fe_render( array() );
	$_GET       = array( 'zmai_status' => 'uploaded' );
	$rendered[] = zmai_fe_render( array() );
	zmai_fe_reset_request();
	wp_set_current_user( 0 );
	$rendered[] = zmai_fe_render( array() );
	if ( null !== $tag ) {
		$rendered[] = do_shortcode( '[' . $tag . ']' );
	}
	if ( null !== $block ) {
		$rendered[] = render_block(
			array(
				'blockName'    => $block,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
	remove_filter( 'query', 'zmai_fe_write_probe' );

	$all_rendered = true;
	foreach ( $rendered as $html ) {
		$all_rendered = $all_rendered && is_string( $html ) && '' !== $html;
	}
	zmai_fe_expect( $all_rendered && 0 === count( $zmai_fe_writes ), 'guest, subscriber, notice, shortcode and block renders issue no write queries' );
	foreach ( $zmai_fe_writes as $sql ) {
		echo 'INFO write during render: ' . substr( preg_replace( '/\s+/', ' ', $sql ), 0, 160 ) . "\n";
	}
}

/** Allowlisted notices, hostile status values and escaping. */
function zmai_fe_notices() {
	global $zmai_fe_store;
	$codes    = array( 'uploaded', 'removed', 'denied', 'rate-limited', 'upload-error' );
	$messages = array();
	foreach ( $codes as $code ) {
		$message = zmai_fe_front_call( 'status_message', array( $code ) );
		zmai_fe_expect( is_string( $message ) && '' !== $message && false === strpos( $message, '<' ), 'status_message(' . $code . ') returns a fixed plain-text notice' );
		$messages[ $code ] = is_string( $message ) ? $message : null;
	}
	zmai_fe_expect( 5 === count( array_unique( $messages ) ) && ! in_array( null, $messages, true ), 'each allowlisted status has its own message' );

	zmai_fe_expect( '' === zmai_fe_front_call( 'status_message', array( 'bogus-code' ) ), 'unknown status returns an empty notice' );
	zmai_fe_expect( '' === zmai_fe_front_call( 'status_message', array( array( 'uploaded' ) ) ), 'array status returns an empty notice' );
	zmai_fe_expect( '' === zmai_fe_front_call( 'status_message', array( '<script>alert(1)</script>' ) ), 'markup status returns an empty notice' );

	$user = zmai_fe_user( 'subscriber', 'notice' );
	zmai_fe_legacy_policy();
	wp_set_current_user( $user );
	$_GET    = array(
		'zmai_status' => '<img src=x onerror=alert(1)>',
		'zmai_error'  => 'zmai-secret-marker@example.com /var/www/secret',
	);
	$hostile = zmai_fe_render( array() );
	zmai_fe_expect( is_string( $hostile ) && false === strpos( $hostile, '<img' ) && false === strpos( $hostile, 'onerror=alert' ), 'hostile zmai_status is never reflected as markup' );
	zmai_fe_expect( is_string( $hostile ) && false === strpos( $hostile, 'zmai-secret-marker' ) && false === strpos( $hostile, '/var/www/secret' ), 'raw zmai_error text is never rendered' );

	$_GET        = array( 'zmai_status' => 'denied' );
	$denied_msg  = zmai_fe_front_call( 'status_message', array( 'denied' ) );
	$expected    = is_string( $denied_msg ) ? esc_html( $denied_msg ) : '';
	$denied_html = zmai_fe_render( array() );
	zmai_fe_expect( '' !== $expected && is_string( $denied_html ) && false !== strpos( $denied_html, $expected ), 'allowlisted denied status renders its escaped fixed message' );
	zmai_fe_reset_request();
}

/** Two instances on one page keep IDs, labels and live regions unique. */
function zmai_fe_instances() {
	$user = zmai_fe_user( 'subscriber', 'instances' );
	zmai_fe_legacy_policy();
	wp_set_current_user( $user );
	$first  = zmai_fe_render( array() );
	$second = zmai_fe_render( array() );
	if ( ! is_string( $first ) || ! is_string( $second ) ) {
		zmai_fe_expect( false, 'two instances render as strings' );
		return;
	}
	$doc     = zmai_fe_dom( $first . $second );
	$ids     = zmai_fe_attr_values( $doc, '//*[@id]', 'id' );
	$targets = zmai_fe_attr_values( $doc, '//label[@for]', 'for' );
	preg_match_all( '/\sid="([^"]*)"/', $first . $second, $raw_ids );
	$raw_ids = $raw_ids[1];
	echo 'INFO element ids: ' . implode( ' | ', $raw_ids ) . "\n";
	zmai_fe_expect( count( $raw_ids ) > 0 && count( $raw_ids ) === count( array_unique( $raw_ids ) ), 'two instances on one page share no element id' );
	zmai_fe_expect( count( $targets ) >= 2 && count( $targets ) === count( array_unique( $targets ) ), 'each instance has its own label targets' );

	$resolvable = count( $targets ) > 0;
	foreach ( $targets as $target ) {
		$resolvable = $resolvable && in_array( $target, $ids, true );
	}
	zmai_fe_expect( $resolvable, 'every label points at an element that exists in the markup' );

	$file_ids = zmai_fe_attr_values( $doc, '//input[@type="file"]', 'id' );
	$labelled = 2 === count( $file_ids );
	foreach ( $file_ids as $file_id ) {
		$labelled = $labelled && '' !== $file_id && in_array( $file_id, $targets, true );
	}
	zmai_fe_expect( $labelled, 'each of the two file inputs has its own visible label' );
	zmai_fe_expect( zmai_fe_count( zmai_fe_dom( $first ), '//*[@aria-live]' ) >= 1 && zmai_fe_count( zmai_fe_dom( $second ), '//*[@aria-live]' ) >= 1, 'each instance has an aria-live status region' );
}

/** Return URL rejection matrix, the allowed case, and the subdirectory home. */
function zmai_fe_return_urls() {
	$home   = home_url( '/' );
	$page   = home_url( '/zmai-return/' );
	$parts  = wp_parse_url( $home );
	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
	$host   = isset( $parts['host'] ) ? $parts['host'] : '';
	$other  = 'https' === $scheme ? 'http' : 'https';
	$origin = $scheme . '://' . $host . ( ! empty( $parts['port'] ) ? ':' . $parts['port'] : '' );

	zmai_fe_expect( zmai_fe_front_call( 'safe_return_url', array( $page ) ) === $page, 'same-origin page under the home path is accepted unchanged' );
	$with_params = add_query_arg(
		array(
			'zmai_status' => 'uploaded',
			'zmai_error'  => 'x',
			'keep'        => '1',
		),
		$page
	);
	$stripped    = zmai_fe_front_call( 'safe_return_url', array( $with_params ) );
	zmai_fe_expect( is_string( $stripped ) && false !== strpos( $stripped, 'keep=1' ) && false === strpos( $stripped, 'zmai_status' ) && false === strpos( $stripped, 'zmai_error' ), 'zmai_status and zmai_error are removed while other query args survive' );

	$rejects = array(
		'foreign https host'            => 'https://evil.example/zmai-return/',
		'protocol-relative'             => '//evil.example/zmai-return/',
		'scheme swap'                   => $other . '://' . $host . '/zmai-return/',
		'non-default port'              => $scheme . '://' . $host . ':8443/zmai-return/',
		'embedded credentials'          => $scheme . '://user:pass@' . $host . '/zmai-return/',
		'host confusion with @'         => $scheme . '://' . $host . '@evil.example/zmai-return/',
		'backslash host'                => '/\\evil.example/zmai-return/',
		'raw CRLF'                      => $page . "\r\nSet-Cookie: zmai=1",
		'encoded CRLF'                  => $page . '%0d%0aSet-Cookie:%20zmai=1',
		'NUL byte'                      => $page . "\0",
		'tab control byte'              => $page . "\t",
		'leading whitespace to foreign' => ' https://evil.example/',
		'javascript scheme'             => 'javascript:alert(1)',
		'data scheme'                   => 'data:text/html,<script>alert(1)</script>',
		'empty string'                  => '',
		'encoded traversal to wp-admin' => home_url( '/%2e%2e/wp-admin/' ),
		'traversal after the prefix'    => home_url( '/zmai-return/..%2fwp-admin/' ),
		'admin users screen'            => admin_url( 'users.php' ),
		'admin-post action'             => admin_url( 'admin-post.php?action=zmai_remove_avatar' ),
		'login page'                    => wp_login_url(),
		'logout destination'            => wp_logout_url(),
	);
	foreach ( $rejects as $label => $url ) {
		zmai_fe_expect( zmai_fe_front_call( 'safe_return_url', array( $url ) ) === $home, 'rejects ' . $label . ' and falls back to home' );
	}

	add_filter( 'allowed_redirect_hosts', 'zmai_fe_expand_hosts' );
	$expanded = zmai_fe_front_call( 'safe_return_url', array( 'https://evil.example/zmai-return/' ) );
	remove_filter( 'allowed_redirect_hosts', 'zmai_fe_expand_hosts' );
	zmai_fe_expect( $home === $expanded, 'an allowed_redirect_hosts filter does not expand the return allowlist' );

	add_filter( 'option_home', 'zmai_fe_subdirectory_home' );
	$sub_home = home_url( '/' );
	$sub_ok   = zmai_fe_front_call( 'safe_return_url', array( 'https://zmai-sub.example/blog/post/' ) );
	zmai_fe_expect( 'https://zmai-sub.example/blog/post/' === $sub_ok, 'subdirectory home accepts a page under its path prefix' );
	$sub_rejects = array(
		'sibling directory sharing the prefix' => 'https://zmai-sub.example/blogroll/',
		'site root outside the prefix'         => 'https://zmai-sub.example/',
		'other path on the same host'          => 'https://zmai-sub.example/other/',
		'traversal out of the prefix'          => 'https://zmai-sub.example/blog/../secret/',
		'encoded traversal out of the prefix'  => 'https://zmai-sub.example/blog/%2e%2e/secret/',
		'other host with the same path'        => 'https://other.zmai-sub.example/blog/',
	);
	foreach ( $sub_rejects as $label => $url ) {
		zmai_fe_expect( zmai_fe_front_call( 'safe_return_url', array( $url ) ) === $sub_home, 'subdirectory home rejects ' . $label );
	}
	remove_filter( 'option_home', 'zmai_fe_subdirectory_home' );
	$encoded_home = static function () {
		return 'https://zmai-sub.example/site%20name';
	};
	add_filter( 'option_home', $encoded_home );
	$encoded_page = 'https://zmai-sub.example/site%20name/profile/';
	zmai_fe_expect( zmai_fe_front_call( 'safe_return_url', array( $encoded_page ) ) === $encoded_page, 'encoded home path preserves a valid return page' );
	zmai_fe_expect( home_url( '/' ) === zmai_fe_front_call( 'safe_return_url', array( 'https://zmai-sub.example/site%20name/%2e%2e/wp-admin/' ) ), 'encoded home path still rejects traversal' );
	remove_filter( 'option_home', $encoded_home );
}

/** Filter callback: add a host to the redirect allowlist. */
function zmai_fe_expand_hosts( $hosts ) {
	$hosts[] = 'evil.example';
	return $hosts;
}

/** Filter callback: home is a subdirectory of a different host. */
function zmai_fe_subdirectory_home() {
	return 'https://zmai-sub.example/blog';
}

/** Existing admin-post handlers refuse GET, malformed fields and front-only misuse before mutating. */
function zmai_fe_admin_handlers() {
	global $zmai_fe_store, $zmai_fe_admin;
	$store = $zmai_fe_store;
	$admin = $zmai_fe_admin;
	zmai_fe_legacy_policy();

	list( $get_user, $get_att ) = zmai_fe_avatar_user( 'get-remove' );
	wp_set_current_user( $get_user );
	zmai_fe_expect( zmai_fe_avatar_of( $get_user ) === $get_att, 'precondition: subscriber owns the fixture avatar' );
	zmai_fe_request(
		'GET',
		array(),
		array(
			'_wpnonce'    => wp_create_nonce( 'zmai_remove_avatar' ),
			'avatar_type' => 'user',
		)
	);
	$get_remove = zmai_fe_call( array( $admin, 'remove_avatar' ) );
	zmai_fe_info( 'GET remove_avatar', $get_remove );
	zmai_fe_expect( zmai_fe_died_with( $get_remove, 405 ), 'GET remove_avatar is refused with 405' );
	zmai_fe_expect( zmai_fe_avatar_of( $get_user ) === $get_att, 'GET remove_avatar leaves the avatar assigned' );

	list( $get_save_user, $get_save_att ) = zmai_fe_avatar_user( 'get-save' );
	wp_set_current_user( $get_save_user );
	zmai_fe_request(
		'GET',
		array(),
		array(
			'_wpnonce'    => wp_create_nonce( 'zmai_save_avatar' ),
			'avatar_type' => 'user',
		)
	);
	$get_save = zmai_fe_call( array( $admin, 'save_avatar' ) );
	zmai_fe_info( 'GET save_avatar', $get_save );
	zmai_fe_expect( zmai_fe_died_with( $get_save, 405 ), 'GET save_avatar is refused with 405' );
	zmai_fe_expect( zmai_fe_avatar_of( $get_save_user ) === $get_save_att, 'GET save_avatar leaves the existing avatar untouched' );

	list( $bad_user, $bad_att ) = zmai_fe_avatar_user( 'malformed' );
	wp_set_current_user( $bad_user );
	$save_nonce = wp_create_nonce( 'zmai_save_avatar' );
	$save_cases = array(
		'avatar_type array'   => array( 'avatar_type' => array( 'user' ) ),
		'avatar_type unknown' => array( 'avatar_type' => 'bogus' ),
		'context unknown'     => array(
			'avatar_type'  => 'user',
			'zmai_context' => 'bogus',
		),
		'context array'       => array(
			'avatar_type'  => 'user',
			'zmai_context' => array( 'front' ),
		),
		'context case drift'  => array(
			'avatar_type'  => 'user',
			'zmai_context' => 'FRONT',
		),
	);
	foreach ( $save_cases as $label => $fields ) {
		zmai_fe_request( 'POST', array_merge( array( '_wpnonce' => $save_nonce ), $fields ) );
		$outcome = zmai_fe_call( array( $admin, 'save_avatar' ) );
		zmai_fe_info( 'save_avatar ' . $label, $outcome );
		zmai_fe_expect( zmai_fe_is_rejected( $outcome ), 'save_avatar rejects malformed ' . $label );
		zmai_fe_expect( zmai_fe_avatar_of( $bad_user ) === $bad_att, 'save_avatar keeps the avatar after malformed ' . $label );
	}

	$remove_nonce = wp_create_nonce( 'zmai_remove_avatar' );
	zmai_fe_request(
		'POST',
		array(
			'_wpnonce'    => $remove_nonce,
			'avatar_type' => array( 'user' ),
		)
	);
	$remove_array = zmai_fe_call( array( $admin, 'remove_avatar' ) );
	zmai_fe_info( 'remove_avatar avatar_type array', $remove_array );
	zmai_fe_expect( zmai_fe_is_rejected( $remove_array ), 'remove_avatar rejects malformed avatar_type array' );
	zmai_fe_expect( zmai_fe_avatar_of( $bad_user ) === $bad_att, 'remove_avatar keeps the avatar after malformed avatar_type array' );

	$admin_user = zmai_fe_user( 'administrator', 'front-default' );
	wp_set_current_user( $admin_user );
	$default_before = get_option( Image_Store::DEFAULT_OPTION );
	zmai_fe_request(
		'POST',
		array(
			'_wpnonce'     => wp_create_nonce( 'zmai_save_avatar' ),
			'avatar_type'  => 'default',
			'zmai_context' => 'front',
		)
	);
	$front_default = zmai_fe_call( array( $admin, 'save_avatar' ) );
	zmai_fe_info( 'front-context default upload by administrator', $front_default );
	zmai_fe_expect( zmai_fe_is_rejected( $front_default ), 'front context refuses a default-avatar upload even for an administrator' );
	zmai_fe_expect( get_option( Image_Store::DEFAULT_OPTION ) === $default_before, 'front context leaves the default avatar option unchanged' );

	list( $own_user, $own_att )       = zmai_fe_avatar_user( 'forge-own' );
	list( $victim_user, $victim_att ) = zmai_fe_avatar_user( 'forge-victim' );
	wp_set_current_user( $own_user );
	zmai_fe_request(
		'POST',
		array(
			'_wpnonce'    => wp_create_nonce( 'zmai_remove_avatar' ),
			'avatar_type' => 'user',
			'user_id'     => $victim_user,
		)
	);
	$forged = zmai_fe_call( array( $admin, 'remove_avatar' ) );
	zmai_fe_info( 'remove_avatar with posted user_id', $forged );
	zmai_fe_expect( zmai_fe_avatar_of( $victim_user ) === $victim_att, 'a posted user_id never selects another user\'s avatar to remove' );
	zmai_fe_expect( 0 === zmai_fe_avatar_of( $own_user ), 'the posted user_id is ignored: the signed-in user\'s own avatar is removed' );

	list( $control_user, $control_att ) = zmai_fe_avatar_user( 'control' );
	wp_set_current_user( $control_user );
	zmai_fe_request(
		'POST',
		array(
			'_wpnonce'    => wp_create_nonce( 'zmai_remove_avatar' ),
			'avatar_type' => 'user',
		)
	);
	$control = zmai_fe_call( array( $admin, 'remove_avatar' ) );
	zmai_fe_info( 'control POST remove_avatar', $control );
	zmai_fe_expect( 'redirect' === $control['kind'] && false !== strpos( $control['location'], 'zmai_status=removed' ), 'control: a valid POST removal redirects with zmai_status=removed' );

	zmai_fe_deny_all_policy();
	$denied_user = zmai_fe_user( 'subscriber', 'front-denied' );
	wp_set_current_user( $denied_user );
	zmai_fe_expect( ! ( new Upload_Policy( $store ) )->can_upload( $denied_user ), 'precondition: the deny fixture refuses the front-denied subscriber' );
	zmai_fe_request(
		'POST',
		array(
			'_wpnonce'     => wp_create_nonce( 'zmai_save_avatar' ),
			'avatar_type'  => 'user',
			'zmai_context' => 'front',
		)
	);
	$denied = zmai_fe_call( array( $admin, 'save_avatar' ) );
	zmai_fe_info( 'front-context policy denial', $denied );
	$location = 'redirect' === $denied['kind'] ? $denied['location'] : '';
	zmai_fe_expect( 'redirect' === $denied['kind'] && 303 === $denied['status'] && false !== strpos( $location, 'zmai_status=denied' ), 'front policy denial redirects 303 with fixed zmai_status=denied' );
	zmai_fe_expect( '' !== $location && false === strpos( $location, 'zmai_error' ) && wp_parse_url( $location, PHP_URL_HOST ) === wp_parse_url( home_url( '/' ), PHP_URL_HOST ), 'front denial redirect carries no raw error and stays on the home host' );

	list( $policy_user, $policy_att ) = zmai_fe_avatar_user( 'policy-remove' );
	wp_set_current_user( $policy_user );
	zmai_fe_request(
		'POST',
		array(
			'_wpnonce'     => wp_create_nonce( 'zmai_remove_avatar' ),
			'avatar_type'  => 'user',
			'zmai_context' => 'front',
		)
	);
	$policy_remove = zmai_fe_call( array( $admin, 'remove_avatar' ) );
	zmai_fe_info( 'front-context removal by policy-denied user', $policy_remove );
	$policy_location = 'redirect' === $policy_remove['kind'] ? $policy_remove['location'] : '';
	zmai_fe_expect( 'redirect' === $policy_remove['kind'] && 303 === $policy_remove['status'] && false !== strpos( $policy_location, 'zmai_status=removed' ), 'front removal still works for a policy-denied user (303, zmai_status=removed)' );
	zmai_fe_expect( 0 === zmai_fe_avatar_of( $policy_user ), 'front removal deletes the policy-denied user\'s own avatar' );

	zmai_fe_legacy_policy();
}

/** Remove fixtures and restore the upload policy option. */
function zmai_fe_cleanup() {
	global $zmai_fe_users, $zmai_fe_attachments, $zmai_fe_policy_before;
	foreach ( $zmai_fe_attachments as $attachment ) {
		wp_delete_attachment( $attachment, true );
	}
	wp_set_current_user( 0 );
	foreach ( $zmai_fe_users as $user ) {
		wp_delete_user( $user );
	}
	if ( null === $zmai_fe_policy_before ) {
		zmai_fe_legacy_policy();
	} else {
		update_option( Upload_Policy::OPTION, $zmai_fe_policy_before, false );
	}
	zmai_fe_reset_request();
}

/** A shortcode rendered after the head must include its stylesheet only once. */
function zmai_fe_late_styles() {
	$user = zmai_fe_user( 'subscriber', 'late-style' );
	zmai_fe_legacy_policy();
	wp_set_current_user( $user );
	$server_name            = isset( $_SERVER['SERVER_NAME'] ) ? $_SERVER['SERVER_NAME'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve this CLI-only environment field verbatim for restoration; never output it.
	$_SERVER['SERVER_NAME'] = wp_parse_url( home_url(), PHP_URL_HOST );
	ob_start();
	do_action( 'wp_head' );
	ob_end_clean();
	if ( null === $server_name ) {
		unset( $_SERVER['SERVER_NAME'] );
	} else {
		$_SERVER['SERVER_NAME'] = $server_name;
	}
	$styles       = wp_styles();
	$styles->done = array_values( array_diff( $styles->done, array( 'zmai-front-end' ) ) );
	wp_dequeue_style( 'zmai-front-end' );
	$first  = zmai_fe_render();
	$second = zmai_fe_render();
	zmai_fe_expect( is_string( $first ) && false !== strpos( $first, 'assets/css/front-end.css' ) && false !== strpos( $first, '<link' ), 'late shortcode includes the actual registered stylesheet link' );
	zmai_fe_expect( is_string( $second ) && false === strpos( $second, 'assets/css/front-end.css' ), 'a second late shortcode does not duplicate the stylesheet link' );
}

zmai_fe_run( 'registration' );
zmai_fe_run( 'render_contract' );
zmai_fe_run( 'parity' );
zmai_fe_run( 'no_writes' );
zmai_fe_run( 'notices' );
zmai_fe_run( 'instances' );
zmai_fe_run( 'return_urls' );
zmai_fe_run( 'admin_handlers' );
zmai_fe_run( 'late_styles' );
zmai_fe_run( 'cleanup' );

global $zmai_fe_failures, $zmai_fe_checks;
echo "\nSUMMARY zmai front-end checks: " . $zmai_fe_checks . ' run, ' . count( $zmai_fe_failures ) . " failed\n";
foreach ( $zmai_fe_failures as $failure ) {
	echo 'FAILED ' . $failure . "\n";
}
if ( $zmai_fe_failures ) {
	throw new RuntimeException( 'Front-end contract checks failed: ' . count( $zmai_fe_failures ) );
}
