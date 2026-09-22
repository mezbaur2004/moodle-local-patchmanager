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
use local_patchmanager\output\ui;

/**
 * Tests for the review screen's "apply from the server" CLI instruction.
 *
 * ui::review() always includes this: it is purely informational, tied to one
 * already-registered customisation, and independent of the web-apply switch
 * and of every existing Apply/Remove/Reapply/Verify/Acknowledge behaviour.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_patchmanager\output\ui
 */
final class applyhint_test extends \basic_testcase {

    /**
     * Build a status for one registered customisation, with only the fields
     * ui::applyhint() and ui::review() read.
     *
     * @return status
     */
    private function status(): status {
        $definition = new definition();
        $definition->pack = 'local_zoomcustom';
        $definition->id = '001-period-grading';
        $definition->name = 'Period grading';
        $definition->description = 'Adjusts period grading behaviour.';

        $status = new status();
        $status->definition = $definition;

        return $status;
    }

    /**
     * The command names the real script, and the real pack:patchid key, cd'd
     * into the real $CFG->dirroot.
     *
     * @return void
     */
    public function test_applyhint_shows_dynamic_key_and_dirroot(): void {
        global $CFG;

        $html = ui::applyhint($this->status());

        $this->assertStringContainsString('cd ' . $CFG->dirroot, $html);
        $this->assertStringContainsString(
            'sudo -u www-data php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading',
            $html
        );
    }

    /**
     * The instruction is explicit that this applies an existing, already
     * registered customisation, not some generic or new patch.
     *
     * @return void
     */
    public function test_applyhint_clarifies_it_applies_an_existing_patch(): void {
        $html = ui::applyhint($this->status());

        $this->assertStringContainsString(
            get_string('applyhint', 'local_patchmanager'),
            $html
        );
        $this->assertStringContainsString('already registered', $html);
    }

    /**
     * A different customisation's key appears verbatim, not a placeholder.
     *
     * @return void
     */
    public function test_applyhint_uses_the_actual_patch_key(): void {
        $definition = new definition();
        $definition->pack = 'local_zoomcustom';
        $definition->id = '002-something-else';
        $definition->name = 'Something else';
        $definition->description = '';

        $status = new status();
        $status->definition = $definition;

        $html = ui::applyhint($status);

        $this->assertStringContainsString('--patch=local_zoomcustom:002-something-else', $html);
        $this->assertStringNotContainsString('PATCH_KEY', $html);
    }

    /**
     * ui::review() includes the CLI instruction, so the review screen shows
     * it without index.php having to call it separately.
     *
     * @return void
     */
    public function test_review_includes_the_apply_hint(): void {
        $html = ui::review($this->status());

        $this->assertStringContainsString(
            'sudo -u www-data php local/patchmanager/cli/apply.php --patch=local_zoomcustom:001-period-grading',
            $html
        );
    }
}
