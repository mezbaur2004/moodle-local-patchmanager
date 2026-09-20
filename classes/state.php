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

/**
 * The six patch states and the aggregation rules between them.
 *
 * A state is never stored as authoritative data. It is always recomputed from
 * the files on disk plus the patch definition.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state {

    /** @var string No markers present, every hunk can be applied exactly once. */
    public const NOT_APPLIED = 'not_applied';

    /** @var string All markers for the current revision present, all blocks exact. */
    public const APPLIED = 'applied';

    /** @var string Cleanly present, but at an older revision. */
    public const OUTDATED = 'outdated';

    /** @var string Some of our code is present but the patch is not cleanly applied. */
    public const PARTIAL = 'partial';

    /** @var string No markers, and at least one hunk context cannot be matched exactly once. */
    public const CONFLICT = 'conflict';

    /** @var string Target file missing, unreadable or otherwise not inspectable. */
    public const UNKNOWN = 'unknown';

    /**
     * All known states.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::NOT_APPLIED,
            self::APPLIED,
            self::OUTDATED,
            self::PARTIAL,
            self::CONFLICT,
            self::UNKNOWN,
        ];
    }

    /**
     * Human readable label.
     *
     * @param string $state
     * @return string
     */
    public static function label(string $state): string {
        return get_string('state_' . $state, 'local_patchmanager');
    }

    /**
     * Severity of a state, used for sorting and for the dashboard summary.
     *
     * @param string $state
     * @return string one of ok, warning, error
     */
    public static function severity(string $state): string {
        switch ($state) {
            case self::APPLIED:
                return 'ok';
            case self::NOT_APPLIED:
            case self::OUTDATED:
                return 'warning';
            default:
                return 'error';
        }
    }

    /**
     * Aggregate per file states into one customisation state.
     *
     * Precedence: UNKNOWN > PARTIAL > CONFLICT > OUTDATED > APPLIED/NOT_APPLIED.
     * A mixture of applied and unapplied files is PARTIAL.
     *
     * @param string[] $states
     * @return string
     */
    public static function aggregate(array $states): string {
        if (empty($states)) {
            return self::UNKNOWN;
        }

        if (in_array(self::UNKNOWN, $states, true)) {
            return self::UNKNOWN;
        }

        if (in_array(self::PARTIAL, $states, true)) {
            return self::PARTIAL;
        }

        $hascustom = in_array(self::APPLIED, $states, true) || in_array(self::OUTDATED, $states, true);
        $hasstock = in_array(self::NOT_APPLIED, $states, true) || in_array(self::CONFLICT, $states, true);
        if ($hascustom && $hasstock) {
            // Part of the customisation is on disk and part of it is not.
            return self::PARTIAL;
        }

        if (in_array(self::CONFLICT, $states, true)) {
            return self::CONFLICT;
        }

        if (in_array(self::OUTDATED, $states, true)) {
            return self::OUTDATED;
        }

        if (in_array(self::NOT_APPLIED, $states, true)) {
            return self::NOT_APPLIED;
        }

        return self::APPLIED;
    }
}
