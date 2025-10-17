# Author or User Image - WordPress Plugin

A modernized WordPress plugin for managing author and user profile images with admin controls.

[![CI](https://github.com/alimuzzaman/author-or-user-image/actions/workflows/ci.yml/badge.svg)](https://github.com/alimuzzaman/author-or-user-image/actions/workflows/ci.yml)

## Features

- Set default image for users without custom avatars
- Individual user image management
- Configurable image sizing with automatic resizing
- Admin controls for user image management
- Blacklist functionality to prevent specific users from setting images
- User listing by role
- REST API support for programmatic access
- Legacy AJAX compatibility

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher
- Composer (for development)

## Installation

### For Users

1. Download the plugin
2. Upload to `/wp-content/plugins/` directory
3. Activate through the WordPress admin panel
4. Configure under Users → Author Image

### For Developers

```bash
# Clone the repository
git clone https://github.com/alimuzzaman/author-or-user-image.git
cd author-or-user-image

# Install dependencies
composer install

# Run tests
composer test

# Check code style
composer phpcs
```

## Architecture

The plugin has been modernized with:

- **PSR-4 Autoloading**: All classes under `AuthorImage\` namespace
- **Service Layer**: Business logic separated into services
  - `FileManager` - File operations
  - `OptionStore` - WordPress options management
- **REST API**: Full REST API under `/wp-json/author-image/v1/`
- **Legacy Compatibility**: Original AJAX endpoints maintained
- **Comprehensive Testing**: PHPUnit test coverage
- **CI/CD**: GitHub Actions workflow

### Directory Structure

```
author-or-user-image/
├── src/                    # PSR-4 autoloaded source
│   ├── Admin/             # Admin interface
│   ├── Ajax/              # Legacy AJAX handlers
│   ├── REST/              # REST API controllers
│   └── Service/           # Business logic
├── tests/                 # PHPUnit tests
├── legacy/                # Legacy PHP files (archived)
├── author_image.php       # Main plugin file
├── composer.json          # Dependencies
└── readme.txt             # WordPress.org readme
```

## REST API

All endpoints require administrator capabilities.

### Images

- `GET /wp-json/author-image/v1/images` - List all images
- `GET /wp-json/author-image/v1/images/{login}` - Get specific image
- `DELETE /wp-json/author-image/v1/images/{login}` - Delete image

### Banned Users

- `GET /wp-json/author-image/v1/banned` - List banned users
- `POST /wp-json/author-image/v1/banned` - Ban a user
- `DELETE /wp-json/author-image/v1/banned/{login}` - Unban user

### Bulk Operations

- `POST /wp-json/author-image/v1/bulk` - Perform bulk actions

Example:
```json
{
  "action": "delete",
  "logins": ["user1", "user2"]
}
```

## Development

### Running Tests

```bash
# All tests
composer test

# With coverage
./vendor/bin/phpunit --coverage-html coverage/
```

### Code Style

The project follows PSR-12 coding standards.

```bash
# Check code style
composer phpcs

# Auto-fix issues
composer phpcbf
```

### Continuous Integration

GitHub Actions runs on every push:
- PHP 7.4, 8.0, 8.1, 8.2 compatibility
- PHPUnit tests
- Code style checks

## Data Storage

The plugin maintains backward compatibility:

- **Option Keys**:
  - `zm_ai_ban_id` - Banned users list
  - `author_image_size` - Image dimensions
  - `ai_notification` - Notification status

- **File Storage**:
  - `wp-content/uploads/author-image/{login}.jpg` - User images
  - `wp-content/uploads/author-image/author_default.jpg` - Default image

## Contributing

Contributions are welcome! Please ensure:

1. All tests pass: `composer test`
2. Code follows PSR-12: `composer phpcs`
3. Add tests for new features
4. Update documentation

## License

GPLv2 or later

## Author

**Alimuzzaman Alim**
- Website: [https://alim.dev](https://alim.dev)
- GitHub: [@alimuzzaman](https://github.com/alimuzzaman)

## Changelog

### 2.0.2 - Modernization Release

- Refactored to PSR-4 architecture
- Added REST API support
- Implemented comprehensive testing
- Added CI/CD with GitHub Actions
- Maintained full backward compatibility
- Improved code quality with PSR-12 standards
