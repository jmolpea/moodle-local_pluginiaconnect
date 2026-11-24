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

use local_pluginiaconnect\local\guardrail\text_sanitizer;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: get_assignment_details.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_assignment_details extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_assignment_details';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get assignment details',
            'Returns the description and dates of one assignment and the state of the signed-in user\'s own submission '
                . 'to it. Use it to answer what an assignment asks for, when it is due and whether the user has already '
                . 'submitted. \'assignment_id\' is the activity identifier returned by get_course_outline. Submissions '
                . 'of other users and file contents are not returned. Fields whose name ends in \'_user_content\' hold '
                . 'text written by course teachers and are returned as written.',
            [
                'assignment_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Activity identifier of the assignment, as returned by get_course_outline.',
                ],
            ],
            ['assignment_id'],
            [
                'type' => 'object',
                'properties' => [
                    'assignment_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'course_id' => ['type' => 'integer'],
                    'course_name' => ['type' => 'string'],
                    'instructions_user_content' => ['type' => 'string'],
                    'opens_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'due_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'cut_off_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'maximum_grade' => ['type' => ['number', 'null']],
                    'my_submission_status' => ['type' => 'string'],
                    'my_submission_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
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
        return new tool_requirement(profile::STUDENT, 'courses.read', ['mod/assign:view']);
    }

    /**
     * The tool acts on one assignment.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        [$cm] = self::activity('assign', (int) $args['assignment_id'], 'Assignment');
        return \context_module::instance($cm->id);
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
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        [$cm, $course] = self::activity('assign', (int) $args['assignment_id'], 'Assignment');
        $cminfo = self::visible_activity($course, (int) $cm->id, 'Assignment');
        $assign = new \assign($call->context, $cminfo, $course);
        $instance = $assign->get_instance((int) $call->user->id);

        // The instructions stay hidden until the opening date unless the assignment says otherwise, as on the page.
        $showinstructions = !empty($instance->alwaysshowdescription)
            || empty($instance->allowsubmissionsfromdate)
            || time() >= $instance->allowsubmissionsfromdate
            || has_capability('mod/assign:grade', $call->context, $call->user);

        // Read as stored and passed through the site filters, so that a multi-language text gives only the
        // block of the user's language. The activity instructions share the page: a style sheet there can
        // hide parts of the description, and then the description is not returned.
        $instructions = text_sanitizer::has_stylesheet((string) ($instance->activity ?? ''))
            ? text_sanitizer::STYLESHEET_NOTE
            : self::plain_stored($instance->intro ?? '', $instance->introformat ?? FORMAT_HTML, $call->context);

        $submission = $assign->get_user_submission((int) $call->user->id, false);
        $submitted = $submission && $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED;

        return new tool_result([
            'assignment_id' => (int) $cm->id,
            'name' => self::label($cminfo->get_formatted_name()),
            'course_id' => (int) $course->id,
            'course_name' => self::label(format_string($course->fullname, true, ['context' => $call->context])),
            'instructions_user_content' => $showinstructions ? $instructions : '',
            'opens_at' => self::iso((int) $instance->allowsubmissionsfromdate),
            'due_at' => self::iso((int) $instance->duedate),
            'cut_off_at' => self::iso((int) $instance->cutoffdate),
            'maximum_grade' => $instance->grade > 0 ? (float) $instance->grade : null,
            'my_submission_status' => $submission ? (string) $submission->status : 'none',
            'my_submission_at' => $submitted ? self::iso((int) $submission->timemodified) : null,
            'url' => (new \moodle_url('/mod/assign/view.php', ['id' => $cm->id]))->out(false),
        ], 1, null, 'assign', (int) $cm->instance);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return [
            'assignment_id', 'name', 'course_id', 'course_name', 'instructions_user_content', 'opens_at', 'due_at',
            'cut_off_at', 'maximum_grade', 'my_submission_status', 'my_submission_at', 'url',
        ];
    }
}
