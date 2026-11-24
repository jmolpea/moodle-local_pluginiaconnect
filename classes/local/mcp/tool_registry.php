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

namespace local_pluginiaconnect\local\mcp;

use local_pluginiaconnect\local\tool\read;
use local_pluginiaconnect\local\tool\write;

/**
 * The closed catalogue of tools: a static map from tool name to class.
 *
 * Nothing outside this map can be called. A published name never changes its
 * meaning; a tool that changes incompatibly gets a new name.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_registry {
    /** @var string Version of the catalogue format written to catalog/tools.json. */
    public const CATALOG_FORMAT = '1';

    /** @var array Tool name => class. Order is the order of tools/list. */
    private const TOOLS = [
        'get_connection_info' => read\get_connection_info::class,
        'list_my_courses' => read\list_my_courses::class,
        'search_courses' => read\search_courses::class,
        'get_course_outline' => read\get_course_outline::class,
        'list_upcoming_deadlines' => read\list_upcoming_deadlines::class,
        'get_assignment_details' => read\get_assignment_details::class,
        'list_course_announcements' => read\list_course_announcements::class,
        'get_my_grades' => read\get_my_grades::class,
        'get_my_course_progress' => read\get_my_course_progress::class,
        'list_course_participants' => read\list_course_participants::class,
        'list_submissions_to_grade' => read\list_submissions_to_grade::class,
        'get_submission' => read\get_submission::class,
        'get_course_grade_summary' => read\get_course_grade_summary::class,
        'get_course_completion_report' => read\get_course_completion_report::class,
        'list_inactive_participants' => read\list_inactive_participants::class,
        'list_categories' => read\list_categories::class,
        'get_course_enrolment_summary' => read\get_course_enrolment_summary::class,
        'find_users' => read\find_users::class,
        'get_site_status' => read\get_site_status::class,
        'list_scheduled_tasks' => read\list_scheduled_tasks::class,
        'list_installed_plugins' => read\list_installed_plugins::class,
        'get_pending_action_status' => read\get_pending_action_status::class,
        'save_assignment_grade' => write\save_assignment_grade::class,
        'post_course_announcement' => write\post_course_announcement::class,
        'send_message_to_participants' => write\send_message_to_participants::class,
        'set_activity_visibility' => write\set_activity_visibility::class,
        'update_assignment_dates' => write\update_assignment_dates::class,
        'enrol_users_in_course' => write\enrol_users_in_course::class,
        'set_enrolment_status' => write\set_enrolment_status::class,
        'create_course' => write\create_course::class,
        'set_user_suspension' => write\set_user_suspension::class,
    ];

    /** @var array Extra tools registered by unit tests. Always empty outside PHPUnit. */
    private static array $testtools = [];

    /**
     * Registers extra tools for unit tests of the pipeline. Refused outside PHPUnit.
     *
     * @param array $tools Tool name => class; an empty array removes them.
     * @return void
     */
    public static function set_test_tools(array $tools): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new coding_exception('Test tools can only be registered by unit tests.');
        }
        self::$testtools = $tools;
    }

    /**
     * Class of a tool, or null when the name is not in the catalogue.
     *
     * @param string $name Tool name.
     * @return string|null
     */
    public static function get(string $name): ?string {
        return self::TOOLS[$name] ?? (self::$testtools[$name] ?? null);
    }

    /**
     * The whole catalogue.
     *
     * @return string[] Tool name => class.
     */
    public static function all(): array {
        return self::TOOLS + self::$testtools;
    }

    /**
     * MCP definition of one tool.
     *
     * @param string $class Tool class.
     * @return array
     */
    public static function describe(string $class): array {
        return $class::definition()->to_mcp($class::requirement()->scope);
    }

    /**
     * The catalogue as written to catalog/tools.json and served by the gateway.
     *
     * @return array
     */
    public static function catalog(): array {
        $tools = [];
        foreach (self::TOOLS as $class) {
            $requirement = $class::requirement();
            $tools[] = self::describe($class) + [
                'x-pluginia' => [
                    'profile' => $requirement->profile,
                    'scope' => $requirement->scope,
                    'write' => $requirement->write,
                    'approval' => $requirement->approval,
                ],
            ];
        }
        return ['format' => self::CATALOG_FORMAT, 'tools' => $tools];
    }

    /**
     * The catalogue as the exact text of catalog/tools.json.
     *
     * @return string
     */
    public static function catalog_json(): string {
        return json_encode(self::catalog(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
