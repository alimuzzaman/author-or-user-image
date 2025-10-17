<?php
namespace AuthorImage;

use AuthorImage\Service\FileManager;
use AuthorImage\Service\OptionStore;
use AuthorImage\Admin\Page;
use AuthorImage\Ajax\LegacyShim;
use AuthorImage\REST\Controller;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Plugin
{
    public const VERSION = '2.0.2';

    private FileManager $fileManager;
    private OptionStore $optionStore;
    private Page $adminPage;
    private LegacyShim $legacyShim;
    private Controller $restController;

    public function __construct()
    {
        // Initialize services
        $this->fileManager = new FileManager();
        $this->optionStore = new OptionStore();
        
        // Initialize admin pages
        $this->adminPage = new Page($this->fileManager, $this->optionStore);
        
        // Initialize legacy AJAX handlers
        $this->legacyShim = new LegacyShim($this->fileManager, $this->optionStore);
        
        // Initialize REST API controller
        $this->restController = new Controller($this->fileManager, $this->optionStore);
        
        // Hook into WordPress lifecycle
        add_action('init', [$this, 'init']);
    }

    public function init(): void
    {
        // REST routes are registered via rest_api_init hook in Controller
    }

    public function getFileManager(): FileManager
    {
        return $this->fileManager;
    }

    public function getOptionStore(): OptionStore
    {
        return $this->optionStore;
    }
}
