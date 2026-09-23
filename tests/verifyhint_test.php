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
 * Tests for the "verify next" instruction shown after a successful apply.
 *
 * After the target plugin is upgraded, applying the customisation again puts
 * the code back but cannot restore verification, which is tied to the target
 * version. Packs can hold behaviour back until then, so the administrator has
 * to be told the exact command.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_patchmanager\status::verify_hint
 */
final class verifyhint_test extends \basic_testcase {

    /**
     * Build a status with only the fields verify_hint() reads.
     *
     * @param string $state
     * @param bool $verified
     * @return status
     */
    private function make_status(string $state, bool $verified): status {
        $definition = new definition();
        $definition->pack = 'local_zoomcustom';
        $definition->id = '001-period-grading';
        $definition->component = 'mod_zoom';

        $status = new status();
        $status->definition = $definition;
        $status->state = $state;
        $status->verified = $verified;
        $status->componentversion = (object) ['versiondisk' => 2026100100];

        return $status;
    }

    /**
     * Applied but unverified: the hint names the version and the real verify command.
     *
     * @return void
     */
    public function test_applied_unverified_gives_the_verify_command(): void {
        global $CFG;

        $hint = $this->make_status(state::APPLIED, false)->verify_hint();

        $this->assertNotNull($hint);
        $this->assertStringContainsString('mod_zoom 2026100100', $hint);
        $this->assertStringContainsString('cd ' . $CFG->dirroot, $hint);
        $this->assertStringContainsString(
            'sudo -u www-data php local/patchmanager/cli/verify.php --patch=local_zoomcustom:001-period-grading',
            $hint
        );
    }

    /**
     * Nothing to say once it is verified.
     *
     * @return void
     */
    public function test_verified_gives_no_hint(): void {
        $this->assertNull($this->make_status(state::APPLIED, true)->verify_hint());
    }

    /**
     * Only an applied customisation can be verified, so no other state gets the hint.
     *
     * @return void
     */
    public function test_not_applied_gives_no_hint(): void {
        $this->assertNull($this->make_status(state::NOT_APPLIED, false)->verify_hint());
    }
}
