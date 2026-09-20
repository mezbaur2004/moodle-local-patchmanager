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
 * Restore the pristine files of a customisation from a verified backup.
 *
 * The patch is never reverse applied.
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
    ['help' => false, 'patch' => '', 'dry-run' => false, 'force' => false],
    ['h' => 'help', 'n' => 'dry-run']
);

if ($unrecognised) {
    cli_error(get_string('cliunknownoption', 'admin', implode(PHP_EOL, $unrecognised)));
}

if ($options['help'] || $options['patch'] === '') {
    cli_writeln("Restore the stock files of one customisation.

Options:
  -h, --help        Print this help.
      --patch=KEY   Required, for example local_zoomcustom:001-period-grading.
  -n, --dry-run     Show what would be restored, write nothing.
      --force       Restore even from an unrecognised state.

If no verified pristine backup exists, reinstall the stock package instead.
");
    exit(0);
}

\core\session\manager::set_user(get_admin());
api::require_manage(false);

registry::reset_cache();

$definitions = registry::get_definitions();
if (!isset($definitions[$options['patch']])) {
    cli_error('Unknown patch: ' . $options['patch']);
}
$definition = $definitions[$options['patch']];

$result = api::restore($definition, (bool) $options['dry-run'], (bool) $options['force']);

foreach ($result->messages as $message) {
    cli_writeln('    ' . $message);
}

cli_writeln('result: ' . ($result->success ? 'ok' : 'FAILED') . ', state: ' . $result->state);

if (!empty($result->critical)) {
    cli_writeln('CRITICAL: rollback failed, manual recovery required.');
}

exit($result->success ? 0 : 1);
