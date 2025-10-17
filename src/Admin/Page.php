<?php

namespace AuthorImage\Admin;

use AuthorImage\Service\FileManager;
use AuthorImage\Service\OptionStore;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Admin\Page handles all admin interface registration and rendering
 */
class Page
{
    private FileManager $fileManager;
    private OptionStore $optionStore;

    public function __construct(FileManager $fileManager, OptionStore $optionStore)
    {
        $this->fileManager = $fileManager;
        $this->optionStore = $optionStore;

        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerAdminPages']);
        add_action('admin_init', [$this, 'handleFormSubmissions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);

        register_activation_hook(AUTHOR_IMAGE_PLUGIN_FILE, [$this, 'pluginActivate']);
    }

    /**
     * Register admin pages
     */
    public function registerAdminPages(): void
    {
        $ban = $this->optionStore->getBannedUsers();
        $current_user = wp_get_current_user();
        $id = $current_user->user_login;
        $band = in_array($id, $ban);

        if (!$band) {
            add_users_page(
                'Author Image',
                'Author Image',
                'read',
                'Author_Image',
                [$this, 'renderMainPage']
            );
        }

        add_users_page(
            'Author Image List',
            'Author Image List',
            'administrator',
            'Author_Image_List',
            [$this, 'renderListPage']
        );

        add_users_page(
            'Author Image Black List',
            'Author Image Black List',
            'administrator',
            'Author_Image_Black_List',
            [$this, 'renderBlackListPage']
        );

        add_users_page(
            'Author Image Settings',
            'Author Image Settings',
            'administrator',
            'Author_Image_Settings',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueueScripts(string $hook): void
    {
        wp_enqueue_style('ai_styles', plugin_dir_url(AUTHOR_IMAGE_PLUGIN_FILE) . 'style.css');
        wp_enqueue_script('ai_notification_close', plugin_dir_url(AUTHOR_IMAGE_PLUGIN_FILE) . 'scripts.js', ['jquery']);

        if ($hook === 'users_page_Author_Image_List') {
            wp_enqueue_script('my_custom_script', plugin_dir_url(AUTHOR_IMAGE_PLUGIN_FILE) . 'list_user.js', ['jquery']);
        }

        if ($hook === 'users_page_Author_Image_Black_List') {
            wp_enqueue_script('my_custom_script', plugin_dir_url(AUTHOR_IMAGE_PLUGIN_FILE) . 'block_user.js', ['jquery']);
        }

        // Inline script with nonces
        ?>
        <script>
        var plugin_url = "<?php echo esc_js(plugins_url('', AUTHOR_IMAGE_PLUGIN_FILE)); ?>";
        var nonce_dsban = "<?php echo wp_create_nonce('dsban'); ?>";
        var nonce_zmdelete = "<?php echo wp_create_nonce('zmdelete'); ?>";
        var nonce_unblock = "<?php echo wp_create_nonce('unblock'); ?>";
        var nonce_zm_u_change = "<?php echo wp_create_nonce('zm_u_change'); ?>";
        </script>
        <?php
    }

    /**
     * Plugin activation hook
     */
    public function pluginActivate(): void
    {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/author-image/';

        if (file_exists($path)) {
            $imgs = scandir($path);
            if ($imgs) {
                foreach ($imgs as $img) {
                    $id = str_replace('.jpg', '', $img);
                    if (is_numeric($id)) {
                        $user = get_user_by('id', $id);
                        if ($user) {
                            rename($path . $img, $path . $user->user_login . '.jpg');
                        }
                    }
                }
            }
        }
    }

    /**
     * Handle form submissions
     */
    public function handleFormSubmissions(): void
    {
        if (isset($_POST['rem']) || $_FILES || !empty($_POST['size'])) {
            $this->processFormSubmit();
        }

        $dir = WP_CONTENT_DIR . '/uploads/author-image/';
        if (is_admin() && !file_exists($dir . 'author_default.jpg')) {
            add_action('admin_notices', [$this, 'showAdminNotice']);
        }

        if (isset($_GET['ai_notification']) && $_GET['ai_notification']) {
            $this->optionStore->updateNotificationStatus(1);
        }
    }

    /**
     * Show admin notice for default image
     */
    public function showAdminNotice(): void
    {
        if (!$this->optionStore->getNotificationStatus()) :
            ?>
        <div id="ai_message" class="updated below-h1">
            <a class="ai-notification-close" href="?ai_notification=1">Dismiss</a>
            <p>Default Image is not set yet. <a href="users.php?page=Author_Image">Please set a default Image.</a></p>
        </div>
            <?php
        endif;
    }

    /**
     * Process form submission
     */
    private function processFormSubmit(): void
    {
        if (!wp_verify_nonce($_POST['___wpnonce'] ?? '', 'author_image_size')) {
            wp_die('Sorry unauthorized access.', 'Error');
        }

        $current_user = wp_get_current_user();
        $user_ID = $current_user->user_login;
        $dir = WP_CONTENT_DIR . '/uploads/author-image/';

        $this->fileManager->ensureDirectoryExists();

        // Handle size change
        if (isset($_POST['size'])) {
            $size = $_POST['size'];
            $currentSize = $this->optionStore->getImageSize();

            if ($size != $currentSize) {
                $this->optionStore->updateImageSize($size);
                $this->fileManager->resizeAllImages($size);
            }
        }

        $size = $this->optionStore->getImageSize();

        // Handle default image removal
        if (isset($_POST['author_default_image'])) {
            $this->fileManager->deleteImage('author_default');
        }

        // Handle user image removal
        if (isset($_POST['author_image']) || (!isset($_POST['ad']) && isset($_POST['rem']))) {
            $this->fileManager->deleteImage($user_ID);
        }

        // Handle default image upload
        if (!empty($_FILES['author_default']['tmp_name'])) {
            $this->fileManager->uploadImage($_FILES['author_default'], 'author_default', $size);
        }

        // Handle user image upload
        if (!empty($_FILES['author_curr']['tmp_name'])) {
            $this->fileManager->uploadImage($_FILES['author_curr'], $user_ID, $size);
        }
    }

    /**
     * Render main page
     */
    public function renderMainPage(): void
    {
        $dir = WP_CONTENT_DIR . '/uploads/author-image/';
        $nonce = wp_create_nonce('author_image_size');
        ?>
        <div class="wrap">
            <h2>Author Image</h2>
            <form method="post" enctype="multipart/form-data">
                <?php settings_fields('author_image_size'); ?>
                <input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo $nonce; ?>">
                <table class="form-table">
                    <tbody>
                        <?php if (current_user_can('administrator')) : ?>
                        <tr valign="top">
                            <th scope="row"><label for="author_default">Default Image</label></th>
                            <td>
                                <input name="author_default" id="author_default" type="file">
                                <br>
                                <?php
                                if (file_exists($dir . 'author_default.jpg')) {
                                    $qq = get_option('siteurl') . '/wp-content/uploads/author-image/author_default.jpg';
                                    echo "<br /><img width='200px' src='{$qq}' alt='' />";
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr valign="top">
                            <th scope="row"><label for="author_curr">Yours Image</label></th>
                            <td>
                                <input name="author_curr" id="author_curr" type="file">
                                <br>
                                <?php
                                $current_user = wp_get_current_user();
                                $user_ID = $current_user->user_login;
                                if (file_exists($dir . $user_ID . '.jpg')) {
                                    $qq = get_option('siteurl') . '/wp-content/uploads/author-image/' . $user_ID . '.jpg';
                                    echo "<img width='200px' src='$qq' alt='' />";
                                }
                                ?>
                                <br>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Image" />
                </p>
            </form>

            <br /><br /><br />

            <h2>Remove Picture</h2>
            <form method="post">
                <?php settings_fields('author_image_size'); ?>
                <input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo $nonce; ?>">
                <input type="hidden" name="rem" value="true" />
                <p style="margin-left:22px">
                    <?php if (current_user_can('administrator')) : ?>
                    <label>
                        <input type="hidden" name="ad" value="true" />
                        <input type="checkbox" name="author_default_image" value="true" id="CheckboxGroup1_0"
                            <?php if (!file_exists($dir . 'author_default.jpg')) {
                                echo 'disabled';
                            } ?> />
                        Default
                    </label>
                    <br /><br /><br />
                    <label>
                        <input type="checkbox" name="author_image" value="true" id="CheckboxGroup1_1"
                            <?php if (!file_exists($dir . $user_ID . '.jpg')) {
                                echo 'disabled';
                            } ?> />
                        Your
                    </label>
                    <br />
                    <?php endif; ?>
                </p>
                <p class="submit">
                    <input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo $nonce; ?>">
                    <input type="submit" name="submit" class="button-primary" value="Remove Picture"
                        <?php
                        if (current_user_can('administrator')) {
                            if (!file_exists($dir . $user_ID . '.jpg') && !file_exists($dir . 'author_default.jpg')) {
                                echo 'disabled';
                            }
                        } else {
                            if (!file_exists($dir . $user_ID . '.jpg')) {
                                echo 'disabled';
                            }
                        }
                        ?> />
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render list page
     */
    public function renderListPage(): void
    {
        ?>
        <div class="wrap">
            <h2>Author Image List</h2>
            <?php $this->renderTableNav(); ?>
            <table id="tbl" class="wp-list-table widefat fixed users">
                <tr>
                    <th width=30px><input class=chkall type="checkbox"></th>
                    <th>User Login Name</th>
                    <th>User Display Name</th>
                    <th width=150px>User Picture</th>
                    <th>Action</th>
                </tr>
                <?php
                $upload_dir = wp_upload_dir();
                $path = $upload_dir['basedir'] . '/author-image/';
                $imgs = scandir($path);
                $ban = $this->optionStore->getBannedUsers();
                $x = false;

                if (empty($imgs)) {
                    echo "<tr><td colspan='5'>Sorry nothing found</td><tr>";
                }

                for ($i = 2; $i < count($imgs); $i++) {
                    $id = str_replace('.jpg', '', $imgs[$i]);
                    $band = in_array($id, $ban);
                    $idd = get_user_by('login', $id);

                    if ($idd && !$band) {
                        $x = !$x;
                        if ($x) {
                            echo "<tr id='tr{$id}' class=alternate>";
                        } else {
                            echo "<tr id='tr{$id}'>";
                        }
                        echo "<th><input id='{$id}' class='chk' type=checkbox></th>";

                        if ($id === 'author_default') {
                            echo "<td>The Detault Image</td>";
                        } else {
                            echo "<td>{$idd->user_login}</td>";
                        }

                        echo "<td>{$idd->display_name}</td>";
                        echo "<td><img width=100px src='{$upload_dir['baseurl']}/author-image/{$id}.jpg' /></td>";
                        echo "<td id='td{$id}'>";
                        echo $this->renderActionButtons($id);
                        echo "</td>";
                        echo "</tr>";
                    }
                }
                ?>
                <tr>
                    <th><input type=checkbox class=chkall></th>
                    <th>User Login Name</th>
                    <th>User Display Name</th>
                    <th>User Picture</th>
                    <th>Action</th>
                </tr>
            </table>
            <?php $this->renderTableNav(2); ?>
        </div>
        <?php
    }

    /**
     * Render blacklist page
     */
    public function renderBlackListPage(): void
    {
        ?>
        <div class="wrap">
            <h2>Author Image Black List</h2>
            <?php $this->renderTableNav(); ?>
            <table id="tbl" class="wp-list-table widefat fixed users">
                <tr>
                    <th width=30px><input class=chkall type="checkbox"></th>
                    <th>User Login Name</th>
                    <th>User Display Name</th>
                    <th>Action</th>
                </tr>
                <?php
                $ban = $this->optionStore->getBannedUsers();
                $x = false;

                if (empty($ban)) {
                    echo "<tr><td colspan='5'>Sorry nothing found</td><tr>";
                }

                for ($i = 0; $i < count($ban); $i++) {
                    $id = $ban[$i];
                    $idd = get_user_by('login', $id);

                    if ($idd) {
                        $x = !$x;
                        if ($x) {
                            echo "<tr id='tr{$id}' class=alternate>";
                        } else {
                            echo "<tr id='tr{$id}'>";
                        }
                        echo "<th><input id='{$id}' class='chk' type=checkbox></th>";

                        if ($id === 'author_default') {
                            echo "<td>The Detault Image</td>";
                        } else {
                            echo "<td>{$idd->user_login}</td>";
                        }

                        echo "<td>{$idd->display_name}</td>";
                        echo "<td id='td{$id}'>";
                        echo $this->renderUnblockButton($id);
                        echo "</td>";
                        echo "</tr>";
                    }
                }
                ?>
                <tr>
                    <th><input type=checkbox class=chkall></th>
                    <th>User Login Name</th>
                    <th>User Display Name</th>
                    <th>Action</th>
                </tr>
            </table>
            <?php $this->renderTableNav(2); ?>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function renderSettingsPage(): void
    {
        $size = $this->optionStore->getImageSize();
        ?>
        <div class="wrap">
            <h2>Author Image</h2>
            <form method="post" enctype="multipart/form-data">
                <?php settings_fields('author_image_size'); ?>
                <input type="hidden" id="___wpnonce" name="___wpnonce" value="<?php echo wp_create_nonce('author_image_size'); ?>">
                <table>
                    <tr>
                        <td colspan="2"><h3 title="This is maintain accepts ratio">Resize Image</h3></td>
                    </tr>
                    <tr valign="top">
                        <th><label scope="row" for="size[h]">Height of image:</label></th>
                        <td><input type="text" name='size[h]' id='size[h]' value='<?php echo $size['h']; ?>' /></td>
                    </tr>
                    <tr>
                        <th><label scope="row" for="size[width]">Width of image:</label></th>
                        <td><input type="text" name='size[width]' id='size[width]' value='<?php echo $size['width']; ?>'/></td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <input type="submit" name="submit" class="button-primary" value="Save Settings" />
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <?php
    }

    /**
     * Render table navigation
     */
    private function renderTableNav(int $id = 1): void
    {
        ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <select name="action<?php echo $id; ?>" id="action<?php echo $id; ?>">
                    <option value="-1" selected="selected">Bulk Actions</option>
                    <option value="delete">Delete</option>
                    <option value="block">Block</option>
                </select>
                <input type="submit" name="" id="<?php echo $id; ?>" class="button action dall" value="Apply">
            </div>
            <div class="alignleft actions">
                <label class="screen-reader-text" for="new_role">Change role to…</label>
                <select name="new_role" id="new_role<?php echo $id; ?>">
                    <option value="All">Change role to…</option>
                    <option value="All">All</option>
                    <option value="administrator">Administrator</option>
                    <option value="editor">Editor</option>
                    <option value="author">Author</option>
                    <option value="contributor">Contributor</option>
                    <option value="subscriber">Subscriber</option>
                </select>
                <input type="submit" name="changerole" id="<?php echo $id; ?>" class="button changer" value="Change">
            </div>
            <div class="tablenav-pages one-page lodingg" id="loding"></div>
        </div>
        <?php
    }

    private function renderActionButtons(string $id): string
    {
        return "
        <input class='button action ds' id='{$id}' type=submit value='Delete Picture' name='delete' style='margin-bottom:15px' />
        <input class='button action dsban' id='{$id}' type=submit value='Add to black list' name=delete />";
    }

    private function renderUnblockButton(string $id): string
    {
        return "
        <input class='button action unblock' id='{$id}' type=submit value='Unblock user' name=delete />";
    }
}
