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

namespace local_pluginiaconnect\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_pluginiaconnect\local\oauth\token_service;

/**
 * Privacy API implementation.
 *
 * All personal data of the plugin belongs to the user account, so it lives in
 * the system context. Secrets are stored only as hashes and are never exported.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] Tables that hold a user id directly. */
    private const USER_TABLES = [
        'local_pluginiaconnect_grant',
        'local_pluginiaconnect_authcode',
        'local_pluginiaconnect_audit',
        'local_pluginiaconnect_action',
    ];

    /**
     * Describes the personal data stored and where it is sent.
     *
     * @param collection $collection Collection to add items to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $tables = [
            'grant' => [
                'userid', 'clientid', 'profile', 'scopes', 'resource', 'actor', 'actorid', 'status', 'revokedby',
                'timecreated', 'timelastused', 'timerevoked', 'timeabsoluteexpiry',
            ],
            'authcode' => [
                'codehash', 'clientid', 'userid', 'profile', 'scopes', 'redirecturi', 'codechallenge', 'resource',
                'actor', 'actorid', 'timeexpires', 'timeused', 'grantid', 'timecreated',
            ],
            'token' => [
                'grantid', 'type', 'tokenhash', 'parentid', 'timeexpires', 'timerevoked', 'timeused', 'gracedata',
                'timecreated', 'timelastused', 'lastip',
            ],
            'audit' => [
                'requestid', 'userid', 'grantid', 'clientid', 'tool', 'profile', 'iswrite', 'contextid', 'courseid',
                'objecttype', 'objectid', 'argshash', 'outcome', 'reason', 'itemcount', 'durationms', 'ip', 'timecreated',
            ],
            'action' => [
                'userid', 'grantid', 'tool', 'mode', 'tokenhash', 'args', 'argshash', 'summary', 'status', 'result',
                'timeexpires', 'timecreated', 'timeresolved',
            ],
            'idem' => ['grantid', 'tool', 'keyhash', 'argshash', 'result', 'timeexpires'],
        ];
        foreach ($tables as $table => $fields) {
            $described = [];
            foreach ($fields as $field) {
                $described[$field] = 'privacy:metadata:' . $table . ':' . $field;
            }
            $collection->add_database_table('local_pluginiaconnect_' . $table, $described, 'privacy:metadata:' . $table);
        }

        // The assistant chosen by the user receives what the user asks for through the tools.
        $collection->add_external_location_link('assistant', [
            'requesteddata' => 'privacy:metadata:assistant:requesteddata',
            'pseudonym' => 'privacy:metadata:assistant:pseudonym',
        ], 'privacy:metadata:assistant');

        // The gateway, when the site enabled it, carries requests and responses and keeps connection data.
        $collection->add_external_location_link('gateway', [
            'requesteddata' => 'privacy:metadata:gateway:requesteddata',
            'pseudonym' => 'privacy:metadata:gateway:pseudonym',
            'profile' => 'privacy:metadata:gateway:profile',
            'sitetokens' => 'privacy:metadata:gateway:sitetokens',
        ], 'privacy:metadata:gateway');

        return $collection;
    }

    /**
     * Contexts that hold data of a user: the system context, when there is any.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist List to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach (self::USER_TABLES as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}}", []);
        }
    }

    /**
     * Exports the data of a user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!self::includes_system($contextlist)) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $context = \context_system::instance();
        $root = get_string('pluginname', 'local_pluginiaconnect');

        $connections = [];
        $grants = $DB->get_records('local_pluginiaconnect_grant', ['userid' => $userid], 'timecreated ASC');
        foreach ($grants as $grant) {
            $client = $DB->get_record('local_pluginiaconnect_client', ['id' => $grant->clientid], 'id, name, type');
            $tokens = [];
            $records = $DB->get_records('local_pluginiaconnect_token', ['grantid' => $grant->id], 'timecreated ASC');
            foreach ($records as $token) {
                // Token hashes are secrets of the connection and are not exported.
                $tokens[] = (object) [
                    'type' => $token->type,
                    'timecreated' => transform::datetime($token->timecreated),
                    'timeexpires' => transform::datetime($token->timeexpires),
                    'timelastused' => $token->timelastused ? transform::datetime($token->timelastused) : null,
                    'lastip' => $token->lastip,
                ];
            }
            $connections[] = (object) [
                'client' => $client ? $client->name : null,
                'clienttype' => $client ? $client->type : null,
                'assistant' => $grant->actor,
                'profile' => $grant->profile,
                'scopes' => $grant->scopes,
                'status' => transform::yesno($grant->status),
                'revokedby' => $grant->revokedby,
                'timecreated' => transform::datetime($grant->timecreated),
                'timelastused' => $grant->timelastused ? transform::datetime($grant->timelastused) : null,
                'timerevoked' => $grant->timerevoked ? transform::datetime($grant->timerevoked) : null,
                'tokens' => $tokens,
            ];
        }
        if ($connections) {
            writer::with_context($context)->export_data(
                [$root, get_string('privacy:path:connections', 'local_pluginiaconnect')],
                (object) ['connections' => $connections]
            );
        }

        $calls = [];
        $records = $DB->get_records('local_pluginiaconnect_audit', ['userid' => $userid], 'timecreated ASC');
        foreach ($records as $record) {
            $calls[] = (object) [
                'requestid' => $record->requestid,
                'tool' => $record->tool,
                'profile' => $record->profile,
                'iswrite' => transform::yesno($record->iswrite),
                'courseid' => $record->courseid,
                'objecttype' => $record->objecttype,
                'objectid' => $record->objectid,
                'outcome' => $record->outcome,
                'reason' => $record->reason,
                'itemcount' => $record->itemcount,
                'ip' => $record->ip,
                'timecreated' => transform::datetime($record->timecreated),
            ];
        }
        if ($calls) {
            writer::with_context($context)->export_data(
                [$root, get_string('privacy:path:audit', 'local_pluginiaconnect')],
                (object) ['calls' => $calls]
            );
        }

        $actions = [];
        $records = $DB->get_records('local_pluginiaconnect_action', ['userid' => $userid], 'timecreated ASC');
        foreach ($records as $record) {
            $actions[] = (object) [
                'tool' => $record->tool,
                'mode' => $record->mode,
                'summary' => $record->summary,
                'arguments' => $record->args,
                'status' => $record->status,
                'result' => $record->result,
                'timecreated' => transform::datetime($record->timecreated),
                'timeexpires' => transform::datetime($record->timeexpires),
                'timeresolved' => $record->timeresolved ? transform::datetime($record->timeresolved) : null,
            ];
        }
        if ($actions) {
            writer::with_context($context)->export_data(
                [$root, get_string('privacy:path:actions', 'local_pluginiaconnect')],
                (object) ['actions' => $actions]
            );
        }

        // Stored answers of repeated changes (idempotency keys). The key itself is kept only as a hash.
        $sql = "SELECT i.id, i.tool, i.result, i.timeexpires
                  FROM {local_pluginiaconnect_idem} i
                  JOIN {local_pluginiaconnect_grant} g ON g.id = i.grantid
                 WHERE g.userid = :userid
              ORDER BY i.id ASC";
        $repeats = [];
        foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $record) {
            $repeats[] = (object) [
                'tool' => $record->tool,
                'result' => $record->result,
                'timeexpires' => transform::datetime($record->timeexpires),
            ];
        }
        if ($repeats) {
            writer::with_context($context)->export_data(
                [$root, get_string('privacy:path:repeats', 'local_pluginiaconnect')],
                (object) ['repeats' => $repeats]
            );
        }
    }

    /**
     * Deletes the data of every user in a context.
     *
     * @param \context $context Context to clear.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $userids = [];
        foreach (self::USER_TABLES as $table) {
            $userids = array_merge($userids, $DB->get_fieldset_sql("SELECT DISTINCT userid FROM {{$table}}"));
        }
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            self::delete_user($userid);
        }
    }

    /**
     * Deletes the data of one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (self::includes_system($contextlist)) {
            self::delete_user((int) $contextlist->get_user()->id);
        }
    }

    /**
     * Deletes the data of several users in a context.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user((int) $userid);
        }
    }

    /**
     * Revokes and deletes everything the plugin holds about a user.
     *
     * @param int $userid User id.
     * @return void
     */
    private static function delete_user(int $userid): void {
        global $DB;
        // Idempotency keys hang from the grants, so they go first, while the grants still exist.
        $grantids = $DB->get_fieldset_select('local_pluginiaconnect_grant', 'id', 'userid = :userid', ['userid' => $userid]);
        if ($grantids) {
            [$insql, $params] = $DB->get_in_or_equal($grantids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_pluginiaconnect_idem', "grantid $insql", $params);
        }
        $DB->delete_records('local_pluginiaconnect_action', ['userid' => $userid]);
        $DB->delete_records('local_pluginiaconnect_audit', ['userid' => $userid]);
        // Tokens, authorization codes and grants are deleted outright: with the rows gone no token can be used again.
        token_service::delete_user_data($userid);
    }

    /**
     * Whether the plugin holds any data of a user.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function user_has_data(int $userid): bool {
        global $DB;
        foreach (self::USER_TABLES as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an approved context list includes the system context.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return bool
     */
    private static function includes_system(approved_contextlist $contextlist): bool {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                return true;
            }
        }
        return false;
    }
}
