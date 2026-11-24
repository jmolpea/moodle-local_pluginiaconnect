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
use local_pluginiaconnect\local\tool\assign_helper;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: get_submission.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_submission extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_submission';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get submission',
            'Returns one assignment submission that the signed-in user may grade: its status and date, the text typed '
                . 'online, the name, size and type of each attached file, and the current grade if there is one. Use '
                . 'it to read a submission found with list_submissions_to_grade. File contents and download links are '
                . 'not returned. While the assignment uses anonymous marking, the student appears as a numbered '
                . 'participant. Fields whose name ends in \'_user_content\' hold text written by the student and are '
                . 'returned as written.',
            [
                'submission_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Identifier of the submission, as returned by list_submissions_to_grade.',
                ],
            ],
            ['submission_id'],
            [
                'type' => 'object',
                'properties' => [
                    'submission_id' => ['type' => 'integer'],
                    'assignment_id' => ['type' => 'integer'],
                    'assignment_name' => ['type' => 'string'],
                    'course_id' => ['type' => 'integer'],
                    'student' => assign_helper::student_schema(),
                    'status' => ['type' => 'string'],
                    'submitted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'late' => ['type' => 'boolean'],
                    'online_text_user_content' => ['type' => 'string'],
                    'files' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name_user_content' => ['type' => 'string'],
                                'size_bytes' => ['type' => 'integer'],
                                'type' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'current_grade' => ['type' => ['number', 'null']],
                    'maximum_grade' => ['type' => ['number', 'null']],
                    'graded_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
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
        return new tool_requirement(profile::TEACHER, 'grades.read', ['mod/assign:grade']);
    }

    /**
     * The tool returns another person's work.
     *
     * @return bool
     */
    public static function returns_third_party_data(): bool {
        return true;
    }

    /**
     * Loads the latest-attempt submission record and its course module, or fails with 'not_found'.
     *
     * @param int $submissionid Submission id.
     * @return array [submission record, course module record, course record]
     */
    private static function locate(int $submissionid): array {
        global $DB;
        $submission = $DB->get_record('assign_submission', ['id' => $submissionid, 'latest' => 1]);
        $cm = $submission ? get_coursemodule_from_instance('assign', $submission->assignment) : false;
        if (!$submission || !$cm) {
            throw self::activity_not_found('Submission', $submissionid);
        }
        return [$submission, $cm, self::course((int) $cm->course)];
    }

    /**
     * The tool acts on one assignment.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        [, $cm] = self::locate((int) $args['submission_id']);
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
        global $DB;
        $submissionid = (int) $args['submission_id'];
        [$submission, $cm, $course] = self::locate($submissionid);
        $cminfo = self::visible_activity($course, (int) $cm->id, 'Submission');
        $assign = assign_helper::load($cminfo, $course);
        $instance = $assign->get_instance();
        $emails = people::may_see_emails($call);
        $gradable = assign_helper::gradable_users($assign, $course, $call->user);

        if ((int) $submission->userid > 0) {
            // A student outside the caller's groups, or with a suspended enrolment the caller cannot see, does not exist here.
            if (!isset($gradable[(int) $submission->userid])) {
                throw self::activity_not_found('Submission', $submissionid);
            }
            $user = $gradable[(int) $submission->userid];
            $student = assign_helper::student($assign, $user, $call, $emails);
            $grade = $assign->get_user_grade((int) $user->id, false);
            $fileowner = $user;
        } else {
            // Team submission: it belongs to a group, which the caller must be allowed to see.
            $restricted = assign_helper::restricted_groups($assign, $course, $call->user);
            if ($restricted !== null && !in_array((int) $submission->groupid, $restricted, true)) {
                throw self::activity_not_found('Submission', $submissionid);
            }
            // With marker allocation, a marker only sees the teams of students allocated to them.
            if (assign_helper::allocated_students($assign, $call->user) !== null) {
                $members = (int) $submission->groupid > 0
                    ? groups_get_members((int) $submission->groupid, 'u.id', 'u.id') : [];
                if (!array_intersect_key($gradable, $members)) {
                    throw self::activity_not_found('Submission', $submissionid);
                }
            }
            $group = $submission->groupid ? $DB->get_record('groups', ['id' => $submission->groupid], 'id, name') : null;
            $student = [
                'user_id' => null,
                'full_name' => $group
                    ? self::label(format_string($group->name, true, ['context' => $call->context]))
                    : 'Default group',
                'email' => null, 'custom_fields' => [], 'anonymous' => false,
                'url' => (new \moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']))->out(false),
            ];
            $grade = null;
            $fileowner = $call->user;
        }

        $text = '';
        $onlinetext = $assign->get_submission_plugin_by_type('onlinetext');
        if ($onlinetext && $onlinetext->is_enabled()) {
            // Read as stored and passed through the site filters: a multi-language text gives only the block
            // of the reader's language, as on the grading page.
            $stored = $DB->get_record(
                'assignsubmission_onlinetext',
                ['submission' => $submission->id],
                'id, onlinetext, onlineformat'
            );
            $text = $stored ? self::plain_stored($stored->onlinetext, $stored->onlineformat, $call->context) : '';
        }
        $files = [];
        $fileplugin = $assign->get_submission_plugin_by_type('file');
        if ($fileplugin && $fileplugin->is_enabled()) {
            foreach ($fileplugin->get_files($submission, $fileowner) as $file) {
                $files[] = [
                    'name_user_content' => text_sanitizer::clean_plain((string) $file->get_filename(), 255),
                    'size_bytes' => (int) $file->get_filesize(),
                    'type' => (string) $file->get_mimetype(),
                ];
            }
        }
        $hasgrade = $grade && $grade->grade !== null && $grade->grade >= 0;
        $submitted = $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $result = new tool_result([
            'submission_id' => $submissionid,
            'assignment_id' => (int) $cm->id,
            'assignment_name' => self::label($cminfo->get_formatted_name()),
            'course_id' => (int) $course->id,
            'student' => $student,
            'status' => (string) $submission->status,
            'submitted_at' => $submitted ? self::iso((int) $submission->timemodified) : null,
            'late' => $submitted && $instance->duedate > 0 && $submission->timemodified > $instance->duedate,
            'online_text_user_content' => $text,
            'files' => $files,
            'current_grade' => $hasgrade ? (float) $grade->grade : null,
            'maximum_grade' => $instance->grade > 0 ? (float) $instance->grade : null,
            'graded_at' => $hasgrade ? self::iso((int) $grade->timemodified) : null,
            'url' => (new \moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']))->out(false),
        ], 1, null, 'assign_submission', $submissionid);
        $result->thirdparty = 1;
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
            return ['submission_id'];
        }
        return [
            'submission_id', 'assignment_id', 'assignment_name', 'course_id', 'status', 'submitted_at', 'late',
            'online_text_user_content', 'current_grade', 'maximum_grade', 'graded_at', 'url',
            'student' => assign_helper::student_fields($profile),
            'files' => ['*' => ['name_user_content', 'size_bytes', 'type']],
        ];
    }
}
