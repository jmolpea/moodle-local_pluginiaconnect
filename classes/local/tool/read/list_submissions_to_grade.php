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
use local_pluginiaconnect\local\tool\assign_helper;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: list_submissions_to_grade.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_submissions_to_grade extends base_tool {
    /** @var int Most students examined in one call, across all assignments. */
    private const MAX_EXAMINED = 2000;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_submissions_to_grade';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List submissions to grade',
            'Returns the assignment submissions waiting for a grade in one course the signed-in user teaches, or in one '
                . 'assignment of it, with the date submitted and the days waiting. Use it to see what is pending to '
                . 'grade; pass \'submission_id\' to get_submission to read one. While an assignment uses anonymous '
                . 'marking, students appear as numbered participants. In a course with separate groups only '
                . 'submissions from the user\'s own groups are listed. Returns at most 50 submissions per call; use '
                . '\'cursor\' to continue, and \'scan_incomplete\' is true when a very large course was only partly '
                . 'examined.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
                'assignment_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Limit results to one assignment: its activity identifier from get_course_outline.',
                ],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'scan_incomplete' => ['type' => 'boolean'],
                    'submissions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['submission_id', 'assignment_id', 'assignment_name', 'url'],
                            'properties' => [
                                'submission_id' => ['type' => 'integer'],
                                'assignment_id' => ['type' => 'integer'],
                                'assignment_name' => ['type' => 'string'],
                                'student' => assign_helper::student_schema(),
                                'submitted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'days_waiting' => ['type' => 'integer'],
                                'late' => ['type' => 'boolean'],
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
        return new tool_requirement(profile::TEACHER, 'grades.read');
    }

    /**
     * The tool lists other people's work.
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
        $course = self::course((int) $args['course_id']);
        $cms = get_fast_modinfo($course)->get_instances_of('assign');
        if (isset($args['assignment_id'])) {
            $wanted = (int) $args['assignment_id'];
            $cms = array_filter($cms, static fn($cm) => (int) $cm->id === $wanted);
            if (!$cms || !reset($cms)->uservisible) {
                throw self::activity_not_found('Assignment', $wanted);
            }
        }

        $limit = self::limit($args);
        $offset = self::position($args);
        $emails = people::may_see_emails($call);
        $rows = [];
        $matches = 0;
        $examined = 0;
        $more = false;
        $incomplete = false;
        foreach ($cms as $cm) {
            $context = \context_module::instance($cm->id);
            if (!$cm->uservisible || !has_capability('mod/assign:grade', $context, $call->user)) {
                if (isset($args['assignment_id'])) {
                    throw new \required_capability_exception($context, 'mod/assign:grade', 'nopermissions', '');
                }
                continue;
            }
            $assign = assign_helper::load($cm, $course);
            $duedate = (int) $assign->get_instance()->duedate;
            foreach (assign_helper::gradable_users($assign, $course, $call->user) as $user) {
                if (++$examined > self::MAX_EXAMINED) {
                    // A hard stop for very large courses, said out loud in the result: the caller must
                    // narrow the call to one assignment to see the rest.
                    $incomplete = true;
                    break 2;
                }
                $submission = assign_helper::submission($assign, (int) $user->id);
                if (!assign_helper::needs_grading($assign, $submission, (int) $user->id)) {
                    continue;
                }
                $matches++;
                if ($matches <= $offset) {
                    continue;
                }
                if (count($rows) >= $limit) {
                    $more = true;
                    break 2;
                }
                $rows[] = [
                    'submission_id' => (int) $submission->id,
                    'assignment_id' => (int) $cm->id,
                    'assignment_name' => self::label($cm->get_formatted_name()),
                    'student' => assign_helper::student($assign, $user, $call, $emails),
                    'submitted_at' => self::iso((int) $submission->timemodified),
                    'days_waiting' => max(0, intdiv(time() - (int) $submission->timemodified, DAYSECS)),
                    'late' => $duedate > 0 && $submission->timemodified > $duedate,
                    'url' => (new \moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']))->out(false),
                ];
            }
        }
        $result = new tool_result(
            ['course_id' => (int) $course->id, 'scan_incomplete' => $incomplete, 'submissions' => $rows],
            count($rows),
            $more ? self::cursor($offset + count($rows)) : null,
            'course',
            (int) $course->id
        );
        $result->thirdparty = count($rows);
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
            return ['course_id', 'scan_incomplete'];
        }
        return ['course_id', 'scan_incomplete', 'submissions' => ['*' => [
            'submission_id', 'assignment_id', 'assignment_name', 'submitted_at', 'days_waiting', 'late', 'url',
            'student' => assign_helper::student_fields($profile),
        ]]];
    }
}
