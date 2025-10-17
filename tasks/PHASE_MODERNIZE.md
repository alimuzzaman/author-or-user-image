---
applyTo: '**'
---

# Phase: Modernize plugin (PSR-4, Composer, modern admin UI)

Goal: Modernize the plugin internals and admin UI without introducing any new features or changing user-visible data (no option migration). Keep existing behavior exactly; only change internal structure and the admin UI implementation.

Constraints (non-negotiable)
- Do NOT change option keys (e.g. `zm_ai_ban_id`).
- Do NOT change the filename convention for stored images (`<login>.jpg` in `uploads/author-image/`).
- Keep only `author_image.php` and `readme.txt` at plugin root after cleanup.
- No new product features. UI may be modernized (React) but must expose the same actions and semantics.

Phase 1 — Prep & bootstrap (safety)
- Tasks:
  - Add `composer.json` with PSR-4 autoload `AuthorImage\\` => `src/`.
  - Add `.gitignore` entries for `vendor/` and front-end build outputs.
  - Create `author_image.php` minimal bootstrap (WP headers, constants, `require __DIR__ . '/vendor/autoload.php'`, instantiate Plugin class).
- Deliverable: `composer.json`, `src/` folder created, minimal `author_image.php` in root.
- Acceptance: plugin still activates; no option data changed.

Phase 2 — Move code to PSR-4 classes (separation of concerns)
- Tasks:
  - Create classes: `Plugin` (bootstrap), `Admin\Page` (admin menu + enqueue), `Service\FileManager`, `Service\OptionStore`, `Ajax\LegacyShim` (optional), `REST\Controller` (if implementing REST shim).
  - Move logic from `list.php`, `black_list.php` into the above classes; do not delete originals until verification complete.
- Deliverable: `src/` with above classes, tests for FileManager (optional at this stage).
- Acceptance: FileManager can delete files; OptionStore reads/writes `zm_ai_ban_id` identical to old behavior.

Phase 3 — API surface and compatibility
- Tasks:
  - Provide REST endpoints under `author-image/v1` for listing, deleting, and banning — these are implementation detail; preserve old `wp_ajax_*` action names by registering a small legacy shim that maps old AJAX calls to the new services (so external callers keep working).
  - Ensure permission checks match previous behavior (admin-level actions).
- Deliverable: REST routes + legacy ajax shim.
- Acceptance: Existing admin JS (if still present) or external scripts that POST to `admin-ajax.php` continue to function.

Phase 4 — Modern admin UI (no new features)
- Tasks:
  - Scaffold a React admin app (Vite) in `assets/admin-src/` or `admin/`.
  - Implement a UI that mirrors current admin functionality: list images, delete, add to blacklist, unblock, role filter, bulk actions.
  - Use Redux Toolkit for predictable state; choose a UI library (MUI recommended) for consistent components.
  - Localize script with `rest_url`, a nonce, and plugin info; use REST endpoints for operations.
- Deliverable: built JS/CSS bundle enqueued by `Admin\Page` and an empty fallback PHP rendering for no-JS environments.
- Acceptance: Admin page provides the same actions and results as the legacy UI. No extra actions or options are introduced.

Phase 5 — Tests, linting and CI
- Tasks:
  - Add `phpcs` rules (WordPress or PSR-12 as preferred) and minimal `phpunit` tests for core services (`FileManager`, `OptionStore`).
  - Add a GitHub Actions workflow to run `composer install`, `phpcs`, and `phpunit`.
- Deliverable: `.github/workflows/ci.yml` and test skeleton.
- Acceptance: CI runs without introducing behavioral changes.

Phase 6 — Cleanup and finalization
- Tasks:
  - After full manual verification in a local WP install, remove legacy files from plugin root leaving only `author_image.php` and `readme.txt`.
  - Keep legacy files in a branch or archive if desired.
- Deliverable: tidy plugin root and updated `readme.txt` with developer instructions.
- Acceptance: Plugin functions unchanged in user-visible behavior; options intact.

Developer notes / commands
- Composer (from plugin dir):
  - composer install
  - composer dump-autoload -o
- Front-end (from plugin dir, after creating admin scaffold):
  - cd admin
  - npm install
  - npm run dev (development)
  - npm run build (production)

Compatibility & safety practices
- Preserve option keys exactly. Use `OptionStore::get('zm_ai_ban_id')` to access bans.
- Use `wp_upload_dir()` to build file paths. Default to `.jpg` for reads/deletes if that matches existing files.
- Add a `Legacy\Backup` branch before deleting legacy files.

Acceptance checklist (pre-merge)
- Manual integration test: activate plugin in a local WP site and exercise list, delete, block/unblock, and role filter flows.
- Confirm `zm_ai_ban_id` remains unchanged after refactor and operations.
- Ensure REST & legacy AJAX endpoints both function (legacy shim optional but recommended).

If you approve this plan I will implement the Phase 1 scaffold (composer.json + minimal `author_image.php` bootstrap and `src/Plugin.php`) next. No runtime behavior will be changed in that step.
