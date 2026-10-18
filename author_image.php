<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- The underscored entry filename is the existing WordPress.org plugin entry point.
/**
 * Plugin Name: Author or User Image
 * Description: Let registered users use a local image as their WordPress avatar.
 * Version: 3.0.1
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Alimuzzaman Alim
 * Author URI: https://alim.dev
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: author-or-user-image
 *
 * @package AuthorOrUserImage
 */

defined( 'ABSPATH' ) || exit;

define( 'ZMAI_VERSION', '3.0.1' );
define( 'ZMAI_FILE', __FILE__ );
define( 'ZMAI_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZMAI_URL', plugin_dir_url( __FILE__ ) );

require_once ZMAI_PATH . 'includes/class-lock.php';
require_once ZMAI_PATH . 'includes/class-upload-policy.php';
require_once ZMAI_PATH . 'includes/class-image-processor.php';
require_once ZMAI_PATH . 'includes/class-image-store.php';
require_once ZMAI_PATH . 'includes/class-migration.php';
require_once ZMAI_PATH . 'includes/class-user-cleanup.php';
require_once ZMAI_PATH . 'includes/class-privacy.php';
require_once ZMAI_PATH . 'includes/class-admin.php';
require_once ZMAI_PATH . 'includes/class-plugin.php';

register_activation_hook( ZMAI_FILE, array( 'AuthorOrUserImage\\Plugin', 'activate' ) );

AuthorOrUserImage\Plugin::instance();
