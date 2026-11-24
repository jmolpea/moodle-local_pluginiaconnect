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
 * Tool: get_course_enrolment_summary.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_course_enrolment_summary extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_course_enrolment_summary';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get course enrolment summary',
            'Returns, for one course the signed-in user manages, the enrolment methods with their state and number of '
                . 'enrolled users, the total of enrolled users and the number of users in each role. Use it to check '
                . 'how people get into a course and how many there are. Enrolment keys and other settings of the '
                . 'methods are not returned, and no individual user is listed.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ],
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'course_name' => ['type' => 'string'],
                    'enrolled_total' => ['type' => 'integer'],
                    'enrolled_active' => ['type' => 'integer'],
                    'methods' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'enabled' => ['type' => 'boolean'],
                                'user_count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'roles' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => ['name' => ['type' => 'string'], 'user_count' => ['type' => 'integer']],
                        ],
                    ],
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
        return new tool_requirement(profile::MANAGER, 'manage.read', ['moodle/course:enrolreview']);
    }

    /**
     * The tool acts on one course.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_course::instance(self::course((int) $args['course_id'])->id);
    }

    /**
     * Runs the tool.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        global $DB;
        $course = self::course((int) $args['course_id']);
        $context = $call->context;
        $methods = [];
        foreach (enrol_get_instances($course->id, false) as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            if (!$plugin) {
                continue;
            }
            // Only the type, the display name, the state and a count leave the site: never the key or other settings.
            $methods[] = [
                'type' => (string) $instance->enrol,
                'name' => self::label($plugin->get_instance_name($instance)),
                'enabled' => $instance->status == ENROL_INSTANCE_ENABLED && enrol_is_enabled($instance->enrol),
                'user_count' => (int) $DB->count_records('user_enrolments', ['enrolid' => $instance->id]),
            ];
        }
        $roles = [];
        foreach (get_roles_used_in_context($context, false) as $role) {
            $roles[] = [
                'name' => self::label(role_get_name($role, $context)),
                'user_count' => (int) count_role_users($role->id, $context, false),
            ];
        }
        return new tool_result([
            'course_id' => (int) $course->id,
            'course_name' => self::label(format_string($course->fullname, true, ['context' => $context])),
            'enrolled_total' => (int) count_enrolled_users($context),
            'enrolled_active' => (int) count_enrolled_users($context, '', 0, true),
            'methods' => $methods,
            'roles' => $roles,
            'url' => (new \moodle_url('/enrol/instances.php', ['id' => $course->id]))->out(false),
        ], count($methods), null, 'course', (int) $course->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if (!profile::at_least($profile, profile::MANAGER)) {
            return ['course_id'];
        }
        return [
            'course_id', 'course_name', 'enrolled_total', 'enrolled_active', 'url',
            'methods' => ['*' => ['type', 'name', 'enabled', 'user_count']],
            'roles' => ['*' => ['name', 'user_count']],
        ];
    }
}
