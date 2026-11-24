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

namespace local_pluginiaconnect;

use local_pluginiaconnect\event\connection_granted;
use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\token_service;
use local_pluginiaconnect\local\policy\eligibility;

/**
 * Reactions to core events that change what a user may do.
 *
 * Capabilities are evaluated live on every call, so these observers are a
 * second line: they drop cached eligibility at once and revoke connections
 * when the account itself is no longer valid.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * A user was deleted: revoke and delete their connections, tokens and codes.
     *
     * @param \core\event\user_deleted $event The event.
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        $userid = (int) $event->objectid;
        token_service::delete_user_data($userid);
        eligibility::invalidate($userid);
    }

    /**
     * A user was updated: if the account is now suspended, revoke their connections.
     *
     * @param \core\event\user_updated $event The event.
     * @return void
     */
    public static function user_updated(\core\event\user_updated $event): void {
        global $DB;
        $userid = (int) $event->objectid;
        eligibility::invalidate($userid);
        $user = $DB->get_record('user', ['id' => $userid], 'id, suspended, deleted, auth');
        if (!$user || $user->suspended || $user->deleted || $user->auth === 'nologin') {
            token_service::revoke_user_grants($userid, 'system');
        }
    }

    /**
     * A user connected an assistant: tell them, so that a connection they did not make does not go unnoticed.
     *
     * The user can switch this notification off in their notification preferences.
     *
     * @param connection_granted $event The event.
     * @return void
     */
    public static function connection_granted(connection_granted $event): void {
        $user = \core_user::get_user((int) $event->relateduserid);
        if (!$user || !empty($user->deleted)) {
            return;
        }
        $client = client_repository::get_by_id((int) ($event->other['clientid'] ?? 0));
        $url = new \moodle_url('/local/pluginiaconnect/connections.php');
        $a = (object) [
            'client' => $client ? $client->name : get_string('message_connectioncreated_unknownclient', config::COMPONENT),
            'profile' => (string) ($event->other['profile'] ?? ''),
            'url' => $url->out(false),
        ];
        $manager = get_string_manager();
        $lang = $user->lang ?? null;
        $text = $manager->get_string('message_connectioncreated_body', config::COMPONENT, $a, $lang);

        $message = new \core\message\message();
        $message->component = config::COMPONENT;
        $message->name = 'connectioncreated';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $manager->get_string('message_connectioncreated_subject', config::COMPONENT, null, $lang);
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s($text));
        $message->smallmessage = $manager->get_string('message_connectioncreated_small', config::COMPONENT, $a, $lang);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $manager->get_string('myconnections', config::COMPONENT, null, $lang);
        try {
            message_send($message);
        } catch (\Throwable $e) {
            // A notification that cannot be sent must not undo the connection the user just approved.
            debugging('The new connection notification could not be sent.', DEBUG_DEVELOPER);
        }
    }

    /**
     * A password was changed: revoke the connections of that user when the site says so.
     *
     * @param \core\event\user_password_updated $event The event.
     * @return void
     */
    public static function user_password_updated(\core\event\user_password_updated $event): void {
        if (config::bool('revokeonpasswordchange')) {
            token_service::revoke_user_grants((int) $event->relateduserid, 'system');
        }
    }

    /**
     * Roles, enrolments or cohort membership of a user changed: forget their cached profiles.
     *
     * @param \core\event\base $event Any of the observed events; they all name the user in relateduserid.
     * @return void
     */
    public static function eligibility_changed(\core\event\base $event): void {
        if (!empty($event->relateduserid)) {
            eligibility::invalidate((int) $event->relateduserid);
        }
    }

    /**
     * A capability of a role was allowed, prevented, prohibited or removed, or a role was edited or
     * deleted: the cached profiles of every user are forgotten, because any of them may hold that role.
     *
     * @param \core\event\base $event capability_assigned, capability_unassigned, role_updated or role_deleted.
     * @return void
     */
    public static function role_definition_changed(\core\event\base $event): void {
        global $CFG;
        // An installation or upgrade assigns thousands of capabilities and purges every cache when it ends.
        if (during_initial_install() || !empty($CFG->upgraderunning)) {
            return;
        }
        eligibility::invalidate_all();
    }
}
