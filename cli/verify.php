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
 * Record that a customisation has been verified on the installed target version.
 *
 * This writes one verification record and no code. It exists so that a site
 * which keeps browser code-writing switched off still has a supported way to
 * complete the apply/verify lifecycle.
 *
 * Verifying an active required customisation can resume work that the pack
 * paused while it was unverified, so it is an explicit administrative action.
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
    ['help' => false, 'patch' => '', 'note' => ''],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknownoption', 'admin', implode(PHP_EOL, $unrecognised)));
}

if ($options['help'] || $options['patch'] === '') {
    cli_writeln("Record a verification for one customisation.

Options:
  -h, --help        Print this help.
      --patch=KEY   Required, for example local_zoomcustom:001-period-grading.
      --note=TEXT   Optional note stored with the verification record.

The customisation must be applied. The verification is recorded against the
currently installed version of the target component, so it stops applying once
that component is upgraded.
");
    exit(0);
}

\core\session\manager::set_user(get_admin());

// Verifying writes a record, not code, so it does not require the web-apply
// switch. Site admin and the manage capability are still required.
api::require_manage_action('verify', false);

registry::reset_cache();

$definitions = registry::get_definitions();
if (!isset($definitions[$options['patch']])) {
    cli_error('Unknown patch: ' . $options['patch']);
}

$definition = $definitions[$options['patch']];
$status = api::build_status($definition);

if ($status->state !== state::APPLIED) {
    cli_writeln($options['patch'] . ': cannot verify while the state is ' . $status->state);
    exit(1);
}

if ($status->verified) {
    cli_writeln($options['patch'] . ': already verified ('
            . ($status->verifiedsource ?? 'unknown source') . '), nothing to do.');
    exit(0);
}

api::verify($definition, (string) $options['note']);

$after = api::build_status($definition);

cli_writeln($options['patch'] . ': verified against ' . $definition->component
        . ' ' . (string) $after->componentversion->versiondisk);
cli_writeln('    state: ' . $after->state
        . ($after->verified ? ' (verified)' : ' (STILL UNVERIFIED)')
        . ($after->required ? ' [required]' : ''));

exit($after->verified ? 0 : 1);
