=== Author or User Image ===
Plugin Name: Author or User Image
Contributors: alimuzzamanalim
Author: Alimuzzaman Alim
Author URI: https://alim.dev
Donate link: none
Tags: author-image, author, authors, image, avatar, gravatar.
Requires at least: 5.0
Tested up to: 6.0.2
Stable tag: 2.0.2
License: GPLv2

== Description ==
WordPress Author / User Image lets you set the author image.
With this plugin, you will be able to set the author's image.
Ability to remove user image or block particular user. (New added)
This photo will appear below the post in the about author section and also in their comment. When you have many users your users will be able to set their photos. It appears in the comment. You can resize the uploaded image. So no extra disk space or bandwidth will be lost. The most amazing part of this plugin is you can set a default image for your user. If any of your users do not set their image then show the default image.


FEATURE of Author or User Image

1. Set a default image for any user. (Who did not set their Image)
2. Individually set user's image by user. (This image will be shown instead of the default image)

3. You can specify image size. (All images will be resized with this size before saving)

4. You will also be able to remove your image or the default image.

5. It's just below your profile page in the user section.

6. Control over the user’s image.

7. Ability to remove user’s image.

8. Ability to block any user from setting his image.

9. Ability to list all users who have set their image.

10. Ability to list all users who are on your blacklist.



== Installation ==

To Set Image:

1. You can add your photo or a default photo in "User >> Author Image".

2. If you want to set a default image you will find this at the top of the page.

3. Click on the browse button. Then select an image.

4. You can specify image size. So all images will be resized to that size.

5. At last click on save images to upload the image.


To Update Image:

1. If you want to update your image just upload it the first time, then it will replace your old image.


To Remove/Delete Image

1.  You can delete your image or default image. To delete your image go to "User >> Author Image" and scroll down to the bottom. There you find the image below.




== Developer Documentation ==

This plugin has been modernized with PSR-4 autoloading, REST API support, and comprehensive testing.

= Requirements =

* PHP 7.4 or higher
* WordPress 5.0 or higher
* Composer (for development)

= Architecture =

The plugin follows modern WordPress development practices:

* PSR-4 autoloading with namespace `AuthorImage\`
* Service layer for business logic (FileManager, OptionStore)
* REST API endpoints under `/wp-json/author-image/v1/`
* Legacy AJAX compatibility maintained for backward compatibility
* Comprehensive PHPUnit test coverage
* CI/CD with GitHub Actions

= Directory Structure =

* `src/` - Main plugin source code (PSR-4 autoloaded)
  * `Admin/` - Admin interface classes
  * `Ajax/` - Legacy AJAX endpoint handlers
  * `REST/` - REST API controllers
  * `Service/` - Business logic services
* `tests/` - PHPUnit tests
* `author_image.php` - Main plugin bootstrap file
* `composer.json` - Composer dependencies and autoload configuration

= For Developers =

Install dependencies:
```
composer install
```

Run tests:
```
composer test
# or
./vendor/bin/phpunit
```

Run code style checks:
```
composer phpcs
```

Auto-fix code style issues:
```
composer phpcbf
```

= REST API Endpoints =

* `GET /wp-json/author-image/v1/images` - List all author images
* `GET /wp-json/author-image/v1/images/{login}` - Get specific image
* `DELETE /wp-json/author-image/v1/images/{login}` - Delete an image
* `GET /wp-json/author-image/v1/banned` - List banned users
* `POST /wp-json/author-image/v1/banned` - Add user to ban list
* `DELETE /wp-json/author-image/v1/banned/{login}` - Remove from ban list
* `POST /wp-json/author-image/v1/bulk` - Bulk operations

All REST endpoints require administrator capabilities.

= Legacy AJAX Support =

The plugin maintains backward compatibility with the original AJAX endpoints:
* `wp_ajax_zmdeleteimg` - Delete image
* `wp_ajax_changerole` - Change role filter
* `wp_ajax_zm_dban` - Add to ban list
* `wp_ajax_zm_unblock` - Unblock user
* `wp_ajax_zm_uchangerole` - Change role filter (blacklist)
* `wp_ajax_ai-notification-close` - Close notification

= Service Classes =

**FileManager** - Handles all file operations:
* `deleteImage(string $login): bool`
* `imageExists(string $login): bool`
* `getImageUrl(string $login): string|false`
* `getAllImages(): array`
* `uploadImage(array $file, string $login, array $size)`

**OptionStore** - Manages WordPress options:
* `getBannedUsers(): array`
* `addBannedUser(string $login): bool`
* `removeBannedUser(string $login): bool`
* `isUserBanned(string $login): bool`
* `getImageSize(): array`
* `updateImageSize(array $size): bool`

= Data Storage =

The plugin maintains backward compatibility with existing installations:
* Option key for banned users: `zm_ai_ban_id`
* Option key for image size: `author_image_size`
* Option key for notification: `ai_notification`
* Image storage: `{wp-content}/uploads/author-image/{login}.jpg`
* Default image: `{wp-content}/uploads/author-image/author_default.jpg`

= Contributing =

Contributions are welcome! Please ensure:
1. All tests pass: `composer test`
2. Code follows PSR-12: `composer phpcs`
3. Add tests for new features
4. Update documentation as needed
