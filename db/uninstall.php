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
 * Uninstall handler for local_patchmanager.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Refuse to uninstall while customisations are still on disk.
 *
 * Called by uninstall_plugin() in lib/adminlib.php, which ignores the return
 * value, so refusing means throwing.
 *
 * Removing the engine while patched code remains would leave modifications
 * behind with nothing watching them.
 *
 * @return bool
 * @throws moodle_exception when any customisation is still present
 */
function xmldb_local_patchmanager_uninstall(): bool {
    $active = [];

    foreach (\local_patchmanager\api::get_statuses() as $key => $status) {
        if (in_array($status->state, [
            \local_patchmanager\state::APPLIED,
            \local_patchmanager\state::OUTDATED,
            \local_patchmanager\state::PARTIAL,
        ], true)) {
            $active[] = $key . ' (' . $status->state . ')';
        }
    }

    if ($active) {
        throw new moodle_exception('erruninstallactive', 'local_patchmanager', '', implode(', ', $active));
    }

    // Backups are deliberately left in moodledata: they are the only way back
    // to stock code if something was missed.

    return true;
}
