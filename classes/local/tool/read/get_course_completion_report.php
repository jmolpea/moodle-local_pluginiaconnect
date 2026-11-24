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
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: get_course_completion_report.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_course_completion_report extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_course_completion_report';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get course completion report',
            'Returns, for one course the signed-in user teaches, whether each tracked participant has completed the '
                . 'course, the date of completion and the progress percentage. Use it to see who has finished and who '
                . 'is behind. If completion tracking is off in the course the list is empty. In a course with separate '
                . 'groups only members of the user\'s own groups are listed. Returns at most 50 participants per call; '
                . 'use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'completion_enabled' => ['type' => 'boolean'],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + [
                                'completed' => ['type' => 'boolean'],
                                'completed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'progress_percent' => ['type' => ['number', 'null']],
                            ],
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
        return new tool_requirement(profile::TEACHER, 'participants.read', ['report/completion:view']);
    }

    /**
     * The tool lists other people.
     *
     * @return bool
     */
    public static function returns_third_party_data(): bool {
        return true;
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
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $course = self::course((int) $args['course_id']);
        $url = (new \moodle_url('/report/completion/index.php', ['course' => $course->id]))->out(false);
        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            return new tool_result(
                ['course_id' => (int) $course->id, 'completion_enabled' => false, 'participants' => [], 'url' => $url],
                0,
                null,
                'course',
                (int) $course->id
            );
        }
        $visible = people::visible_enrolled($course, $call->context, $call->user);
        $users = array_filter($visible, static fn($user) => $completion->is_tracked_user((int) $user->id));
        [$page, $next] = self::page($users, $args);

        $emails = people::may_see_emails($call);
        $participants = [];
        $others = 0;
        foreach ($page as $user) {
            $record = new \completion_completion(['userid' => $user->id, 'course' => $course->id]);
            $percent = \core_completion\progress::get_course_progress_percentage($course, (int) $user->id);
            $participants[] = people::describe($user, $call, (int) $course->id, $emails) + [
                'completed' => $record->is_complete(),
                'completed_at' => self::iso((int) $record->timecompleted),
                'progress_percent' => $percent === null ? null : round((float) $percent, 1),
            ];
            $others += $user->id == $call->user->id ? 0 : 1;
        }
        $result = new tool_result(
            ['course_id' => (int) $course->id, 'completion_enabled' => true, 'participants' => $participants, 'url' => $url],
            count($participants),
            $next,
            'course',
            (int) $course->id
        );
        $result->thirdparty = $others;
        return $result;
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if (!profile::at_least($profile, profile::TEACHER)) {
            return ['course_id'];
        }
        return ['course_id', 'completion_enabled', 'url', 'participants' => ['*' => array_merge(people::fields($profile), [
            'completed', 'completed_at', 'progress_percent',
        ])]];
    }
}
