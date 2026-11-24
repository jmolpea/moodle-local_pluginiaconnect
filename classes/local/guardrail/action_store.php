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

use local_pluginiaconnect\local\oauth\grant_context;
use local_pluginiaconnect\local\tool\tool_exception;

/**
 * Storage of writes that wait for a confirmation token or for the user's approval.
 *
 * The arguments of a pending write are kept only until it is resolved, and its
 * stored result only for 24 hours. Stale rows are tidied on access as well as
 * by the scheduled task, so nothing outlives its purpose by an hour.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class action_store {
    /** @var string Table name. */
    public const TABLE = 'local_pluginiaconnect_action';
    /** @var string Mode: two-step confirmation with a token. */
    public const MODE_CONFIRM = 'confirm';
    /** @var string Mode: approval by the user on a page of the site. */
    public const MODE_APPROVAL = 'approval';
    /** @var int Pending actions a connection may hold at once. */
    public const MAX_PENDING = 5;
    /** @var int Lifetime of a confirmation token, in seconds. */
    public const CONFIRM_TTL = 300;
    /** @var int Lifetime of an approval request, in seconds. */
    public const APPROVAL_TTL = 900;
    /** @var int How long the stored result of a resolved action is kept, in seconds. */
    public const RESULT_TTL = DAYSECS;
    /** @var int Seconds to wait for a lock before giving up. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Marks expired pending actions as expired, clears their arguments and drops old stored results.
     *
     * @param int|null $grantid Limit the work to one connection; null for all.
     * @param int|null $now Current time, for tests.
     * @return void
     */
    public static function tidy(?int $grantid = null, ?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $params = ['now' => $now, 'pending' => 'pending'];
        $where = 'status = :pending AND timeexpires < :now';
        $grantsql = '';
        if ($grantid !== null) {
            $grantsql = ' AND grantid = :grantid';
            $params['grantid'] = $grantid;
        }
        // One conditional statement: an action that another request is applying right now is locked
        // by its transaction, and once that commits it is no longer pending, so it is left alone.
        $DB->execute(
            'UPDATE {' . self::TABLE . '}
                SET status = :expired, args = NULL, tokenhash = NULL, timeresolved = :resolved
              WHERE ' . $where . $grantsql,
            $params + ['expired' => 'expired', 'resolved' => $now]
        );
        $params = ['cutoff' => $now - self::RESULT_TTL] + ($grantid !== null ? ['grantid' => $grantid] : []);
        $DB->set_field_select(
            self::TABLE,
            'result',
            null,
            'result IS NOT NULL AND timeresolved IS NOT NULL AND timeresolved < :cutoff' . $grantsql,
            $params
        );
    }

    /**
     * Number of actions of a connection that still wait.
     *
     * @param int $grantid Grant id.
     * @return int
     */
    public static function pending_count(int $grantid): int {
        global $DB;
        return $DB->count_records_select(
            self::TABLE,
            'grantid = :grantid AND status = :pending AND timeexpires >= :now',
            ['grantid' => $grantid, 'pending' => 'pending', 'now' => time()]
        );
    }

    /**
     * Creates a pending action. Refuses when the connection already holds the maximum.
     *
     * The count and the insert run under a lock of the connection, so parallel
     * requests cannot exceed the cap.
     *
     * @param grant_context $grant Grant that asks for the change.
     * @param string $tool Tool name.
     * @param string $mode confirm or approval.
     * @param array $args Arguments of the change, without control arguments.
     * @param string $argshash Hash of the canonical arguments.
     * @param string $summary Server-built description of the change.
     * @param string|null $tokenhash SHA-256 of the confirmation token, in confirm mode.
     * @return \stdClass The new record.
     * @throws tool_exception limit_exceeded when the cap is reached.
     */
    public static function create(
        grant_context $grant,
        string $tool,
        string $mode,
        array $args,
        string $argshash,
        string $summary,
        ?string $tokenhash
    ): \stdClass {
        global $DB;
        $lock = self::lock('grant' . $grant->grantid);
        try {
            self::tidy($grant->grantid);
            if (self::pending_count($grant->grantid) >= self::MAX_PENDING) {
                throw new tool_exception(
                    'limit_exceeded',
                    'This connection already has ' . self::MAX_PENDING
                        . ' changes waiting. Complete or let them expire before requesting more.'
                );
            }
            $now = time();
            $record = (object) [
                'userid' => $grant->userid,
                'grantid' => $grant->grantid,
                'tool' => $tool,
                'mode' => $mode,
                'tokenhash' => $tokenhash,
                'args' => json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'argshash' => $argshash,
                'summary' => $summary,
                'status' => 'pending',
                'result' => null,
                'timeexpires' => $now + ($mode === self::MODE_APPROVAL ? self::APPROVAL_TTL : self::CONFIRM_TTL),
                'timecreated' => $now,
                'timeresolved' => null,
            ];
            $record->id = (int) $DB->insert_record(self::TABLE, $record);
            return $record;
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolves an action: sets its final state, stores the result and clears the arguments.
     *
     * @param int $id Action id.
     * @param string $status executed, rejected, expired or failed.
     * @param array|null $result Envelope to return on replays, or the failure, when there is one.
     * @return void
     */
    public static function resolve(int $id, string $status, ?array $result = null): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => $status,
            'args' => null,
            'result' => $result === null ? null
                : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'timeresolved' => time(),
        ]);
    }

    /**
     * Stored result of a resolved action, while it is kept.
     *
     * @param \stdClass $action Action record.
     * @return array|null
     */
    public static function stored_result(\stdClass $action): ?array {
        if ($action->result === null || $action->result === '') {
            return null;
        }
        $result = json_decode($action->result, true);
        return is_array($result) ? $result : null;
    }

    /**
     * Arguments of a pending action.
     *
     * @param \stdClass $action Action record.
     * @return array|null Null when they were already cleared or are unreadable.
     */
    public static function args(\stdClass $action): ?array {
        if ($action->args === null || $action->args === '') {
            return null;
        }
        $args = json_decode($action->args, true);
        return is_array($args) ? $args : null;
    }

    /**
     * Takes an exclusive lock. An action or a token can never be applied twice because
     * every execution happens while holding the lock of its action.
     *
     * @param string $resource Lock name.
     * @return \core\lock\lock
     * @throws tool_exception conflict when the lock cannot be taken.
     */
    public static function lock(string $resource): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('local_pluginiaconnect_action');
        $lock = $factory->get_lock($resource, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new tool_exception('conflict', 'This change is already being processed. Try again in a moment.');
        }
        return $lock;
    }
}
