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
 * Discovery and validation of patch packs.
 *
 * Any plugin may become a pack by declaring a patchmanager_patches() function
 * in its lib.php. The engine knows nothing about what the patches do.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {

    /** @var definition[]|null Cached definitions keyed by pack:id. */
    protected static $definitions = null;

    /** @var array|null Cached load errors keyed by pack. */
    protected static $errors = null;

    /**
     * All valid definitions, keyed by pack:id.
     *
     * @return definition[]
     */
    public static function get_definitions(): array {
        self::load();
        return self::$definitions;
    }

    /**
     * Definition load errors, keyed by "pack" or "pack:id".
     *
     * @return array
     */
    public static function get_errors(): array {
        self::load();
        return self::$errors;
    }

    /**
     * One definition or null.
     *
     * @param string $pack
     * @param string $patchid
     * @return definition|null
     */
    public static function get_definition(string $pack, string $patchid): ?definition {
        self::load();
        return self::$definitions[$pack . ':' . $patchid] ?? null;
    }

    /**
     * Every definition that touches the given file of the given component,
     * in stable apply order.
     *
     * @param string $component
     * @param string $relpath
     * @param string|null $excludekey skip this patch
     * @return definition[]
     */
    public static function definitions_for_file(string $component, string $relpath, ?string $excludekey = null): array {
        $result = [];
        foreach (self::get_definitions() as $key => $def) {
            if ($def->component !== $component || $key === $excludekey) {
                continue;
            }
            if (isset($def->files[$relpath])) {
                $result[$key] = $def;
            }
        }
        return $result;
    }

    /**
     * Forget cached definitions, used by tests and by the CLI.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$definitions = null;
        self::$errors = null;
    }

    /**
     * Discover and validate.
     *
     * @return void
     */
    protected static function load(): void {
        if (self::$definitions !== null) {
            return;
        }

        self::$definitions = [];
        self::$errors = [];

        $functions = get_plugins_with_function('patchmanager_patches', 'lib.php');
        foreach ($functions as $plugintype => $plugins) {
            foreach ($plugins as $pluginname => $functionname) {
                $pack = $plugintype . '_' . $pluginname;
                try {
                    $raw = $functionname();
                } catch (\Throwable $e) {
                    self::$errors[$pack] = $e->getMessage();
                    continue;
                }

                if (!is_array($raw)) {
                    self::$errors[$pack] = 'patchmanager_patches() must return an array';
                    continue;
                }

                foreach ($raw as $rawdefinition) {
                    try {
                        $def = definition::from_array($pack, (array) $rawdefinition);
                    } catch (\Throwable $e) {
                        $id = is_array($rawdefinition) ? ($rawdefinition['id'] ?? '?') : '?';
                        self::$errors[$pack . ':' . $id] = $e->getMessage();
                        continue;
                    }

                    if (isset(self::$definitions[$def->key()])) {
                        self::$errors[$def->key()] = 'duplicate patch id';
                        continue;
                    }

                    self::$definitions[$def->key()] = $def;
                }
            }
        }

        ksort(self::$definitions);
    }
}
