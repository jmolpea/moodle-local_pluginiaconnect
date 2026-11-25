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
use local_pluginiaconnect\local\tool\report_helper;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool 61: per learner of a quiz, number of attempts, state of the last one and best grade.
 *
 * Reads the attempt and grade tables directly, without previews: the external function of 4.5 is
 * deprecated from 5.0 and its replacement does not exist in 4.5. No question and no answer is returned.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_quiz_attempts_report extends base_tool {
    /** @var string[] Attempt states as stored. */
    private const STATES = ['inprogress', 'overdue', 'finished', 'abandoned', 'submitted', 'notstarted'];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_quiz_attempts_report';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get quiz attempts report',
            'Returns, for one quiz of a course the signed-in user teaches, each learner who may attempt it with the '
                . 'number of attempts, the state of the last one and the highest grade, including those who have not started. '
                . 'Use it to see who has done a quiz and how they scored. Questions and answers are never returned, '
                . 'teacher previews are left out, and with separate groups only members of the user\'s own groups are '
                . 'listed. Returns at most 50 learners per call; use \'cursor\' to continue.',
            [
                'quiz_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Course module identifier of the quiz, from get_course_outline.',
                ],
                'only_without_attempts' => [
                    'type' => 'boolean', 'default' => false,
                    'description' => 'List only learners who have not finished any attempt.',
                ],
            ] + self::paging_properties(),
            ['quiz_id'],
            [
                'type' => 'object',
                'properties' => [
                    'quiz_id' => ['type' => 'integer'],
                    'quiz_name' => ['type' => 'string'],
                    'max_grade' => ['type' => 'number'],
                    'attempts_allowed' => ['type' => 'integer'],
                    'learners_total' => ['type' => 'integer'],
                    'learners_finished' => ['type' => 'integer'],
                    'average_best_grade' => ['type' => ['number', 'null']],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + [
                                'attempts' => ['type' => 'integer'],
                                'last_attempt_state' => ['type' => ['string', 'null'], 'enum' => array_merge(self::STATES, [null])],
                                'last_attempt_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'best_grade' => ['type' => ['number', 'null']],
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
        return new tool_requirement(profile::TEACHER, 'reports.read', ['mod/quiz:viewreports']);
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
     * The tool acts on one quiz.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        [$cm] = self::activity('quiz', (int) $args['quiz_id'], 'Quiz');
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
        [$cmrecord, $course] = self::activity('quiz', (int) $args['quiz_id'], 'Quiz');
        $cm = self::visible_activity($course, (int) $cmrecord->id, 'Quiz');
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, name, grade, sumgrades, attempts', MUST_EXIST);

        $learners = report_helper::activity_participants($cm, $call->user, 'mod/quiz:attempt');
        $stats = [];
        if ($learners) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($learners), SQL_PARAMS_NAMED, 'u');
            $rows = $DB->get_records_sql(
                "SELECT qa.id, qa.userid, qa.state, qa.timestart, qa.timefinish, qa.attempt
                   FROM {quiz_attempts} qa
                  WHERE qa.quiz = :quiz AND qa.preview = 0 AND qa.userid {$insql}
               ORDER BY qa.userid, qa.attempt",
                ['quiz' => $quiz->id] + $params
            );
            foreach ($rows as $row) {
                $userid = (int) $row->userid;
                $stats[$userid]['attempts'] = ($stats[$userid]['attempts'] ?? 0) + 1;
                $stats[$userid]['state'] = (string) $row->state;
                $stats[$userid]['at'] = (int) ($row->timefinish ?: $row->timestart);
                if ($row->state === 'finished') {
                    $stats[$userid]['finished'] = true;
                }
            }
            $grades = $DB->get_records_select_menu(
                'quiz_grades',
                "quiz = :quiz AND userid {$insql}",
                ['quiz' => $quiz->id] + $params,
                '',
                'userid, grade'
            );
            foreach ($grades as $userid => $grade) {
                $stats[(int) $userid]['grade'] = $grade === null ? null : round((float) $grade, 2);
            }
        }
        $finished = array_filter($stats, static fn($row) => !empty($row['finished']));
        $bestgrades = array_filter(array_map(static fn($row) => $row['grade'] ?? null, $stats), static fn($g) => $g !== null);
        $listed = !empty($args['only_without_attempts'])
            ? array_filter($learners, static fn($user) => empty($stats[(int) $user->id]['finished']))
            : $learners;
        [$page, $next] = self::page($listed, $args);

        $emails = people::may_see_emails($call);
        $participants = [];
        foreach ($page as $user) {
            $row = $stats[(int) $user->id] ?? [];
            $participants[] = people::describe($user, $call, (int) $course->id, $emails) + [
                'attempts' => (int) ($row['attempts'] ?? 0),
                'last_attempt_state' => isset($row['state']) && in_array($row['state'], self::STATES, true) ? $row['state'] : null,
                'last_attempt_at' => self::iso($row['at'] ?? null),
                'best_grade' => $row['grade'] ?? null,
            ];
        }
        $result = new tool_result([
            'quiz_id' => (int) $cm->id,
            'quiz_name' => self::label($cm->get_formatted_name()),
            'max_grade' => round((float) $quiz->grade, 2),
            'attempts_allowed' => (int) $quiz->attempts,
            'learners_total' => count($learners),
            'learners_finished' => count($finished),
            'average_best_grade' => $bestgrades ? round(array_sum($bestgrades) / count($bestgrades), 2) : null,
            'participants' => $participants,
            'url' => (new \moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'overview']))->out(false),
        ], count($participants), $next, 'course_module', (int) $cm->id);
        $result->thirdparty = count($participants);
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
            return ['quiz_id'];
        }
        return ['quiz_id', 'quiz_name', 'max_grade', 'attempts_allowed', 'learners_total', 'learners_finished',
            'average_best_grade', 'url', 'participants' => ['*' => array_merge(people::fields($profile), [
                'attempts', 'last_attempt_state', 'last_attempt_at', 'best_grade',
            ])]];
    }
}
