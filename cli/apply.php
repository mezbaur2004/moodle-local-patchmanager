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
 * Apply or reapply customisations. This is the primary write path.
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
use local_patchmanager\state;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'patch' => '', 'all' => false, 'dry-run' => false, 'reapply' => false],
    ['h' => 'help', 'n' => 'dry-run']
);

if ($unrecognised) {
    cli_error(get_string('cliunknownoption', 'admin', implode(PHP_EOL, $unrecognised)));
}

if ($options['help'] || (!$options['all'] && $options['patch'] === '')) {
    cli_writeln("Apply managed customisations to the target plugin code.

Options:
  -h, --help        Print this help.
      --patch=KEY   Apply one patch, for example local_zoomcustom:001-period-grading.
      --all         Apply every patch that is currently applicable.
  -n, --dry-run     Validate and show what would change, write nothing.
      --reapply     Restore the pristine files first, then apply the current revision.

Examples:
  php local/patchmanager/cli/apply.php --all --dry-run
  php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading
");
    exit(0);
}

\core\session\manager::set_user(get_admin());
api::require_manage(false);

registry::reset_cache();

$definitions = registry::get_definitions();
if ($options['patch'] !== '') {
    if (!isset($definitions[$options['patch']])) {
        cli_error('Unknown patch: ' . $options['patch']);
    }
    $definitions = [$options['patch'] => $definitions[$options['patch']]];
}

$dryrun = (bool) $options['dry-run'];
$exitcode = 0;

foreach ($definitions as $key => $definition) {
    $status = api::build_status($definition);

    $reapply = $options['reapply'] || $status->state === state::OUTDATED;

    if (!$reapply && $status->state !== state::NOT_APPLIED) {
        cli_writeln("{$key}: skipped, state is {$status->state}");
        if ($options['patch'] !== '') {
            $exitcode = 1;
        }
        continue;
    }

    if ($status->blockedby) {
        cli_writeln("{$key}: blocked - " . implode('; ', $status->blockedby));
        $exitcode = 1;
        continue;
    }

    cli_writeln(($dryrun ? '[dry run] ' : '') . ($reapply ? 'Reapplying ' : 'Applying ') . $key . ' ...');

    $result = $reapply ? api::reapply($definition, $dryrun) : api::apply($definition, $dryrun);

    foreach ($result->messages as $message) {
        cli_writeln('    ' . $message);
    }

    if ($dryrun && $result->success) {
        foreach ($result->plan as $relpath => $entry) {
            cli_writeln("    {$relpath}: {$entry->oldhash} -> {$entry->newhash}");
        }
    }

    cli_writeln('    result: ' . ($result->success ? 'ok' : 'FAILED') . ', state: ' . $result->state);

    if (!empty($result->critical)) {
        cli_writeln('    CRITICAL: rollback failed, manual recovery required. See the backup paths above.');
    }

    if (!$result->success) {
        $exitcode = 1;
    }
}

exit($exitcode);
