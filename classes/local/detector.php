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

use local_patchmanager\state;

/**
 * Computes the state of a patch from the files on disk.
 *
 * Nothing here reads a stored state. The database is history only.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class detector {

    /**
     * Evaluate one patch.
     *
     * @param definition $def
     * @return array [state, files, reasons, appliedrevision]
     */
    public static function evaluate(definition $def): array {
        $files = [];
        foreach ($def->files as $relpath => $hunks) {
            $files[$relpath] = self::evaluate_file($def, $relpath, $hunks);
        }

        $states = [];
        $reasons = [];
        $revisions = [];
        foreach ($files as $file) {
            $states[] = $file->state;
            foreach ($file->reasons as $reason) {
                $reasons[] = $reason;
            }
            if ($file->appliedrevision !== null) {
                $revisions[$file->appliedrevision] = true;
            }
        }

        $state = state::aggregate($states);

        // A mixture of revisions on disk is never clean.
        if (count($revisions) > 1 && $state !== state::UNKNOWN) {
            $state = state::PARTIAL;
            $reasons[] = get_string('reasonmixedrevisions', 'local_patchmanager', implode(', ', array_keys($revisions)));
        }

        $appliedrevision = count($revisions) === 1 ? (int) array_key_first($revisions) : null;

        return [$state, $files, $reasons, $appliedrevision];
    }

    /**
     * Evaluate one file of a patch.
     *
     * @param definition $def
     * @param string $relpath
     * @param hunk[] $hunks
     * @return \stdClass
     */
    protected static function evaluate_file(definition $def, string $relpath, array $hunks): \stdClass {
        $file = (object) [
            'relpath' => $relpath,
            'path' => $def->file_path($relpath),
            'state' => state::UNKNOWN,
            'reasons' => [],
            'hunks' => [],
            'sha256' => null,
            'size' => null,
            'mtime' => null,
            'appliedrevision' => null,
            'baselinematch' => null,
            'eol' => "\n",
        ];

        if (!file_exists($file->path) || !is_file($file->path)) {
            $file->reasons[] = get_string('reasonfilemissing', 'local_patchmanager', $relpath);
            return $file;
        }
        if (!is_readable($file->path)) {
            $file->reasons[] = get_string('reasonfileunreadable', 'local_patchmanager', $relpath);
            return $file;
        }

        $content = @file_get_contents($file->path);
        if ($content === false) {
            $file->reasons[] = get_string('reasonfileunreadable', 'local_patchmanager', $relpath);
            return $file;
        }

        $file->sha256 = util::hash($content);
        $file->size = strlen($content);
        $file->mtime = @filemtime($file->path) ?: null;
        $file->eol = util::detect_eol($content);

        if (isset($def->baselinehashes[$relpath])) {
            $file->baselinematch = ($def->baselinehashes[$relpath] === $file->sha256);
        }

        // Which revisions of our markers are present in this file?
        $begins = [];
        $ends = [];
        if (preg_match_all($def->marker_pattern(), $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $revision = (int) $match[2];
                if (strtoupper($match[1]) === 'BEGIN') {
                    $begins[$revision] = ($begins[$revision] ?? 0) + 1;
                } else {
                    $ends[$revision] = ($ends[$revision] ?? 0) + 1;
                }
            }
        }
        $markercount = array_sum($begins);
        $revisions = array_keys($begins);

        // Per hunk facts.
        $allapplied = true;
        $allapplicable = true;
        foreach ($hunks as $hunk) {
            $anchor = $hunk->anchor_for($file->eol);
            $payload = $hunk->payload_for($file->eol);
            $anchorcount = util::count($content, $anchor);
            $payloadcount = util::count($content, $payload);

            $hunkstate = 'conflict';
            if ($payloadcount === 1) {
                $hunkstate = 'applied';
            } else if ($payloadcount > 1) {
                $hunkstate = 'ambiguous_payload';
            } else if ($anchorcount === 1) {
                $hunkstate = 'applicable';
            } else if ($anchorcount === 0) {
                $hunkstate = 'anchor_missing';
            } else {
                $hunkstate = 'anchor_ambiguous';
            }

            if ($hunkstate !== 'applied') {
                $allapplied = false;
            }
            if ($hunkstate !== 'applicable') {
                $allapplicable = false;
            }

            $file->hunks[] = (object) [
                'index' => $hunk->index,
                'label' => $hunk->label,
                'type' => $hunk->type,
                'state' => $hunkstate,
                'anchorcount' => $anchorcount,
                'payloadcount' => $payloadcount,
                'anchor' => $hunk->anchor,
                'payload' => $hunk->payload,
            ];
        }

        $hunkcount = count($hunks);

        if ($markercount === 0) {
            if ($allapplicable) {
                $file->state = state::NOT_APPLIED;
            } else {
                $file->state = state::CONFLICT;
                foreach ($file->hunks as $hunkinfo) {
                    if ($hunkinfo->state !== 'applicable') {
                        $file->reasons[] = get_string('reasonhunk_' . $hunkinfo->state, 'local_patchmanager',
                                (object) ['file' => $relpath, 'hunk' => $hunkinfo->label]);
                    }
                }
            }
            return $file;
        }

        // Markers are present, so some of our code is on disk.
        $singlerevision = (count($revisions) === 1) ? (int) $revisions[0] : null;
        $balanced = ($begins == $ends);

        if ($allapplied && $singlerevision === $def->revision && $markercount === $hunkcount && $balanced) {
            $file->state = state::APPLIED;
            $file->appliedrevision = $def->revision;
            return $file;
        }

        if ($singlerevision !== null && $singlerevision !== $def->revision
                && $markercount === $hunkcount && $balanced) {
            $file->state = state::OUTDATED;
            $file->appliedrevision = $singlerevision;
            $file->reasons[] = get_string('reasonoldrevision', 'local_patchmanager',
                    (object) ['file' => $relpath, 'found' => $singlerevision, 'expected' => $def->revision]);
            return $file;
        }

        $file->state = state::PARTIAL;
        $file->appliedrevision = $singlerevision;
        if ($markercount !== $hunkcount) {
            $file->reasons[] = get_string('reasonmarkercount', 'local_patchmanager',
                    (object) ['file' => $relpath, 'found' => $markercount, 'expected' => $hunkcount]);
        }
        if (!$balanced) {
            $file->reasons[] = get_string('reasonmarkerunbalanced', 'local_patchmanager', $relpath);
        }
        foreach ($file->hunks as $hunkinfo) {
            if ($hunkinfo->state !== 'applied') {
                $file->reasons[] = get_string('reasonhunk_' . $hunkinfo->state, 'local_patchmanager',
                        (object) ['file' => $relpath, 'hunk' => $hunkinfo->label]);
            }
        }

        return $file;
    }
}
