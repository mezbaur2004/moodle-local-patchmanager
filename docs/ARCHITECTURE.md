# Patch Manager — Architecture & Data Flow

Verified against the merged source of `local_patchmanager`, `local_zoomcustom`
and `block_patchmanager` (`main`, checked 2026-09-22, updated 2026-09-22 for
the `can_restore()` fix in §13). Corrects the earlier 30-section draft, which
conflated target-version compatibility with the `OUTDATED` state — the two
are unrelated in the actual code.

## 1. Three components, one direction of knowledge

```
local_patchmanager   (engine)     knows HOW to detect, apply, restore, verify
local_zoomcustom     (pack)       knows WHICH Zoom files to patch, and WHY
block_patchmanager   (UI)         reads local_patchmanager's status API only
mod_zoom             (target)     unmodified plugin; never references the engine
```

The engine never imports the pack. The pack declares itself to the engine
through one callback. The block never touches a target file — it calls
`api::get_statuses()` / `api::can_manage_action()` and renders the answer.

## 2. Patch identity

```
pack:patchid           local_zoomcustom:001-period-grading
revision                r1   (bumped only when the hunks themselves change)
target component        mod_zoom
target files             2   (get_meeting_reports.php, console/get_meeting_report.php)
baseline / tested version 2026082400  (what the patch was authored and tested against)
```

A ZIP release is a deployment artifact, not identity — reinstalling the same
ZIP under a different package version doesn't change `pack:patchid:revision`.

## 3. Discovery: how a ZIP install becomes a known patch

```
Admin installs/upgrades local_zoomcustom ZIP
        │
        ▼
Moodle upgrade rebuilds the plugin-function cache
        │
        ▼
local_patchmanager\local\registry::load()
   calls get_plugins_with_function('patchmanager_patches', 'lib.php')
        │
        ▼
local_zoomcustom_patchmanager_patches()  →  \local_zoomcustom\patches::all()
        │
        ▼
Each raw definition validated by definition::from_array()
   (anchor non-empty, markers well-formed, target file exists and is inside
   the target component's directory — nothing is trusted blindly)
        │
        ▼
Registered, keyed "local_zoomcustom:001-period-grading" — visible to
cli/status.php, index.php, and the block. Not yet applied to any file.
```

Installing the ZIP never touches `mod_zoom`. It only makes the patch
*knowable*. `local_zoomcustom`'s own `db/install.php` additionally calls
`guard::evaluate(true)` at this point — since the patch is `NOT_APPLIED`,
this immediately pauses the Zoom grading task rather than leaving a window
where stock grading could run before anyone applies anything.

## 4. Detection: state is always recomputed, never stored as truth

```
detector::evaluate($def)
   for each target file:
      read file from disk
      count BEGIN/END markers for this pack:patchid (any revision)
      for each hunk: is the anchor present once? is the payload present once?
   aggregate per-file results
```

| State | Trigger | Notes |
|---|---|---|
| `NOT_APPLIED` | No markers, every anchor findable | Safe to apply |
| `APPLIED` | Markers present at the *current* revision, every hunk matches | The only state `guard` treats as potentially safe |
| `OUTDATED` | Markers present, but at a **different revision** than the definition | Revision mismatch only — **not** a target-version signal |
| `PARTIAL` | Some hunks applied, others not, across the patch's files | |
| `CONFLICT` | Anchor missing/ambiguous, or payload duplicated | Upstream changed around the anchor; needs human review |
| `UNKNOWN` | File missing/unreadable | |

**What upgrading `mod_zoom` actually does:** nothing to this table. If the
anchors survive the upgrade, the file still reads `APPLIED`. What changes is
a *separate* attribute — see §6.

## 5. Apply

```
api::apply($def)
   lock "code" (per-site lock, avoids two admins racing)
   refuse unless current state is NOT_APPLIED
   build plan: for each hunk, anchor must appear exactly once,
               payload must not already be present, no overlap with another
               pack's markers on the same file; generated PHP is syntax-checked
   [dry-run stops here — plan is shown, nothing written]
   back up the pristine file (see §7)
   write to a temp file, atomically rename over the target
   re-read the file and hash-verify the write
   invalidate OPcache for that file
   audit: apply_started, then apply_success or apply_failed
   notify_packs() → local_zoomcustom reacts (see §8)
```

No step here checks the installed `mod_zoom` version against anything. Apply
is pure anchor-text matching — that's what lets one patch definition survive
a run of upstream releases without being rewritten, as long as the anchor
text itself doesn't move.

## 6. Verification — the actual version-compatibility mechanism

```
api::verify($def)
   refuse unless state === APPLIED
   hash every patched file right now
   insert one row: pack, patchid, revision, targetcomponent,
                    targetversion = installed mod_zoom version, filehashes
   audit: verification
   notify_packs()
```

On every status read, `attach_verification()` checks: does a verify row (or
an entry in the pack's declared `testedversions`) exist for **this exact
installed target version**, with hashes that still match? If yes → `verified
= true`. If `mod_zoom` is then upgraded, that row no longer matches the new
version — `verified` flips to `false`. **The old row is not deleted**; it
stays as history for the version it was recorded against. State stays
`APPLIED` throughout. This is the entire mechanism the earlier doc's
"patch becomes outdated" claim was describing incorrectly.

## 7. Backups and restore

Every apply/restore first writes a pristine copy to moodledata, keyed by
`(targetcomponent, filepath, targetversion, pristine=1)`. Restore looks up a
backup matching the **currently installed** target version — if `mod_zoom`
was upgraded since the backup was taken, no match exists and restore is
refused (reinstalling the stock package is the recovery path). Restore never
reverse-applies the patch; it writes back the recorded pristine bytes,
hash-verified, then re-applies any *other* pack's patch that was also active
on the same file.

## 8. The guard reaction (local_zoomcustom-specific, not engine behaviour)

```
notify_packs() → local_zoomcustom_patchmanager_state_changed()
   → guard::evaluate(true)
        safe = acknowledged
             OR (state === APPLIED AND (verified OR strictness === applied_ok))
        if safe:  resume the mod_zoom get_meeting_reports task (if we paused it)
        if unsafe: pause it, record a window in local_zoomcustom_guard
```

This also runs on its own schedule (`guard_check`, */15) and via
`check_state` (*/30), independent of any admin action — so a target-version
upgrade that quietly invalidates verification is caught within 15 minutes
even if nobody visits the UI.

## 9. CLI reference (all confirmed against the actual scripts)

```bash
php local/patchmanager/cli/status.php                          # read-only
php local/patchmanager/cli/apply.php   --patch=KEY [--dry-run]
php local/patchmanager/cli/restore.php --patch=KEY [--dry-run] [--force]
php local/patchmanager/cli/verify.php  --patch=KEY [--note=TEXT]
```

`cli/check.php` is **not** read-only — it prunes stale acknowledgements and
calls `notify_packs()`, which can move the guard. Use `status.php` for
inspection.

## 10. Web vs CLI

`apply` / `reapply` / `restore` require `$CFG->local_patchmanager_allowwebapply
= true` — this switch exists specifically to gate writing code from a
browser. **`verify` and `acknowledge` do not require it** — they write one
database row, not code — enforced by `api::can_manage_action($action, $web)`,
which every caller (the admin page, the block, `cli/verify.php`) asks instead
of restating the rule. Production should keep the flag off and use the CLI
for apply/restore; verify works from the browser either way.

## 11. Audit trail (complete list)

`apply_started`, `apply_success`, `apply_failed`, `restore_started`,
`restore_success`, `restore_failed`, `reapply`, `verification`,
`acknowledgement`, `acknowledgement_cleared`, `state_check`,
`rollback_failed`.

## 12. Current state of the system

One patch exists today: `local_zoomcustom:001-period-grading` r1, two target
files in `mod_zoom`, authored and tested against `mod_zoom 2026082400`. No
`002-…` patch exists yet — treat any such ID in planning docs as roadmap, not
shipped.

## 13. Restore and CONFLICT — resolved

`status::can_restore()` used to include `CONFLICT` while the server-side
enforcement in `index.php` (`state::can_restore()`) excluded it, so the web
UI could offer a Restore button the server then refused. Fixed:
`status::can_restore()` now excludes `CONFLICT` too, for the same reason
`state::can_restore()` always did — in `CONFLICT` none of the patch's
markers are on disk, so there is nothing of the patch's own to remove, and
writing a stored backup over the file would discard whatever upstream now
ships there. The UI and the server gate agree on all six states.
