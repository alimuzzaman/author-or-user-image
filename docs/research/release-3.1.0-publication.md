# 3.1.0 publication

Released to WordPress.org on 2026-11-11 in SVN revision **3796032**, from source
commit `d6d6a412cdf7d3509750cf03f39773e802324f92`.

- [Plugin listing](https://wordpress.org/plugins/author-or-user-image/)
- [Public download](https://downloads.wordpress.org/plugin/author-or-user-image.3.1.0.zip)
- [Published SVN tag](https://plugins.svn.wordpress.org/author-or-user-image/tags/3.1.0/)

## Fresh acceptance

The current stable WordPress API reported **7.1.4**. The candidate passed all
14 single-site/multisite combinations of that release with PHP 7.4 and 8.0
through 8.5. The current browser run passed 22 cases without failures, skips or
retries. Official Plugin Check **2.2.0** passed with runtime bootstrap, update
mode and experimental checks enabled; its clean output reported no errors.

An independent Sol review compared the entire production package against the
published 3.0.0 release. It found no executable release blockers. Its readme
finding was corrected to describe WordPress's attachment fallback accurately.
The corresponding PHP documentation comment was corrected without changing
executable tokens. The importer source test now loads the active candidate's
class instead of loading a second checkout copy.

## Public verification

At **2026-11-11 10:02:53 UTC**, the WordPress.org API reported version `3.1.0`.
The generated public ZIP contained exactly 24 files. After normalizing its
plugin-directory prefix, every file matched the validated release byte for
byte.

Validated flat ZIP SHA-256:

```text
b930856c9719dc664d243e03a7f3b3ffbbc3c18b6a7d3a15d5f6da06f33d4c55
```

WordPress.org ZIP SHA-256:

```text
1f6bd74945e892763359634ec091061cf4fecd2acbbe7f909e0a6da50c372250
```

The ZIP hashes differ because WordPress.org generates its own archive. The
24 member-file contents are identical.

Publication used one atomic WordPress.org SVN commit for trunk, `tags/3.1.0`
and the 11 reviewed PNG assets. Credentials were supplied through the Sandbox
secret broker; no password was passed in command arguments or cached by SVN.
No Git tag, GitHub Actions invocation or GitHub push was performed.

## Local receipts

Receipts are under the shared scratch root's
`author-or-user-image/release-nov11/` directory:

- `review.txt` and `package-v2/report.txt`.
- `runtime/followup/report.txt` and its composed per-cell results.
- `pcp/report.txt` and `pcp/final/`.
- `browser/final/report.txt` and `browser/release-gate.json`.
- `publish/publication.json`, `public-svn-info.xml`, `public-status.json`,
  `release-report.txt` and the downloaded public ZIP.

Initial fixture and collection errors remain in the receipts. The final
passing records and public-download comparison support this publication.
