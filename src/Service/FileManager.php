<?php

namespace AuthorImage\Service;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * FileManager handles all file operations for author images
 * Maintains existing filename convention: <login>.jpg in uploads/author-image/
 */
class FileManager
{
    /**
     * Delete an author image file
     *
     * @param string $login User login name
     * @return bool True on success, false on failure
     */
    public function deleteImage(string $login): bool
    {
        $udir = wp_upload_dir();
        $filePath = $udir['basedir'] . '/author-image/' . $login . '.jpg';

        if (is_file($filePath)) {
            return unlink($filePath);
        }

        return false;
    }

    /**
     * Check if an author image exists
     *
     * @param string $login User login name
     * @return bool True if image exists
     */
    public function imageExists(string $login): bool
    {
        $udir = wp_upload_dir();
        $filePath = $udir['basedir'] . '/author-image/' . $login . '.jpg';

        return file_exists($filePath);
    }

    /**
     * Get the URL for an author image
     *
     * @param string $login User login name
     * @return string|false URL on success, false if not found
     */
    public function getImageUrl(string $login)
    {
        if ($this->imageExists($login)) {
            $udir = wp_upload_dir();
            return $udir['baseurl'] . '/author-image/' . $login . '.jpg';
        }

        return false;
    }

    /**
     * Get all author images
     *
     * @return array List of login names that have images
     */
    public function getAllImages(): array
    {
        $udir = wp_upload_dir();
        $path = $udir['basedir'] . '/author-image/';

        if (!file_exists($path)) {
            return [];
        }

        $imgs = scandir($path);
        $result = [];

        foreach ($imgs as $img) {
            if ($img === '.' || $img === '..') {
                continue;
            }

            $login = str_replace('.jpg', '', $img);
            $result[] = $login;
        }

        return $result;
    }

    /**
     * Ensure the author-image directory exists
     *
     * @return bool True on success
     */
    public function ensureDirectoryExists(): bool
    {
        $udir = wp_upload_dir();
        $path = $udir['basedir'] . '/author-image/';

        return wp_mkdir_p($path);
    }

    /**
     * Upload and resize an author image
     *
     * @param array $uploadedFile Uploaded file data from $_FILES
     * @param string $login User login name
     * @param array $size Array with 'width' and 'h' keys
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function uploadImage(array $uploadedFile, string $login, array $size)
    {
        $this->ensureDirectoryExists();

        $wp_filetype = wp_check_filetype_and_ext($uploadedFile['tmp_name'], $uploadedFile['name'], false);

        if (!wp_match_mime_types('image', $wp_filetype['type'])) {
            return new \WP_Error('invalid_image', __('The uploaded file is not a valid image. Please try again.'));
        }

        $image = wp_get_image_editor($uploadedFile['tmp_name']);

        if (is_wp_error($image)) {
            return $image;
        }

        $image->resize($size['width'], $size['h']);

        $udir = wp_upload_dir();
        $path = $udir['basedir'] . '/author-image/' . $login . '.jpg';

        $saved = $image->save($path);

        if (is_wp_error($saved)) {
            return $saved;
        }

        return true;
    }

    /**
     * Resize all existing images
     *
     * @param array $size Array with 'width' and 'h' keys
     * @return int Number of images resized
     */
    public function resizeAllImages(array $size): int
    {
        $udir = wp_upload_dir();
        $dir = $udir['basedir'] . '/author-image/';

        if (!file_exists($dir)) {
            return 0;
        }

        $count = 0;
        $d = dir($dir);

        while ($file = $d->read()) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $image = wp_get_image_editor($dir . $file);

            if (!is_wp_error($image)) {
                $image->resize($size['width'], $size['h']);
                $image->save($dir . $file);
                $count++;
            }
        }

        $d->close();

        return $count;
    }
}
