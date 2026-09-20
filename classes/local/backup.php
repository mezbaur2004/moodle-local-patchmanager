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

/**
 * Pristine and pre-restore file backups, stored under moodledata.
 *
 * Restore never reverse applies a patch. It puts back a verified copy of the
 * file as it was before the manager touched it.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup {

    /** @var string Backup taken before applying a patch. */
    public const REASON_PRE_APPLY = 'pre_apply';

    /** @var string Backup of the current file taken before restoring. */
    public const REASON_PRE_RESTORE = 'pre_restore';

    /** @var string Backup taken while rolling back a failed apply. */
    public const REASON_ROLLBACK = 'rollback';

    /**
     * Root directory of all backups.
     *
     * @return string
     */
    public static function root(): string {
        global $CFG;
        return $CFG->dataroot . '/local_patchmanager/backups';
    }

    /**
     * Store a copy of the given content and index it.
     *
     * @param definition $def
     * @param string $relpath path relative to the target component
     * @param string $content file content being preserved
     * @param bool $pristine true when the file was unpatched at this moment
     * @param string $reason
     * @param string|null $filemode octal mode of the original file
     * @return \stdClass the backup record
     * @throws \moodle_exception when the copy cannot be written or verified
     */
    public static function store(definition $def, string $relpath, string $content, bool $pristine,
            string $reason, ?string $filemode = null): \stdClass {
        global $DB, $USER;

        $version = env::component_version($def->component);
        $dir = self::root() . '/' . $def->component . '/' . ($version->versiondisk ?? 'unknown')
                . '/' . date('Ymd-His') . '-' . $def->id . '-' . $reason;
        $target = $dir . '/' . $relpath;

        make_writable_directory(dirname($target));

        if (file_put_contents($target, $content) === false) {
            throw new \moodle_exception('errbackupwrite', 'local_patchmanager', '', $target);
        }

        $written = @file_get_contents($target);
        if ($written === false || util::hash($written) !== util::hash($content)) {
            throw new \moodle_exception('errbackupverify', 'local_patchmanager', '', $target);
        }

        $record = (object) [
            'pack' => $def->pack,
            'patchid' => $def->id,
            'targetcomponent' => $def->component,
            'targetversion' => $version->versiondisk,
            'filepath' => $def->dirroot_relative($relpath),
            'backuppath' => self::dataroot_relative($target),
            'contenthash' => util::hash($content),
            'filesize' => strlen($content),
            'filemode' => $filemode,
            'pristine' => $pristine ? 1 : 0,
            'reason' => $reason,
            'timecreated' => time(),
            'userid' => isset($USER->id) ? (int) $USER->id : 0,
            'hostname' => env::hostname(),
        ];
        $record->id = $DB->insert_record('local_patchmanager_backup', $record);

        return $record;
    }

    /**
     * The newest pristine backup usable for the current target version.
     *
     * A pristine copy taken against a different version of the target component
     * is never offered: putting old upstream code back would be worse than the
     * problem it is meant to solve.
     *
     * @param definition $def
     * @param string $relpath
     * @return \stdClass|null
     */
    public static function find_pristine(definition $def, string $relpath): ?\stdClass {
        global $DB;

        $version = env::component_version($def->component);
        if ($version->versiondisk === null) {
            return null;
        }

        $records = $DB->get_records('local_patchmanager_backup', [
            'targetcomponent' => $def->component,
            'filepath' => $def->dirroot_relative($relpath),
            'targetversion' => $version->versiondisk,
            'pristine' => 1,
        ], 'timecreated DESC, id DESC', '*', 0, 1);

        if (!$records) {
            return null;
        }

        $record = reset($records);
        return self::read($record) === null ? null : $record;
    }

    /**
     * Read and verify a backup.
     *
     * @param \stdClass $record
     * @return string|null null when the file is missing or its hash does not match
     */
    public static function read(\stdClass $record): ?string {
        global $CFG;

        $path = $CFG->dataroot . '/' . $record->backuppath;
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false || util::hash($content) !== $record->contenthash) {
            return null;
        }
        return $content;
    }

    /**
     * Does every file of this patch have a usable pristine backup?
     *
     * @param definition $def
     * @return bool
     */
    public static function is_restorable(definition $def): bool {
        foreach (array_keys($def->files) as $relpath) {
            if (self::find_pristine($def, $relpath) === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert an absolute path inside moodledata to a relative one.
     *
     * @param string $path
     * @return string
     */
    protected static function dataroot_relative(string $path): string {
        global $CFG;
        $dataroot = rtrim(str_replace('\\', '/', $CFG->dataroot), '/');
        $path = str_replace('\\', '/', $path);
        if (strpos($path, $dataroot . '/') === 0) {
            return substr($path, strlen($dataroot) + 1);
        }
        return $path;
    }
}
