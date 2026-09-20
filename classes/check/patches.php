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

namespace local_patchmanager\check;

use core\check\check;
use core\check\result;
use local_patchmanager\api;
use local_patchmanager\state;

/**
 * Exposes patch status through Moodle's Check API, so it appears in
 * Site administration / Reports / System status and in external monitoring.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class patches extends check {

    /**
     * Link to the management page.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(new \moodle_url('/local/patchmanager/index.php'),
                get_string('pluginname', 'local_patchmanager'));
    }

    /**
     * Run the check.
     *
     * @return result
     */
    public function get_result(): result {
        $statuses = api::get_statuses();
        if (empty($statuses)) {
            return new result(result::NA, get_string('checknopatches', 'local_patchmanager'), '');
        }

        $summary = [];
        $worst = result::OK;

        foreach ($statuses as $key => $status) {
            $line = $key . ': ' . $status->state_label();
            if ($status->state === state::APPLIED && !$status->verified) {
                $line .= ' (' . get_string('unverified', 'local_patchmanager') . ')';
            }
            if ($status->acknowledgement) {
                $line .= ' (' . get_string('acknowledged', 'local_patchmanager') . ')';
            }
            $summary[] = $line;

            $severity = $status->severity();
            if ($severity === 'error' && $status->required) {
                $worst = result::CRITICAL;
            } else if ($severity === 'error' && $worst !== result::CRITICAL) {
                $worst = result::ERROR;
            } else if ($severity === 'warning' && $worst === result::OK) {
                $worst = result::WARNING;
            }
        }

        $text = implode(', ', $summary);
        return new result($worst, $text, implode('<br>', $summary));
    }
}
