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

use local_patchmanager\local\hunk;
use local_patchmanager\local\util;

/**
 * Tests for exact matching, insertion and state aggregation.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_patchmanager\local\hunk
 * @covers     \local_patchmanager\local\util
 * @covers     \local_patchmanager\state
 */
final class matching_test extends \basic_testcase {

    /** @var string A small stand in for an upstream file. */
    private const SOURCE = "<?php\nfunction demo() {\n    \$a = 1;\n\n    // anchor line.\n    \$b = 2;\n}\n";

    /**
     * Insert before keeps the anchor and places the payload in front of it.
     *
     * @return void
     */
    public function test_insert_before(): void {
        $anchor = "    // anchor line.\n    \$b = 2;";
        $payload = "    // BEGIN local_demo:001-demo r1\n    demo_hook();\n    // END local_demo:001-demo r1\n\n";
        $hunk = new hunk(hunk::TYPE_INSERT_BEFORE, $anchor, $payload, 0);

        $offset = strpos(self::SOURCE, $anchor);
        $result = $hunk->apply_at(self::SOURCE, $offset, "\n");

        $this->assertStringContainsString($payload . $anchor, $result);
        $this->assertSame(1, util::count($result, $payload));
        $this->assertSame(1, util::count($result, $anchor));
        $this->assertTrue(util::php_syntax_ok($result));
    }

    /**
     * Insert after places the payload directly behind the anchor.
     *
     * @return void
     */
    public function test_insert_after(): void {
        $anchor = "    \$a = 1;";
        $payload = "\n\n    // BEGIN local_demo:001-demo r1\n    demo_hook();\n    // END local_demo:001-demo r1";
        $hunk = new hunk(hunk::TYPE_INSERT_AFTER, $anchor, $payload, 0);

        $result = $hunk->apply_at(self::SOURCE, strpos(self::SOURCE, $anchor), "\n");

        $this->assertStringContainsString($anchor . $payload, $result);
        $this->assertTrue(util::php_syntax_ok($result));
    }

    /**
     * Replace swaps the anchor for the payload.
     *
     * @return void
     */
    public function test_replace(): void {
        $anchor = "    \$b = 2;";
        $payload = "    // BEGIN local_demo:001-demo r1\n    \$b = 3;\n    // END local_demo:001-demo r1";
        $hunk = new hunk(hunk::TYPE_REPLACE, $anchor, $payload, 0);

        $result = $hunk->apply_at(self::SOURCE, strpos(self::SOURCE, $anchor), "\n");

        $this->assertStringNotContainsString("\$b = 2;", $result);
        $this->assertStringContainsString("\$b = 3;", $result);
        $this->assertTrue(util::php_syntax_ok($result));
    }

    /**
     * A file using CRLF is matched and written with CRLF.
     *
     * @return void
     */
    public function test_crlf_files(): void {
        $source = str_replace("\n", "\r\n", self::SOURCE);
        $eol = util::detect_eol($source);
        $this->assertSame("\r\n", $eol);

        $hunk = new hunk(hunk::TYPE_INSERT_BEFORE,
                "    // anchor line.\n    \$b = 2;",
                "    // BEGIN local_demo:001-demo r1\n    demo_hook();\n    // END local_demo:001-demo r1\n\n", 0);

        $anchor = $hunk->anchor_for($eol);
        $this->assertSame(1, util::count($source, $anchor));

        $result = $hunk->apply_at($source, strpos($source, $anchor), $eol);
        $this->assertStringNotContainsString("demo_hook();\n", $result);
        $this->assertStringContainsString("demo_hook();\r\n", $result);
    }

    /**
     * Broken output is rejected before anything is written.
     *
     * @return void
     */
    public function test_syntax_check(): void {
        $error = null;
        $this->assertFalse(util::php_syntax_ok("<?php function broken( {", $error));
        $this->assertNotEmpty($error);
        $this->assertTrue(util::php_syntax_ok("<?php \$x = 1;"));
    }

    /**
     * Aggregation of per file states.
     *
     * @return void
     */
    public function test_state_aggregation(): void {
        $this->assertSame(state::APPLIED, state::aggregate([state::APPLIED, state::APPLIED]));
        $this->assertSame(state::NOT_APPLIED, state::aggregate([state::NOT_APPLIED, state::NOT_APPLIED]));

        // A mixture of patched and unpatched files is never "applied".
        $this->assertSame(state::PARTIAL, state::aggregate([state::APPLIED, state::NOT_APPLIED]));
        $this->assertSame(state::PARTIAL, state::aggregate([state::OUTDATED, state::CONFLICT]));

        $this->assertSame(state::UNKNOWN, state::aggregate([state::UNKNOWN, state::APPLIED]));
        $this->assertSame(state::PARTIAL, state::aggregate([state::PARTIAL, state::APPLIED]));
        $this->assertSame(state::CONFLICT, state::aggregate([state::CONFLICT, state::CONFLICT]));
        $this->assertSame(state::OUTDATED, state::aggregate([state::OUTDATED, state::APPLIED]));
        $this->assertSame(state::UNKNOWN, state::aggregate([]));
    }
}
