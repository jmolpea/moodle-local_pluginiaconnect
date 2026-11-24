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

namespace local_pluginiaconnect\task;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\oauth\token_service;

/**
 * Hourly clean-up of everything that has expired.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_expired extends \core\task\scheduled_task {
    /** @var int Seconds a resolved action keeps its stored result for idempotent replies. */
    public const RESULT_RETENTION = DAYSECS;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup_expired', config::COMPONENT);
    }

    /**
     * Runs the clean-up.
     *
     * @return void
     */
    public function execute() {
        // Authorization codes, tokens and grants past their absolute lifetime.
        token_service::cleanup_expired();
        self::cleanup_actions(time());
        self::cleanup_idempotency_keys(time());
    }

    /**
     * Expires pending actions and removes what resolved actions no longer need.
     *
     * The stored arguments may contain text written by the user (a message, a
     * feedback comment), so they are cleared as soon as the action can no longer run.
     *
     * @param int $now Current time.
     * @return void
     */
    public static function cleanup_actions(int $now): void {
        global $DB;
        // Pending actions whose time ran out: they can never run now.
        $DB->execute(
            "UPDATE {local_pluginiaconnect_action}
                SET status = :expired, args = NULL, tokenhash = NULL, timeresolved = :now
              WHERE status = :pending AND timeexpires < :cutoff",
            ['expired' => 'expired', 'now' => $now, 'pending' => 'pending', 'cutoff' => $now]
        );
        // Resolved actions are kept one day so that a repeated call gets the stored result.
        $DB->delete_records_select(
            'local_pluginiaconnect_action',
            'status <> :pending AND timeresolved IS NOT NULL AND timeresolved < :cutoff',
            ['pending' => 'pending', 'cutoff' => $now - self::RESULT_RETENTION]
        );
    }

    /**
     * Removes idempotency keys past their lifetime.
     *
     * @param int $now Current time.
     * @return void
     */
    public static function cleanup_idempotency_keys(int $now): void {
        global $DB;
        $DB->delete_records_select('local_pluginiaconnect_idem', 'timeexpires < :now', ['now' => $now]);
    }
}
