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

namespace local_pluginiaconnect\local\guardrail;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\mcp\call_pipeline;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\grant_context;
use local_pluginiaconnect\local\tool\tool_exception;

/**
 * Approval of write tools on a page of the site.
 *
 * In approval mode a tool call changes nothing. It records a pending action and
 * returns a link. The change is applied only when the user the connection belongs
 * to opens that page with their own session and approves it; at that moment the
 * action is authorised again from scratch and runs as that user.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class approval_service {
    /**
     * Records a change that waits for the user's approval, and tells the user.
     *
     * @param grant_context $grant Grant that asks for the change.
     * @param \stdClass $user User the connection belongs to.
     * @param string $tool Tool name.
     * @param array $args Arguments of the change, without control arguments.
     * @param string $argshash Hash of the canonical arguments.
     * @param string $summary Server-built description of the change.
     * @return \stdClass Action record.
     * @throws tool_exception limit_exceeded when the connection holds too many pending changes.
     */
    public static function request(
        grant_context $grant,
        \stdClass $user,
        string $tool,
        array $args,
        string $argshash,
        string $summary
    ): \stdClass {
        $action = action_store::create($grant, $tool, action_store::MODE_APPROVAL, $args, $argshash, $summary, null);
        self::notify($action, $user);
        return $action;
    }

    /**
     * URL of the page where the user approves or rejects an action.
     *
     * @param int $actionid Action id.
     * @return \moodle_url
     */
    public static function url(int $actionid): \moodle_url {
        return new \moodle_url('/local/pluginiaconnect/approve.php', ['id' => $actionid]);
    }

    /**
     * Loads an approval request of a user. An action of somebody else does not exist for the caller.
     *
     * @param int $actionid Action id.
     * @param int $userid User who asks.
     * @return \stdClass|null
     */
    public static function load(int $actionid, int $userid): ?\stdClass {
        global $DB;
        $action = $DB->get_record(action_store::TABLE, [
            'id' => $actionid, 'userid' => $userid, 'mode' => action_store::MODE_APPROVAL,
        ]);
        return $action ?: null;
    }

    /**
     * Approval requests of a user that still wait, newest first.
     *
     * @param int $userid User id.
     * @return \stdClass[]
     */
    public static function pending_for_user(int $userid): array {
        global $DB;
        return array_values($DB->get_records_select(
            action_store::TABLE,
            'userid = :userid AND mode = :mode AND status = :pending AND timeexpires >= :now',
            ['userid' => $userid, 'mode' => action_store::MODE_APPROVAL, 'pending' => 'pending', 'now' => time()],
            'timecreated DESC'
        ));
    }

    /**
     * State of an action as the user should see it: an unexpired pending action is pending,
     * one past its time is expired even before the clean-up runs.
     *
     * @param \stdClass $action Action record.
     * @return string pending, executed, rejected, expired or failed.
     */
    public static function state(\stdClass $action): string {
        if ($action->status === 'pending' && $action->timeexpires < time()) {
            return 'expired';
        }
        return (string) $action->status;
    }

    /**
     * The connection an action belongs to, if it can still act.
     *
     * @param \stdClass $action Action record.
     * @return grant_context|null Null when the connection was revoked or has expired.
     */
    public static function grant_of(\stdClass $action): ?grant_context {
        global $DB;
        $grant = $DB->get_record('local_pluginiaconnect_grant', ['id' => $action->grantid, 'userid' => $action->userid]);
        if (!$grant || empty($grant->status) || $grant->timeabsoluteexpiry < time()) {
            return null;
        }
        $client = client_repository::get_by_id((int) $grant->clientid);
        if (!$client || !$client->active) {
            return null;
        }
        return new grant_context(
            (int) $grant->id,
            0,
            (int) $grant->userid,
            (int) $grant->clientid,
            $client->type,
            (string) $grant->profile,
            preg_split('/\s+/', trim((string) $grant->scopes)) ?: [],
            (string) $grant->resource,
            $grant->actor,
            $grant->actorid
        );
    }

    /**
     * Description of the change as it would be applied right now.
     *
     * The data may have moved since the assistant asked (another grade, another
     * state), so the page always shows and approves the current description.
     *
     * @param \stdClass $action Pending action.
     * @param \stdClass $user Session user.
     * @return string
     * @throws tool_exception When the change can no longer be made.
     */
    public static function current_summary(\stdClass $action, \stdClass $user): string {
        $grant = self::grant_of($action);
        $args = action_store::args($action);
        if ($grant === null || $args === null) {
            throw new tool_exception('permission_denied', 'The connection that requested this change is no longer active.');
        }
        return call_pipeline::preview($grant, $user, (string) $action->tool, $args);
    }

    /**
     * Applies an action the user approved. It runs at most once: everything happens
     * under the lock of the action and the state is read again inside it.
     *
     * @param int $actionid Action id.
     * @param \stdClass $user Session user. Must be the user of the connection.
     * @param string $summaryhash SHA-256 of the summary the user saw.
     * @param string $requestid Request identifier.
     * @param string|null $ip Remote address, for the audit trail.
     * @return array [state, message]: state is executed, failed, expired, changed or missing.
     */
    public static function approve(int $actionid, \stdClass $user, string $summaryhash, string $requestid, ?string $ip): array {
        global $USER;
        // Only the person the connection belongs to approves, with their own session: never somebody
        // signed in as them, and never on behalf of another account.
        if (\core\session\manager::is_loggedinas() || (int) $USER->id !== (int) $user->id || isguestuser($user)) {
            return ['missing', ''];
        }
        try {
            $lock = action_store::lock('action' . $actionid);
        } catch (tool_exception $e) {
            return ['failed', $e->getMessage()];
        }
        try {
            $action = self::load($actionid, (int) $user->id);
            if ($action === null) {
                return ['missing', ''];
            }
            if ($action->status !== 'pending') {
                return [self::state($action), ''];
            }
            if ($action->timeexpires < time()) {
                action_store::tidy((int) $action->grantid);
                return ['expired', ''];
            }
            $grant = self::grant_of($action);
            $args = action_store::args($action);
            if ($grant === null || $args === null) {
                return self::fail($action, 'permission_denied', 'The connection that requested this change is no longer active.');
            }
            // What is approved is what was shown: if the data moved, the user must look again.
            try {
                $summary = call_pipeline::preview($grant, $user, (string) $action->tool, $args);
            } catch (tool_exception $e) {
                return self::fail($action, $e->error_code(), $e->getMessage());
            }
            if (!hash_equals(hash('sha256', $summary), $summaryhash)) {
                return ['changed', ''];
            }
            $result = call_pipeline::run_approved($grant, $user, $action, $args, $requestid, $ip);
            if ($result->iserror) {
                return self::fail(
                    $action,
                    (string) $result->error_code(),
                    (string) ($result->envelope['error']['message'] ?? '')
                );
            }
            self::event('action_approved', $action);
            return ['executed', ''];
        } finally {
            $lock->release();
        }
    }

    /**
     * Rejects a pending action. Nothing is changed on the site.
     *
     * @param int $actionid Action id.
     * @param \stdClass $user Session user.
     * @return string State after the call: rejected, or the state it already had, or missing.
     */
    public static function reject(int $actionid, \stdClass $user): string {
        try {
            $lock = action_store::lock('action' . $actionid);
        } catch (tool_exception $e) {
            return 'pending';
        }
        try {
            $action = self::load($actionid, (int) $user->id);
            if ($action === null) {
                return 'missing';
            }
            if ($action->status !== 'pending' || $action->timeexpires < time()) {
                return self::state($action);
            }
            action_store::resolve((int) $action->id, 'rejected');
            self::event('action_rejected', $action);
            return 'rejected';
        } finally {
            $lock->release();
        }
    }

    /**
     * Records that an approved action could not be applied.
     *
     * @param \stdClass $action Action record.
     * @param string $code Error code.
     * @param string $message Message for the user.
     * @return array ['failed', message]
     */
    private static function fail(\stdClass $action, string $code, string $message): array {
        action_store::resolve((int) $action->id, 'failed', [
            'status' => 'error',
            'error' => ['code' => $code, 'message' => $message],
        ]);
        return ['failed', $message];
    }

    /**
     * Triggers one of the action events. Events carry the tool and the mode, never arguments or text.
     *
     * @param string $name Event class short name.
     * @param \stdClass $action Action record.
     * @return void
     */
    public static function event(string $name, \stdClass $action): void {
        $class = '\\local_pluginiaconnect\\event\\' . $name;
        try {
            $class::create([
                'context' => \context_system::instance(),
                'objectid' => (int) $action->id,
                'relateduserid' => (int) $action->userid,
                'other' => ['tool' => (string) $action->tool, 'mode' => (string) $action->mode],
            ])->trigger();
        } catch (\Throwable $e) {
            debugging('Pluginia Connect: an action event could not be triggered.', DEBUG_DEVELOPER);
        }
    }

    /**
     * Tells the user that a change waits for their approval.
     *
     * The message names the tool and links to the page. It does not repeat the
     * summary, which may contain other people's names.
     *
     * @param \stdClass $action Action record.
     * @param \stdClass $user User to notify.
     * @return void
     */
    private static function notify(\stdClass $action, \stdClass $user): void {
        $url = self::url((int) $action->id);
        $manager = get_string_manager();
        $lang = $user->lang ?? null;
        $a = (object) ['tool' => (string) $action->tool, 'url' => $url->out(false), 'minutes' => action_store::APPROVAL_TTL / 60];
        $text = $manager->get_string('message_actionpending_body', config::COMPONENT, $a, $lang);

        $message = new \core\message\message();
        $message->component = config::COMPONENT;
        $message->name = 'actionpending';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $manager->get_string('message_actionpending_subject', config::COMPONENT, null, $lang);
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s($text));
        $message->smallmessage = $manager->get_string('message_actionpending_small', config::COMPONENT, $a, $lang);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $manager->get_string('approval_title', config::COMPONENT, null, $lang);
        try {
            message_send($message);
        } catch (\Throwable $e) {
            // The pending action exists and is listed on the user's page even if the message cannot be sent.
            debugging('The pending action notification could not be sent.', DEBUG_DEVELOPER);
        }
    }
}
