# Shared 3.1 contracts

## Review fixes for 3.1.0

Square saves pin their own input MIME for that save only. Deterministic editor
and file failures clean staged files and release owner locks. Recovery fences
are reserved for failed database reconciliation or an unverifiable committed
mapping; ordinary image validation failures do not fence an owner.
Rendering uses attachment square metadata and the version marker, without local
filesystem checks, and caches decisions within the request. Missing square
metadata uses the historical attachment URL fallback. Write-time square
validation remains strict.

Role definitions come from `wp_roles()`, including its global and option filters.
Rendering must not invalidate the global user-query cache. Early no-cache
headers apply only to queried content containing the upload block or shortcode;
rendered template/widget forms also define `DONOTCACHEPAGE`.

Import jobs retain schema 1 and accept an optional `skips` list of at most 100
`{user_id:int,reason:string}` records. New jobs include that list and a `skipped`
count. Deterministic source/validation failures are permanent skips; only busy
locks and persistence failures request another sweep. Paused jobs may be
finished through an authenticated, operation-nonce-protected `finish` action,
which preserves counts and skips and permits a new source. Recovery-required
jobs remain fenced. Main-site imports reject the multisite `sites/` subtree.
No source path or URL is persisted in skip records.

Baseline: `feature/3.1-avatar-features`, `fdf263eb1dde850359aeb73862b7f50b49545176`.
Version stays 3.0.1 until final integration. Candidate loading uses
`tests/candidate-bootstrap.php`, without replacing fixture plugin symlinks.

## Upload policy

`Upload_Policy::__construct(?Image_Store $store = null)` shares authoritative
reads with the owner lock. `get_policy()` returns `{mode:string,roles:string[]}`;
corrupt storage returns mode `invalid`. `can_upload($user_id)` and
`can_upload_fresh($user_id)` return bool. `save_policy($input)` accepts exactly
`{mode:'legacy'|'roles',roles:string[]}` and returns true or WP_Error. Persisted
`zmai_upload_policy` adds `schema:1`, is site-local and nonautoloaded. Absence is
legacy and causes no upgrade write. The settings handler owns POST,
manage_options and zmai_save_settings nonce checks.

| State | Legacy | Selected roles |
| --- | --- | --- |
| Missing/deleted user or no read | deny | deny |
| Individually blocked | deny | deny |
| Read, member, matching role | allow | allow |
| Read, member, no matching role | allow | deny |
| Read, nonmember or super administrator without matching membership | allow | deny |
| Explicit empty role list | n/a | deny |
| Invalid persisted policy | deny | deny |

Multiple roles qualify on any match. Removed roles are inert; recreating a slug
restores its match. Role denial affects uploads only, not display or removal.
Imports ignore role eligibility but honor block and newer explicit choices.

## Local images and store

### New-file metadata contract

`Image_Metadata::sanitize($path,$name,$context)` returns a fresh validated
image descriptor with `metadata_stripped:bool`, compatible `metadata_icc` records
and `metadata_channels:int`, or `WP_Error`. The file path stays the same.
The caller owns a private staging file and
its cleanup. Validation, size/dimension limits and upload-rate checks precede
pixel decoding. HTTP callers first require `is_uploaded_file()`, retain the
same PHP upload-temp path and core provenance check, and update its reported
size after a checked complete write.

The PHP-only filter `zmai_strip_image_metadata` receives default `true` and
`{owner:int,source:'upload'|'default'|'import'|'legacy'}`. A false value opts out
only for that operation, after validation. Request fields and competitor data
cannot control it. It never changes `image_strip_meta` or other uploads.

Default processing applies JPEG, PNG eXIf and WebP EXIF orientation 2-8
to pixels once, including mirrored orientations, then parses
and removes descriptive metadata before any media handler sees the file.
Structured JPEG/PNG/WebP parsing rejects malformed bounds and verifies its
own output. Only rendering structures, alpha and ICC color data survive.
RGB ICC data survives orientation re-encoding in all supported formats; GD rejects CMYK,
including PHP opt-out, rather than attaching a CMYK profile to RGB pixels. Sanitizer failures
return typed errors and cannot replace an existing mapping. Imports and legacy
migration sanitize only private copies, leaving source files and meta intact.
Legacy sideload returns bool|WP_Error; its sweep requires strict true so a typed
error cannot count as success. Deterministic GD CMYK is an importer permanent
skip (`metadata_cmyk`); generic sanitizer processing failures remain retryable.
Before committing a new mapping, all recorded originals, sub-sizes, squares and
backups are re-parsed to remove encoder-added comments and restore compatible
ICC color data; stored filesize metadata is reconciled. Opt-out applies to this
whole operation and is evaluated only on its incoming staging file.

Attachment-ID import sources require the subject as author, or an author with
`edit_user` for the subject. URL-only sources require the same attachment
ownership or a verified competitor-owned storage convention; otherwise the
permanent skip reason is `not_owned`. SLA URLs must map to an authorized
attachment because its upload handler creates one. WPUA direct uploads may use
`avatar_user_ID_timestamp[_collision].ext` at the uploads root or YYYY/MM;
protected subdirectories and another subject ID do not qualify. OUA and
Metronet use attachment IDs only. Skip records stay bounded and contain
no source path. Administrator-assigned media remains supported.

`Image_Processor::validate_local_image($path,$name)` returns
`{path:string,name:string,type:string,width:int,height:int,filesize:int}` or
WP_Error. `create_square($path,$size)` returns the core saved-file descriptor
`{path:string,file:string,width:int,height:int,'mime-type':string,filesize:int}`
or WP_Error. Only readable local regular files, JPEG/PNG/static WebP, at most
min(site upload limit,8 MiB), and 4096 pixels per side qualify. GIF, SVG, AVIF,
APNG and animated WebP are unsupported. JPEG requires core EXIF support.

`Image_Store::save_upload($field,$user_id = 0)` remains int|WP_Error. Core must
still verify HTTP upload provenance. Policy and checked owner-scoped rate writes
precede decoding under the owner lock. Rate record `_zmai_avatar_upload_rate`
contains only minute_start, minute_count, hour_start, hour_count, with limits
5/minute and 20/hour per site on multisite. Reversed clocks do not reset windows. Default owner has no
user rate counter and requires manage_options. Removal is never rate limited.

`Image_Store::import_local_image($resolve_source,$user_id)` invokes a callable
under its own owner lock. Resolver returns `{path:string,name:string}` or
WP_Error. The path must resolve inside current uploads. The store copies it,
checks source identity, sideloads a new owned attachment, checks its square and
writes only MIGRATED_USER_META. Return is
`{status:'imported'|'already_present'|'explicit_choice'|'blocked'|'gone',attachment_id:int}`
or WP_Error. Present USER_META, including zero or invalid IDs, suppresses import.
No source adapter, batch UI or permission endpoint is part of this phase.

New squares use metadata sizes['zmai-avatar'] and attachment marker
`_zmai_avatar_square_version=1`. Write-time inventory checks remain strict.
Rendering uses metadata, so offload and recompression do not hide an avatar;
missing square metadata and existing unmarked media use 3.0.x URL behavior.
Store owns staging inventory and rollback; unconfirmed database state retains
the owner fence. Cleanup lists literal directories and matches only the generated
UUID stem and image extensions/subsizes. A failed listing retains cleanup
inventory and the fence; an exception without a recognized staged file does too.

`Migration::with_migration_lock($callback)` returns callback result or WP_Error,
uses the existing migration mutex and reports exact-token release failures.
Lock order is migration then owner, or blocks then owner. Never reverse it.

## Reserved phase 2 schemas

Import job option `zmai_import_job` is nonautoloaded, missing means idle. Its
schema is `{schema:1,id:string,source:string,site_id:int,state:string,
last_user_id:int,upper_user_id:int,sweep:int,revision:int,retry_needed:bool,
counts:{inspected:int,imported:int,existing:int,suppressed:int,blocked:int,
no_source:int,other_site:int,failed:int},last_error_code:string}`.
Sources are simple-local-avatars, one-user-avatar, wp-user-avatars and
metronet-profile-picture. Jobs may retain the bounded skip list described above;
no paths or URLs are stored.
Import requests use fixed Start/Resume/Batch/Pause/Retry/Finish operations, POST,
manage_options, operation nonce and job ID/revision. No arbitrary paths, class
names, owner IDs or batch size from request input. Phase 2 implements transitions.

Front forms reuse zmai_save_avatar/zmai_remove_avatar and their nonce actions,
derive the current owner, and submit avatar_type=user. No owner attribute or
new REST mutation route. Phase 2A owns safe return URLs and 303 responses.

Source fixtures for phase 2B must cover SLA full/media_id/blog_id and shared
keys, prefixed One User Avatar attachment IDs and explicit empty rows, WP User
Avatars full/media_id/site_id, and Metronet prefixed profile-post thumbnail with
post type/author checks. Source reports in the approved scratch plan are the
evidence; no source plugin is loaded or mutated.

Rate data is exported and erased under the owner lock. Opted-in uninstall
removes policy/job/rate data and owned square files; opted-out data survives.
Role changes are rechecked at operation and commit time, without promising an
atomic transaction with external capability writers. Originals retain uncropped
pixels; new files strip descriptive metadata by default. Previously uploaded
originals retain their metadata until re-uploaded. No automatic backfill runs.
Center crop is not privacy redaction.
