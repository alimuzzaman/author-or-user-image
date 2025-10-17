<?php
namespace AuthorImage\Service;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * OptionStore handles all WordPress option operations
 * Maintains existing option keys: zm_ai_ban_id, author_image_size, ai_notification
 */
class OptionStore
{
    /**
     * Get banned user IDs
     *
     * @return array Array of banned user login names
     */
    public function getBannedUsers(): array
    {
        $banned = get_option('zm_ai_ban_id', []);
        
        return is_array($banned) ? $banned : [];
    }

    /**
     * Add a user to the ban list
     *
     * @param string $login User login name
     * @return bool True if added, false if already banned
     */
    public function addBannedUser(string $login): bool
    {
        $banned = $this->getBannedUsers();
        
        if (!in_array($login, $banned)) {
            $banned[] = $login;
            update_option('zm_ai_ban_id', $banned);
            return true;
        }
        
        return false;
    }

    /**
     * Remove a user from the ban list
     *
     * @param string $login User login name
     * @return bool True if removed, false if not found
     */
    public function removeBannedUser(string $login): bool
    {
        $banned = $this->getBannedUsers();
        $key = array_search($login, $banned);
        
        if ($key !== false) {
            array_splice($banned, $key, 1);
            update_option('zm_ai_ban_id', $banned);
            return true;
        }
        
        return false;
    }

    /**
     * Check if a user is banned
     *
     * @param string $login User login name
     * @return bool True if banned
     */
    public function isUserBanned(string $login): bool
    {
        $banned = $this->getBannedUsers();
        
        return in_array($login, $banned);
    }

    /**
     * Get image size settings
     *
     * @return array Array with 'width' and 'h' keys
     */
    public function getImageSize(): array
    {
        return get_option('author_image_size', ['h' => '150', 'width' => '150']);
    }

    /**
     * Update image size settings
     *
     * @param array $size Array with 'width' and 'h' keys
     * @return bool True on success
     */
    public function updateImageSize(array $size): bool
    {
        if (!add_option('author_image_size', $size, '', 'no')) {
            return update_option('author_image_size', $size);
        }
        
        return true;
    }

    /**
     * Get notification status
     *
     * @return bool|int Notification status
     */
    public function getNotificationStatus()
    {
        return get_option('ai_notification');
    }

    /**
     * Update notification status
     *
     * @param int $status Status value
     * @return bool True on success
     */
    public function updateNotificationStatus(int $status): bool
    {
        return update_option('ai_notification', $status);
    }
}
