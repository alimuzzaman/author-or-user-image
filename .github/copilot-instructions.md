<!--
Repository: author-or-user-image
Purpose: Author / user image management WordPress plugin (PHP). This file gives concise, actionable guidance for AI coding agents working on this repository.
Do not include speculative or non-discoverable instructions.
-->

# Copilot instructions for author-or-user-image

Short guidance so an AI coding agent can be productive immediately.

1. Repository purpose (big picture)
   - This is a small WordPress plugin that stores per-user author images under the uploads directory (folder: `wp-content/uploads/author-image`).
   - Main features: list author images, delete images, add users to a blacklist, and change role-based listings via AJAX.
   - No build system — it's PHP intended to run inside a WordPress installation.

2. Key files / entry points
   - `list.php` — central UI and AJAX handlers (class `zm_ai_list`). Important methods:
     - `listuser()` — renders the admin listing table.
     - `changerole()` — AJAX route that filters the displayed images by role.
     - `zm_delete_pic()` and `zm_dban()` — AJAX endpoints to delete images and add banned ids via `update_option("zm_ai_ban_id", ...)`.
     - `delimg($id)` — deletes the file from uploads; path built with `wp_upload_dir()`.
   - `author_image.php`, `settings.php`, `black_list.php` — other plugin pages (inspect for complementary logic).
   - `scripts.js`, `list_user.js`, `block_user.js` — JS that wires admin interactions to the AJAX handlers. When changing PHP handlers, update corresponding JS selectors and nonces.

3. Patterns and conventions observed
   - Uses WordPress APIs directly: `add_action('wp_ajax_*', ...)`, `get_option()`, `update_option()`, `wp_upload_dir()`, `get_user_by()`, `user_can()`.
   - File storage convention: images stored as `<login>.jpg` inside `uploads/author-image/` (e.g., `$upload_dir['basedir'].'/author-image/'.$id.'.jpg'`).
   - Nonce usage: verify via `wp_verify_nonce($_POST['non'], '...')` in AJAX handlers. Ensure JS passes the correct nonce field name `non`.
   - UI rendering: many HTML fragments are echoed directly from PHP methods. Modifications should preserve output escaping when exposed to admin UI.

4. Important development workflows (how to run/test)
   - Run inside a local WordPress environment (e.g., local Docker, LocalWP, or MAMP). Place plugin files into `wp-content/plugins/author-or-user-image` and activate via WP admin.
   - To exercise AJAX handlers, use the plugin's admin pages or trigger JS actions in browser devtools. For unit-like manual tests, craft POST requests to `wp-admin/admin-ajax.php` with action keys:
     - `action=zmdeleteimg` (payload: `id`, `non`)
     - `action=zm_dban` (payload: `id`, `non`)
     - `action=changerole` (payload: `role`, `non`)
   - There are no automated tests in the repo. Add tests only if you update behavior; prefer small integration tests using WP-CLI + PHPUnit if desired.

5. Integration points and external dependencies
   - Relies on WordPress core only. No composer/npm configs in this repo.
   - Uses the WP uploads directory — ensure `wp_upload_dir()` returns expected paths in the environment.

6. Safe edit rules for AI agents
   - Preserve existing action hooks (`add_action`) and nonces. If renaming actions, update JS and admin pages.
   - When editing file paths, use `wp_upload_dir()` results (do not hardcode `/wp-content/uploads`).
   - When outputting user-provided values (user login, display_name), prefer escaping functions like `esc_html()` — if adding escaping, keep behavior identical unless explicitly fixing security issues.
   - Avoid changing the storage filename convention unless you migrate existing files in uploads/author-image.

7. Minimal examples (what to search/edit)
   - To find AJAX endpoints: search for `add_action('wp_ajax_` in PHP files.
   - To find where images are deleted: inspect `delimg($id)` in `list.php`.
   - To update the ban option key: `get_option("zm_ai_ban_id")` and `update_option("zm_ai_ban_id",$ids)` are the authoritative usages.

8. When to ask the human (what AI cannot infer)
   - Clarify expected behavior for `author_default` image and how to treat users missing in `get_user_by()`.
   - Confirm desired escaping/output sanitization policy for admin HTML.
   - If changing storage format (e.g., PNG or subfolders), confirm migration strategy.

9. Quick checklist for PRs
   - Confirm WordPress hooks and JS selectors remain in sync.
   - Preserve nonces or update them in all JS/PHP locations.
   - Run manual integration test in a local WP instance: upload images to `uploads/author-image`, exercise listing, delete, and blacklist flows.

If anything above is unclear or you want me to include command examples for a specific local setup (Docker, MAMP, LocalWP), tell me which and I will expand the instructions.
