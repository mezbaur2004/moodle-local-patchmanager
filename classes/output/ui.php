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

namespace local_patchmanager\output;

use local_patchmanager\state;
use local_patchmanager\status;

/**
 * HTML fragments for the patch manager page.
 *
 * There is deliberately no code editor here. The review screen shows what the
 * patch expects and what is actually on disk, and nothing more.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ui {

    /**
     * Coloured badge for a state.
     *
     * @param status $status
     * @return string
     */
    public static function badge(status $status): string {
        $severity = $status->severity();
        $classes = [
            'ok' => 'badge badge-success bg-success text-white',
            'warning' => 'badge badge-warning bg-warning text-dark',
            'error' => 'badge badge-danger bg-danger text-white',
        ];
        $symbols = ['ok' => '✓', 'warning' => '⚠', 'error' => '✗'];

        $label = $symbols[$severity] . ' ' . $status->state_label();
        if ($status->state === state::APPLIED) {
            $label .= ' — ' . ($status->verified
                    ? get_string('verified', 'local_patchmanager')
                    : get_string('unverified', 'local_patchmanager'));
        }

        return \html_writer::span($label, $classes[$severity]);
    }

    /**
     * The status table.
     *
     * @param status[] $statuses
     * @param \moodle_url $baseurl
     * @return string
     */
    public static function table(array $statuses, \moodle_url $baseurl): string {
        $table = new \html_table();
        $table->head = [
            get_string('target', 'local_patchmanager'),
            get_string('customisation', 'local_patchmanager'),
            get_string('status', 'local_patchmanager'),
            get_string('attributes', 'local_patchmanager'),
            get_string('actions', 'local_patchmanager'),
        ];
        $table->attributes['class'] = 'generaltable';

        foreach ($statuses as $status) {
            $def = $status->definition;
            $version = $status->componentversion;

            $target = \html_writer::tag('strong', $def->component);
            $target .= \html_writer::empty_tag('br');
            $target .= \html_writer::span(get_string('versiondisk', 'local_patchmanager',
                    $version->versiondisk ?? '?'), 'small text-muted');
            if ($version->upgradepending) {
                $target .= \html_writer::empty_tag('br');
                $target .= \html_writer::span(get_string('upgradepending', 'local_patchmanager',
                        $version->versiondb ?? '?'), 'small text-danger');
            }

            $name = \html_writer::tag('strong', s($def->name));
            $name .= \html_writer::empty_tag('br');
            $name .= \html_writer::span(s($def->key()) . ' r' . $def->revision, 'small text-muted');
            if ($def->required) {
                $name .= ' ' . \html_writer::span(get_string('required', 'local_patchmanager'), 'badge badge-info bg-info');
            }

            $table->data[] = [
                $target,
                $name,
                self::badge($status) . self::reasons($status),
                self::attributes($status),
                self::actions($status, $baseurl),
            ];
        }

        return \html_writer::table($table);
    }

    /**
     * Short reason list under the badge.
     *
     * @param status $status
     * @return string
     */
    protected static function reasons(status $status): string {
        if (empty($status->reasons)) {
            return '';
        }
        $items = array_map(function($reason) {
            return \html_writer::tag('li', s($reason));
        }, array_slice($status->reasons, 0, 4));
        return \html_writer::tag('ul', implode('', $items), ['class' => 'small text-muted mt-1 mb-0']);
    }

    /**
     * Attribute column.
     *
     * @param status $status
     * @return string
     */
    protected static function attributes(status $status): string {
        $lines = [];

        if ($status->appliedrevision !== null) {
            $lines[] = get_string('revisionondisk', 'local_patchmanager', $status->appliedrevision);
        }
        if ($status->verified) {
            $lines[] = get_string('verifiedby', 'local_patchmanager', $status->verifiedsource);
        } else if ($status->verificationstale) {
            $lines[] = get_string('verificationstale', 'local_patchmanager');
        }
        if ($status->acknowledgement) {
            $lines[] = get_string('acknowledgedby', 'local_patchmanager', (object) [
                'user' => fullname(\core_user::get_user($status->acknowledgement->userid) ?: \core_user::get_noreply_user()),
                'reason' => s($status->acknowledgement->reason),
            ]);
        }
        if (!$status->writable) {
            $lines[] = get_string('notwritable', 'local_patchmanager');
        }
        foreach ($status->blockedby as $blocked) {
            $lines[] = s($blocked);
        }
        if ($status->changedsinceapply) {
            $lines[] = get_string('changedsinceapply', 'local_patchmanager');
        }
        if (!empty($status->opcache->stale)) {
            $lines[] = get_string('opcachestale', 'local_patchmanager');
        }
        if (!$status->backupavailable) {
            $lines[] = get_string('nobackup', 'local_patchmanager');
        }

        $lines[] = get_string('node', 'local_patchmanager', s($status->hostname));

        return \html_writer::tag('div', implode(\html_writer::empty_tag('br'), $lines), ['class' => 'small']);
    }

    /**
     * State aware action buttons.
     *
     * @param status $status
     * @param \moodle_url $baseurl
     * @return string
     */
    protected static function actions(status $status, \moodle_url $baseurl): string {
        $def = $status->definition;
        $buttons = [];

        $link = function(string $action, string $label, string $class = 'btn btn-secondary btn-sm mb-1')
                use ($baseurl, $def): string {
            $url = new \moodle_url($baseurl, [
                'action' => $action,
                'pack' => $def->pack,
                'patch' => $def->id,
                'sesskey' => sesskey(),
            ]);
            return \html_writer::link($url, $label, ['class' => $class]);
        };

        $buttons[] = $link('review', get_string('review', 'local_patchmanager'));

        if ($status->can_apply()) {
            $buttons[] = $link('apply', get_string('apply', 'local_patchmanager'), 'btn btn-primary btn-sm mb-1');
        }
        if ($status->can_reapply()) {
            $buttons[] = $link('reapply', get_string('reapply', 'local_patchmanager'), 'btn btn-primary btn-sm mb-1');
        }
        if ($status->can_restore()) {
            $buttons[] = $link('restore', get_string('restore', 'local_patchmanager'));
        }
        if ($status->can_verify()) {
            $buttons[] = $link('verify', get_string('markverified', 'local_patchmanager'));
        }
        if ($status->can_acknowledge()) {
            $buttons[] = $link('acknowledge', get_string('acknowledge', 'local_patchmanager'));
        }

        return implode(' ', $buttons);
    }

    /**
     * Detailed review of one customisation.
     *
     * @param status $status
     * @return string
     */
    public static function review(status $status): string {
        $out = \html_writer::tag('p', s($status->definition->description));

        foreach ($status->files as $relpath => $file) {
            $out .= \html_writer::tag('h4', s($relpath));

            $meta = [
                get_string('filestate', 'local_patchmanager', state::label($file->state)),
                get_string('filehash', 'local_patchmanager', $file->sha256 ?? '-'),
            ];
            if ($file->baselinematch !== null) {
                $meta[] = $file->baselinematch
                        ? get_string('baselinematch', 'local_patchmanager')
                        : get_string('baselinediffer', 'local_patchmanager');
            }
            $out .= \html_writer::tag('div', implode(' · ', $meta), ['class' => 'small text-muted mb-2']);

            foreach ($file->hunks as $hunk) {
                $out .= \html_writer::tag('h5', s($hunk->label) . ' — '
                        . get_string('hunkstate_' . $hunk->state, 'local_patchmanager'));
                $out .= \html_writer::tag('div', get_string('hunkcounts', 'local_patchmanager', (object) [
                    'anchor' => $hunk->anchorcount,
                    'payload' => $hunk->payloadcount,
                ]), ['class' => 'small text-muted']);

                $out .= \html_writer::tag('p', get_string('expectedanchor', 'local_patchmanager'), ['class' => 'mb-1 mt-2']);
                $out .= \html_writer::tag('pre', s($hunk->anchor), ['class' => 'bg-light p-2 border']);
                $out .= \html_writer::tag('p', get_string('expectedpayload', 'local_patchmanager'), ['class' => 'mb-1']);
                $out .= \html_writer::tag('pre', s($hunk->payload), ['class' => 'bg-light p-2 border']);
            }
        }

        if ($status->reasons) {
            $items = array_map(function($reason) {
                return \html_writer::tag('li', s($reason));
            }, $status->reasons);
            $out .= \html_writer::tag('h4', get_string('reasons', 'local_patchmanager'));
            $out .= \html_writer::tag('ul', implode('', $items));
        }

        $out .= self::applyhint($status);

        return $out;
    }

    /**
     * CLI instruction for applying this already-registered customisation from
     * the server.
     *
     * Purely informational: it does not depend on, and does not change, which
     * actions the web UI offers, or whether $CFG->local_patchmanager_allowwebapply
     * is on. It just gives an admin looking at one registered customisation's
     * review screen the exact command that applies it, with its real
     * pack:patchid key filled in.
     *
     * @param status $status
     * @return string
     */
    public static function applyhint(status $status): string {
        global $CFG;

        $args = (object) ['key' => $status->definition->key()];

        if (!empty($CFG->dirroot)) {
            $args->dirroot = $CFG->dirroot;
            $command = get_string('applycommand', 'local_patchmanager', $args);
        } else {
            $command = get_string('applycommand_nodirroot', 'local_patchmanager', $args);
        }

        $out = \html_writer::tag('h4', get_string('applyhintheading', 'local_patchmanager'));
        $out .= \html_writer::tag('p', get_string('applyhint', 'local_patchmanager'), ['class' => 'small text-muted mb-1']);
        $out .= \html_writer::tag('pre', s($command), ['class' => 'bg-light p-2 border small']);

        return $out;
    }

    /**
     * What an apply or restore would do, rendered from a dry run plan.
     *
     * @param \stdClass $result
     * @return string
     */
    public static function plan(\stdClass $result): string {
        if (empty($result->plan)) {
            return \html_writer::tag('p', get_string('infonochange', 'local_patchmanager'));
        }

        $out = '';
        foreach ($result->plan as $relpath => $entry) {
            $out .= \html_writer::tag('h4', s($relpath));
            $out .= \html_writer::tag('div', get_string('planhashes', 'local_patchmanager', (object) [
                'before' => $entry->oldhash,
                'after' => $entry->newhash,
            ]), ['class' => 'small text-muted mb-2']);
            $out .= \html_writer::tag('pre', s(self::difference($entry->old, $entry->new)),
                    ['class' => 'bg-light p-2 border']);
        }

        return $out;
    }

    /**
     * A minimal line level difference for the confirmation screen.
     *
     * @param string $old
     * @param string $new
     * @return string
     */
    protected static function difference(string $old, string $new): string {
        $oldlines = preg_split('/\r\n|\n/', $old);
        $newlines = preg_split('/\r\n|\n/', $new);

        // Trim the common head and tail, then show what is left with a little context.
        $start = 0;
        while ($start < count($oldlines) && $start < count($newlines) && $oldlines[$start] === $newlines[$start]) {
            $start++;
        }
        $endold = count($oldlines) - 1;
        $endnew = count($newlines) - 1;
        while ($endold >= $start && $endnew >= $start && $oldlines[$endold] === $newlines[$endnew]) {
            $endold--;
            $endnew--;
        }

        $context = 3;
        $out = [];
        for ($i = max(0, $start - $context); $i < $start; $i++) {
            $out[] = '  ' . $oldlines[$i];
        }
        for ($i = $start; $i <= $endold; $i++) {
            $out[] = '- ' . $oldlines[$i];
        }
        for ($i = $start; $i <= $endnew; $i++) {
            $out[] = '+ ' . $newlines[$i];
        }
        for ($i = $endnew + 1; $i < min(count($newlines), $endnew + 1 + $context); $i++) {
            $out[] = '  ' . $newlines[$i];
        }

        return implode("\n", $out);
    }
}
