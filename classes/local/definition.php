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
 * A validated patch definition, contract version 1.
 *
 * Packs return raw arrays from their patchmanager_patches() callback. This
 * class validates them once and refuses anything unsafe.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class definition {

    /** @var int The only contract version supported by this engine. */
    public const CONTRACT = 1;

    /** @var string[] Paths a patch may never touch, relative to the target component. */
    public const FORBIDDEN = ['db/', 'version.php'];

    /** @var string Component that provides the definition, for example local_zoomcustom. */
    public $pack;

    /** @var string Patch id, for example 001-period-grading. */
    public $id;

    /** @var string */
    public $name;

    /** @var string */
    public $description;

    /** @var string Target component, for example mod_zoom. */
    public $component;

    /** @var int Current revision of this definition. */
    public $revision;

    /** @var bool Whether the pack treats this customisation as required. */
    public $required;

    /** @var string[] Patch ids from the same pack that must be applied first. */
    public $dependencies;

    /** @var int[] Target component versions the pack author has tested. */
    public $testedversions;

    /** @var array Map of relative path to the expected stock sha256, evidence only. */
    public $baselinehashes;

    /** @var array Map of relative path to hunk[] */
    public $files;

    /**
     * Build and validate a definition.
     *
     * @param string $pack component that supplied the definition
     * @param array $raw
     * @return definition
     * @throws definition_exception
     */
    public static function from_array(string $pack, array $raw): definition {
        $def = new definition();
        $def->pack = $pack;

        $contract = (int) ($raw['contract'] ?? 0);
        if ($contract !== self::CONTRACT) {
            throw new definition_exception("unsupported contract version '{$contract}'");
        }

        $def->id = (string) ($raw['id'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,63}$/', $def->id)) {
            throw new definition_exception("invalid patch id '{$def->id}'");
        }

        $def->component = (string) ($raw['component'] ?? '');
        $root = \core_component::get_component_directory($def->component);
        if ($root === null || !is_dir($root)) {
            throw new definition_exception("target component '{$def->component}' is not installed");
        }

        $def->revision = (int) ($raw['revision'] ?? 0);
        if ($def->revision < 1) {
            throw new definition_exception("invalid revision for '{$def->id}'");
        }

        $def->name = (string) ($raw['name'] ?? $def->id);
        $def->description = (string) ($raw['description'] ?? '');
        $def->required = !empty($raw['required']);
        $def->dependencies = array_values(array_map('strval', $raw['dependencies'] ?? []));
        $def->testedversions = array_values(array_map('intval', $raw['testedversions'] ?? []));
        $def->baselinehashes = (array) ($raw['baselinehashes'] ?? []);

        $files = $raw['files'] ?? [];
        if (!is_array($files) || empty($files)) {
            throw new definition_exception("patch '{$def->id}' declares no files");
        }

        $def->files = [];
        foreach ($files as $relpath => $rawhunks) {
            $relpath = (string) $relpath;
            self::validate_path($def, $relpath);

            if (!is_array($rawhunks) || empty($rawhunks)) {
                throw new definition_exception("patch '{$def->id}' declares no hunks for '{$relpath}'");
            }

            $hunks = [];
            foreach (array_values($rawhunks) as $index => $rawhunk) {
                $hunks[] = self::validate_hunk($def, $relpath, $index, (array) $rawhunk);
            }
            $def->files[$relpath] = $hunks;
        }

        return $def;
    }

    /**
     * Reject forbidden, escaping or missing target files.
     *
     * @param definition $def
     * @param string $relpath
     * @return void
     * @throws definition_exception
     */
    protected static function validate_path(definition $def, string $relpath): void {
        if ($relpath === '' || strpos($relpath, '..') !== false || strpos($relpath, "\0") !== false) {
            throw new definition_exception("invalid target path '{$relpath}'");
        }
        if ($relpath[0] === '/' || $relpath[0] === '\\' || preg_match('/^[a-zA-Z]:/', $relpath)) {
            throw new definition_exception("target path must be relative: '{$relpath}'");
        }

        foreach (self::FORBIDDEN as $forbidden) {
            if (substr($forbidden, -1) === '/') {
                if (strpos($relpath, $forbidden) === 0) {
                    throw new definition_exception("patching '{$relpath}' is forbidden");
                }
            } else if ($relpath === $forbidden) {
                throw new definition_exception("patching '{$relpath}' is forbidden");
            }
        }

        $full = $def->file_path($relpath);
        $realroot = realpath($def->component_root());
        $realfile = realpath($full);
        if ($realfile === false || !is_file($realfile)) {
            throw new definition_exception("target file does not exist: '{$relpath}'");
        }
        if ($realroot === false || strpos($realfile, $realroot) !== 0) {
            throw new definition_exception("target file escapes the component directory: '{$relpath}'");
        }
    }

    /**
     * Validate one hunk, including its mandatory markers.
     *
     * @param definition $def
     * @param string $relpath
     * @param int $index
     * @param array $raw
     * @return hunk
     * @throws definition_exception
     */
    protected static function validate_hunk(definition $def, string $relpath, int $index, array $raw): hunk {
        $type = (string) ($raw['type'] ?? '');
        if (!in_array($type, hunk::types(), true)) {
            throw new definition_exception("invalid hunk type '{$type}' in '{$relpath}'");
        }

        $anchor = (string) ($raw['anchor'] ?? '');
        if (trim($anchor) === '') {
            throw new definition_exception("empty anchor in '{$relpath}' hunk {$index}");
        }

        $payload = (string) ($raw['payload'] ?? '');
        if (trim($payload) === '') {
            throw new definition_exception("empty payload in '{$relpath}' hunk {$index}");
        }

        // The marker is load bearing: detection depends on it.
        $begin = $def->marker('BEGIN', $def->revision);
        $end = $def->marker('END', $def->revision);
        if (util::count($payload, $begin) !== 1 || util::count($payload, $end) !== 1) {
            throw new definition_exception(
                "hunk {$index} of '{$relpath}' must contain exactly one '{$begin}' and one '{$end}' marker");
        }
        if (strpos($payload, $begin) > strpos($payload, $end)) {
            throw new definition_exception("markers out of order in hunk {$index} of '{$relpath}'");
        }

        // No stray markers for another revision inside our own payload.
        if (preg_match_all($def->marker_pattern(), $payload, $matches) !== 2) {
            throw new definition_exception("unexpected marker content in hunk {$index} of '{$relpath}'");
        }
        foreach ($matches[2] as $revision) {
            if ((int) $revision !== $def->revision) {
                throw new definition_exception("marker revision mismatch in hunk {$index} of '{$relpath}'");
            }
        }

        $label = (string) ($raw['label'] ?? ($relpath . ' #' . ($index + 1)));

        return new hunk($type, $anchor, $payload, $index, $label);
    }

    /**
     * Unique key of this patch.
     *
     * @return string
     */
    public function key(): string {
        return $this->pack . ':' . $this->id;
    }

    /**
     * A marker line body, for example "BEGIN local_zoomcustom:001-period-grading r1".
     *
     * @param string $kind BEGIN or END
     * @param int $revision
     * @return string
     */
    public function marker(string $kind, int $revision): string {
        return $kind . ' ' . $this->pack . ':' . $this->id . ' r' . $revision;
    }

    /**
     * Regular expression matching any revision of this patch's markers.
     *
     * @return string
     */
    public function marker_pattern(): string {
        return '/\b(BEGIN|END)\s+' . preg_quote($this->pack . ':' . $this->id, '/') . '\s+r(\d+)\b/';
    }

    /**
     * Absolute path of the target component.
     *
     * @return string
     */
    public function component_root(): string {
        $root = \core_component::get_component_directory($this->component);
        return $root === null ? '' : $root;
    }

    /**
     * Absolute path of one target file.
     *
     * @param string $relpath
     * @return string
     */
    public function file_path(string $relpath): string {
        return $this->component_root() . '/' . ltrim($relpath, '/');
    }

    /**
     * Absolute paths of every target file.
     *
     * @return string[]
     */
    public function file_paths(): array {
        $paths = [];
        foreach (array_keys($this->files) as $relpath) {
            $paths[$relpath] = $this->file_path($relpath);
        }
        return $paths;
    }

    /**
     * Path of a file relative to dirroot, used for backups and audit records.
     *
     * @param string $relpath
     * @return string
     */
    public function dirroot_relative(string $relpath): string {
        global $CFG;
        $full = $this->file_path($relpath);
        $dirroot = rtrim(str_replace('\\', '/', $CFG->dirroot), '/');
        $full = str_replace('\\', '/', $full);
        if (strpos($full, $dirroot . '/') === 0) {
            return substr($full, strlen($dirroot) + 1);
        }
        return $full;
    }
}
