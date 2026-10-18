=== Author or User Image ===
Contributors: alimuzzamanalim
Tags: local avatar, profile picture, gravatar alternative
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use a local avatar or profile picture for registered users, with a site default and a Gravatar alternative for those accounts.

== Description ==

Author or User Image lets registered users upload an image that WordPress uses as their avatar in comments, author boxes, and other places that call the standard avatar functions.

Features include:

* Self-service avatar upload and removal for registered users.
* An optional default avatar for registered users without a personal image.
* Administrator controls to remove images or block individual users from local avatar uploads.
* Media Library storage and WordPress-generated image sizes.
* Personal-data export and erasure integration.
* Upgrade migration for images and blocked-user settings created by older versions.

The plugin does not send image or user data to an external service.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/author-or-user-image`, or install the plugin through the Plugins screen.
2. Activate **Author or User Image**.
3. Open **Users > Author or User Image** to upload your avatar.
4. Administrators can set the site default on that page and manage user images under **Users > Avatar Management**.

== Frequently Asked Questions ==

= Where are new avatar images stored? =

Version 3.0.0 stores new uploads as WordPress Media Library attachments. Attachments created by the plugin are marked internally so replacement, privacy erasure, and optional uninstall cleanup only delete plugin-managed files.

= What happens to images from an older version? =

An administrator visit starts a one-time migration. The plugin imports valid legacy JPG files into the Media Library and keeps the old files as a safe fallback. Existing image-size and blocked-user options are also understood and migrated.

Temporary import failures are retried on a later administrator visit. On multisite, avatars and blocked-upload settings belong to each individual site.

Imported images are stored separately from explicit uploads and removals, so a legacy import in progress cannot replace a newer user choice.

Legacy images are read from the fixed `wp-content/uploads/author-image` location used by the old plugin, including on sites with a custom upload directory. On multisite, removing an avatar hides its legacy fallback on that site and retains the shared original for other sites. Privacy erasure reports any shared original it retains.

= Does network user deletion clean avatars on every site? =

Network cleanup requires network activation, or activation on the main site so the plugin loads in network admin. Activation only on a subsite cannot install a network deletion hook, so that subsite's avatar data may remain after a network account is deleted.

Deletion checks all existing sites in pages of 100. Sites without plugin settings, affected user metadata or owned images are skipped with one database existence check and receive no plugin rows. Cleanup runs synchronously and holds a fence for each affected site until deletion ends. Large networks should allow enough execution time and delete accounts in small batches. Keep site creation, removal and first avatar setup quiescent during deletion. A busy site aborts the account deletion so it can be retried.

= Does uninstalling delete avatar data? =

Not by default. Administrators may opt in under **Users > Avatar Management > Settings**. Deactivation never deletes data.

Opted-in cleanup removes plugin settings and managed Media Library attachments for that site. Legacy JPG fallback files retained during upgrades remain in `uploads/author-image`; remove those separately if they are no longer needed.

= Can subscribers upload an avatar? =

Yes. Any logged-in user who can access their WordPress profile can upload an image for their own account unless an administrator has blocked local avatar uploads for that user. Only administrators can change the default image or manage other users.

= Which file types are accepted? =

The plugin accepts JPEG, PNG, and WebP images. WordPress verifies the actual file type and processes the upload with its image APIs.

== Screenshots ==

1. Upload, replace, or remove your avatar from your profile menu.
2. Review user images and block local avatar uploads with administrator bulk controls.
3. Choose the generated avatar size and whether plugin data is removed on uninstall.
4. Custom avatars appear in front-end author cards and WordPress comments.
5. Administrators can set a site default avatar for registered users without an uploaded image.

== Upgrade Notice ==

= 3.0.1 =

Fixes privacy erasure and avatar cleanup during user deletion. Release metadata and package contents are checked before publication.

= 3.0.0 =

Major security and standards rewrite. Existing settings and legacy JPG avatars are migrated automatically and remain available as fallbacks.

== Changelog ==

= 3.0.1 =

* Serialized avatar changes and cleanup to prevent orphaned files and false privacy-erasure success.
* Bounded eraser retries, cleaned network user data, and released completed deletion fences on shutdown.
* Kept legacy migration moving past failed imports and reported recovery failures.
* Split storage, locking, migration and user cleanup into separate classes.
* Aligned release metadata and added a production package check.

= 3.0.0 =

* Rebuilt avatar handling around current WordPress avatar, upload, image, and Media Library APIs.
* Replaced login-based primary storage with user-ID attachment metadata.
* Added idempotent migration and legacy-data fallbacks.
* Added nonce and capability checks to every state-changing request.
* Added strict upload validation, sanitization, and escaped output.
* Replaced AJAX administration with accessible server-rendered forms and paginated tables.
* Added personal-data exporter and eraser support.
* Added optional, explicit uninstall cleanup.
* Removed unused undocumented binary assets and the former branding from the display name.

= 2.0.2 =

* Previous public release.
