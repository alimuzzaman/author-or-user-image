# Local integration checks

These checks mutate users, uploads, plugin options, and sites. Use only disposable
WordPress installations outside the repository. The PHP suites require
`WP_ENVIRONMENT_TYPE=local`, the active plugin, and an empty
`.zmai-test-instance` file in the WordPress root. GD is required for image fixtures.

Run the main suite once on a single site and once on a multisite installation:

```sh
wp eval-file /absolute/plugin/tests/runtime.php --path=/absolute/disposable/wordpress
```

Run the network pagination check on the disposable multisite installation:

```sh
wp eval-file /absolute/plugin/tests/network-cleanup.php --path=/absolute/disposable/network
```

That check creates enough real sites to exceed 100 and exercises opted-in uninstall
on the final site. It preserves the fixture installation for inspection.

The HTTP suite starts a PHP server on an ephemeral loopback port, exercises real
multipart uploads and WordPress form handlers, and terminates the server afterward:

```sh
python3 /absolute/plugin/tests/http-runtime.py --wp-root /absolute/disposable/wordpress --php /absolute/php
```

Its router impersonates synthetic subscriber and administrator accounts, so it
must never be installed on a shared or public site. It checks upload nonces,
subscriber/default-avatar permissions, image validation, replacement cleanup, and
blocked uploads. It does not test WordPress login or browser interaction. The test
accounts use public fixture passwords and contain no real personal data.

The tests directory is excluded from WordPress.org packages through `.distignore`.

Run the standard-library release package unit checks without a WordPress site:

```sh
python3 /absolute/plugin/tests/test-release-check.py
```

Run the independent-worker lifecycle and privacy contention check on a disposable
site with an empty `.zmai-test-instance` marker and a new state directory:

```sh
python3 /absolute/plugin/tests/lifecycle-concurrency.py \
  --site /absolute/disposable/wordpress \
  --plugin /absolute/package-extracted/author-or-user-image \
  --state /absolute/scratch/lifecycle-concurrency-php74 \
  --php /absolute/php-7.4
```

The release matrix and exact-package Plugin Check steps are in
`docs/release-checklist.md`.

Run `wp eval-file /absolute/plugin/tests/migration-race.php --path=/absolute/disposable/wordpress`
on both disposable single-site and multisite installations to exercise newer
uploads and removals while a real legacy import is processing image metadata.

The migration interleaving suite distinguishes rejected busy operations from
completed writes. It retries the public store operation after import completes
and still requires the newer completed intent to win and superseded media to be
removed. Its CLI-only namespaced transport calls core sideload and does not replace the independent
multipart HTTP checks.

Run `wp eval-file /absolute/plugin/tests/lifecycle-locks.php --path=/absolute/disposable/wordpress`
for database uniqueness, exact-token release, owner/site independence, normal
core user deletion and physical-file failure recovery checks. For an isolated
candidate, add `--require=/absolute/plugin/tests/candidate-bootstrap.php` before
the command to skip the installed target and load that candidate. This keeps
fixture plugin symlinks unchanged.

See [lifecycle recovery](../docs/lifecycle-recovery.md) for non-expiring lock
recovery and retry semantics. MySQL and external persistent-cache behavior need
separate validation; SQLite results do not establish either.

Run `wp eval-file /absolute/plugin/tests/migration-progress.php --path=/absolute/disposable/wordpress`
on each fixture for a persistent bad user image, a failing default, later-page
progress, repair retries and a busy owner. A retry can take a complete sweep;
completion requires a sweep with no failed items.

Run `wp eval-file /absolute/plugin/tests/network-user-deletion.php --path=/absolute/disposable/network`
for real network account deletion, administrator-authored migrated avatars on
multiple sites, departed membership, retained unrelated/default images, fence
lifetime and same-username recreation. This fixture requires at least 100
pre-existing sites. On a fresh network, run `network-cleanup.php` first to
provision that disposable population. It intentionally removes opted-in data,
so isolate its fixtures from data needed by another suite. Serialize all suites
using a fixture.


The implementation is split across `Lock`, `Image_Store`, `Migration` and
`User_Cleanup`. Reflection tests target the class that owns each moved method;
production callers use the same store and mutex through the plugin coordinator.

Run `review-regressions.php` on each fixture for bounded eraser retries, retained
files, exact-token mismatch, callback exceptions, pre-write versus post-write
read failures, migration diagnostics and opt-in uninstall of leftover lock rows.
Run `network-delete-failure.php` on multisite for a busy site partway through
network deletion, release of completed fences, retry and untouched-site rows.

Run the shutdown checks on each disposable fixture:

```sh
python3 /absolute/plugin/tests/review-shutdown.py \
  --site /absolute/disposable/wordpress \
  --plugin /absolute/plugin \
  --php /absolute/php
```

The shutdown tests use native PHP WordPress workers for later-plugin exit,
wp_die and fatal-error vetoes, and check state from a separate WP-CLI process.
This avoids WP-CLI's fatal recovery prompt terminating PHP shutdown before later
callbacks can run. The foreign-token case must retain the replacement token.
The lifecycle concurrency suite also pauses removal before deleting row/files,
then rejects a competing upload for both user and default avatars.

Privacy returns `done=false` only for busy results before page five. Storage or
recovery failures, and busy results at the limit, return `done=true` with retained
data and no removal claim. A later explicitly restarted erasure can retry after
recovery. Full release package tests have a separate synthetic secret-path guard;
select only tests compatible with the current task's file-access restrictions.

The shared 3.1 foundation adds three disposable-site suites. Run each on both
single-site and multisite, serialized with every other suite using that site:

```sh
wp --path=/absolute/disposable/wordpress --require=/absolute/plugin/tests/candidate-bootstrap.php eval-file /absolute/plugin/tests/upload-policy.php
wp --path=/absolute/disposable/wordpress --require=/absolute/plugin/tests/candidate-bootstrap.php eval-file /absolute/plugin/tests/image-processing.php
wp --path=/absolute/disposable/wordpress --require=/absolute/plugin/tests/candidate-bootstrap.php eval-file /absolute/plugin/tests/phase1-store.php
```

Policy covers missing/selected/corrupt settings, fresh roles and capabilities,
blocks, checked nonautoloaded persistence and opposite network policies.
Processing uses real asymmetric pixels, JPEG EXIF orientations 1–8, PNG alpha,
static/extended WebP and bounded malformed/animated containers. It runs GD and
Imagick where installed and prints `MISSING` for unavailable backend cells.
To exercise the missing-EXIF branch, run the processing suite with
`ZMAI_IMAGE_PROCESSOR_MODE=missing-exif` and PHP's
`-d disable_functions=exif_read_data`. A missing cell is not a passing result.
Store checks typed import outcomes, source preservation, square metadata/files,
post-commit cleanup retries, rate windows/storage failures and privacy cleanup.

`http-runtime.py` accepts `--scratch /absolute/scratch` for its temporary server
log and upload temp directory. Its policy and sixth-attempt checks use genuine
multipart uploads, with synthetic loopback authentication as before. The
independent-worker lifecycle suite additionally contends on the final available
upload attempt. Test-only resets separate synthetic scenarios; they do not
change or disable production rate limits. Neither harness proves real login or
the phase 2 front-end forms. See [shared contracts](../docs/phase1-contracts.md)
for the APIs the two feature worktrees can use.
