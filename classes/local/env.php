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
 * Environment facts that affect what the manager may do and what it must warn about.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class env {

    /**
     * Node name, recorded in every audit record.
     *
     * A filesystem check only ever proves something about this node.
     *
     * @return string
     */
    public static function hostname(): string {
        $host = gethostname();
        return $host === false ? 'unknown' : substr($host, 0, 100);
    }

    /**
     * Is web based applying explicitly enabled in config.php?
     *
     * @return bool
     */
    public static function webapply_allowed(): bool {
        global $CFG;
        return !empty($CFG->local_patchmanager_allowwebapply);
    }

    /**
     * Can the manager write to every file of this patch and to their directories?
     *
     * @param string[] $paths absolute paths
     * @param string[] $reasons receives human readable problems
     * @return bool
     */
    public static function files_writable(array $paths, array &$reasons = []): bool {
        $ok = true;
        foreach ($paths as $path) {
            if (file_exists($path) && !is_writable($path)) {
                $reasons[] = get_string('errfilenotwritable', 'local_patchmanager', $path);
                $ok = false;
            }
            $dir = dirname($path);
            if (!is_writable($dir)) {
                // A temporary file is created next to the target and renamed over it.
                $reasons[] = get_string('errdirnotwritable', 'local_patchmanager', $dir);
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Is the Moodle code directory under Git?
     *
     * Informational only. It never blocks an operation.
     *
     * @return bool
     */
    public static function is_git_managed(): bool {
        global $CFG;
        return file_exists($CFG->dirroot . '/.git');
    }

    /**
     * OPcache facts for the given files.
     *
     * Returns an object with: enabled, validatetimestamps, stale (array of files
     * whose cached copy is older than the file on disk), checked.
     *
     * @param string[] $paths absolute paths
     * @return \stdClass
     */
    public static function opcache_info(array $paths = []): \stdClass {
        $info = (object) [
            'enabled' => false,
            'validatetimestamps' => true,
            'stale' => [],
            'checked' => false,
        ];

        if (!function_exists('opcache_get_status')) {
            return $info;
        }

        $info->validatetimestamps = (bool) ini_get('opcache.validate_timestamps');

        try {
            $status = @opcache_get_status(true);
        } catch (\Throwable $e) {
            return $info;
        }

        if (empty($status) || empty($status['opcache_enabled'])) {
            return $info;
        }

        $info->enabled = true;
        $info->checked = true;

        if (empty($status['scripts'])) {
            return $info;
        }

        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            foreach ($status['scripts'] as $key => $script) {
                if ($key !== $real && $key !== $path) {
                    continue;
                }
                $cached = $script['timestamp'] ?? null;
                $ondisk = @filemtime($path);
                if ($cached !== null && $ondisk !== false && $cached < $ondisk) {
                    $info->stale[] = $path;
                }
            }
        }

        return $info;
    }

    /**
     * Invalidate the OPcache entry of a file that was just written.
     *
     * @param string $path
     * @return void
     */
    public static function invalidate_opcache(string $path): void {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * Version of an installed component, on disk and in the database.
     *
     * @param string $component for example mod_zoom
     * @return \stdClass with versiondisk, versiondb, upgradepending, installed
     */
    public static function component_version(string $component): \stdClass {
        $result = (object) [
            'versiondisk' => null,
            'versiondb' => null,
            'upgradepending' => false,
            'installed' => false,
        ];

        $info = \core_plugin_manager::instance()->get_plugin_info($component);
        if ($info === null) {
            return $result;
        }

        $result->installed = true;
        $result->versiondisk = $info->versiondisk === null ? null : (int) $info->versiondisk;
        $result->versiondb = $info->versiondb === null ? null : (int) $info->versiondb;
        $result->upgradepending = ($result->versiondisk !== null && $result->versiondb !== null
                && $result->versiondisk !== $result->versiondb);

        return $result;
    }
}
