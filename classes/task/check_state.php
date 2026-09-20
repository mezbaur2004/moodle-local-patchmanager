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

namespace local_patchmanager\task;

use local_patchmanager\api;
use local_patchmanager\local\audit;
use local_patchmanager\local\registry;

/**
 * Recomputes patch state and lets the packs react.
 *
 * This task never modifies code. Applying a patch is always an explicit
 * administrative or deployment action.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_state extends \core\task\scheduled_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskcheckstate', 'local_patchmanager');
    }

    /**
     * Run the check.
     *
     * @return void
     */
    public function execute(): void {
        registry::reset_cache();

        $cleared = api::prune_acknowledgements();
        if ($cleared) {
            mtrace("Cleared {$cleared} stale acknowledgement(s).");
        }

        foreach (api::get_statuses() as $key => $status) {
            $last = audit::latest($status->definition->pack, $status->definition->id,
                    [audit::STATE_CHECK, audit::APPLY_SUCCESS, audit::RESTORE_SUCCESS]);
            $previous = $last ? $last->stateafter : null;

            mtrace("{$key}: {$status->state}"
                    . ($status->verified ? ' (verified)' : ' (unverified)')
                    . ($status->required ? ' [required]' : ''));

            if ($previous !== $status->state) {
                audit::log(audit::STATE_CHECK, $status->definition, [
                    'statebefore' => $previous,
                    'stateafter' => $status->state,
                    'result' => 'changed',
                    'detail' => ['reasons' => $status->reasons],
                ]);
            }
        }

        // Packs decide what to do about the current states.
        api::notify_packs();
    }
}
