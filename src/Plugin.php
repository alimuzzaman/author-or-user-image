<?php
namespace AuthorImage;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Plugin
{
    public const VERSION = '2.0.2';

    public function __construct()
    {
        // Hook into WordPress lifecycle
        add_action('init', [$this, 'init']);
    }

    public function init(): void
    {
        // Placeholder for initialization tasks (register post types, REST routes, etc.)
    }

    public function register_admin(): void
    {
        // Placeholder to register admin pages and enqueue assets
    }
}
