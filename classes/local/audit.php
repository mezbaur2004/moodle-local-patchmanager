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
 * Audit trail. Historical evidence only, never the source of current state.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit {

    /** @var string */
    public const APPLY_STARTED = 'apply_started';
    /** @var string */
    public const APPLY_SUCCESS = 'apply_success';
    /** @var string */
    public const APPLY_FAILED = 'apply_failed';
    /** @var string */
    public const RESTORE_STARTED = 'restore_started';
    /** @var string */
    public const RESTORE_SUCCESS = 'restore_success';
    /** @var string */
    public const RESTORE_FAILED = 'restore_failed';
    /** @var string */
    public const REAPPLY = 'reapply';
    /** @var string */
    public const VERIFICATION = 'verification';
    /** @var string */
    public const ACKNOWLEDGEMENT = 'acknowledgement';
    /** @var string */
    public const ACK_CLEARED = 'acknowledgement_cleared';
    /** @var string */
    public const STATE_CHECK = 'state_check';
    /** @var string */
    public const ROLLBACK_FAILED = 'rollback_failed';

    /**
     * Write one audit record.
     *
     * @param string $action
     * @param definition|null $def
     * @param array $extra keys: statebefore, stateafter, result, detail (array), targetversion
     * @return int record id
     */
    public static function log(string $action, ?definition $def, array $extra = []): int {
        global $DB, $USER;

        $record = (object) [
            'timecreated' => time(),
            'userid' => isset($USER->id) ? (int) $USER->id : 0,
            'action' => $action,
            'pack' => $def ? $def->pack : ($extra['pack'] ?? ''),
            'patchid' => $def ? $def->id : ($extra['patchid'] ?? ''),
            'targetcomponent' => $def ? $def->component : ($extra['targetcomponent'] ?? null),
            'revision' => $def ? $def->revision : null,
            'targetversion' => $extra['targetversion'] ?? null,
            'statebefore' => $extra['statebefore'] ?? null,
            'stateafter' => $extra['stateafter'] ?? null,
            'result' => $extra['result'] ?? null,
            'hostname' => env::hostname(),
            'detail' => isset($extra['detail']) ? json_encode($extra['detail']) : null,
        ];

        if ($record->targetversion === null && $def !== null) {
            $version = env::component_version($def->component);
            $record->targetversion = $version->versiondisk;
        }

        return (int) $DB->insert_record('local_patchmanager_audit', $record);
    }

    /**
     * Most recent record of the given actions for a patch.
     *
     * @param string $pack
     * @param string $patchid
     * @param string[] $actions
     * @return \stdClass|null
     */
    public static function latest(string $pack, string $patchid, array $actions): ?\stdClass {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($actions, SQL_PARAMS_NAMED, 'act');
        $params['pack'] = $pack;
        $params['patchid'] = $patchid;

        $records = $DB->get_records_select('local_patchmanager_audit',
                "pack = :pack AND patchid = :patchid AND action {$insql}", $params, 'timecreated DESC, id DESC', '*', 0, 1);

        return $records ? reset($records) : null;
    }

    /**
     * Recent history for the review screen.
     *
     * @param string $pack
     * @param string $patchid
     * @param int $limit
     * @return \stdClass[]
     */
    public static function history(string $pack, string $patchid, int $limit = 20): array {
        global $DB;
        return $DB->get_records('local_patchmanager_audit', ['pack' => $pack, 'patchid' => $patchid],
                'timecreated DESC, id DESC', '*', 0, $limit);
    }
}
