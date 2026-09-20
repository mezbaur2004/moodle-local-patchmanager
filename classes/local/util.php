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

namespace local_patchmanager\local;

/**
 * Small text helpers used by the matcher and the applier.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {

    /**
     * Detect the dominant end of line sequence of a file.
     *
     * Patch definitions are authored with LF. If the target file uses CRLF we
     * convert the anchor and the payload rather than failing, and we never
     * change the file's own convention.
     *
     * @param string $content
     * @return string "\n" or "\r\n"
     */
    public static function detect_eol(string $content): string {
        $crlf = substr_count($content, "\r\n");
        if ($crlf === 0) {
            return "\n";
        }
        $lf = substr_count($content, "\n");
        return ($crlf >= ($lf - $crlf)) ? "\r\n" : "\n";
    }

    /**
     * Convert a LF authored string to the given end of line sequence.
     *
     * @param string $text
     * @param string $eol
     * @return string
     */
    public static function to_eol(string $text, string $eol): string {
        $normalised = str_replace("\r\n", "\n", $text);
        if ($eol === "\n") {
            return $normalised;
        }
        return str_replace("\n", $eol, $normalised);
    }

    /**
     * SHA-256 of a string.
     *
     * @param string $content
     * @return string
     */
    public static function hash(string $content): string {
        return hash('sha256', $content);
    }

    /**
     * Count non overlapping occurrences of a needle.
     *
     * @param string $haystack
     * @param string $needle
     * @return int
     */
    public static function count(string $haystack, string $needle): int {
        if ($needle === '') {
            return 0;
        }
        return substr_count($haystack, $needle);
    }

    /**
     * Validate PHP syntax without executing the code and without shelling out.
     *
     * @param string $code
     * @param string|null $error receives the parser message
     * @return bool
     */
    public static function php_syntax_ok(string $code, ?string &$error = null): bool {
        try {
            token_get_all($code, TOKEN_PARSE);
            return true;
        } catch (\ParseError $e) {
            $error = $e->getMessage();
            return false;
        } catch (\Error $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    /**
     * Return the 1-based line number of an offset, for reporting only.
     *
     * Line numbers are never used as identity.
     *
     * @param string $content
     * @param int $offset
     * @return int
     */
    public static function line_of_offset(string $content, int $offset): int {
        return substr_count(substr($content, 0, max(0, $offset)), "\n") + 1;
    }

    /**
     * Extract a few lines of context around an offset for the review screen.
     *
     * @param string $content
     * @param int $offset
     * @param int $lines
     * @return string
     */
    public static function excerpt(string $content, int $offset, int $lines = 3): string {
        $start = $offset;
        for ($i = 0; $i < $lines && $start > 0; $i++) {
            $prev = strrpos(substr($content, 0, $start - 1), "\n");
            if ($prev === false) {
                $start = 0;
                break;
            }
            $start = $prev + 1;
        }
        $end = $offset;
        for ($i = 0; $i < $lines * 2; $i++) {
            $next = strpos($content, "\n", $end);
            if ($next === false) {
                $end = strlen($content);
                break;
            }
            $end = $next + 1;
        }
        return substr($content, $start, $end - $start);
    }
}
