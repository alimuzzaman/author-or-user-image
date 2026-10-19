=== Author or User Image ===
Contributors: alimuzzamanalim
Tags: local avatar, profile picture, gravatar alternative
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use a local avatar or profile picture for registered users, with a site default and a Gravatar alternative for those accounts.

== Description ==

Author or User Image lets registered users upload an image that WordPress uses as their avatar in comments, author boxes, and other places that call the standard avatar functions.

Features include:

* Self-service avatar upload and removal for registered users.
* An Avatar upload block and `[author_or_user_image_upload]` shortcode that let signed-in visitors change their own avatar on a page.
* An upload setting that allows all users who can read, or only selected roles, on each site.
* Center-square avatars for new uploads, with the original image kept uncropped.
* An optional default avatar for registered users without a personal image.
* Administrator controls to remove images or block individual users from local avatar uploads.
* An importer for avatars from Simple Local Avatars, One User Avatar, WP User Avatar, WP User Avatars, and User Profile Picture / Metronet.
* Media Library storage and WordPress-generated image sizes.
* Personal-data export and erasure integration.
* Upgrade migration for images and blocked-user settings created by older versions.

The plugin does not send image or user data to an external service.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/author-or-user-image`, or install the plugin through the Plugins screen.
2. Activate **Author or User Image**.
3. Open **Users > Author or User Image** to upload your avatar.
4. Administrators can set the site default on that page and manage user images under **Users > Avatar Management**.
5. To let signed-in visitors upload from a page, add the **Avatar upload** block, or use the `[author_or_user_image_upload]` shortcode on a Classic Editor page.
6. Administrators can import avatars from an older avatar plugin under **Users > Import avatars**.

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

Opted-in cleanup removes plugin settings, upload and import data, and managed Media Library attachments for that site, including originals and square renditions. Legacy JPG fallback files retained during upgrades remain in `uploads/author-image`; remove those separately if they are no longer needed.

Blocks and shortcodes stay in your content after uninstall and do nothing. Remove each Avatar upload block and `[author_or_user_image_upload]` shortcode before you uninstall.

= Can subscribers upload an avatar? =

By default, yes. Any logged-in user who can read the site can upload an image for their own account, unless an administrator has blocked local avatar uploads for that user. Administrators can narrow this for each site under **Users > Avatar Management > Settings**:

* **All users who can read** keeps the default rule. It includes users in custom roles and users given read access individually.
* **Selected roles only** allows uploads only from members of that site who have a checked role. Super administrators need a checked role too.

Unchecking every role turns off self-service uploads. Existing avatars stay visible, and users can still remove their own. A user with several roles qualifies if any checked role matches. A deleted role stops matching, and a role created later with the same slug matches again. Roles created after you save the list are not included until you check them.

An individual block still stops that user from uploading, whatever their role. Only administrators can change the default image or manage other users.

= Is there an upload rate limit? =

Each user can make five authorized self-upload attempts per minute and twenty per hour, shared between the dashboard and front-end form on the same site. Multisite counters are per site, so a member of several sites has a separate allowance on each. Failed image attempts count too. Removal, privacy erasure, administrator moderation, imports and site-default changes do not use this counter.

= How do I add an upload form to a page? =

Insert the **Avatar upload** block in the block editor. On a Classic Editor page, use the `[author_or_user_image_upload]` shortcode instead.

The form changes only the signed-in visitor's own avatar. Neither the block nor the shortcode accepts attributes that choose a user, an attachment, a source URL, or the default avatar. Logged-out visitors see a log-in link. Blocked users, and users the upload setting excludes, see the reason instead of the form. The form posts directly to WordPress, so uploads and removals work with JavaScript turned off.

Place the block or shortcode as standalone content. Nesting it inside another form is not supported, and some themes nest content that way.

= Do I need to exclude form pages from caching? =

Yes. The form is personal to each signed-in visitor, so a full-page cache must not serve it to anyone else. Exclude every page that contains the block or shortcode from full-page caching, including forms placed in widgets or templates. For signed-in visitors, the plugin sends early no-cache headers when the queried content contains the block or shortcode. Rendering a form also sets DONOTCACHEPAGE and sends headers if they are still available. Other pages keep their normal cache behavior. A cache that answers before WordPress runs ignores these signals, so configure the exclusion in that cache.

= Can a login or security plugin block the upload form? =

Yes. The form submits to `admin-post.php`. Login and security plugins that block wp-admin for some users can block that file too, and then uploads and removals fail. Allow `admin-post.php` for logged-in users.

= Which file types are accepted? =

The plugin accepts JPEG, PNG, and static WebP images. GIF, SVG, AVIF, APNG, and animated WebP are rejected. Images must be no larger than 8 MiB or the site upload limit, whichever is smaller, and no more than 4096 pixels on either side. WordPress verifies the actual file type and processes the upload with its image APIs. JPEG processing requires the server's EXIF extension so phone-photo orientation can be handled safely. PNG and WebP do not require EXIF.

= How are new avatars cropped? =

Each new upload and competitor import gets a square rendition, cropped from the center to the avatar size set in the settings. The sanitized original image is kept uncropped.

Center cropping can cut off a face near an edge, so upload a square or centered photo for the best result. A small image may be enlarged for display, which can look soft.

= Are media-offload setups supported? =

New uploads need the local file until the square avatar is generated. Offload setups that delete local copies before then are not supported for uploads. Existing avatars keep rendering from attachment metadata.

Existing avatars and legacy JPG files are not rewritten. They keep their current display until the user replaces them. Changing the avatar size affects newly processed uploads and imports only.

An uncropped original is never served as a replacement for a missing square file.

Cropping is not redaction. The original keeps its full image.

= Does the plugin remove photo metadata? =

Starting with 3.1, new uploads, imported copies and legacy migration copies remove EXIF, GPS, XMP, IPTC, comments and embedded thumbnails before WordPress stores the image. JPEG, PNG eXIf and WebP EXIF orientation is applied to pixels first, including mirrored orientations. Rendering data, transparency and ICC color profiles are retained. Malformed files or failed sanitation are rejected, keeping the previous avatar. Oriented CMYK JPEGs are rejected when the selected editor cannot preserve their color space.

Previously uploaded originals and existing legacy files keep their metadata until the user re-uploads. There is no automatic backfill. Competitor source files and metadata are never changed.

Trusted PHP code can opt out for an individual operation with the `zmai_strip_image_metadata` filter, which receives a boolean defaulting to true and a context array with `owner` and `source` keys. Source is `upload`, `default`, `import` or `legacy`. This exception retains descriptive metadata and cannot be enabled through form fields, block attributes or imported settings. It does not bypass upload validation, provenance, permissions or rate limits.

= How do I import avatars from another plugin? =

Open **Users > Import avatars**, choose the source plugin, and start the import. Only an administrator can run it. Competitor imports start only from this screen. The automatic migration of this plugin's older data remains separate. The import runs in pages and reports counts, errors, and completion. Stop pauses the import, and Resume continues it. Closing the page stops further batches, so reopen the page and choose Resume.

A user who already has an imported avatar is skipped by later runs, so the first source imported for that user wins. A user who removes an avatar stays without one, and removal blocks any later re-import for that user.

Upload settings do not stop an administrator's import. Individual blocks still do.

Missing, offloaded, malformed, oversized, animated, and unsupported files are permanent skips for that sweep. A sweep with only these skips finishes. The screen lists up to 100 skipped user IDs and reason codes so you can repair their source files, then start a new import. Counts include every skipped user. Temporary lock or persistence failures pause the job; choose Retry repaired sources after resolving them. A paused job also offers Finish this import, which retains its results and lets you start another source. It does not clear recovery locks or undo imported avatars.

= Which avatar plugins can the importer read? =

The importer supports these sources and verified versions:

* Simple Local Avatars 2.8.6
* One User Avatar 2.5.6 / WP User Avatar 2.2.16 attachment IDs
* WP User Avatars 2.2.0
* User Profile Picture / Metronet 2.6.4

Other versions, other avatar plugins (including ProfilePress), historical formats, and cloud-hosted files are not covered.

The importer reads source data and never changes it. It does not deactivate, delete, or clean up source plugins. Imports copy files, so they use additional disk space. Erasing an imported image does not erase the source plugin data.

Source plugins can keep overriding avatars while they are active. Review the results, then turn off that plugin's avatar override yourself if needed. Avatar ratings, defaults, and override preferences are not migrated. For User Profile Picture / Metronet, the importer imports stored profile pictures and does not claim the same display as the source plugin.

On multisite, each site imports only its own users and local media. A shared avatar that came from another site needs its source file restored by hand. There is no network-wide or cross-site import.

The importer does not migrate source settings or ratings, sync avatars across a network, or import remote files. The bounded skip list contains user IDs and fixed reason codes only; it stores no source URL or file path and is replaced when you start a new job.

== Screenshots ==

1. Upload, replace, or remove your avatar from your profile menu.
2. Review user images and block local avatar uploads with administrator bulk controls.
3. Choose the generated avatar size and whether plugin data is removed on uninstall.
4. Custom avatars appear in front-end author cards and WordPress comments.
5. Administrators can set a site default avatar for registered users without an uploaded image.
6. Upload, replace, or remove your avatar on a page with the Avatar upload block or shortcode.
7. Import avatars from one source plugin at a time, and see progress and results on the Import avatars page.

== Upgrade Notice ==

= 3.1.0 =

Adds a front-end upload block and shortcode, role settings, square crops and an avatar importer. Source data stays untouched. Self-uploads are rate limited. Exclude pages with the upload form from full-page caching.

= 3.0.1 =

Fixes privacy erasure and avatar cleanup during user deletion. Release metadata and package contents are checked before publication.

= 3.0.0 =

Major security and standards rewrite. Existing settings and legacy JPG avatars are migrated automatically and remain available as fallbacks.

== Changelog ==

= 3.1.0 =

* Added the Avatar upload block and `[author_or_user_image_upload]` shortcode for signed-in visitors.
* Added a site-local upload setting that limits self-service uploads to selected roles. The default keeps the existing rule.
* Added per-user self-upload attempt limits shared by dashboard and front-end forms.
* Restricted importer adoption to subject-owned or legitimately administrator-assigned attachments. Unprovable URL-only references are skipped as not_owned.
* Replaced arbitrary administrator error text with fixed messages.
* Added center-square renditions for new uploads and competitor imports. Originals keep their full, uncropped pixels. New stored files strip descriptive metadata by default while retaining ICC color data. Earlier uploads retain metadata until re-uploaded.
* Added an Import avatars page under Users. It imports one source plugin at a time, in pages that can resume, from Simple Local Avatars, One User Avatar and WP User Avatar attachment IDs, WP User Avatars, and User Profile Picture / Metronet.
* Import leaves source plugin data unchanged and never deactivates or removes source plugins.

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
