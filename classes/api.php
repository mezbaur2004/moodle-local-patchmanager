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

namespace local_patchmanager;

use local_patchmanager\local\applier;
use local_patchmanager\local\audit;
use local_patchmanager\local\backup;
use local_patchmanager\local\definition;
use local_patchmanager\local\detector;
use local_patchmanager\local\env;
use local_patchmanager\local\registry;

/**
 * Public entry point of the engine. Packs, the admin page, the CLI and the
 * scheduled task all go through here, so there is only one implementation.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {

    /** @var int Seconds to wait for the code modification lock. */
    protected const LOCK_TIMEOUT = 30;

    /**
     * Status of every registered customisation.
     *
     * @return status[] keyed by pack:id
     */
    public static function get_statuses(): array {
        $statuses = [];
        foreach (registry::get_definitions() as $key => $def) {
            $statuses[$key] = self::build_status($def);
        }
        return $statuses;
    }

    /**
     * Status of one customisation, or null when it is not registered.
     *
     * @param string $pack
     * @param string $patchid
     * @return status|null
     */
    public static function get_status(string $pack, string $patchid): ?status {
        $def = registry::get_definition($pack, $patchid);
        return $def === null ? null : self::build_status($def);
    }

    /**
     * Compute the full condition of one customisation from disk.
     *
     * @param definition $def
     * @return status
     */
    public static function build_status(definition $def): status {
        [$state, $files, $reasons, $appliedrevision] = detector::evaluate($def);

        $status = new status();
        $status->definition = $def;
        $status->state = $state;
        $status->files = $files;
        $status->reasons = $reasons;
        $status->appliedrevision = $appliedrevision;
        $status->required = $def->required;
        $status->componentversion = env::component_version($def->component);
        $status->hostname = env::hostname();
        $status->gitmanaged = env::is_git_managed();
        $status->backupavailable = backup::is_restorable($def);
        $status->opcache = env::opcache_info(array_values($def->file_paths()));

        $reasonlist = [];
        $status->writable = env::files_writable(array_values($def->file_paths()), $reasonlist);
        $status->writablereasons = $reasonlist;
        if (!$status->writable) {
            $status->blockedby[] = get_string('blockednotwritable', 'local_patchmanager');
        }
        if (!env::webapply_allowed() && !CLI_SCRIPT) {
            $status->blockedby[] = get_string('blockedwebapply', 'local_patchmanager');
        }

        foreach ($def->dependencies as $dependency) {
            $other = registry::get_definition($def->pack, $dependency);
            if ($other === null) {
                $status->blockedby[] = get_string('blockedmissingdep', 'local_patchmanager', $dependency);
                continue;
            }
            [$otherstate] = detector::evaluate($other);
            if ($otherstate !== state::APPLIED) {
                $status->blockedby[] = get_string('blockeddep', 'local_patchmanager',
                        (object) ['patch' => $dependency, 'state' => state::label($otherstate)]);
            }
        }

        self::attach_verification($status);
        self::attach_acknowledgement($status);
        self::attach_history($status);

        return $status;
    }

    /**
     * Apply a patch. The caller must already have checked permissions.
     *
     * @param definition $def
     * @param bool $dryrun
     * @return \stdClass
     */
    public static function apply(definition $def, bool $dryrun = false): \stdClass {
        return self::locked(function() use ($def, $dryrun) {
            $result = applier::apply($def, $dryrun);
            if (!$dryrun) {
                self::notify_packs();
            }
            return $result;
        });
    }

    /**
     * Restore the pristine files of a patch.
     *
     * @param definition $def
     * @param bool $dryrun
     * @param bool $force
     * @return \stdClass
     */
    public static function restore(definition $def, bool $dryrun = false, bool $force = false): \stdClass {
        return self::locked(function() use ($def, $dryrun, $force) {
            $result = applier::restore($def, $dryrun, $force);
            if (!$dryrun) {
                self::notify_packs();
            }
            return $result;
        });
    }

    /**
     * Restore and apply as one locked operation.
     *
     * @param definition $def
     * @param bool $dryrun
     * @return \stdClass
     */
    public static function reapply(definition $def, bool $dryrun = false): \stdClass {
        return self::locked(function() use ($def, $dryrun) {
            $result = applier::reapply($def, $dryrun);
            if (!$dryrun) {
                self::notify_packs();
            }
            return $result;
        });
    }

    /**
     * Record that this revision has been tested against the installed target version.
     *
     * @param definition $def
     * @param string $note
     * @return void
     * @throws \moodle_exception when the patch is not cleanly applied
     */
    public static function verify(definition $def, string $note = ''): void {
        global $DB, $USER;

        $status = self::build_status($def);
        if ($status->state !== state::APPLIED) {
            throw new \moodle_exception('errverifystate', 'local_patchmanager', '', state::label($status->state));
        }

        $hashes = [];
        foreach ($status->files as $relpath => $file) {
            $hashes[$relpath] = $file->sha256;
        }

        $record = (object) [
            'pack' => $def->pack,
            'patchid' => $def->id,
            'revision' => $def->revision,
            'targetcomponent' => $def->component,
            'targetversion' => $status->componentversion->versiondisk,
            'filehashes' => json_encode($hashes),
            'note' => \core_text::substr($note, 0, 255),
            'userid' => (int) $USER->id,
            'timecreated' => time(),
        ];
        $DB->insert_record('local_patchmanager_verify', $record);

        audit::log(audit::VERIFICATION, $def, [
            'stateafter' => $status->state,
            'result' => 'ok',
            'detail' => ['hashes' => $hashes, 'note' => $note],
        ]);

        self::notify_packs();
    }

    /**
     * Acknowledge the current condition of a required customisation.
     *
     * The acknowledgement is tied to this state, revision and target version, so
     * it stops applying as soon as any of them changes.
     *
     * @param definition $def
     * @param string $reason
     * @return void
     */
    public static function acknowledge(definition $def, string $reason): void {
        global $DB, $USER;

        $status = self::build_status($def);

        $record = (object) [
            'pack' => $def->pack,
            'patchid' => $def->id,
            'state' => $status->state,
            'revision' => $def->revision,
            'targetversion' => $status->componentversion->versiondisk,
            'reason' => \core_text::substr($reason, 0, 255),
            'userid' => (int) $USER->id,
            'timecreated' => time(),
            'timecleared' => null,
        ];
        $DB->insert_record('local_patchmanager_ack', $record);

        audit::log(audit::ACKNOWLEDGEMENT, $def, [
            'stateafter' => $status->state,
            'result' => 'ok',
            'detail' => ['reason' => $reason],
        ]);

        self::notify_packs();
    }

    /**
     * Clear acknowledgements that no longer describe the current condition.
     *
     * @return int number of records cleared
     */
    public static function prune_acknowledgements(): int {
        global $DB;

        $cleared = 0;
        $open = $DB->get_records('local_patchmanager_ack', ['timecleared' => null]);
        foreach ($open as $record) {
            $def = registry::get_definition($record->pack, $record->patchid);
            $stillvalid = false;
            if ($def !== null) {
                $status = self::build_status($def);
                $stillvalid = ($record->state === $status->state
                        && (int) $record->revision === $def->revision
                        && (int) $record->targetversion === (int) $status->componentversion->versiondisk);
            }
            if (!$stillvalid) {
                $record->timecleared = time();
                $DB->update_record('local_patchmanager_ack', $record);
                audit::log(audit::ACK_CLEARED, $def, ['result' => 'ok', 'detail' => ['ackid' => $record->id]]);
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Tell every pack that states may have changed.
     *
     * Packs decide for themselves what to do about it. The engine stays generic.
     *
     * @return void
     */
    public static function notify_packs(): void {
        $statuses = self::get_statuses();
        $functions = get_plugins_with_function('patchmanager_state_changed', 'lib.php');
        foreach ($functions as $plugins) {
            foreach ($plugins as $functionname) {
                try {
                    $functionname($statuses);
                } catch (\Throwable $e) {
                    debugging('local_patchmanager: ' . $functionname . '() failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }

    /**
     * Permission gate shared by the web UI and the CLI.
     *
     * @param bool $web true when the request came through the browser
     * @return void
     * @throws \moodle_exception
     */
    public static function require_manage(bool $web): void {
        if (!is_siteadmin()) {
            throw new \moodle_exception('errnotsiteadmin', 'local_patchmanager');
        }
        require_capability('local/patchmanager:manage', \context_system::instance());
        if ($web && !env::webapply_allowed()) {
            throw new \moodle_exception('errwebapplydisabled', 'local_patchmanager');
        }
    }

    /**
     * Run a callable while holding the code modification lock.
     *
     * @param callable $callable
     * @return \stdClass
     */
    protected static function locked(callable $callable): \stdClass {
        $factory = \core\lock\lock_config::get_lock_factory('local_patchmanager');
        $lock = $factory->get_lock('code', self::LOCK_TIMEOUT);
        if (!$lock) {
            $result = (object) [
                'success' => false,
                'critical' => false,
                'dryrun' => false,
                'messages' => [get_string('errlock', 'local_patchmanager')],
                'plan' => [],
                'state' => null,
                'statebefore' => null,
                'written' => [],
                'backuppaths' => [],
            ];
            return $result;
        }

        try {
            return $callable();
        } finally {
            $lock->release();
        }
    }

    /**
     * Decide whether the current condition counts as verified.
     *
     * @param status $status
     * @return void
     */
    protected static function attach_verification(status $status): void {
        global $DB;

        $def = $status->definition;
        if ($status->state !== state::APPLIED) {
            return;
        }

        $version = $status->componentversion->versiondisk;

        if ($version !== null && in_array((int) $version, $def->testedversions, true)) {
            $status->verified = true;
            $status->verifiedsource = 'pack';
            return;
        }

        $records = $DB->get_records('local_patchmanager_verify', [
            'pack' => $def->pack,
            'patchid' => $def->id,
            'revision' => $def->revision,
            'targetversion' => $version,
        ], 'timecreated DESC, id DESC');

        foreach ($records as $record) {
            $status->verificationrecord = $record;
            $hashes = json_decode((string) $record->filehashes, true);
            if (!is_array($hashes)) {
                continue;
            }
            $matches = true;
            foreach ($status->files as $relpath => $file) {
                if (($hashes[$relpath] ?? null) !== $file->sha256) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $status->verified = true;
                $status->verifiedsource = 'site';
                return;
            }
            $status->verificationstale = true;
        }
    }

    /**
     * Attach an acknowledgement only when it still describes the current condition.
     *
     * @param status $status
     * @return void
     */
    protected static function attach_acknowledgement(status $status): void {
        global $DB;

        $def = $status->definition;
        $records = $DB->get_records('local_patchmanager_ack', [
            'pack' => $def->pack,
            'patchid' => $def->id,
            'timecleared' => null,
        ], 'timecreated DESC, id DESC');

        foreach ($records as $record) {
            if ($record->state === $status->state
                    && (int) $record->revision === $def->revision
                    && (int) $record->targetversion === (int) $status->componentversion->versiondisk) {
                $status->acknowledgement = $record;
                return;
            }
        }
    }

    /**
     * Attach the last apply record and detect changes made since then.
     *
     * @param status $status
     * @return void
     */
    protected static function attach_history(status $status): void {
        $def = $status->definition;
        $record = audit::latest($def->pack, $def->id, [audit::APPLY_SUCCESS]);
        if ($record === null) {
            return;
        }

        $status->lastapply = $record;

        $detail = json_decode((string) $record->detail, true);
        $hashes = $detail['hashes'] ?? [];
        foreach ($status->files as $relpath => $file) {
            $expected = $hashes[$relpath]['after'] ?? null;
            if ($expected !== null && $expected !== $file->sha256) {
                $status->changedsinceapply = true;
            }
        }
    }
}
