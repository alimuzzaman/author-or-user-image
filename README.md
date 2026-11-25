# Author or User Image

[![CI](https://github.com/alimuzzaman/author-or-user-image/actions/workflows/ci.yml/badge.svg)](https://github.com/alimuzzaman/author-or-user-image/actions/workflows/ci.yml)

Use a local avatar or profile picture for registered WordPress users, with a site default and a Gravatar alternative for those accounts.

## Features

- Self-service avatar upload and removal for registered users (`Users > Author or User Image`).
- Front-end **Avatar upload** block (`author-or-user-image/avatar-upload`) and `[author_or_user_image_upload]` shortcode for signed-in visitors.
- Per-site upload eligibility policy allowing all users who can read or selected roles only, plus per-user block controls and rate limits.
- Center-square `zmai-avatar` renditions stored in the WordPress Media Library, with descriptive metadata stripped by default (retaining ICC color data).
- Importer for local avatars from Simple Local Avatars, One User Avatar / WP User Avatar, WP User Avatars, and User Profile Picture / Metronet.
- Personal-data export/erasure integration, multisite-safe cleanup on account deletion, and optional opted-in uninstall cleanup.

## Requirements

- WordPress 6.6 or later (tested up to 7.1)
- PHP 7.4 or later (tested through PHP 8.5)
- GD or Imagick PHP extension (with EXIF support for JPEG uploads)

## Development & Verification

- Release validation and packaging: see [docs/release-checklist.md](docs/release-checklist.md) and run:
  ```sh
  python3 tests/test-release-check.py
  python3 tools/check-release.py --tag 3.1.0 --zip-output /tmp/author-or-user-image.zip
  ```
- Disposable local WordPress test suites (PHP unit/integration, HTTP multipart, concurrency, and Playwright E2E): see [tests/README.md](tests/README.md) and [tests/e2e/README.md](tests/e2e/README.md).
- Lifecycle recovery documentation: [docs/lifecycle-recovery.md](docs/lifecycle-recovery.md).

## License

GPL-2.0-or-later ([LICENSE.txt](LICENSE.txt)).
