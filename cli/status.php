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
 * Print the state of every registered customisation.
 *
 * Exit code 0 when every required customisation is applied and verified,
 * 1 when something needs attention, so monitoring can use it directly.
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

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'json' => false, 'patch' => ''],
    ['h' => 'help', 'j' => 'json']
);

if ($unrecognised) {
    cli_error(get_string('cliunknownoption', 'admin', implode(PHP_EOL, $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Show the state of every managed customisation.

Options:
  -h, --help        Print this help.
  -j, --json        Machine readable output.
      --patch=KEY   Limit to one patch, for example local_zoomcustom:001-period-grading.

Example:
  php local/patchmanager/cli/status.php
");
    exit(0);
}

registry::reset_cache();

$statuses = api::get_statuses();
if ($options['patch'] !== '') {
    $statuses = array_intersect_key($statuses, [$options['patch'] => true]);
    if (empty($statuses)) {
        cli_error('Unknown patch: ' . $options['patch']);
    }
}

$errors = registry::get_errors();
$exitcode = 0;
$payload = [];

foreach ($statuses as $key => $status) {
    $version = $status->componentversion;
    $payload[$key] = [
        'state' => $status->state,
        'verified' => $status->verified,
        'required' => $status->required,
        'acknowledged' => $status->acknowledgement !== null,
        'revision' => $status->definition->revision,
        'revisionondisk' => $status->appliedrevision,
        'target' => $status->definition->component,
        'versiondisk' => $version->versiondisk,
        'versiondb' => $version->versiondb,
        'writable' => $status->writable,
        'backupavailable' => $status->backupavailable,
        'blockedby' => $status->blockedby,
        'reasons' => $status->reasons,
        'hostname' => $status->hostname,
    ];

    if (!$status->is_current() && $status->required) {
        $exitcode = 1;
    }

    if (!$options['json']) {
        cli_writeln(str_pad($key, 44) . ' ' . str_pad($status->state, 12)
                . ($status->verified ? 'verified  ' : 'unverified')
                . ($status->required ? '  [required]' : '')
                . ($status->acknowledgement ? '  [acknowledged]' : ''));
        cli_writeln('    target: ' . $status->definition->component
                . ' ' . ($version->versiondisk ?? '?')
                . ($version->upgradepending ? ' (upgrade pending, db=' . $version->versiondb . ')' : '')
                . ' | revision r' . $status->definition->revision
                . ' | node ' . $status->hostname);
        foreach ($status->reasons as $reason) {
            cli_writeln('    - ' . $reason);
        }
        foreach ($status->blockedby as $blocked) {
            cli_writeln('    ! ' . $blocked);
        }
    }
}

foreach ($errors as $key => $message) {
    $exitcode = 1;
    if ($options['json']) {
        $payload['errors'][$key] = $message;
    } else {
        cli_writeln('DEFINITION ERROR ' . $key . ': ' . $message);
    }
}

if ($options['json']) {
    cli_writeln(json_encode($payload, JSON_PRETTY_PRINT));
}

exit($exitcode);
