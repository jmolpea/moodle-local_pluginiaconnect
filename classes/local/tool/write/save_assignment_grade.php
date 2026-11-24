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

namespace local_pluginiaconnect\local\tool\write;

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\assign_helper;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;
use local_pluginiaconnect\local\tool\write_tool;

/**
 * Tool 23: saves the grade and feedback comment of one student in one assignment.
 *
 * It grades exactly as the grading page lets this user grade: only students of the
 * user's own groups in separate-groups courses, only allocated students when marker
 * allocation is on, never by name under anonymous marking, and only simple grading
 * (points or scale). There is no way to grade several students in one call.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class save_assignment_grade extends write_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'save_assignment_grade';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Save assignment grade',
            "Saves a grade and optional feedback comment for one student's submission to one assignment, replacing any "
                . 'existing grade unless it is locked or overridden, and never changing its marking workflow state, so '
                . "an unreleased grade stays hidden from the student. The student is named with 'user_id', or with "
                . "'submission_id' when marking is anonymous." . self::two_step_text(false),
            [
                'assignment_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => "Identifier of the assignment activity, as returned by 'get_course_outline'.",
                ],
                'user_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => "Student to grade. Give this or 'submission_id', not both.",
                ],
                'submission_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => "Submission to grade, as returned by 'list_submissions_to_grade'.",
                ],
                'grade' => [
                    'type' => 'number', 'minimum' => 0, 'maximum' => 100000,
                    'description' => "Grade on the assignment's own scale: points up to its maximum, or the position "
                        . 'of the item when the assignment uses a scale.',
                ],
                'feedback' => [
                    'type' => 'string', 'maxLength' => 4000,
                    'description' => 'Feedback comment for the student, as plain text.',
                ],
                'notify_student' => [
                    'type' => 'boolean', 'default' => false,
                    'description' => 'Whether the site notifies the student of the new grade.',
                ],
            ] + self::control_properties(),
            ['assignment_id', 'grade'],
            self::result_schema([
                'assignment_id' => ['type' => 'integer'],
                'course_id' => ['type' => 'integer'],
                'user_id' => ['type' => ['integer', 'null']],
                'grade' => ['type' => 'number'],
                'maximum_grade' => ['type' => ['number', 'null']],
                'graded_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'url' => ['type' => 'string', 'format' => 'uri'],
            ]),
            false,
            true,
            true
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(
            profile::TEACHER,
            'grades.write',
            ['mod/assign:grade'],
            true,
            tool_requirement::APPROVAL_CONFIRM
        );
    }

    /**
     * The assignment the change acts on.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        [$cm] = self::activity('assign', (int) $args['assignment_id'], 'Assignment');
        return \context_module::instance($cm->id);
    }

    /**
     * Validates the whole change against the assignment and returns what is needed to describe or apply it.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return array Plan: assign, course, cm, student, hidden, grade, max, scaleitem, current, feedback, notify.
     */
    private static function plan(array $args, call_context $call): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        [$cmrecord, $course] = self::activity('assign', (int) $args['assignment_id'], 'Assignment');
        $cm = self::visible_activity($course, (int) $cmrecord->id, 'Assignment');
        $assign = assign_helper::load($cm, $course);
        $instance = $assign->get_instance();
        $hidden = assign_helper::identities_hidden($assign, $call->user);
        $unknown = new tool_exception(
            'not_found',
            'That student or submission was not found in this assignment or is not one you can grade.'
        );

        $hasuser = array_key_exists('user_id', $args);
        $hassubmission = array_key_exists('submission_id', $args);
        if ($hasuser === $hassubmission) {
            throw new tool_exception('validation_error', "Give exactly one of 'user_id' and 'submission_id'.");
        }
        if ($hidden && $hasuser) {
            throw new tool_exception(
                'validation_error',
                "This assignment is marked anonymously: name the submission with 'submission_id' instead of 'user_id'."
            );
        }
        if ($hassubmission) {
            $submission = $DB->get_record('assign_submission', [
                'id' => (int) $args['submission_id'], 'assignment' => $instance->id, 'latest' => 1,
            ], 'id, userid');
            if (!$submission || empty($submission->userid)) {
                throw $unknown;
            }
            $userid = (int) $submission->userid;
        } else {
            $userid = (int) $args['user_id'];
        }

        // Only students this user could open on the grading page: own groups, visible enrolments
        // and, with marker allocation, the students allocated to this marker.
        $gradable = assign_helper::gradable_users($assign, $course, $call->user);
        if (!isset($gradable[$userid])) {
            throw $unknown;
        }
        // Core skips a locked or overridden grade, or one whose workflow state this user may not
        // change, and still reports success: such a grade is refused here instead.
        // Later versions also make a grade read-only once it is ready for release or released, and
        // restrict it while several markers still have to mark.
        $existing = $assign->get_user_grade($userid, false);
        $readonly = $assign->grading_disabled($userid)
            || (method_exists($assign, 'grading_locked') && $assign->grading_locked($userid))
            || (method_exists($assign, 'grading_restricted')
                && $assign->grading_restricted($existing ? (int) $existing->id : null, $userid));
        if ($readonly) {
            throw new tool_exception(
                'conflict',
                'This grade is locked or overridden in the gradebook, or its marking workflow state or the marking by '
                    . 'several markers does not let you change it. Change it on the site.'
            );
        }
        if (get_grading_manager($assign->get_context(), 'mod_assign', 'submissions')->get_active_method()) {
            throw new tool_exception(
                'validation_error',
                'This assignment is graded with a rubric or marking guide. Grade it on the site.'
            );
        }

        // The grade must be valid for the assignment: points up to the maximum, or an item of its scale.
        $value = (float) $args['grade'];
        $max = null;
        $scaleitem = null;
        if ($instance->grade > 0) {
            $max = (float) $instance->grade;
            if ($value > $max) {
                throw new tool_exception('validation_error', "'grade' must be between 0 and " . format_float($max, 2) . '.');
            }
        } else if ($instance->grade < 0) {
            $scale = $DB->get_record('scale', ['id' => -(int) $instance->grade]);
            $items = $scale ? array_map('trim', explode(',', $scale->scale)) : [];
            if (!$items || floor($value) !== $value || $value < 1 || $value > count($items)) {
                throw new tool_exception(
                    'validation_error',
                    "This assignment uses a scale: 'grade' must be a whole number from 1 to " . max(1, count($items)) . '.'
                );
            }
            $scaleitem = self::label($items[(int) $value - 1]);
        } else {
            throw new tool_exception('validation_error', 'This assignment is not graded.');
        }

        $feedback = array_key_exists('feedback', $args) ? self::caller_text((string) $args['feedback'], 4000) : null;
        if ($feedback !== null && $feedback !== '') {
            $plugin = $assign->get_feedback_plugin_by_type('comments');
            if (!$plugin || !$plugin->is_enabled()) {
                throw new tool_exception('validation_error', 'Feedback comments are switched off in this assignment.');
            }
        }

        $current = $assign->get_user_grade($userid, false);
        return [
            'assign' => $assign,
            'course' => $course,
            'cm' => $cm,
            'student' => $gradable[$userid],
            'hidden' => $hidden,
            'grade' => $value,
            'max' => $max,
            'scaleitem' => $scaleitem,
            'current' => $current && $current->grade !== null && $current->grade >= 0 ? (float) $current->grade : null,
            'feedback' => $feedback,
            'notify' => !empty($args['notify_student']),
            'unreleased' => self::unreleased($assign, $userid),
        ];
    }

    /**
     * Whether the assignment uses marking workflow and the grade of a student is not released:
     * it is then saved in the assignment but students and the gradebook do not get it yet.
     *
     * @param \assign $assign Assignment.
     * @param int $userid Student.
     * @return bool
     */
    private static function unreleased(\assign $assign, int $userid): bool {
        if (empty($assign->get_instance()->markingworkflow)) {
            return false;
        }
        $flags = $assign->get_user_flags($userid, false);
        return !$flags || $flags->workflowstate !== ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
    }

    /**
     * Description of the change. Under anonymous marking it never names the student.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        $plan = self::plan($args, $call);
        $assign = $plan['assign'];
        $who = $plan['hidden']
            ? 'Participant ' . $assign->get_uniqueid_for_user((int) $plan['student']->id)
            : people::name($plan['student'], $assign->get_context());
        $shown = static fn(?float $grade): string => $grade === null ? 'none' : format_float($grade, 2);
        $new = $plan['scaleitem'] !== null
            ? "'" . $plan['scaleitem'] . "' (item " . (int) $plan['grade'] . ' of the scale)'
            : $shown($plan['grade']) . ' out of ' . $shown($plan['max']);
        // The feedback is published under the name of the user: it is shown in full, never as a count.
        $feedback = $plan['feedback'] === null || $plan['feedback'] === ''
            ? 'none' : '"' . $plan['feedback'] . '"';
        $workflow = $plan['unreleased']
            ? ' This assignment uses marking workflow: the grade is saved but the student does not see it and it does '
                . 'not reach the gradebook until it is released on the site.'
            : '';
        return "Set grade for {$who} in '" . self::label($plan['cm']->get_formatted_name()) . "' (Course: "
            . self::label(format_string($plan['course']->fullname, true, ['context' => $assign->get_course_context()]))
            . ") to {$new}. Current grade: " . $shown($plan['current']) . ". Feedback: {$feedback}. Student notification: "
            . ($plan['notify'] ? 'on' : 'off') . '.' . $workflow;
    }

    /**
     * Applies the change through the assignment's own grading API.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $plan = self::plan($args, $call);
        $assign = $plan['assign'];
        $userid = (int) $plan['student']->id;

        $data = new \stdClass();
        $data->grade = number_format($plan['grade'], 5, '.', '');
        $data->attemptnumber = -1;
        $data->addattempt = false;
        // The marking workflow state is deliberately left out: sending it, even empty, would
        // change it and pull a released grade out of the gradebook.
        $data->applytoall = false;
        $data->sendstudentnotifications = $plan['notify'] ? 1 : 0;
        if ($plan['feedback'] !== null && $plan['feedback'] !== '') {
            $data->assignfeedbackcomments_editor = ['text' => text_to_html(s($plan['feedback']), false, true, true),
                'format' => FORMAT_HTML];
        } else {
            $plugin = $assign->get_feedback_plugin_by_type('comments');
            if ($plugin && $plugin->is_enabled()) {
                // Core reads the comment whenever comments are on: sending back what is stored leaves it untouched.
                $graded = $assign->get_user_grade($userid, false);
                $stored = $graded ? $plugin->get_feedback_comments($graded->id) : false;
                $data->assignfeedbackcomments_editor = [
                    'text' => $stored ? (string) $stored->commenttext : '',
                    'format' => $stored ? (int) $stored->commentformat : FORMAT_HTML,
                ];
            }
        }
        // The grading API checks mod/assign:grade again and writes the gradebook.
        if (!$assign->save_grade($userid, $data)) {
            throw new tool_exception('internal_error', 'The grade could not be saved.');
        }
        $saved = $assign->get_user_grade($userid, false);
        return new tool_result([
            'assignment_id' => (int) $plan['cm']->id,
            'course_id' => (int) $plan['course']->id,
            'user_id' => $plan['hidden'] ? null : $userid,
            'grade' => $plan['grade'],
            'maximum_grade' => $plan['max'],
            'graded_at' => self::iso($saved ? (int) $saved->timemodified : time()),
            'url' => (new \moodle_url('/mod/assign/view.php', ['id' => $plan['cm']->id, 'action' => 'grading']))->out(false),
        ], 1, null, 'assign', (int) $assign->get_instance()->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['assignment_id', 'course_id', 'user_id', 'grade', 'maximum_grade', 'graded_at', 'url'];
    }
}
