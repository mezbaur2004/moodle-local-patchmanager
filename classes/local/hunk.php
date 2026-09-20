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
 * One anchored change inside one file.
 *
 * A hunk is an exact anchor string plus an exact payload string. There is no
 * fuzzy matching, no context tolerance and no line number identity. The anchor
 * must occur exactly once for the hunk to be applicable, and the payload must
 * occur exactly once for it to count as applied.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hunk {

    /** @var string Insert the payload immediately before the anchor. */
    public const TYPE_INSERT_BEFORE = 'insert_before';

    /** @var string Insert the payload immediately after the anchor. */
    public const TYPE_INSERT_AFTER = 'insert_after';

    /** @var string Replace the anchor with the payload. */
    public const TYPE_REPLACE = 'replace';

    /** @var string */
    public $type;

    /** @var string Exact upstream text used to locate the change. */
    public $anchor;

    /** @var string Exact text written by this patch, including its markers. */
    public $payload;

    /** @var int Zero based position of this hunk inside its file. */
    public $index;

    /** @var string Short label shown on the review screen. */
    public $label;

    /**
     * Constructor.
     *
     * @param string $type
     * @param string $anchor
     * @param string $payload
     * @param int $index
     * @param string $label
     */
    public function __construct(string $type, string $anchor, string $payload, int $index, string $label = '') {
        $this->type = $type;
        $this->anchor = $anchor;
        $this->payload = $payload;
        $this->index = $index;
        $this->label = $label;
    }

    /**
     * Valid hunk types.
     *
     * @return string[]
     */
    public static function types(): array {
        return [self::TYPE_INSERT_BEFORE, self::TYPE_INSERT_AFTER, self::TYPE_REPLACE];
    }

    /**
     * The anchor converted to the end of line convention of the target file.
     *
     * @param string $eol
     * @return string
     */
    public function anchor_for(string $eol): string {
        return util::to_eol($this->anchor, $eol);
    }

    /**
     * The payload converted to the end of line convention of the target file.
     *
     * @param string $eol
     * @return string
     */
    public function payload_for(string $eol): string {
        return util::to_eol($this->payload, $eol);
    }

    /**
     * The exact contiguous text this hunk leaves behind once applied.
     *
     * Detection compares against this rather than against the payload alone.
     * A payload that is present but no longer joined to its anchor no longer
     * runs where it was designed to run, so presence on its own is not
     * evidence that the customisation is active.
     *
     * @param string $eol
     * @return string
     */
    public function applied_form(string $eol): string {
        $anchor = $this->anchor_for($eol);
        $payload = $this->payload_for($eol);

        switch ($this->type) {
            case self::TYPE_INSERT_BEFORE:
                return $payload . $anchor;
            case self::TYPE_INSERT_AFTER:
                return $anchor . $payload;
            case self::TYPE_REPLACE:
            default:
                // The anchor is consumed, so the payload itself is the whole edit.
                return $payload;
        }
    }

    /**
     * Apply this hunk to the given content.
     *
     * The caller must already have checked that the anchor occurs exactly once.
     *
     * @param string $content
     * @param int $offset offset of the single anchor occurrence
     * @param string $eol
     * @return string the new content
     */
    public function apply_at(string $content, int $offset, string $eol): string {
        $anchor = $this->anchor_for($eol);
        $payload = $this->payload_for($eol);

        switch ($this->type) {
            case self::TYPE_INSERT_BEFORE:
                return substr($content, 0, $offset) . $payload . substr($content, $offset);
            case self::TYPE_INSERT_AFTER:
                $end = $offset + strlen($anchor);
                return substr($content, 0, $end) . $payload . substr($content, $end);
            case self::TYPE_REPLACE:
            default:
                $end = $offset + strlen($anchor);
                return substr($content, 0, $offset) . $payload . substr($content, $end);
        }
    }
}
