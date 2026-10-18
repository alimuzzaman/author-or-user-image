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
5/minute and 20/hour. Reversed clocks do not reset windows. Default owner has no
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
pixels and original metadata. Center crop is not privacy redaction.
