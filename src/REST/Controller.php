<?php

namespace AuthorImage\REST;

use AuthorImage\Service\FileManager;
use AuthorImage\Service\OptionStore;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * REST API Controller for Author Image plugin
 * Provides endpoints under /author-image/v1/
 */
class Controller
{
    private FileManager $fileManager;
    private OptionStore $optionStore;
    private string $namespace = 'author-image/v1';

    public function __construct(FileManager $fileManager, OptionStore $optionStore)
    {
        $this->fileManager = $fileManager;
        $this->optionStore = $optionStore;

        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register REST API routes
     */
    public function registerRoutes(): void
    {
        // List all author images
        register_rest_route($this->namespace, '/images', [
            'methods' => 'GET',
            'callback' => [$this, 'listImages'],
            'permission_callback' => [$this, 'checkAdminPermission'],
        ]);

        // Get a specific image
        register_rest_route($this->namespace, '/images/(?P<login>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getImage'],
            'permission_callback' => [$this, 'checkReadPermission'],
            'args' => [
                'login' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_user',
                ],
            ],
        ]);

        // Delete an image
        register_rest_route($this->namespace, '/images/(?P<login>[a-zA-Z0-9_-]+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'deleteImage'],
            'permission_callback' => [$this, 'checkAdminPermission'],
            'args' => [
                'login' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_user',
                ],
            ],
        ]);

        // List banned users
        register_rest_route($this->namespace, '/banned', [
            'methods' => 'GET',
            'callback' => [$this, 'listBanned'],
            'permission_callback' => [$this, 'checkAdminPermission'],
        ]);

        // Add user to ban list
        register_rest_route($this->namespace, '/banned', [
            'methods' => 'POST',
            'callback' => [$this, 'addToBanList'],
            'permission_callback' => [$this, 'checkAdminPermission'],
            'args' => [
                'login' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_user',
                ],
            ],
        ]);

        // Remove user from ban list
        register_rest_route($this->namespace, '/banned/(?P<login>[a-zA-Z0-9_-]+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'removeFromBanList'],
            'permission_callback' => [$this, 'checkAdminPermission'],
            'args' => [
                'login' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_user',
                ],
            ],
        ]);

        // Bulk operations
        register_rest_route($this->namespace, '/bulk', [
            'methods' => 'POST',
            'callback' => [$this, 'bulkAction'],
            'permission_callback' => [$this, 'checkAdminPermission'],
            'args' => [
                'action' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['delete', 'ban', 'unban'],
                ],
                'logins' => [
                    'required' => true,
                    'type' => 'array',
                ],
            ],
        ]);
    }

    /**
     * List all author images
     */
    public function listImages(WP_REST_Request $request): WP_REST_Response
    {
        $role = $request->get_param('role');
        $images = $this->fileManager->getAllImages();
        $banned = $this->optionStore->getBannedUsers();

        $result = [];

        foreach ($images as $login) {
            if ($login === '.' || $login === '..') {
                continue;
            }

            $user = get_user_by('login', $login);

            // Skip banned users and non-existent users
            if (!$user || in_array($login, $banned)) {
                continue;
            }

            // Filter by role if specified
            if ($role && $role !== 'all') {
                $roleFilter = $role === 'All' ? 'read' : $role;
                if (!user_can($user, $roleFilter)) {
                    continue;
                }
            }

            $result[] = [
                'login' => $login,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'image_url' => $this->fileManager->getImageUrl($login),
                'is_default' => $login === 'author_default',
            ];
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Get a specific image
     */
    public function getImage(WP_REST_Request $request): WP_REST_Response
    {
        $login = $request->get_param('login');

        if (!$this->fileManager->imageExists($login)) {
            return new WP_REST_Response([
                'error' => 'Image not found',
            ], 404);
        }

        $user = get_user_by('login', $login);
        $imageUrl = $this->fileManager->getImageUrl($login);

        return new WP_REST_Response([
            'login' => $login,
            'display_name' => $user ? $user->display_name : '',
            'image_url' => $imageUrl,
            'is_banned' => $this->optionStore->isUserBanned($login),
        ], 200);
    }

    /**
     * Delete an image
     */
    public function deleteImage(WP_REST_Request $request): WP_REST_Response
    {
        $login = $request->get_param('login');

        if ($this->fileManager->deleteImage($login)) {
            return new WP_REST_Response([
                'success' => true,
                'login' => $login,
            ], 200);
        }

        return new WP_REST_Response([
            'error' => 'Failed to delete image',
        ], 500);
    }

    /**
     * List banned users
     */
    public function listBanned(WP_REST_Request $request): WP_REST_Response
    {
        $banned = $this->optionStore->getBannedUsers();
        $result = [];

        foreach ($banned as $login) {
            $user = get_user_by('login', $login);

            if ($user) {
                $result[] = [
                    'login' => $login,
                    'display_name' => $user->display_name,
                    'email' => $user->user_email,
                ];
            }
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Add user to ban list
     */
    public function addToBanList(WP_REST_Request $request): WP_REST_Response
    {
        $login = $request->get_param('login');

        // Delete the image first
        $this->fileManager->deleteImage($login);

        // Add to ban list
        $this->optionStore->addBannedUser($login);

        return new WP_REST_Response([
            'success' => true,
            'login' => $login,
        ], 200);
    }

    /**
     * Remove user from ban list
     */
    public function removeFromBanList(WP_REST_Request $request): WP_REST_Response
    {
        $login = $request->get_param('login');

        if ($this->optionStore->removeBannedUser($login)) {
            return new WP_REST_Response([
                'success' => true,
                'login' => $login,
            ], 200);
        }

        return new WP_REST_Response([
            'error' => 'User not found in ban list',
        ], 404);
    }

    /**
     * Bulk action handler
     */
    public function bulkAction(WP_REST_Request $request): WP_REST_Response
    {
        $action = $request->get_param('action');
        $logins = $request->get_param('logins');

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($logins as $login) {
            $login = sanitize_user($login);
            $success = false;

            switch ($action) {
                case 'delete':
                    $success = $this->fileManager->deleteImage($login);
                    break;

                case 'ban':
                    $this->fileManager->deleteImage($login);
                    $this->optionStore->addBannedUser($login);
                    $success = true;
                    break;

                case 'unban':
                    $success = $this->optionStore->removeBannedUser($login);
                    break;
            }

            if ($success) {
                $results['success'][] = $login;
            } else {
                $results['failed'][] = $login;
            }
        }

        return new WP_REST_Response($results, 200);
    }

    /**
     * Check if current user has admin permissions
     */
    public function checkAdminPermission(): bool
    {
        return current_user_can('administrator');
    }

    /**
     * Check if current user has read permissions
     */
    public function checkReadPermission(): bool
    {
        return current_user_can('read');
    }
}
