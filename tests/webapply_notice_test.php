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

use local_patchmanager\local\definition;

/**
 * Tests for the action-specific web-apply-disabled notice and its CLI hint.
 *
 * index.php shows this notice, and only this notice, when a write action
 * (apply/reapply/restore) is requested from the browser while
 * $CFG->local_patchmanager_allowwebapply is off. These tests pin down the
 * exact rendered text so the strings and index.php's action -> string/script
 * mapping cannot drift apart silently.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_patchmanager\local\definition
 */
final class webapply_notice_test extends \basic_testcase {

    /**
     * Apply and reapply show the "Applying..." wording; restore shows "Restoring...".
     *
     * This mirrors index.php's own $verbkey selection: everything except
     * 'restore' uses errwebapplydisabled_apply.
     *
     * @return void
     */
    public function test_action_specific_disabled_message(): void {
        $this->assertSame(
            'Applying from the browser is disabled on this site.',
            get_string('errwebapplydisabled_apply', 'local_patchmanager')
        );
        $this->assertSame(
            'Restoring from the browser is disabled on this site.',
            get_string('errwebapplydisabled_restore', 'local_patchmanager')
        );

        // The original key, thrown by api::require_manage() with no $a, must be
        // untouched: it is a separate string from the two above on purpose.
        $this->assertSame(
            'Applying from the browser is disabled on this site.',
            get_string('errwebapplydisabled', 'local_patchmanager')
        );
    }

    /**
     * The CLI hint names the real patch key, the right script, and cds into
     * the Moodle root before running it, for both apply and restore.
     *
     * @return void
     */
    public function test_clihint_includes_dirroot_script_and_key(): void {
        $definition = new definition();
        $definition->pack = 'local_zoomcustom';
        $definition->id = '001-period-grading';

        $applyargs = (object) [
            'script' => 'apply',
            'key' => $definition->key(),
            'dirroot' => '/var/www/html/student.pedagoacademy.com',
        ];
        $this->assertSame(
            'Run this from the server instead: cd /var/www/html/student.pedagoacademy.com '
                . '&& sudo -u www-data php local/patchmanager/cli/apply.php '
                . '--patch=local_zoomcustom:001-period-grading',
            get_string('clihint', 'local_patchmanager', $applyargs)
        );

        $restoreargs = (object) [
            'script' => 'restore',
            'key' => $definition->key(),
            'dirroot' => '/var/www/html/student.pedagoacademy.com',
        ];
        $this->assertSame(
            'Run this from the server instead: cd /var/www/html/student.pedagoacademy.com '
                . '&& sudo -u www-data php local/patchmanager/cli/restore.php '
                . '--patch=local_zoomcustom:001-period-grading',
            get_string('clihint', 'local_patchmanager', $restoreargs)
        );
    }

    /**
     * When $CFG->dirroot cannot be trusted, the fallback string is used
     * instead: no cd step, but still the real script and patch key, plus an
     * explicit instruction to run it from the Moodle root.
     *
     * @return void
     */
    public function test_clihint_nodirroot_fallback(): void {
        $definition = new definition();
        $definition->pack = 'local_zoomcustom';
        $definition->id = '001-period-grading';

        $args = (object) ['script' => 'restore', 'key' => $definition->key()];
        $this->assertSame(
            'Run this from the Moodle root directory: sudo -u www-data php '
                . 'local/patchmanager/cli/restore.php --patch=local_zoomcustom:001-period-grading',
            get_string('clihint_nodirroot', 'local_patchmanager', $args)
        );
    }
}
