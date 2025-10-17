<?php
/**
 * PHPUnit bootstrap file for Author Image plugin tests
 */

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', '/tmp/wordpress/wp-content');
}

// Initialize global test variables
global $_test_options;
global $_test_upload_dir;
$_test_options = [];
$_test_upload_dir = sys_get_temp_dir() . '/test-uploads';

// Mock WordPress functions for unit testing
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        global $_test_upload_dir;
        return [
            'basedir' => $_test_upload_dir,
            'baseurl' => 'http://example.com/wp-content/uploads',
        ];
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $_test_options;
        return $_test_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        global $_test_options;
        $_test_options[$option] = $value;
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option($option, $value, $deprecated = '', $autoload = 'yes') {
        global $_test_options;
        if (!isset($_test_options[$option])) {
            $_test_options[$option] = $value;
            return true;
        }
        return false;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        if (file_exists($target)) {
            return @is_dir($target);
        }
        $target = str_replace('//', '/', $target);
        $target = rtrim($target, '/');
        if (empty($target)) {
            $target = '/';
        }
        if (@mkdir($target, 0777, true)) {
            return true;
        } elseif (is_dir($target)) {
            return true;
        }
        return false;
    }
}
