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
 * Tool: get_my_course_progress.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_my_course_progress extends base_tool {
    /** @var int Default number of activities per page. */
    protected const DEFAULT_LIMIT = 50;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_my_course_progress';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get my course progress',
            'Returns the signed-in user\'s own completion of one course: whether the course is complete, the progress '
                . 'percentage and, for each activity with completion tracking that the user can see, whether it is '
                . 'complete. Use it to answer what the user still has to do in a course. Completion of other users is '
                . 'not returned. Returns at most 50 activities per call; use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'completion_enabled' => ['type' => 'boolean'],
                    'course_completed' => ['type' => ['boolean', 'null']],
                    'progress_percent' => ['type' => ['number', 'null']],
                    'activities' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'name', 'state', 'url'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'state' => ['type' => 'string'],
                                'completed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'url' => ['type' => 'string', 'format' => 'uri'],
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
        return new tool_requirement(profile::STUDENT, 'courses.read');
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
        $userid = (int) $call->user->id;
        $url = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $completion = new \completion_info($course);
        // Completion is tracked only for users enrolled in the course.
        if (!$completion->is_enabled() || !$completion->is_tracked_user($userid)) {
            return new tool_result([
                'course_id' => (int) $course->id, 'completion_enabled' => $completion->is_enabled() > 0,
                'course_completed' => null, 'progress_percent' => null, 'activities' => [], 'url' => $url,
            ], 0, null, 'course', (int) $course->id);
        }

        $states = [
            COMPLETION_INCOMPLETE => 'incomplete', COMPLETION_COMPLETE => 'complete',
            COMPLETION_COMPLETE_PASS => 'complete_pass', COMPLETION_COMPLETE_FAIL => 'complete_fail',
        ];
        // Activities that are available but not shown on the course page stay out, as in the outline.
        $seesstealth = has_capability('moodle/course:viewhiddenactivities', $call->context, $call->user);
        $rows = [];
        foreach ($completion->get_activities() as $cm) {
            if (!$cm->uservisible || (!$seesstealth && !$cm->visibleoncoursepage)) {
                continue;
            }
            $data = $completion->get_data($cm, true, $userid);
            $rows[] = [
                'id' => (int) $cm->id,
                'name' => self::label($cm->get_formatted_name()),
                'type' => (string) $cm->modname,
                'state' => $states[(int) $data->completionstate] ?? 'incomplete',
                'completed_at' => (int) $data->completionstate !== COMPLETION_INCOMPLETE
                    ? self::iso((int) $data->timemodified) : null,
                'url' => $cm->url ? $cm->url->out(false) : $url,
            ];
        }
        [$page, $next] = self::page($rows, $args);
        $percent = \core_completion\progress::get_course_progress_percentage($course, $userid);
        return new tool_result([
            'course_id' => (int) $course->id,
            'completion_enabled' => true,
            'course_completed' => $completion->is_course_complete($userid),
            'progress_percent' => $percent === null ? null : round((float) $percent, 1),
            'activities' => $page,
            'url' => $url,
        ], count($page), $next, 'course', (int) $course->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return [
            'course_id', 'completion_enabled', 'course_completed', 'progress_percent', 'url',
            'activities' => ['*' => ['id', 'name', 'type', 'state', 'completed_at', 'url']],
        ];
    }
}
