# 3.1.0 release validation

Validation date: 2026-11-05. Candidate branch: `codex/avatar-migration-design`,
based on `cb5c369c547699adde069a1134095a0280a4e66f`.

The release ZIP contains exactly 24 production files. Its SHA-256 is:

```text
1a8e9e9750a6322e504b71149cc7af25ff8cd1d58222109a1a32635de8861f93
```

The package was built from a snapshot of the tracked working files. Unrelated
untracked `.DS_Store` files were preserved and excluded. The extracted package,
snapshot and production working files were compared byte for byte.

## Results

| Gate | Observed result |
| --- | --- |
| PHP syntax, 7.4 and 8.0 through 8.5 | 16 production PHP files per runtime; 112 passes |
| PHPCompatibilityWP, `testVersion 7.4-8.5` | 16 files; zero errors, warnings or findings |
| Production WordPress coding standards | 16 PHP files; zero errors or warnings |
| Release package unit tests | 8 passed |
| Import controls JavaScript tests | 8 passed |
| Fresh SQLite compatibility matrix | WordPress 6.6 and 7.1.3, seven PHP versions, single-site and multisite; 28/28 cells passed |
| SQLite multipart and lifecycle contention | 14 single-site PHP/WordPress pairs; 294 HTTP and 420 lifecycle assertions passed |
| MySQL 8.0.27, WordPress 7.1.3 | Seven PHP versions, single-site and multisite; 455 commands passed, zero failed or missing |
| MySQL 8.0.27, WordPress 6.6 | 14 fresh native cells passed with 2,863 checks; seven single-site HTTP cells passed with 147 assertions |
| Official Plugin Check 2.1.0 | Exact ZIP, runtime bootstrap, update mode and experimental checks; zero errors or warnings |
| Exact-package public/admin integration | 9 and 16 checks passed |
| Importer admin regression suite | 29 passed |
| Functional Chromium browser suite | 22 passed; zero failures or skips |
| Screenshot harness | Passed; seven captures |
| Terra visual review | Passed for all seven desktop captures |
| Independent Sol source review | No remaining actionable findings |

The fresh SQLite matrix ran integration, runtime and migration interleaving
checks in every cell. Multisite cells also exercised cleanup over at least 101
sites and repeated migration afterward. It recorded 112 passing test entries
and 1,778 passing assertions. Separate checks on the existing matrix also
exercised source detection and importer administration across all 28 cells.

The multipart and lifecycle drivers support single-site fixtures. Their results
do not claim multisite HTTP coverage. Every recorded driver exit code was zero;
the detached outer batch exit code was unavailable after completion.

PHPCompatibilityWP was installed only in scratch with Composer scripts and
plugins disabled and an isolated Composer home. The scan used
PHPCompatibilityWP 3.0.0-alpha2, PHPCompatibility 10.0.0-alpha2,
PHPCompatibilityParagonie 2.0.0-alpha2 and PHP_CodeSniffer 4.0.4.

The WordPress 7.1.3 MySQL suites used the preceding frozen package snapshot.
Archive comparison confirmed that its only difference from the final package
is `readme.txt`; all executable and asset bytes are identical.
That matrix recorded 21,252 passing entries and 42 passing isolated cache
scenarios.

## Corrections and retained failures

- Source detection previously required a capabilities row on single-site as
  well as multisite. The detector now follows the import worker's membership
  rules and excludes orphan metadata. Its new regression failed before the
  correction and passed afterward.
- The release test fixture now resolves its temporary path before comparing it
  with the validator's resolved path. This preserves the test that rejects an
  injected `.env` entry without opening an environment file.
- Browser security tests now take the nonce from the upload form explicitly.
  The redesigned screen places the removal form first. The assertions still
  require rejection for the intended permission reason.
- Screenshot assertions now require the actual management tab and detected
  WP User Avatars action, and require the source selector to be absent.
- Two reused SQLite fixtures failed legacy runtime assertions. Those failures
  did not reproduce on fresh installations with the same PHP/WordPress pairs
  and exact final package. The release matrix uses the fresh installations;
  the failed reused runs remain in the evidence directory.
- The multipart canary initially lacked its PHP wrapper files. That setup
  attempt is retained separately; the corrected canary and all 14 pairs passed.
- An initial MySQL 6.6 runner incorrectly substituted the single-site HTTP
  driver for `runtime.php`, including on multisite. The corrected native matrix
  passed on 14 new installations. Separate single-site HTTP checks passed on
  all seven PHP versions using the Herd fixture's required `pcre.jit=0`
  wrappers. The original JSON-response failures remain in `mysql66/`.

## Preview and release boundaries

The existing temporary xcloud-london site was updated from the exact package.
Hosting revision `e268ad0ffef1374af0459effbe9fbc5b2ca45ed5` was observed in the
runtime, with healthy application/database services and a ready public edge.

The [WordPress.org API](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=author-or-user-image) still reported published version `3.0.0` during this
validation. No GitHub push or merge, tag creation, workflow dispatch or
WordPress.org publication was performed by this release-preparation task.

Visual acceptance covers the captured desktop states. It does not establish
mobile layout or a full accessibility audit. The release screenshots were
updated only after the seven captures passed Terra review.

## Evidence

The local evidence root is
`/Users/alim/Sites/git/.scratch/author-or-user-image/release-nov05/`.
It contains:

- `php-checks/report.txt` and the final `author-or-user-image-3.1.0-release.zip`.
- `phpcompat-tool/report.txt`, package lock and machine-readable scan output.
- `pcp/report.txt` and `pcp/logs-v3/` for exact-package checks and restoration.
- `runtime-fresh/report.txt`, `results.json`, snapshots and complete stage logs.
- `runtime-fresh/supplemental/report.txt` and per-driver logs.
- `mysql/report.txt` and `mysql66-native/final-report.txt` for the SQL gates.
- `mysql66-native/http/` for the additional MySQL multipart checks.
- `mysql66/` for the superseded HTTP-substitution attempt.
- `browser/report.txt`, successful run logs and the screenshot gallery.
- `terra-visual-review.txt`, `final-review.txt` and `screenshot-manifest.json`.
- `deployment-status.json`, `wordpress-org-version.json` and `decisions.tsv`.

The ZIP, reports, traces, synthetic fixture data and test tools are outside the
plugin package. Publishing requires a separate human instruction.
