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
 * Recompute state, clear stale acknowledgements and let the packs react.
 *
 * This is the same work the scheduled task does. It never modifies code.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_patchmanager\api;
use local_patchmanager\local\registry;

[$options, $unrecognised] = cli_get_params(['help' => false], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknownoption', 'admin', implode(PHP_EOL, $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Recompute patch state and notify the packs.

Options:
  -h, --help   Print this help.
");
    exit(0);
}

\core\session\manager::set_user(get_admin());

registry::reset_cache();

$cleared = api::prune_acknowledgements();
if ($cleared) {
    cli_writeln("Cleared {$cleared} stale acknowledgement(s).");
}

$exitcode = 0;
foreach (api::get_statuses() as $key => $status) {
    cli_writeln($key . ': ' . $status->state . ($status->verified ? ' (verified)' : ' (unverified)'));
    if (!$status->is_current() && $status->required) {
        $exitcode = 1;
    }
}

api::notify_packs();

cli_writeln('Packs notified.');
exit($exitcode);
