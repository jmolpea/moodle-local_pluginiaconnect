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

/**
 * Daily removal of audit records older than the retention period.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prune_audit extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_prune_audit', config::COMPONENT);
    }

    /**
     * Runs the task.
     *
     * @return void
     */
    public function execute() {
        self::prune(time());
    }

    /**
     * Deletes audit records older than the configured number of days.
     *
     * @param int $now Current time.
     * @return void
     */
    public static function prune(int $now): void {
        global $DB;
        $cutoff = $now - config::int('auditretentiondays') * DAYSECS;
        $DB->delete_records_select('local_pluginiaconnect_audit', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
    }
}
