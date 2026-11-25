# Author or User Image

[![CI](https://github.com/alimuzzaman/author-or-user-image/actions/workflows/ci.yml/badge.svg)](https://github.com/alimuzzaman/author-or-user-image/actions/workflows/ci.yml)

Use a local avatar or profile picture for registered WordPress users, with a site default and a Gravatar alternative for those accounts.

## Features

- Self-service avatar upload and removal (`Users > Author or User Image` and standard WordPress **Profile** / **Edit User** screens).
- Front-end **Avatar upload** block (`author-or-user-image/avatar-upload`) and `[author_or_user_image_upload]` shortcode with configurable display and label attributes (`showPreview`, `previewSize`, `alignment`, `uploadLabel`, `removeLabel`, `loginLabel`).
- Per-site upload eligibility policy allowing all users who can read or selected roles only, plus per-user block controls and rate limits.
- Center-square `zmai-avatar` renditions stored in the WordPress Media Library, with descriptive metadata stripped by default (retaining ICC color data).
- Site-scoped REST API under `/wp-json/author-or-user-image/v1/` with capability, policy, rate-limit, and multisite membership enforcement.
- Importer for local avatars from Simple Local Avatars, One User Avatar / WP User Avatar, WP User Avatars, and User Profile Picture / Metronet in admin and via WP-CLI (`wp zmai import`).
- Read-only lifecycle lock diagnostics under **Users > Avatar Management > Settings** and exact-token WP-CLI recovery commands (`wp zmai locks`).
- Personal-data export/erasure integration, multisite-safe cleanup on account deletion, and optional opted-in uninstall cleanup.

## REST API (`author-or-user-image/v1`)

| Method & Route | Access | Purpose |
| --- | --- | --- |
| `GET /avatars` | `manage_options` | Paginated list of users with local avatars (`role`, `page`, `per_page`, `size`) |
| `GET /avatars/{user_id}` | Self (`read`) or `manage_options` + `edit_user` | Read a user's local avatar state and upload eligibility |
| `POST /avatars/{user_id}` | Self (policy + rate limit) or `manage_options` + `edit_user` | Upload/replace a user's avatar (`file` or `user_avatar`) |
| `DELETE /avatars/{user_id}` | Self (`read`) or `manage_options` + `edit_user` | Remove a user's local avatar |
| `GET/POST/DELETE /default` | `manage_options` | Read, upload (`file` or `default_avatar`), or remove the site default avatar |
| `GET/POST /blocked`, `DELETE /blocked/{user_id}` | `manage_options` (+ `edit_user` on target) | List, block, or unblock users |
| `POST /bulk` | `manage_options` (+ `edit_user` per target) | Bulk `delete`, `block`, or `unblock` for up to 50 `user_ids` |
| `GET/POST /settings` | `manage_options` | Read or update `upload_policy`, `avatar_size`, and `delete_data_on_uninstall` |

## WP-CLI

```sh
wp zmai import sources [--all] [--format=table|json|csv|yaml]
wp zmai import status [--format=table|json|yaml]
wp zmai import run <source> [--max-batches=<n>] [--format=table|json]
wp zmai import pause|resume|retry|finish [--format=table|json]
wp zmai locks list [--show-tokens] [--format=table|json|csv|yaml]
wp zmai locks unlock <name> --token=<exact-token> --yes
```

## Shortcode & Block Attributes

```text
[author_or_user_image_upload show_preview="true" preview_size="96" alignment="center" upload_label="Upload photo" remove_label="Remove photo" login_label="Sign in to update your avatar."]
```

Only presentation attributes are honored; user/URL/default attributes are ignored.

## Requirements

- WordPress 6.6 or later (tested up to 7.1)
- PHP 7.4 or later (tested through PHP 8.5)
- GD or Imagick PHP extension (with EXIF support for JPEG uploads)

## Development & Verification

- Release validation and packaging: see [docs/release-checklist.md](docs/release-checklist.md) and run:
  ```sh
  python3 tests/test-release-check.py
  python3 tools/check-release.py --tag 3.2.0 --zip-output /tmp/author-or-user-image.zip
  ```
- Disposable local WordPress test suites (PHP unit/integration, HTTP multipart, concurrency, REST, CLI, and Playwright E2E): see [tests/README.md](tests/README.md) and [tests/e2e/README.md](tests/e2e/README.md).
- Lifecycle recovery documentation: [docs/lifecycle-recovery.md](docs/lifecycle-recovery.md).

## License

GPL-2.0-or-later ([LICENSE.txt](LICENSE.txt)).
