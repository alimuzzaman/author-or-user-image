<?php

namespace AuthorImage\Ajax;

use AuthorImage\Service\FileManager;
use AuthorImage\Service\OptionStore;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * LegacyShim maintains backward compatibility with old AJAX endpoints
 * Maps old wp_ajax_* actions to new service methods
 */
class LegacyShim
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
        // List.php actions
        add_action('wp_ajax_zmdeleteimg', [$this, 'handleDeleteImage']);
        add_action('wp_ajax_changerole', [$this, 'handleChangeRole']);
        add_action('wp_ajax_zm_dban', [$this, 'handleAddToBanList']);

        // Black_list.php actions
        add_action('wp_ajax_zm_unblock', [$this, 'handleUnblock']);
        add_action('wp_ajax_zm_uchangerole', [$this, 'handleUserChangeRole']);

        // Author_image.php actions
        add_action('wp_ajax_ai-notification-close', [$this, 'handleNotificationClose']);
    }

    /**
     * Handle delete image AJAX request
     * Original: zm_ai_list::zm_delete_pic()
     */
    public function handleDeleteImage(): void
    {
        if (!wp_verify_nonce($_POST['non'] ?? '', 'zmdelete')) {
            echo 'Failed';
            exit;
        }

        $id = $_POST['id'] ?? '';

        if (!is_array($id)) {
            if ($this->fileManager->deleteImage($id)) {
                echo $id;
            }
        } else {
            $ids = '';
            foreach ($id as $val) {
                if ($this->fileManager->deleteImage($val)) {
                    $ids .= "<id class=ids>{$val}</id>";
                }
            }
            echo $ids;
        }

        exit;
    }

    /**
     * Handle add to ban list AJAX request
     * Original: zm_ai_list::zm_dban()
     */
    public function handleAddToBanList(): void
    {
        if (
            !wp_verify_nonce($_POST['non'] ?? '', 'dsban') &&
            !wp_verify_nonce($_POST['non'] ?? '', 'zmdelete')
        ) {
            echo 'Failed';
            exit;
        }

        $id = $_POST['id'] ?? '';

        if (!is_array($id)) {
            if ($this->fileManager->deleteImage($id)) {
                $this->optionStore->addBannedUser($id);
                echo $id;
            } else {
                echo 'Failed';
            }
        } else {
            $idss = '';
            foreach ($id as $val) {
                if ($this->fileManager->deleteImage($val)) {
                    $idss .= "<id>{$val}</id>";
                    $this->optionStore->addBannedUser($val);
                }
            }
            echo $idss;
        }

        exit;
    }

    /**
     * Handle change role filter AJAX request
     * Original: zm_ai_list::changerole()
     */
    public function handleChangeRole(): void
    {
        if (!wp_verify_nonce($_POST['non'] ?? '', 'zmdelete')) {
            exit;
        }

        $role = $_POST['role'] ?? '';
        if ($role === 'All') {
            $role = 'read';
        }

        ?>
        <tr><th><input class=chkall type="checkbox" ></th><th>User Login Name</th><th>User Display Name</th><th>User Picture</th><th>Action</th></tr>
        <?php

        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/author-image/';
        $imgs = scandir($path);
        $x = false;

        if (empty($imgs)) {
            echo "<tr><td colspan='5'>Sorry nothing found</td><tr>";
        }

        for ($i = 2; $i < count($imgs); $i++) {
            $id = str_replace('.jpg', '', $imgs[$i]);
            $idd = get_user_by('login', $id);

            if (user_can($idd, $role) || $role === '') {
                $x = !$x;
                if ($x) {
                    echo "<tr id='tr{$id}' class=alternate >";
                } else {
                    echo "<tr id='tr{$id}' >";
                }
                echo "<th><input id='{$id}' class='chk' type=checkbox ></th>";

                if ($id === 'author_default') {
                    echo "<td>The Detault Image</td>";
                } else {
                    echo "<td>{$idd->user_login}</td>";
                }

                echo "<td>{$idd->display_name}</td>";
                echo "<td><img width=100px src='{$upload_dir['baseurl']}/author-image/{$imgs[$i]}' /></td>";
                echo "<td id='td{$id}'>";
                echo $this->renderActionButtons($id);
                echo "</td>";
                echo "</tr>";
            }
        }
        ?>
        <tr><th><input type=checkbox class=chkall ></th><th>User Login Name</th><th>User Display Name</th><th>User Picture</th><th>Action</th></tr>
        <?php
        exit;
    }

    /**
     * Handle unblock user AJAX request
     * Original: zm_ai_black_list::zm_unblock()
     */
    public function handleUnblock(): void
    {
        if (!wp_verify_nonce($_POST['non'] ?? '', 'unblock')) {
            echo 'Failed';
            exit;
        }

        $id = $_POST['id'] ?? '';

        if (!is_array($id)) {
            if ($this->optionStore->removeBannedUser($id)) {
                echo $id;
            }
        } else {
            foreach ($id as $i) {
                if ($this->optionStore->removeBannedUser($i)) {
                    echo "<id>{$i}</id>";
                }
            }
        }

        exit;
    }

    /**
     * Handle user change role filter AJAX request
     * Original: zm_ai_black_list::changerole()
     */
    public function handleUserChangeRole(): void
    {
        if (!wp_verify_nonce($_POST['non'] ?? '', 'zm_u_change')) {
            exit;
        }

        $role = $_POST['role'] ?? '';
        if ($role === 'All') {
            $role = 'read';
        }

        ?>
        <tr><th width=30px><input class=chkall type="checkbox" ></th><th>User Login Name</th><th>User Display Name</th><th>Action</th></tr>
        <?php

        $imgs = $this->optionStore->getBannedUsers();

        if (empty($imgs)) {
            echo "<tr><td colspan='5'>Sorry nothing found</td><tr>";
        }

        $x = false;
        for ($i = 0; $i < count($imgs); $i++) {
            $id = $imgs[$i];
            $idd = get_user_by('login', $id);

            if (user_can($idd, $role) || $role === '') {
                $x = !$x;
                if ($x) {
                    echo "<tr id='tr{$id}' class=alternate >";
                } else {
                    echo "<tr id='tr{$id}' >";
                }
                echo "<th><input id='{$id}' class='chk' type=checkbox ></th>";

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
        <tr><th><input type=checkbox class=chkall ></th><th>User Login Name</th><th>User Display Name</th><th>Action</th></tr>
        <?php
        exit;
    }

    /**
     * Handle notification close AJAX request
     * Original: zm_author_image::ai_notification_close()
     */
    public function handleNotificationClose(): void
    {
        $this->optionStore->updateNotificationStatus(1);
        echo 'done';
        die();
    }

    /**
     * Render action buttons for list view
     */
    private function renderActionButtons(string $id): string
    {
        return "
        <input class='button action ds' id='{$id}' type=submit value='Delete Picture' name='delete' style='margin-bottom:15px' />
        <input class='button action dsban' id='{$id}' type=submit value='Add to black list' name=delete />";
    }

    /**
     * Render unblock button for blacklist view
     */
    private function renderUnblockButton(string $id): string
    {
        return "
        <input class='button action unblock' id='{$id}' type=submit value='Unblock user' name=delete />";
    }
}
