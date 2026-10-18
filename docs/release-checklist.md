# Release checklist

The release is `3.1.0`. The tag, PHP plugin header, `ZMAI_VERSION`, and readme
Stable tag must all match. The minimums remain WordPress 6.6 and PHP 7.4. The
readme declares WordPress 7.1 as its tested series; runtime fixtures use 7.1.3.

## Build and inspect the package

Choose a new ZIP path outside the plugin worktree. The checker rejects an
existing output file, an output inside the worktree, a `v`-prefixed tag, version
mismatches, missing production files, unexpected distributable paths, and any
`.distignore` entry outside its explicit development-path list.

```sh
PLUGIN_DIR=/absolute/path/to/author-or-user-image
PACKAGE_ZIP=/absolute/path/outside/worktree/author-or-user-image-3.1.0.zip
python3 "$PLUGIN_DIR/tools/check-release.py" \
  --tag 3.1.0 \
  --zip-output "$PACKAGE_ZIP"
```

The command prints the SHA-256 digest and the 23 production members. Confirm
that the archive contains those members only and retains no development files.
The package check and PHP syntax checks run before the existing WordPress.org
deploy action receives its SVN credentials.

The manifest includes the upload block's `block.json`, editor script and scoped
front-end stylesheet, the importer script and all runtime classes. It excludes
`tests/`, `tools/`, `docs/` and `.wordpress-org/`, including the screenshot sources.
The workflow recursively lints production PHP, including future PHP below `blocks/`.

## Run Plugin Check on the exact ZIP

Use a disposable local site with Plugin Check 2.1.0 installed. Pass the ZIP path as the
plugin argument so the checker reads the package that will be deployed. Load the
Plugin Check CLI file through WP-CLI's early `--require` option to include its
runtime checks:

```sh
WP_ROOT=/absolute/path/to/disposable/wordpress
PCP_CLI="$WP_ROOT/wp-content/plugins/plugin-check/cli.php"
wp plugin check "$PACKAGE_ZIP" \
  --require="$PCP_CLI" \
  --format=table \
  --path="$WP_ROOT"
```

Review every result. Record warnings and failures with the exact package digest.
Do not treat a clean static scan as runtime coverage.

## Check PHP and WordPress compatibility

Use a fresh disposable WordPress installation for each PHP/WordPress pair below.
Repeat the package install and runtime checks with PHP 7.4, 8.0, 8.1, 8.2, 8.3,
8.4, and 8.5 against WordPress 6.6 and 7.1.3. Keep each PHP executable and site
root explicit:

```sh
PHP_BIN=/absolute/path/to/php-7.4
WP_CLI_PHAR=/absolute/path/to/wp-cli.phar
WP_ROOT=/absolute/path/to/disposable/wordpress-6.6-php74
PLUGIN_DIR=/absolute/path/to/author-or-user-image
PACKAGE_ZIP=/absolute/path/outside/worktree/author-or-user-image-3.1.0.zip

"$PHP_BIN" "$WP_CLI_PHAR" core version --path="$WP_ROOT"
mkdir -p "$WP_ROOT/wp-content/plugins/author-or-user-image"
unzip -q "$PACKAGE_ZIP" -d "$WP_ROOT/wp-content/plugins/author-or-user-image"
"$PHP_BIN" "$WP_CLI_PHAR" plugin activate author-or-user-image/author_image.php --path="$WP_ROOT"
"$PHP_BIN" "$WP_CLI_PHAR" eval-file "$PLUGIN_DIR/tests/runtime.php" --path="$WP_ROOT"
"$PHP_BIN" "$WP_CLI_PHAR" eval-file "$PLUGIN_DIR/tests/migration-race.php" --path="$WP_ROOT"
```

For each multisite installation, also run:

```sh
"$PHP_BIN" "$WP_CLI_PHAR" eval-file "$PLUGIN_DIR/tests/network-cleanup.php" --path="$WP_ROOT"
"$PHP_BIN" "$WP_CLI_PHAR" eval-file "$PLUGIN_DIR/tests/migration-race.php" --path="$WP_ROOT"
```

The runtime suites require the disposable-site safeguards and image support
described in `tests/README.md`. The HTTP suite exercises real multipart requests:

```sh
python3 "$PLUGIN_DIR/tests/http-runtime.py" --wp-root "$WP_ROOT" --php "$PHP_BIN"
```

Run lifecycle contention and privacy-erasure retries with two PHP workers. Give
each run a new scratch state directory:

```sh
python3 "$PLUGIN_DIR/tests/lifecycle-concurrency.py" \
  --site "$WP_ROOT" \
  --plugin "$WP_ROOT/wp-content/plugins/author-or-user-image" \
  --state /absolute/scratch/lifecycle-concurrency-php74-wp66 \
  --php "$PHP_BIN"
```

Repeat these checks across the complete PHP and WordPress matrix. Record the PHP
version, WordPress version, package SHA-256, command, and result for every pair.
The release workflow does not run these WordPress runtime suites.

## Review the 3.1 behavior

Run the full SQLite and MySQL single-site/multisite matrices, production WPCS,
PHP syntax checks on 7.4 through 8.5 and JavaScript syntax checks. Run every
Playwright suite on the marked wp713 fixture, including the front-end and
importer specs. Generate the screenshot gallery through that harness and inspect
its images before copying final PNGs to `.wordpress-org/`.

Human review before release covers the upload role policy and fresh permission
checks, front-end admin-post handlers and redirect/nonce boundaries, importer
capabilities and purpose/job-bound nonces, local source path resolution and
source-data preservation. Exclude pages with personalized upload forms from page
caches and allow authenticated POSTs to `wp-admin/admin-post.php`.

## Publish

A human reviews the package digest and recorded results, then separately
authorizes publishing. Pushing the bare `3.1.0` tag starts the existing deploy
workflow. The checker does not create or push tags, and it does not publish by
itself.

The readme `Tested up to` field uses `7.1` as required by Plugin Check; runtime fixtures remain WordPress 7.1.3.
