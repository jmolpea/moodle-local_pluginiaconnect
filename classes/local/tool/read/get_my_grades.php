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
 * Tool: get_my_grades.
 *
 * It only ever reads the grades of the signed-in user: no argument selects another user.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_my_grades extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_my_grades';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        $nullablestring = ['type' => ['string', 'null']];
        $nullablenumber = ['type' => ['number', 'null']];
        return new tool_definition(
            self::name(),
            'Get my grades',
            'Returns the signed-in user\'s own grades. Without \'course_id\' it returns the overall grade in each '
                . 'course; with \'course_id\' it returns every grade item of that course with the grade, the range and '
                . 'the teacher\'s feedback. Grades the site hides from the user are not revealed. Fields whose name '
                . 'ends in \'_user_content\' hold text written by teachers and are returned as written. Returns at most '
                . '50 items per call; use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Return the grade items of this course.'],
            ] + self::paging_properties(),
            [],
            [
                'type' => 'object',
                'properties' => [
                    'course_grades' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['course_id', 'course_name', 'url'],
                            'properties' => [
                                'course_id' => ['type' => 'integer'],
                                'course_name' => ['type' => 'string'],
                                'grade' => $nullablestring,
                                'url' => ['type' => 'string', 'format' => 'uri'],
                            ],
                        ],
                    ],
                    'course_id' => ['type' => 'integer'],
                    'course_name' => ['type' => 'string'],
                    'grade_items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['name', 'url'],
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'grade' => $nullablestring,
                                'grade_raw' => $nullablenumber,
                                'grade_min' => $nullablenumber,
                                'grade_max' => $nullablenumber,
                                'percentage' => $nullablestring,
                                'feedback_user_content' => ['type' => 'string'],
                                'graded_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
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
        return new tool_requirement(profile::STUDENT, 'grades.read');
    }

    /**
     * One course when the caller names it; otherwise the user's own grade overview.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        if (isset($args['course_id'])) {
            return \context_course::instance(self::course((int) $args['course_id'])->id);
        }
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
        if (isset($args['course_id'])) {
            return $this->course_items($args, $call);
        }
        return $this->overview($args, $call);
    }

    /**
     * Overall grade in each course.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    private function overview(array $args, call_context $call): tool_result {
        $result = self::call_core_function('gradereport_overview_get_course_grades', ['userid' => (int) $call->user->id]);
        $rows = [];
        foreach ($result['grades'] ?? [] as $row) {
            $courseid = (int) $row['courseid'];
            if (!context_filter::is_course_allowed($courseid)) {
                continue;
            }
            $course = get_course($courseid);
            $context = \context_course::instance($courseid);
            $rows[] = [
                'course_id' => $courseid,
                'course_name' => self::label(format_string($course->fullname, true, ['context' => $context])),
                'grade' => self::grade_text($row['grade'] ?? null),
                'url' => (new \moodle_url('/grade/report/user/index.php', ['id' => $courseid]))->out(false),
            ];
        }
        usort($rows, static fn($a, $b) => [strcasecmp($a['course_name'], $b['course_name']), $a['course_id']]
            <=> [0, $b['course_id']]);
        [$page, $next] = self::page($rows, $args);
        return new tool_result(['course_grades' => $page], count($page), $next);
    }

    /**
     * Grade items of one course.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    private function course_items(array $args, call_context $call): tool_result {
        global $DB;
        $course = self::course((int) $args['course_id']);
        require_capability('gradereport/user:view', $call->context);
        if (empty($course->showgrades) && !has_capability('moodle/grade:viewall', $call->context)) {
            return new tool_result([
                'course_id' => (int) $course->id,
                'course_name' => self::label(format_string($course->fullname, true, ['context' => $call->context])),
                'grade_items' => [],
            ], 0, null, 'course', (int) $course->id);
        }
        $result = self::call_core_function('gradereport_user_get_grade_items', [
            'courseid' => (int) $course->id,
            'userid' => (int) $call->user->id,
        ]);
        $reporturl = (new \moodle_url('/grade/report/user/index.php', ['id' => $course->id]))->out(false);
        // Feedback is read as stored, and only for the items whose feedback core decided to show: core
        // returns it cleaned, and cleaning removes the styles that mark hidden text.
        $stored = $DB->get_records('grade_grades', ['userid' => (int) $call->user->id], '', 'itemid, feedback, feedbackformat');
        $rows = [];
        foreach ($result['usergrades'] ?? [] as $usergrade) {
            if ((int) $usergrade['userid'] !== (int) $call->user->id) {
                continue;
            }
            foreach ($usergrade['gradeitems'] ?? [] as $item) {
                $name = (string) ($item['itemname'] ?? '');
                if ($name === '') {
                    $name = ($item['itemtype'] ?? '') === 'course' ? 'Course total' : 'Category total';
                }
                $url = $reporturl;
                if (!empty($item['cmid']) && !empty($item['itemmodule'])) {
                    $url = (new \moodle_url("/mod/{$item['itemmodule']}/view.php", ['id' => $item['cmid']]))->out(false);
                }
                // A hidden grade shows nothing in the web report, not even when it was given.
                $hidden = !empty($item['gradeishidden']) || !isset($item['graderaw']);
                $rows[] = [
                    'name' => self::label($name),
                    'type' => (string) (!empty($item['itemmodule']) ? $item['itemmodule'] : ($item['itemtype'] ?? '')),
                    'grade' => self::grade_text($item['gradeformatted'] ?? null),
                    'grade_raw' => isset($item['graderaw']) ? (float) $item['graderaw'] : null,
                    'grade_min' => isset($item['grademin']) ? (float) $item['grademin'] : null,
                    'grade_max' => isset($item['grademax']) ? (float) $item['grademax'] : null,
                    'percentage' => self::grade_text($item['percentageformatted'] ?? null),
                    'feedback_user_content' => ($item['feedback'] ?? '') !== '' && isset($stored[$item['id'] ?? 0])
                        ? self::plain_stored(
                            $stored[$item['id']]->feedback,
                            $stored[$item['id']]->feedbackformat,
                            $call->context
                        ) : '',
                    'graded_at' => $hidden ? null : self::iso((int) ($item['gradedategraded'] ?? 0)),
                    'url' => $url,
                ];
            }
        }
        [$page, $next] = self::page($rows, $args);
        return new tool_result([
            'course_id' => (int) $course->id,
            'course_name' => self::label(format_string($course->fullname, true, ['context' => $call->context])),
            'grade_items' => $page,
        ], count($page), $next, 'course', (int) $course->id);
    }

    /**
     * Formatted grade as plain text; null when there is none.
     *
     * @param mixed $value Formatted grade.
     * @return string|null
     */
    private static function grade_text($value): ?string {
        $text = self::label((string) ($value ?? ''));
        return $text === '' || $text === '-' ? null : $text;
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return [
            'course_grades' => ['*' => ['course_id', 'course_name', 'grade', 'url']],
            'course_id', 'course_name',
            'grade_items' => ['*' => [
                'name', 'type', 'grade', 'grade_raw', 'grade_min', 'grade_max', 'percentage', 'feedback_user_content',
                'graded_at', 'url',
            ]],
        ];
    }
}
