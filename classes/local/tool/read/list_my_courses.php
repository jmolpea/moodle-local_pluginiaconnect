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

use local_pluginiaconnect\local\policy\context_filter;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: list_my_courses.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_my_courses extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_my_courses';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List my courses',
            'Returns the courses the signed-in user is enrolled in, with the user\'s roles in each course, start and '
                . 'end dates, completion progress and a link to the course. Use it to find a course by name and to get '
                . 'the course identifier that other tools take. Courses the site has excluded from the connector are '
                . 'not listed. Returns at most 50 courses per call; use \'cursor\' to continue.',
            self::paging_properties(),
            [],
            [
                'type' => 'object',
                'properties' => [
                    'courses' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'name', 'url'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'short_name' => ['type' => 'string'],
                                'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'start_date' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'end_date' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'progress_percent' => ['type' => ['number', 'null']],
                                'completed' => ['type' => ['boolean', 'null']],
                                'visible' => ['type' => 'boolean'],
                                'last_access' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'url' => ['type' => 'string', 'format' => 'uri'],
                            ],
                        ],
                    ],
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
        return new tool_requirement(profile::STUDENT, 'courses.read');
    }

    /**
     * The tool acts on the user's own enrolments.
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
        $rows = self::call_core_function('core_enrol_get_users_courses', [
            'userid' => (int) $call->user->id,
            'returnusercount' => false,
        ]);
        $rows = array_filter($rows, static fn($row) => context_filter::is_course_allowed((int) $row['id']));
        usort($rows, static fn($a, $b) => [strcasecmp($a['fullname'], $b['fullname']), $a['id']] <=> [0, $b['id']]);
        [$page, $next] = self::page($rows, $args);

        $courses = [];
        foreach ($page as $row) {
            $context = \context_course::instance((int) $row['id']);
            $roles = [];
            foreach (get_user_roles($context, $call->user->id, false) as $assignment) {
                $roles[] = role_get_name((object) [
                    'id' => $assignment->roleid,
                    'name' => $assignment->name,
                    'shortname' => $assignment->shortname,
                ], $context);
            }
            $courses[] = [
                'id' => (int) $row['id'],
                'name' => self::label($row['fullname']),
                'short_name' => self::label($row['shortname']),
                'roles' => array_values(array_unique($roles)),
                'start_date' => self::iso((int) ($row['startdate'] ?? 0)),
                'end_date' => self::iso((int) ($row['enddate'] ?? 0)),
                'progress_percent' => isset($row['progress']) ? round((float) $row['progress'], 1) : null,
                'completed' => isset($row['completed']) ? (bool) $row['completed'] : null,
                'visible' => !empty($row['visible']),
                'last_access' => self::iso((int) ($row['lastaccess'] ?? 0)),
                'url' => (new \moodle_url('/course/view.php', ['id' => $row['id']]))->out(false),
            ];
        }
        return new tool_result(['courses' => $courses], count($courses), $next);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['courses' => ['*' => [
            'id', 'name', 'short_name', 'roles', 'start_date', 'end_date', 'progress_percent', 'completed', 'visible',
            'last_access', 'url',
        ]]];
    }
}
