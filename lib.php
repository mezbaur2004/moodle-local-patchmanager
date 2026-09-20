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

/**
 * Callbacks for local_patchmanager.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Status checks shown under Site administration / Reports / System status.
 *
 * @return \core\check\check[]
 */
function local_patchmanager_status_checks(): array {
    return [
        new \local_patchmanager\check\patches(),
    ];
}

// The uninstall guard lives in db/uninstall.php as xmldb_local_patchmanager_uninstall().
// Moodle has no *_pre_uninstall_hook() callback, so a function of that name here
// would never be called.
