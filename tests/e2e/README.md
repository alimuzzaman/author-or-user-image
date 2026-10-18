# Browser tests and WordPress.org screenshots

This private Playwright package exercises real WordPress login, plugin forms,
admin notices, profile/admin-bar images, and front-end `get_avatar()` output.
The plugin's upload screen is a profile submenu, not an upload field embedded
in the core profile form. All tests run serially with one Chromium worker.

`/tests` is excluded by the root `.distignore`. Never include this directory,
its dependencies, fixture credentials, traces or reports in the plugin ZIP.

## Prepared local fixture

Use only the marked disposable `compat-sites/wp713` site for this task.
It has synthetic users `mayachen` and `morganreed`, with subscriber and
administrator roles. Their public fixture password is
`Local-avatar-demo-2026!`. `E2E_PASSWORD` can override it for a separately
prepared local fixture. Never use this password on another service.

The fixture setup and checks are recorded in the sibling scratch
`e2e-artifacts/fixture-setup.txt`. `fixture.json` must contain `post.id` and
`subscriber.id`. The published fixture post uses `[aoui_e2e_author]`, implemented
by a local-only MU plugin in the disposable site. Its `.aoui-e2e-author-card img`
is actual WordPress `get_avatar()` output, not injected browser markup.
The shortcode and mail/network guards are test infrastructure, not plugin code.
The three PNG inputs in `fixtures/` are synthetic illustrations.

```sh
cd /Users/alim/Sites/git/.scratch/author-or-user-image/security-oct18/fix-candidate/tests/e2e
npm ci --ignore-scripts --no-audit --no-fund
export E2E_WP_PATH=/Users/alim/Sites/git/.scratch/author-or-user-image/security-oct18/compat-sites/wp713
export E2E_ARTIFACT_DIR=/Users/alim/Sites/git/.scratch/author-or-user-image/security-oct18/e2e-artifacts
export PLAYWRIGHT_BROWSERS_PATH="$HOME/Library/Caches/ms-playwright"
npm run test:local
npm run screenshots:local
```

The local runner checks the disposable marker and local environment, selects a
free loopback port, sets only the fixture home/site URLs, starts Herd PHP,
then terminates the server and restores the URLs in `finally` and signal
handlers. The built-in PHP document-root router serves real WordPress pages.
The existing HTTP API test router would impersonate users and return JSON,
so it cannot serve a browser login test. PHP upload/post limits are explicitly
12/16 MiB so the oversized upload reaches the plugin's 8 MiB validation.
There is a small bind race between free-port selection and PHP startup;
startup fails explicitly if the chosen port becomes unavailable.

JSON results, failure PNGs and traces go outside the repository under
`E2E_ARTIFACT_DIR`. Traces contain disposable fixture login data. Run captures
one at a time; screenshot execution overwrites `results.json`, so archive the
functional run's report first. The screenshot test creates only
`.wordpress-org/screenshot-1.png` through `screenshot-5.png`.

## Existing server or fixed candidate

To use an already-running disposable server:

```sh
E2E_BASE_URL=http://127.0.0.1:18973 npm test
```

Point that server's active plugin at the candidate and prepare the same fixture
before claiming candidate validation. The local runner does not change the
plugin symlink or PHP source. Record the exact active plugin source/version
with each run. This task's wp713 symlink initially targets the original checkout.

Tests deliberately fail on missing notices, broken avatar image loads, rejected
valid uploads, accepted unauthorized operations and broken fallback behavior.
They do not mock plugin handlers, bypass authentication, or retry failures.
A blocked-upload assertion reuses the subscriber's own pre-block session nonce
to distinguish capability enforcement from an expired/mismatched nonce.

## Scope

Chromium desktop, single-site WordPress 7.1.3, SQLite, Herd PHP. No multisite,
MySQL, mobile, accessibility audit, concurrency, network deletion or release
packaging assertion is covered here. External Gravatar delivery is blocked by
the local fixture; default-removal asserts its URL, not remote image loading.
Screenshot capture uses the real admin/theme UI without CSS replacement.

The successful baseline used Playwright 1.64.1 and cached Chromium revision
1248. The login helper waits for core's delayed autofocus before filling inputs;
avatar assertions scroll lazy images into view and require decoded pixels.
Browser requests to Gravatar are aborted so default-fallback checks stay local.

Screenshots retain Maya's and Morgan's demo images and the site default avatar.
The gallery shows the pending block bulk action without submitting it. The
subscriber remains unblocked. Fixture presentation cleanup drafted the older
local test posts and removed older `zmai-*` runtime avatar images via the plugin
API; accounts and unrelated media remain. The local MU helper removes only the
unrelated SQLite diagnostic toolbar badge. No plugin UI CSS is replaced.

A forced task/process kill can bypass JavaScript cleanup. `runner-recovery.json`
records the public prior home/site URLs before mutation; restore those with
WP-CLI if needed. Normal success/failure and handled signals stop PHP and
restore both URLs independently. Browser workers may require separate shutdown
after abrupt cancellation; verify the runner's loopback port is closed.
