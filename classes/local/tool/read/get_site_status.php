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

namespace local_pluginiaconnect\local\tool\read;

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: get_site_status.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_site_status extends base_tool {
    /** @var int Seconds without a cron run after which cron counts as overdue. */
    private const CRON_OVERDUE = 600;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_site_status';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get site status',
            'Returns a short health summary of the site for its administrator: the major version, whether maintenance '
                . 'mode is on, when cron last ran and whether it is overdue, how many scheduled and background tasks '
                . 'are failing, and the number of user accounts and courses. Use it for a quick check of the site. '
                . 'Configuration values, server paths, the exact build and secrets are not returned.',
            [],
            [],
            [
                'type' => 'object',
                'properties' => [
                    'site_name' => ['type' => 'string'],
                    'major_version' => ['type' => 'string'],
                    'maintenance_mode' => ['type' => 'boolean'],
                    'cron_last_run' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'cron_overdue' => ['type' => 'boolean'],
                    'failing_scheduled_tasks' => ['type' => 'integer'],
                    'failing_background_tasks' => ['type' => 'integer'],
                    'user_count' => ['type' => 'integer'],
                    'course_count' => ['type' => 'integer'],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                ],
            ]
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::ADMIN, 'site.read', ['moodle/site:config']);
    }

    /**
     * The tool looks at the site.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_system::instance();
    }

    /**
     * Runs the tool.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        global $CFG, $DB, $SITE;
        $failing = 0;
        foreach (\core\task\manager::get_all_scheduled_tasks() as $task) {
            $failing += $task->get_fail_delay() > 0 ? 1 : 0;
        }
        $lastcron = (int) get_config('tool_task', 'lastcronstart');
        return new tool_result([
            'site_name' => self::label(format_string($SITE->fullname, true, ['context' => $call->context])),
            'major_version' => (string) moodle_major_version(),
            'maintenance_mode' => !empty($CFG->maintenance_enabled),
            'cron_last_run' => self::iso($lastcron),
            'cron_overdue' => !$lastcron || time() - $lastcron > self::CRON_OVERDUE,
            'failing_scheduled_tasks' => $failing,
            'failing_background_tasks' => count(\core\task\manager::get_failed_adhoc_tasks()),
            'user_count' => (int) $DB->count_records_select('user', 'deleted = 0 AND id <> :guest', ['guest' => $CFG->siteguest]),
            'course_count' => max(0, (int) $DB->count_records('course') - 1),
            'url' => (new \moodle_url('/admin/index.php'))->out(false),
        ], 1);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if ($profile !== profile::ADMIN) {
            return [];
        }
        return [
            'site_name', 'major_version', 'maintenance_mode', 'cron_last_run', 'cron_overdue', 'failing_scheduled_tasks',
            'failing_background_tasks', 'user_count', 'course_count', 'url',
        ];
    }
}
