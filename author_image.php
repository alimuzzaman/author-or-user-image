<?php
/*
Plugin Name:WordPress Author Image
Plugin URI: https://alim.dev
Description: This plugin is for Author Image. You and your users will be able to set image. The image will be appears in comment and about author section. Ability to remove user image or block particular user.
Author: Alimuzzaman Alim
Version: 2.0.2
Author URI:  https://alim.dev
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('AUTHOR_IMAGE_VERSION', '2.0.2');
define('AUTHOR_IMAGE_PLUGIN_FILE', __FILE__);
define('AUTHOR_IMAGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AUTHOR_IMAGE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Composer autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Initialize the plugin
function author_image_init() {
    // Instantiate the main plugin class
    $plugin = new \AuthorImage\Plugin();
}
add_action('plugins_loaded', 'author_image_init');

// Override get_avatar function if it doesn't exist
if (!function_exists('get_avatar')) :
    function get_avatar($id_or_email = "1", $size = '96px', $default = '', $alt = 'Author Image') {
        if (is_numeric($id_or_email)) {
            $us = get_user_by('id', $id_or_email);
            $id = $us ? $us->user_login : '';
        } elseif (is_object($id_or_email)) {
            // No avatar for pingbacks or trackbacks
            $allowed_comment_types = apply_filters('get_avatar_comment_types', array('comment'));
            if (!empty($id_or_email->comment_type) && !in_array($id_or_email->comment_type, (array) $allowed_comment_types)) {
                return false;
            }

            if (!empty($id_or_email->user_id)) {
                $us = get_user_by('id', $id_or_email->user_id);
                $id = $us ? $us->user_login : '';
            } else {
                $email = $id_or_email->comment_author_email;
                $us = get_user_by('email', $email);
                $id = $us ? $us->user_login : '';
            }
        } else {
            $us = get_user_by('email', $id_or_email);
            $id = $us ? $us->user_login : '';
        }

        $dir = WP_CONTENT_DIR . '/uploads/author-image/';
        $ban = get_option("zm_ai_ban_id");
        $band = false;
        
        if (is_array($ban)) {
            $band = in_array($id, $ban);
        }

        if (file_exists($dir . $id . '.jpg') && !$band) {
            $op = get_option('siteurl') . '/wp-content/uploads/author-image/' . $id . '.jpg';
        } else {
            if (file_exists($dir . 'author_default.jpg')) {
                $op = get_option('siteurl') . '/wp-content/uploads/author-image/author_default.jpg';
            } else {
                $op = false;
            }
        }

        if ($op) {
            $avatar = "<img alt='{$alt}' title='Author Image' src='{$op}' class='avatar avatar-{$size} photo avatar-default' width='{$size}' />";
        } else {
            $avatar = "<img alt='{$alt}' title='Author Image' src='" . plugin_dir_url(__FILE__) . "default-user-image.png' class='avatar avatar-{$size} photo avatar-default' width='{$size}' />";
        }

        return apply_filters('get_avatar', $avatar, $id_or_email, $size, $default, $alt);
    }
endif;