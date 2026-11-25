<?php
/**
 * Runtime disabled-functions and strict E_ALL PHP compatibility worker.
 *
 * Compatible with PHP 7.4-8.6 and runnable directly via CLI without WP-CLI
 * so real `-d disable_functions=...` configurations are exercised cleanly.
 *
 * Usage:
 *   php -d disable_functions=... tests/disabled-functions-worker.php --isolated
 *   php -d disable_functions=... tests/disabled-functions-worker.php --wp-root=/path --profile=host-baseline
 *   php -d disable_functions=... tests/disabled-functions-worker.php --wp-root=/path --profile=hardened-overlay [--symlink=/tmp/link]
 *
 * @package AuthorOrUserImage
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

error_reporting( E_ALL );

$zmai_repo_root     = realpath( dirname( __DIR__ ) );
$zmai_plugin_errors = array();

set_error_handler(
	static function ( $errno, $errstr, $errfile, $errline ) use ( $zmai_repo_root, &$zmai_plugin_errors ) {
		// Respect intentional @-suppression on both PHP 7.4 and PHP 8.x.
		if ( 0 === ( error_reporting() & $errno ) ) {
			return false;
		}
		$real = is_string( $errfile ) ? realpath( $errfile ) : false;
		if ( false !== $real && false !== $zmai_repo_root ) {
			$in_includes = 0 === strpos( $real, $zmai_repo_root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR );
			$in_root     = dirname( $real ) === $zmai_repo_root && in_array( basename( $real ), array( 'author_image.php', 'index.php', 'uninstall.php' ), true );
			if ( $in_includes || $in_root ) {
				$zmai_plugin_errors[] = sprintf( '[errno=%d] %s in %s:%d', $errno, $errstr, $real, $errline );
			}
		}
		return false;
	}
);

$isolated     = false;
$wp_root      = '';
$profile      = 'host-baseline';
$symlink_path = '';

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--isolated' === $arg ) {
		$isolated = true;
	} elseif ( 0 === strpos( $arg, '--wp-root=' ) ) {
		$wp_root = substr( $arg, strlen( '--wp-root=' ) );
	} elseif ( 0 === strpos( $arg, '--profile=' ) ) {
		$profile = substr( $arg, strlen( '--profile=' ) );
	} elseif ( 0 === strpos( $arg, '--symlink=' ) ) {
		$symlink_path = substr( $arg, strlen( '--symlink=' ) );
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

$checks = 0;
$failed = 0;
$expect = static function ( $ok, $label ) use ( &$checks, &$failed ) {
	++$checks;
	if ( ! $ok ) {
		++$failed;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
};
$invoke = static function ( $target, $method, array $args = array() ) {
	$ref = new ReflectionMethod( $target, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}
	return $ref->invokeArgs( is_object( $target ) ? $target : null, $args );
};

if ( $isolated ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
	define( 'ZMAI_VERSION', '3.2.0' );
	define( 'ZMAI_PATH', $zmai_repo_root . '/' );
	define( 'ZMAI_URL', 'http://127.0.0.1/wp-content/plugins/author-or-user-image/' );

	/** Minimal WordPress stubs for isolated ini_get/scandir/lstat/error_log/mb_substr checks. */
	class WP_Error {
		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/** @var mixed */
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
	}
	class WP_REST_Request {
		public function get_body_params() {
			return array();
		}
		public function get_header( $name ) {
			return '';
		}
	}
	function __( $text, $domain = 'default' ) {
		return (string) $text;
	}
	function current_user_can( $cap ) {
		return true;
	}
	function sanitize_text_field( $text ) {
		return is_scalar( $text ) ? trim( strip_tags( (string) $text ) ) : '';
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function wp_convert_hr_to_bytes( $value ) {
		$value = trim( (string) $value );
		return is_numeric( $value ) ? (int) $value : 0;
	}
	function wp_delete_file( $file ) {
		@unlink( $file );
	}
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
	function wp_parse_args( $args, $defaults = array() ) {
		return is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;
	}
	function absint( $value ) {
		return abs( (int) $value );
	}

	require_once $zmai_repo_root . '/includes/class-image-processor.php';
	require_once $zmai_repo_root . '/includes/class-admin.php';
	require_once $zmai_repo_root . '/includes/class-rest-api.php';
	require_once $zmai_repo_root . '/includes/class-front-end.php';

	foreach ( array( 'ini_get', 'ini_set', 'glob', 'chmod', 'scandir', 'error_log', 'lstat', 'exif_read_data', 'mb_substr', 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open' ) as $disabled_fn ) {
		$expect( ! function_exists( $disabled_fn ), 'isolated disable_functions disables ' . $disabled_fn );
	}

	$tmp_dir = sys_get_temp_dir() . '/zmai-iso-' . str_replace( '.', '-', uniqid( '', true ) );
	mkdir( $tmp_dir, 0700, true );
	$tmp_file = $tmp_dir . '/probe.txt';
	file_put_contents( $tmp_file, 'ok' );
	try {
		$entries = AuthorOrUserImage\Image_Processor::list_directory( $tmp_dir );
		$expect( is_array( $entries ) && in_array( 'probe.txt', $entries, true ), 'Image_Processor::list_directory falls back to DirectoryIterator when scandir is disabled' );

		$expect(
			true === $invoke( 'AuthorOrUserImage\\Image_Processor', 'is_regular_file', array( $tmp_file ) )
			&& false === $invoke( 'AuthorOrUserImage\\Image_Processor', 'is_regular_file', array( $tmp_dir ) ),
			'Image_Processor::is_regular_file works accurately without lstat'
		);

		$ref_admin                 = new ReflectionClass( 'AuthorOrUserImage\\Admin' );
		$admin_obj                 = $ref_admin->newInstanceWithoutConstructor();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_LENGTH'] = '99999999';
		$_POST                     = array();
		$_FILES                    = array();
		$invoke( $admin_obj, 'reject_oversized_upload' );
		$expect( true, 'Admin::reject_oversized_upload completes without fatal when ini_get is disabled' );

		$ref_rest     = new ReflectionClass( 'AuthorOrUserImage\\REST_API' );
		$rest_obj     = $ref_rest->newInstanceWithoutConstructor();
		$res_oversize = $invoke( $rest_obj, 'is_oversized_post_body', array( new WP_REST_Request(), array() ) );
		$expect( false === $res_oversize, 'REST_API::is_oversized_post_body completes safely when ini_get is disabled' );

		$ref_front = new ReflectionClass( 'AuthorOrUserImage\\Front_End' );
		$front_obj = $ref_front->newInstanceWithoutConstructor();
		$attrs     = $invoke(
			$front_obj,
			'normalize_attributes',
			array(
				array(
					'upload_label' => str_repeat( 'A', 150 ),
				),
			)
		);
		$expect( 80 === strlen( $attrs['uploadLabel'] ), 'Front_End::normalize_attributes truncates cleanly when mb_substr is disabled' );
	} finally {
		if ( is_file( $tmp_file ) ) {
			unlink( $tmp_file );
		}
		if ( is_dir( $tmp_dir ) ) {
			rmdir( $tmp_dir );
		}
	}

	$expect( empty( $zmai_plugin_errors ), 'isolated mode triggered zero plugin warnings/notices/deprecations' );
	foreach ( $zmai_plugin_errors as $err ) {
		fwrite( STDERR, "PLUGIN_ERROR {$err}\n" );
	}
	echo "RESULT {$checks} checks, {$failed} failed\n";
	exit( $failed > 0 || ! empty( $zmai_plugin_errors ) ? 1 : 0 );
}

// WordPress-backed runtime tests (host-baseline or hardened-overlay).
if ( '' === $wp_root || ! is_file( rtrim( $wp_root, '/' ) . '/.zmai-test-instance' ) ) {
	fwrite( STDERR, "Disposable local WordPress installation (.zmai-test-instance) is required.\n" );
	exit( 2 );
}

$wp_root = rtrim( $wp_root, '/' );

// Load repository plugin candidate instead of any installed copy.
$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
	'function'      => static function ( $plugins ) {
		return array_values(
			array_filter(
				is_array( $plugins ) ? $plugins : array(),
				static function ( $file ) {
					return 'author_image.php' !== basename( $file );
				}
			)
		);
	},
	'accepted_args' => 1,
);
$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10][] = array(
	'function'      => static function ( $plugins ) {
		return array_filter(
			is_array( $plugins ) ? $plugins : array(),
			static function ( $file ) {
				return 'author_image.php' !== basename( $file );
			},
			ARRAY_FILTER_USE_KEY
		);
	},
	'accepted_args' => 1,
);
$GLOBALS['wp_filter']['plugins_loaded'][10][] = array(
	'function'      => static function () use ( $zmai_repo_root ) {
		require_once $zmai_repo_root . '/author_image.php';
	},
	'accepted_args' => 0,
);

$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_ADMIN', true );
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! class_exists( 'WP_Screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
}
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}

if ( 'local' !== wp_get_environment_type() ) {
	fwrite( STDERR, "WP_ENVIRONMENT_TYPE must be local.\n" );
	exit( 2 );
}

$expect( defined( 'ZMAI_PATH' ) && realpath( ZMAI_PATH ) === $zmai_repo_root, 'booted WordPress with repo Author or User Image under disable_functions (PHP ' . PHP_VERSION . ', profile=' . $profile . ')' );

$store   = new AuthorOrUserImage\Image_Store();
$policy  = new AuthorOrUserImage\Upload_Policy( $store );
$admin   = new AuthorOrUserImage\Admin( $store );
$prof    = new AuthorOrUserImage\Profile_Admin( $store );
$front   = new AuthorOrUserImage\Front_End( $store );
$rest    = new AuthorOrUserImage\REST_API( $store );
$mig     = new AuthorOrUserImage\Migration( $store );
$cleanup = new AuthorOrUserImage\User_Cleanup( $store );

$created_users = array();
$temp_dir      = sys_get_temp_dir() . '/zmai-df-' . str_replace( '.', '-', uniqid( '', true ) );
mkdir( $temp_dir, 0700, true );
$uploads     = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
$png_path    = $temp_dir . '/avatar.png';
$jpg_path    = $temp_dir . '/avatar.jpg';
$png_copy    = $uploads['path'] . '/zmai-df-import-' . wp_generate_uuid4() . '.png';
$jpg_copy    = $uploads['path'] . '/zmai-df-import-' . wp_generate_uuid4() . '.jpg';
$extra_files = array();

$im = imagecreatetruecolor( 96, 96 );
$bg = imagecolorallocate( $im, 40, 120, 180 );
imagefilledrectangle( $im, 0, 0, 95, 95, $bg );
imagepng( $im, $png_path );
imagejpeg( $im, $jpg_path, 90 );
if ( PHP_VERSION_ID < 80000 ) {
	imagedestroy( $im );
}
copy( $png_path, $png_copy );
copy( $jpg_path, $jpg_copy );

$prev_user_id = get_current_user_id();

try {
	foreach ( array( 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'symlink', 'phpinfo', 'putenv', 'mail', 'set_time_limit', 'curl_multi_exec' ) as $fn ) {
		$expect( ! function_exists( $fn ), 'runtime environment disables ' . $fn );
	}

	$sub_id = wp_insert_user(
		array(
			'user_login' => 'zmai-df-sub-' . wp_generate_uuid4(),
			'user_email' => wp_generate_uuid4() . '@example.invalid',
			'user_pass'  => 'public-local-test-fixture',
			'role'       => 'subscriber',
		)
	);
	$admin_id = wp_insert_user(
		array(
			'user_login' => 'zmai-df-adm-' . wp_generate_uuid4(),
			'user_email' => wp_generate_uuid4() . '@example.invalid',
			'user_pass'  => 'public-local-test-fixture',
			'role'       => 'administrator',
		)
	);
	if ( is_wp_error( $sub_id ) || ! $sub_id || is_wp_error( $admin_id ) || ! $admin_id ) {
		throw new RuntimeException( 'Failed creating disposable users.' );
	}
	$created_users[] = (int) $sub_id;
	$created_users[] = (int) $admin_id;
	$sub_obj         = get_userdata( $sub_id );

	if ( 'host-baseline' === $profile ) {
		$val_png = AuthorOrUserImage\Image_Processor::validate_local_image( $png_path, 'avatar.png' );
		$expect( is_array( $val_png ) && 'image/png' === ( $val_png['type'] ?? '' ), 'validate_local_image accepts PNG under host-baseline disable_functions' );

		$sq_png = AuthorOrUserImage\Image_Processor::create_square( $png_path, 64 );
		$expect( is_array( $sq_png ) && 64 === ( $sq_png['width'] ?? 0 ), 'create_square produces 64x64 PNG under host-baseline disable_functions' );
		if ( is_array( $sq_png ) && ! empty( $sq_png['path'] ) ) {
			$extra_files[] = $sq_png['path'];
		}

		$sq_jpg = AuthorOrUserImage\Image_Processor::create_square( $jpg_path, 64 );
		$expect( is_array( $sq_jpg ) && 'image/jpeg' === ( $sq_jpg['mime-type'] ?? '' ), 'create_square produces JPEG under host-baseline disable_functions' );
		if ( is_array( $sq_jpg ) && ! empty( $sq_jpg['path'] ) ) {
			$extra_files[] = $sq_jpg['path'];
		}

		$imp = $store->import_local_image(
			static function () use ( $png_copy ) {
				return array(
					'path' => $png_copy,
					'name' => 'avatar-import.png',
				);
			},
			$sub_id
		);
		$expect( is_array( $imp ) && 'imported' === ( $imp['status'] ?? '' ) && '' !== $store->get_url( $sub_id, 96, false ), 'Image_Store::import_local_image & get_url work under host-baseline disable_functions' );

		wp_set_current_user( $sub_id );
		$can_self = $policy->can_upload( $sub_id ) && $policy->can_upload_fresh( $sub_id );
		ob_start();
		$prof->render_profile_section( $sub_obj );
		$prof_html = (string) ob_get_clean();
		$sc_html   = $front->render_form( array( 'upload_label' => 'Choose Photo' ) );
		$expect( $can_self && false !== strpos( $prof_html, 'zmai-avatar-preview' ) && false !== strpos( $sc_html, 'Choose Photo' ), 'Profile_Admin & Front_End render cleanly under host-baseline disable_functions' );

		wp_set_current_user( $admin_id );
		$req = new WP_REST_Request( 'GET', '/author-or-user-image/v1/avatars/' . $sub_id );
		$res = rest_do_request( $req );
		$expect( 200 === $res->get_status() && true === ( $res->get_data()['has_local_avatar'] ?? false ), 'REST GET /avatars/{id} succeeds under host-baseline disable_functions' );

		$blk = $store->set_blocked( $sub_id, true );
		$unb = $store->set_blocked( $sub_id, false );
		$locks = $store->get_lock()->inspect_locks();
		$expect( ! is_wp_error( $blk ) && ! is_wp_error( $unb ) && is_array( $locks ), 'Block/unblock and inspect_locks succeed under host-baseline disable_functions' );

		$mig_status = $mig->import_status();
		$expect( ! is_wp_error( $mig_status ), 'Migration::import_status completes cleanly under host-baseline disable_functions' );
	} elseif ( 'hardened-overlay' === $profile ) {
		foreach ( array( 'scandir', 'error_log', 'lstat', 'exif_read_data' ) as $hfn ) {
			$expect( ! function_exists( $hfn ), 'hardened-overlay disables ' . $hfn );
		}

		$entries = AuthorOrUserImage\Image_Processor::list_directory( $temp_dir );
		$expect( is_array( $entries ) && in_array( 'avatar.png', $entries, true ), 'list_directory fallback lists temp_dir cleanly without scandir' );

		$sq_err = $invoke( 'AuthorOrUserImage\\Image_Processor', 'square_failure', array( $temp_dir . '/missing-stem.png', 'simulated failure' ) );
		$expect( is_wp_error( $sq_err ) && 'zmai_image_save_failed' === $sq_err->get_error_code(), 'Image_Processor::square_failure reconciles cleanly without scandir' );

		$val_dir = AuthorOrUserImage\Image_Processor::validate_local_image( $temp_dir, 'dir.png' );
		$expect( is_wp_error( $val_dir ) && 'zmai_invalid_image' === $val_dir->get_error_code(), 'validate_local_image rejects directory without lstat' );

		if ( '' !== $symlink_path ) {
			$val_sym = AuthorOrUserImage\Image_Processor::validate_local_image( $symlink_path, 'sym.png' );
			$expect( is_wp_error( $val_sym ) && 'zmai_invalid_image' === $val_sym->get_error_code(), 'validate_local_image rejects symlink without lstat' );
		}

		$sq_png = AuthorOrUserImage\Image_Processor::create_square( $png_path, 64 );
		$expect( is_array( $sq_png ) && 64 === ( $sq_png['width'] ?? 0 ), 'PNG validation & create_square pass cleanly without lstat/exif_read_data' );
		if ( is_array( $sq_png ) && ! empty( $sq_png['path'] ) ) {
			$extra_files[] = $sq_png['path'];
		}

		$sq_jpg = AuthorOrUserImage\Image_Processor::create_square( $jpg_path, 64 );
		$expect( is_wp_error( $sq_jpg ) && 'zmai_jpeg_exif_unavailable' === $sq_jpg->get_error_code(), 'JPEG create_square returns zmai_jpeg_exif_unavailable without exif_read_data' );

		$imp_jpg = $store->import_local_image(
			static function () use ( $jpg_copy ) {
				return array(
					'path' => $jpg_copy,
					'name' => 'avatar-import.jpg',
				);
			},
			$sub_id
		);
		$expect( is_wp_error( $imp_jpg ) && 'zmai_jpeg_exif_unavailable' === $imp_jpg->get_error_code(), 'Image_Store::import_local_image rejects JPEG safely without exif_read_data' );

		$imp_png = $store->import_local_image(
			static function () use ( $png_copy ) {
				return array(
					'path' => $png_copy,
					'name' => 'avatar-import.png',
				);
			},
			$sub_id
		);
		$expect( is_array( $imp_png ) && 'imported' === ( $imp_png['status'] ?? '' ), 'Image_Store::import_local_image imports PNG under full hardened-overlay' );

		$invoke( $mig, 'report_error', array( new WP_Error( 'zmai_test', 'test' ) ) );
		$attrs = $invoke( $front, 'normalize_attributes', array( array( 'upload_label' => str_repeat( 'B', 140 ) ) ) );
		$expect( 80 === strlen( $attrs['uploadLabel'] ), 'Migration::report_error & Front_End::normalize_attributes run without error_log or mb_substr' );

		$mig_sub_id = wp_insert_user(
			array(
				'user_login' => 'zmai-df-mig-' . wp_generate_uuid4(),
				'user_email' => wp_generate_uuid4() . '@example.invalid',
				'user_pass'  => 'public-local-test-fixture',
				'role'       => 'subscriber',
			)
		);
		if ( ! is_wp_error( $mig_sub_id ) && $mig_sub_id ) {
			$created_users[] = (int) $mig_sub_id;
			add_filter( 'zmai_strip_image_metadata', '__return_false' );
			try {
				$mig_ok = $invoke( $mig, 'sideload_legacy_image', array( $jpg_path, (int) $mig_sub_id ) );
				$expect( true === $mig_ok && $store->get_user_attachment_id( (int) $mig_sub_id ) > 0, 'Migration::sideload_legacy_image completes cleanly without scandir' );
			} finally {
				remove_filter( 'zmai_strip_image_metadata', '__return_false' );
			}
		}
	} else {
		throw new RuntimeException( 'Unsupported profile: ' . $profile );
	}
} finally {
	wp_set_current_user( $prev_user_id );
	foreach ( $created_users as $uid ) {
		$store->delete_user_avatar( $uid );
		wp_delete_user( $uid );
	}
	foreach ( array_merge( array( $png_path, $jpg_path, $png_copy, $jpg_copy ), $extra_files ) as $f ) {
		if ( is_string( $f ) && is_file( $f ) ) {
			unlink( $f );
		}
	}
	if ( is_dir( $temp_dir ) ) {
		rmdir( $temp_dir );
	}
}

$expect( empty( $zmai_plugin_errors ), 'runtime profile ' . $profile . ' triggered zero plugin warnings/notices/deprecations' );
foreach ( $zmai_plugin_errors as $err ) {
	fwrite( STDERR, "PLUGIN_ERROR {$err}\n" );
}

echo "RESULT {$checks} checks, {$failed} failed\n";
exit( $failed > 0 || ! empty( $zmai_plugin_errors ) ? 1 : 0 );
