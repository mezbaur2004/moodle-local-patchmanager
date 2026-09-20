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

namespace local_patchmanager\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * The plugin stores which administrator performed which code operation. That is
 * an audit trail, so deletion requests anonymise the records rather than
 * removing the evidence that a change happened.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_patchmanager_audit', [
            'userid' => 'privacy:metadata:audit:userid',
            'action' => 'privacy:metadata:audit:action',
            'timecreated' => 'privacy:metadata:audit:timecreated',
        ], 'privacy:metadata:audit');

        $collection->add_database_table('local_patchmanager_verify', [
            'userid' => 'privacy:metadata:verify:userid',
            'note' => 'privacy:metadata:verify:note',
            'timecreated' => 'privacy:metadata:verify:timecreated',
        ], 'privacy:metadata:verify');

        $collection->add_database_table('local_patchmanager_ack', [
            'userid' => 'privacy:metadata:ack:userid',
            'reason' => 'privacy:metadata:ack:reason',
            'timecreated' => 'privacy:metadata:ack:timecreated',
        ], 'privacy:metadata:ack');

        $collection->add_database_table('local_patchmanager_backup', [
            'userid' => 'privacy:metadata:backup:userid',
            'timecreated' => 'privacy:metadata:backup:timecreated',
        ], 'privacy:metadata:backup');

        return $collection;
    }

    /**
     * Contexts holding data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        foreach (self::tables() as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                $contextlist->add_system_context();
                break;
            }
        }

        return $contextlist;
    }

    /**
     * Users in a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }

        foreach (self::tables() as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}} WHERE userid > 0", []);
        }
    }

    /**
     * Export.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            foreach (self::tables() as $table) {
                $records = $DB->get_records($table, ['userid' => $userid]);
                if (!$records) {
                    continue;
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_patchmanager'), $table],
                    (object) ['records' => array_values($records)]
                );
            }
        }
    }

    /**
     * Anonymise all records in a context.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }

        foreach (self::tables() as $table) {
            $DB->set_field_select($table, 'userid', 0, 'userid > 0');
        }
    }

    /**
     * Anonymise one user's records.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            foreach (self::tables() as $table) {
                $DB->set_field($table, 'userid', 0, ['userid' => $userid]);
            }
        }
    }

    /**
     * Anonymise several users' records.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach (self::tables() as $table) {
            $DB->set_field_select($table, 'userid', 0, "userid {$insql}", $params);
        }
    }

    /**
     * Tables holding a userid.
     *
     * @return string[]
     */
    protected static function tables(): array {
        return [
            'local_patchmanager_audit',
            'local_patchmanager_verify',
            'local_patchmanager_ack',
            'local_patchmanager_backup',
        ];
    }
}
