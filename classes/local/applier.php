<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_patchmanager\local;

use local_patchmanager\state;

/**
 * Applies and restores patches.
 *
 * Nothing is written to the code directory until every pre-write validation has
 * succeeded. If the replacement phase fails part way, the backups taken in this
 * same operation are put back.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class applier {

    /** @var string Marker pattern for any pack, used for overlap detection. */
    protected const ANY_MARKER = '/\b(BEGIN|END)\s+([a-z0-9_]+:[a-z0-9][a-z0-9_\-]*)\s+r(\d+)\b/';

    /**
     * Apply a patch.
     *
     * @param definition $def
     * @param bool $dryrun
     * @return \stdClass result with success, messages, plan, state, critical
     */
    public static function apply(definition $def, bool $dryrun = false): \stdClass {
        $result = self::new_result($dryrun);

        [$before] = detector::evaluate($def);
        $result->statebefore = $before;

        if ($before !== state::NOT_APPLIED) {
            $result->messages[] = get_string('errnotapplicable', 'local_patchmanager', state::label($before));
            $result->state = $before;
            return $result;
        }

        $plan = self::build_apply_plan($def, $result->messages);
        if ($plan === null) {
            $result->state = $before;
            return $result;
        }
        $result->plan = $plan;

        if ($dryrun) {
            $result->success = true;
            $result->state = $before;
            $result->messages[] = get_string('dryrunok', 'local_patchmanager');
            return $result;
        }

        audit::log(audit::APPLY_STARTED, $def, ['statebefore' => $before]);

        self::commit($def, $plan, true, $result);

        [$after] = detector::evaluate($def);
        $result->state = $after;

        if ($result->success && $after !== state::APPLIED) {
            // The files were written but the result is not what we expected.
            $result->messages[] = get_string('errunexpectedstate', 'local_patchmanager', state::label($after));
            $result->success = false;
            self::rollback($result);
            [$after] = detector::evaluate($def);
            $result->state = $after;
        }

        audit::log($result->success ? audit::APPLY_SUCCESS : audit::APPLY_FAILED, $def, [
            'statebefore' => $before,
            'stateafter' => $after,
            'result' => $result->success ? 'ok' : ($result->critical ? 'critical' : 'failed'),
            'detail' => [
                'messages' => $result->messages,
                'hashes' => self::plan_hashes($plan),
                'backups' => $result->backuppaths,
            ],
        ]);

        return $result;
    }

    /**
     * Restore the pristine files of a patch and re-apply any other patch that
     * was applied to the same files.
     *
     * @param definition $def
     * @param bool $dryrun
     * @param bool $force restore even from an unrecognised state
     * @return \stdClass
     */
    public static function restore(definition $def, bool $dryrun = false, bool $force = false): \stdClass {
        $result = self::new_result($dryrun);

        [$before] = detector::evaluate($def);
        $result->statebefore = $before;

        if (in_array($before, [state::NOT_APPLIED, state::CONFLICT], true) && !$force) {
            $result->messages[] = get_string('errnothingtorestore', 'local_patchmanager', state::label($before));
            $result->state = $before;
            return $result;
        }

        $plan = self::build_restore_plan($def, $result->messages);
        if ($plan === null) {
            $result->state = $before;
            return $result;
        }
        $result->plan = $plan;

        if ($dryrun) {
            $result->success = true;
            $result->state = $before;
            $result->messages[] = get_string('dryrunok', 'local_patchmanager');
            return $result;
        }

        audit::log(audit::RESTORE_STARTED, $def, ['statebefore' => $before]);

        self::commit($def, $plan, false, $result);

        [$after] = detector::evaluate($def);
        $result->state = $after;

        audit::log($result->success ? audit::RESTORE_SUCCESS : audit::RESTORE_FAILED, $def, [
            'statebefore' => $before,
            'stateafter' => $after,
            'result' => $result->success ? 'ok' : ($result->critical ? 'critical' : 'failed'),
            'detail' => [
                'messages' => $result->messages,
                'backups' => $result->backuppaths,
            ],
        ]);

        return $result;
    }

    /**
     * Restore then apply, as one operation. The caller holds the lock.
     *
     * @param definition $def
     * @param bool $dryrun
     * @return \stdClass
     */
    public static function reapply(definition $def, bool $dryrun = false): \stdClass {
        if ($dryrun) {
            // A dry run writes nothing, so the restore step leaves the patched
            // file on disk and the apply precondition would never be met.
            // Plan the apply against the content restore *would* have produced.
            return self::reapply_dryrun($def);
        }

        $restore = self::restore($def, $dryrun, true);
        if (!$restore->success) {
            $restore->messages[] = get_string('errreapplyaborted', 'local_patchmanager');
            return $restore;
        }

        $apply = self::apply($def, $dryrun);
        $apply->messages = array_merge($restore->messages, $apply->messages);
        $apply->statebefore = $restore->statebefore;
        $apply->critical = $apply->critical || $restore->critical;

        audit::log(audit::REAPPLY, $def, [
            'statebefore' => $restore->statebefore,
            'stateafter' => $apply->state,
            'result' => $apply->success ? 'ok' : 'failed',
            'detail' => ['messages' => $apply->messages],
        ]);

        return $apply;
    }

    /**
     * Preview a reapply without writing anything.
     *
     * Restore is simulated in memory, and the apply plan is then built against
     * that simulated content rather than against what is still on disk.
     *
     * @param definition $def
     * @return \stdClass
     */
    protected static function reapply_dryrun(definition $def): \stdClass {
        $result = self::new_result(true);

        [$before] = detector::evaluate($def);
        $result->statebefore = $before;
        $result->state = $before;

        $restoreplan = self::build_restore_plan($def, $result->messages);
        if ($restoreplan === null) {
            $result->messages[] = get_string('errreapplyaborted', 'local_patchmanager');
            return $result;
        }

        // What each file would contain once restore had run.
        $restored = [];
        foreach (array_keys($def->files) as $relpath) {
            if (isset($restoreplan[$relpath])) {
                $restored[$relpath] = $restoreplan[$relpath]->new;
                continue;
            }

            // Restore is a no-op for this file, so its current content stands.
            $current = self::read_file($def->file_path($relpath), $result->messages);
            if ($current === null) {
                $result->messages[] = get_string('errreapplyaborted', 'local_patchmanager');
                return $result;
            }
            $restored[$relpath] = $current;
        }

        $applyplan = self::build_apply_plan($def, $result->messages, $restored);
        if ($applyplan === null) {
            $result->messages[] = get_string('errreapplyaborted', 'local_patchmanager');
            return $result;
        }

        $result->plan = $applyplan;
        $result->success = true;
        $result->messages[] = get_string('dryrunok', 'local_patchmanager');

        return $result;
    }

    /**
     * Build the new content of every file of this patch.
     *
     * @param definition $def
     * @param string[] $messages
     * @param array $contents relpath => content to plan against instead of reading
     *                        from disk. Used by the reapply preview.
     * @return array|null relpath => entry, or null on failure
     */
    protected static function build_apply_plan(definition $def, array &$messages, array $contents = []): ?array {
        $plan = [];

        foreach ($def->files as $relpath => $hunks) {
            $path = $def->file_path($relpath);
            if (array_key_exists($relpath, $contents)) {
                $old = $contents[$relpath];
            } else {
                $old = self::read_file($path, $messages);
                if ($old === null) {
                    return null;
                }
            }

            $eol = util::detect_eol($old);
            $new = $old;

            foreach ($hunks as $hunk) {
                $anchor = $hunk->anchor_for($eol);
                $payload = $hunk->payload_for($eol);

                if (util::count($new, $payload) !== 0) {
                    $messages[] = get_string('errpayloadpresent', 'local_patchmanager', $hunk->label);
                    return null;
                }

                $count = util::count($new, $anchor);
                if ($count !== 1) {
                    $messages[] = get_string('erranchorcount', 'local_patchmanager',
                            (object) ['hunk' => $hunk->label, 'count' => $count]);
                    return null;
                }

                $offset = strpos($new, $anchor);
                $owner = null;
                if (self::inside_other_patch($def, $new, $offset, $owner)) {
                    $messages[] = get_string('erroverlap', 'local_patchmanager',
                            (object) ['hunk' => $hunk->label, 'other' => $owner]);
                    return null;
                }

                $new = $hunk->apply_at($new, $offset, $eol);
            }

            if (!self::syntax_ok($relpath, $new, $messages)) {
                return null;
            }

            $plan[$relpath] = self::plan_entry($def, $relpath, $path, $old, $new, true);
        }

        return $plan;
    }

    /**
     * Build the restored content of every file: the pristine copy plus any
     * other patch that is currently applied to the same file.
     *
     * @param definition $def
     * @param string[] $messages
     * @return array|null
     */
    protected static function build_restore_plan(definition $def, array &$messages): ?array {
        $plan = [];

        foreach ($def->files as $relpath => $unusedhunks) {
            $path = $def->file_path($relpath);
            $old = self::read_file($path, $messages);
            if ($old === null) {
                return null;
            }

            $record = backup::find_pristine($def, $relpath);
            if ($record === null) {
                $messages[] = get_string('errnopristine', 'local_patchmanager', $relpath);
                return null;
            }

            $new = backup::read($record);
            if ($new === null) {
                $messages[] = get_string('errbackupunusable', 'local_patchmanager', $relpath);
                return null;
            }

            // Put back any other customisation that is currently applied here.
            foreach (registry::definitions_for_file($def->component, $relpath, $def->key()) as $other) {
                [$otherstate] = detector::evaluate($other);
                if ($otherstate !== state::APPLIED) {
                    continue;
                }
                $eol = util::detect_eol($new);
                foreach ($other->files[$relpath] as $hunk) {
                    $anchor = $hunk->anchor_for($eol);
                    if (util::count($new, $anchor) !== 1) {
                        $messages[] = get_string('errreapplyother', 'local_patchmanager',
                                (object) ['other' => $other->key(), 'file' => $relpath]);
                        return null;
                    }
                    $new = $hunk->apply_at($new, strpos($new, $anchor), $eol);
                }
                $messages[] = get_string('infokeptother', 'local_patchmanager', $other->key());
            }

            if ($new === $old) {
                continue;
            }

            if (!self::syntax_ok($relpath, $new, $messages)) {
                return null;
            }

            $plan[$relpath] = self::plan_entry($def, $relpath, $path, $old, $new, false);
        }

        if (empty($plan)) {
            $messages[] = get_string('infonochange', 'local_patchmanager');
        }

        return $plan;
    }

    /**
     * Back up, write and verify every file of a plan.
     *
     * @param definition $def
     * @param array $plan
     * @param bool $pristine whether this operation is one that starts from unpatched files
     * @param \stdClass $result
     * @return void
     */
    protected static function commit(definition $def, array $plan, bool $pristine, \stdClass $result): void {
        if (empty($plan)) {
            $result->success = true;
            return;
        }

        $reason = $pristine ? backup::REASON_PRE_APPLY : backup::REASON_PRE_RESTORE;

        // Phase 1: back up every current file. Nothing has been written yet.
        foreach ($plan as $relpath => $entry) {
            // Applying starts from unpatched files only while this is the sole
            // patch on them. Once another patch is already applied to the same
            // file, the content here still carries that patch's markers, and
            // find_pristine() keys on the file rather than on the patch: storing
            // this as pristine would hand a later restore a copy that already
            // contains the other patch, which then gets re-applied on top of
            // itself. Record what the content actually is.
            $filepristine = $pristine && !preg_match(self::ANY_MARKER, $entry->old);

            try {
                $record = backup::store($def, $relpath, $entry->old, $filepristine, $reason, $entry->mode);
            } catch (\Throwable $e) {
                $result->messages[] = $e->getMessage();
                return;
            }
            $result->backups[$relpath] = (object) ['path' => $entry->path, 'content' => $entry->old, 'mode' => $entry->mode];
            $result->backuppaths[$relpath] = $record->backuppath;
        }

        // Phase 2: write temporary files next to their targets.
        $temps = [];
        foreach ($plan as $relpath => $entry) {
            $temp = self::write_temp($entry, $result->messages);
            if ($temp === null) {
                foreach ($temps as $stale) {
                    @unlink($stale);
                }
                return;
            }
            $temps[$relpath] = $temp;
        }

        // Phase 3: replace. This is the only moment the code directory changes.
        foreach ($plan as $relpath => $entry) {
            if (!self::replace($temps[$relpath], $entry->path)) {
                $result->messages[] = get_string('errrenamefailed', 'local_patchmanager', $entry->path);
                foreach ($temps as $stale) {
                    @unlink($stale);
                }
                self::rollback($result);
                return;
            }
            $result->written[$relpath] = $entry->path;
            env::invalidate_opcache($entry->path);
        }

        // Phase 4: verify what is on disk.
        foreach ($plan as $relpath => $entry) {
            $written = @file_get_contents($entry->path);
            if ($written === false || util::hash($written) !== util::hash($entry->new)) {
                $result->messages[] = get_string('errverifyfailed', 'local_patchmanager', $relpath);
                self::rollback($result);
                return;
            }
            if ($entry->owner !== null && @fileowner($entry->path) !== $entry->owner) {
                $result->messages[] = get_string('warnowner', 'local_patchmanager', $relpath);
            }
        }

        $result->success = true;
    }

    /**
     * Put back the backups taken during this operation.
     *
     * @param \stdClass $result
     * @return void
     */
    protected static function rollback(\stdClass $result): void {
        $failed = [];

        foreach ($result->written as $relpath => $path) {
            $backup = $result->backups[$relpath] ?? null;
            if ($backup === null) {
                $failed[] = $relpath;
                continue;
            }
            $entry = (object) [
                'path' => $path,
                'new' => $backup->content,
                'mode' => $backup->mode,
            ];
            $temp = self::write_temp($entry, $result->messages);
            if ($temp === null || !self::replace($temp, $path)) {
                $failed[] = $relpath;
                continue;
            }
            env::invalidate_opcache($path);
        }

        if ($failed) {
            $result->critical = true;
            $result->messages[] = get_string('errrollbackfailed', 'local_patchmanager', implode(', ', $failed));
            audit::log(audit::ROLLBACK_FAILED, null, [
                'result' => 'critical',
                'detail' => ['files' => $failed, 'backups' => $result->backuppaths],
            ]);
        } else {
            $result->messages[] = get_string('inforolledback', 'local_patchmanager');
            $result->written = [];
        }
    }

    /**
     * Write a temporary file beside its target, with the same mode.
     *
     * @param \stdClass $entry
     * @param string[] $messages
     * @return string|null path of the temporary file
     */
    protected static function write_temp(\stdClass $entry, array &$messages): ?string {
        $temp = $entry->path . '.pmtmp.' . getmypid() . '.' . random_string(8);
        if (file_put_contents($temp, $entry->new) === false) {
            $messages[] = get_string('errtempwrite', 'local_patchmanager', $temp);
            return null;
        }
        if (!empty($entry->mode)) {
            @chmod($temp, octdec($entry->mode));
        }
        return $temp;
    }

    /**
     * Atomically move a temporary file over its target.
     *
     * @param string $temp
     * @param string $target
     * @return bool
     */
    protected static function replace(string $temp, string $target): bool {
        if (@rename($temp, $target)) {
            return true;
        }
        // Windows cannot rename over an existing file.
        if (DIRECTORY_SEPARATOR === '\\' && file_exists($target) && @unlink($target) && @rename($temp, $target)) {
            return true;
        }
        @unlink($temp);
        return false;
    }

    /**
     * Read a target file.
     *
     * @param string $path
     * @param string[] $messages
     * @return string|null
     */
    protected static function read_file(string $path, array &$messages): ?string {
        if (!file_exists($path) || !is_readable($path)) {
            $messages[] = get_string('errfileunreadable', 'local_patchmanager', $path);
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            $messages[] = get_string('errfileunreadable', 'local_patchmanager', $path);
            return null;
        }
        return $content;
    }

    /**
     * Syntax check generated PHP.
     *
     * @param string $relpath
     * @param string $content
     * @param string[] $messages
     * @return bool
     */
    protected static function syntax_ok(string $relpath, string $content, array &$messages): bool {
        if (substr($relpath, -4) !== '.php') {
            return true;
        }
        $error = null;
        if (util::php_syntax_ok($content, $error)) {
            return true;
        }
        $messages[] = get_string('errsyntax', 'local_patchmanager', (object) ['file' => $relpath, 'error' => $error]);
        return false;
    }

    /**
     * Is this offset inside a marker block owned by a different patch?
     *
     * @param definition $def
     * @param string $content
     * @param int $offset
     * @param string|null $owner receives the other patch key
     * @return bool
     */
    protected static function inside_other_patch(definition $def, string $content, int $offset, ?string &$owner): bool {
        if (!preg_match_all(self::ANY_MARKER, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return false;
        }

        $open = [];
        foreach ($matches as $match) {
            $kind = strtoupper($match[1][0]);
            $key = $match[2][0];
            $position = (int) $match[0][1];
            if ($kind === 'BEGIN') {
                $open[$key] = $position;
                continue;
            }
            if (!isset($open[$key])) {
                continue;
            }
            $start = $open[$key];
            $end = $position + strlen($match[0][0]);
            unset($open[$key]);
            if ($key !== $def->key() && $offset > $start && $offset < $end) {
                $owner = $key;
                return true;
            }
        }

        return false;
    }

    /**
     * One entry of a plan.
     *
     * @param definition $def
     * @param string $relpath
     * @param string $path
     * @param string $old
     * @param string $new
     * @param bool $applying
     * @return \stdClass
     */
    protected static function plan_entry(definition $def, string $relpath, string $path, string $old,
            string $new, bool $applying): \stdClass {
        $mode = @fileperms($path);
        return (object) [
            'relpath' => $relpath,
            'path' => $path,
            'old' => $old,
            'new' => $new,
            'oldhash' => util::hash($old),
            'newhash' => util::hash($new),
            'mode' => $mode === false ? null : substr(sprintf('%o', $mode), -4),
            'owner' => @fileowner($path) ?: null,
            'applying' => $applying,
            'component' => $def->component,
        ];
    }

    /**
     * Hashes for the audit record.
     *
     * @param array $plan
     * @return array
     */
    protected static function plan_hashes(array $plan): array {
        $hashes = [];
        foreach ($plan as $relpath => $entry) {
            $hashes[$relpath] = ['before' => $entry->oldhash, 'after' => $entry->newhash];
        }
        return $hashes;
    }

    /**
     * Empty result.
     *
     * @param bool $dryrun
     * @return \stdClass
     */
    protected static function new_result(bool $dryrun): \stdClass {
        return (object) [
            'success' => false,
            'critical' => false,
            'dryrun' => $dryrun,
            'messages' => [],
            'plan' => [],
            'backups' => [],
            'backuppaths' => [],
            'written' => [],
            'statebefore' => null,
            'state' => null,
        ];
    }
}
