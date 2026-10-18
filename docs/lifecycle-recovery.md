# Avatar lifecycle locks and recovery

Avatar uploads, imports, replacements and removals use a database option lock for
one owner in one site's Media Library. The options table supplies site scope.
`zmai_avatar_lock_user_<ID>` and `zmai_avatar_lock_default` identify distinct
owners. Operations changing block state acquire `zmai_avatar_lock_blocks` first,
then the owner lock, so the shared rollback block list cannot lose another
user's change. A normal WordPress `delete_user` keeps its owner lock through
`deleted_user`. Network deletion must fence every existing site, including
departed memberships, remove exact-owner media and block data, then remove the
shared username original before core discards the identity. A busy or failed
cleanup must abort account deletion and remain retryable. Successful fences release from `deleted_user`, after an aborted cleanup with
known state, or from a registered PHP shutdown callback when a later plugin
vetoes deletion with exit, wp_die or a fatal error. Shutdown uses the captured
table and exact token and releases only this request's successful fences.
Uncertain in-flight operations are never included in that release list.

Acquisition uses a plain INSERT into the unique option_name column and requires
exactly one inserted row. Release requires the captured table, option name and
byte-exact random token. No cache, timer, lease expiry or stale-lock takeover
confers ownership. A busy/error response means the operation needs retry; it
must not be treated as a completed removal or erasure.

Privacy retries repeat the same cleanup only for a transient `zmai_busy` result,
for at most five pages. A leftover lock cannot be distinguished from a live
worker, so page five ends with `done=true` and a retained-data message.
Storage and recovery errors stop immediately with `done=true`. All failures
return `items_removed=false`, `items_retained=true` and an error message.
Some files may already have been removed before a later failure. The response
does not claim complete removal. A deletion veto that leaves its attachment row
can be retried normally. On multisite a successful site-local erase may retain
and report the shared legacy original, as before.

## Operator recovery

A crashed worker, an unexpected exception, failed exact-token release, or core
removing an attachment row while leaving its image file requires manual
reconciliation. Such failures leave the non-expiring lock in place. There is
no public reset endpoint. A privacy failure can retain both the owner lock and
the shared block-list lock if its state is uncertain.

1. Quiesce relevant plugin writers and establish that the original worker has
   ended. A timestamp or elapsed time does not prove this.
2. Identify the site's actual options table and affected owner. Inspect that
   owner's mappings, exact `_zmai_avatar_owner` attachment markers and files.
   Reconcile incomplete media creation and deletion, including thumbnails,
   originals, imported fallback and legacy files. Keep other owners and sites.
3. If core removed a row but retained files, recover the missing inventory from
   available filesystem records/backups and reconcile it before unlocking.
   The lock identifies an owner, not a complete file journal.
4. Remove only the captured lock row with a conditional match on its current
   name and exact token, after reconciliation. Handle any retained block-list
   lock under the same quiescence. Invalidate affected WordPress option caches.
5. Retry the failed operation and verify its terminal result and owned files.

WordPress media/file operations are not transactional. The lock prevents new
cooperative mutations during recovery; it does not roll back a crashed request.
There is a core creation interval before owner metadata exists. This protocol
also does not fence arbitrary plugins, administrator database writes, other
code manipulating media, or live uninstall. Uninstall requires quiescing
writers first. Never clear a lock merely because it looks old.

SQLite fixture tests cover the exercised interleavings and token comparisons.
MySQL and SQLite test results are recorded in the implementation handoff.
External persistent-cache behavior remains unvalidated.

## Migration progress contract

Each image page inspects at most 20 users and the default image. Failed imports and
busy owners advance with the page but keep the sweep incomplete. A single
boolean retry flag remembers earlier failed pages; the final page restarts the
sweep when needed. Only a completely successful sweep records the data version.
`zmai_avatar_lock_migration` serializes the page cursor and sweep failure flag,
so overlapping administrators cannot clear a failure and falsely finish a sweep.
This lock also requires operator recovery after process death. A migration read
exception is caught so administrator pages remain usable. Read/release failures
write a fixed diagnostic to the PHP error log and emit `zmai_migration_error`; a
failed release still requires recovery. A persistent, non-dismissable notice is shown to users with `manage_options` until a later migration check confirms the migration fence is absent. Post-deletion release failures are logged without aborting the remaining bulk deletions. Known decision-read errors before any
owner mutation release the owner lock. Read failures after mutation retain it.
No failed owner is marked migrated; existing mappings and legacy files retain
their prior precedence. A repaired file is retried on a later sweep.

## Network activation and size

Network cleanup requires network activation or activation on the main site, so
WordPress loads the plugin in network admin. With subsite-only activation its
network deletion hook does not run and that subsite's data can remain. The
plugin cannot install hooks in a request where WordPress does not load it.

Network deletion visits all existing sites in 100-site query pages. One uncached
existence query per site checks plugin settings, the subject's scoped metadata
and owned attachment markers. Untouched sites get no lock, suppression metadata
or empty `zm_ai_ban_id` row. Data-bearing sites retain one owner fence until core
finishes deletion or PHP shutdown runs. This remains O(number of sites) and is
synchronous. No site-count or duration limit is claimed; allow execution time
for the network size and process bulk deletions in small batches.

Site provisioning, removal and first plugin/avatar setup on untouched sites must
be quiescent during network deletion, as must direct media writers. The site
registry itself is not locked. A failed site can leave earlier sites cleaned
while deletion aborts; retry is safe. Shutdown releases completed fences after
normal exit and PHP fatal errors, but cannot recover the current uncertain
operation or run after an OS kill or power loss. Those cases require operator
reconciliation. Opted-in uninstall removes leftover mutex rows only while
writers are quiescent; opted-out uninstall preserves them with the other data.
